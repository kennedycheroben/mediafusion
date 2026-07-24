<?php
/**
 * Ensures all upload directories exist with proper permissions.
 * Invoked by Apache (user daemon) so directories are created with daemon ownership.
 */
declare(strict_types=1);

function initStorageDirectories(): array
{
    $baseUploads = __DIR__ . '/../uploads/';
    $dirs = [
        $baseUploads . 'studio',
        $baseUploads . 'studio/thumbs',
        $baseUploads . 'videos',
        $baseUploads . 'temp',
        $baseUploads . 'avatars',
        $baseUploads . 'processed',
    ];

    $created = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (@mkdir($dir, 0777, true)) {
                $created[] = $dir;
            }
        } else {
            @chmod($dir, 0777);
        }
    }
    return $created;
}

$createdDirs = initStorageDirectories();

// Only emit JSON output if requested directly via script URL
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'init_storage.php') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'created' => $createdDirs, 'message' => 'Upload directories initialized.']);
}
