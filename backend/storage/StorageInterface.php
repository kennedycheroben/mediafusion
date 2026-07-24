<?php
declare(strict_types=1);

namespace MediaFusion\Storage;

/**
 * StorageInterface — Contract for all storage operations.
 *
 * Every storage adapter (local, S3, R2, MinIO) must implement this interface.
 * The application interacts only with this contract — never with provider-specific code.
 */
interface StorageInterface
{
    /**
     * Check if this adapter is properly configured and available.
     */
    public function isAvailable(): bool;

    /**
     * Upload data from a file path to storage.
     *
     * @param string $localPath  Absolute path to the local source file
     * @param string $key        Server-generated storage key (e.g. users/42/media/abc/original.mp4)
     * @param string $contentType MIME type of the content
     * @return array{key: string, url: string}|null  Storage key and resolved URL on success
     */
    public function putFile(string $localPath, string $key, string $contentType = 'application/octet-stream'): ?array;

    /**
     * Upload raw data directly to storage.
     *
     * @param string $data        Binary content
     * @param string $key         Server-generated storage key
     * @param string $contentType MIME type
     * @return array{key: string, url: string}|null
     */
    public function putData(string $data, string $key, string $contentType = 'application/octet-stream'): ?array;

    /**
     * Read file content from storage.
     *
     * @param string $key  Storage key
     * @return string|false  File content or false on failure
     */
    public function get(string $key): string|false;

    /**
     * Check if an object exists in storage.
     */
    public function exists(string $key): bool;

    /**
     * Delete an object from storage.
     */
    public function delete(string $key): bool;

    /**
     * Copy an object within the same storage.
     */
    public function copy(string $sourceKey, string $destKey): bool;

    /**
     * Get metadata for an object.
     *
     * @return array{size: int, content_type: string, last_modified: string}|null
     */
    public function getMetadata(string $key): ?array;

    /**
     * Get the publicly accessible URL for a storage key.
     * For public buckets, this returns a direct URL.
     * For private buckets, this should return a short-lived signed URL.
     *
     * @param int $expiresInSeconds  URL validity period (for signed URLs)
     */
    public function getUrl(string $key, int $expiresInSeconds = 3600): string;

    /**
     * Generate a presigned upload authorization for direct browser uploads.
     *
     * @param string $key         Storage key the browser will upload to
     * @param string $contentType Expected MIME type
     * @param int    $maxSize     Maximum file size in bytes
     * @param int    $expiresInSeconds  Authorization validity period
     * @return array{url: string, fields: array<string, string>}|null  Presigned URL + form fields
     */
    public function createUploadAuthorization(string $key, string $contentType, int $maxSize, int $expiresInSeconds = 900): ?array;

    /**
     * Return the provider name (e.g. 'local', 's3', 'r2').
     */
    public function getProviderName(): string;
}
