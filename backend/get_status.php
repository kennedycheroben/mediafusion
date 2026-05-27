<?php
declare(strict_types=1);

/**
 * backend/get_status.php
 * Returns JSON statuses for the current user's uploads.
 *
 * Output shape:
 * { "success": true, "uploads": [ { "id": 123, "status": "pending", "platforms": ["youtube"], "updated_at": "..." }, ... ] }
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/db.php';

try {
    $stmt = $pdo->prepare("SELECT id, status, platforms, updated_at, created_at FROM uploads WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
    $stmt->execute([$_SESSION['user_id']]);
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

