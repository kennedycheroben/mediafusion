<?php
/**
 * MediaFusion - Terms of Service
 * 
 * Professional legal foundation covering:
 * - Content ownership (IP) & Limited Distribution License
 * - Limitation of Liability (Platform suspensions, API interruptions, revenue loss)
 * - Prohibited Uses & Account termination
 * - Disclaimer of Warranties (As-Is / As-Available)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$pageTitle = 'Terms of Service — MediaFusion';
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
                        <p class="text-secondary">Last updated: July 16, 2026</p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-circle-check me-2"></i>1. Acceptance of Terms</h3>
                        <p class="text-secondary">
                            By accessing, registering for, or using the <strong>MediaFusion</strong> platform (the "Service"), you agree to be bound by these Terms of Service. If you do not agree to these terms, you must immediately cease all use of the Service and disconnect any connected social media accounts.
                        </p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-copyright me-2"></i>2. Intellectual Property & License</h3>
                        <p class="text-secondary">
                            <strong>Your Content:</strong> You retain sole ownership and all intellectual property rights to any videos, metadata, captions, or other files you upload to the Service ("User Content"). We claim no ownership over your intellectual property.
                        </p>
                        <p class="text-secondary">
                            <strong>Limited License:</strong> By uploading User Content, you grant MediaFusion a non-exclusive, worldwide, royalty-free, sublicensable license to host, transcode, transmit, and distribute your content solely for the purpose of publishing it to your designated social channels (YouTube, TikTok, Facebook, Instagram) at your command.
                        </p>
                        <p class="text-secondary">
                            <strong>Our Property:</strong> All software, code, styling, visual interfaces, graphics, designs, compilations, and proprietary materials on MediaFusion are the exclusive intellectual property of MediaFusion and are protected by copyright, trademark, and other applicable laws.
                        </p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-triangle-exclamation me-2"></i>3. Upload Responsibility & Third-Party Platforms</h3>
                        <p class="text-secondary">
                            You acknowledge and agree that:
                        </p>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-solid fa-shield text-magenta me-2"></i> <strong>Compliance:</strong> You must strictly adhere to the terms, conditions, API guidelines, and community standards of all connected third-party networks, including the YouTube Terms of Service, Google Privacy Policy, TikTok Terms of Service, and Meta Developer Policies.</li>
                            <li class="mb-2"><i class="fa-solid fa-ban text-magenta me-2"></i> <strong>Legality of Content:</strong> You represent and warrant that your User Content does not infringe upon any third-party copyrights, trademarks, privacy rights, or intellectual property, and does not contain illegal, harmful, or malicious materials.</li>
                        </ul>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-shield-halved me-2"></i>4. Disclaimer of Warranties</h3>
                        <p class="text-secondary">
                            THE SERVICE IS PROVIDED ON AN "AS IS" AND "AS AVAILABLE" BASIS, WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR IMPLIED. TO THE FULLEST EXTENT PERMISSIBLE UNDER APPLICABLE LAW, MediaFusion DISCLAIMS ALL WARRANTIES, INCLUDING BUT NOT LIMITED TO IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, NON-INFRINGEMENT, AND SYSTEM SECURITY. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, SECURE, TIMELY, OR ERROR-FREE, OR THAT LOSS OF MEDIA DATA WILL NOT OCCUR.
                        </p>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-scale-balanced me-2"></i>5. Limitation of Liability</h3>
                        <p class="text-secondary">
                            IN NO EVENT SHALL MediaFusion, ITS DIRECTORS, EMPLOYEES, OR AGENTS BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, INCLUDING WITHOUT LIMITATION LOSS OF PROFITS, REVENUE, DATA, USE, GOODWILL, OR OTHER INTANGIBLE LOSSES, ARISING OUT OF OR IN CONNECTION WITH:
                        </p>
                        <ul class="text-secondary list-unstyled ps-3">
                            <li class="mb-2"><i class="fa-solid fa-circle-exclamation text-magenta me-2"></i> Your access to or inability to access or use the Service.</li>
                            <li class="mb-2"><i class="fa-solid fa-circle-exclamation text-magenta me-2"></i> Any actions, bans, suspensions, demonetization, or penalties applied to your accounts by third-party social networks (YouTube, TikTok, Meta).</li>
                            <li class="mb-2"><i class="fa-solid fa-circle-exclamation text-magenta me-2"></i> Technical shifts, deprecations, or outages of third-party APIs.</li>
                            <li class="mb-2"><i class="fa-solid fa-circle-exclamation text-magenta me-2"></i> Unauthorized access, alteration, deletion, or loss of your video files or data.</li>
                        </ul>
                    </div>

                    <div class="content-section mb-4">
                        <h3 class="h5 text-gradient-cyan mb-3"><i class="fa-solid fa-gavel me-2"></i>6. Dispute Resolution & Governing Law</h3>
                        <p class="text-secondary">
                            These terms shall be governed by and construed in accordance with the laws of the jurisdiction of our operation, without regard to conflict of law principles. Any dispute arising out of or relating to these terms shall be subject to the exclusive jurisdiction of the state and federal courts located within our primary operating region.
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
