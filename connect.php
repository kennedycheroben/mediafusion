<?php
declare(strict_types=1);
require_once __DIR__ . '/backend/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Shared header.php enforces auth for non-public pages.
$pageTitle  = 'Your Socials — MediaFusion';
$activePage = 'socials';
include __DIR__ . '/header.php';

$allowedPlatforms = ['youtube', 'meta', 'tiktok', 'facebook', 'instagram'];
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

// Disconnect connected social channel (POST with CSRF required)
if ($action === 'disconnect' && $platform !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    rateLimitPolicy('sensitive_social');

    // CSRF validation for state-changing action
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        $message = "<div class='alert alert-danger' style='background: rgba(255, 0, 0, 0.08); border: 1px solid #ff4444; color: #ff8080;'>Security token validation failed. Please refresh the page.</div>";
    } else {
        try {
            require_once __DIR__ . '/backend/db.php';
            $stmt = $pdo->prepare("DELETE FROM oauth_tokens WHERE user_id = ? AND platform = ?");
            $stmt->execute([$userId, $platform]);
            
            log_security_event('social_disconnected', "platform={$platform}", $userId);
            $safePlatform = htmlspecialchars(ucfirst($platform), ENT_QUOTES, 'UTF-8');
            $message = "<div class='alert alert-success' style='background: rgba(0, 255, 102, 0.08); border: 1px solid var(--neon-green); color: var(--neon-green);'>Successfully disconnected {$safePlatform} account.</div>";
        } catch (Exception $e) {
            error_log("Disconnect failed: " . $e->getMessage());
            $message = "<div class='alert alert-danger' style='background: rgba(255, 0, 0, 0.08); border: 1px solid #ff4444; color: #ff8080;'>Failed to disconnect. Please try again.</div>";
        }
    }
}

// Fetch connected platforms
$connectedPlatforms = [];
try {
    require_once __DIR__ . '/backend/db.php';
    if (isset($pdo)) {
        $stmt = $pdo->prepare("SELECT platform FROM oauth_tokens WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $connectedPlatforms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    // Fail-safe
}

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

    if ($platform === 'facebook') {
        // Redirect to Meta OAuth initiator with facebook scope set
        header('Location: connect_meta.php?platform=facebook');
        exit;
    }

    if ($platform === 'instagram') {
        // Redirect to Meta OAuth initiator with instagram scope set
        header('Location: connect_meta.php?platform=instagram');
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

// Legacy callback handling has been removed from connect.php to prevent mock token storage.
// OAuth callbacks are now handled by callback.php and callback_meta.php only.
?>

<main style="padding-top: 100px; min-height: 100vh;">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h1 class="display-5 text-gradient-magenta">Your Socials</h1>
            <p class="text-secondary">Securely connect your social accounts to start sharing videos.</p>
            <?= $message ?>
        </div>

        <div class="row g-4 justify-content-center">
            <!-- YouTube Card -->
            <div class="col-lg-3 col-md-6">
                <div class="glass-card text-center h-100 d-flex flex-column justify-content-between">
                    <div>
                        <i class="fa-brands fa-youtube fa-4x mb-4" style="color: var(--youtube-red); text-shadow: 0 0 15px var(--youtube-red);"></i>
                        <h3 class="mb-3">YouTube</h3>
                        <p class="text-secondary mb-4">Connect your YouTube channel to post your videos.</p>
                    </div>
                    <?php if (in_array('youtube', $connectedPlatforms)): ?>
                        <div>
                            <div class="badge bg-success border-0 mb-3 px-3 py-2 text-dark fw-bold w-100" style="background-color: var(--neon-green) !important;"><i class="fa-solid fa-circle-check me-1"></i> Connected</div>
                            <form method="POST" action="connect.php?action=disconnect&amp;platform=youtube" onsubmit="return confirm('Disconnect YouTube account?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100 py-2 fw-bold text-uppercase">Disconnect</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <a href="connect.php?action=start&amp;platform=youtube" class="btn-magnetic w-100" style="border-color: var(--youtube-red);">
                            Connect <i class="fa-solid fa-link ms-2"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TikTok Card -->
            <div class="col-lg-3 col-md-6">
                <div class="glass-card text-center h-100 d-flex flex-column justify-content-between">
                    <div>
                        <i class="fa-brands fa-tiktok fa-4x mb-4" style="color: #fff; text-shadow: -2px -2px 0 var(--tiktok-cyan), 2px 2px 0 var(--tiktok-pink);"></i>
                        <h3 class="mb-3">TikTok</h3>
                        <p class="text-secondary mb-4">Connect your TikTok account.</p>
                    </div>
                    <?php if (in_array('tiktok', $connectedPlatforms)): ?>
                        <div>
                            <div class="badge bg-success border-0 mb-3 px-3 py-2 text-dark fw-bold w-100" style="background-color: var(--neon-green) !important;"><i class="fa-solid fa-circle-check me-1"></i> Connected</div>
                            <form method="POST" action="connect.php?action=disconnect&amp;platform=tiktok" onsubmit="return confirm('Disconnect TikTok account?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100 py-2 fw-bold text-uppercase">Disconnect</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <a href="connect.php?action=start&amp;platform=tiktok" class="btn-magnetic w-100" style="border-color: var(--tiktok-cyan);">
                            Connect <i class="fa-solid fa-link ms-2"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Facebook Card -->
            <div class="col-lg-3 col-md-6">
                <div class="glass-card text-center h-100 d-flex flex-column justify-content-between">
                    <div>
                        <i class="fa-brands fa-facebook-f fa-4x mb-4" style="color: var(--meta-blue); text-shadow: 0 0 15px var(--meta-blue);"></i>
                        <h3 class="mb-3">Facebook</h3>
                        <p class="text-secondary mb-4">Post videos directly to your Facebook Page.</p>
                    </div>
                    <?php if (in_array('facebook', $connectedPlatforms)): ?>
                        <div>
                            <div class="badge bg-success border-0 mb-3 px-3 py-2 text-dark fw-bold w-100" style="background-color: var(--neon-green) !important;"><i class="fa-solid fa-circle-check me-1"></i> Connected</div>
                            <form method="POST" action="connect.php?action=disconnect&amp;platform=facebook" onsubmit="return confirm('Disconnect Facebook account?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100 py-2 fw-bold text-uppercase">Disconnect</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <a href="connect.php?action=start&amp;platform=facebook" class="btn-magnetic w-100" style="border-color: var(--meta-blue);">
                            Connect <i class="fa-solid fa-link ms-2"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Instagram Card -->
            <div class="col-lg-3 col-md-6">
                <div class="glass-card text-center h-100 d-flex flex-column justify-content-between">
                    <div>
                        <i class="fa-brands fa-instagram fa-4x mb-4" style="color: #e1306c; text-shadow: 0 0 15px rgba(225,48,108,0.6);"></i>
                        <h3 class="mb-3">Instagram</h3>
                        <p class="text-secondary mb-4">Post videos directly to your Instagram Business account.</p>
                    </div>
                    <?php if (in_array('instagram', $connectedPlatforms)): ?>
                        <div>
                            <div class="badge bg-success border-0 mb-3 px-3 py-2 text-dark fw-bold w-100" style="background-color: var(--neon-green) !important;"><i class="fa-solid fa-circle-check me-1"></i> Connected</div>
                            <form method="POST" action="connect.php?action=disconnect&amp;platform=instagram" onsubmit="return confirm('Disconnect Instagram account?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100 py-2 fw-bold text-uppercase">Disconnect</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <a href="connect.php?action=start&amp;platform=instagram" class="btn-magnetic w-100" style="border-color: #e1306c;">
                            Connect <i class="fa-solid fa-link ms-2"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include_once 'includes/footer.php'; ?>
