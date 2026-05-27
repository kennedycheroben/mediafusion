#!/usr/bin/env python3
"""
Unify Studios Distribution Engine
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
    - UNIFY_DB_HOST, UNIFY_DB_USER, UNIFY_DB_PASS, UNIFY_DB_NAME
"""

from __future__ import annotations

import json
import os
import threading
import time
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

import mysql.connector
import requests

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
    host = os.getenv("UNIFY_DB_HOST") or os.getenv("DB_HOST") or os.getenv("MYSQLHOST") or "localhost"
    port = os.getenv("UNIFY_DB_PORT") or os.getenv("DB_PORT") or os.getenv("MYSQLPORT") or "3306"
    user = os.getenv("UNIFY_DB_USER") or os.getenv("DB_USER") or os.getenv("MYSQLUSER") or "root"
    password = os.getenv("UNIFY_DB_PASS") or os.getenv("DB_PASS") or os.getenv("MYSQLPASSWORD") or ""
    database = os.getenv("UNIFY_DB_NAME") or os.getenv("DB_NAME") or os.getenv("MYSQLDATABASE") or "unify_social_hub"
    
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
    return row


def push_youtube(job: UploadJob, result: Dict[str, Any]) -> None:
    """
    YouTube upload implementation placeholder.
    - Real upload requires googleapiclient + OAuth credentials bound to the user's token.
    - This function demonstrates the integration boundary and failure reporting.
    """
    token = get_token(job.user_id, "youtube")
    if not token or not token.get("access_token") or token.get("token_status") == "invalid":
        result["youtube"] = {"ok": False, "error": "missing_or_invalid_token"}
        return

    if build is None:
        result["youtube"] = {"ok": False, "error": "google_api_client_not_installed"}
        return

    # TODO: Implement resumable upload via YouTube Data API.
    # This is intentionally a scaffold to keep the system runnable without credentials.
    result["youtube"] = {"ok": True, "video_id": None, "note": "scaffold_only"}


def push_meta(job: UploadJob, result: Dict[str, Any]) -> None:
    """
    Meta Graph API upload placeholder.
    Requires a Page/IG token and correct endpoints (video upload differs for FB vs IG).
    """
    token = get_token(job.user_id, "meta")
    if not token or not token.get("access_token") or token.get("token_status") == "invalid":
        result["meta"] = {"ok": False, "error": "missing_or_invalid_token"}
        return

    # TODO: Implement actual Meta upload:
    # - Facebook Pages: /{page-id}/videos
    # - Instagram: requires IG User + container publishing flow
    result["meta"] = {"ok": True, "post_id": None, "note": "scaffold_only"}


def push_tiktok(job: UploadJob, result: Dict[str, Any]) -> None:
    """
    TikTok upload placeholder.
    TikTok APIs and app scopes vary by app type; implement once credentials are provisioned.
    """
    token = get_token(job.user_id, "tiktok")
    if not token or not token.get("access_token") or token.get("token_status") == "invalid":
        result["tiktok"] = {"ok": False, "error": "missing_or_invalid_token"}
        return

    result["tiktok"] = {"ok": True, "publish_id": None, "note": "scaffold_only"}


def run_job(job: UploadJob) -> None:
    log_line(f"Starting job upload_id={job.id} platforms={job.platforms}")

    # Validate file path
    abs_path = job.file_path
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

