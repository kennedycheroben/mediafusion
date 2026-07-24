<?php
/**
 * backend/cutout_handler.php
 * Provider-based AI background removal and subject cutout router.
 * Supports remove.bg, Clipdrop, and Photoroom API adapters.
 *
 * Security:
 *  - Requires authenticated session
 *  - Rate-limited (paid API resource)
 *  - CSRF protection on state-changing operations
 *  - SSRF protection on remote URLs
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/bootstrap.php';

// ── 1. Authentication ────────────────────────────────────────────────────────
$userId = requireAuth();

// ── 2. CSRF Validation ───────────────────────────────────────────────────────
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN']
    ?? ($_POST['csrf_token'] ?? '');
if (!verify_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'CSRF_TOKEN_MISSING',
        'message' => 'Security token missing or invalid. Please refresh the page.'
    ]);
    exit;
}

// ── 3. Rate Limiting (paid API resource) ──────────────────────────────────────
rateLimitPolicy('processing_cutout');

// Fetch API Keys
$removeBgKey = getenv('REMOVE_BG_API_KEY') ?: $_ENV['REMOVE_BG_API_KEY'] ?? '';
$clipdropKey = getenv('CLIPDROP_API_KEY') ?: $_ENV['CLIPDROP_API_KEY'] ?? '';
$photoroomKey = getenv('PHOTOROOM_API_KEY') ?: $_ENV['PHOTOROOM_API_KEY'] ?? '';

// Check if any provider is configured
if (empty($removeBgKey) && empty($clipdropKey) && empty($photoroomKey)) {
    echo json_encode([
        'success' => false,
        'error' => 'PROVIDER_UNCONFIGURED',
        'message' => 'No AI Cutout providers are configured. Please define REMOVE_BG_API_KEY, CLIPDROP_API_KEY, or PHOTOROOM_API_KEY in your .env file.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) $input = [];
$mediaUrl = $input['mediaUrl'] ?? '';
$action = $input['action'] ?? 'background_removal';

if (empty($mediaUrl)) {
    echo json_encode([
        'success' => false,
        'error' => 'INVALID_INPUT',
        'message' => 'No media source provided.'
    ]);
    exit;
}

// Convert relative URL to absolute local path or download it
$filePath = '';
$tempFileCreated = false;

if (str_starts_with($mediaUrl, 'http')) {
    // Remote URL: SSRF protection
    $parsed = parse_url($mediaUrl);
    if ($parsed === false || empty($parsed['host'])) {
        echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Invalid URL format.']);
        exit;
    }

    $scheme = strtolower($parsed['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Only HTTP and HTTPS URLs are supported.']);
        exit;
    }

    // Block credentials in URL
    if (!empty($parsed['user']) || !empty($parsed['pass'])) {
        echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'URLs with embedded credentials are not allowed.']);
        exit;
    }

    // Block common SSRF targets
    $host = $parsed['host'];
    $blockedHosts = ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0', '169.254.169.254'];
    if (in_array(strtolower($host), $blockedHosts, true)) {
        echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Requests to internal addresses are not allowed.']);
        exit;
    }

    // Resolve and validate IP
    $resolvedIp = @gethostbyname($host);
    if (filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
        if (!filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Requests to private/reserved IP ranges are not allowed.']);
            exit;
        }
    }

    $tempDir = __DIR__ . '/../uploads/temp';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    $tempFile = $tempDir . '/cutout_' . bin2hex(random_bytes(16)) . '.jpg';

    $ch = curl_init($mediaUrl);
    $fp = @fopen($tempFile, 'wb');
    if ($fp === false) {
        curl_close($ch);
        echo json_encode(['success' => false, 'error' => 'DOWNLOAD_FAILED', 'message' => 'Unable to create temporary file for download.']);
        exit;
    }
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_exec($ch);
    $dlStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $dlErr = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($dlErr || $dlStatus < 200 || $dlStatus >= 400) {
        @unlink($tempFile);
        echo json_encode(['success' => false, 'error' => 'DOWNLOAD_FAILED', 'message' => 'Unable to download media from the provided URL.']);
        exit;
    }

    $filePath = $tempFile;
    $tempFileCreated = true;
} else {
    // Local relative file path — prevent directory traversal
    $cleaned = ltrim($mediaUrl, '/');
    $localFile = realpath(__DIR__ . '/../' . $cleaned);
    $projectRoot = realpath(__DIR__ . '/..');
    if ($localFile === false || $projectRoot === false || !str_starts_with($localFile, $projectRoot . DIRECTORY_SEPARATOR)) {
        echo json_encode(['success' => false, 'error' => 'FILE_NOT_FOUND', 'message' => 'Media file could not be resolved locally.']);
        exit;
    }
    if (is_file($localFile)) {
        $filePath = $localFile;
    } else {
        echo json_encode(['success' => false, 'error' => 'FILE_NOT_FOUND', 'message' => 'Media file could not be resolved locally.']);
        exit;
    }
}

// Check if file is readable
if (!is_file($filePath) || filesize($filePath) === 0) {
    echo json_encode(['success' => false, 'error' => 'FILE_READ_ERROR', 'message' => 'Unable to read or fetch media source.']);
    if ($tempFileCreated && is_file($filePath)) {
        @unlink($filePath);
    }
    exit;
}

// Ensure cutouts directory exists
$cutoutDir = __DIR__ . '/../uploads/cutouts';
if (!is_dir($cutoutDir)) {
    @mkdir($cutoutDir, 0755, true);
}
$outputFile = $cutoutDir . '/' . bin2hex(random_bytes(8)) . '.png';

$success = false;
$errorMessage = 'Unknown provider error';

// ── Dispatch to Configured Providers ──

if (!empty($photoroomKey)) {
    $ch = curl_init('https://sdk.photoroom.com/v1/segment');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . $photoroomKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $cFile = new CURLFile($filePath);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['image_file' => $cFile]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && !empty($response)) {
        file_put_contents($outputFile, $response);
        $success = true;
    } else {
        $errorMessage = 'Photoroom API returned HTTP ' . $httpCode;
    }
} elseif (!empty($removeBgKey)) {
    $ch = curl_init('https://api.remove.bg/v1.0/removebg');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Api-Key: ' . $removeBgKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $cFile = new CURLFile($filePath);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['image_file' => $cFile, 'size' => 'auto']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && !empty($response)) {
        file_put_contents($outputFile, $response);
        $success = true;
    } else {
        $errorMessage = 'remove.bg API returned HTTP ' . $httpCode;
    }
} elseif (!empty($clipdropKey)) {
    $ch = curl_init('https://clipdrop-api.co/remove-background/v1');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . $clipdropKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $cFile = new CURLFile($filePath);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['image_file' => $cFile]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && !empty($response)) {
        file_put_contents($outputFile, $response);
        $success = true;
    } else {
        $errorMessage = 'Clipdrop API returned HTTP ' . $httpCode;
    }
}

// Cleanup temp file
if ($tempFileCreated && is_file($filePath)) {
    @unlink($filePath);
}

if ($success) {
    echo json_encode([
        'success' => true,
        'cutoutUrl' => 'uploads/cutouts/' . basename($outputFile)
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'API_EXECUTION_FAILED',
        'message' => $errorMessage
    ]);
}
