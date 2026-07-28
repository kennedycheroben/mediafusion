<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'Terms of Service — Unify Social Hub';
$activePage = 'terms';
include __DIR__ . '/header.php';
?>

<main style="padding-top: 100px; min-height: 100vh;">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-8">
                <div class="glass-card shadow-lg p-5">
                    <div class="text-center mb-5">
                        <i class="fa-solid fa-file-contract fa-4x text-gradient-magenta mb-3" style="filter: drop-shadow(0 0 10px rgba(255, 0, 255, 0.3));"></i>
                        <h1 class="display-6 text-gradient-magenta fw-bold">Terms of Service</h1>
                        <p class="text-secondary">Last updated: May 20, 2026</p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-circle-check me-2"></i>1. Acceptance of Terms</h3>
                        <p class="text-secondary">
                            By accessing and using <strong>UnifySocialHub</strong>, you agree to comply with and be bound by these Terms of Service. If you do not agree, you must immediately cease all access and revoke active authorization tokens.
                        </p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-server me-2"></i>2. Service Definition</h3>
                        <p class="text-secondary">
                            UnifySocialHub is a high-performance multi-platform media distribution and pipeline utility intended solely for authorized social media account management. The system facilitates:
                        </p>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-solid fa-arrow-right text-magenta me-2"></i> Authenticated connections to third-party APIs.</li>
                            <li class="mb-2"><i class="fa-solid fa-arrow-right text-magenta me-2"></i> Fetching account statistics.</li>
                            <li class="mb-2"><i class="fa-solid fa-arrow-right text-magenta me-2"></i> Direct uploading of video files on the user's explicit action.</li>
                        </ul>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-triangle-exclamation me-2"></i>3. Content & Upload Responsibility</h3>
                        <p class="text-secondary">
                            You acknowledge and agree that:
                        </p>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-solid fa-user text-magenta me-2"></i> <strong>User Responsibility:</strong> You maintain complete, sole, and exclusive ownership and responsibility for the content, quality, copyright, and appropriateness of any materials, videos, or assets uploaded or distributed through UnifySocialHub.</li>
                            <li class="mb-2"><i class="fa-solid fa-shield text-magenta me-2"></i> <strong>Third-Party Compliance:</strong> You must strictly follow the terms of service and community guidelines of external streaming engines like YouTube, TikTok, and Instagram. UnifySocialHub is not responsible for any platform policy violations or suspensions.</li>
                        </ul>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-ban me-2"></i>4. Prohibited Uses</h3>
                        <p class="text-secondary">
                            You agree not to use the application to distribute spam, malware, copyright-infringing content, or materials that violate the community guidelines of any linked social media platforms.
                        </p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-circle-info me-2"></i>5. Disclaimer of Warranties</h3>
                        <p class="text-secondary">
                            The service is provided "as is" and "as available". We do not guarantee uninterrupted or error-free operation, nor do we guarantee the permanent availability of third-party APIs which are controlled entirely by their respective platform operators.
                        </p>
                    </div>

                    <div class="text-center mt-5">
                        <a href="index.php" class="btn-magnetic" style="border-color: var(--neon-magenta);">
                            <i class="fa-solid fa-arrow-left me-2"></i> Back to Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.text-magenta {
    color: var(--neon-magenta);
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
