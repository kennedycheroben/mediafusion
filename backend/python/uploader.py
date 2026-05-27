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

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

def get_db_connection():
    try:
        return mysql.connector.connect(
            host="127.0.0.1",
            user="root",
            password="",
            database="unify_social_hub"
        )
    except Exception as e:
        logging.error(f"Database connection failed: {e}")
        return None

def update_status(upload_id, status, video_url=None):
    conn = get_db_connection()
    if not conn: return
    try:
        cursor = conn.cursor()
        if video_url:
            cursor.execute("UPDATE uploads SET status = %s, video_url = %s WHERE id = %s", (status, video_url, upload_id))
        else:
            cursor.execute("UPDATE uploads SET status = %s WHERE id = %s", (status, upload_id))
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
        cursor.execute("""
            UPDATE oauth_tokens 
            SET access_token = %s, token_expiry = %s 
            WHERE user_id = %s AND platform = %s
        """, (access_token, expiry_str, user_id, platform))
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

    credentials = Credentials(
        token=access_token,
        refresh_token=refresh_token,
        token_uri="https://oauth2.googleapis.com/token",
        client_id="903707729051-g7dlb4g53b907mv4mpbok22tao5rmmml.apps.googleusercontent.com",
        client_secret="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"
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
    time.sleep(4)
    logging.info("TikTok upload finished.")
    return "https://tiktok.com/@user/video/mock"

def upload_meta(file_path, title, description, token):
    logging.info(f"Starting Meta upload for {title}...")
    time.sleep(6)
    logging.info("Meta upload finished.")
    return "https://facebook.com/watch/?v=mock"

def process_upload(upload_id):
    conn = get_db_connection()
    if not conn:
        logging.info(f"Mocking upload process for ID {upload_id} (No DB connection)")
        time.sleep(5)
        logging.info("Mock process finished.")
        return

    try:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM uploads WHERE id = %s", (upload_id,))
        upload_record = cursor.fetchone()
        
        if not upload_record:
            logging.error(f"Upload record {upload_id} not found.")
            return

        platforms = json.loads(upload_record['platforms'])
        file_path = upload_record['file_path']
        title = upload_record['title']
        description = upload_record['description']
        user_id = upload_record['user_id']

        logging.info(f"Retrieved metadata: {title} | Targets: {platforms}")

        # Fetch Tokens (including refresh token)
        cursor.execute("SELECT platform, access_token, refresh_token FROM oauth_tokens WHERE user_id = %s", (user_id,))
        tokens = {row['platform']: {'access_token': row['access_token'], 'refresh_token': row['refresh_token']} for row in cursor.fetchall()}

        threads = []
        results = {}

        def thread_target(platform):
            try:
                if platform == 'youtube':
                    results[platform] = upload_youtube(file_path, title, description, tokens.get('youtube'), user_id)
                elif platform == 'tiktok':
                    results[platform] = upload_tiktok(file_path, title, description, tokens.get('tiktok').get('access_token') if tokens.get('tiktok') else None)
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
        # Clean up the file if needed
        # if os.path.exists(file_path): os.remove(file_path)
        
        primary_url = list(results.values())[0] if results else None
        update_status(upload_id, 'live', primary_url)

    except Exception as e:
        logging.error(f"Process failed: {e}")
        update_status(upload_id, 'failed')
    finally:
        if 'cursor' in locals(): cursor.close()
        if 'conn' in locals(): conn.close()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        logging.error("Usage: python uploader.py <upload_id>")
        sys.exit(1)
        
    upload_id = sys.argv[1]
    logging.info(f"Triggered upload process for ID: {upload_id}")
    process_upload(upload_id)
