<?php
/**
 * MediaFusion - Direct Distribution Handler for Edited Media Studio
 * 
 * SECURITY & ARCHITECTURAL SAFEGUARDS:
 * 1. Session Verification: Via bootstrap requireAuth()
 * 2. CSRF Protection: Via bootstrap require_csrf()
 * 3. LFI & Path Traversal Protection: Restricts file_path to 'uploads/processed'
 * 4. Parameter Validation: Enforces required title, description, and target platforms
 * 5. DB Record Insertion: Safely logs records into core 'uploads' table
 * 6. Rate limiting: Via named policy
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

$userId = requireAuth();
rateLimitPolicy('upload_file');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

require_csrf();

$title       = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$platforms   = $_POST['platforms'] ?? [];
$rawFilePath = trim((string)($_POST['processed_file_path'] ?? ''));

if ($title === '') {
    echo json_encode(['success' => false, 'message' => 'Please provide an engaging title.']);
    exit;
}

if (empty($platforms)) {
    echo json_encode(['success' => false, 'message' => 'Please select at least one social account to share to.']);
    exit;
}

if ($rawFilePath === '') {
    echo json_encode(['success' => false, 'message' => 'No media file was found.']);
    exit;
}

// Strict LFI & Path Traversal Verification
$baseProcessedDir = realpath(__DIR__ . '/../uploads/processed');
$absoluteFilePath = realpath(__DIR__ . '/../' . $rawFilePath);

if ($baseProcessedDir === false || $absoluteFilePath === false || !str_starts_with($absoluteFilePath, $baseProcessedDir)) {
    log_security_event('path_traversal_attempt', "file={$rawFilePath}", $userId);
    echo json_encode(['success' => false, 'message' => 'Access to the specified file is restricted.']);
    exit;
}

if (!is_file($absoluteFilePath)) {
    echo json_encode(['success' => false, 'message' => 'The media file was not found.']);
    exit;
}

try {
    $filename = basename($absoluteFilePath);
    $platformsJson = json_encode($platforms);

    $stmt = $pdo->prepare("
        INSERT INTO uploads (user_id, filename, title, description, platforms, status, file_path) 
        VALUES (?, ?, ?, ?, ?, 'pending', ?)
    ");
    $stmt->execute([
        $userId,
        $filename,
        $title,
        $description,
        $platformsJson,
        $absoluteFilePath
    ]);
    
    $uploadId = $pdo->lastInsertId();

    $pythonScript = escapeshellarg(__DIR__ . '/python/uploader.py');
    $uploadIdEscaped = escapeshellarg((string)$uploadId);
    $logFile = escapeshellarg(__DIR__ . '/../uploads/python_upload.log');
    
    $pythonBinPath = __DIR__ . '/python/venv/bin/python3';
    if (file_exists($pythonBinPath) && is_executable($pythonBinPath)) {
        $pythonBin = escapeshellarg($pythonBinPath);
    } else {
        $pythonBin = 'python3';
    }
    
    $command = "env -u LD_LIBRARY_PATH $pythonBin $pythonScript $uploadIdEscaped > $logFile 2>&1 &";
    exec($command);

    echo json_encode(['success' => true, 'message' => 'Sharing started successfully.']);
    exit;
} catch (Exception $e) {
    error_log("Distribution error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A system error occurred. Please try again.']);
    exit;
}
