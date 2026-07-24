<?php
declare(strict_types=1);

/**
 * MediaFusion - Storage Configuration
 *
 * Environment-driven configuration for the storage abstraction layer.
 * Loaded by config.php. All storage settings come from environment variables.
 */

// ── Storage Provider ──────────────────────────────────────────────────────
// S3_ENABLED: true/false — Use object storage (S3, R2, MinIO)
// MEDIA_STORAGE_ROOT: Override local storage root directory
// MEDIA_STORAGE_BASE_URL: Override base URL prefix for local storage

// ── Object Storage (S3 / R2 / MinIO) ─────────────────────────────────────
// S3_BUCKET: Bucket/container name
// S3_REGION: Storage region (default: us-east-1)
// S3_ACCESS_KEY: Access key ID
// S3_SECRET_KEY: Secret access key
// S3_ENDPOINT: Provider endpoint URL
// S3_PUBLIC_URL_TEMPLATE: Public URL template (e.g. https://{bucket}.s3.amazonaws.com/{key})
// S3_PATH_STYLE: true/false — Use path-style URLs (required for MinIO, R2)
// CDN_BASE_URL: CDN URL prefix (takes priority over S3 URLs)

// ── Upload Limits ─────────────────────────────────────────────────────────
// MEDIA_MAX_FILE_SIZE: Maximum upload size in bytes (default: 524288000 = 500MB)
// MEDIA_MAX_DURATION: Maximum video duration in seconds (default: 600 = 10min)
// MEDIA_MAX_RESOLUTION: Maximum video resolution (default: 3840x2160)

// ── Signed URL Configuration ──────────────────────────────────────────────
// MEDIA_SIGNED_URL_EXPIRY: Signed URL lifetime in seconds (default: 3600)

// ── Processing ────────────────────────────────────────────────────────────
// MEDIA_PROCESSING_DIR: Temp directory for processing (default: /tmp)
// MEDIA_THUMBNAIL_WIDTH: Default thumbnail width (default: 480)

// Storage constants (used by StorageManager and adapters)
define('MEDIA_STORAGE_PROVIDER', getenv('S3_ENABLED') === 'true' || getenv('S3_ENABLED') === '1' ? 'object' : 'local');
define('MEDIA_MAX_FILE_SIZE', (int)(getenv('MEDIA_MAX_FILE_SIZE') ?: '524288000'));
define('MEDIA_MAX_DURATION', (int)(getenv('MEDIA_MAX_DURATION') ?: '600'));
define('MEDIA_SIGNED_URL_EXPIRY', (int)(getenv('MEDIA_SIGNED_URL_EXPIRY') ?: '3600'));
define('MEDIA_THUMBNAIL_WIDTH', (int)(getenv('MEDIA_THUMBNAIL_WIDTH') ?: '480'));
