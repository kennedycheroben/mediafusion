<?php
declare(strict_types=1);

/**
 * includes/footer.php — Global navigation, system status and compliance footer.
 * Includes Bootstrap script integrations and page closure.
 */
?>

<footer class="py-5 mt-auto" style="color: var(--text-secondary); font-size: 0.9rem; position: relative; z-index: 10;">
    <div class="container">
        <div class="row g-5">
            
            <!-- Column 1: Brand & Identity with Social Buttons -->
            <div class="col-lg-3 col-md-6 mb-4 mb-lg-0">
                <a class="d-flex align-items-center mb-4 text-white text-decoration-none fw-bold" href="index.php" style="font-family: var(--font-heading); font-size: 1.2rem; letter-spacing: 1px;">
                    <img src="assets/img/logo.png" alt="MediaFusion Logo" class="brand-logo me-2" style="height: 30px;">
                    <span style="text-shadow: 0 0 10px rgba(0, 243, 255, 0.4); font-weight: 800;">MediaFusion</span>
                </a>
                <p class="small text-secondary mb-4" style="line-height: 1.8; opacity: 0.9;">
                    Powerful workspace for creators to distribute content and scale across multi-platform networks.
                </p>
                <div class="d-flex gap-2" style="margin-top: 20px;">
                    <a href="https://www.youtube.com/@MediaFusion" target="_blank" class="social-glow-btn social-yt" title="YouTube"><i class="fa-brands fa-youtube"></i></a>
                    <a href="https://www.tiktok.com/@mediafusion" target="_blank" class="social-glow-btn social-tt" title="TikTok"><i class="fa-brands fa-tiktok"></i></a>
                    <a href="https://web.facebook.com/profile.php?id=61589997259788" target="_blank" class="social-glow-btn social-fb" title="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="https://www.instagram.com/mediafusion" target="_blank" class="social-glow-btn social-ig" title="Instagram"><i class="fa-brands fa-instagram"></i></a>
                </div>
            </div>

            <!-- Column 2: Quick Links -->
            <div class="col-lg-3 col-md-6 mb-4 mb-lg-0">
                <h6 class="text-uppercase fw-bold text-gradient-cyan mb-4" style="font-family: var(--font-heading); font-size: 0.85rem; letter-spacing: 1.5px; text-shadow: 0 0 5px rgba(0, 243, 255, 0.2);">Quick Links</h6>
                <div class="d-flex flex-column gap-3">
                    <a href="history.php" class="footer-link">
                        <i class="fa-solid fa-chart-line me-2" style="width: 16px;"></i>Dashboard
                    </a>
                    <a href="studio.php" class="footer-link">
                        <i class="fa-solid fa-scissors me-2" style="width: 16px;"></i>Studio
                    </a>
                    <a href="connect.php" class="footer-link">
                        <i class="fa-solid fa-vault me-2" style="width: 16px;"></i>Vault
                    </a>
                    <a href="about.php" class="footer-link">
                        <i class="fa-solid fa-circle-info me-2" style="width: 16px;"></i>About Us
                    </a>
                    <a href="profile.php" class="footer-link">
                        <i class="fa-solid fa-user me-2" style="width: 16px;"></i>Profile
                    </a>
                </div>
            </div>

            <!-- Column 3: Legal Links -->
            <div class="col-lg-3 col-md-6 mb-4 mb-md-0">
                <h6 class="text-uppercase fw-bold text-gradient-magenta mb-4" style="font-family: var(--font-heading); font-size: 0.85rem; letter-spacing: 1.5px; text-shadow: 0 0 5px rgba(255, 0, 255, 0.2);">Legal</h6>
                <div class="d-flex flex-column gap-3">
                    <a href="privacy.php" class="footer-link">
                        <i class="fa-solid fa-shield me-2" style="width: 16px;"></i>Privacy Policy
                    </a>
                    <a href="terms.php" class="footer-link">
                        <i class="fa-solid fa-file-contract me-2" style="width: 16px;"></i>Terms of Service
                    </a>
                </div>
            </div>

            <!-- Column 4: System Status -->
            <div class="col-lg-3 col-md-6">
                <h6 class="text-uppercase fw-bold text-gradient-cyan mb-4" style="font-family: var(--font-heading); font-size: 0.85rem; letter-spacing: 1.5px; text-shadow: 0 0 5px rgba(0, 243, 255, 0.2);">System Status</h6>
                <ul class="status-badge-list">
                    <li class="status-badge-item">
                        <span class="status-indicator-dot"></span>
                        <span>Platform: <strong class="text-white">Online</strong></span>
                    </li>
                    <li class="status-badge-item">
                        <span class="status-indicator-dot"></span>
                        <span>Security: <strong class="text-white">Secured</strong></span>
                    </li>
                    <li class="status-badge-item">
                        <span class="status-indicator-dot"></span>
                        <span>Engine: <strong class="text-white">Active</strong></span>
                    </li>
                </ul>
            </div>
            
        </div>
        
        <!-- Divider -->
        <hr style="border-color: rgba(255, 255, 255, 0.1); margin: 40px 0 25px 0;">
        
        <!-- Copyright -->
        <div class="row">
            <div class="col-12">
                <p class="small text-secondary mb-0 text-center" style="font-size: 0.8rem; letter-spacing: 0.5px;">
                    &copy; <?= date('Y') ?> MediaFusion. All Rights Reserved.
                </p>
            </div>
        </div>

    </div>
</footer>

<!-- Bootstrap 5 Bundle + GSAP animations -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="assets/js/main.js"></script>

<?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>
