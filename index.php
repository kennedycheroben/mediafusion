<?php
$pageTitle  = 'Home - MediaFusion';
$activePage = 'home';
$extraHead  = '
<meta name="description" content="High-performance Social Media Posting Tool for YouTube, TikTok, Facebook, and Instagram.">
';
?>
<?php include 'header.php'; ?>

    <!-- Main Content wrapper -->
    <main>
        
        <!-- Hero Section -->
        <section class="hero-section">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-lg-6 z-2">
                        <h1 class="display-3 fw-bold mb-4 hero-title">
                            <span class="line"><span class="word">Distribute</span> <span class="word">at</span></span>
                            <span class="line"><span class="word text-gradient-cyan">Light Speed.</span></span>
                        </h1>
                        <p class="lead text-secondary mb-5">
                            A high-performance tool to share your videos across YouTube, TikTok, Facebook, and Instagram simultaneously. MediaFusion your social presence with a single click.
                        </p>
                        <div class="d-flex gap-4">
                            <a href="connect.php" class="btn-magnetic" style="border-color: var(--neon-magenta); color: #fff;">
                                Link Your Socials <i class="fa-solid fa-plug ms-2"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="col-lg-6 position-relative z-1">
                        <!-- Floating Orbit GSAP Animation -->
                        <div class="orbit-container">
                            <div class="center-hub">
                                <img src="assets/img/logo.webp" alt="Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            </div>
                            <div class="orbit-ring">
                                <div class="orbit-icon yt"><i class="fa-brands fa-youtube"></i></div>
                                <div class="orbit-icon tt"><i class="fa-brands fa-tiktok"></i></div>
                                <div class="orbit-icon fb"><i class="fa-brands fa-facebook-f"></i></div>
                                <div class="orbit-icon ig"><i class="fa-brands fa-instagram"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section class="py-5 mb-6">
            <div class="container">
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="glass-card h-100 gsap-fade-in text-center">
                            <i class="fa-solid fa-file-video fa-3x mb-3 text-gradient-cyan"></i>
                            <h3 class="mb-3">Unlimited Size</h3>
                            <p class="text-secondary">Upload large video files seamlessly without worrying about size limits. Send 2GB+ files easily.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="glass-card h-100 gsap-fade-in text-center">
                            <i class="fa-solid fa-bolt fa-3x mb-3 text-gradient-magenta"></i>
                            <h3 class="mb-3">Fast Sharing</h3>
                            <p class="text-secondary">Your content is sent to all social accounts at once in the background.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="glass-card h-100 gsap-fade-in text-center">
                            <i class="fa-solid fa-chart-line fa-3x mb-3 text-gradient-cyan"></i>
                            <h3 class="mb-3">Live Tracking</h3>
                            <p class="text-secondary">Monitor your uploads in real-time with our reactive neon status dashboard.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </main>

<?php include_once 'includes/footer.php'; ?>
