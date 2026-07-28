<?php
$pageTitle  = 'Home - Unify Social Hub';
$activePage = 'home';
$extraHead  = '
<meta name="description" content="High-performance Social Media Distribution System for YouTube, TikTok, and Meta.">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/locomotive-scroll@4.1.4/dist/locomotive-scroll.min.css">
';
?>
<?php include 'header.php'; ?>

    <!-- Main Content wrapper for Locomotive Scroll -->
    <main data-scroll-container>
        
        <!-- Hero Section -->
        <section class="hero-section" data-scroll-section>
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-lg-6 z-2">
                        <h1 class="display-3 fw-bold mb-4 gsap-fade-in" data-scroll data-scroll-speed="1">
                            Distribute at <br>
                            <span class="text-gradient-cyan">Light Speed.</span>
                        </h1>
                        <p class="lead text-secondary mb-5 gsap-fade-in" data-scroll data-scroll-speed="1.2">
                            A high-performance engine to broadcast your videos across YouTube, TikTok, and Meta simultaneously. Unify your social presence with a single click.
                        </p>
                        <div class="d-flex gap-4 gsap-fade-in" data-scroll data-scroll-speed="1.4">
                            <a href="connect.php" class="btn-magnetic" style="border-color: var(--neon-magenta); color: #fff;">
                                Connect Vault <i class="fa-solid fa-key ms-2"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="col-lg-6 position-relative z-1 gsap-fade-in">
                        <!-- Floating Orbit GSAP Animation -->
                        <div class="orbit-container" data-scroll data-scroll-speed="-1">
                            <div class="center-hub">
                                <i class="fa-solid fa-satellite-dish text-gradient-cyan"></i>
                            </div>
                            <div class="orbit-ring">
                                <div class="orbit-icon yt"><i class="fa-brands fa-youtube"></i></div>
                                <div class="orbit-icon tt"><i class="fa-brands fa-tiktok"></i></div>
                                <div class="orbit-icon meta"><i class="fa-brands fa-meta"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section class="py-5 mb-6" data-scroll-section>
            <div class="container">
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="glass-card h-100 gsap-fade-in text-center" data-scroll data-scroll-speed="1">
                            <i class="fa-solid fa-file-video fa-3x mb-3 text-gradient-cyan"></i>
                            <h3 class="mb-3">Unlimited Size</h3>
                            <p class="text-secondary">Bypass server limits with our advanced chunked uploading algorithm. Send 2GB+ files seamlessly.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="glass-card h-100 gsap-fade-in text-center" data-scroll data-scroll-speed="1.2">
                            <i class="fa-solid fa-bolt fa-3x mb-3 text-gradient-magenta"></i>
                            <h3 class="mb-3">Concurrent Engine</h3>
                            <p class="text-secondary">Experience seamless, background-level synchronization as your content is deployed to all platforms at once.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="glass-card h-100 gsap-fade-in text-center" data-scroll data-scroll-speed="1.4">
                            <i class="fa-solid fa-chart-line fa-3x mb-3 text-gradient-cyan"></i>
                            <h3 class="mb-3">Live Tracking</h3>
                            <p class="text-secondary">Monitor your uploads in real-time with our reactive neon status dashboard.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </main>

<?php
    $extraScripts = '
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/locomotive-scroll@4.1.4/dist/locomotive-scroll.min.js"></script>
    ';
    include_once 'includes/footer.php';
?>

<?php include_once 'includes/footer.php'; ?>
