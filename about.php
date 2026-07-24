<?php
declare(strict_types=1);

/**
 * about.php — The "About Us" and Platform Tutorial Guide page.
 * - Integrates with global header.php and includes/footer.php.
 * - Explains what MediaFusion does and how to use it.
 * - Features a high-fidelity interactive Canvas-driven cyberpunk tutorial player.
 */

$pageTitle  = 'About Us - MediaFusion';
$activePage = 'about';

include 'header.php';
?>

<main class="py-5" style="background: var(--bg-color); min-height: 100vh;">
    <div class="container">
        
        <!-- Welcome Header Block -->
        <div class="text-center mb-5 mt-3">            
            <h1 class="glowing-title text-gradient-cyan-magenta mb-2" style="font-size: 2.5rem;">COMPREHENSIVE MEDIA LABORATORY</h1>
            <p class="text-secondary mx-auto" style="max-width: 680px; font-size: 1.05rem; line-height: 1.6;">
                MediaFusion simplifies how you create and share content. Edit your clips and track your growth using a single dashboard built to save you time and effort automatically.
            </p>
        </div>

        <!-- Tutorial Player Block -->
        

        

        <!-- Three Core Feature Cards -->
        <h3 class="text-center mb-4 text-uppercase fw-bold text-gradient-cyan-magenta" style="font-family: var(--font-heading); font-size: 1.25rem; letter-spacing: 1px;">Platform Features</h3>
        <div class="row g-4 mb-5">
            
            <!-- Card 1: Video Editing Studio -->
            <div class="col-md-4">
                <div class="glass-card h-100 d-flex flex-column">
                    <div class="illustration-frame mb-3">
                        <!-- Custom CSS mockup of a video timeline workspace -->
                        <div style="width: 90%; display: flex; flex-direction: column; gap: 10px;">
                            <div class="d-flex justify-content-between text-secondary small" style="font-size: 0.6rem;">
                                <span>Timeline edit workspace</span>
                                <span class="text-info">Trim mode</span>
                            </div>
                            <div class="mock-timeline-track">
                                <div class="mock-timeline-fill"></div>
                                <div class="mock-timeline-handle" style="left: 20%;"></div>
                                <div class="mock-timeline-handle" style="left: 70%; background: var(--neon-magenta); box-shadow: 0 0 8px var(--neon-magenta);"></div>
                            </div>
                            <div class="d-flex gap-2">
                                <div style="width: 25px; height: 15px; background: rgba(79, 70, 229, 0.2); border: 1px solid var(--primary-bg); border-radius: 2px;"></div>
                                <div style="width: 80px; height: 15px; background: rgba(236, 72, 153, 0.2); border: 1px solid var(--neon-magenta); border-radius: 2px; display:flex; align-items:center; justify-content:center; font-size:0.5rem; color:#0f172a; font-weight:bold;">Text Overlay</div>
                                <div style="width: 35px; height: 15px; background: rgba(15, 23, 42, 0.05); border: 1px dashed rgba(15, 23, 42, 0.2); border-radius: 2px;"></div>
                            </div>
                        </div>
                        <i class="fa-solid fa-scissors" style="position: absolute; right: 15px; top: 15px; font-size: 1rem; color: var(--neon-cyan); opacity: 0.3;"></i>
                    </div>
                    <h4 class="mb-2" style="font-size: 1.15rem; font-weight: 700;"><i class="fa-solid fa-scissors text-info me-2"></i>Video Editing Studio</h4>
                    <p class="text-secondary small flex-grow-1" style="line-height:1.5;">
                       Easily trim, cut, and perfect your videos using our simple visual sliders. Just drag the handlers to choose your start and end points, add your text captions, and let the system handle the rest instantly without any complicated guessing game.
                    </p>
                </div>
            </div>

            <!-- Card 2: Secure Profile Copy Syncing -->
            <div class="col-md-4">
                <div class="glass-card h-100 d-flex flex-column">
                    <div class="illustration-frame mb-3">
                        <!-- Custom CSS mockup of Secure profile sync connections -->
                        <div class="d-flex align-items-center justify-content-center position-relative" style="width: 100%; height: 100%;">
                            <div class="mock-node" style="left: -10px;"><i class="fa-solid fa-user-astronaut text-info"></i></div>
                            <div class="connection-line" style="left: 22%; width: 20%;"></div>
                            <div class="mock-node-center"><i class="fa-solid fa-lock text-white" style="filter: drop-shadow(0 0 3px #fff); z-index: 3;"></i></div>
                            <div class="connection-line" style="left: 58%; width: 20%;"></div>
                            <div class="mock-node" style="right: -10px; border-color: var(--neon-magenta); background: rgba(255,0,255,0.05); box-shadow:0 0 10px rgba(255,0,255,0.2);"><i class="fa-brands fa-youtube text-gradient-magenta" style="-webkit-text-fill-color: initial;"></i></div>
                        </div>
                        <i class="fa-solid fa-rotate" style="position: absolute; right: 15px; top: 15px; font-size: 1rem; color: var(--neon-magenta); opacity: 0.3;"></i>
                    </div>
                    <h4 class="mb-2" style="font-size: 1.15rem; font-weight: 700;"><i class="fa-solid fa-rotate text-info me-2"></i>Account Syncing</h4>
                    <p class="text-secondary small flex-grow-1" style="line-height:1.5;">
                        Securely connect your favorite social media profiles all in one place. Keep your channel details updated, customize your dashboard profile image with your own uploaded picture, and manage your account safely with our fully protected login system.
                    </p>
                </div>
            </div>

            <!-- Card 3: Channel Growth Analytics -->
            <div class="col-md-4">
                <div class="glass-card h-100 d-flex flex-column">
                    <div class="illustration-frame mb-3">
                        <!-- Custom CSS mockup of progress graphs and metrics -->
                        <div class="d-flex align-items-end justify-content-center gap-3" style="width: 80%; height: 70%;">
                            <div class="mock-bar" style="height: 30%;"></div>
                            <div class="mock-bar" style="height: 55%; background: linear-gradient(to top, var(--neon-magenta), var(--neon-cyan));"></div>
                            <div class="mock-bar" style="height: 45%;"></div>
                            <div class="mock-bar" style="height: 85%; background: linear-gradient(to top, var(--neon-cyan), var(--neon-magenta));"></div>
                            <div class="mock-bar" style="height: 70%;"></div>
                        </div>
                        <i class="fa-solid fa-chart-line" style="position: absolute; right: 15px; top: 15px; font-size: 1rem; color: var(--neon-cyan); opacity: 0.3;"></i>
                    </div>
                    <h4 class="mb-2" style="font-size: 1.15rem; font-weight: 700;"><i class="fa-solid fa-chart-line text-info me-2"></i>Growth Analytics</h4>
                    <p class="text-secondary small flex-grow-1" style="line-height:1.5;">
                        Keep a close eye on your audience growth and performance statistics instantly. View all your views, likes, and comments from different social channels brought together into a single, beautiful dashboard with easy-to-read growth trends.
                    </p>
                </div>
            </div>

        </div>

    </div>
</main>

<script src="assets/js/about.js"></script>

<?php
include_once 'includes/footer.php';
?>
