-- MediaFusion Google Sign-In user fields.
-- Required by backend/google_auth.php.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) DEFAULT NULL UNIQUE,
    ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(512) DEFAULT NULL;
