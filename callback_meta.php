<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/db.php';

// Retrieve CSRF state and authorization code from request
$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';

// Error handling from provider
if (isset($_GET['error'])) {
    $err = htmlspecialchars((string)$_GET['error'], ENT_QUOTES, 'UTF-8');
    $desc = isset($_GET['error_description']) ? htmlspecialchars((string)$_GET['error_description'], ENT_QUOTES, 'UTF-8') : '';
    header("Location: connect.php?error=" . urlencode($err) . "&error_description=" . urlencode($desc));
    exit;
}

if (empty($state) || empty($code)) {
    header("Location: connect.php?error=invalid_request&error_description=Missing+state+or+code");
    exit;
}

// CSRF State validation
$expectedState = $_SESSION['meta_oauth_state'] ?? '';
if (empty($expectedState) || !hash_equals($expectedState, $state)) {
    header("Location: connect.php?error=invalid_state&error_description=CSRF+state+mismatch");
    exit;
}

// Token Exchange URL & parameters
$tokenUrl = 'https://graph.facebook.com/' . META_GRAPH_VERSION . '/oauth/access_token';
$params = [
    'client_id'     => META_APP_ID,
    'client_secret' => META_APP_SECRET,
    'redirect_uri'  => META_REDIRECT_URI,
    'code'          => $code
];

try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        header("Location: connect.php?error=curl_error&error_description=" . urlencode($curlError));
        exit;
    }

    $tokenData = json_decode($response, true);

    if (isset($tokenData['access_token'])) {
        $accessToken = $tokenData['access_token'];
        $expiresIn = $tokenData['expires_in'] ?? 5184000; // Default to 60 days if not set
        $tokenExpiry = date('Y-m-d H:i:s', time() + (int)$expiresIn);
        
        $userId = $_SESSION['user_id'] ?? 1;

        // Upsert to oauth_tokens table
        $stmt = $pdo->prepare("
            INSERT INTO oauth_tokens (user_id, platform, access_token, refresh_token, token_expiry)
            VALUES (:user_id, 'meta', :access_token, NULL, :token_expiry)
            ON DUPLICATE KEY UPDATE 
                access_token = VALUES(access_token),
                token_expiry = VALUES(token_expiry),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([
            ':user_id'      => (int)$userId,
            ':access_token' => (string)$accessToken,
            ':token_expiry' => $tokenExpiry
        ]);

        // Cleanup OAuth session states
        unset($_SESSION['meta_oauth_state']);

        header("Location: connect.php?status=success&platform=meta");
        exit;
    } else {
        $errorMsg = $tokenData['error']['message'] ?? ($tokenData['error'] ?? 'token_exchange_failed');
        $errorType = $tokenData['error']['type'] ?? 'OAuthException';
        header("Location: connect.php?error=" . urlencode($errorType) . "&error_description=" . urlencode($errorMsg));
        exit;
    }
} catch (Exception $e) {
    error_log('Meta OAuth callback exception: ' . $e->getMessage());
    header("Location: connect.php?error=exception&error_description=" . urlencode($e->getMessage()));
    exit;
}
