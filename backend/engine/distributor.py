#!/usr/bin/env python3
"""
MediaFusion Studios Distribution Engine
--------------------------------
backend/engine/distributor.py

Purpose:
    - Fetch uploads marked as 'pending'
    - Mark as 'processing'
    - Push a single video to YouTube / Meta / TikTok concurrently
    - Update status to 'live' or 'failed' with structured result metadata

Constraints:
    - Paths are relative to project root when launched from project root:
        python3 backend/engine/distributor.py

Dependencies (install via pip):
    - mysql-connector-python
    - google-api-python-client
    - google-auth
    - requests

Environment variables:
    - MEDIAFUSION_DB_HOST, MEDIAFUSION_DB_USER, MEDIAFUSION_DB_PASS, MEDIAFUSION_DB_NAME
"""

from __future__ import annotations

import json
import os
import sys
import threading
import time
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

import mysql.connector
import requests

# Add Python token crypto helper to path
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'python'))
from token_crypto import decrypt_if_needed, encrypt_if_needed

# NOTE: google-api-python-client integration is scaffolded; real upload requires OAuth credentials.
try:
    from googleapiclient.discovery import build  # type: ignore
except Exception:
    build = None  # noqa: N816


PROJECT_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
LOG_DIR = os.path.join(PROJECT_ROOT, "logs")
os.makedirs(LOG_DIR, exist_ok=True)
LOG_PATH = os.path.join(LOG_DIR, "distributor.log")


def log_line(msg: str) -> None:
    line = f"[{time.strftime('%Y-%m-%dT%H:%M:%S%z')}] {msg}\n"
    try:
        with open(LOG_PATH, "a", encoding="utf-8") as f:
            f.write(line)
    except Exception:
        pass


@dataclass
class UploadJob:
    id: int
    user_id: int
    filename: str
    title: str
    description: str
    platforms: List[str]
    file_path: str


def db_connect():
    host = os.getenv("MEDIAFUSION_DB_HOST") or os.getenv("DB_HOST") or os.getenv("MYSQLHOST") or "localhost"
    port = os.getenv("MEDIAFUSION_DB_PORT") or os.getenv("DB_PORT") or os.getenv("MYSQLPORT") or "3306"
    user = os.getenv("MEDIAFUSION_DB_USER") or os.getenv("DB_USER") or os.getenv("MYSQLUSER") or "root"
    password = os.getenv("MEDIAFUSION_DB_PASS") or os.getenv("DB_PASS") or os.getenv("MYSQLPASSWORD") or ""
    database = os.getenv("MEDIAFUSION_DB_NAME") or os.getenv("DB_NAME") or os.getenv("MYSQLDATABASE") or "mediafusion"
    
    return mysql.connector.connect(
        host=host,
        port=int(port),
        user=user,
        password=password,
        database=database,
        autocommit=True,
    )


def fetch_pending_uploads(limit: int = 5) -> List[UploadJob]:
    cnx = db_connect()
    cur = cnx.cursor(dictionary=True)
    cur.execute(
        "SELECT id, user_id, filename, title, description, platforms, file_path "
        "FROM uploads WHERE status = 'pending' ORDER BY created_at ASC LIMIT %s",
        (limit,),
    )
    rows = cur.fetchall()
    cur.close()
    cnx.close()

    jobs: List[UploadJob] = []
    for r in rows:
        platforms: List[str] = []
        try:
            platforms = json.loads(r.get("platforms") or "[]")
            if not isinstance(platforms, list):
                platforms = []
        except Exception:
            platforms = []
        jobs.append(
            UploadJob(
                id=int(r["id"]),
                user_id=int(r["user_id"]),
                filename=str(r.get("filename") or ""),
                title=str(r.get("title") or "Untitled"),
                description=str(r.get("description") or ""),
                platforms=[str(p) for p in platforms],
                file_path=str(r.get("file_path") or ""),
            )
        )
    return jobs


def update_upload_status(upload_id: int, status: str, results: Optional[Dict[str, Any]] = None) -> None:
    cnx = db_connect()
    cur = cnx.cursor()
    if results is not None:
        cur.execute(
            "UPDATE uploads SET status=%s, results_json=%s, updated_at=NOW() WHERE id=%s",
            (status, json.dumps(results), upload_id),
        )
    else:
        cur.execute(
            "UPDATE uploads SET status=%s, updated_at=NOW() WHERE id=%s",
            (status, upload_id),
        )
    cur.close()
    cnx.close()


def get_token(user_id: int, platform: str) -> Optional[Dict[str, Any]]:
    cnx = db_connect()
    cur = cnx.cursor(dictionary=True)
    cur.execute(
        "SELECT access_token, refresh_token, token_expiry, token_status FROM oauth_tokens WHERE user_id=%s AND platform=%s",
        (user_id, platform),
    )
    row = cur.fetchone()
    cur.close()
    cnx.close()
    if row:
        row['access_token'] = decrypt_if_needed(row.get('access_token'))
        row['refresh_token'] = decrypt_if_needed(row.get('refresh_token'))
    return row


def push_youtube(job: UploadJob, result: Dict[str, Any]) -> None:
    token = get_token(job.user_id, "youtube")
    if not token or not token.get("access_token") or token.get("token_status") == "invalid":
        result["youtube"] = {"ok": False, "error": "missing_or_invalid_token"}
        return

    access_token = token.get("access_token")
    if access_token.startswith("mock_token_") or access_token == "sandbox_token":
        result["youtube"] = {
            "ok": True,
            "video_id": "mock_yt_id",
            "url": "https://www.youtube.com/watch?v=mock_yt_id",
            "views": 1500,
            "likes": 120,
            "comments": 15
        }
        return

    try:
        from googleapiclient.discovery import build
        from googleapiclient.http import MediaFileUpload
        from google.oauth2.credentials import Credentials
        
        youtube_client_id = os.getenv("YOUTUBE_CLIENT_ID", "")
        youtube_client_secret = os.getenv("YOUTUBE_CLIENT_SECRET", "")
        if not youtube_client_id or not youtube_client_secret:
            result["youtube"] = {"ok": False, "error": "missing_youtube_client_credentials"}
            return

        credentials = Credentials(
            token=access_token,
            refresh_token=token.get("refresh_token"),
            token_uri="https://oauth2.googleapis.com/token",
            client_id=youtube_client_id,
            client_secret=youtube_client_secret
        )
        
        from google.auth.transport.requests import Request
        if credentials.expired or not credentials.valid:
            credentials.refresh(Request())
            cnx = db_connect()
            cur = cnx.cursor()
            enc_token = encrypt_if_needed(credentials.token)
            cur.execute("""
                UPDATE oauth_tokens 
                SET access_token = %s, token_expiry = %s 
                WHERE user_id = %s AND platform = 'youtube'
            """, (enc_token, credentials.expiry.strftime('%Y-%m-%d %H:%M:%S') if credentials.expiry else None, job.user_id))
            cur.close()
            cnx.close()
            
        youtube = build('youtube', 'v3', credentials=credentials)
        body = {
            'snippet': {
                'title': job.title,
                'description': job.description,
                'categoryId': '22'
            },
            'status': {
                'privacyStatus': 'public'
            }
        }
        media = MediaFileUpload(job.file_path, chunksize=-1, resumable=True, mimetype='video/*')
        request = youtube.videos().insert(part='snippet,status', body=body, media_body=media)
        
        response = None
        while response is None:
            status, response = request.next_chunk()
            
        video_id = response.get('id')
        result["youtube"] = {
            "ok": True,
            "video_id": video_id,
            "url": f"https://www.youtube.com/watch?v={video_id}",
            "views": 0,
            "likes": 0,
            "comments": 0
        }
    except Exception as e:
        result["youtube"] = {"ok": False, "error": str(e)}


def push_meta(job: UploadJob, result: Dict[str, Any]) -> None:
    token = get_token(job.user_id, "meta")
    if not token or not token.get("access_token") or token.get("token_status") == "invalid":
        result["meta"] = {"ok": False, "error": "missing_or_invalid_token"}
        return

    access_token = token.get("access_token")
    if access_token.startswith("mock_token_") or access_token == "sandbox_token":
        result["meta"] = {
            "ok": True,
            "post_id": "mock_meta_id",
            "url": "https://facebook.com/watch/?v=mock",
            "views": 1800,
            "likes": 150,
            "comments": 20
        }
        return

    try:
        import requests
        url = "https://graph.facebook.com/v25.0/me/videos"
        with open(job.file_path, "rb") as f:
            files = {"source": f}
            data = {
                "access_token": access_token,
                "title": job.title,
                "description": job.description
            }
            response = requests.post(url, files=files, data=data)
            
        if response.status_code != 200:
            raise Exception(f"Facebook upload failed: {response.text}")
        res_data = response.json()
        post_id = res_data.get("id")
        result["meta"] = {
            "ok": True,
            "post_id": post_id,
            "url": f"https://facebook.com/watch/?v={post_id}",
            "views": 0,
            "likes": 0,
            "comments": 0
        }
    except Exception as e:
        result["meta"] = {"ok": False, "error": str(e)}


def push_tiktok(job: UploadJob, result: Dict[str, Any]) -> None:
    token = get_token(job.user_id, "tiktok")
    if not token or not token.get("access_token") or token.get("token_status") == "invalid":
        result["tiktok"] = {"ok": False, "error": "missing_or_invalid_token"}
        return

    access_token = token.get("access_token")
    if access_token.startswith("mock_token_") or access_token == "sandbox_token":
        result["tiktok"] = {
            "ok": True,
            "publish_id": "mock_tt_id",
            "url": "https://tiktok.com/@user/video/mock",
            "views": 2500,
            "likes": 340,
            "comments": 42
        }
        return

    try:
        import requests
        url = "https://open.tiktokapis.com/v2/post/publish/video/init/"
        headers = {
            "Authorization": f"Bearer {access_token}",
            "Content-Type": "application/json; charset=UTF-8"
        }
        file_size = os.path.getsize(job.file_path)
        chunk_size = 10 * 1024 * 1024
        total_chunk_count = (file_size + chunk_size - 1) // chunk_size
        
        data = {
            "post_info": {
                "title": job.title,
                "privacy_level": "PUBLIC_TO_EVERYONE",
                "disable_comment": False,
                "disable_duet": False,
                "disable_stitch": False
            },
            "source_info": {
                "source": "FILE_UPLOAD",
                "video_size": file_size,
                "chunk_size": chunk_size,
                "total_chunk_count": total_chunk_count
            }
        }
        
        response = requests.post(url, headers=headers, json=data)
        if response.status_code != 200:
            raise Exception(f"TikTok initialization failed: {response.text}")
        res_data = response.json()
        if res_data.get("error", {}).get("code") != "ok":
            raise Exception(f"TikTok API error: {res_data.get('error', {}).get('message')}")
            
        upload_url = res_data["data"]["upload_url"]
        publish_id = res_data["data"]["publish_id"]
        
        with open(job.file_path, "rb") as f:
            for i in range(total_chunk_count):
                chunk_data = f.read(chunk_size)
                chunk_headers = {
                    "Content-Range": f"bytes {i * chunk_size}-{i * chunk_size + len(chunk_data) - 1}/{file_size}",
                    "Content-Type": "video/mp4"
                }
                upload_resp = requests.put(upload_url, headers=chunk_headers, data=chunk_data)
                if upload_resp.status_code not in (200, 201, 206):
                    raise Exception(f"TikTok chunk upload failed: {upload_resp.text}")
                    
        result["tiktok"] = {
            "ok": True,
            "publish_id": publish_id,
            "url": f"https://www.tiktok.com/@user/video/{publish_id}",
            "views": 0,
            "likes": 0,
            "comments": 0
        }
    except Exception as e:
        result["tiktok"] = {"ok": False, "error": str(e)}


def run_job(job: UploadJob) -> None:
    log_line(f"Starting job upload_id={job.id} platforms={job.platforms}")

    # Validate file path (download S3 file to local temp path if it is an external URL)
    is_url = job.file_path.startswith("http://") or job.file_path.startswith("https://")
    temp_local_path = None
    abs_path = job.file_path

    if is_url:
        import urllib.request
        import tempfile
        log_line(f"Downloading remote file from S3: {job.file_path}")
        suffix = os.path.splitext(job.file_path)[0].split("/")[-1] if "." not in os.path.splitext(job.file_path)[1] else os.path.splitext(job.file_path)[1]
        if "?" in suffix:
            suffix = suffix.split("?")[0]
        if not suffix or len(suffix) > 8:
            suffix = ".mp4"
        try:
            temp_fd, temp_local_path = tempfile.mkstemp(suffix=suffix)
            os.close(temp_fd)
            urllib.request.urlretrieve(job.file_path, temp_local_path)
            abs_path = temp_local_path
            # Update job file path to local copy so upload handlers read from it
            job.file_path = abs_path
        except Exception as e:
            update_upload_status(job.id, "failed", {"error": "download_failed", "url": job.file_path, "details": str(e)})
            log_line(f"FAILED upload_id={job.id} download failed: {e}")
            if temp_local_path and os.path.exists(temp_local_path):
                os.remove(temp_local_path)
            return
    else:
        if not os.path.isabs(abs_path):
            abs_path = os.path.join(PROJECT_ROOT, abs_path)
        if not os.path.isfile(abs_path):
            update_upload_status(job.id, "failed", {"error": "file_missing", "file_path": abs_path})
            log_line(f"FAILED upload_id={job.id} file missing: {abs_path}")
            return

    update_upload_status(job.id, "processing", {"started_at": time.time(), "platforms": job.platforms})

    result: Dict[str, Any] = {"platforms": job.platforms, "started_at": time.time()}

    threads: List[threading.Thread] = []
    if "youtube" in job.platforms:
        threads.append(threading.Thread(target=push_youtube, args=(job, result), daemon=True))
    if "meta" in job.platforms:
        threads.append(threading.Thread(target=push_meta, args=(job, result), daemon=True))
    if "tiktok" in job.platforms:
        threads.append(threading.Thread(target=push_tiktok, args=(job, result), daemon=True))

    for t in threads:
        t.start()
    for t in threads:
        t.join()

    # Clean up temp file if we downloaded it
    if temp_local_path and os.path.exists(temp_local_path):
        try:
            os.remove(temp_local_path)
            log_line(f"Cleaned up temporary download file: {temp_local_path}")
        except Exception as e:
            log_line(f"Failed to remove temporary file {temp_local_path}: {e}")

    ok = all(bool(result.get(p, {}).get("ok")) for p in job.platforms)
    result["finished_at"] = time.time()

    if ok:
        update_upload_status(job.id, "live", result)
        log_line(f"LIVE upload_id={job.id}")
    else:
        update_upload_status(job.id, "failed", result)
        log_line(f"FAILED upload_id={job.id} result={result}")


def main() -> int:
    try:
        jobs = fetch_pending_uploads(limit=5)
        if not jobs:
            return 0
        for job in jobs:
            run_job(job)
        return 0
    except Exception as e:
        log_line(f"FATAL: {e}")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
