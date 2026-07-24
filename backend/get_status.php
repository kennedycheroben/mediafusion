<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/**
 * backend/get_status.php
 * Returns JSON statuses for the current user's uploads.
 *
 * Output shape:
 * { "success": true, "uploads": [ { "id": 123, "status": "pending", "platforms": ["youtube"], "updated_at": "..." }, ... ] }
 */

header('Content-Type: application/json; charset=utf-8');

$userId = requireAuth();
rateLimitPolicy('api_polling');

// ETag for conditional requests (avoids re-sending unchanged data)
$cacheKey = 'status_' . $userId;
$etag = '"' . md5($cacheKey . floor(time() / 15)) . '"';
header("ETag: $etag");
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, status, video_url, platforms, updated_at, created_at FROM uploads WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $uploads = [];
    foreach ($rows as $r) {
        $platforms = [];
        if (isset($r['platforms'])) {
            $decoded = json_decode((string)$r['platforms'], true);
            if (is_array($decoded)) $platforms = $decoded;
        }
        $uploads[] = [
            'id' => (int)$r['id'],
            'status' => (string)($r['status'] ?? 'unknown'),
            'video_url' => $r['video_url'] ?? null,
            'platforms' => $platforms,
            'updated_at' => $r['updated_at'] ?? null,
            'created_at' => $r['created_at'] ?? null,
        ];
    }

    echo json_encode(['success' => true, 'uploads' => $uploads]);
    exit;
} catch (Throwable $e) {
    error_log('get_status failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}
