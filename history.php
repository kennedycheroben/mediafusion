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
        color: #fff !important;
        border: 1px solid var(--youtube-red) !important;
        transition: all 0.3s ease;
    }
    .btn-brand-youtube:hover {
        background-color: var(--youtube-red) !important;
        box-shadow: 0 0 10px rgba(255, 0, 0, 0.4);
    }

    .btn-brand-tiktok {
        background-color: transparent !important;
        color: #fff !important;
        border: 1px solid var(--tiktok-cyan) !important;
        transition: all 0.3s ease;
    }
    .btn-brand-tiktok:hover {
        background-color: #121212 !important;
        border-color: var(--tiktok-pink) !important;
        box-shadow: -2px -2px 0 var(--tiktok-cyan), 2px 2px 0 var(--tiktok-pink);
    }

    .btn-brand-facebook {
        background-color: transparent !important;
        color: #fff !important;
        border: 1px solid var(--meta-blue) !important;
        transition: all 0.3s ease;
    }
    .btn-brand-facebook:hover {
        background-color: var(--meta-blue) !important;
        box-shadow: 0 0 10px rgba(24, 119, 242, 0.4);
    }

    .btn-brand-instagram {
        background-color: transparent !important;
        color: #fff !important;
        border: 1px solid var(--neon-magenta) !important;
        transition: all 0.3s ease;
    }
    .btn-brand-instagram:hover {
        background: linear-gradient(45deg, #f09433, #bc1888, var(--neon-magenta)) !important;
        border-color: transparent !important;
        box-shadow: 0 0 10px rgba(255, 0, 255, 0.4);
    }

    /* Live Analytics metric card styling */
    .dashboard-stat-card {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid var(--glass-border);
        border-radius: 12px;
        padding: 1.25rem;
        transition: all 0.3s ease;
        text-align: center;
    }
    .dashboard-stat-card:hover {
        transform: translateY(-2px);
        border-color: rgba(255, 255, 255, 0.15);
    }
    .stat-metric-title {
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--text-secondary);
        margin-bottom: 0.25rem;
    }
    .stat-metric-value {
        font-family: \'Space Grotesk\', sans-serif;
        font-size: 1.35rem;
        font-weight: 700;
        color: #fff;
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
            <h1 class="display-5 text-gradient-cyan">Mission Control</h1>
            <p class="text-secondary">Track your multi-platform distribution and real-time consolidated analytics.</p>
        </div>

        <!-- 3. LIVE CONSOLIDATED ANALYTICS INTERFACE PANEL (Task 3) -->
        <div class="glass-card mb-5">
            <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary pb-3 flex-wrap gap-3">
                <div>
                    <h4 class="text-white mb-1"><i class="fa-solid fa-chart-line me-2 text-gradient-cyan"></i>Real-Time Channel Engagement</h4>
                    <p class="text-secondary mb-0" style="font-size: 0.8rem;">Real-time data fetched directly from your connected accounts.</p>
                </div>
                <div>
                    <button onclick="refreshDashboardAnalytics()" class="btn btn-outline-info btn-sm fw-bold text-uppercase px-3" style="border-radius: 4px;">
                        Refresh Stats <i class="fa-solid fa-arrows-rotate ms-1" id="dashSyncIcon"></i>
                    </button>
                </div>
            </div>

            <!-- Compliance Message Banner -->
            <div class="alert alert-info border-0 mb-4 py-2 px-3 d-flex align-items-center" style="background: rgba(0, 243, 255, 0.05); color: var(--text-secondary); font-size: 0.8rem;">
                <i class="fa-solid fa-circle-info text-info me-2"></i>
                <span id="dashComplianceMsg">Loading dynamic API integration handshakes...</span>
            </div>

            <div class="row g-3">
                <!-- YouTube Metrics Matrix -->
                <div class="col-md-4">
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
                <div class="col-md-4">
                    <div class="dashboard-stat-card border-light" style="border-left: 3px solid #fff;">
                        <div class="text-white fw-bold small text-uppercase mb-3"><i class="fa-brands fa-tiktok me-1"></i> TikTok Creator</div>
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

                <!-- Meta Metrics Matrix -->
                <div class="col-md-4">
                    <div class="dashboard-stat-card border-primary" style="border-left: 3px solid var(--meta-blue);">
                        <div class="text-primary fw-bold small text-uppercase mb-3"><i class="fa-brands fa-meta me-1"></i> Meta Pages</div>
                        <div class="row g-1">
                            <div class="col-4">
                                <div class="stat-metric-title">Likes</div>
                                <div class="stat-metric-value" id="dashMeta-likes">—</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-metric-title">Comments</div>
                                <div class="stat-metric-value" id="dashMeta-comments">—</div>
                            </div>
                            <div class="col-4">
                                <div class="stat-metric-title">Views</div>
                                <div class="stat-metric-value" id="dashMeta-views">—</div>
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
                            <td class="text-white fw-bold"><?= htmlspecialchars($up['filename']) ?></td>
                            <td>
                                <?php if(in_array('youtube', $platforms)): ?> <i class="fa-brands fa-youtube fs-5 me-2" style="color: var(--youtube-red);"></i> <?php endif; ?>
                                <?php if(in_array('tiktok', $platforms)): ?> <i class="fa-brands fa-tiktok fs-5 me-2" style="color: #fff;"></i> <?php endif; ?>
                                <?php if(in_array('meta', $platforms)): ?> <i class="fa-brands fa-meta fs-5" style="color: var(--meta-blue);"></i> <?php endif; ?>
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
                                    <div class="d-flex flex-wrap gap-1">
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
                                        <?php if(in_array('meta', $platforms)): ?>
                                            <a href="view_post_router.php?platform=facebook&amp;post_id=<?= $up['id'] ?>" target="_blank" class="btn btn-sm btn-brand-facebook" style="font-size: 0.72rem; padding: 0.25rem 0.5rem; text-transform: uppercase;">
                                                <i class="fa-brands fa-facebook-f me-1"></i> FB
                                            </a>
                                            <a href="view_post_router.php?platform=instagram&amp;post_id=<?= $up['id'] ?>" target="_blank" class="btn btn-sm btn-brand-instagram" style="font-size: 0.72rem; padding: 0.25rem 0.5rem; text-transform: uppercase;">
                                                <i class="fa-brands fa-instagram me-1"></i> IG
                                            </a>
                                        <?php endif; ?>
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
                <div class="text-center py-5 text-secondary">
                    <i class="fa-solid fa-satellite fa-3x mb-3"></i>
                    <p>No transmissions found. Go to the Studio to launch one.</p>
                </div>
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
                    if (item.platform === 'youtube') key = 'YT';
                    else if (item.platform === 'tiktok') key = 'TT';
                    else if (item.platform === 'meta') key = 'Meta';

                    if (key !== '') {
                        if (item.connected) {
                            document.getElementById(`dash${key}-likes`).innerText = item.formatted.likes;
                            document.getElementById(`dash${key}-comments`).innerText = item.formatted.comments;
                            document.getElementById(`dash${key}-views`).innerText = item.formatted.views;
                        } else {
                            document.getElementById(`dash${key}-likes`).innerText = "—";
                            document.getElementById(`dash${key}-comments`).innerText = "—";
                            document.getElementById(`dash${key}-views`).innerText = "—";
                        }
                    }
                });
            }
        })
        .catch(err => {
            if (syncIcon) syncIcon.classList.remove('fa-spin');
            console.error("Dashboard consolidated stats fetch failure:", err);
            document.getElementById('dashComplianceMsg').innerText = "Dynamic statistics synchronization currently offline. Verify vault connections.";
        });
    }

    // Initialize analytics load
    window.addEventListener('DOMContentLoaded', () => {
        refreshDashboardAnalytics();
    });
</script>

<?php include_once 'includes/footer.php'; ?>
