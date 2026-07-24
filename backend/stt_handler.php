<?php
/**
 * stt_handler.php
 * Speech-to-text transcription handler using OpenAI Whisper API.
 * Returns timestamped segments from verbose_json format.
 *
 * Security:
 *  - Requires authenticated session (bootstrap.php)
 *  - CSRF token validated for state-changing POST
 *  - Rate-limited (paid API resource)
 *  - SSRF protection on remote URLs (scheme, host, IP validation)
 *  - MIME type validation on downloaded files
 *  - No provider errors exposed in production
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/bootstrap.php';

// ── 1. Authentication ────────────────────────────────────────────────────────
$userId = requireAuth();

// ── 2. CSRF Validation ───────────────────────────────────────────────────────
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN']
    ?? ($_POST['csrf_token'] ?? '');

if (empty($csrfToken) || !verify_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'CSRF_TOKEN_MISSING',
        'message' => 'Security token missing or invalid. Please refresh the page.'
    ]);
    exit;
}

// ── 3. Rate Limiting (paid OpenAI resource) ──────────────────────────────────
rateLimitApi();

// ── 4. API Key Check ─────────────────────────────────────────────────────────
$apiKey = getenv('OPENAI_API_KEY') !== false
    ? getenv('OPENAI_API_KEY')
    : ($_ENV['OPENAI_API_KEY'] ?? '');

if (empty($apiKey)) {
    echo json_encode([
        'success' => false,
        'error'   => 'API_KEY_MISSING',
        'message' => 'OpenAI API Key is not configured. Please define OPENAI_API_KEY in your .env file.'
    ]);
    exit;
}

// ── 5. Input Parsing & Validation ────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input)) {
    $input = [];
}

$mediaUrl = trim((string)($input['mediaUrl'] ?? ''));
$language = trim((string)($input['language'] ?? 'en'));

if ($mediaUrl === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'INVALID_INPUT',
        'message' => 'No media source provided.'
    ]);
    exit;
}

// Validate language: must be a short ISO 639 code (1-10 chars, lowercase letters/hyphens)
if (!preg_match('/^[a-z]{2,3}(-[a-zA-Z]{2,10})?$/', $language)) {
    echo json_encode([
        'success' => false,
        'error'   => 'INVALID_INPUT',
        'message' => 'Invalid language code.'
    ]);
    exit;
}

// ── 6. URL / Local File Resolution ───────────────────────────────────────────
$filePath  = '';
$tempFile  = null;
$isRemote  = preg_match('#^https?://#i', $mediaUrl) === 1;

if ($isRemote) {
    // ── Remote URL: SSRF protection ──────────────────────────────────────
    $parsed = parse_url($mediaUrl);
    if ($parsed === false || empty($parsed['host'])) {
        echo json_encode([
            'success' => false,
            'error'   => 'INVALID_INPUT',
            'message' => 'Invalid media URL format.'
        ]);
        exit;
    }

    $scheme = strtolower($parsed['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        echo json_encode([
            'success' => false,
            'error'   => 'INVALID_INPUT',
            'message' => 'Only HTTP and HTTPS URLs are supported.'
        ]);
        exit;
    }

    // Block dangerous URL components
    $host = $parsed['host'];
    if (str_contains($host, ':') || str_contains($host, "\0")) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Invalid hostname.']);
        exit;
    }

    // Block credentials in URL
    if (!empty($parsed['user']) || !empty($parsed['pass'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'URLs with embedded credentials are not allowed.']);
        exit;
    }

    // Block common SSRF targets by hostname
    $blockedHosts = [
        'localhost', '127.0.0.1', '::1', '[::1]',
        '0.0.0.0', 'metadata.google.internal',
        'instance-data', '169.254.169.254',
    ];
    if (in_array(strtolower($host), $blockedHosts, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Requests to internal addresses are not allowed.']);
        exit;
    }

    // Block link-local and metadata hostnames
    if (preg_match('/^fe[89a-f][0-9a-f]:/i', $host) || str_ends_with($host, '.local')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Requests to link-local addresses are not allowed.']);
        exit;
    }

    // Resolve hostname and validate IP is not private / reserved
    $resolvedIp = @gethostbyname($host);
    // gethostbyname returns input unchanged when it's already an IP or unresolvable.
    // Always validate the resolved IP regardless.
    if (filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
        if (!filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'INVALID_INPUT', 'message' => 'Requests to private/reserved IP ranges are not allowed.']);
            exit;
        }
    }

    // Download with SSRF-safe curl options (no redirect following, short timeout)
    $tempDir  = __DIR__ . '/../uploads/temp';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    $tempFile = $tempDir . '/stt_' . bin2hex(random_bytes(16)) . '.part';

    $ch = curl_init($mediaUrl);
    $fp = @fopen($tempFile, 'wb');
    if ($fp === false) {
        curl_close($ch);
        echo json_encode(['success' => false, 'error' => 'DOWNLOAD_FAILED', 'message' => 'Unable to create temporary file for download.']);
        exit;
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);

    curl_exec($ch);
    $dlError  = curl_error($ch);
    $dlErrno  = curl_errno($ch);
    $dlStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if ($dlErrno !== 0 || $dlStatus < 200 || $dlStatus >= 400) {
        @unlink($tempFile);
        echo json_encode([
            'success' => false,
            'error'   => 'DOWNLOAD_FAILED',
            'message' => 'Unable to download media from the provided URL (HTTP ' . $dlStatus . ').'
        ]);
        exit;
    }

    // Validate MIME type of downloaded content (block executables, scripts)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tempFile);
    $allowedMimes = [
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/flac',
        'audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/webm',
        'video/mp4', 'video/webm', 'video/quicktime',
    ];
    if (!in_array($mimeType, $allowedMimes, true)) {
        @unlink($tempFile);
        echo json_encode([
            'success' => false,
            'error'   => 'INVALID_FILE_TYPE',
            'message' => 'Downloaded file is not a supported audio/video type (detected: ' . htmlspecialchars($mimeType, ENT_QUOTES, 'UTF-8') . ').'
        ]);
        exit;
    }

    $filePath = $tempFile;

} else {
    // ── Local / relative file ────────────────────────────────────────────
    $cleaned   = ltrim($mediaUrl, '/');
    $localFile = realpath(__DIR__ . '/../' . $cleaned);

    // Block directory traversal: resolved path must remain inside project
    $projectRoot = realpath(__DIR__ . '/..');
    if ($localFile === false || $projectRoot === false || !str_starts_with($localFile, $projectRoot . DIRECTORY_SEPARATOR)) {
        echo json_encode([
            'success' => false,
            'error'   => 'FILE_NOT_FOUND',
            'message' => 'Media file could not be resolved locally.'
        ]);
        exit;
    }

    if (!is_file($localFile) || filesize($localFile) === 0) {
        echo json_encode([
            'success' => false,
            'error'   => 'FILE_NOT_FOUND',
            'message' => 'Media file could not be resolved locally.'
        ]);
        exit;
    }

    $filePath = $localFile;
}

// ── 7. Final file readability check ──────────────────────────────────────────
if (!is_readable($filePath)) {
    if ($tempFile !== null && is_file($tempFile)) {
        @unlink($tempFile);
    }
    echo json_encode([
        'success' => false,
        'error'   => 'FILE_READ_ERROR',
        'message' => 'Unable to read media source.'
    ]);
    exit;
}

// ── 8. Send to OpenAI Whisper API ────────────────────────────────────────────
try {
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS     => [
            'file'            => new CURLFile($filePath),
            'model'           => 'whisper-1',
            'language'        => $language,
            'response_format' => 'verbose_json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
} finally {
    // Always clean up temporary download
    if ($tempFile !== null && is_file($tempFile)) {
        @unlink($tempFile);
    }
}

// ── 9. Handle API response ───────────────────────────────────────────────────
if ($httpCode !== 200) {
    $errData = json_decode($response ?: '', true);
    $providerMessage = $errData['error']['message'] ?? null;

    if (APP_DEBUG && is_string($providerMessage)) {
        $userMessage = 'OpenAI API error: ' . $providerMessage;
    } else {
        $userMessage = 'Transcription service returned an error (HTTP ' . $httpCode . ').';
    }

    echo json_encode([
        'success' => false,
        'error'   => 'API_ERROR',
        'message' => $userMessage,
    ]);
    exit;
}

$transcription = json_decode($response ?: '', true);
if (!is_array($transcription)) {
    echo json_encode([
        'success' => false,
        'error'   => 'API_ERROR',
        'message' => 'Received an invalid response from the transcription service.'
    ]);
    exit;
}

echo json_encode([
    'success'  => true,
    'text'     => $transcription['text'] ?? '',
    'segments' => $transcription['segments'] ?? [],
]);
