<?php
session_start();
require_once __DIR__ . '/config.php';

// Shared header.php enforces auth for non-public pages.
$pageTitle  = 'Integration Vault — MediaFusion';
$activePage = 'vault';
include __DIR__ . '/header.php';

$allowedPlatforms = ['youtube', 'meta', 'tiktok'];
$platform = $_GET['platform'] ?? '';
$action   = $_GET['action'] ?? '';

if (!in_array($platform, $allowedPlatforms, true)) {
    $platform = '';
}

function oauthStateKey(string $platform): string {
    return 'oauth_state_' . $platform;
}

function generateOauthState(): string {
    // OAuth 2.0 state parameter for CSRF protection (Meta/Google requirement)
    return bin2hex(random_bytes(16));
}

function buildUrl(string $base, array $params): string {
    return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

$message = '';

// OAuth initiation (authorization request)
if ($action === 'start' && $platform !== '') {
    $state = generateOauthState();
    $_SESSION[oauthStateKey($platform)] = $state;
    // Providers typically redirect back ONLY to REDIRECT_URI (no extra query params),
    // so we persist the target platform in session for the callback handler.
    $_SESSION['oauth_pending_platform'] = $platform;

    if ($platform === 'youtube') {
        // Google OAuth 2.0 (YouTube Data API scopes are added later as needed)
        $authUrl = buildUrl('https://accounts.google.com/o/oauth2/v2/auth', [
            'client_id' => YOUTUBE_CLIENT_ID,
            'redirect_uri' => REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube.upload',
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);
        header('Location: ' . $authUrl);
        exit;
    }

    if ($platform === 'meta') {
        // Redirect to specialized Meta OAuth initiator
        header('Location: connect_meta.php');
        exit;
    }

    if ($platform === 'tiktok') {
        // TikTok OAuth 2.0 connection workflow
        $code_verifier = bin2hex(random_bytes(32));
        $_SESSION['tiktok_code_verifier'] = $code_verifier;

        $hash = hash('sha256', $code_verifier, true);
        $code_challenge = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');

        $authUrl = buildUrl('https://www.tiktok.com/v2/auth/authorize/', [
            'client_key' => TIKTOK_CLIENT_KEY,
            'scope' => 'user.info.basic,video.list,video.upload',
            'response_type' => 'code',
            'redirect_uri' => REDIRECT_URI,
            'state' => $state,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => 'S256',
        ]);
        header("Location: " . $authUrl);
        exit;
    }
}

// OAuth callback (authorization code response)
if (isset($_GET['error'])) {
    $err = htmlspecialchars((string)$_GET['error'], ENT_QUOTES, 'UTF-8');
    $desc = isset($_GET['error_description']) ? htmlspecialchars((string)$_GET['error_description'], ENT_QUOTES, 'UTF-8') : '';
    $message = "<div class='alert alert-danger' style='background: rgba(255, 0, 0, 0.08); border: 1px solid #ff4444; color: #ff8080;'>OAuth failed: {$err}" . ($desc !== '' ? " — {$desc}" : "") . "</div>";
} elseif (isset($_GET['code'], $_GET['state'])) {
    $code  = (string)$_GET['code'];
    $state = (string)$_GET['state'];

    $cbPlatform = $_GET['platform'] ?? ($_SESSION['oauth_pending_platform'] ?? '');
    unset($_SESSION['oauth_pending_platform']);

    if (!in_array($cbPlatform, $allowedPlatforms, true)) {
        $message = "<div class='alert alert-danger' style='background: rgba(255, 0, 0, 0.08); border: 1px solid #ff4444; color: #ff8080;'>OAuth callback missing platform context. Please reconnect from the Vault.</div>";
    } else {
        $expected = $_SESSION[oauthStateKey($cbPlatform)] ?? null;
        unset($_SESSION[oauthStateKey($cbPlatform)]);

        if (!is_string($expected) || !hash_equals($expected, $state)) {
            $message = "<div class='alert alert-danger' style='background: rgba(255, 0, 0, 0.08); border: 1px solid #ff4444; color: #ff8080;'>OAuth state verification failed. Please try connecting again.</div>";
        } else {
            // TODO (production): Exchange $code for access/refresh tokens using the provider's token endpoint over HTTPS.
            // For now, store a mock token to keep the UI flow functional without external calls.
            $mock_access_token  = "mock_token_" . time();
            $mock_refresh_token = "mock_refresh_" . time();

            try {
                require_once __DIR__ . '/backend/db.php';
                $stmt = $pdo->prepare(
                    "INSERT INTO oauth_tokens (user_id, platform, access_token, refresh_token, token_expiry)
                     VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
                     ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), token_expiry = VALUES(token_expiry)"
                );
                $stmt->execute([$_SESSION['user_id'], $cbPlatform, $mock_access_token, $mock_refresh_token]);
                $safePlatform = htmlspecialchars($cbPlatform, ENT_QUOTES, 'UTF-8');
                $message = "<div class='alert alert-success' style='background: rgba(0, 255, 102, 0.08); border: 1px solid var(--neon-green); color: var(--neon-green);'>Successfully connected {$safePlatform}.</div>";
            } catch (PDOException $e) {
                error_log('OAuth token insert failed: ' . $e->getMessage());
                $safePlatform = htmlspecialchars($cbPlatform, ENT_QUOTES, 'UTF-8');
                $message = "<div class='alert alert-warning' style='background: rgba(252, 238, 10, 0.06); border: 1px solid rgba(252, 238, 10, 0.35); color: #fff;'>OAuth callback verified for {$safePlatform}, but token storage is not configured yet.</div>";
            }
        }
    }
}
?>

<main style="padding-top: 100px; min-height: 100vh;">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h1 class="display-5 text-gradient-magenta">Integration Vault</h1>
            <p class="text-secondary">Securely connect your social platforms to the distribution engine.</p>
            <?= $message ?>
        </div>

        <div class="row g-4 justify-content-center">
            <!-- YouTube Card -->
            <div class="col-md-4">
                <div class="glass-card text-center h-100">
                    <i class="fa-brands fa-youtube fa-4x mb-4" style="color: var(--youtube-red); text-shadow: 0 0 15px var(--youtube-red);"></i>
                    <h3 class="mb-3">YouTube</h3>
                    <p class="text-secondary mb-4">Connect to your YouTube account for high-speed video uploading.</p>
                    <a href="connect.php?action=start&amp;platform=youtube" class="btn-magnetic w-100" style="border-color: var(--youtube-red);">
                        Connect <i class="fa-solid fa-link ms-2"></i>
                    </a>
                </div>
            </div>

            <!-- TikTok Card -->
            <div class="col-md-4">
                <div class="glass-card text-center h-100">
                    <i class="fa-brands fa-tiktok fa-4x mb-4" style="color: #fff; text-shadow: -2px -2px 0 var(--tiktok-cyan), 2px 2px 0 var(--tiktok-pink);"></i>
                    <h3 class="mb-3">TikTok</h3>
                    <p class="text-secondary mb-4">Connect your TikTok account.</p>
                    <a href="connect.php?action=start&amp;platform=tiktok" class="btn-magnetic w-100" style="border-color: var(--tiktok-cyan);">
                        Connect <i class="fa-solid fa-link ms-2"></i>
                    </a>
                </div>
            </div>

            <!-- Meta Card -->
            <div class="col-md-4">
                <div class="glass-card text-center h-100">
                    <i class="fa-brands fa-meta fa-4x mb-4" style="color: var(--meta-blue); text-shadow: 0 0 15px var(--meta-blue);"></i>
                    <h3 class="mb-3">Meta (FB & IG)</h3>
                    <p class="text-secondary mb-4">Link your Meta Pages.</p>
                    <a href="connect_meta.php" class="btn-magnetic w-100" style="border-color: var(--meta-blue);">
                        Connect <i class="fa-solid fa-link ms-2"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include_once 'includes/footer.php'; ?>
