<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/FfmpegBuilder.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = requireAuth();
require_csrf();
rateLimitPolicy('processing_export');
enforce_concurrent_job_limit($userId, 'export');

$input = json_decode(file_get_contents('php://input') ?: '{}', true);

if (!$input || !isset($input['project_data'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid project data payload.']);
    exit;
}

$jobId = 'export_' . bin2hex(random_bytes(8));
$progressLog = '/tmp/' . $jobId . '_progress.log';
$outName = 'export_' . time() . '.mp4';
$uploadDir = dirname(__DIR__) . '/uploads';
$processedDir = $uploadDir . '/processed';

if (!is_dir($processedDir)) {
    mkdir($processedDir, 0755, true);
}
$outPath = $processedDir . '/' . $outName;

try {
    $builder = new FfmpegBuilder($input['project_data'], $input);
    $cmd = $builder->buildFfmpegCommand($outPath, $progressLog);
    
    $bgCmd = "nohup " . $cmd . " > /tmp/{$jobId}_ffmpeg.out 2>&1 &";
    exec($bgCmd);

    $metaFile = '/tmp/' . $jobId . '_meta.json';
    file_put_contents($metaFile, json_encode([
        'job_id' => $jobId,
        'out_name' => $outName,
        'out_path' => $outPath,
        'output_url' => 'uploads/processed/' . $outName,
        'duration' => $builder->getProjectDuration(),
        'user_id' => $userId,
        'type' => 'export'
    ]));

    echo json_encode(['success' => true, 'job_id' => $jobId]);
} catch (Exception $e) {
    error_log("Export job error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Export failed. Please try again.']);
}
