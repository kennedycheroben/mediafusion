<?php
/**
 * MediaFusion - Mission Control Dashboard
 * 
 * CORE ENGAGEMENT & ROUTING FEATURES:
 * 1. Live Consolidated Analytics: Renders asynchronous metrics panels for Likes, Comments, and Views.
 * 2. Cache-Bypass Synchronization: Connects directly with our fetch_live_analytics.php dispatcher.
 * 3. Brand-Individualized Navigation: Maps custom Bootstrap brand view buttons for each published channel.
 * 4. Automatic Native Routing: Feeds post actions securely into our whitelisted view_post_router.php.
 */

declare(strict_types=1);

$pageTitle  = 'Dashboard — MediaFusion';
$activePage = 'dashboard';
$extraHead = '
<style>
    /* Premium Brand view buttons custom outlines matching brand guidelines */
    .btn-brand-youtube {
        background-color: transparent !important;
        color: var(--youtube-red) !important;
        border: 1px solid var(--youtube-red) !important;
        transition: all 0.3s ease;
    }
    .btn-brand-youtube:hover {
        background-color: var(--youtube-red) !important;
        color: #fff !important;
        box-shadow: 0 0 10px rgba(255, 0, 0, 0.4);
    }

    .btn-brand-tiktok {
        background-color: transparent !important;
        color: var(--tiktok-cyan) !important;
        border: 1px solid var(--tiktok-cyan) !important;
        transition: all 0.3s ease;
    }
    .btn-brand-tiktok:hover {
        background-color: #111827 !important;
        color: #fff !important;
        border-color: var(--tiktok-pink) !important;
        box-shadow: -2px -2px 0 var(--tiktok-cyan), 2px 2px 0 var(--tiktok-pink);
    }

    .btn-brand-facebook {
        background-color: transparent !important;
        color: var(--meta-blue) !important;
        border: 1px solid var(--meta-blue) !important;
        transition: all 0.3s ease;
    }
    .btn-brand-facebook:hover {
        background-color: var(--meta-blue) !important;
        color: #fff !important;
        box-shadow: 0 0 10px rgba(24, 119, 242, 0.4);
    }

    .btn-brand-instagram {
        background-color: transparent !important;
        color: var(--neon-magenta) !important;
        border: 1px solid var(--neon-magenta) !important;
        transition: all 0.3s ease;
    }
    .btn-brand-instagram:hover {
        background: linear-gradient(45deg, #f09433, #bc1888, var(--neon-magenta)) !important;
        color: #fff !important;
        border-color: transparent !important;
        box-shadow: 0 0 10px rgba(255, 0, 255, 0.4);
    }

    /* Live Analytics metric card styling */
    .dashboard-stat-card {
        background: var(--card-bg) !important;
        border: 1px solid var(--card-border) !important;
        border-radius: 16px !important;
        padding: 1.25rem;
        transition: all 0.3s ease;
        text-align: center;
        box-shadow: var(--card-shadow) !important;
    }
    .dashboard-stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 40px rgba(15, 23, 42, 0.12) !important;
    }
    .stat-metric-title {
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--text-secondary);
        margin-bottom: 0.25rem;
    }
    .stat-metric-value {
        font-family: \'Plus Jakarta Sans\', sans-serif;
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--text-primary) !important;
    }
    .dashboard-stat-card-tiktok {
        border-left: 3px solid var(--tiktok-cyan) !important;
    }
    .stat-brand-tiktok {
        color: var(--tiktok-cyan) !important;
        text-shadow: 1px 1px 0 var(--tiktok-pink);
    }
</style>
';

include 'header.php';

// Fetch data from DB if available
$uploads = [];
try {
    require_once 'backend/db.php';
    $stmt = $pdo->prepare("SELECT * FROM uploads WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // If DB is not yet set up, mock some data for the UI
    $uploads = [
        [
            'id' => 1,
            'filename' => 'cyberpunk_promo.mp4',
            'platforms' => '["youtube", "tiktok"]',
            'status' => 'live',
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
        ],
        [
            'id' => 2,
            'filename' => 'gameplay_footage.mov',
            'platforms' => '["meta"]',
            'status' => 'uploading',
            'created_at' => date('Y-m-d H:i:s', strtotime('-5 mins'))
        ],
        [
            'id' => 3,
            'filename' => 'corrupted_file.avi',
            'platforms' => '["youtube", "tiktok", "meta"]',
            'status' => 'failed',
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ]
    ];
}
?>

<main style="padding-top: 100px; min-height: 100vh;">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h1 class="display-5 text-gradient-cyan">Your Dashboard</h1>
            <p class="text-secondary">Track your posts and check your views, likes, and comments in one place.</p>
        </div>

        <!-- 3. LIVE CONSOLIDATED ANALYTICS INTERFACE PANEL (Task 3) -->
        <div class="glass-card mb-5">
            <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary pb-3 flex-wrap gap-3">
                <div>
                    <h4 class="mb-1"><i class="fa-solid fa-chart-line me-2 text-gradient-cyan"></i>Real-Time Channel Stats</h4>
                    <p class="text-secondary mb-0" style="font-size: 0.8rem;">Views, likes, and comments from your connected accounts.</p>
                </div>
                <div>
                    <button onclick="refreshDashboardAnalytics()" class="btn btn-outline-primary btn-sm fw-bold text-uppercase px-3" style="border-radius: 4px;">
                        Refresh Stats <i class="fa-solid fa-arrows-rotate ms-1" id="dashSyncIcon"></i>
                    </button>
                </div>
            </div>

            <!-- Compliance Message Banner -->
            <div class="alert border-0 mb-4 py-2 px-3 d-flex align-items-center" style="background: rgba(79, 70, 229, 0.06); color: var(--text-secondary); font-size: 0.8rem; border-radius: 8px;">
                <i class="fa-solid fa-circle-info text-info me-2"></i>
                <span id="dashComplianceMsg">Checking your channel connection status...</span>
            </div>

            <div class="row g-3">
                <!-- YouTube Metrics Matrix -->
                <div class="col-md-3">
                    <div class="dashboard-stat-card border-danger" style="border-left: 3px solid var(--youtube-red);">
                        <div class="text-danger fw-bold small text-uppercase mb-3"><i class="fa-brands fa-youtube me-1"></i> YouTube Channel</div>
                        <div class="row g-1">
                            <div class="col-4">
                                <div class="stat-metric-title">Likes</div>
                                <div class="stat-metric-value" id="dashYT-likes">—</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-metric-title">Comments</div>
                                <div class="stat-metric-value" id="dashYT-comments">—</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-metric-title">Views</div>
                                <div class="stat-metric-value" id="dashYT-views">—</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TikTok Metrics Matrix -->
                <div class="col-md-3">
                    <div class="dashboard-stat-card dashboard-stat-card-tiktok">
                        <div class="stat-brand-tiktok fw-bold small text-uppercase mb-3"><i class="fa-brands fa-tiktok me-1"></i> TikTok Creator</div>
                        <div class="row g-1">
                            <div class="col-4">
                                <div class="stat-metric-title">Likes</div>
                                <div class="stat-metric-value" id="dashTT-likes">—</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-metric-title">Comments</div>
                                <div class="stat-metric-value" id="dashTT-comments">—</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-metric-title">Views</div>
                                <div class="stat-metric-value" id="dashTT-views">—</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Facebook Metrics Matrix -->
                <div class="col-md-3">
                    <div class="dashboard-stat-card border-primary" style="border-left: 3px solid var(--meta-blue);">
                        <div class="fw-bold small text-uppercase mb-3" style="color: var(--meta-blue);"><i class="fa-brands fa-facebook-f me-1"></i> Facebook Page</div>
                        <div class="row g-1">
                            <div class="col-4">
                                <div class="stat-metric-title">Likes</div>
                                <div class="stat-metric-value" id="dashFB-likes">—</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-metric-title">Comments</div>
                                <div class="stat-metric-value" id="dashFB-comments">—</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-metric-title">Views</div>
                                <div class="stat-metric-value" id="dashFB-views">—</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Instagram Metrics Matrix -->
                <div class="col-md-3">
                    <div class="dashboard-stat-card" style="border-left: 3px solid #e1306c;">
                        <div class="fw-bold small text-uppercase mb-3" style="color: #e1306c;"><i class="fa-brands fa-instagram me-1"></i> Instagram</div>
                        <div class="row g-1">
                            <div class="col-4">
                                <div class="stat-metric-title">Likes</div>
                                <div class="stat-metric-value" id="dashIG-likes">—</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-metric-title">Comments</div>
                                <div class="stat-metric-value" id="dashIG-comments">—</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-metric-title">Views</div>
                                <div class="stat-metric-value" id="dashIG-views">—</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- UPLOADS HISTORY & NATIVE REDIRECTS (Task 1) -->
        <div class="table-responsive">
            <table class="table table-cyber w-100" id="uploadsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Filename</th>
                        <th>Platforms</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($uploads as $up): ?>
                        <?php 
                            $platforms = json_decode($up['platforms'], true) ?: [];
                            $statusClass = '';
                            $statusIcon = '';
                            if ($up['status'] == 'live') {
                                $statusClass = 'status-live';
                                $statusIcon = '<i class="fa-solid fa-circle-check"></i>';
                            } elseif ($up['status'] == 'uploading') {
                                $statusClass = 'status-uploading';
                                $statusIcon = '<div class="neon-spinner" style="width:16px; height:16px; border-width: 2px;"></div>';
                            } elseif ($up['status'] == 'failed') {
                                $statusClass = 'status-failed';
                                $statusIcon = '<i class="fa-solid fa-triangle-exclamation"></i>';
                            } else {
                                $statusClass = 'status-badge text-secondary border border-secondary';
                                $statusIcon = '<i class="fa-solid fa-clock"></i>';
                            }
                        ?>
                        <tr data-id="<?= $up['id'] ?>">
                            <td>#<?= $up['id'] ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($up['filename']) ?></td>
                            <td>
                                <?php if(in_array('youtube',   $platforms)): ?> <i class="fa-brands fa-youtube fs-5 me-2" style="color: var(--youtube-red);"></i> <?php endif; ?>
                                <?php if(in_array('tiktok',    $platforms)): ?> <i class="fa-brands fa-tiktok fs-5 me-2" style="color: var(--tiktok-cyan); text-shadow: 1px 1px 0 var(--tiktok-pink);"></i> <?php endif; ?>
                                <?php if(in_array('facebook',  $platforms)): ?> <i class="fa-brands fa-facebook-f fs-5 me-2" style="color: var(--meta-blue);"></i> <?php endif; ?>
                                <?php if(in_array('instagram', $platforms)): ?> <i class="fa-brands fa-instagram fs-5 me-2" style="color: #e1306c;"></i> <?php endif; ?>
                                <?php if(in_array('meta',      $platforms)): ?> <i class="fa-brands fa-meta fs-5" style="color: var(--meta-blue);"></i> <?php endif; ?>
                            </td>
                            <td class="text-secondary"><?= date('M j, Y H:i', strtotime($up['created_at'])) ?></td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>">
                                    <?= $statusIcon ?> <?= ucfirst($up['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if($up['status'] == 'live'): ?>
                                    <!-- Render platform-individual View buttons directly for easy, custom navigation -->
                                    <div class="d-flex flex-wrap gap-1 align-items-center">
                                        <?php if(in_array('youtube', $platforms)): ?>
                                            <a href="view_post_router.php?platform=youtube&amp;post_id=<?= $up['id'] ?>" target="_blank" class="btn btn-sm btn-brand-youtube" style="font-size: 0.72rem; padding: 0.25rem 0.5rem; text-transform: uppercase;">
                                                <i class="fa-brands fa-youtube me-1"></i> YouTube
                                            </a>
                                        <?php endif; ?>
                                        <?php if(in_array('tiktok', $platforms)): ?>
                                            <a href="view_post_router.php?platform=tiktok&amp;post_id=<?= $up['id'] ?>" target="_blank" class="btn btn-sm btn-brand-tiktok" style="font-size: 0.72rem; padding: 0.25rem 0.5rem; text-transform: uppercase;">
                                                <i class="fa-brands fa-tiktok me-1"></i> TikTok
                                            </a>
                                        <?php endif; ?>
                                        <?php if(in_array('facebook', $platforms)): ?>
                                            <a href="view_post_router.php?platform=facebook&amp;post_id=<?= $up['id'] ?>" target="_blank" class="btn btn-sm btn-brand-facebook" style="font-size: 0.72rem; padding: 0.25rem 0.5rem; text-transform: uppercase;">
                                                <i class="fa-brands fa-facebook-f me-1"></i> FB
                                            </a>
                                        <?php endif; ?>
                                        <?php if(in_array('instagram', $platforms)): ?>
                                            <a href="view_post_router.php?platform=instagram&amp;post_id=<?= $up['id'] ?>" target="_blank" class="btn btn-sm btn-brand-instagram" style="font-size: 0.72rem; padding: 0.25rem 0.5rem; text-transform: uppercase;">
                                                <i class="fa-brands fa-instagram me-1"></i> IG
                                            </a>
                                        <?php endif; ?>
                                        <?php if(in_array('meta', $platforms)): ?>
                                            <a href="view_post_router.php?platform=facebook&amp;post_id=<?= $up['id'] ?>" target="_blank" class="btn btn-sm btn-brand-facebook" style="font-size: 0.72rem; padding: 0.25rem 0.5rem; text-transform: uppercase;">
                                                <i class="fa-brands fa-facebook-f me-1"></i> FB
                                            </a>
                                            <a href="view_post_router.php?platform=instagram&amp;post_id=<?= $up['id'] ?>" target="_blank" class="btn btn-sm btn-brand-instagram" style="font-size: 0.72rem; padding: 0.25rem 0.5rem; text-transform: uppercase;">
                                                <i class="fa-brands fa-instagram me-1"></i> IG
                                            </a>
                                        <?php endif; ?>
                                        
                                        <!-- Post-level Analytics Trigger -->
                                        <button onclick="openPostAnalytics(<?= $up['id'] ?>, '<?= htmlspecialchars($up['filename'], ENT_QUOTES) ?>')" class="btn btn-sm btn-outline-primary ms-auto" style="font-size: 0.72rem; padding: 0.25rem 0.5rem; text-transform: uppercase;">
                                            <i class="fa-solid fa-chart-simple me-1"></i> Stats
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary py-1" style="font-size: 0.72rem; text-transform: uppercase;" disabled>Processing...</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if(empty($uploads)): ?>
                <!-- Interactive High-Fidelity Onboarding Moment Empty State -->
                <div class="surface-light text-center py-5 px-4 my-4" style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 16px;">
                    <div class="mb-4 position-relative d-inline-block">
                        <img src="assets/img/logo.webp" alt="MediaFusion Logo" style="width: 120px; height: auto; animation: pulse 2s infinite ease-in-out; filter: drop-shadow(0 0 8px rgba(79,70,229,0.2));">
                    </div>
                    <h3 class="h4 mb-2 font-space-grotesk">Your Social Distribution Launchpad is Quiet</h3>
                    <p class="text-secondary mx-auto mb-4" style="max-width: 500px; font-size: 0.9rem;">
                        Connect your social accounts, produce your first short-form video in the workbench, and upload it to YouTube, TikTok, and Meta in one click.
                    </p>
                    
                    <!-- Structured Onboarding Progress Grid -->
                    <div class="row justify-content-center text-start g-3 mb-5 mx-auto" style="max-width: 600px;">
                        <div class="col-md-4">
                            <div class="p-3 h-100" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px;">
                                <div class="badge bg-primary mb-2 text-white fw-bold">Step 1</div>
                                <h5 class="small mb-1">Link Channels</h5>
                                <p class="text-secondary small mb-0" style="font-size:0.75rem;">Authorize YouTube, TikTok, or Meta platforms in vault.</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 h-100" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px;">
                                <div class="badge mb-2 text-white fw-bold" style="background-color: var(--neon-magenta, #ec4899) !important;">Step 2</div>
                                <h5 class="small mb-1">Create Clip</h5>
                                <p class="text-secondary small mb-0" style="font-size:0.75rem;">Trim, crop, and adjust your videos in the studio.</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 h-100" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px;">
                                <div class="badge bg-success mb-2 text-white fw-bold">Step 3</div>
                                <h5 class="small mb-1">Launch Post</h5>
                                <p class="text-secondary small mb-0" style="font-size:0.75rem;">Publish and track performance in real-time.</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Call To Action Buttons -->
                    <div class="d-flex flex-wrap gap-3 justify-content-center">
                        <a href="studio.php" class="btn btn-primary px-4 py-2 fw-bold text-uppercase" style="border-radius: 4px;">
                            <i class="fa-solid fa-wand-magic-sparkles me-2"></i> Open Video Studio
                        </a>
                        <a href="connect.php" class="btn btn-outline-secondary px-4 py-2 fw-bold text-uppercase" style="border-radius: 4px;">
                            <i class="fa-solid fa-key me-2"></i> Link Social Channels
                        </a>
                    </div>
                </div>
                
                <style>
                @keyframes pulse {
                    0% { transform: scale(1); opacity: 0.85; }
                    50% { transform: scale(1.05); opacity: 1; filter: drop-shadow(0 0 15px rgba(0,243,255,0.65)); }
                    100% { transform: scale(1); opacity: 0.85; }
                }
                .font-space-grotesk {
                    font-family: 'Space Grotesk', sans-serif;
                    letter-spacing: 0.5px;
                }
                </style>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
    /**
     * Dynamically pulls absolute statistics bypasses background cron layers on page load
     */
    function refreshDashboardAnalytics() {
        const syncIcon = document.getElementById('dashSyncIcon');
        if (syncIcon) syncIcon.classList.add('fa-spin');

        fetch('fetch_live_analytics.php?action=fetch', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => {
            if (!res.ok) throw new Error("HTTP validation failure.");
            return res.json();
        })
        .then(data => {
            if (syncIcon) syncIcon.classList.remove('fa-spin');
            if (data.success) {
                // Populate required compliance message string
                document.getElementById('dashComplianceMsg').innerText = data.message;
                
                // Map values to DOM
                data.metrics.forEach(item => {
                    let key = '';
                    if (item.platform === 'youtube')   key = 'YT';
                    else if (item.platform === 'tiktok')    key = 'TT';
                    else if (item.platform === 'facebook')  key = 'FB';
                    else if (item.platform === 'instagram') key = 'IG';
                    else if (item.platform === 'meta')      key = 'FB'; // legacy fallback

                    if (key !== '') {
                        if (item.connected) {
                            document.getElementById(`dash${key}-likes`).innerText    = item.formatted.likes;
                            document.getElementById(`dash${key}-comments`).innerText = item.formatted.comments;
                            document.getElementById(`dash${key}-views`).innerText    = item.formatted.views;
                        } else {
                            document.getElementById(`dash${key}-likes`).innerText    = '—';
                            document.getElementById(`dash${key}-comments`).innerText = '—';
                            document.getElementById(`dash${key}-views`).innerText    = '—';
                        }
                    }
                });
            }
        })
        .catch(err => {
            if (syncIcon) syncIcon.classList.remove('fa-spin');
            console.error("Dashboard consolidated stats fetch failure:", err);
            document.getElementById('dashComplianceMsg').innerText = "Stats could not be loaded. Please check your social account connections.";
        });
    }

    let postAnalyticsModal = null;
    function openPostAnalytics(postId, filename) {
        if (!postAnalyticsModal) {
            postAnalyticsModal = new bootstrap.Modal(document.getElementById('postAnalyticsModal'));
        }

        document.getElementById('modalPostFilename').innerText = filename;
        document.getElementById('modalPostTitle').innerText = 'Loading post details...';
        document.getElementById('modalTotalViews').innerText = '—';
        document.getElementById('modalTotalLikes').innerText = '—';
        document.getElementById('modalTotalComments').innerText = '—';
        document.getElementById('modalPlatformGrid').innerHTML = `
            <div class="col-12 text-center py-4">
                <div class="neon-spinner mx-auto mb-2" style="width: 30px; height: 30px;"></div>
                <div class="text-secondary small">Loading post stats...</div>
            </div>
        `;

        postAnalyticsModal.show();

        fetch(`fetch_live_analytics.php?action=fetch_post&post_id=${postId}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => {
            if (!res.ok) throw new Error("Failed to load post analytics.");
            return res.json();
        })
        .then(data => {
            if (data.success) {
                document.getElementById('modalPostTitle').innerText = data.title;
                document.getElementById('modalTotalViews').innerText = data.totals.formatted.views;
                document.getElementById('modalTotalLikes').innerText = data.totals.formatted.likes;
                document.getElementById('modalTotalComments').innerText = data.totals.formatted.comments;

                let gridHtml = '';
                data.metrics.forEach(m => {
                    let borderClass = '';
                    let platformTitle = '';
                    let platformIcon = '';
                    let colorStyle = '';
                    
                    if (m.platform === 'youtube') {
                        borderClass = 'border-danger';
                        platformTitle = 'YouTube';
                        platformIcon = '<i class="fa-brands fa-youtube me-2"></i>';
                        colorStyle = 'color: var(--youtube-red);';
                    } else if (m.platform === 'tiktok') {
                        borderClass = 'border-dark';
                        platformTitle = 'TikTok';
                        platformIcon = '<i class="fa-brands fa-tiktok me-2"></i>';
                        colorStyle = 'color: var(--tiktok-cyan); text-shadow: 1px 1px 0 var(--tiktok-pink);';
                    } else if (m.platform === 'facebook') {
                        borderClass = 'border-primary';
                        platformTitle = 'Facebook';
                        platformIcon = '<i class="fa-brands fa-facebook-f me-2"></i>';
                        colorStyle = 'color: var(--meta-blue);';
                    } else if (m.platform === 'instagram') {
                        borderClass = 'border-danger';
                        platformTitle = 'Instagram';
                        platformIcon = '<i class="fa-brands fa-instagram me-2"></i>';
                        colorStyle = 'color: #e1306c;';
                    }

                    if (m.connected) {
                        gridHtml += `
                            <div class="col-md-6">
                                <div class="post-platform-card h-100" style="border-left: 3px solid ${m.platform === 'youtube' ? 'var(--youtube-red)' : m.platform === 'facebook' ? 'var(--meta-blue)' : m.platform === 'tiktok' ? 'var(--tiktok-cyan)' : '#e1306c'};">
                                    <div class="fw-bold small text-uppercase mb-3" style="${colorStyle}">
                                        ${platformIcon} ${platformTitle}
                                    </div>
                                    <div class="row text-center g-1">
                                        <div class="col-4">
                                            <div class="text-secondary" style="font-size: 0.65rem;">Views</div>
                                            <div class="fw-bold text-dark small">${m.formatted.views}</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="text-secondary" style="font-size: 0.65rem;">Likes</div>
                                            <div class="fw-bold text-dark small">${m.formatted.likes}</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="text-secondary" style="font-size: 0.65rem;">Comments</div>
                                            <div class="fw-bold text-dark small">${m.formatted.comments}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        gridHtml += `
                            <div class="col-md-6">
                                <div class="post-platform-card h-100 opacity-50" style="border-left: 3px solid var(--text-secondary);">
                                    <div class="fw-bold small text-uppercase mb-2 text-secondary">
                                        ${platformIcon} ${platformTitle}
                                    </div>
                                    <div class="text-muted small">Channel disconnected.</div>
                                </div>
                            </div>
                        `;
                    }
                });

                document.getElementById('modalPlatformGrid').innerHTML = gridHtml;
            } else {
                document.getElementById('modalPlatformGrid').innerHTML = `
                    <div class="col-12 text-center py-4 text-danger">
                        <i class="fa-solid fa-triangle-exclamation fa-2x mb-2"></i>
                        <div>Error: ${data.message}</div>
                    </div>
                `;
            }
        })
        .catch(err => {
            console.error(err);
            document.getElementById('modalPlatformGrid').innerHTML = `
                <div class="col-12 text-center py-4 text-danger">
                    <i class="fa-solid fa-triangle-exclamation fa-2x mb-2"></i>
                    <div>Connection failed. Please check your internet connection.</div>
                </div>
            `;
        });
    }

    // Initialize analytics load
    window.addEventListener('DOMContentLoaded', () => {
        refreshDashboardAnalytics();
    });
</script>

<?php ob_start(); ?>
<style>
/* Modern Creator Modal Styling */
.modal-cyberpunk .modal-content {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 40px rgba(15, 23, 42, 0.08);
    color: var(--text-primary);
    border-radius: 16px;
}
.post-platform-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.3s ease;
}
.post-platform-card:hover {
    border-color: #cbd5e1;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(15, 23, 42, 0.05);
}
</style>

<!-- Post Analytics Modal -->
<div class="modal fade modal-cyberpunk" id="postAnalyticsModal" tabindex="-1" aria-labelledby="postAnalyticsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-gradient-cyan" id="postAnalyticsModalLabel">
                    <i class="fa-solid fa-chart-simple me-2"></i>Post Statistics
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-4">
                    <span class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">Active Post</span>
                    <h4 class="mb-0 text-dark" id="modalPostFilename">—</h4>
                    <p class="text-secondary small mb-0" id="modalPostTitle">—</p>
                </div>

                <!-- Combined Totals Segment -->
                <div class="glass-card mb-4 p-3" style="background: #f8fafc; border: 1px solid #e2e8f0; box-shadow: none !important;">
                    <div class="text-center text-gradient-magenta fw-bold small text-uppercase mb-3" style="font-size: 0.75rem; letter-spacing: 1px;">
                        <i class="fa-solid fa-calculator me-1"></i> Combined Platform Totals
                    </div>
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="text-secondary small text-uppercase mb-1" style="font-size: 0.65rem;">Views</div>
                            <div class="fs-4 fw-bold text-dark" id="modalTotalViews">0</div>
                        </div>
                        <div class="col-4">
                            <div class="text-secondary small text-uppercase mb-1" style="font-size: 0.65rem;">Likes</div>
                            <div class="fs-4 fw-bold text-dark" id="modalTotalLikes">0</div>
                        </div>
                        <div class="col-4">
                            <div class="text-secondary small text-uppercase mb-1" style="font-size: 0.65rem;">Comments</div>
                            <div class="fs-4 fw-bold text-dark" id="modalTotalComments">0</div>
                        </div>
                    </div>
                </div>

                <div class="text-secondary small text-uppercase mb-3" style="font-size: 0.7rem; letter-spacing: 1px;"><i class="fa-solid fa-network-wired me-1"></i> Breakdown by Platform</div>
                
                <!-- Platform Breakdown Cards Container -->
                <div class="row g-3" id="modalPlatformGrid">
                    <!-- Dynamic platform metric cards go here -->
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-between">
                <span class="text-secondary small" style="font-size: 0.75rem;">
                    Stats are calculated and updated live from your channels.
                </span>
                <button type="button" class="btn btn-outline-secondary btn-sm px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php
$extraFooter = ob_get_clean();
include_once 'includes/footer.php';
?>
