<?php
/**
 * MediaFusion - Operator Profile Management
 * 
 * CORE SECURITY & IDENTITY FEATURES:
 * 1. Self-Healing Schema Check: Assures email and avatar_path exist natively on the user structure.
 * 2. Visual Neon Avatar Border: Renders the active custom avatar, falling back to an astronaut vector.
 * 3. Local Multipart Upload: Handles PNG, JPG, and JPEG files safely with size boundaries.
 * 4. Social Avatar Synchronizer: Uses high-speed cURL handshake to mirror platform pictures.
 * 5. Secure Credentials Updates: Safely hashes modified credentials inside parameterized queries.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) {
    // Session is active
} else {
    session_start();
}

$userId = $_SESSION['user_id'] ?? null;

if ($userId === null) {
    header('Location: login.php');
    exit;
}

try {
    require_once 'backend/db.php';
    
    // Proactively align database schema triggers to ensure all columns exist
    $columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('avatar_path', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) DEFAULT NULL");
    }
    if (!in_array('profile_pic', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) DEFAULT NULL");
    }
    if (!in_array('email', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100) DEFAULT NULL UNIQUE");
    }
} catch (Exception $e) {
    die("Database synchronization failed: " . htmlspecialchars($e->getMessage()));
}

$success = '';
$error   = '';

// ----------------------------------------------------
// 1. Process Actions POST Handlers
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

    // Action A: Update display name, email, and password credentials
    if ($action === 'update_profile') {
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $curPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';

        if ($displayName === '') {
            $error = "Display name cannot be empty.";
        } else {
            try {
                // Fetch existing user to verify password changes if requested
                $stmt = $pdo->prepare("SELECT password_hash, email FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $userDb = $stmt->fetch(PDO::FETCH_ASSOC);

                $updatePass = false;
                $newHash = '';

                // Password change validation logic
                if ($newPass !== '') {
                    if (strlen($newPass) < 6) {
                        $error = "New password must be at least 6 characters.";
                    } elseif (!password_verify($curPass, $userDb['password_hash'] ?? '')) {
                        $error = "Current password verification failed.";
                    } else {
                        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                        $updatePass = true;
                    }
                }

                if ($error === '') {
                    if ($updatePass) {
                        $update = $pdo->prepare("UPDATE users SET display_name = ?, email = ?, password_hash = ? WHERE id = ?");
                        $update->execute([$displayName, $email, $newHash, $userId]);
                    } else {
                        $update = $pdo->prepare("UPDATE users SET display_name = ?, email = ? WHERE id = ?");
                        $update->execute([$displayName, $email, $userId]);
                    }
                    $success = "Operator profile updated successfully.";
                }
            } catch (Exception $e) {
                $error = "Failed to update profile: " . $e->getMessage();
            }
        }
    }
}

// ----------------------------------------------------
// 2. Fetch User Context for Display
// ----------------------------------------------------
$stmt = $pdo->prepare("SELECT id, username, display_name, created_at, avatar_path, profile_pic, email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$displayName = htmlspecialchars($user['display_name'] ?? $user['username'] ?? 'Operator');
$username    = htmlspecialchars($user['username'] ?? '—');
$emailVal    = htmlspecialchars($user['email'] ?? '');
$joinedDate  = isset($user['created_at']) ? date('M j, Y', strtotime($user['created_at'])) : '—';
$profilePic  = $user['profile_pic'] ?? $user['avatar_path'] ?? '';
$hasCustomAvatar = ($profilePic !== '' && is_file(__DIR__ . '/' . $profilePic));

$pageTitle  = 'Profile - MediaFusion';
$activePage = 'profile';
include 'header.php';
?>

<main style="padding-top:100px;min-height:100vh;">
<div class="container py-5" style="max-width:800px;">

    <div class="text-center mb-5">
        <h1 class="display-6 text-gradient-cyan">Operator Profile</h1>
        <p class="text-secondary">Manage your local avatar credentials and connected account details.</p>
    </div>

    <!-- Alert triggers -->
    <?php if ($success !== ''): ?>
        <div class="alert alert-success border-0 mb-4" style="background: rgba(0, 255, 102, 0.08); border-left: 3px solid var(--neon-green) !important; color: #80ffaa;">
            <i class="fa-solid fa-circle-check me-2"></i><?= $success ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger border-0 mb-4" style="background: rgba(255, 0, 0, 0.08); border-left: 3px solid #ff4444 !important; color: #ff8080;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error ?>
        </div>
    <?php endif; ?>

    <!-- Visual Identity Card -->
    <div class="glass-card mb-4 text-center">
        <!-- Neon border avatar frame (Interactive management module) -->
        <div class="avatar-frame mb-3 mx-auto" id="avatarFrameContainer" style="width: 120px; height: 120px; border-radius: 50%; overflow: hidden; border: 3px solid var(--neon-cyan); box-shadow: 0 0 20px rgba(0, 243, 255, 0.25); display: flex; align-items: center; justify-content: center; background: rgba(0, 0, 0, 0.45); transition: all 0.3s ease; position: relative; cursor: pointer;">
            <div id="avatarImageWrapper" style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;">
                <?php if ($hasCustomAvatar): ?>
                    <img id="avatarImageElement" src="<?= htmlspecialchars($profilePic) ?>" alt="Operator Avatar" style="width: 100%; height: 100%; object-fit: cover;">
                <?php else: ?>
                    <i id="avatarPlaceholderIcon" class="fa-solid fa-user-astronaut" style="font-size: 3rem; color: var(--neon-cyan); filter: drop-shadow(0 0 5px rgba(0,243,255,0.4));"></i>
                <?php endif; ?>
            </div>
            
            <!-- Hover overlay -->
            <div class="avatar-overlay" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: flex; flex-direction: column; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s ease; pointer-events: none;">
                <i class="fa-solid fa-camera" style="color: var(--neon-cyan); font-size: 1.5rem; text-shadow: 0 0 8px var(--neon-cyan);"></i>
                <span style="color: #fff; font-size: 0.65rem; text-transform: uppercase; margin-top: 4px; font-weight: 600; letter-spacing: 0.5px;">Update Photo</span>
            </div>
            
            <!-- Hidden file input overlaying user profile circle -->
            <input type="file" id="profilePicInput" accept="image/png, image/jpeg, image/jpg" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 10;">
        </div>
        
        <div class="text-center mb-4">
            <h2 class="text-white profile-display-name mb-1" style="font-size:1.4rem;"><?= htmlspecialchars($displayName) ?></h2>
            <p class="text-secondary mb-0" style="font-size:.85rem;">@<?= htmlspecialchars($username) ?></p>
        </div>
        
        <div class="row g-3">
            <div class="col-6"><div class="stat-badge"><span class="stat-val"><?= htmlspecialchars($username) ?></span><span class="stat-key">Username</span></div></div>
            <div class="col-6"><div class="stat-badge"><span class="stat-val"><?= htmlspecialchars($joinedDate) ?></span><span class="stat-key">Member Since</span></div></div>
        </div>
    </div>

    <!-- 4. PROFILE IMAGE MANAGER & SOCIAL SYNC MODULES (Task 4) -->
    <div class="glass-card mb-4">
        <h4 class="text-white text-gradient-magenta mb-4"><i class="fa-solid fa-image me-2"></i>Avatar Management & Social Sync</h4>
        
        <div class="row g-4">
            <!-- Local file upload form -->
            <div class="col-md-6 border-end border-secondary pe-md-4">
                <h5 class="text-white mb-2" style="font-size: 0.9rem;"><i class="fa-solid fa-upload me-2 text-info"></i>Local Upload</h5>
                <p class="text-secondary small mb-3">Upload a clean PNG, JPG, or JPEG file from your device.</p>
                <form action="sync_social_profile.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_local">
                    <div class="mb-3">
                        <input type="file" name="avatar_file" class="form-control form-control-cyber" accept="image/png, image/jpeg, image/jpg" required>
                    </div>
                    <button type="submit" class="btn btn-info btn-sm w-100 fw-bold text-uppercase py-2" style="border-radius: 4px;">Upload File</button>
                </form>
            </div>

            <!-- Platform synchronizer cURL download form -->
            <div class="col-md-6 ps-md-4">
                <h5 class="text-white mb-2" style="font-size: 0.9rem;"><i class="fa-solid fa-rotate me-2 text-info"></i>Social Profile Copy</h5>
                <p class="text-secondary small mb-3">Copy your platform profile avatar instantly to MediaFusion via cURL.</p>
                <form action="sync_social_profile.php" method="POST">
                    <input type="hidden" name="action" value="sync_social">
                    <div class="mb-3">
                        <input type="url" name="social_avatar_url" class="form-control form-control-cyber py-2" style="font-size: 0.8rem;" placeholder="Paste platform image URL..." required>
                    </div>
                    <button type="submit" class="btn btn-outline-info btn-sm w-100 fw-bold text-uppercase py-2" style="border-radius: 4px;">Mirror Social Picture</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Form -->
    <div class="glass-card">
        <form action="profile.php" method="POST" id="profileForm" autocomplete="off">
            <input type="hidden" name="action" value="update_profile">

            <!-- Display Name -->
            <p class="section-label"><i class="fa-solid fa-id-card me-2"></i>Display Name</p>
            <div class="mb-4">
                <div class="input-group">
                    <span class="input-group-text ig-icon"><i class="fa-solid fa-signature"></i></span>
                    <input type="text" name="display_name" class="form-control form-control-cyber"
                           placeholder="<?= htmlspecialchars($displayName) ?>"
                           value="<?= htmlspecialchars($displayName) ?>" maxlength="60" required>
                </div>
                <small class="text-secondary" style="font-size:.72rem;">This name is shown across Mission Control.</small>
            </div>

            <!-- Recovery Email -->
            <p class="section-label"><i class="fa-solid fa-envelope me-2"></i>Recovery Email</p>
            <div class="mb-4">
                <div class="input-group">
                    <span class="input-group-text ig-icon"><i class="fa-solid fa-envelope"></i></span>
                    <input type="email" name="email" class="form-control form-control-cyber"
                           placeholder="johnkennedy@gmail.com"
                           value="<?= $emailVal ?>" maxlength="100" required>
                </div>
                <small class="text-secondary" style="font-size:.72rem;">Email required to verify security password reset queries.</small>
            </div>

            <!-- Change Password -->
            <p class="section-label"><i class="fa-solid fa-lock me-2"></i>Change Password <span style="font-size:.7rem;opacity:.5;">(leave blank to keep current)</span></p>
            <div class="mb-3">
                <label class="form-label text-secondary text-uppercase" style="font-size:.72rem;letter-spacing:1px;">Current Password</label>
                <div class="input-group">
                    <span class="input-group-text ig-icon"><i class="fa-solid fa-unlock-keyhole"></i></span>
                    <input type="password" name="current_password" id="cur-pass" class="form-control form-control-cyber" placeholder="Required if changing password" autocomplete="current-password">
                    <button type="button" class="input-group-text" id="toggleCur" style="background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.1);border-left:none;color:var(--text-secondary);cursor:pointer;"><i class="fa-solid fa-eye" id="eyeCur"></i></button>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label text-secondary text-uppercase" style="font-size:.72rem;letter-spacing:1px;">New Password</label>
                <div class="input-group">
                    <span class="input-group-text ig-icon"><i class="fa-solid fa-key"></i></span>
                    <input type="password" name="new_password" id="new-pass" class="form-control form-control-cyber" placeholder="Min 6 characters" autocomplete="new-password">
                    <button type="button" class="input-group-text" id="toggleNew" style="background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.1);border-left:none;color:var(--text-secondary);cursor:pointer;"><i class="fa-solid fa-eye" id="eyeNew"></i></button>
                </div>
            </div>

            <button type="submit" class="btn-magnetic w-100" id="saveBtn" style="font-size:1rem;padding:.9rem;">
                Save Changes <i class="fa-solid fa-floppy-disk ms-2"></i>
            </button>
        </form>
    </div>

    <!-- Danger Zone -->
    <div class="glass-card mt-4" style="border-color:rgba(255,68,68,.2);">
        <p class="section-label" style="color:#ff6666;"><i class="fa-solid fa-radiation me-2"></i>Session Control</p>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <p class="text-white mb-1" style="font-size:.9rem;">Terminate Current Session</p>
                <p class="text-secondary mb-0" style="font-size:.8rem;">You will be redirected to the login portal.</p>
            </div>
            <a href="logout.php" class="btn-magnetic" style="border-color:#ff4444;color:#ff8080;box-shadow:0 0 10px rgba(255,68,68,.15);font-size:.85rem;padding:.6rem 1.4rem;">
                Logout <i class="fa-solid fa-arrow-right-from-bracket ms-2"></i>
            </a>
        </div>
    </div>

</div>
</main>

<script src="assets/js/profile.js"></script>

<?php include_once 'includes/footer.php'; ?>
