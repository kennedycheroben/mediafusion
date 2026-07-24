<?php
declare(strict_types=1);
require_once __DIR__ . '/backend/bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header("Location: history.php");
    exit;
}

$error = $_SESSION['auth_error'] ?? '';
unset($_SESSION['auth_error']);

$pageTitle  = 'Register — MediaFusion';
$activePage = 'auth';
include __DIR__ . '/header.php';
?>

<div class="portal-wrap">
    <div class="video-bg-container" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; z-index: 0; pointer-events: none;">
        <video class="video-bg-content" style="width: 100%; height: 100%; object-fit: cover;" autoplay loop muted playsinline poster="assets/img/hero_bg.png">
            <source src="assets/video/bg_video_part3.webm" type="video/webm">
            <source src="assets/video/bg_video_part3.mp4" type="video/mp4">
        </video>
    </div>
    <div class="portal-card">
        <div class="glass-card card-magenta">
            <div class="text-center mb-4">
                <img src="assets/img/logo.webp" alt="MediaFusion Logo" class="portal-logo portal-logo-magenta mb-3">
                <h1 class="text-gradient-magenta" style="font-size:1.7rem;">Register</h1>
                <p class="text-secondary" style="font-size:.88rem;margin-top:.35rem;">Create your profile to start sharing your media.</p>
            </div>

            <?php if ($error): ?>
            <div class="cyber-alert mb-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form action="backend/auth_handler.php" method="POST" id="registerForm" autocomplete="off" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <div class="mb-3">
                    <label for="r-user" class="form-label text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:1px;">Username</label>
                    <div class="input-group">
                        <span class="input-group-text ig-icon ig-icon-magenta"><i class="fa-solid fa-user-astronaut"></i></span>
                        <input type="text" id="r-user" name="username" class="form-control form-control-cyber magenta-focus" placeholder="Insert username" required autocomplete="username" minlength="3">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="r-email" class="form-label text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:1px;">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text ig-icon ig-icon-magenta"><i class="fa-solid fa-envelope"></i></span>
                        <input type="email" id="r-email" name="email" class="form-control form-control-cyber magenta-focus" placeholder="johnkennedy@gmail.com" required autocomplete="email">
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
                    Create Account <i class="fa-solid fa-user-plus ms-2"></i>
                </button>
            </form>

            <!-- Google OAuth Divider -->
            <div class="d-flex align-items-center my-3 gap-2">
                <div style="flex:1;height:1px;background:var(--glass-border,rgba(255,255,255,.15));"></div>
                <span class="text-secondary" style="font-size:.78rem;letter-spacing:.5px;white-space:nowrap;">OR CONTINUE WITH</span>
                <div style="flex:1;height:1px;background:var(--glass-border,rgba(255,255,255,.15));"></div>
            </div>

            <a href="backend/google_auth.php?action=redirect" id="googleSignUpBtn"
               style="
                   display:flex; align-items:center; justify-content:center; gap:.65rem;
                   width:100%; padding:.78rem 1rem;
                   background:rgba(255,255,255,.06);
                   border:1px solid rgba(255,255,255,.18);
                   border-radius:12px;
                   color:inherit; text-decoration:none;
                   font-size:.92rem; font-weight:500; letter-spacing:.3px;
                   transition: background .2s, border-color .2s, transform .15s, box-shadow .2s;
               "
               onmouseover="this.style.background='rgba(255,255,255,.13)';this.style.borderColor='rgba(255,0,255,.5)';this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(255,0,255,.15)';"
               onmouseout="this.style.background='rgba(255,255,255,.06)';this.style.borderColor='rgba(255,255,255,.18)';this.style.transform='';this.style.boxShadow='';">
                <svg width="20" height="20" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.35-8.16 2.35-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                    <path fill="none" d="M0 0h48v48H0z"/>
                </svg>
                Continue with Google
            </a>

            <hr class="cyber-hr">
            <p class="text-center text-secondary mb-0" style="font-size:.9rem;">Already have Account? <a href="login.php" class="auth-link auth-link-magenta ms-1">Login</a></p>
        </div>
    </div>
</div>

<?php include_once 'includes/footer.php'; ?>
