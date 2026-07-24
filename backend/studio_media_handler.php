<?php
/**
 * backend/studio_media_handler.php
 *
 * Secure studio media upload and management endpoint.
 *
 * Now uses MediaService for storage operations while maintaining
 * backward compatibility with legacy local file references.
 *
 * Security model:
 *  - Session authentication required on every request
 *  - All ownership checks use user_id from session, never client input
 *  - Filenames are discarded; server generates controlled storage keys
 *  - MIME type is verified server-side via finfo
 *  - Extension derived from verified MIME type
 *  - Allowlist of MIME types enforced
 *  - Path traversal prevented via storage abstraction
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Auth guard ──────────────────────────────────────────────────────────────
$userId = requireAuth();
rateLimitPolicy('upload_studio');

require_once __DIR__ . '/storage/MediaService.php';
$mediaService = MediaService::getInstance($pdo);

// ── Router ──────────────────────────────────────────────────────────────────
$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

match($action) {
    'upload'   => handleUpload($userId, $mediaService),
    'list'     => handleList($userId, $mediaService),
    'rename'   => handleRename($userId),
    'delete'   => handleDelete($userId, $mediaService),
    'metadata' => handleMetadata($userId),
    default    => respond(false, 'Unknown action.')
};

// ── Upload Handler ───────────────────────────────────────────────────────────
function handleUpload(int $userId, MediaService $mediaService): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'POST required.');
    }

    if (!isset($_FILES['media_file'])) {
        respond(false, 'No file received.');
    }

    $result = $mediaService->uploadStudioMedia($userId, $_FILES['media_file']);

    if ($result['success']) {
        respond(true, $result['message'], $result['media']);
    } else {
        respond(false, $result['message']);
    }
}

// ── List Handler ─────────────────────────────────────────────────────────────
function handleList(int $userId, MediaService $mediaService): void
{
    global $pdo;
    $type   = trim((string)($_GET['type'] ?? $_POST['type'] ?? ''));
    $search = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));
    $sort   = trim((string)($_GET['sort'] ?? $_POST['sort'] ?? 'created_at'));
    $order  = strtoupper(trim((string)($_GET['order'] ?? $_POST['order'] ?? 'DESC')));

    $media = $mediaService->listStudioMedia($userId, $type, $search, $sort, $order);

    // Resolve URLs through MediaUrlResolver for each item
    $resolver = $mediaService->urls();
    foreach ($media as &$item) {
        $item['public_url'] = $resolver->resolveStudioMedia($item);
        $item['thumbnail_url'] = $resolver->resolveThumbnail($item);
    }
    unset($item);

    // Include uploads from general 'uploads' table for video/all filter
    if ($type === '' || $type === 'video') {
        try {
            $uParams = [$userId];
            $uWhere  = ['user_id = ?'];
            if ($search !== '') {
                $uWhere[]  = '(title LIKE ? OR filename LIKE ?)';
                $uParams[] = '%' . $search . '%';
                $uParams[] = '%' . $search . '%';
            }
            $uSql = 'SELECT id, title, filename, file_path, video_url, created_at
                     FROM uploads
                     WHERE ' . implode(' AND ', $uWhere) . '
                     ORDER BY created_at DESC LIMIT 100';
            $uStmt = $pdo->prepare($uSql);
            $uStmt->execute($uParams);
            $userUploads = $uStmt->fetchAll(PDO::FETCH_ASSOC);

            $existingUrls = array_column($media, 'public_url');

            foreach ($userUploads as $u) {
                $relUrl = '';
                $rawPath = $u['file_path'] ?? '';

                // If file_path is a full URL (S3), use it directly
                if (str_starts_with($rawPath, 'http://') || str_starts_with($rawPath, 'https://')) {
                    $relUrl = $rawPath;
                } elseif (preg_match('/uploads\/videos\/.+$/', $rawPath, $matches)) {
                    $relUrl = $matches[0];
                } elseif (preg_match('/uploads\/.+$/', $rawPath, $matches)) {
                    $relUrl = $matches[0];
                } elseif (!empty($u['filename'])) {
                    $relUrl = 'uploads/videos/' . basename($u['filename']);
                }

                if ($relUrl !== '' && !in_array($relUrl, $existingUrls, true)) {
                    $existingUrls[] = $relUrl;
                    $media[] = [
                        'id'            => 'upload_' . $u['id'],
                        'name'          => $u['title'] ?: ($u['filename'] ?: 'Uploaded Video'),
                        'public_url'    => $relUrl,
                        'thumbnail_url' => null,
                        'mime_type'     => 'video/mp4',
                        'media_type'    => 'video',
                        'file_size'     => null,
                        'duration'      => 15.0,
                        'width'         => 1920,
                        'height'        => 1080,
                        'fps'           => 30,
                        'created_at'    => $u['created_at']
                    ];
                }
            }
        } catch (\PDOException $e) {
            // Table might not exist
        }
    }

    respond(true, '', ['media' => $media]);
}

// ── Rename Handler ────────────────────────────────────────────────────────────
function handleRename(int $userId): void
{
    global $pdo;
    $id      = (int)($_POST['id'] ?? 0);
    $newName = trim((string)($_POST['name'] ?? ''));
    $newName = preg_replace('/[<>&"\'\\/\\\\]/', '', $newName);
    $newName = mb_substr(trim($newName), 0, 200);

    if ($id < 1 || $newName === '') {
        respond(false, 'Invalid request.');
    }

    $stmt = $pdo->prepare('UPDATE studio_media SET name = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$newName, $id, $userId]);

    if ($stmt->rowCount() === 0) {
        respond(false, 'Media not found or permission denied.');
    }

    respond(true, 'Renamed successfully.', ['name' => $newName]);
}

// ── Delete Handler ────────────────────────────────────────────────────────────
function handleDelete(int $userId, MediaService $mediaService): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) {
        respond(false, 'Invalid ID.');
    }

    $result = $mediaService->deleteStudioMedia($id, $userId);
    respond($result['success'], $result['message']);
}

// ── Metadata Handler ──────────────────────────────────────────────────────────
function handleMetadata(int $userId): void
{
    global $pdo;
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id < 1) {
        respond(false, 'Invalid ID.');
    }

    $stmt = $pdo->prepare('SELECT * FROM studio_media WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        respond(false, 'Media not found or permission denied.');
    }

    // Remove internal fields from public response
    unset($row['storage_path']);
    unset($row['storage_key']);
    respond(true, '', ['media' => $row]);
}

// ── JSON Response Helper ──────────────────────────────────────────────────────
function respond(bool $success, string $message = '', array $data = []): never
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}
