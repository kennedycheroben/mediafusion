<?php
declare(strict_types=1);

/**
 * includes/footer.php — Global navigation, system status and compliance footer.
 * Responsive layout: 4-col (desktop) → 2×2 (tablet) → 1-col (mobile)
 */

if (isset($_SESSION['user_id'])) {
    echo '</div></div>'; // Close .content-wrapper and .dashboard-layout
}
?>

<style>
/* ── Footer Base ── */
.site-footer {
    background: #f1f5f9;
    border-top: 1px solid #e2e8f0;
    padding: 8px 0 8px;
    color: var(--text-secondary, #475569);
    font-size: 0.9rem;
    position: relative;
    z-index: 10;
    width: 100%;
}

/* ── Footer inner wrapper ── */
.footer-inner {
    width: 100%;
    padding: 0 2rem;
    box-sizing: border-box;
}

/* ── Footer Row: flex-wrap drives the 4→2→1 col responsiveness ── */
.footer-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0;
    margin: 0;
    width: 100%;
}

/* ── Each column ── */
.footer-col {
    flex: 1 1 25%;
    min-width: 200px;
    padding: 0 20px;
    margin-bottom: 36px;
    box-sizing: border-box;
}

/* ── Column heading with accent underline ── */
.footer-col h4 {
    font-family: var(--font-heading, 'Space Grotesk', sans-serif);
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--text-primary, #0f172a);
    margin-bottom: 24px;
    position: relative;
    padding-bottom: 12px;
}

.footer-col h4::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 40px;
    height: 2px;
    background: linear-gradient(90deg, var(--neon-cyan, #4f46e5), transparent);
    border-radius: 2px;
}

/* ── Brand logo col heading variant (no underline) ── */
.footer-col .footer-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    margin-bottom: 14px;
}

.footer-col .footer-brand img {
    height: 30px;
    width: auto;
    object-fit: contain;
    filter: brightness(1.1) drop-shadow(0 0 3px rgba(79, 70, 229, 0.4));
}

.footer-col .footer-brand span {
    font-family: var(--font-heading, 'Space Grotesk', sans-serif);
    font-size: 1.2rem;
    font-weight: 800;
    color: #f8fafc;
    letter-spacing: 1px;
}

.footer-col .footer-tagline {
    font-size: 0.85rem;
    color: #000000;
    line-height: 1.8;
    margin-bottom: 20px;
}

/* ── Navigation links — slide-right on hover ── */
.footer-col ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.footer-col ul li {
    margin-bottom: 10px;
}

.footer-col ul li a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color:black;
    text-decoration: none;
    transition: color 0.25s ease, padding-left 0.25s ease;
    font-size: 0.875rem;
}

.footer-col ul li a i {
    width: 16px;
    font-size: 0.8rem;
    opacity: 0.65;
    transition: opacity 0.25s ease;
    flex-shrink: 0;
}

.footer-col ul li a:hover {
    color: var(--primary-bg, #4f46e5);
    padding-left: 6px;
}

.footer-col ul li a:hover i {
    opacity: 1;
}

/* ── Social icon buttons ── */
.footer-socials {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 4px;
}

.footer-social-btn {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.05);
    border: 1px solid rgba(15, 23, 42, 0.1);
    color: blue;
    text-decoration: none;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.footer-social-btn:hover {
    background: var(--primary-bg, #4f46e5);
    border-color: var(--primary-bg, #4f46e5);
    color: #fff;
    transform: translateY(-3px) scale(1.04);
    box-shadow: 0 8px 20px rgba(79, 70, 229, 0.35);
}

/* Platform-specific glow on hover */
.footer-social-btn.yt:hover  { background: #ff0000; border-color: #ff0000; color: #fff; transform: translateY(-3px) scale(1.04); box-shadow: 0 8px 20px rgba(255,0,0,0.3); }
.footer-social-btn.tt:hover  { background: #0f172a; border-color: #0f172a; color: #fff; transform: translateY(-3px) scale(1.04); box-shadow: 0 8px 20px rgba(0,0,0,0.2); }
.footer-social-btn.fb:hover  { background: #1877f2; border-color: #1877f2; color: #fff; transform: translateY(-3px) scale(1.04); box-shadow: 0 8px 20px rgba(24,119,242,0.3); }
.footer-social-btn.ig:hover  { background: linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); border-color: transparent; color: #fff; transform: translateY(-3px) scale(1.04); box-shadow: 0 8px 20px rgba(188,24,136,0.3); }

/* ── System status list ── */
.footer-status-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.footer-status-item {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.85rem;
    color: var(--text-secondary, #475569);
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 6px rgba(16, 185, 129, 0.5);
    flex-shrink: 0;
    animation: pulse-dot 2s ease-in-out infinite;
}

@keyframes pulse-dot {
    0%, 100% { opacity: 1; box-shadow: 0 0 6px rgba(16, 185, 129, 0.5); }
    50%       { opacity: 0.6; box-shadow: 0 0 3px rgba(16, 185, 129, 0.3); }
}

/* ── Divider + copyright ── */
.footer-divider {
    border: none;
    border-top: 1px solid #e2e8f0;
    margin: 4px 0 10px;
}

.footer-copyright {
    text-align: center;
    font-size: 0.78rem;
    color:#000000;
    opacity: 0.7;
    letter-spacing: 0.5px;    
}

/* ════════════════════════════════════════
   RESPONSIVE BREAKPOINTS
   Tablet  (≤ 991px)  → 2 × 2 grid
   Mobile  (≤ 575px)  → 1 × 4 stack
   ════════════════════════════════════════ */
@media (max-width: 991px) {
    .footer-col {
        flex: 1 1 50%;
        margin-bottom: 32px;
    }
}

@media (max-width: 575px) {
    .footer-col {
        flex: 1 1 100%;
        margin-bottom: 28px;
        padding: 0 10px;
    }

    .footer-inner {
        padding: 0 1.25rem;
    }

    .footer-col h4::before {
        width: 32px;
    }
}
</style>

<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-row">

            <!-- Col 1: Brand & Socials -->
            <div class="footer-col">
                <a href="index.php" class="footer-brand">
                    <img src="assets/img/logo.webp" alt="MediaFusion Logo" class="brand-logo">
                    <span>MEDIAFUSION</span>
                </a>
                <p class="footer-tagline">
                    Powerful workspace for creators to distribute content and scale across multi-platform networks.
                </p>
            </div>

            <!-- Col 2: Quick Links -->
            <div class="footer-col">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="history.php"><i class="fa-solid fa-chart-line"></i>Dashboard</a></li>
                    <li><a href="studio.php"><i class="fa-solid fa-scissors"></i>Studio</a></li>
                    <li><a href="connect.php"><i class="fa-solid fa-plug"></i>Socials</a></li>
                    <li><a href="about.php"><i class="fa-solid fa-circle-info"></i>About Us</a></li>
                    <li><a href="profile.php"><i class="fa-solid fa-user"></i>Profile</a></li>
                </ul>
            </div>

            <!-- Col 3: Legal -->
            <div class="footer-col">
                <h4>Legal</h4>
                <ul>
                    <li><a href="privacy.php"><i class="fa-solid fa-shield"></i>Privacy Policy</a></li>
                    <li><a href="terms.php"><i class="fa-solid fa-file-contract"></i>Terms of Service</a></li>
                    <li><a href="contact.php"><i class="fa-solid fa-headset"></i>Support Center</a></li>
                </ul>
            </div>

            <!-- Col 4: System Status -->
            <div class="footer-col">
                <h4>Follow Us</h4>
                <div class="footer-socials">
                    <a href="https://www.youtube.com/@MediaFusion" target="_blank" class="footer-social-btn yt" title="YouTube">
                        <i class="fa-brands fa-youtube"></i>
                    </a>
                    <a href="https://www.tiktok.com/@mediafusion1" target="_blank" class="footer-social-btn tt" title="TikTok">
                        <i class="fa-brands fa-tiktok"></i>
                    </a>
                    <a href="https://web.facebook.com/profile.php?id=61589997259788" target="_blank" class="footer-social-btn fb" title="Facebook">
                        <i class="fa-brands fa-facebook-f"></i>
                    </a>
                    <a href="https://www.instagram.com/MediaFusion" target="_blank" class="footer-social-btn ig" title="Instagram">
                        <i class="fa-brands fa-instagram"></i>
                    </a>
                </div>
            </div>

        </div><!-- /.footer-row -->

        <hr class="footer-divider">

        <p class="footer-copyright">
            &copy; <?= date('Y') ?> MediaFusion. All Rights Reserved.
        </p>

    </div><!-- /.footer-inner -->
</footer>




<?php if (!empty($extraFooter)) { echo $extraFooter; } ?>

<!-- Bootstrap 5 Bundle + GSAP, ScrollTrigger & Lenis animations -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
<script src="https://unpkg.com/lenis@1.1.18/dist/lenis.min.js"></script>
<script src="assets/js/main.js"></script>

<?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>
