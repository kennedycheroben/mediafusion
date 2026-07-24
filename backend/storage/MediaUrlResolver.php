<?php
declare(strict_types=1);

namespace MediaFusion\Storage;

/**
 * MediaUrlResolver — Centralized media URL resolution.
 *
 * Every media URL in the application must go through this resolver.
 * This ensures:
 * - Consistent URL generation
 * - Proper CDN URL usage when configured
 * - Legacy local paths are resolved correctly during migration
 * - Private media uses signed URLs when needed
 *
 * Usage:
 *   $resolver = new MediaUrlResolver();
 *   $url = $resolver->resolve('users/42/media/abc/original.mp4');
 *   $url = $resolver->resolveLegacy('/opt/lampp/htdocs/mediafusion/uploads/studio/abc123.mp4');
 */
class MediaUrlResolver
{
    private StorageManager $storage;
    private string $baseUrl;

    public function __construct()
    {
        $this->storage = StorageManager::getInstance();
        $this->baseUrl = rtrim($this->storage->getStorageRoot(), '/');
    }

    /**
     * Resolve a storage key to a public URL.
     *
     * @param string $key       Storage key (e.g. "users/42/media/abc/original.mp4")
     * @param int    $expires   Signed URL expiry in seconds (default 1 hour)
     * @return string           Public or signed URL
     */
    public function resolve(string $key, int $expires = 3600): string
    {
        return $this->storage->getUrl($key, $expires);
    }

    /**
     * Resolve a legacy local file path to a public URL.
     * Handles both absolute paths and relative paths from the old system.
     *
     * @param string $path  Local filesystem path (absolute or relative)
     * @return string       Public URL
     */
    public function resolveLegacy(string $path): string
    {
        // Already a URL
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Already a relative URL path (e.g. "uploads/studio/abc.mp4")
        if (preg_match('#^uploads/#', $path)) {
            return '/' . ltrim($path, '/');
        }

        // Absolute path — extract the relative portion
        $storageRoot = $this->baseUrl;
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = str_replace('\\', '/', dirname($storageRoot));

        if (str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            $relative = substr($normalizedPath, strlen($normalizedRoot) + 1);
            return '/' . $relative;
        }

        // Try to find 'uploads/' in the path
        $uploadsPos = strpos($normalizedPath, 'uploads/');
        if ($uploadsPos !== false) {
            return '/' . substr($normalizedPath, $uploadsPos);
        }

        // Fallback: return as-is (will likely 404, but avoids crashes)
        return $path;
    }

    /**
     * Resolve a studio media record to a playable URL.
     * Checks for storage_key (new system) or public_url/storage_path (legacy).
     *
     * @param array $mediaRow  Database row from studio_media table
     * @return string          Playable URL
     */
    public function resolveStudioMedia(array $mediaRow): string
    {
        // New system: has storage_key
        if (!empty($mediaRow['storage_key'])) {
            return $this->resolve($mediaRow['storage_key']);
        }

        // Legacy: public_url
        if (!empty($mediaRow['public_url'])) {
            return $this->resolveLegacy($mediaRow['public_url']);
        }

        // Legacy: storage_path (absolute path)
        if (!empty($mediaRow['storage_path'])) {
            return $this->resolveLegacy($mediaRow['storage_path']);
        }

        return '';
    }

    /**
     * Resolve a studio media thumbnail URL.
     *
     * @param array $mediaRow  Database row from studio_media table
     * @return string|null     Thumbnail URL or null
     */
    public function resolveThumbnail(array $mediaRow): ?string
    {
        // New system: has thumbnail_key
        if (!empty($mediaRow['thumbnail_key'])) {
            return $this->resolve($mediaRow['thumbnail_key']);
        }

        // Legacy: thumbnail_url
        if (!empty($mediaRow['thumbnail_url'])) {
            $url = $mediaRow['thumbnail_url'];
            if (str_starts_with($url, 'http')) {
                return $url;
            }
            return '/' . ltrim($url, '/');
        }

        return null;
    }

    /**
     * Resolve an upload record to a playable URL.
     * Handles both local file_path and S3 URLs stored in the uploads table.
     *
     * @param array $uploadRow  Database row from uploads table
     * @return string           Playable URL
     */
    public function resolveUpload(array $uploadRow): string
    {
        // Check video_url first (may be an S3 URL)
        if (!empty($uploadRow['video_url'])) {
            return $uploadRow['video_url'];
        }

        // file_path — could be local absolute, local relative, or S3 URL
        $filePath = $uploadRow['file_path'] ?? '';
        if ($filePath === '') return '';

        // Already a full URL
        if (str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://')) {
            return $filePath;
        }

        return $this->resolveLegacy($filePath);
    }

    /**
     * Get the local filesystem path for a storage key.
     * Used by FFmpeg and other processing that requires local files.
     * If the file is in object storage, it must be downloaded first.
     *
     * @param string $key  Storage key
     * @return string|null  Local filesystem path, or null if not available locally
     */
    public function getLocalPath(string $key): ?string
    {
        if ($this->storage->isUsingObjectStorage()) {
            // File is in object storage — would need to download to temp
            // Return null to signal caller needs to fetch
            return null;
        }

        // Local adapter — resolve directly
        $root = $this->storage->getStorageRoot();
        $path = $root . '/' . ltrim($key, '/');
        return is_file($path) ? $path : null;
    }

    /**
     * Ensure a media file is available locally for processing (FFmpeg, etc).
     * Downloads from object storage to a temp file if necessary.
     *
     * @param string      $key  Storage key
     * @param string|null $url  Fallback URL to download from
     * @return string|null      Local temp path (caller must clean up)
     */
    public function ensureLocal(string $key, ?string $url = null): ?string
    {
        // Try direct local path first
        $localPath = $this->getLocalPath($key);
        if ($localPath !== null) {
            return $localPath;
        }

        // Download from object storage or URL
        $data = $this->storage->get($key);
        if ($data === false && $url !== null) {
            // Try downloading from URL directly
            $data = @file_get_contents($url);
        }

        if ($data === false) {
            return null;
        }

        $ext = pathinfo($key, PATHINFO_EXTENSION) ?: 'bin';
        $tmpPath = sys_get_temp_dir() . '/mf_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (file_put_contents($tmpPath, $data) !== false) {
            return $tmpPath;
        }

        return null;
    }

    /**
     * Resolve a processed/exported media URL.
     *
     * @param string $filename  Output filename (e.g. "export_1234.mp4")
     * @return string           Public URL
     */
    public function resolveProcessed(string $filename): string
    {
        return '/uploads/processed/' . basename($filename);
    }
}
