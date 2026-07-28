# MediaFusion

This repository contains the MediaFusion web application. It supports social account connection, resumable uploads, and automated distribution to YouTube, TikTok, and Meta.

## What was fixed

- Removed legacy mock OAuth callback code from `connect.php`
- Fixed Meta callback token storage in `callback_meta.php` so long-lived tokens are stored in both `access_token` and `refresh_token` for refresh handling
- Corrected password reset email validation in `request_password_reset.php`
- Improved Instagram upload URL return in `backend/python/uploader.py`
- Added `.env.example` with required environment variables and deployment instructions

## Required setup steps

### 1. Configure environment variables

Copy `.env.example` to `.env` in the project root and fill in your real values.

Example:

```bash
cp .env.example .env
```

Then update values for:
- `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`
- `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET`
- `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`, `TIKTOK_REDIRECT_URI`
- `META_APP_ID`, `META_APP_SECRET`, `META_CONFIG_ID`, `META_REDIRECT_URI`
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_ENCRYPTION`
- optional S3 values if you want public cloud storage for video uploads

### 2. Run database migrations

Load the schema and migration script into your MySQL database:

```bash
mysql -u root -p mediafusion < database/schema.mysql.sql
mysql -u root -p mediafusion < database/schema_migration.sql
```

This ensures `oauth_tokens`, `uploads`, `password_resets`, and migration columns such as `token_status` and `results_json` exist.

### 3. Install Python dependencies

From the project root:

```bash
python3 -m venv backend/python/venv
source backend/python/venv/bin/activate
pip install -r backend/python/requirements.txt
```

### 4. Configure OAuth redirect URIs in provider consoles

Set the redirect URIs exactly as:

- Google sign-in: `https://yourdomain.com/backend/google_auth.php`
- YouTube: `https://yourdomain.com/callback.php`
- TikTok: `https://yourdomain.com/callback.php`
- Meta / Facebook: `https://yourdomain.com/callback_meta.php`

If you are testing locally, use a valid HTTPS tunnel or local domain because these providers often require HTTPS.

### 5. Configure SMTP

Supply working SMTP credentials in `.env`.

The password reset flow uses the custom SMTP socket client in `request_password_reset.php`.

### 6. Start or schedule the token refresh worker

The app includes `backend/refresh_worker.php` for renewing tokens.

Run it regularly with cron:

```cron
* * * * * php /opt/lampp/htdocs/MediaFusion/backend/refresh_worker.php >> /opt/lampp/htdocs/MediaFusion/logs/refresh_worker.log 2>&1
```

### 7. Verify file permissions

Ensure PHP can write to these directories:
- `uploads/avatars`
- `uploads/processed`
- `uploads/temp`
- `uploads/videos`
- `logs/`

### 8. Verify production readiness

Some items cannot be completed purely in code:

- Create and approve OAuth apps in Google, TikTok, and Meta
- Obtain real OAuth client IDs/secrets and configure provider scopes
- Verify all redirect URIs on each platform
- Ensure the site is served over HTTPS in production
- Confirm SMTP server credentials and email sending
- If uploading to Instagram, enable S3/public URL hosting for video files

## Troubleshooting

- OAuth errors appear in `connect.php?error=...`
- Token refresh/log failures are written to `logs/refresh_worker.log`
- Python upload logs are in `uploads/python_upload.log`

## Notes

- `connect.php` now delegates all code exchange to `callback.php` and `callback_meta.php`
- `callback_meta.php` now stores Meta access tokens correctly for the refresh worker
- The system still requires real provider app configuration to move from sandbox to production
