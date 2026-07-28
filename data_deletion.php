<?php
/**
 * MediaFusion - User Data Deletion Instructions
 *
 * Public page for Meta/Facebook app review and user account deletion guidance.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$pageTitle = 'Data Deletion Instructions - MediaFusion';
$activePage = 'privacy';
include __DIR__ . '/header.php';
?>

<main style="padding-top: 100px; min-height: 100vh;">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-8">
                <div class="glass-card shadow-lg p-5">
                    <div class="text-center mb-5">
                        <i class="fa-solid fa-user-shield fa-4x text-gradient-cyan mb-3" style="filter: drop-shadow(0 0 10px rgba(0, 243, 255, 0.3));"></i>
                        <h1 class="display-6 text-gradient-cyan fw-bold">User Data Deletion Instructions</h1>
                        <p class="text-secondary">Last updated: July 28, 2026</p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-circle-info me-2"></i>Overview</h3>
                        <p class="text-secondary">
                            MediaFusion lets users connect social accounts such as Facebook, Instagram, YouTube, and TikTok for content publishing. You can request deletion of your MediaFusion account data and connected social authorization data at any time.
                        </p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-list-check me-2"></i>How to Request Deletion</h3>
                        <ol class="text-secondary ps-3">
                            <li class="mb-2">Sign in to your MediaFusion account.</li>
                            <li class="mb-2">Open your <a href="profile.php" class="text-cyan">Profile</a> page.</li>
                            <li class="mb-2">Use the account deletion option to submit your deletion request.</li>
                            <li class="mb-2">If you cannot access your account, send a request through our <a href="contact.php" class="text-cyan">contact page</a> using the email address linked to your account.</li>
                        </ol>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-trash-can me-2"></i>What We Delete</h3>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-solid fa-check text-cyan me-2"></i>Your MediaFusion account record, including username and email.</li>
                            <li class="mb-2"><i class="fa-solid fa-check text-cyan me-2"></i>Connected social account tokens for Facebook, Instagram, YouTube, TikTok, and related publishing integrations.</li>
                            <li class="mb-2"><i class="fa-solid fa-check text-cyan me-2"></i>User-owned upload records, generated media metadata, and account-specific processing records where applicable.</li>
                            <li class="mb-2"><i class="fa-solid fa-check text-cyan me-2"></i>Password reset records and user-specific security/session records.</li>
                        </ul>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-clock me-2"></i>Processing Time</h3>
                        <p class="text-secondary">
                            Account deletion requests are processed as soon as possible. Most in-app deletion requests are completed immediately. Manual support requests are reviewed and completed within 30 days after ownership verification.
                        </p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-link-slash me-2"></i>Revoke Platform Access</h3>
                        <p class="text-secondary">
                            You can also revoke MediaFusion access directly from each platform:
                        </p>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-brands fa-facebook text-cyan me-2"></i>Facebook/Instagram: <a href="https://www.facebook.com/settings?tab=applications" target="_blank" rel="noopener noreferrer" class="text-cyan">Meta app settings</a></li>
                            <li class="mb-2"><i class="fa-brands fa-google text-cyan me-2"></i>Google/YouTube: <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener noreferrer" class="text-cyan">Google third-party access</a></li>
                            <li class="mb-2"><i class="fa-brands fa-tiktok text-cyan me-2"></i>TikTok: Manage app access from TikTok account settings.</li>
                        </ul>
                    </div>

                    <div class="text-center mt-5">
                        <a href="index.php" class="btn-magnetic" style="border-color: var(--neon-cyan);">
                            <i class="fa-solid fa-arrow-left me-2"></i> Back to Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.text-cyan {
    color: var(--neon-cyan);
}
.content-section {
    border-bottom: 1px solid var(--glass-border);
    padding-bottom: 1.5rem;
}
.content-section:last-of-type {
    border-bottom: none;
}
</style>

<?php include_once 'includes/footer.php'; ?>
