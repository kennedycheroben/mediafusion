<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

$userId = requireAuth();
rateLimitPolicy('api_polling');

$jobId = isset($_GET['job_id']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['job_id']) : '';
if (!$jobId) {
    echo json_encode(['status' => 'failed', 'message' => 'Invalid Job ID']);
    exit;
}

$metaFile = '/tmp/' . $jobId . '_meta.json';
$progressLog = '/tmp/' . $jobId . '_progress.log';

if (!is_file($metaFile)) {
    echo json_encode(['status' => 'failed', 'message' => 'Job metadata not found']);
    exit;
}

$meta = json_decode(file_get_contents($metaFile), true);

// Verify job ownership — prevent cross-user access
if (($meta['user_id'] ?? null) !== null && (int)($meta['user_id'] ?? 0) !== $userId) {
    log_security_event('idor_attempt', "export_job={$jobId}", $userId);
    echo json_encode(['status' => 'failed', 'message' => 'Job not found']);
    exit;
}

$totalDurationSeconds = floatval($meta['duration'] ?? 1);

$isEnded = false;
$outTimeUs = 0;

if (is_file($progressLog)) {
    $logLines = file($progressLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($logLines)) {
        foreach ($logLines as $line) {
            if (strpos($line, 'out_time_us=') === 0) {
                $outTimeUs = intval(substr($line, 12));
            }
            if (strpos($line, 'progress=end') === 0) {
                $isEnded = true;
            }
        }
    }
}

if ($isEnded) {
    if (is_file($meta['out_path']) && filesize($meta['out_path']) > 0) {
        echo json_encode([
            'status' => 'completed',
            'output_url' => $meta['output_url']
        ]);
    } else {
        echo json_encode([
            'status' => 'failed',
            'message' => 'FFmpeg completed but output file is missing or empty.'
        ]);
    }
    @unlink($metaFile);
    @unlink($progressLog);
    exit;
}

$outTimeSeconds = $outTimeUs / 1000000.0;
$percent = ($outTimeSeconds / $totalDurationSeconds) * 100;
if ($percent > 99) $percent = 99;

echo json_encode([
    'status' => 'processing',
    'percent' => $percent,
    'out_time' => $outTimeSeconds,
    'total_time' => $totalDurationSeconds
]);
