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
 * - $activePage (string) one of: home|vault|studio|dashboard|profile|auth
 * - $extraHead (string) additional <head> tags
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$currentFile = basename($_SERVER['PHP_SELF'] ?? '');
$isLoggedIn = isset($_SESSION['user_id']);
$publicPages = ['index.php', 'login.php', 'register.php', 'privacy.php', 'terms.php', 'about.php'];

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

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="assets/img/logo.png" type="image/png">

    <?php if (!empty($extraHead)) { echo $extraHead; } ?>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-glass">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">
            <img src="assets/img/logo.png" alt="MediaFusion Logo" class="brand-logo"> MediaFusion
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
                <?php if ($isLoggedIn): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $activePage === 'vault' ? 'active' : '' ?>" href="connect.php">Vault</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activePage === 'studio' ? 'active' : '' ?>" href="studio.php">Studio</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>" href="history.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activePage === 'incomplete' ? 'active' : '' ?>" href="incomplete.php">Library</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activePage === 'profile' ? 'active' : '' ?>" href="profile.php">
                            <i class="fa-solid fa-circle-user me-1"></i>Profile
                        </a>
                    </li>
                    <li class="nav-item ms-2">
                        <a href="logout.php" class="btn-magnetic" id="nav-logout-btn"
                           style="border-color: var(--neon-magenta); font-size: 0.85rem; padding: 0.5rem 1.2rem;">
                            Logout <i class="fa-solid fa-arrow-right-from-bracket ms-1"></i>
                        </a>
                    </li>
                <?php else: ?>
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
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
