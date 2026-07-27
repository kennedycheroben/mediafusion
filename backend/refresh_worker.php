<?php
declare(strict_types=1);

/**
 * backend/refresh_worker.php
 *
 * Token Heartbeat:
 * - Finds oauth_tokens expiring within 15 minutes
 * - Refreshes YouTube + Meta tokens
 * - Marks tokens invalid on failure (so UI can prompt reconnect)
 *
 * Run via CLI (recommended):
 *   php backend/refresh_worker.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/token_crypto.php';

function log_line(string $msg): void {
    $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
    $logPath = __DIR__ . '/../logs/refresh_worker.log';
    @file_put_contents($logPath, $line, FILE_APPEND);
}

function curl_post_form(string $url, array $fields, array $headers = []): array {
    $ch = curl_init($url);
    $payload = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    $baseHeaders = array_merge([
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ], $headers);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $baseHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $raw === false) {
        throw new RuntimeException("cURL error ({$errno}): {$err}");
    }

    $json = json_decode($raw, true);
    return ['http_code' => $code, 'raw' => $raw, 'json' => $json];
}

function mark_token_invalid(PDO $pdo, int $userId, string $platform, string $reason): void {
    // Be compatible with schemas that may not yet have token_status/last_error columns.
    try {
        $pdo->prepare("UPDATE oauth_tokens SET token_status = 'invalid', last_error = ?, updated_at = NOW() WHERE user_id = ? AND platform = ?")
            ->execute([$reason, $userId, $platform]);
        return;
    } catch (Throwable $e) {
        // fallback: at least expire the token to force reconnect
    }

    try {
        $pdo->prepare("UPDATE oauth_tokens SET token_expiry = NOW() WHERE user_id = ? AND platform = ?")
            ->execute([$userId, $platform]);
    } catch (Throwable $e) {
        // last resort: no-op
    }
}

function refresh_youtube(PDO $pdo, array $row): void {
    $userId = (int)$row['user_id'];
    $rawRefresh = (string)($row['refresh_token'] ?? '');
    if ($rawRefresh === '') {
        mark_token_invalid($pdo, $userId, 'youtube', 'missing_refresh_token');
        return;
    }
    if (YOUTUBE_CLIENT_ID === '' || YOUTUBE_CLIENT_SECRET === '') {
        mark_token_invalid($pdo, $userId, 'youtube', 'missing_google_client_credentials');
        return;
    }

    try {
        $crypto = getTokenCrypto();
        $refreshToken = $crypto->decryptIfNeeded($rawRefresh);
    } catch (Throwable $e) {
        mark_token_invalid($pdo, $userId, 'youtube', 'decryption_failed');
        log_line("YouTube decrypt failed for user {$userId}: " . $e->getMessage());
        return;
    }

    $resp = curl_post_form('https://oauth2.googleapis.com/token', [
        'client_id' => YOUTUBE_CLIENT_ID,
        'client_secret' => YOUTUBE_CLIENT_SECRET,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ]);

    // Clear plaintext from memory
    $refreshToken = null;

    $data = $resp['json'];
    if (!is_array($data) || empty($data['access_token'])) {
        mark_token_invalid($pdo, $userId, 'youtube', 'refresh_failed');
        log_line("YouTube refresh failed for user {$userId}: " . $resp['raw']);
        return;
    }

    $accessToken = (string)$data['access_token'];
    $expiresIn = (int)($data['expires_in'] ?? 3600);

    try {
        $encAccess = $crypto->encrypt($accessToken);
    } catch (Throwable $e) {
        log_line("YouTube encrypt failed for user {$userId}: " . $e->getMessage());
        return;
    }

    // Clear plaintext from memory
    $accessToken = null;

    $pdo->prepare(
        "UPDATE oauth_tokens
         SET access_token = ?, token_expiry = DATE_ADD(NOW(), INTERVAL ? SECOND), token_status = 'valid', updated_at = NOW()
         WHERE user_id = ? AND platform = 'youtube'"
    )->execute([$encAccess, $expiresIn, $userId]);

    log_line("YouTube token refreshed for user {$userId} (expires_in={$expiresIn}s)");
}

function refresh_meta(PDO $pdo, array $row): void {
    // Meta does not use a classic OAuth2 refresh_token for long-lived user/page tokens.
    // We treat oauth_tokens.refresh_token as the *current long-lived token* to exchange.
    $userId = (int)$row['user_id'];
    $rawExchange = (string)($row['refresh_token'] ?? '');
    if ($rawExchange === '') {
        mark_token_invalid($pdo, $userId, 'meta', 'missing_exchange_token');
        return;
    }
    if (META_APP_ID === '' || META_APP_SECRET === '') {
        mark_token_invalid($pdo, $userId, 'meta', 'missing_meta_app_credentials');
        return;
    }

    try {
        $crypto = getTokenCrypto();
        $exchangeToken = $crypto->decryptIfNeeded($rawExchange);
    } catch (Throwable $e) {
        mark_token_invalid($pdo, $userId, 'meta', 'decryption_failed');
        log_line("Meta decrypt failed for user {$userId}: " . $e->getMessage());
        return;
    }

    $url = 'https://graph.facebook.com/' . META_GRAPH_VERSION . '/oauth/access_token';
    $resp = curl_post_form($url, [
        'grant_type' => 'fb_exchange_token',
        'client_id' => META_APP_ID,
        'client_secret' => META_APP_SECRET,
        'fb_exchange_token' => $exchangeToken,
    ]);

    // Clear plaintext from memory
    $exchangeToken = null;

    $data = $resp['json'];
    if (!is_array($data) || empty($data['access_token'])) {
        mark_token_invalid($pdo, $userId, 'meta', 'refresh_failed');
        log_line("Meta refresh failed for user {$userId}: " . $resp['raw']);
        return;
    }

    $accessToken = (string)$data['access_token'];
    $expiresIn = (int)($data['expires_in'] ?? 60 * 60 * 24 * 60); // Meta long-lived often ~60 days

    try {
        $encAccess = $crypto->encrypt($accessToken);
    } catch (Throwable $e) {
        log_line("Meta encrypt failed for user {$userId}: " . $e->getMessage());
        return;
    }

    // Clear plaintext from memory
    $accessToken = null;

    $pdo->prepare(
        "UPDATE oauth_tokens
         SET access_token = ?, token_expiry = DATE_ADD(NOW(), INTERVAL ? SECOND), token_status = 'valid', updated_at = NOW()
         WHERE user_id = ? AND platform = 'meta'"
    )->execute([$encAccess, $expiresIn, $userId]);

    log_line("Meta token refreshed for user {$userId} (expires_in={$expiresIn}s)");
}

try {
    // Ensure logs dir exists (safe permissions recommended outside editor)
    if (!is_dir(__DIR__ . '/../logs')) {
        @mkdir(__DIR__ . '/../logs', 0750, true);
    }

    $stmt = $pdo->query(
        "SELECT user_id, platform, access_token, refresh_token, token_expiry
         FROM oauth_tokens
         WHERE token_expiry IS NOT NULL
           AND token_expiry <= DATE_ADD(NOW(), INTERVAL 15 MINUTE)"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $platform = (string)($row['platform'] ?? '');
        try {
            if ($platform === 'youtube') {
                refresh_youtube($pdo, $row);
            } elseif ($platform === 'meta') {
                refresh_meta($pdo, $row);
            } else {
                // TikTok etc handled later
                log_line("Skipping refresh for platform={$platform}");
            }
        } catch (Throwable $e) {
            $userId = (int)($row['user_id'] ?? 0);
            mark_token_invalid($pdo, $userId, $platform, 'exception_during_refresh');
            log_line("Exception refreshing {$platform} for user {$userId}: " . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    log_line('refresh_worker fatal: ' . $e->getMessage());
    exit(1);
}

