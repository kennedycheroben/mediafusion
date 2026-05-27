<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/db.php';

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
    $expectedState = $_SESSION[oauthStateKey('tiktok')] ?? '';
    if ($state !== $expectedState) {
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
        
        // Check for access token at root or in nested 'data' (TikTok v2 structure)
        $accessToken = $tokenData['access_token'] ?? ($tokenData['data']['access_token'] ?? null);
        $refreshToken = $tokenData['refresh_token'] ?? ($tokenData['data']['refresh_token'] ?? null);
        $expiresIn = $tokenData['expires_in'] ?? ($tokenData['data']['expires_in'] ?? null);

        if ($accessToken && $expiresIn !== null) {
            $tokenExpiry = date('Y-m-d H:i:s', time() + (int)$expiresIn);
            $userId = $_SESSION['user_id'] ?? 1;

            $stmt = $pdo->prepare("INSERT INTO oauth_tokens (user_id, platform, access_token, refresh_token, token_expiry, token_status) VALUES (?, 'tiktok', ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), token_expiry = VALUES(token_expiry), token_status = 'active', last_error = NULL");
            $stmt->execute([
                $userId,
                $accessToken,
                $refreshToken,
                $tokenExpiry
            ]);

            unset($_SESSION[oauthStateKey('tiktok')]);
            unset($_SESSION['tiktok_code_verifier']);
            unset($_SESSION['oauth_pending_platform']);

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
        header("Location: connect.php?error=exception&error_description=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    // Default or YouTube path
    $expectedState = $_SESSION[oauthStateKey('youtube')] ?? '';
    if ($state !== $expectedState) {
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

            $userId = $_SESSION['user_id'] ?? 1;

            $stmt = $pdo->prepare("
                INSERT INTO oauth_tokens (user_id, platform, access_token, refresh_token, token_expiry, status)
                VALUES (:user_id, :platform, :access_token, :refresh_token, :token_expiry, :status)
                ON DUPLICATE KEY UPDATE 
                    access_token = VALUES(access_token),
                    refresh_token = COALESCE(VALUES(refresh_token), oauth_tokens.refresh_token),
                    token_expiry = VALUES(token_expiry),
                    status = VALUES(status)
            ");
            $stmt->execute([
                ':user_id' => (int)$userId,
                ':platform' => 'youtube',
                ':access_token' => (string)$accessToken,
                ':refresh_token' => $refreshToken,
                ':token_expiry' => $tokenExpiry,
                ':status' => 'active'
            ]);
            
            unset($_SESSION[oauthStateKey('youtube')]);
            unset($_SESSION['oauth_pending_platform']);
            
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
