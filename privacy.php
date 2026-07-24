<?php
/**
 * MediaFusion - Privacy Policy
 * 
 * Production-ready GDPR/CCPA compliant privacy declaration covering:
 * - Data Collection (OAuth credentials, media metadata, logs)
 * - Integration Disclosures (Google/YouTube API Services, Meta Graph API, TikTok API)
 * - Data Retention & Security
 * - Step-by-Step Data Deletion Instructions
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$pageTitle = 'Privacy Policy — MediaFusion';
$activePage = 'privacy';
include __DIR__ . '/header.php';
?>

<main style="padding-top: 100px; min-height: 100vh;">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-8">
                <div class="glass-card shadow-lg p-5">
                    <div class="text-center mb-5">
                        <i class="fa-solid fa-shield-halved fa-4x text-gradient-cyan mb-3" style="filter: drop-shadow(0 0 10px rgba(0, 243, 255, 0.3));"></i>
                        <h1 class="display-6 text-gradient-cyan fw-bold">Privacy Policy</h1>
                        <p class="text-secondary">Last updated: July 16, 2026</p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-user-secret me-2"></i>1. Overview & Scope</h3>
                        <p class="text-secondary">
                            At <strong>MediaFusion</strong>, we respect your privacy and are committed to protecting your personal information. This Privacy Policy outlines how we collect, store, process, and protect your data when you use our platform and connect your social media profiles (YouTube, TikTok, Facebook, Instagram).
                        </p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-database me-2"></i>2. Data We Collect & How We Use It</h3>
                        <p class="text-secondary">
                            To deliver consolidated short-form video editing and distribution features, we collect and store:
                        </p>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-solid fa-circle-info text-cyan me-2"></i> <strong>Account Details:</strong> Email addresses and hashed passwords used during registration.</li>
                            <li class="mb-2"><i class="fa-solid fa-key text-cyan me-2"></i> <strong>OAuth Credentials:</strong> Access tokens and refresh tokens authorized by you to allow publishing to connected channels.</li>
                            <li class="mb-2"><i class="fa-solid fa-file-video text-cyan me-2"></i> <strong>Media Metadata:</strong> Video titles, descriptions, upload durations, and platform statuses to keep your distribution dashboard synchronized.</li>
                        </ul>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-network-wired me-2"></i>3. Integration Disclosures</h3>
                        <p class="text-secondary">
                            Our Service interacts directly with external social network APIs. Your data is handled in strict compliance with:
                        </p>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-solid fa-link text-cyan me-2"></i> <strong>YouTube API Services:</strong> We access and upload videos on your behalf. By using this service, you agree to be bound by the <a href="https://www.youtube.com/t/terms" target="_blank" class="text-cyan">YouTube Terms of Service</a> and the <a href="https://policies.google.com/privacy" target="_blank" class="text-cyan">Google Privacy Policy</a>.</li>
                            <li class="mb-2"><i class="fa-solid fa-link text-cyan me-2"></i> <strong>Meta Graph API:</strong> We publish videos and reels to Facebook Pages and Instagram Business Accounts, in compliance with the <a href="https://www.facebook.com/about/privacy/" target="_blank" class="text-cyan">Meta Privacy Policy</a>.</li>
                            <li class="mb-2"><i class="fa-solid fa-link text-cyan me-2"></i> <strong>TikTok API:</strong> We publish content on your behalf in compliance with the <a href="https://www.tiktok.com/legal/privacy-policy" target="_blank" class="text-cyan">TikTok Privacy Policy</a>.</li>
                        </ul>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-scale-balanced me-2"></i>4. Global Compliance Rights (GDPR & CCPA)</h3>
                        <p class="text-secondary">
                            Regardless of location, we support standard data protection controls:
                        </p>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-solid fa-check text-cyan me-2"></i> <strong>GDPR (EU/UK):</strong> You have the right to access, correct, restrict, transfer, or permanently erase your personal data stored on our servers.</li>
                            <li class="mb-2"><i class="fa-solid fa-check text-cyan me-2"></i> <strong>CCPA (California):</strong> You have the right to request disclosure of categories of personal information collected, request deletion of your information, and opt-out of any prospective sales of data (we do not sell your data).</li>
                        </ul>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-trash-can me-2"></i>5. Data Deletion Instructions</h3>
                        <p class="text-secondary">
                            You maintain total authority over your connected accounts and stored information. To delete your data from our systems:
                        </p>
                        <ol class="text-secondary ps-3">
                            <li class="mb-2"><strong>Disconnect Accounts:</strong> Go to the <a href="connect.php" class="text-cyan">Integrations Vault</a> on your dashboard and click "Disconnect" on any connected platform. This immediately deletes the associated access and refresh tokens from our database.</li>
                            <li class="mb-2"><strong>Revoke Third-Party Permissions:</strong> You can also revoke access directly through platform provider settings:
                                <ul>
                                    <li>For Google/YouTube: Visit the <a href="https://myaccount.google.com/permissions" target="_blank" class="text-cyan">Google Security Console</a>.</li>
                                    <li>For Meta/Facebook: Visit the <a href="https://www.facebook.com/settings?tab=applications" target="_blank" class="text-cyan">Meta App Settings</a>.</li>
                                    <li>For TikTok: Go to settings on your mobile application under "Security and Login > Manage App Access".</li>
                                </ul>
                            </li>
                            <li class="mb-2"><strong>Complete Account Erasure:</strong> To request complete deletion of your MediaFusion account, registration records, and upload logs, please submit a deletion request directly to our support team at <a href="mailto:support@MediaFusion.com" class="text-cyan">support@MediaFusion.com</a>. Requests are validated and executed within 30 days.</li>
                        </ol>
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