<?php
declare(strict_types=1);

namespace MediaFusion\Storage;

/**
 * MediaService — Centralized media management.
 *
 * Provides a single API for all media operations:
 * - Upload authorization
 * - Storage key generation
 * - Media metadata management
 * - Media access control
 * - Deletion and cleanup
 * - URL resolution
 *
 * Every media endpoint in the application should use this service
 * instead of directly manipulating files or database records.
 */
class MediaService
{
    private static ?MediaService $instance = null;

    private StorageManager $storage;
    private MediaUrlResolver $urlResolver;
    private \PDO $pdo;

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->storage = StorageManager::getInstance();
        $this->urlResolver = new MediaUrlResolver();
    }

    public static function getInstance(? \PDO $pdo = null): self
    {
        if (self::$instance === null) {
            if ($pdo === null) {
                throw new \RuntimeException('MediaService requires a PDO instance on first call');
            }
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    /**
     * Get the URL resolver.
     */
    public function urls(): MediaUrlResolver
    {
        return $this->urlResolver;
    }

    /**
     * Get the storage manager.
     */
    public function storage(): StorageManager
    {
        return $this->storage;
    }

    // ── Studio Media Operations ────────────────────────────────────────────

    /**
     * Upload a studio media file.
     *
     * @param int    $userId      Owner's user ID
     * @param array  $file        $_FILES entry
     * @return array{success: bool, message: string, media?: array}
     */
    public function uploadStudioMedia(int $userId, array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Upload failed.'];
        }

        $maxSize = 500 * 1024 * 1024; // 500 MB
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'message' => 'File exceeds maximum size of 500 MB.'];
        }

        if ($file['size'] < 1) {
            return ['success' => false, 'message' => 'Empty file rejected.'];
        }

        // Server-side MIME detection
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);

        $allowedMimes = [
            'video/mp4'       => ['type' => 'video', 'ext' => 'mp4'],
            'video/webm'      => ['type' => 'video', 'ext' => 'webm'],
            'video/quicktime' => ['type' => 'video', 'ext' => 'mov'],
            'video/x-msvideo' => ['type' => 'video', 'ext' => 'avi'],
            'video/x-matroska'=> ['type' => 'video', 'ext' => 'mkv'],
            'image/jpeg'      => ['type' => 'image', 'ext' => 'jpg'],
            'image/png'       => ['type' => 'image', 'ext' => 'png'],
            'image/gif'       => ['type' => 'image', 'ext' => 'gif'],
            'image/webp'      => ['type' => 'image', 'ext' => 'webp'],
            'audio/mpeg'      => ['type' => 'audio', 'ext' => 'mp3'],
            'audio/ogg'       => ['type' => 'audio', 'ext' => 'ogg'],
            'audio/wav'       => ['type' => 'audio', 'ext' => 'wav'],
            'audio/x-wav'     => ['type' => 'audio', 'ext' => 'wav'],
            'audio/aac'       => ['type' => 'audio', 'ext' => 'aac'],
            'audio/flac'      => ['type' => 'audio', 'ext' => 'flac'],
            'audio/x-m4a'     => ['type' => 'audio', 'ext' => 'm4a'],
        ];

        if (!array_key_exists($detectedMime, $allowedMimes)) {
            return ['success' => false, 'message' => 'File type not allowed.'];
        }

        $mimeInfo = $allowedMimes[$detectedMime];
        $mediaType = $mimeInfo['type'];
        $safeExt = $mimeInfo['ext'];

        // Generate server-controlled storage key
        $storageKey = $this->storage->generateKey($userId, 'media', $safeExt, 'original');
        $thumbnailKey = $this->storage->generateThumbnailKey($userId, 'media', 'jpg');

        // Upload to storage
        $result = $this->storage->putFile($file['tmp_name'], $storageKey, $detectedMime);
        if ($result === null) {
            return ['success' => false, 'message' => 'Failed to store file. Please try again.'];
        }

        // Generate thumbnail
        $thumbUrl = $this->generateThumbnail($file['tmp_name'], $storageKey, $mediaType, $userId);

        // Extract metadata
        $metadata = $this->extractMetadata($file['tmp_name'], $mediaType);

        // Sanitize display name
        $originalName = $file['name'] ?? 'Untitled';
        $displayName = preg_replace('/[<>&"\'\\/\\\\]/', '', pathinfo($originalName, PATHINFO_FILENAME));
        $displayName = trim($displayName) ?: 'Untitled';
        $displayName = mb_substr($displayName, 0, 200);

        // Insert database record
        $stmt = $this->pdo->prepare("
            INSERT INTO studio_media 
                (user_id, name, storage_name, storage_path, public_url, mime_type, media_type,
                 file_size, duration, width, height, fps, thumbnail_url,
                 storage_key, storage_provider, thumbnail_key, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ready')
        ");
        $stmt->execute([
            $userId,
            $displayName,
            basename($storageKey),
            '', // storage_path — no longer storing absolute path for new uploads
            $this->storage->getUrl($storageKey),
            $detectedMime,
            $mediaType,
            $file['size'],
            $metadata['duration'],
            $metadata['width'],
            $metadata['height'],
            $metadata['fps'],
            $thumbUrl,
            $storageKey,
            $this->storage->getProviderName(),
            $thumbnailKey,
        ]);

        $mediaId = (int)$this->pdo->lastInsertId();

        return [
            'success' => true,
            'message' => 'Upload successful.',
            'media' => [
                'id'           => $mediaId,
                'name'         => $displayName,
                'url'          => $this->storage->getUrl($storageKey),
                'thumbnailUrl' => $thumbUrl,
                'mediaType'    => $mediaType,
                'mimeType'     => $detectedMime,
                'fileSize'     => $file['size'],
                'duration'     => $metadata['duration'],
                'width'        => $metadata['width'],
                'height'       => $metadata['height'],
                'fps'          => $metadata['fps'],
            ],
        ];
    }

    /**
     * Create a direct upload authorization for browser-to-object-storage uploads.
     *
     * @param int    $userId     Owner's user ID
     * @param string $mediaType  video, image, audio
     * @param string $mimeType   Expected MIME type
     * @param string $filename   Original filename (for metadata only)
     * @param int    $fileSize   Expected file size
     * @return array{success: bool, message: string, authorization?: array, media_id?: int}
     */
    public function createDirectUpload(int $userId, string $mediaType, string $mimeType, string $filename, int $fileSize): array
    {
        $maxSize = 500 * 1024 * 1024;
        if ($fileSize > $maxSize) {
            return ['success' => false, 'message' => 'File exceeds maximum size.'];
        }

        if (!$this->storage->isUsingObjectStorage()) {
            return ['success' => false, 'message' => 'Direct upload requires object storage.'];
        }

        // Determine extension from MIME type
        $extMap = [
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'audio/mpeg' => 'mp3', 'audio/wav' => 'wav',
        ];
        $ext = $extMap[$mimeType] ?? 'bin';

        $storageKey = $this->storage->generateKey($userId, 'media', $ext, 'original');

        // Create pending media record
        $stmt = $this->pdo->prepare("
            INSERT INTO studio_media 
                (user_id, name, storage_name, storage_path, public_url, mime_type, media_type,
                 file_size, storage_key, storage_provider, status)
            VALUES (?, ?, ?, '', '', ?, ?, ?, ?, ?, 'pending')
        ");
        $displayName = preg_replace('/[<>&"\'\\/\\\\]/', '', pathinfo($filename, PATHINFO_FILENAME));
        $displayName = trim($displayName) ?: 'Untitled';
        $stmt->execute([
            $userId, $displayName, basename($storageKey), $mimeType, $mediaType,
            $fileSize, $storageKey, $this->storage->getProviderName()
        ]);
        $mediaId = (int)$this->pdo->lastInsertId();

        // Generate presigned upload authorization
        $auth = $this->storage->createUploadAuthorization($storageKey, $mimeType, $maxSize);
        if ($auth === null) {
            return ['success' => false, 'message' => 'Failed to generate upload authorization.'];
        }

        return [
            'success' => true,
            'message' => 'Upload authorization created.',
            'authorization' => $auth,
            'media_id' => $mediaId,
            'storage_key' => $storageKey,
        ];
    }

    /**
     * Complete a direct upload — verify and finalize.
     */
    public function completeDirectUpload(int $userId, int $mediaId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM studio_media WHERE id = ? AND user_id = ? AND status = 'pending'"
        );
        $stmt->execute([$mediaId, $userId]);
        $media = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$media) {
            return ['success' => false, 'message' => 'Media not found or already processed.'];
        }

        $key = $media['storage_key'];
        if (!$this->storage->exists($key)) {
            $this->updateMediaStatus($mediaId, 'failed');
            return ['success' => false, 'message' => 'Uploaded file not found in storage.'];
        }

        $this->updateMediaStatus($mediaId, 'uploaded');

        return [
            'success' => true,
            'message' => 'Upload verified. Processing will begin shortly.',
            'media_id' => $mediaId,
        ];
    }

    /**
     * Get a studio media record with ownership verification.
     */
    public function getStudioMedia(int $mediaId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM studio_media WHERE id = ? AND user_id = ?");
        $stmt->execute([$mediaId, $userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * List studio media for a user.
     */
    public function listStudioMedia(int $userId, string $type = '', string $search = '', string $sort = 'created_at', string $order = 'DESC'): array
    {
        $allowedSorts = ['created_at', 'name', 'file_size', 'duration', 'media_type'];
        $allowedOrders = ['ASC', 'DESC'];
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'created_at';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $params = [$userId];
        $where = ['user_id = ?'];

        if ($type !== '' && in_array($type, ['video', 'image', 'audio'], true)) {
            $where[] = 'media_type = ?';
            $params[] = $type;
        }
        if ($search !== '') {
            $where[] = 'name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $sql = "SELECT id, name, public_url, thumbnail_url, mime_type, media_type, file_size,
                       duration, width, height, fps, created_at, storage_key, thumbnail_key
                FROM studio_media
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$sort} {$order}
                LIMIT 200";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Delete a studio media asset — database record + storage objects.
     */
    public function deleteStudioMedia(int $mediaId, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, storage_path, storage_key, thumbnail_url, thumbnail_key 
             FROM studio_media WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$mediaId, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['success' => false, 'message' => 'Media not found or permission denied.'];
        }

        // Delete from object storage (new system)
        if (!empty($row['storage_key'])) {
            $this->storage->delete($row['storage_key']);
        }
        if (!empty($row['thumbnail_key'])) {
            $this->storage->delete($row['thumbnail_key']);
        }

        // Delete from local storage (legacy)
        if (!empty($row['storage_path']) && is_file($row['storage_path'])) {
            @unlink($row['storage_path']);
        }
        if (!empty($row['thumbnail_url']) && !str_starts_with($row['thumbnail_url'], 'http')) {
            $thumbPath = dirname(__DIR__, 2) . '/' . ltrim($row['thumbnail_url'], '/');
            if (is_file($thumbPath)) {
                @unlink($thumbPath);
            }
        }

        $del = $this->pdo->prepare('DELETE FROM studio_media WHERE id = ? AND user_id = ?');
        $del->execute([$mediaId, $userId]);

        return ['success' => true, 'message' => 'Deleted successfully.'];
    }

    // ── Upload (Distribution) Operations ───────────────────────────────────

    /**
     * Get a distribution upload URL for a given filename.
     * Resolves legacy file_path and S3 URLs.
     */
    public function resolveDistributionUrl(array $uploadRow): string
    {
        return $this->urlResolver->resolveUpload($uploadRow);
    }

    // ── Processed Media Operations ─────────────────────────────────────────

    /**
     * Store processed media (FFmpeg output) and return its URL.
     *
     * @param string $localPath  Local file path of processed output
     * @param string $category   'processed', 'cutout', etc.
     * @param string $ext        File extension
     * @param int    $userId     Owner's user ID
     * @return array{key: string, url: string}|null
     */
    public function storeProcessedMedia(string $localPath, string $category, string $ext, int $userId): ?array
    {
        $key = $this->storage->generateKey($userId, $category, $ext, 'processed');
        $contentType = mime_content_type($localPath) ?: 'application/octet-stream';

        return $this->storage->putFile($localPath, $key, $contentType);
    }

    // ── Utility Methods ────────────────────────────────────────────────────

    /**
     * Update media status.
     */
    public function updateMediaStatus(int $mediaId, string $status): void
    {
        $allowed = ['pending', 'uploading', 'uploaded', 'processing', 'ready', 'failed', 'deleting', 'deleted'];
        if (!in_array($status, $allowed, true)) return;

        $stmt = $this->pdo->prepare("UPDATE studio_media SET status = ? WHERE id = ?");
        $stmt->execute([$status, $mediaId]);
    }

    /**
     * Generate a thumbnail for a media file.
     */
    private function generateThumbnail(string $sourcePath, string $storageKey, string $mediaType, int $userId): ?string
    {
        $thumbKey = $this->storage->generateThumbnailKey($userId, 'media', 'jpg');
        $tmpThumb = sys_get_temp_dir() . '/mf_thumb_' . bin2hex(random_bytes(8)) . '.jpg';

        try {
            if ($mediaType === 'video') {
                $cmd = "ffmpeg -ss 1 -i " . escapeshellarg($sourcePath) . " -vframes 1 -vf 'scale=480:-1' -q:v 3 " . escapeshellarg($tmpThumb) . " 2>/dev/null";
                shell_exec($cmd);
            } elseif ($mediaType === 'image') {
                $cmd = "ffmpeg -i " . escapeshellarg($sourcePath) . " -vf 'scale=480:-1' -q:v 3 " . escapeshellarg($tmpThumb) . " 2>/dev/null";
                shell_exec($cmd);
            } elseif ($mediaType === 'audio') {
                $cmd = "ffmpeg -i " . escapeshellarg($sourcePath) . " -filter_complex 'showwavespic=s=480x120:colors=#00f3ff' -frames:v 1 " . escapeshellarg($tmpThumb) . " 2>/dev/null";
                shell_exec($cmd);
            }

            if (is_file($tmpThumb) && filesize($tmpThumb) > 0) {
                $result = $this->storage->putFile($tmpThumb, $thumbKey, 'image/jpeg');
                @unlink($tmpThumb);
                if ($result !== null) {
                    return $this->storage->getUrl($thumbKey);
                }
            }
        } catch (\Throwable $e) {
            error_log("Thumbnail generation failed: " . $e->getMessage());
            @unlink($tmpThumb);
        }

        return null;
    }

    /**
     * Extract metadata from a media file via ffprobe.
     */
    private function extractMetadata(string $filePath, string $mediaType): array
    {
        $meta = ['duration' => null, 'width' => null, 'height' => null, 'fps' => null];

        $escapedPath = escapeshellarg($filePath);
        $cmd = "ffprobe -v quiet -print_format json -show_streams -show_format {$escapedPath} 2>/dev/null";
        $output = shell_exec($cmd);

        if (!$output) return $meta;

        $data = json_decode($output, true);
        if (!is_array($data)) return $meta;

        if (isset($data['format']['duration'])) {
            $meta['duration'] = round((float)$data['format']['duration'], 3);
        }

        foreach ($data['streams'] ?? [] as $stream) {
            $codecType = $stream['codec_type'] ?? '';
            if ($codecType === 'video' && $mediaType !== 'audio') {
                if (isset($stream['width']))  $meta['width']  = (int)$stream['width'];
                if (isset($stream['height'])) $meta['height'] = (int)$stream['height'];
                if (isset($stream['r_frame_rate'])) {
                    $parts = explode('/', $stream['r_frame_rate']);
                    if (count($parts) === 2 && (float)$parts[1] > 0) {
                        $meta['fps'] = round((float)$parts[0] / (float)$parts[1], 3);
                    }
                }
            }
            if ($codecType === 'audio' && $mediaType === 'audio') {
                if (!$meta['duration'] && isset($stream['duration'])) {
                    $meta['duration'] = round((float)$stream['duration'], 3);
                }
            }
        }

        if ($mediaType === 'image' && !$meta['width']) {
            $imgInfo = @getimagesize($filePath);
            if ($imgInfo) {
                $meta['width'] = $imgInfo[0];
                $meta['height'] = $imgInfo[1];
            }
        }

        return $meta;
    }
}
