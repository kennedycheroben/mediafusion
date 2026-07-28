<?php
declare(strict_types=1);

/**
 * header.php — Central layout header for MediaFusion.
 * - Provides HTML boilerplate, Bootstrap 5.3, FontAwesome 6.x, favicon, and global navbar.
 * - CRITICAL: Uses <link rel="stylesheet" href="assets/css/style.css">
 * - Access control: redirects unauthenticated users to login.php, except on login/register pages.
 *
 * Optional variables (set by pages before include):
 * - $pageTitle (string)
 * - $activePage (string) one of: home|socials|studio|dashboard|profile|auth
 * - $extraHead (string) additional <head> tags
 */

// Bootstrap already loaded by parent page; ensure session is active
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/backend/bootstrap.php';
}

// Ensure CSRF token is available
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_generated_at'] = time();
}

$currentFile = basename($_SERVER['PHP_SELF'] ?? '');
$isLoggedIn = isset($_SESSION['user_id']);
$publicPages = ['index.php', 'login.php', 'register.php', 'privacy.php', 'terms.php', 'data_deletion.php', 'about.php', 'request_password_reset.php', 'verify_password_reset.php', 'google_auth.php'];

if (!$isLoggedIn && !in_array($currentFile, $publicPages, true)) {
    header('Location: login.php');
    exit;
}

if (!isset($pageTitle)) $pageTitle = 'MediaFusion';
if (!isset($activePage)) $activePage = '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <meta name="description" content="MediaFusion — High-performance multi-platform video distribution engine.">

    <!-- Performance: Preconnect to CDNs -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>

    <!-- Performance: Preload critical assets -->
    <link rel="preload" href="assets/css/style.css?v=1.0.7" as="style">
    <link rel="preload" href="assets/img/logo.webp" as="image" type="image/webp">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" as="style" crossorigin>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=1.0.7">
    <link rel="icon" href="assets/img/logo.webp" type="image/webp">

    <?php if (!empty($extraHead)) { echo $extraHead; } ?>
    <script>
        window.csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
    </script>
</head>
<body>

<!-- ===== Global Background Video ===== -->
<div id="global-video-bg">
    <video autoplay loop muted playsinline>
        <source src="assets/video/hero-loop.mp4" type="video/mp4">
    </video>
    <div id="global-video-overlay"></div>
</div>
<!-- ===== /Global Background Video ===== -->

<div id="cursor"></div>
<div id="cursor-blur"></div>




<?php if ($isLoggedIn): ?>
    <!-- Responsive Sidebar offcanvas menu -->
    <div class="offcanvas-lg offcanvas-start sidebar" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarMenuLabel">
        <div class="sidebar-brand">
            <a href="index.php" class="text-white d-flex align-items-center gap-2" style="text-decoration: none;">
                <img src="assets/img/logo.webp" alt="MediaFusion Logo" class="brand-logo" style="height: 32px;"> MediaFusion
            </a>
            <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu" aria-label="Close"></button>
        </div>
        
        <div class="sidebar-nav">
            <a class="sidebar-link <?= $activePage === 'home' ? 'active' : '' ?>" href="index.php">
                <i class="fa-solid fa-house"></i> Home
            </a>
            <a class="sidebar-link <?= $activePage === 'dashboard' ? 'active' : '' ?>" href="history.php">
                <i class="fa-solid fa-chart-line"></i> Dashboard
            </a>
            <a class="sidebar-link <?= $activePage === 'analytics' ? 'active' : '' ?>" href="analytics.php">
                <i class="fa-solid fa-chart-pie"></i> Analytics
            </a>
            <a class="sidebar-link <?= $activePage === 'studio' ? 'active' : '' ?>" href="studio.php">
                <i class="fa-solid fa-scissors"></i> Studio
            </a>
            <a class="sidebar-link <?= $activePage === 'socials' ? 'active' : '' ?>" href="connect.php">
                <i class="fa-solid fa-plug"></i> Socials
            </a>
            <a class="sidebar-link <?= $activePage === 'incomplete' ? 'active' : '' ?>" href="incomplete.php">
                <i class="fa-solid fa-photo-film"></i> Library
            </a>
            <a class="sidebar-link <?= $activePage === 'profile' ? 'active' : '' ?>" href="profile.php">
                <i class="fa-solid fa-circle-user"></i> Profile
            </a>
            <a class="sidebar-link <?= $activePage === 'about' ? 'active' : '' ?>" href="about.php">
                <i class="fa-solid fa-circle-info"></i> About
            </a>
        </div>
        
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-link">
                <i class="fa-solid fa-arrow-right-from-bracket me-2"></i> Logout
            </a>
        </div>
    </div>
    
    <!-- Mobile top header bar -->
    <nav class="navbar navbar-expand-lg navbar-glass d-lg-none fixed-top w-100" style="z-index: 1020;">
        <div class="container-fluid px-3">
            <a class="navbar-brand py-1" href="index.php">
                <img src="assets/img/logo.webp" alt="MediaFusion Logo" class="brand-logo" style="height: 28px;"> MediaFusion
            </a>
            <button class="navbar-toggler border-0 p-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-label="Toggle navigation">
                <i class="fa-solid fa-bars text-white" style="font-size: 1.4rem;"></i>
            </button>
        </div>
    </nav>
    
    <!-- Layout wrappers for sidebar integration -->
    <div class="dashboard-layout">
        <div class="content-wrapper">
<?php else: ?>
    <!-- Public top navbar for unauthenticated pages -->
    <nav class="navbar navbar-expand-lg navbar-glass fixed-top w-100" style="z-index: 1020;">
        <div class="container px-3">
            <a class="navbar-brand" href="index.php">
                <img src="assets/img/logo.webp" alt="MediaFusion Logo" class="brand-logo" style="height: 32px;"> MediaFusion
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                    aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <i class="fa-solid fa-bars text-white"></i>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav align-items-center gap-3">
                    <li class="nav-item">
                        <a class="nav-link <?= $activePage === 'home' ? 'active' : '' ?>" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activePage === 'about' ? 'active' : '' ?>" href="about.php">About</a>
                    </li>
                    <li class="nav-item ms-2">
                        <?php
                            $authCtaHref = 'login.php';
                            $authCtaText = 'Login';
                            $authCtaIcon = '<i class="fa-solid fa-arrow-right-to-bracket ms-1"></i>';
                            if ($currentFile === 'login.php') {
                                $authCtaHref = 'register.php';
                                $authCtaText = 'Register';
                                $authCtaIcon = '<i class="fa-solid fa-user-plus ms-1"></i>';
                            } elseif ($currentFile === 'register.php') {
                                $authCtaHref = 'login.php';
                                $authCtaText = 'Login';
                                $authCtaIcon = '<i class="fa-solid fa-arrow-right-to-bracket ms-1"></i>';
                            }
                        ?>
                        <a href="<?= htmlspecialchars($authCtaHref, ENT_QUOTES, 'UTF-8') ?>" class="btn-magnetic" id="nav-auth-btn"
                           style="font-size: 0.85rem; padding: 0.5rem 1.2rem;">
                            <?= htmlspecialchars($authCtaText, ENT_QUOTES, 'UTF-8') ?> <?= $authCtaIcon ?>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
<?php endif; ?>
