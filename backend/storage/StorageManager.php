<?php
declare(strict_types=1);

namespace MediaFusion\Storage;

/**
 * StorageManager — Factory and router for storage adapters.
 *
 * Selects the appropriate storage adapter based on environment configuration.
 * Provides a single point of access for all storage operations throughout the app.
 *
 * Configuration (via environment variables):
 *   S3_ENABLED=true/false   — Use object storage (S3/R2/MinIO)
 *   S3_*                    — Object storage credentials
 *   MEDIA_STORAGE_ROOT      — Override local storage root
 *   CDN_BASE_URL            — CDN URL prefix for media delivery
 *
 * Usage:
 *   $storage = MediaFusion\Storage\StorageManager::getInstance();
 *   $result = $storage->putFile('/tmp/photo.jpg', 'users/42/media/abc/original.jpg', 'image/jpeg');
 */
class StorageManager
{
    private static ?StorageManager $instance = null;

    private StorageInterface $primary;
    private LocalStorageAdapter $local;
    private ?ObjectStorageAdapter $object = null;
    private string $storageRoot;

    private function __construct()
    {
        $this->storageRoot = $this->determineStorageRoot();
        $this->local = new LocalStorageAdapter($this->storageRoot, $this->determineBaseUrl());

        $obj = new ObjectStorageAdapter();
        if ($obj->isAvailable()) {
            $this->object = $obj;
            $this->primary = $obj;
        } else {
            $this->primary = $this->local;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the primary (active) storage adapter.
     */
    public function getAdapter(): StorageInterface
    {
        return $this->primary;
    }

    /**
     * Get the local storage adapter directly.
     */
    public function getLocalAdapter(): LocalStorageAdapter
    {
        return $this->local;
    }

    /**
     * Get the object storage adapter if available.
     */
    public function getObjectAdapter(): ?ObjectStorageAdapter
    {
        return $this->object;
    }

    /**
     * Check if object storage is the active provider.
     */
    public function isUsingObjectStorage(): bool
    {
        return $this->primary instanceof ObjectStorageAdapter;
    }

    /**
     * Get the storage provider name.
     */
    public function getProviderName(): string
    {
        return $this->primary->getProviderName();
    }

    /**
     * Get the storage root directory (for local adapter).
     */
    public function getStorageRoot(): string
    {
        return $this->storageRoot;
    }

    // ── Convenience methods (delegate to primary adapter) ─────────────────

    public function putFile(string $localPath, string $key, string $contentType = 'application/octet-stream'): ?array
    {
        return $this->primary->putFile($localPath, $key, $contentType);
    }

    public function putData(string $data, string $key, string $contentType = 'application/octet-stream'): ?array
    {
        return $this->primary->putData($data, $key, $contentType);
    }

    public function get(string $key): string|false
    {
        return $this->primary->get($key);
    }

    public function exists(string $key): bool
    {
        return $this->primary->exists($key);
    }

    public function delete(string $key): bool
    {
        return $this->primary->delete($key);
    }

    public function copy(string $sourceKey, string $destKey): bool
    {
        return $this->primary->copy($sourceKey, $destKey);
    }

    public function getMetadata(string $key): ?array
    {
        return $this->primary->getMetadata($key);
    }

    public function getUrl(string $key, int $expiresInSeconds = 3600): string
    {
        return $this->primary->getUrl($key, $expiresInSeconds);
    }

    public function createUploadAuthorization(string $key, string $contentType, int $maxSize, int $expiresInSeconds = 900): ?array
    {
        return $this->primary->createUploadAuthorization($key, $contentType, $maxSize, $expiresInSeconds);
    }

    /**
     * Generate a server-controlled storage key for a media asset.
     * Users never control the storage path directly.
     *
     * @param int    $userId   Owner's user ID
     * @param string $category Media category (media, avatar, brand, processed, cutout, temp)
     * @param string $ext      File extension (e.g. 'mp4', 'jpg')
     * @param string $variant  Sub-variant (e.g. 'original', 'thumbnail', 'preview')
     * @return string          Storage key like "users/42/media/550e8400/original.mp4"
     */
    public function generateKey(int $userId, string $category, string $ext, string $variant = 'original'): string
    {
        $uuid = bin2hex(random_bytes(16));
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext));
        return "users/{$userId}/{$category}/{$uuid}/{$variant}.{$ext}";
    }

    /**
     * Generate a storage key for thumbnails.
     */
    public function generateThumbnailKey(int $userId, string $category, string $ext = 'webp'): string
    {
        $uuid = bin2hex(random_bytes(16));
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext));
        return "users/{$userId}/{$category}/{$uuid}/thumbnail.{$ext}";
    }

    // ── Private configuration helpers ──────────────────────────────────────

    private function determineStorageRoot(): string
    {
        $envRoot = getenv('MEDIA_STORAGE_ROOT');
        if ($envRoot !== false && is_dir($envRoot)) {
            return rtrim(realpath($envRoot) ?: $envRoot, '/');
        }
        return dirname(__DIR__, 2) . '/uploads';
    }

    private function determineBaseUrl(): string
    {
        $envUrl = getenv('MEDIA_STORAGE_BASE_URL');
        if ($envUrl !== false) {
            return rtrim($envUrl, '/');
        }

        // Detect project subdirectory from document root
        $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
        $projRoot = str_replace('\\', '/', dirname(__DIR__, 2));
        $basePath = '';
        if ($docRoot !== '' && str_starts_with($projRoot, $docRoot)) {
            $basePath = substr($projRoot, strlen($docRoot));
        }
        return rtrim($basePath, '/') . '/uploads';
    }
}
