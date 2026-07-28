<?php
/**
 * google_auth.php — Google OAuth 2.0 handler for MediaFusion
 *
 * Two modes:
 *   ?action=redirect  — Build & redirect to Google consent screen
 *   ?action=callback  — Handle Google callback, log in or register user
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// ── Constants ────────────────────────────────────────────────────────────────
$clientId     = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : YOUTUBE_CLIENT_ID;
$clientSecret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : YOUTUBE_CLIENT_SECRET;
$redirectUri  = defined('GOOGLE_REDIRECT_URI') ? trim((string)GOOGLE_REDIRECT_URI) : (function () {
                    $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
                        ? $_SERVER['HTTP_X_FORWARDED_PROTO']
                        : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http'));
                    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
                    return $scheme . '://' . $host . $base . '/backend/google_auth.php';
                })();

$scopes = implode(' ', [
    'openid',
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile',
]);

$action = $_GET['action'] ?? '';
$isCallback = $action === 'callback' || isset($_GET['code']) || isset($_GET['error']);

// ── 1. REDIRECT — send user to Google ────────────────────────────────────────
if ($action === 'redirect') {
    rateLimitPolicy('oauth_init');

    if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
        $_SESSION['auth_error'] = 'Google sign-in is not configured yet. Please add Google OAuth credentials.';
        header('Location: ../login.php');
        exit;
    }

    // CSRF state token
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    $params = http_build_query([
        'client_id'             => $clientId,
        'redirect_uri'          => $redirectUri,
        'response_type'         => 'code',
        'scope'                 => $scopes,
        'access_type'           => 'online',
        'prompt'                => 'select_account',
        'state'                 => $state,
    ]);

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

// ── 2. CALLBACK — exchange code for user info ─────────────────────────────────
if ($isCallback) {
    rateLimitPolicy('oauth_callback');

    // CSRF validation
    $returnedState = $_GET['state'] ?? '';
    $savedState    = $_SESSION['google_oauth_state'] ?? '';
    unset($_SESSION['google_oauth_state']);

    if (empty($returnedState) || !hash_equals($savedState, $returnedState)) {
        $_SESSION['auth_error'] = 'Google sign-in failed: invalid state. Please try again.';
        header('Location: ../login.php');
        exit;
    }

    // Error from Google?
    if (isset($_GET['error'])) {
        $_SESSION['auth_error'] = 'Google sign-in was cancelled or denied.';
        header('Location: ../login.php');
        exit;
    }

    $code = $_GET['code'] ?? '';
    if (empty($code)) {
        $_SESSION['auth_error'] = 'Google sign-in failed: no authorization code received.';
        header('Location: ../login.php');
        exit;
    }

    // Exchange code → tokens
    $tokenResponse = _google_post('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]);

    if (empty($tokenResponse['access_token'])) {
        error_log('[google_auth] token exchange failed: ' . json_encode($tokenResponse));
        $_SESSION['auth_error'] = 'Google sign-in failed: could not obtain access token.';
        header('Location: ../login.php');
        exit;
    }

    // Fetch user profile
    $userInfo = _google_get(
        'https://www.googleapis.com/oauth2/v3/userinfo',
        $tokenResponse['access_token']
    );

    $googleId      = trim((string)($userInfo['sub'] ?? ''));
    $email         = trim((string)($userInfo['email'] ?? ''));
    $emailVerified = filter_var($userInfo['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
    $name          = trim((string)($userInfo['name'] ?? ''));
    $picture       = trim((string)($userInfo['picture'] ?? ''));

    if ($googleId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$emailVerified) {
        $_SESSION['auth_error'] = 'Google sign-in failed: could not retrieve your profile.';
        header('Location: ../login.php');
        exit;
    }

    if (!isset($pdo)) {
        $_SESSION['auth_error'] = 'Database connection error. Please try again.';
        header('Location: ../login.php');
        exit;
    }

    // ── Self-healing: ensure google_id & avatar_url columns exist ────────────
    try {
        $cols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('google_id', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(255) DEFAULT NULL UNIQUE");
        }
        if (!in_array('avatar_url', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar_url VARCHAR(512) DEFAULT NULL");
        }
        if (!in_array('email', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100) DEFAULT NULL UNIQUE");
        }
    } catch (Exception $e) {
        error_log('[google_auth] schema migration error: ' . $e->getMessage());
    }

    // ── Look up existing user by google_id OR email ───────────────────────────
    $stmt = $pdo->prepare("SELECT id, google_id FROM users WHERE google_id = ? OR email = ? LIMIT 1");
    $stmt->execute([$googleId, $email]);
    $user = $stmt->fetch();

    if ($user) {
        // Returning user — update google_id & avatar if needed
        if (empty($user['google_id'])) {
            $pdo->prepare("UPDATE users SET google_id = ?, avatar_url = ? WHERE id = ?")
                ->execute([$googleId, $picture, $user['id']]);
        } else {
            $pdo->prepare("UPDATE users SET avatar_url = ? WHERE id = ?")
                ->execute([$picture, $user['id']]);
        }

        $userId = (int)$user['id'];
        init_session_metadata($userId);
        log_security_event('login_success', 'provider=google', $userId);

    } else {
        // New user — auto-register
        // Derive a unique username from Google display name
        $baseUsername = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', strtolower($name)));
        if (!$baseUsername) {
            $baseUsername = preg_replace('/[^a-zA-Z0-9_]/', '', strstr($email, '@', true) ?: '');
        }
        $baseUsername = $baseUsername ?: 'user';
        if (strlen($baseUsername) < 3) $baseUsername = 'user_' . $baseUsername;

        $username = $baseUsername;
        $suffix   = 1;
        while (true) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $chk->execute([$username]);
            if (!$chk->fetch()) break;
            $username = $baseUsername . '_' . $suffix++;
        }

        // Random placeholder password (user can set one later from profile)
        $placeholderHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, google_id, avatar_url) VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt->execute([$username, $email, $placeholderHash, $googleId, $picture])) {
            $_SESSION['auth_error'] = 'Registration via Google failed. Please try again.';
            header('Location: ../register.php');
            exit;
        }

        $newUserId = (int)$pdo->lastInsertId();
        init_session_metadata($newUserId);
        log_security_event('user_registered', 'provider=google', $newUserId);
    }

    header('Location: ../history.php');
    exit;
}

// ── Fallback ──────────────────────────────────────────────────────────────────
header('Location: ../login.php');
exit;


// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * POST JSON to a Google endpoint and return decoded response array.
 * @param string $url
 * @param array  $data
 * @return array
 */
function _google_post(string $url, array $data): array {
    $body = http_build_query($data);

    if (!function_exists('curl_init')) {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 15,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        return json_decode((string)$response, true) ?? [];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$response, true) ?? [];
}

/**
 * GET a Google API endpoint with Bearer token authentication.
 * @param string $url
 * @param string $accessToken
 * @return array
 */
function _google_get(string $url, string $accessToken): array {
    if (!function_exists('curl_init')) {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => 'Authorization: Bearer ' . $accessToken . "\r\n",
                'timeout' => 15,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        return json_decode((string)$response, true) ?? [];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$response, true) ?? [];
}
