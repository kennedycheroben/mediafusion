<?php
/**
 * MediaFusion - Observability Health Endpoint
 * 
 * Performs proactive checks on critical system dependencies:
 * 1. Database Connectivity (latency check)
 * 2. Storage capacity & write status for uploads, temp, and avatar dirs
 * 3. FFmpeg installation & capability verification
 * 
 * Returns structured JSON with correlated log headers and correct HTTP status codes.
 */

declare(strict_types=1);

// Generate/capture Correlation ID for unified observability log aggregation
$correlationId = $_SERVER['HTTP_X_CORRELATION_ID'] ?? bin2hex(random_bytes(16));
header("X-Correlation-ID: $correlationId");

$health = [
    'status' => 'UP',
    'timestamp' => gmdate('Y-m-d\THis\Z'),
    'correlation_id' => $correlationId,
    'checks' => [],
];

$criticalFailure = false;

// 1. Database Integration Check
try {
    require_once __DIR__ . '/backend/db.php';
    if (isset($pdo)) {
        $startTime = microtime(true);
        $stmt = $pdo->query("SELECT 1");
        $stmt->execute();
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        $health['checks']['database'] = [
            'status' => 'OK',
            'latency_ms' => $duration
        ];
    } else {
        throw new Exception("PDO database driver is not initialized.");
    }
} catch (Exception $e) {
    $health['checks']['database'] = [
        'status' => 'ERROR',
        'message' => $e->getMessage()
    ];
    $criticalFailure = true;
}

// 2. Storage System Check
$storagePaths = [
    'videos' => __DIR__ . '/uploads/videos/',
    'temp' => __DIR__ . '/uploads/temp/',
    'avatars' => __DIR__ . '/uploads/avatars/'
];

$health['checks']['storage'] = [];
foreach ($storagePaths as $key => $path) {
    if (!is_dir($path)) {
        @mkdir($path, 0750, true);
    }
    
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    
    $freeSpace = $exists ? @disk_free_space($path) : false;
    $totalSpace = $exists ? @disk_total_space($path) : false;
    
    $health['checks']['storage'][$key] = [
        'exists' => $exists,
        'writable' => $writable,
        'free_space_bytes' => $freeSpace !== false ? $freeSpace : 'unknown',
        'total_space_bytes' => $totalSpace !== false ? $totalSpace : 'unknown',
        'status' => ($exists && $writable) ? 'OK' : 'ERROR'
    ];
    
    if (!$writable) {
        $criticalFailure = true;
    }
}

// 3. Media Processor (FFmpeg) Check
$ffmpegBin = '/usr/bin/ffmpeg';
$ffmpegAvailable = false;
$ffmpegVersion = 'unknown';

if (is_executable($ffmpegBin)) {
    $ffmpegAvailable = true;
    $output = [];
    exec("env -u LD_LIBRARY_PATH $ffmpegBin -version 2>&1", $output);
    if (!empty($output)) {
        $ffmpegVersion = $output[0];
    }
} else {
    // Fallback: search system path
    $whichOutput = shell_exec('which ffmpeg 2>/dev/null');
    if ($whichOutput) {
        $ffmpegAvailable = true;
        $ffmpegBin = trim($whichOutput);
        $output = [];
        exec("env -u LD_LIBRARY_PATH $ffmpegBin -version 2>&1", $output);
        if (!empty($output)) {
            $ffmpegVersion = $output[0];
        }
    }
}

$health['checks']['ffmpeg'] = [
    'status' => $ffmpegAvailable ? 'OK' : 'WARNING',
    'path' => $ffmpegBin,
    'version' => $ffmpegVersion
];

// Determine overall node status
if ($criticalFailure) {
    $health['status'] = 'DOWN';
    http_response_code(503); // Service Unavailable
} else {
    http_response_code(200); // OK
}

// Write health check metrics to correlation logs
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
$logLine = sprintf(
    "[%s] [Correlation-ID: %s] Status: %s. DB: %s. Storage: %s. FFmpeg: %s\n",
    gmdate('Y-m-d H:i:s'),
    $correlationId,
    $health['status'],
    $health['checks']['database']['status'],
    ($criticalFailure ? 'UNHEALTHY' : 'OK'),
    $health['checks']['ffmpeg']['status']
);
@file_put_contents($logDir . '/health.log', $logLine, FILE_APPEND);

header('Content-Type: application/json');
echo json_encode($health, JSON_PRETTY_PRINT);
