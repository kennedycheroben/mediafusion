<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: history.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentication</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="assets/img/logo.png" type="image/png">
    
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-glass">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <img src="assets/img/logo.png" alt="Unify Logo" class="brand-logo"> Unify
            </a>
            <div class="collapse navbar-collapse justify-content-end">
                <ul class="navbar-nav align-items-center gap-3">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="auth-container">
        <div class="glass-card auth-card">
            <div class="text-center mb-4">
                <img src="assets/img/logo.png" alt="Unify Logo" style="height: 60px; filter: drop-shadow(0 0 10px var(--neon-cyan));" class="mb-3">
                <h2 id="authTitle" class="text-gradient-cyan">System Login</h2>
                <p id="authSubtitle" class="text-secondary">Authenticate to access the distribution engine.</p>
            </div>

            <!-- Display Error Messages -->
            <?php if (isset($_SESSION['auth_error'])): ?>
                <div class="alert alert-danger" style="background: rgba(255, 0, 0, 0.1); border: 1px solid #ff4444; color: #ff4444;">
                    <?= htmlspecialchars($_SESSION['auth_error']) ?>
                </div>
                <?php unset($_SESSION['auth_error']); ?>
            <?php endif; ?>

            <form action="backend/auth_handler.php" method="POST" id="authForm">
                <input type="hidden" name="action" id="authAction" value="login">
                
                <div class="mb-3">
                    <label class="form-label text-secondary text-uppercase" style="font-size: 0.8rem; letter-spacing: 1px;">Username</label>
                    <div class="input-group">
                        <span class="input-group-text" style="background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); border-right: none; color: var(--neon-cyan);">
                            <i class="fa-solid fa-user"></i>
                        </span>
                        <input type="text" name="username" class="form-control form-control-cyber" style="border-left: none;" required placeholder="operator_name">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary text-uppercase" style="font-size: 0.8rem; letter-spacing: 1px;">Password</label>
                    <div class="input-group">
                        <span class="input-group-text" style="background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); border-right: none; color: var(--neon-cyan);">
                            <i class="fa-solid fa-lock"></i>
                        </span>
                        <input type="password" name="password" class="form-control form-control-cyber" style="border-left: none;" required placeholder="••••••••">
                    </div>
                </div>

                <button type="submit" class="btn-magnetic w-100" id="authBtn">
                    Initialize Access <i class="fa-solid fa-arrow-right-to-bracket ms-2"></i>
                </button>
            </form>

            <div class="text-center mt-4">
                <p class="text-secondary" id="toggleText">
                    No clearance? <span class="auth-toggle" onclick="toggleAuthMode()">Request Access</span>
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script>
        let isLogin = true;

        function toggleAuthMode() {
            const title = document.getElementById('authTitle');
            const subtitle = document.getElementById('authSubtitle');
            const action = document.getElementById('authAction');
            const btn = document.getElementById('authBtn');
            const toggleText = document.getElementById('toggleText');

            // Quick fade out animation
            gsap.to('.auth-card', { opacity: 0, y: -10, duration: 0.2, onComplete: () => {
                isLogin = !isLogin;
                if (isLogin) {
                    title.textContent = "System Login";
                    title.className = "text-gradient-cyan";
                    subtitle.textContent = "Authenticate to access the distribution engine.";
                    action.value = "login";
                    btn.innerHTML = 'Initialize Access <i class="fa-solid fa-arrow-right-to-bracket ms-2"></i>';
                    toggleText.innerHTML = 'No clearance? <span class="auth-toggle" onclick="toggleAuthMode()">Request Access</span>';
                } else {
                    title.textContent = "Register";
                    title.className = "text-gradient-magenta";
                    subtitle.textContent = "Create an operator profile.";
                    action.value = "register";
                    btn.innerHTML = 'Create Profile <i class="fa-solid fa-user-plus ms-2"></i>';
                    toggleText.innerHTML = 'Already have clearance? <span class="auth-toggle" onclick="toggleAuthMode()">System Login</span>';
                }
                // Fade back in
                gsap.to('.auth-card', { opacity: 1, y: 0, duration: 0.2 });
            }});
        }

        // Add magnetic effect for the button
        const btn = document.getElementById('authBtn');
        btn.addEventListener('mousemove', function(e) {
            const position = btn.getBoundingClientRect();
            const x = e.pageX - position.left - position.width / 2;
            const y = e.pageY - position.top - position.height / 2;
            gsap.to(btn, { x: x * 0.2, y: y * 0.4, duration: 0.5, ease: 'power3.out' });
        });
        btn.addEventListener('mouseleave', function() {
            gsap.to(btn, { x: 0, y: 0, duration: 0.5, ease: 'elastic.out(1, 0.3)' });
        });
    </script>
</body>
</html>
