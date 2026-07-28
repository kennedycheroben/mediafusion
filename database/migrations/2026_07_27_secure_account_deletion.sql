-- MediaFusion secure account deletion support
-- Required by backend/bootstrap.php session revocation checks.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS security_version INT NOT NULL DEFAULT 0;

-- Existing baseline schema already indexes uploads.user_id, oauth_tokens.user_id,
-- studio_projects.user_id, incomplete_uploads.user_id, and rate_limits.identifier.
