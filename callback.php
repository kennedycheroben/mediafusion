<?php
declare(strict_types=1);
require_once __DIR__ . '/backend/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];

function oauthStateKey(string $platform): string {
    return 'oauth_state_' . $platform;
}

$platform = $_SESSION['oauth_pending_platform'] ?? '';
$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';

if (empty($state) || empty($code)) {
    header("Location: connect.php?error=invalid_state");
    exit;
}

if ($platform === 'tiktok') {
    rateLimitPolicy('oauth_callback');

    $expectedState = $_SESSION[oauthStateKey('tiktok')] ?? '';
    // Use timing-safe comparison for OAuth state
    if (empty($expectedState) || !hash_equals($expectedState, $state)) {
        log_security_event('oauth_state_mismatch', 'platform=tiktok', $userId);
        header("Location: connect.php?error=invalid_state");
        exit;
    }

    $tokenUrl = 'https://open.tiktokapis.com/v2/oauth/token/';
    $postData = [
        'client_key' => TIKTOK_CLIENT_KEY,
        'client_secret' => TIKTOK_CLIENT_SECRET,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => REDIRECT_URI,
        'code_verifier' => $_SESSION['tiktok_code_verifier'] ?? ''
    ];

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            header("Location: connect.php?error=curl_error&error_description=" . urlencode($curlError));
            exit;
        }

        $tokenData = json_decode($response, true);
        
        $accessToken = $tokenData['access_token'] ?? ($tokenData['data']['access_token'] ?? null);
        $refreshToken = $tokenData['refresh_token'] ?? ($tokenData['data']['refresh_token'] ?? null);
        $expiresIn = $tokenData['expires_in'] ?? ($tokenData['data']['expires_in'] ?? null);

        if ($accessToken && $expiresIn !== null) {
            $tokenExpiry = date('Y-m-d H:i:s', time() + (int)$expiresIn);

            require_once __DIR__ . '/backend/token_crypto.php';
            $crypto = getTokenCrypto();
            $encAccess = $crypto->encrypt($accessToken);
            $encRefresh = $refreshToken !== null ? $crypto->encrypt($refreshToken) : null;

            $stmt = $pdo->prepare("INSERT INTO oauth_tokens (user_id, platform, access_token, refresh_token, token_expiry, token_status) VALUES (?, 'tiktok', ?, ?, ?, 'valid') ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), token_expiry = VALUES(token_expiry), token_status = 'valid'");
            $stmt->execute([
                $userId,
                $encAccess,
                $encRefresh,
                $tokenExpiry
            ]);

            unset($_SESSION[oauthStateKey('tiktok')]);
            unset($_SESSION['tiktok_code_verifier']);
            unset($_SESSION['oauth_pending_platform']);

            log_security_event('oauth_success', 'platform=tiktok', $userId);
            header("Location: connect.php?status=success&platform=tiktok");
            exit;
        } else {
            $errorMsg = $tokenData['error'] ?? ($tokenData['error_description'] ?? 'invalid_token_response');
            $errorDesc = $tokenData['error_description'] ?? '';
            header("Location: connect.php?error=" . urlencode((string)$errorMsg) . "&error_description=" . urlencode((string)$errorDesc));
            exit;
        }
    } catch (Exception $e) {
        error_log('TikTok OAuth callback exception: ' . $e->getMessage());
        header("Location: connect.php?error=exception&error_description=" . urlencode('An error occurred'));
        exit;
    }
} else {
    // Default or YouTube path
    rateLimitPolicy('oauth_callback');

    $expectedState = $_SESSION[oauthStateKey('youtube')] ?? '';
    // Use timing-safe comparison for OAuth state
    if (empty($expectedState) || !hash_equals($expectedState, $state)) {
        log_security_event('oauth_state_mismatch', 'platform=youtube', $userId);
        header("Location: connect.php?error=invalid_state");
        exit;
    }

    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $postData = [
        'code' => $code,
        'client_id' => YOUTUBE_CLIENT_ID,
        'client_secret' => YOUTUBE_CLIENT_SECRET,
        'redirect_uri' => REDIRECT_URI,
        'grant_type' => 'authorization_code'
    ];

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            header("Location: connect.php?error=curl_error&error_description=" . urlencode($curlError));
            exit;
        }

        $tokenData = json_decode($response, true);

        if (isset($tokenData['access_token'])) {
            $accessToken = $tokenData['access_token'];
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $expiresIn = $tokenData['expires_in'] ?? 3600;
            $tokenExpiry = date('Y-m-d H:i:s', time() + $expiresIn);

            require_once __DIR__ . '/backend/token_crypto.php';
            $crypto = getTokenCrypto();
            $encAccess = $crypto->encrypt($accessToken);
            $encRefresh = $refreshToken !== null ? $crypto->encrypt($refreshToken) : null;

            $stmt = $pdo->prepare("
                INSERT INTO oauth_tokens (user_id, platform, access_token, refresh_token, token_expiry, token_status)
                VALUES (:user_id, :platform, :access_token, :refresh_token, :token_expiry, :token_status)
                ON DUPLICATE KEY UPDATE 
                    access_token = VALUES(access_token),
                    refresh_token = COALESCE(VALUES(refresh_token), oauth_tokens.refresh_token),
                    token_expiry = VALUES(token_expiry),
                    token_status = VALUES(token_status)
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':platform' => 'youtube',
                ':access_token' => $encAccess,
                ':refresh_token' => $encRefresh,
                ':token_expiry' => $tokenExpiry,
                ':token_status' => 'valid'
            ]);
            
            unset($_SESSION[oauthStateKey('youtube')]);
            unset($_SESSION['oauth_pending_platform']);
            
            log_security_event('oauth_success', 'platform=youtube', $userId);
            header("Location: connect.php?status=success&platform=youtube");
            exit;
        } else {
            $errorMsg = $tokenData['error'] ?? 'unknown_error';
            $errorDesc = $tokenData['error_description'] ?? '';
            header("Location: connect.php?error=" . urlencode($errorMsg) . "&error_description=" . urlencode($errorDesc));
            exit;
        }
    } catch (PDOException $e) {
        error_log('YouTube OAuth token insert failed: ' . $e->getMessage());
        header("Location: connect.php?status=error&error=db_execution_failed");
        exit;
    }
}
