<?php
session_start();
if (isset($_SESSION['user_id'])) { header("Location: history.php"); exit; }
$error = $_SESSION['auth_error'] ?? '';
unset($_SESSION['auth_error']);

$pageTitle  = 'Login — MediaFusion';
$activePage = 'auth';
include __DIR__ . '/header.php';
?>

<div class="portal-wrap">
    <div class="portal-card">
        <div class="glass-card card-cyan">
            <div class="text-center mb-4">
                <img src="assets/img/logo.png" alt="Unify Logo" class="portal-logo portal-logo-cyan mb-3">
                <h1 class="text-gradient-cyan" style="font-size:1.7rem;">System Login</h1>
                <p class="text-secondary" style="font-size:.88rem;margin-top:.35rem;">Authenticate to access the distribution engine.</p>
            </div>

            <?php if ($error): ?>
            <div class="cyber-alert mb-4" role="alert" id="authAlert">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form action="backend/auth_handler.php" method="POST" id="loginForm" autocomplete="off">
                <input type="hidden" name="action" value="login">
                <div class="mb-3">
                    <label for="l-user" class="form-label text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:1px;">Username</label>
                    <div class="input-group">
                        <span class="input-group-text ig-icon ig-icon-cyan"><i class="fa-solid fa-user"></i></span>
                        <input type="text" id="l-user" name="username" class="form-control form-control-cyber" placeholder="User name" required autocomplete="username">
                    </div>
                </div>
                <div class="mb-4">
                    <label for="l-pass" class="form-label text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:1px;">Password</label>
                    <div class="input-group">
                        <span class="input-group-text ig-icon ig-icon-cyan"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" id="l-pass" name="password" class="form-control form-control-cyber" placeholder="insert your Password" required autocomplete="current-password">
                        <button type="button" class="input-group-text pw-toggle pw-toggle-cyan" id="togglePwd"><i class="fa-solid fa-eye" id="eyeIcon"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn-magnetic w-100" id="loginBtn" style="font-size:1rem;padding:.9rem;">
                    Initialize Access <i class="fa-solid fa-arrow-right-to-bracket ms-2"></i>
                </button>
            </form>

            <hr class="cyber-hr">
            <p class="text-center text-secondary mb-0" style="font-size:.85rem;">No account? <a href="register.php" class="auth-link auth-link-cyan ms-1">Register</a> <span class="mx-2 text-muted">|</span> Forgot password? <a href="request_password_reset.php" class="auth-link auth-link-cyan ms-1">Recover password</a></p>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
