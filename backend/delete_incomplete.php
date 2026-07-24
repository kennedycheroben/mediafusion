<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

require_once 'db.php';
require_once __DIR__ . '/rate_limit.php';

rateLimitApi();

try {
    // Verify ownership and get info
    $stmt = $pdo->prepare("SELECT * FROM incomplete_uploads WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    $upload = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$upload) {
        http_response_code(404);
        echo json_encode(['error' => 'Record not found']);
        exit;
    }

    $tempPath = $upload['temp_target_path']; // e.g. /opt/lampp/htdocs/MediaFusion/uploads/temp/resumableIdentifier
    $totalChunks = (int)$upload['total_chunks'];

    // Clean up temporary chunks on disk
    for ($i = 1; $i <= $totalChunks; $i++) {
        $chunkFile = $tempPath . '_' . $i;
        if (file_exists($chunkFile)) {
            unlink($chunkFile);
        }
    }

    // Delete record from DB
    $deleteStmt = $pdo->prepare("DELETE FROM incomplete_uploads WHERE id = ?");
    $deleteStmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Upload canceled and temporary files deleted.']);
} catch (Exception $e) {
    error_log("Database error in delete_incomplete: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'A system error occurred. Please try again.']);
}
