import sys
import json
import time
import threading
import mysql.connector
import logging
import os
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload
from google.oauth2.credentials import Credentials
from token_crypto import decrypt_if_needed, encrypt_if_needed

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

def get_db_connection():
    try:
        host = os.getenv("MEDIAFUSION_DB_HOST") or os.getenv("DB_HOST") or os.getenv("MYSQLHOST") or "127.0.0.1"
        port = os.getenv("MEDIAFUSION_DB_PORT") or os.getenv("DB_PORT") or os.getenv("MYSQLPORT") or "3306"
        user = os.getenv("MEDIAFUSION_DB_USER") or os.getenv("DB_USER") or os.getenv("MYSQLUSER") or "root"
        password = os.getenv("MEDIAFUSION_DB_PASS") or os.getenv("DB_PASS") or os.getenv("MYSQLPASSWORD") or ""
        database = os.getenv("MEDIAFUSION_DB_NAME") or os.getenv("DB_NAME") or os.getenv("MYSQLDATABASE") or "mediafusion"
        
        return mysql.connector.connect(
            host=host,
            port=int(port),
            user=user,
            password=password,
            database=database
        )
    except Exception as e:
        logging.error(f"Database connection failed: {e}")
        return None

def update_status(upload_id, status, video_url=None, results_json=None):
    conn = get_db_connection()
    if not conn: return
    try:
        cursor = conn.cursor()
        results_str = json.dumps(results_json) if results_json else None
        if video_url:
            cursor.execute("UPDATE uploads SET status = %s, video_url = %s, results_json = %s WHERE id = %s", (status, video_url, results_str, upload_id))
        else:
            cursor.execute("UPDATE uploads SET status = %s, results_json = %s WHERE id = %s", (status, results_str, upload_id))
        conn.commit()
    except Exception as e:
        logging.error(f"Failed to update status for {upload_id}: {e}")
    finally:
        if 'cursor' in locals(): cursor.close()
        if 'conn' in locals(): conn.close()

def update_db_token(user_id, platform, access_token, expiry):
    conn = get_db_connection()
    if not conn: return
    try:
        cursor = conn.cursor()
        expiry_str = expiry.strftime('%Y-%m-%d %H:%M:%S') if expiry else None
        enc_token = encrypt_if_needed(access_token)
        cursor.execute("""
            UPDATE oauth_tokens 
            SET access_token = %s, token_expiry = %s 
            WHERE user_id = %s AND platform = %s
        """, (enc_token, expiry_str, user_id, platform))
        conn.commit()
        logging.info(f"Successfully refreshed and saved new access token for {platform}")
    except Exception as e:
        logging.error(f"Failed to save refreshed token to DB: {e}")
    finally:
        if 'cursor' in locals(): cursor.close()
        if 'conn' in locals(): conn.close()

# Actual YouTube upload implementation with OAuth Refresh support
def upload_youtube(file_path, title, description, token_data, user_id):
    logging.info(f"Starting YouTube upload for {title}...")
    if not token_data or not token_data.get('access_token'):
        raise ValueError("YouTube OAuth token is missing. Please connect YouTube first in the Integration Vault.")

    access_token = token_data.get('access_token')
    refresh_token = token_data.get('refresh_token')
    youtube_client_id = os.getenv("YOUTUBE_CLIENT_ID", "")
    youtube_client_secret = os.getenv("YOUTUBE_CLIENT_SECRET", "")
    if not youtube_client_id or not youtube_client_secret:
        raise ValueError("YouTube client credentials are missing. Define YOUTUBE_CLIENT_ID and YOUTUBE_CLIENT_SECRET in the environment.")

    credentials = Credentials(
        token=access_token,
        refresh_token=refresh_token,
        token_uri="https://oauth2.googleapis.com/token",
        client_id=youtube_client_id,
        client_secret=youtube_client_secret
    )

    from google.auth.transport.requests import Request
    if credentials.expired or not credentials.valid:
        logging.info("YouTube access token expired or invalid. Refreshing...")
        try:
            credentials.refresh(Request())
            # Save the new access token to the database
            update_db_token(user_id, 'youtube', credentials.token, credentials.expiry)
        except Exception as e:
            logging.error(f"Failed to refresh YouTube token: {e}")
            raise e

    youtube = build('youtube', 'v3', credentials=credentials)

    body = {
        'snippet': {
            'title': title,
            'description': description,
            'categoryId': '22'  # People & Blogs
        },
        'status': {
            'privacyStatus': 'public'
        }
    }

    media = MediaFileUpload(file_path, chunksize=-1, resumable=True, mimetype='video/*')
    request = youtube.videos().insert(
        part='snippet,status',
        body=body,
        media_body=media
    )

    response = None
    while response is None:
        status, response = request.next_chunk()
        if status:
            logging.info(f"YouTube upload progress: {int(status.progress() * 100)}%")

    video_id = response.get('id')
    logging.info(f"YouTube upload finished. Video ID: {video_id}")
    return f"https://www.youtube.com/watch?v={video_id}"

def upload_tiktok(file_path, title, description, token):
    logging.info(f"Starting TikTok upload for {title}...")
    if not token:
        raise ValueError("TikTok OAuth token is missing. Please connect TikTok first.")
    
    if token.startswith("mock_token_") or token == "sandbox_token":
        logging.info("TikTok sandbox token detected. Performing mock upload...")
        time.sleep(2)
        return "https://tiktok.com/@user/video/mock"

    # Real TikTok Video Publish API
    import requests
    url = "https://open.tiktokapis.com/v2/post/publish/video/init/"
    headers = {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json; charset=UTF-8"
    }
    
    file_size = os.path.getsize(file_path)
    chunk_size = 10 * 1024 * 1024 # 10MB chunk
    total_chunk_count = (file_size + chunk_size - 1) // chunk_size
    
    data = {
        "post_info": {
            "title": title,
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
        raise Exception(f"TikTok publish initialization failed: {response.text}")
        
    res_data = response.json()
    if res_data.get("error", {}).get("code") != "ok":
        raise Exception(f"TikTok API Error: {res_data.get('error', {}).get('message')}")
        
    upload_url = res_data["data"]["upload_url"]
    publish_id = res_data["data"]["publish_id"]
    
    # Upload chunks
    with open(file_path, "rb") as f:
        for i in range(total_chunk_count):
            chunk_data = f.read(chunk_size)
            chunk_headers = {
                "Content-Range": f"bytes {i * chunk_size}-{i * chunk_size + len(chunk_data) - 1}/{file_size}",
                "Content-Type": "video/mp4"
            }
            upload_resp = requests.put(upload_url, headers=chunk_headers, data=chunk_data)
            if upload_resp.status_code not in (200, 201, 206):
                raise Exception(f"TikTok chunk upload failed: {upload_resp.text}")
                
    logging.info("TikTok upload completed successfully.")
    return f"https://www.tiktok.com/@user/video/{publish_id}"

def upload_facebook(file_path, title, description, token):
    logging.info(f"Starting Facebook upload for {title}...")
    if not token:
        raise ValueError("Facebook/Meta OAuth token is missing.")
        
    if token.startswith("mock_token_") or token == "sandbox_token":
        logging.info("Facebook sandbox token detected. Performing mock upload...")
        time.sleep(2)
        return "https://facebook.com/watch/?v=mock"
        
    import requests
    url = "https://graph.facebook.com/v25.0/me/videos"
    
    with open(file_path, "rb") as f:
        files = {
            "source": f
        }
        data = {
            "access_token": token,
            "title": title,
            "description": description
        }
        response = requests.post(url, files=files, data=data)
        
    if response.status_code != 200:
        raise Exception(f"Facebook video upload failed: {response.text}")
        
    res_data = response.json()
    post_id = res_data.get("id")
    logging.info("Facebook upload completed successfully.")
    return f"https://facebook.com/watch/?v={post_id}"

def upload_instagram(file_path, title, description, token):
    logging.info(f"Starting Instagram upload for {title}...")
    if not token:
        raise ValueError("Instagram/Meta OAuth token is missing.")
        
    if token.startswith("mock_token_") or token == "sandbox_token":
        logging.info("Instagram sandbox token detected. Performing mock upload...")
        time.sleep(2)
        return "https://instagram.com/reel/mock"
        
    import requests
    
    # Step 1: Find IG Business Account ID
    me_url = f"https://graph.facebook.com/v25.0/me/accounts?access_token={token}"
    resp = requests.get(me_url)
    if resp.status_code != 200:
        raise Exception(f"Instagram profile fetch failed: {resp.text}")
        
    accounts = resp.json().get("data", [])
    if not accounts:
        raise Exception("No Facebook pages or Instagram accounts associated with this token.")
        
    ig_user_id = None
    page_access_token = None
    for acc in accounts:
        page_url = f"https://graph.facebook.com/v25.0/{acc['id']}?fields=instagram_business_account&access_token={token}"
        page_resp = requests.get(page_url)
        if page_resp.status_code == 200:
            ig_acc = page_resp.json().get("instagram_business_account", {})
            if ig_acc:
                ig_user_id = ig_acc["id"]
                page_access_token = acc.get("access_token")
                break
                
    if not ig_user_id:
        raise Exception("Could not find a connected Instagram Business Account on the Meta page.")
        
    video_url = file_path
    if not (file_path.startswith("http://") or file_path.startswith("https://")):
        logging.warning("Instagram video upload requires a publicly accessible URL. S3 must be enabled in production.")
        raise ValueError("Instagram publishing requires S3_ENABLED=true to provide a public URL for Meta servers.")

    # Create container
    container_url = f"https://graph.facebook.com/v25.0/{ig_user_id}/media"
    container_data = {
        "media_type": "REELS",
        "video_url": video_url,
        "caption": description,
        "access_token": page_access_token or token
    }
    
    c_resp = requests.post(container_url, data=container_data)
    if c_resp.status_code != 200:
        raise Exception(f"Instagram container creation failed: {c_resp.text}")
        
    creation_id = c_resp.json().get("id")
    
    # Check container status
    status_url = f"https://graph.facebook.com/v25.0/{creation_id}?fields=status_code&access_token={page_access_token or token}"
    for _ in range(30):
        time.sleep(5)
        s_resp = requests.get(status_url)
        if s_resp.status_code == 200:
            status_code = s_resp.json().get("status_code")
            if status_code == "FINISHED":
                break
            elif status_code == "ERROR":
                raise Exception(f"Instagram media container processing failed: {s_resp.text}")
        else:
            raise Exception(f"Instagram container status check failed: {s_resp.text}")
            
    # Publish container
    publish_url = f"https://graph.facebook.com/v25.0/{ig_user_id}/media_publish"
    publish_data = {
        "creation_id": creation_id,
        "access_token": page_access_token or token
    }
    p_resp = requests.post(publish_url, data=publish_data)
    if p_resp.status_code != 200:
        raise Exception(f"Instagram media publish failed: {p_resp.text}")
        
    media_id = p_resp.json().get("id")
    logging.info("Instagram upload completed successfully.")
    return f"https://www.instagram.com/reel/{media_id}/"

def upload_meta(file_path, title, description, token):
    logging.info(f"Starting Meta upload (Facebook + Instagram) for {title}...")
    fb_url = upload_facebook(file_path, title, description, token)
    try:
        ig_url = upload_instagram(file_path, title, description, token)
        return fb_url
    except Exception as e:
        logging.warning(f"Meta partial success: Facebook uploaded but Instagram failed: {e}")
        return fb_url

def process_upload(upload_id):
    conn = get_db_connection()
    if not conn:
        logging.info(f"Mocking upload process for ID {upload_id} (No DB connection)")
        time.sleep(5)
        logging.info("Mock process finished.")
        return

    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT uploads.*
            FROM uploads
            INNER JOIN users ON users.id = uploads.user_id
            WHERE uploads.id = %s
        """, (upload_id,))
        upload_record = cursor.fetchone()
        
        if not upload_record:
            logging.error(f"Upload record {upload_id} not found.")
            return

        platforms = json.loads(upload_record['platforms'])
        file_path = upload_record['file_path']
        title = upload_record['title']
        description = upload_record['description']
        user_id = upload_record['user_id']

        is_url = file_path.startswith("http://") or file_path.startswith("https://")
        temp_local_path = None
        if is_url:
            logging.info(f"File path is a remote URL. Downloading to temporary location: {file_path}")
            import urllib.request
            import tempfile
            
            suffix = os.path.splitext(file_path)[0].split("/")[-1] if "." not in os.path.splitext(file_path)[1] else os.path.splitext(file_path)[1]
            if "?" in suffix:
                suffix = suffix.split("?")[0]
            if not suffix or len(suffix) > 8:
                suffix = ".mp4"
                
            temp_fd, temp_local_path = tempfile.mkstemp(suffix=suffix)
            os.close(temp_fd)
            
            try:
                urllib.request.urlretrieve(file_path, temp_local_path)
                file_path = temp_local_path
                logging.info(f"Downloaded successfully to {temp_local_path}")
            except Exception as e:
                logging.error(f"Failed to download remote file: {e}")
                if os.path.exists(temp_local_path):
                    os.remove(temp_local_path)
                raise e

        logging.info(f"Retrieved metadata: {title} | Targets: {platforms}")

        # Fetch Tokens (including refresh token) and decrypt
        cursor.execute("SELECT platform, access_token, refresh_token FROM oauth_tokens WHERE user_id = %s", (user_id,))
        tokens = {}
        for row in cursor.fetchall():
            tokens[row['platform']] = {
                'access_token': decrypt_if_needed(row['access_token']),
                'refresh_token': decrypt_if_needed(row['refresh_token']),
            }

        threads = []
        results = {}

        def thread_target(platform):
            try:
                if platform == 'youtube':
                    results[platform] = upload_youtube(file_path, title, description, tokens.get('youtube'), user_id)
                elif platform == 'tiktok':
                    results[platform] = upload_tiktok(file_path, title, description, tokens.get('tiktok').get('access_token') if tokens.get('tiktok') else None)
                elif platform == 'facebook':
                    fb_tok = tokens.get('facebook') or tokens.get('meta')
                    results[platform] = upload_facebook(file_path, title, description, fb_tok.get('access_token') if fb_tok else None)
                elif platform == 'instagram':
                    ig_tok = tokens.get('instagram') or tokens.get('meta')
                    results[platform] = upload_instagram(file_path, title, description, ig_tok.get('access_token') if ig_tok else None)
                elif platform == 'meta':
                    results[platform] = upload_meta(file_path, title, description, tokens.get('meta').get('access_token') if tokens.get('meta') else None)
            except Exception as e:
                logging.error(f"Error uploading to {platform}: {e}")

        # Start a thread for each platform
        for platform in platforms:
            t = threading.Thread(target=thread_target, args=(platform,))
            threads.append(t)
            t.start()

        # Wait for all threads to finish
        for t in threads:
            t.join()

        logging.info("All selected platform uploads finished.")
        
        # Build structured results_json for DB
        results_json = {}
        for p in platforms:
            if p in results:
                url = results[p]
                item_id = "mock_id"
                if "v=" in url:
                    item_id = url.split("v=")[-1]
                elif "video/" in url:
                    item_id = url.split("video/")[-1]
                elif "id=" in url:
                    item_id = url.split("id=")[-1]
                
                results_json[p] = {
                    "status": "success",
                    "url": url,
                    "id": item_id,
                    "views": 0,
                    "likes": 0,
                    "comments": 0
                }
            else:
                results_json[p] = {
                    "status": "failed",
                    "error": "Upload failed or timed out"
                }

        primary_url = list(results.values())[0] if results else None
        update_status(upload_id, 'live', primary_url, results_json)

    except Exception as e:
        logging.error(f"Process failed: {e}")
        update_status(upload_id, 'failed')
    finally:
        if 'temp_local_path' in locals() and temp_local_path and os.path.exists(temp_local_path):
            try:
                os.remove(temp_local_path)
                logging.info(f"Successfully cleaned up temporary video file: {temp_local_path}")
            except Exception as e:
                logging.error(f"Error cleaning up temporary file {temp_local_path}: {e}")
        if 'cursor' in locals(): cursor.close()
        if 'conn' in locals(): conn.close()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        logging.error("Usage: python uploader.py <upload_id>")
        sys.exit(1)
        
    upload_id = sys.argv[1]
    logging.info(f"Triggered upload process for ID: {upload_id}")
    process_upload(upload_id)
