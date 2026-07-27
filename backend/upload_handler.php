<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$userId = requireAuth();
rateLimitPolicy('upload_file');

// CSRF validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

$tempDir = __DIR__ . '/../uploads/temp/';
$finalDir = __DIR__ . '/../uploads/videos/';
if (!is_dir($tempDir))  @mkdir($tempDir, 0755, true);
                                        
                                        if (!is_dir($finalDir)) @mkdir($finalDir, 0755, true);

// Resumable.js GET request (check if chunk exists)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $chunkNumber = $_GET['resumableChunkNumber'];
    $identifier = $_GET['resumableIdentifier'];
    
    $chunkFile = $tempDir . $identifier . '_' . $chunkNumber;
    
    if (file_exists($chunkFile)) {
        header("HTTP/1.0 200 OK");
        exit;
    } else {
        header("HTTP/1.0 404 Not Found");
        exit;
    }
}

// Resumable.js POST request (upload chunk)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chunkNumber = (int)$_POST['resumableChunkNumber'];
    $totalChunks = (int)$_POST['resumableTotalChunks'];
    $identifier = $_POST['resumableIdentifier'];
    $filename = $_POST['resumableFilename'];
    $fileSize = isset($_POST['resumableTotalSize']) ? (int)$_POST['resumableTotalSize'] : 0;
    
    $fileHash = hash('sha256', $identifier);
    $chunkFile = $tempDir . $identifier . '_' . $chunkNumber;

    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $freeSpace = disk_free_space($tempDir);
        if ($freeSpace !== false && $freeSpace < $_FILES['file']['size']) {
            header("HTTP/1.0 507 Insufficient Storage");
            echo "Upload failed: Server capacity limit reached.";
            exit;
        }

        if (move_uploaded_file($_FILES['file']['tmp_name'], $chunkFile)) {
            
            // Track incomplete upload
            if (isset($pdo)) {
                $stmt = $pdo->prepare("
                    INSERT INTO incomplete_uploads (user_id, file_hash, file_name, file_size, total_chunks, last_uploaded_chunk, temp_target_path, status)
                    VALUES (:user_id, :file_hash, :file_name, :file_size, :total_chunks, :chunk, :temp_path, 'processing')
                    ON DUPLICATE KEY UPDATE 
                        last_uploaded_chunk = GREATEST(last_uploaded_chunk, VALUES(last_uploaded_chunk)),
                        status = 'processing'
                ");
                $stmt->execute([
                    ':user_id' => $_SESSION['user_id'],
                    ':file_hash' => $fileHash,
                    ':file_name' => $filename,
                    ':file_size' => $fileSize,
                    ':total_chunks' => $totalChunks,
                    ':chunk' => $chunkNumber,
                    ':temp_path' => $tempDir . $identifier
                ]);
            }

            // Check if all chunks are uploaded
            $allChunksPresent = true;
            for ($i = 1; $i <= $totalChunks; $i++) {
                if (!file_exists($tempDir . $identifier . '_' . $i)) {
                    $allChunksPresent = false;
                    break;
                }
            }

            if ($allChunksPresent) {
                // Assemble file
                $finalFilePath = $finalDir . time() . '_' . preg_replace('/[^A-Za-z0-9.\-_]/', '', $filename);
                $out = fopen($finalFilePath, 'wb');
                
                if (!$out) {
                    header("HTTP/1.0 500 Internal Server Error");
                    echo "Failed to save file on the server.";
                    exit;
                }

                for ($i = 1; $i <= $totalChunks; $i++) {
                    $inPath = $tempDir . $identifier . '_' . $i;
                    $in = fopen($inPath, 'rb');
                    while ($buff = fread($in, 4096)) {
                        fwrite($out, $buff);
                    }
                    fclose($in);
                    unlink($inPath); // Delete chunk
                }
                fclose($out);
                
                if (isset($pdo)) {
                    $stmt = $pdo->prepare("UPDATE incomplete_uploads SET status = 'completed' WHERE file_hash = ? AND user_id = ?");
                    $stmt->execute([$fileHash, $_SESSION['user_id']]);
                }

                // Handle metadata sent from uploader.js
                $title = $_POST['title'] ?? 'Untitled';
                $description = $_POST['description'] ?? '';
                $platforms = $_POST['platforms'] ?? '[]'; // e.g. ["youtube", "tiktok"]

                // Upload to S3 Object Storage if enabled, otherwise fall back to local file path
                $dbFilePath = $finalFilePath;
                require_once __DIR__ . '/s3_client.php';
                $s3Client = new SimpleS3Client();
                if ($s3Client->isEnabled()) {
                    $s3Key = 'videos/' . time() . '_' . preg_replace('/[^A-Za-z0-9.\-_]/', '', $filename);
                    $uploadedUrl = $s3Client->uploadFile($finalFilePath, $s3Key, 'video/mp4');
                    if ($uploadedUrl) {
                        $dbFilePath = $uploadedUrl;
                        @unlink($finalFilePath); // Remove local file since it's hosted in the cloud
                    }
                }

                // Insert into Database
                $uploadId = 0;
                if (isset($pdo)) {
                    $stmt = $pdo->prepare("INSERT INTO uploads (user_id, filename, title, description, platforms, status, file_path) 
                                           VALUES (?, ?, ?, ?, ?, 'pending', ?)");
                    $stmt->execute([$_SESSION['user_id'], $filename, $title, $description, $platforms, $dbFilePath]);
                    $uploadId = $pdo->lastInsertId();
                } else {
                    // Mock ID if DB not set
                    $uploadId = rand(1000, 9999);
                }

                $pythonScript = escapeshellarg(__DIR__ . '/python/uploader.py');
                $uploadIdEscaped = escapeshellarg($uploadId);
                $logFile = escapeshellarg(__DIR__ . '/../uploads/python_upload.log');
                
                $pythonBinPath = __DIR__ . '/python/venv/bin/python3';
                if (file_exists($pythonBinPath) && is_executable($pythonBinPath)) {
                    $pythonBin = escapeshellarg($pythonBinPath);
                } else {
                    $pythonBin = 'python3';
                }
                
                $command = "env -u LD_LIBRARY_PATH $pythonBin $pythonScript $uploadIdEscaped > $logFile 2>&1 &";
                exec($command);

                echo "Upload complete.";
                exit;
            } else {
                echo "Uploaded successfully.";
                exit;
            }
        } else {
            header("HTTP/1.0 500 Internal Server Error");
            echo "Upload failed. Please try again.";
            exit;
        }
    } else {
        $errCode = $_FILES['file']['error'] ?? 'Unknown';
        header("HTTP/1.0 500 Internal Server Error");
        error_log("Failed to upload chunk: File upload error code " . $errCode);
        echo "Upload failed. Please try again.";
        exit;
    }
}
?>
