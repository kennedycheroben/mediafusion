<?php
/**
 * MediaFusion - SMTP-Driven Secure Password Reset Verification
 * 
 * CORE VERIFICATION DIRECTIVES:
 * 1. Safe Parameter Capture: Validates email and raw token from URL query string.
 * 2. Hash Verification: Recomputes SHA-256 token hash and matches database record.
 * 3. Expiration Constraints: Confirms the reset request is within the 1-hour window (expires_at > NOW()).
 * 4. DB Integrity Updates: Updates user's password_hash securely using native PASSWORD_DEFAULT.
 * 5. Replay Attack Prevention: Immediately deletes all reset tokens for the verified email upon success.
 * 6. Automated Redirection: Offers premium interface with dynamic count redirection back to login.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/backend/bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header("Location: history.php");
    exit;
}

rateLimitPolicy('auth_password_reset');

try {
    require_once __DIR__ . '/backend/db.php';
} catch (Exception $e) {
    die("System error: " . htmlspecialchars($e->getMessage()));
}

// Extract credentials
$email    = isset($_GET['email']) ? trim((string)$_GET['email']) : (isset($_POST['email']) ? trim((string)$_POST['email']) : '');
$rawToken = isset($_GET['token']) ? trim((string)$_GET['token']) : (isset($_POST['token']) ? trim((string)$_POST['token']) : '');

$errorMessage = '';
$successMessage = '';
$isValidRequest = false;
$tokenHash = hash('sha256', $rawToken);

// ----------------------------------------------------
// 1. Transaction Validation Check
// ----------------------------------------------------
if ($email === '' || $rawToken === '') {
    $errorMessage = "Invalid password reset link. Please check the URL.";
} else {
    // Query resets helper to find valid non-expired entries comparing with PHP current time
    $currentTime = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ? AND token_hash = ? AND expires_at > ?");
    $stmt->execute([$email, $tokenHash, $currentTime]);
    $resetRequest = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetRequest) {
        $errorMessage = "This reset link has either expired or is invalid. Please request a new one.";
    } else {
        $isValidRequest = true;
    }
}

// ----------------------------------------------------
// 2. Commit Updates POST Actions
// ----------------------------------------------------
if ($isValidRequest && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $newPass = $_POST['new_password'] ?? '';
    $confPass = $_POST['confirm_password'] ?? '';

    if (strlen($newPass) < 6) {
        $errorMessage = "New password must be at least 6 characters in length.";
    } elseif ($newPass !== $confPass) {
        $errorMessage = "Passwords do not match. Please verify your inputs.";
    } else {
        // Safe, native cryptographic hashing
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);

        try {
            $pdo->beginTransaction();

            // Step A: Update user password
            $update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
            $update->execute([$newHash, $email]);

            // Step B: Invalidate all existing sessions for this user (security requirement)
            $userStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $userStmt->execute([$email]);
            $resetUserId = (int)$userStmt->fetchColumn();
            if ($resetUserId > 0) {
                invalidate_all_user_sessions($resetUserId, 'password_reset');
            }

            // Step C: Expunge reset tokens to prevent replay attacks (critical security protocol)
            $delete = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
            $delete->execute([$email]);

            $pdo->commit();

            $successMessage = "Your password has been updated. Redirecting you to sign in shortly...";
            $isValidRequest = false; // Disable form render
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Password reset update failed: " . $e->getMessage());
            $errorMessage = "Could not update password. Please try again.";
        }
    }
}
$pageTitle  = 'Verify Password Reset — MediaFusion';
$activePage = 'auth';
include __DIR__ . '/header.php';
?>

<div class="portal-wrap">
    <div class="video-bg-container" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; z-index: 0; pointer-events: none;">
        <video class="video-bg-content" style="width: 100%; height: 100%; object-fit: cover;" autoplay loop muted playsinline poster="assets/img/hero_bg.png">
            <source src="assets/video/bg_video_part5.webm" type="video/webm">
            <source src="assets/video/bg_video_part5.mp4" type="video/mp4">
        </video>
    </div>
    <div class="portal-card">
        <div class="glass-card card-magenta">
            <div class="text-center mb-4">
                <i class="fa-solid fa-key fa-3x text-gradient-cyan mb-3" style="filter: drop-shadow(0 0 10px var(--neon-cyan));"></i>
                <h2 class="text-gradient-magenta" style="font-size:1.7rem;">Reset Password</h2>
                <p class="text-secondary" style="font-size:.88rem;margin-top:.35rem;">Enter your new password below.</p>
            </div>

            <!-- Alert systems -->
            <?php if ($successMessage !== ''): ?>
                <div class="success-alert mb-4">
                    <i class="fa-solid fa-circle-check"></i>
                    <div><?= $successMessage ?></div>
                </div>
                
                <script>
                    // Automated redirect to portal entry after 3 seconds
                    setTimeout(() => {
                        window.location.href = "login.php";
                    }, 3000);
                </script>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="cyber-alert mb-4">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><?= $errorMessage ?></div>
                </div>
            <?php endif; ?>

            <!-- Form segment -->
            <?php if ($isValidRequest): ?>
                <form method="POST" action="verify_password_reset.php" class="mt-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label class="form-label text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:1px;">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text ig-icon ig-icon-magenta"><i class="fa-solid fa-lock"></i></span>
                            <input type="password" name="new_password" class="form-control form-control-cyber" placeholder="Minimum 6 characters" required autocomplete="new-password">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:1px;">Confirm Password</label>
                        <div class="input-group">
                            <span class="input-group-text ig-icon ig-icon-magenta"><i class="fa-solid fa-shield-halved"></i></span>
                            <input type="password" name="confirm_password" class="form-control form-control-cyber" placeholder="Re-type new password" required autocomplete="new-password">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-magnetic w-100" style="font-size:1rem;padding:.9rem;border-color:var(--neon-magenta);box-shadow:0 0 10px rgba(255,0,255,.2);">
                        Update Password <i class="fa-solid fa-square-check ms-2"></i>
                    </button>
                </form>
            <?php endif; ?>

            <hr class="cyber-hr">
            <p class="text-center text-secondary mb-0" style="font-size:.85rem;">
                Remembered password? <a href="login.php" class="auth-link auth-link-magenta ms-1">Login</a>
            </p>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
