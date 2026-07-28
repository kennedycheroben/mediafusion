<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'Privacy Policy — Unify Social Hub';
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
                        <p class="text-secondary">Last updated: May 20, 2026</p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-user-secret me-2"></i>Introduction</h3>
                        <p class="text-secondary">
                            At <strong>UnifySocialHub</strong>, we prioritize the protection and confidentiality of your personal and social platform data. This Privacy Policy details how our media distribution application accesses, manages, and secures data when you link your external accounts (including YouTube, TikTok, and Instagram).
                        </p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-key me-2"></i>Token Collection & Usage</h3>
                        <p class="text-secondary">
                            UnifySocialHub functions strictly as a pipeline utility for authorized distribution management. We securely collect and store account access tokens solely to:
                        </p>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-solid fa-chart-line text-cyan me-2"></i> List your existing channel statistics.</li>
                            <li class="mb-2"><i class="fa-solid fa-list-check text-cyan me-2"></i> Read video posting indices.</li>
                            <li class="mb-2"><i class="fa-solid fa-cloud-arrow-up text-cyan me-2"></i> Push automated video files directly to your profiles upon your explicit instruction.</li>
                        </ul>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-lock me-2"></i>Data Protection & Encryption</h3>
                        <p class="text-secondary">
                            Your security is our absolute priority:
                        </p>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-solid fa-shield-cat text-cyan me-2"></i> <strong>Encryption at Rest:</strong> All database data tokens are heavily encrypted at rest using industry-standard cryptographic algorithms.</li>
                            <li class="mb-2"><i class="fa-solid fa-ban text-cyan me-2"></i> <strong>Zero Data Sharing:</strong> Your access credentials and tokens are never sold, shared, or distributed to any third parties.</li>
                            <li class="mb-2"><i class="fa-solid fa-rotate-left text-cyan me-2"></i> <strong>Immediate Revocation:</strong> You can instantly revoke all access and delete related tokens from our database by hitting the disconnect pipeline on the Profile / Vault configurations.</li>
                        </ul>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-magenta mb-3"><i class="fa-solid fa-circle-info me-2"></i>Compliance</h3>
                        <p class="text-secondary">
                            This application operates in strict compliance with the developer platform rules and policies of Google/YouTube, TikTok, and Meta.
                        </p>
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