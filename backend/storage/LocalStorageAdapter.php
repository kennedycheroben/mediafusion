<?php
declare(strict_types=1);

namespace MediaFusion\Storage;

/**
 * LocalStorageAdapter — Local filesystem storage implementation.
 *
 * Used for:
 * - Development environments
 * - Legacy file migration
 * - Temporary processing
 * - Fallback when object storage is unavailable
 *
 * Security:
 * - All paths are resolved against a configured root directory
 * - Path traversal is blocked by realpath() validation
 * - No raw user input is trusted for path construction
 */
class LocalStorageAdapter implements StorageInterface
{
    private string $rootDir;
    private string $baseUrl;

    /**
     * @param string $rootDir  Absolute path to the storage root (e.g. /opt/lampp/htdocs/mediafusion/uploads)
     * @param string $baseUrl  Relative URL prefix (e.g. /uploads or /MediaFusion/uploads)
     */
    public function __construct(string $rootDir, string $baseUrl = '/uploads')
    {
        $this->rootDir = rtrim(realpath($rootDir) ?: $rootDir, '/');
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function isAvailable(): bool
    {
        return is_dir($this->rootDir) && is_writable($this->rootDir);
    }

    public function getProviderName(): string
    {
        return 'local';
    }

    public function putFile(string $localPath, string $key, string $contentType = 'application/octet-stream'): ?array
    {
        if (!is_file($localPath)) {
            error_log("LocalStorage: Source file not found: {$localPath}");
            return null;
        }

        $destPath = $this->resolveKeyToPath($key);
        if ($destPath === null) {
            return null;
        }

        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                error_log("LocalStorage: Failed to create directory: {$dir}");
                return null;
            }
        }

        if (!@copy($localPath, $destPath)) {
            error_log("LocalStorage: Failed to copy file to: {$destPath}");
            return null;
        }

        @chmod($destPath, 0644);

        return [
            'key' => $key,
            'url' => $this->getUrl($key),
        ];
    }

    public function putData(string $data, string $key, string $contentType = 'application/octet-stream'): ?array
    {
        $destPath = $this->resolveKeyToPath($key);
        if ($destPath === null) {
            return null;
        }

        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                error_log("LocalStorage: Failed to create directory: {$dir}");
                return null;
            }
        }

        $bytesWritten = @file_put_contents($destPath, $data, LOCK_EX);
        if ($bytesWritten === false) {
            error_log("LocalStorage: Failed to write data to: {$destPath}");
            return null;
        }

        @chmod($destPath, 0644);

        return [
            'key' => $key,
            'url' => $this->getUrl($key),
        ];
    }

    public function get(string $key): string|false
    {
        $path = $this->resolveKeyToPath($key);
        if ($path === null || !is_file($path)) {
            return false;
        }
        return @file_get_contents($path);
    }

    public function exists(string $key): bool
    {
        $path = $this->resolveKeyToPath($key);
        return $path !== null && is_file($path);
    }

    public function delete(string $key): bool
    {
        $path = $this->resolveKeyToPath($key);
        if ($path !== null && is_file($path)) {
            return @unlink($path);
        }
        return false;
    }

    public function copy(string $sourceKey, string $destKey): bool
    {
        $srcPath = $this->resolveKeyToPath($sourceKey);
        $destPath = $this->resolveKeyToPath($destKey);

        if ($srcPath === null || $destPath === null || !is_file($srcPath)) {
            return false;
        }

        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return false;
            }
        }

        return @copy($srcPath, $destPath);
    }

    public function getMetadata(string $key): ?array
    {
        $path = $this->resolveKeyToPath($key);
        if ($path === null || !is_file($path)) {
            return null;
        }

        return [
            'size' => filesize($path),
            'content_type' => mime_content_type($path) ?: 'application/octet-stream',
            'last_modified' => date('c', filemtime($path)),
        ];
    }

    public function getUrl(string $key, int $expiresInSeconds = 3600): string
    {
        return $this->baseUrl . '/' . ltrim($key, '/');
    }

    public function createUploadAuthorization(string $key, string $contentType, int $maxSize, int $expiresInSeconds = 900): ?array
    {
        // Local adapter doesn't support presigned uploads — browser uploads go through PHP.
        // Return a URL pointing to the upload handler instead.
        return null;
    }

    /**
     * Resolve a storage key to an absolute filesystem path.
     * Validates that the resolved path stays within the root directory.
     */
    private function resolveKeyToPath(string $key): ?string
    {
        $key = ltrim($key, '/');
        $path = $this->rootDir . '/' . $key;

        // Resolve symlinks and .. sequences
        $realPath = @realpath($path);
        if ($realPath === false) {
            // Path doesn't exist yet — validate the directory part
            $realDir = @realpath(dirname($path));
            if ($realDir === false) {
                // Check parent exists up the chain
                $dir = dirname($path);
                while ($dir !== $this->rootDir && $dir !== '/') {
                    if (is_dir($dir)) {
                        $realDir = realpath($dir);
                        break;
                    }
                    $dir = dirname($dir);
                }
                if ($realDir === false) {
                    error_log("LocalStorage: Path traversal attempt blocked: {$key}");
                    return null;
                }
            }
            $realPath = $realDir . '/' . basename($path);
        }

        // Ensure resolved path stays within root
        $realRoot = @realpath($this->rootDir);
        if ($realRoot === false) {
            $realRoot = $this->rootDir;
        }

        if (!str_starts_with($realPath, $realRoot . '/') && $realPath !== $realRoot) {
            error_log("LocalStorage: Path traversal attempt blocked: {$key} resolves to {$realPath}");
            return null;
        }

        return $realPath;
    }
}
