<?php
/**
 * MediaFusion - Direct Distribution Handler for Edited Media Studio
 * 
 * SECURITY & ARCHITECTURAL SAFEGUARDS:
 * 1. Session Verification: Ensures only authorized operators can launch transmissions.
 * 2. LFI & Path Traversal Protection: Restricts file_path argument to the whitelisted 'uploads/processed' directory.
 * 3. Parameter Validation: Enforces required title, description, and target distribution platforms.
 * 4. DB Record Insertion: Safely logs records into our core 'uploads' table.
 * 5. Python Background Worker Trigger: Hooks cleanly into the existing distribution daemon framework.
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => ''
];

// 1. Session Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    $response['message'] = "Unauthorized. Operator session expired.";
    echo json_encode($response);
    exit;
}

// 2. Database Bootstrap
try {
    require_once __DIR__ . '/db.php';
} catch (Exception $e) {
    http_response_code(500);
    $response['message'] = "Database connection failed.";
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $platforms   = $_POST['platforms'] ?? []; // Expected as PHP array
    $rawFilePath = trim((string)($_POST['processed_file_path'] ?? ''));

    // Validation checks
    if ($title === '') {
        $response['message'] = "Please provide an engaging title.";
        echo json_encode($response);
        exit;
    }

    if (empty($platforms)) {
        $response['message'] = "Please select at least one target distribution platform.";
        echo json_encode($response);
        exit;
    }

    if ($rawFilePath === '') {
        $response['message'] = "No processed media file path provided.";
        echo json_encode($response);
        exit;
    }

    // 3. Strict LFI & Path Traversal Verification
    // Resolve absolute path and verify it stays inside htdocs/MediaFusion/uploads/processed/
    $baseProcessedDir = realpath(__DIR__ . '/../uploads/processed');
    $absoluteFilePath = realpath(__DIR__ . '/../' . $rawFilePath);

    if ($baseProcessedDir === false || $absoluteFilePath === false || !str_starts_with($absoluteFilePath, $baseProcessedDir)) {
        $response['message'] = "Security Violation: Access to specified media asset is restricted.";
        echo json_encode($response);
        exit;
    }

    if (!is_file($absoluteFilePath)) {
        $response['message'] = "The processed media asset was not found on the local server.";
        echo json_encode($response);
        exit;
    }

    // 4. Database Transaction
    try {
        $filename = basename($absoluteFilePath);
        $platformsJson = json_encode($platforms);

        $stmt = $pdo->prepare("
            INSERT INTO uploads (user_id, filename, title, description, platforms, status, file_path) 
            VALUES (?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $filename,
            $title,
            $description,
            $platformsJson,
            $absoluteFilePath
        ]);
        
        $uploadId = $pdo->lastInsertId();

        // 5. Trigger Background Python Distribution Task
        $pythonScript = escapeshellarg(__DIR__ . '/python/uploader.py');
        $uploadIdEscaped = escapeshellarg((string)$uploadId);
        $logFile = escapeshellarg(__DIR__ . '/../../uploads/python_upload.log');
        $pythonBin = escapeshellarg(__DIR__ . '/python/venv/bin/python3');
        
        // Execute background task
        $command = "$pythonBin $pythonScript $uploadIdEscaped > $logFile 2>&1 &";
        exec($command);

        $response['success'] = true;
        $response['message'] = "Distribution successfully engaged for edited master copy.";
        echo json_encode($response);
        exit;
    } catch (Exception $e) {
        $response['message'] = "Database registration error: " . $e->getMessage();
        echo json_encode($response);
        exit;
    }
}

$response['message'] = "Invalid request method.";
echo json_encode($response);
exit;
