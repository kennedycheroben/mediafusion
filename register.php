<?php
session_start();
if (isset($_SESSION['user_id'])) { header("Location: history.php"); exit; }
$error = $_SESSION['auth_error'] ?? '';
unset($_SESSION['auth_error']);

$pageTitle  = 'Register — Unify Social Hub';
$activePage = 'auth';
include __DIR__ . '/header.php';
?>

<div class="portal-wrap">
    <div class="portal-card">
        <div class="glass-card card-magenta">
            <div class="text-center mb-4">
                <img src="assets/img/logo.png" alt="Unify Logo" class="portal-logo portal-logo-magenta mb-3">
                <h1 class="text-gradient-magenta" style="font-size:1.7rem;">Request Access</h1>
                <p class="text-secondary" style="font-size:.88rem;margin-top:.35rem;">Create your operator profile to begin distribution.</p>
            </div>

            <?php if ($error): ?>
            <div class="cyber-alert mb-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form action="backend/auth_handler.php" method="POST" id="registerForm" autocomplete="off" novalidate>
                <input type="hidden" name="action" value="register">
                <div class="mb-3">
                    <label for="r-user" class="form-label text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:1px;">Username</label>
                    <div class="input-group">
                        <span class="input-group-text ig-icon ig-icon-magenta"><i class="fa-solid fa-user-astronaut"></i></span>
                        <input type="text" id="r-user" name="username" class="form-control form-control-cyber magenta-focus" placeholder="Insert username" required autocomplete="username" minlength="3">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="r-pass" class="form-label text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:1px;">Password</label>
                    <div class="input-group">
                        <span class="input-group-text ig-icon ig-icon-magenta"><i class="fa-solid fa-key"></i></span>
                        <input type="password" id="r-pass" name="password" class="form-control form-control-cyber magenta-focus" placeholder="Create password" required autocomplete="new-password" minlength="6">
                        <button type="button" class="input-group-text pw-toggle pw-toggle-magenta" id="togglePwdR"><i class="fa-solid fa-eye" id="eyeIconR"></i></button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    <small class="text-secondary" id="strengthLabel" style="font-size:.72rem;"></small>
                </div>
                <div class="mb-4">
                    <label for="r-pass2" class="form-label text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:1px;">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text ig-icon ig-icon-magenta"><i class="fa-solid fa-shield-halved"></i></span>
                        <input type="password" id="r-pass2" name="password_confirm" class="form-control form-control-cyber magenta-focus" placeholder="Confirm password" required autocomplete="new-password">
                    </div>
                    <small class="text-danger d-none" id="matchErr" style="font-size:.78rem;">Passwords do not match.</small>
                </div>
                <button type="submit" class="btn-magnetic w-100" id="registerBtn" style="font-size:1rem;padding:.9rem;border-color:var(--neon-magenta);box-shadow:0 0 10px rgba(255,0,255,.2);">
                    Create Profile <i class="fa-solid fa-user-plus ms-2"></i>
                </button>
            </form>

            <hr class="cyber-hr">
            <p class="text-center text-secondary mb-0" style="font-size:.9rem;">Already have Account? <a href="login.php" class="auth-link auth-link-magenta ms-1">Login</a></p>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
