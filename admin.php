<?php
declare(strict_types=1);

/**
 * admin.php — Central Administration Panel.
 * - Restricts access to administrator users.
 * - Visualizes counters for users, inquiries, and media uploads.
 * - Provides controls to view/manage inquiries, users, and uploads.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Localhost-only debug endpoint: use ?admin_debug=1 from 127.0.0.1 or ::1
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (isset($_GET['admin_debug']) && in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    // Attempt to show whether the session and DB think this user is an admin
    require_once __DIR__ . '/backend/db.php';
    $dbIsAdmin = null;
    try {
        if (isset($_SESSION['user_id'])) {
            $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $dbIsAdmin = (int)($stmt->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {
        $dbIsAdmin = 'error';
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'remote' => $remoteAddr,
        'session_user_id' => $_SESSION['user_id'] ?? null,
        'session_is_admin' => $_SESSION['is_admin'] ?? null,
        'db_is_admin' => $dbIsAdmin,
    ]);
    exit;
}

// Access Control Hardening
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Ensure we have the latest admin flag from the database (don't trust stale session value)
require_once __DIR__ . '/backend/db.php';
try {
    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $isAdmin = (int)($stmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $isAdmin = 0;
}

if (empty($isAdmin)) {
    header('Location: login.php');
    exit;
}

// keep session in sync with DB
$_SESSION['is_admin'] = $isAdmin;

$pageTitle  = 'Admin Panel - MediaFusion';
$activePage = 'admin';

try {
    // 1. Fetch Stats Count
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
    $totalInquiries = (int)$pdo->query("SELECT COUNT(*) FROM `contact_inquiries`")->fetchColumn();
    $unreadInquiries = (int)$pdo->query("SELECT COUNT(*) FROM `contact_inquiries` WHERE `status` = 'unread'")->fetchColumn();
    $totalUploads = (int)$pdo->query("SELECT COUNT(*) FROM `uploads`")->fetchColumn();

    // 2. Fetch Inquiries
    $stmt = $pdo->query("
        SELECT c.*, u.username as account_username 
        FROM `contact_inquiries` c
        LEFT JOIN `users` u ON c.user_id = u.id
        ORDER BY c.created_at DESC
    ");
    $inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Users
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.display_name, u.email, u.is_admin, u.created_at,
               (SELECT COUNT(*) FROM `uploads` WHERE user_id = u.id) as upload_count
        FROM `users` u
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch Uploaded Media
    $stmt = $pdo->query("
        SELECT up.*, u.username as creator_username
        FROM `uploads` up
        JOIN `users` u ON up.user_id = u.id
        ORDER BY up.created_at DESC
    ");
    $uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Admin panel fetch error: ' . $e->getMessage());
    die("A system error occurred while fetching admin data.");
}

include 'header.php';
?>

<main class="py-5" style="background: var(--bg-color); min-height: 100vh; padding-top: 100px !important;">
    <div class="container py-4">

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-5 flex-wrap gap-3 gsap-fade-in">
            <div>
                <span class="badge bg-primary text-white text-uppercase py-2 px-3 mb-2" style="letter-spacing: 2px; font-size: 0.7rem;">Control Center</span>
                <h1 class="glowing-title text-gradient-cyan mb-1" style="font-size: 2.2rem; font-weight: 800;">Administration Panel</h1>
                <p class="text-secondary mb-0">Monitor live statistics, system configurations, and manage user submissions.</p>
            </div>
            <div>
                <button class="btn btn-outline-primary btn-sm px-3 py-2 text-uppercase fw-bold" onclick="window.location.reload();">
                    <i class="fa-solid fa-arrows-rotate me-2"></i>Reload Data
                </button>
            </div>
        </div>

        <!-- System Summary Cards -->
        <div class="row g-4 mb-5 gsap-fade-in">
            <!-- Total Operators -->
            <div class="col-md-3">
                <div class="glass-card stat-card card-cyan">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-secondary small text-uppercase fw-bold mb-1">Total Users</p>
                            <h3 class="fw-bold mb-0" id="stat-users"><?= $totalUsers ?></h3>
                        </div>
                        <div class="stat-icon-wrap bg-cyan-glow">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Total Media Files -->
            <div class="col-md-3">
                <div class="glass-card stat-card card-magenta">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-secondary small text-uppercase fw-bold mb-1">Uploaded Media</p>
                            <h3 class="fw-bold mb-0" id="stat-uploads"><?= $totalUploads ?></h3>
                        </div>
                        <div class="stat-icon-wrap bg-magenta-glow">
                            <i class="fa-solid fa-photo-film"></i>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Unread Messages -->
            <div class="col-md-3">
                <div class="glass-card stat-card card-orange">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-secondary small text-uppercase fw-bold mb-1">Unread Inquiries</p>
                            <h3 class="fw-bold mb-0" id="stat-unread"><?= $unreadInquiries ?></h3>
                        </div>
                        <div class="stat-icon-wrap bg-orange-glow">
                            <i class="fa-solid fa-bell"></i>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Total Messages -->
            <div class="col-md-3">
                <div class="glass-card stat-card card-green">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-secondary small text-uppercase fw-bold mb-1">Total Inquiries</p>
                            <h3 class="fw-bold mb-0" id="stat-inquiries"><?= $totalInquiries ?></h3>
                        </div>
                        <div class="stat-icon-wrap bg-green-glow">
                            <i class="fa-solid fa-envelope-open-text"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Central Tabs -->
        <div class="row g-4 gsap-fade-in-up">
            <div class="col-12">
                <div class="glass-card p-4 card-magenta">
                    
                    <!-- Navigation Pills -->
                    <ul class="nav nav-pills custom-admin-pills mb-4" id="adminTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active text-uppercase fw-bold" id="inquiries-tab" data-bs-toggle="pill" data-bs-target="#tab-inquiries" type="button" role="tab">
                                <i class="fa-solid fa-inbox me-2"></i>Inquiries (<?= count($inquiries) ?>)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-uppercase fw-bold" id="users-tab" data-bs-toggle="pill" data-bs-target="#tab-users" type="button" role="tab">
                                <i class="fa-solid fa-users-gear me-2"></i>Registered Users (<?= count($users) ?>)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link text-uppercase fw-bold" id="uploads-tab" data-bs-toggle="pill" data-bs-target="#tab-uploads" type="button" role="tab">
                                <i class="fa-solid fa-server me-2"></i>Uploaded Media (<?= count($uploads) ?>)
                            </button>
                        </li>
                    </ul>

                    <div id="adminAlert" class="cyber-alert mb-4 d-none"></div>

                    <!-- Tab Contents -->
                    <div class="tab-content" id="adminTabsContent">
                        
                        <!-- TAB 1: INQUIRIES -->
                        <div class="tab-pane fade show active" id="tab-inquiries" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                <h4 class="text-gradient-magenta mb-0">Contact Form Inquiries</h4>
                                <div class="d-flex gap-2">
                                    <input type="text" id="searchInquiries" class="form-control form-control-cyber form-control-sm" style="width: 220px;" placeholder="Search messages...">
                                    <select id="filterInquiries" class="form-select form-control-cyber form-control-sm" style="width: 140px; background-image: none;">
                                        <option value="all">All Statuses</option>
                                        <option value="unread">Unread</option>
                                        <option value="read">Read</option>
                                        <option value="replied">Replied</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-cyber table-hover align-middle mb-0" id="tableInquiries">
                                    <thead>
                                        <tr>
                                            <th>Timestamp</th>
                                            <th>Sender</th>
                                            <th>Topic</th>
                                            <th>Snippet</th>
                                            <th>Status</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($inquiries)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-secondary py-5">
                                                    <i class="fa-solid fa-envelope-open-text d-block mb-3" style="font-size: 2.5rem;"></i>
                                                    No inquiries have been received yet.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($inquiries as $msg): ?>
                                                <tr data-status="<?= htmlspecialchars($msg['status']) ?>" class="inquiry-row">
                                                    <td class="text-secondary small" style="white-space: nowrap;"><?= date('Y-m-d H:i', strtotime($msg['created_at'])) ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($msg['name']) ?></strong>
                                                        <div class="text-secondary small" style="font-size: 0.75rem;">
                                                            <?= htmlspecialchars($msg['email']) ?> 
                                                            <?= $msg['account_username'] ? '(<span class="text-info">@' . htmlspecialchars($msg['account_username']) . '</span>)' : '' ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light border border-secondary text-secondary small text-capitalize" style="color: var(--text-secondary) !important;">
                                                            <?= htmlspecialchars($msg['subject']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="text-secondary text-truncate" style="max-width: 220px;" title="<?= htmlspecialchars($msg['message']) ?>">
                                                            <?= htmlspecialchars($msg['message']) ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php
                                                            $badgeClass = 'border-danger text-danger';
                                                            if ($msg['status'] === 'read') $badgeClass = 'border-info text-info';
                                                            if ($msg['status'] === 'replied') $badgeClass = 'border-success text-success';
                                                        ?>
                                                        <span class="badge bg-dark border <?= $badgeClass ?> text-uppercase status-badge" style="font-size: 0.65rem;">
                                                            <?= htmlspecialchars($msg['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="d-inline-flex gap-2">
                                                            <button class="btn btn-outline-primary btn-xs px-2" title="Read Full Message" onclick="showMsgModal(<?= htmlspecialchars(json_encode($msg)) ?>)">
                                                                <i class="fa-solid fa-eye"></i>
                                                            </button>
                                                            <select class="form-select form-control-cyber form-control-sm py-0 status-select" style="width: 100px; font-size: 0.75rem; background-image: none;" onchange="updateMessageStatus(<?= $msg['id'] ?>, this.value)">
                                                                <option value="unread" <?= $msg['status'] === 'unread' ? 'selected' : '' ?>>Unread</option>
                                                                <option value="read" <?= $msg['status'] === 'read' ? 'selected' : '' ?>>Read</option>
                                                                <option value="replied" <?= $msg['status'] === 'replied' ? 'selected' : '' ?>>Replied</option>
                                                            </select>
                                                            <button class="btn btn-outline-danger btn-xs px-2" title="Delete Message" onclick="deleteMessage(<?= $msg['id'] ?>, this.closest('tr'))">
                                                                <i class="fa-solid fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TAB 2: REGISTERED USERS -->
                        <div class="tab-pane fade" id="tab-users" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                <h4 class="text-gradient-cyan mb-0">Registered Users</h4>
                                <input type="text" id="searchUsers" class="form-control form-control-cyber form-control-sm" style="width: 220px;" placeholder="Search users...">
                            </div>

                            <div class="table-responsive">
                                <table class="table table-cyber table-hover align-middle mb-0" id="tableUsers">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Display Name</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Uploads</th>
                                            <th>Joined Date</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $u): ?>
                                            <tr class="user-row">
                                                <td class="text-secondary small">#<?= $u['id'] ?></td>
                                                <td><strong><?= htmlspecialchars($u['display_name'] ?? '—') ?></strong></td>
                                                <td><span class="text-info">@<?= htmlspecialchars($u['username']) ?></span></td>
                                                <td><span class="text-secondary small"><?= htmlspecialchars($u['email'] ?? 'No recovery email set') ?></span></td>
                                                <td>
                                                    <?php if ($u['is_admin'] == 1): ?>
                                                        <span class="badge bg-danger text-white text-uppercase admin-badge" style="font-size: 0.65rem;">
                                                            <i class="fa-solid fa-shield-halved me-1"></i>Admin
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary text-white text-uppercase admin-badge" style="font-size: 0.65rem;">
                                                            User
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="badge bg-primary text-white"><?= $u['upload_count'] ?> files</span></td>
                                                <td class="text-secondary small"><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                                                <td class="text-end">
                                                    <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                                        <div class="d-inline-flex gap-2">
                                                            <button class="btn btn-outline-warning btn-sm" onclick="toggleAdmin(<?= $u['id'] ?>, this)">
                                                                <i class="fa-solid fa-user-gear me-1"></i>Toggle Role
                                                            </button>
                                                            <button class="btn btn-outline-danger btn-sm" onclick="deleteUser(<?= $u['id'] ?>, this.closest('tr'))">
                                                                <i class="fa-solid fa-user-xmark"></i>
                                                            </button>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-secondary small italic">Logged In (Self)</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TAB 3: UPLOADED MEDIA -->
                        <div class="tab-pane fade" id="tab-uploads" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                <h4 class="text-gradient-green mb-0">Uploads & Statuses</h4>
                                <input type="text" id="searchUploads" class="form-control form-control-cyber form-control-sm" style="width: 220px;" placeholder="Search media...">
                            </div>

                            <div class="table-responsive">
                                <table class="table table-cyber table-hover align-middle mb-0" id="tableUploads">
                                    <thead>
                                        <tr>
                                            <th>Media Filename</th>
                                            <th>Creator</th>
                                            <th>Title & Details</th>
                                            <th>Destinations</th>
                                            <th>Status</th>
                                            <th>Timestamp</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($uploads)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-secondary py-5">
                                                    <i class="fa-solid fa-clapperboard d-block mb-3" style="font-size: 2.5rem;"></i>
                                                    No media file uploads currently present on the system.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($uploads as $up): ?>
                                                <tr class="upload-row">
                                                    <td style="max-width: 180px;">
                                                        <div class="text-dark text-truncate fw-bold" title="<?= htmlspecialchars($up['filename']) ?>">
                                                            <i class="fa-regular fa-file-video text-info me-2"></i><?= htmlspecialchars($up['filename']) ?>
                                                        </div>
                                                        <span class="text-secondary small d-block" style="font-size: 0.75rem; word-break: break-all;"><?= htmlspecialchars($up['file_path']) ?></span>
                                                    </td>
                                                    <td><span class="text-info">@<?= htmlspecialchars($up['creator_username']) ?></span></td>
                                                    <td>
                                                        <strong class="text-dark d-block" style="font-size: 0.85rem;"><?= htmlspecialchars($up['title'] ?? 'Untitled') ?></strong>
                                                        <small class="text-secondary text-truncate d-block" style="max-width: 200px;"><?= htmlspecialchars($up['description'] ?? 'No description') ?></small>
                                                    </td>
                                                    <td>
                                                        <?php
                                                            $platData = [];
                                                            if (!empty($up['platforms'])) {
                                                                $decoded = json_decode((string)$up['platforms'], true);
                                                                if (is_array($decoded)) $platData = $decoded;
                                                            }
                                                        ?>
                                                        <div class="d-flex gap-1 flex-wrap">
                                                            <?php foreach ($platData as $plat): ?>
                                                                <span class="badge bg-light border border-secondary text-secondary text-capitalize" style="font-size: 0.65rem; color: var(--text-secondary) !important;">
                                                                    <?= htmlspecialchars($plat) ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php
                                                            $st = strtolower($up['status'] ?? 'pending');
                                                            $statusBorder = 'border-warning text-warning';
                                                            if ($st === 'live') $statusBorder = 'border-success text-success';
                                                            if ($st === 'failed') $statusBorder = 'border-danger text-danger';
                                                            if ($st === 'processing' || $st === 'uploading') $statusBorder = 'border-info text-info';
                                                        ?>
                                                        <span class="badge bg-light border <?= $statusBorder ?> text-uppercase" style="font-size: 0.65rem;">
                                                            <?= htmlspecialchars($st) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-secondary small" style="white-space: nowrap;"><?= date('Y-m-d H:i', strtotime($up['created_at'])) ?></td>
                                                    <td class="text-end">
                                                        <div class="d-inline-flex gap-2">
                                                            <?php if (!empty($up['file_path']) && is_file(__DIR__ . '/' . $up['file_path'])): ?>
                                                                <a href="<?= htmlspecialchars($up['file_path']) ?>" download class="btn btn-outline-primary btn-xs px-2" title="Download File">
                                                                    <i class="fa-solid fa-download"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <button class="btn btn-outline-danger btn-xs px-2" title="Delete Submission" onclick="deleteUpload(<?= $up['id'] ?>, this.closest('tr'))">
                                                                <i class="fa-solid fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>

                </div>
            </div>
        </div>

    </div>
</main>

<!-- Detailed Message Reader Modal -->
<?php ob_start(); ?>
<div class="modal fade" id="messageDetailsModal" tabindex="-1" aria-hidden="true" style="background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(8px);">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-card card-magenta" style="background: #ffffff; border: 1px solid var(--card-border) !important;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-gradient-magenta fw-bold"><i class="fa-solid fa-envelope-open me-2"></i>Inquiry Transmission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="mb-3">
                    <label class="text-secondary small text-uppercase fw-bold" style="letter-spacing: 1px;">Sender Details</label>
                    <div class="text-dark fw-bold" id="modalSender">John Doe</div>
                    <div class="text-secondary small" id="modalEmail">john@example.com</div>
                </div>
                <div class="mb-3">
                    <label class="text-secondary small text-uppercase fw-bold" style="letter-spacing: 1px;">Topic Category</label>
                    <div><span class="badge bg-primary text-white text-capitalize" id="modalSubject">Connection Error</span></div>
                </div>
                <div class="mb-3">
                    <label class="text-secondary small text-uppercase fw-bold" style="letter-spacing: 1px;">Timestamp</label>
                    <div class="text-secondary small" id="modalTime">2026-06-11 10:00</div>
                </div>
                <div class="mb-0">
                    <label class="text-secondary small text-uppercase fw-bold" style="letter-spacing: 1px;">Message Body</label>
                    <div class="p-3 text-dark small" style="background: #f1f5f9; border-radius: 8px; border: 1px solid #e2e8f0; max-height: 250px; overflow-y: auto; white-space: pre-wrap;" id="modalMessage">
                        Detailed message...
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <a href="" id="modalReplyBtn" class="btn btn-primary btn-sm text-white fw-bold px-4 py-2 me-auto">
                    <i class="fa-solid fa-reply me-1"></i>Reply via Mail
                </a>
                <button type="button" class="btn btn-outline-secondary btn-sm px-4 py-2" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php
$extraFooter = ($extraFooter ?? '') . ob_get_clean();
?>

<style>
/* Custom Admin Styling */
.custom-admin-pills .nav-link {
    background: #f1f5f9;
    color: var(--text-secondary);
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    margin-right: 10px;
    padding: 0.7rem 1.4rem;
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
}
.custom-admin-pills .nav-link:hover {
    color: var(--text-primary);
    background: #e2e8f0;
    border-color: #cbd5e1;
}
.custom-admin-pills .nav-link.active {
    background: var(--primary-bg) !important;
    color: var(--primary-text) !important;
    border-color: var(--primary-bg) !important;
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.25) !important;
}

/* Glass tables styling */
.table-cyber {
    color: var(--text-primary) !important;
    border-collapse: separate;
    border-spacing: 0 8px;
}
.table-cyber th {
    background: transparent !important;
    color: var(--text-secondary);
    text-transform: uppercase;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 1px;
    border-bottom: 1px solid #e2e8f0;
    padding: 12px 16px;
}
.table-cyber td {
    background: #ffffff !important;
    border-top: 1px solid #e2e8f0 !important;
    border-bottom: 1px solid #e2e8f0 !important;
    padding: 14px 16px;
}
.table-cyber td:first-child {
    border-left: 1px solid #e2e8f0 !important;
    border-top-left-radius: 8px;
    border-bottom-left-radius: 8px;
}
.table-cyber td:last-child {
    border-right: 1px solid #e2e8f0 !important;
    border-top-right-radius: 8px;
    border-bottom-right-radius: 8px;
}
.table-cyber tbody tr {
    transition: all 0.2s ease;
}
.table-cyber tbody tr:hover td {
    background: #f8fafc !important;
    border-color: #cbd5e1 !important;
}

/* Extra small buttons */
.btn-xs {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    border-radius: 4px;
}

/* Stats icons */
.stat-icon-wrap {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}
.bg-cyan-glow {
    background: rgba(0, 243, 255, 0.1);
    color: var(--neon-cyan);
    border: 1px solid rgba(0, 243, 255, 0.2);
    box-shadow: 0 0 10px rgba(0, 243, 255, 0.15);
}
.bg-magenta-glow {
    background: rgba(255, 0, 255, 0.1);
    color: var(--neon-magenta);
    border: 1px solid rgba(255, 0, 255, 0.2);
    box-shadow: 0 0 10px rgba(255, 0, 255, 0.15);
}
.bg-orange-glow {
    background: rgba(255, 153, 0, 0.1);
    color: #ff9900;
    border: 1px solid rgba(255, 153, 0, 0.2);
    box-shadow: 0 0 10px rgba(255, 153, 0, 0.15);
}
.bg-green-glow {
    background: rgba(0, 255, 102, 0.1);
    color: var(--neon-green);
    border: 1px solid rgba(0, 255, 102, 0.2);
    box-shadow: 0 0 10px rgba(0, 255, 102, 0.15);
}

/* Animations */
.gsap-fade-in-up { opacity: 0; transform: translateY(30px); }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script>
const csrfToken = "<?= generate_csrf_token() ?>";

document.addEventListener('DOMContentLoaded', () => {
    // GSAP load animations
    gsap.to('.gsap-fade-in', { opacity: 1, y: 0, duration: 0.6, ease: 'power2.out', stagger: 0.12 });
    gsap.to('.gsap-fade-in-up', { opacity: 1, y: 0, duration: 0.8, ease: 'power2.out', delay: 0.15 });

    // Live search - Inquiries
    const searchInquiries = document.getElementById('searchInquiries');
    searchInquiries.addEventListener('keyup', () => {
        const query = searchInquiries.value.toLowerCase();
        const rows = document.querySelectorAll('.inquiry-row');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });

    // Filter - Inquiries
    const filterInquiries = document.getElementById('filterInquiries');
    filterInquiries.addEventListener('change', () => {
        const filter = filterInquiries.value;
        const rows = document.querySelectorAll('.inquiry-row');
        rows.forEach(row => {
            const status = row.getAttribute('data-status');
            if (filter === 'all' || status === filter) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });

    // Live search - Users
    const searchUsers = document.getElementById('searchUsers');
    searchUsers.addEventListener('keyup', () => {
        const query = searchUsers.value.toLowerCase();
        const rows = document.querySelectorAll('.user-row');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });

    // Live search - Uploads
    const searchUploads = document.getElementById('searchUploads');
    searchUploads.addEventListener('keyup', () => {
        const query = searchUploads.value.toLowerCase();
        const rows = document.querySelectorAll('.upload-row');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });
});

const alertEl = document.getElementById('adminAlert');

function showNotice(msg, isError = false) {
    alertEl.innerHTML = `
        <div class="${isError ? 'cyber-alert bg-danger-glow' : 'cyber-alert bg-info-glow'}" style="padding: 1rem; border-radius: 8px; border: 1px solid ${isError ? '#ff4444' : '#00f3ff'}">
            <i class="fa-solid ${isError ? 'fa-triangle-exclamation' : 'fa-circle-info'} me-2"></i>
            <strong>${isError ? 'SYSTEM ERROR' : 'NOTICE'}:</strong> ${msg}
        </div>
    `;
    alertEl.classList.remove('d-none');
    gsap.fromTo(alertEl, { y: -10, opacity: 0 }, { y: 0, opacity: 1, duration: 0.3 });
    setTimeout(() => {
        gsap.to(alertEl, { opacity: 0, duration: 0.3, onComplete: () => alertEl.classList.add('d-none') });
    }, 4000);
}

// Modal Handler
function showMsgModal(msg) {
    document.getElementById('modalSender').textContent = msg.name;
    document.getElementById('modalEmail').textContent = msg.email;
    document.getElementById('modalSubject').textContent = msg.subject;
    document.getElementById('modalTime').textContent = msg.created_at;
    document.getElementById('modalMessage').textContent = msg.message;
    document.getElementById('modalReplyBtn').href = `mailto:${msg.email}?subject=RE: MediaFusion Inquiry - ${encodeURIComponent(msg.subject)}`;
    
    const modal = new bootstrap.Modal(document.getElementById('messageDetailsModal'));
    modal.show();
}

// Update Inquiry Status
function updateMessageStatus(id, newStatus) {
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'mark_message_status');
    formData.append('id', id);
    formData.append('status', newStatus);

    fetch('backend/admin_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotice(data.message);
            // Reload window after short pause to update statuses
            setTimeout(() => window.location.reload(), 800);
        } else {
            showNotice(data.message, true);
        }
    })
    .catch(() => showNotice('Network connectivity lost.', true));
}

// Delete Inquiry Message
function deleteMessage(id, rowEl) {
    if (!confirm('Are you absolutely sure you want to delete this message?')) return;
    
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'delete_message');
    formData.append('id', id);

    fetch('backend/admin_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotice(data.message);
            gsap.to(rowEl, { opacity: 0, x: -30, duration: 0.4, onComplete: () => {
                rowEl.remove();
                // Update stats count dynamically
                const el = document.getElementById('stat-inquiries');
                if (el) el.textContent = parseInt(el.textContent) - 1;
            }});
        } else {
            showNotice(data.message, true);
        }
    })
    .catch(() => showNotice('Network connectivity lost.', true));
}

// Toggle Admin Status
function toggleAdmin(userId, btnEl) {
    if (!confirm('Modify administrative privileges for this account?')) return;
    
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'toggle_user_admin');
    formData.append('id', userId);

    fetch('backend/admin_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotice(data.message);
            const row = btnEl.closest('tr');
            const badge = row.querySelector('.admin-badge');
            if (data.is_admin == 1) {
                badge.className = 'badge bg-dark border border-danger text-danger text-uppercase admin-badge';
                badge.innerHTML = '<i class="fa-solid fa-shield-halved me-1"></i>Admin';
            } else {
                badge.className = 'badge bg-dark border border-secondary text-secondary text-uppercase admin-badge';
                badge.innerHTML = 'User';
            }
        } else {
            showNotice(data.message, true);
        }
    })
    .catch(() => showNotice('Network connectivity lost.', true));
}

// Delete User
function deleteUser(userId, rowEl) {
    if (!confirm('WARNING: Deleting this user will delete all associated login details, uploads, and profiles. Proceed?')) return;
    
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'delete_user');
    formData.append('id', userId);

    fetch('backend/admin_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotice(data.message);
            gsap.to(rowEl, { opacity: 0, x: -30, duration: 0.4, onComplete: () => {
                rowEl.remove();
                // Update stats count dynamically
                const el = document.getElementById('stat-users');
                if (el) el.textContent = parseInt(el.textContent) - 1;
            }});
        } else {
            showNotice(data.message, true);
        }
    })
    .catch(() => showNotice('Network connectivity lost.', true));
}

// Delete Upload Media
function deleteUpload(uploadId, rowEl) {
    if (!confirm('Are you sure you want to delete this file?')) return;
    
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'delete_upload');
    formData.append('id', uploadId);

    fetch('backend/admin_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotice(data.message);
            gsap.to(rowEl, { opacity: 0, x: -30, duration: 0.4, onComplete: () => {
                rowEl.remove();
                // Update stats count dynamically
                const el = document.getElementById('stat-uploads');
                if (el) el.textContent = parseInt(el.textContent) - 1;
            }});
        } else {
            showNotice(data.message, true);
        }
    })
    .catch(() => showNotice('Network connectivity lost.', true));
}
</script>

<?php
include_once 'includes/footer.php';
?>
