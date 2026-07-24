<?php
/**
 * MediaFusion - Standalone Local Profile Asset Copier & Social Sync Engine
 * 
 * CORE FEATURES:
 * 1. Self-Healing Schema: Automatically alters 'users' table to append 'avatar_path' if missing.
 * 2. Secure Local Upload: Handles operator uploads, verifies extensions (png, jpg, jpeg) & MIME headers.
 * 3. High-Speed cURL Social Sync: Resolves external social avatar links (OAuth basic profiles),
 *    downloads raw binary chunks, validates integrity, and commits straight onto our server storage.
 * 4. DB Sync Operations: Performs parameterized transactional SQL updates for current operator.
 * 5. Premium Workspace (GET): Interactive control center displaying operator avatar and sync buttons.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) {
    // Session is active
} else {
    session_start();
}

$userId = $_SESSION['user_id'] ?? null;

// Require authentication for security
if ($userId === null) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please sign in to access this page.']);
        exit;
    }
    header('Location: login.php');
    exit;
}

// ----------------------------------------------------
// 1. Database & Directory Self-Healing Configuration
// ----------------------------------------------------
try {
    require_once __DIR__ . '/backend/db.php';
    
    // Dynamically verify if 'avatar_path' and 'profile_pic' exist in 'users' table, otherwise alter.
    // Self-healing database structure
    $columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('avatar_path', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) DEFAULT NULL");
    }
    if (!in_array('profile_pic', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) DEFAULT NULL");
    }
} catch (Exception $e) {
    die("System error: " . htmlspecialchars($e->getMessage()));
}

// Setup local avatar store path
$avatarsFolderRelative = 'uploads/avatars';
$avatarsDir = __DIR__ . '/' . $avatarsFolderRelative;
if (!is_dir($avatarsDir)) {
    mkdir($avatarsDir, 0755, true);
}

$successMsg = '';
$errorMsg   = '';

// ----------------------------------------------------
// 2. Controller POST Actions (Local Upload & Social Sync)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    
    // A. Local multipart/form-data upload
    if ($action === 'upload_local' || $action === 'local_upload') {
        if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar_file'];
            $fileSize = $file['size'];
            $tempPath = $file['tmp_name'];
            $origName = basename($file['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            
            // Validation 1: Strict extension checking
            if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
                $errorMsg = "Invalid file type. Supported formats: PNG, JPG, JPEG.";
            } 
            // Validation 2: Size constraints (Limit to 2MB)
            elseif ($fileSize > 2 * 1024 * 1024) {
                $errorMsg = "File size exceeds the 2MB limit.";
            } 
            // Validation 3: MIME type authenticity check
            else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tempPath);
                finfo_close($finfo);
                
                if (!in_array($mimeType, ['image/png', 'image/jpeg', 'image/jpg'], true)) {
                    $errorMsg = "Error: File type is not a valid image.";
                } else {
                    // Store avatar via storage abstraction
                    require_once __DIR__ . '/backend/storage/autoload.php';
                    require_once __DIR__ . '/backend/storage/config.php';
                    $storage = \MediaFusion\Storage\StorageManager::getInstance();
                    
                    $storageKey = $storage->generateKey((int)$userId, 'avatar', $ext, 'original');
                    $result = $storage->putFile($tempPath, $storageKey, $mimeType);
                    
                    if ($result !== null) {
                        $s3Url = $result['url'];
                        
                        // Delete previous avatar
                        $stmt = $pdo->prepare("SELECT avatar_path FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $prevPath = $stmt->fetchColumn();
                        if ($prevPath && !str_starts_with($prevPath, 'http') && is_file(__DIR__ . '/' . $prevPath) && strpos($prevPath, 'avatars/') !== false) {
                            @unlink(__DIR__ . '/' . $prevPath);
                        }
                        
                        $update = $pdo->prepare("UPDATE users SET avatar_path = ?, profile_pic = ? WHERE id = ?");
                        $update->execute([$s3Url, $s3Url, $userId]);
                        
                        $successMsg = "Your profile photo has been updated.";
                    } else {
                        $errorMsg = "Could not save the uploaded image.";
                    }
                }
            }
        } else {
            $errorMsg = "No file selected or an error occurred during upload.";
        }
    } 
    
    // B. Social Sync binary copier engine
    elseif ($action === 'sync_social') {
        $externalUrl = isset($_POST['social_avatar_url']) ? trim((string)$_POST['social_avatar_url']) : '';
        
        if ($externalUrl === '') {
            $errorMsg = "Please enter a valid social account profile picture URL.";
        } elseif (!filter_var($externalUrl, FILTER_VALIDATE_URL)) {
            $errorMsg = "Invalid URL format.";
        } else {
            // Establish a high-speed secure cURL download transaction
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $externalUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'MediaFusion - Sync Engine');
            
            $binaryData = curl_exec($ch);
            $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            
            if ($httpCode === 200 && $binaryData !== false) {
                // Determine extension based on content type header
                $ext = 'jpg';
                if ($contentType === 'image/png') {
                    $ext = 'png';
                } elseif ($contentType === 'image/gif') {
                    $ext = 'gif'; // support but standard check
                } elseif ($contentType === 'image/webp') {
                    $ext = 'webp';
                }
                
                // Keep strictly within png, jpg, jpeg constraints
                if (!in_array($ext, ['png', 'jpg', 'webp'], true)) {
                    $ext = 'jpg';
                }
                
                // Store via storage abstraction
                require_once __DIR__ . '/backend/storage/autoload.php';
                require_once __DIR__ . '/backend/storage/config.php';
                $storage = \MediaFusion\Storage\StorageManager::getInstance();
                
                $storageKey = $storage->generateKey((int)$userId, 'avatar', $ext, 'original');
                $result = $storage->putData($binaryData, $storageKey, $contentType ?: 'image/jpeg');
                
                if ($result !== null) {
                    $s3Url = $result['url'];
                    
                    // Delete previous avatar
                    $stmt = $pdo->prepare("SELECT avatar_path FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $prevPath = $stmt->fetchColumn();
                    if ($prevPath && !str_starts_with($prevPath, 'http') && is_file(__DIR__ . '/' . $prevPath) && strpos($prevPath, 'avatars/') !== false) {
                        @unlink(__DIR__ . '/' . $prevPath);
                    }
                    
                    $update = $pdo->prepare("UPDATE users SET avatar_path = ?, profile_pic = ? WHERE id = ?");
                    $update->execute([$s3Url, $s3Url, $userId]);
                    
                    $successMsg = "Your profile picture has been copied from your social account.";
                } else {
                    $errorMsg = "Could not save the image.";
                }
            } else {
                $errorMsg = "Could not connect or download profile image from server.";
            }
        }
    }

    // Respond with JSON if AJAX request
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        if ($errorMsg !== '') {
            echo json_encode(['success' => false, 'message' => $errorMsg]);
        } else {
            echo json_encode(['success' => true, 'message' => $successMsg]);
        }
        exit;
    }
}

// ----------------------------------------------------
// 3. User Avatar Render & GET Logic
// ----------------------------------------------------
// Fetch existing operator details
$stmt = $pdo->prepare("SELECT username, display_name, avatar_path, profile_pic FROM users WHERE id = ?");
$stmt->execute([$userId]);
$operator = $stmt->fetch(PDO::FETCH_ASSOC);

$displayName = htmlspecialchars($operator['display_name'] ?? $operator['username'] ?? 'User');
$username    = htmlspecialchars($operator['username'] ?? 'user');
$avatarPath  = $operator['profile_pic'] ?? $operator['avatar_path'] ?? '';
$hasCustomAvatar = ($avatarPath !== '' && (str_starts_with($avatarPath, 'http://') || str_starts_with($avatarPath, 'https://') || is_file(__DIR__ . '/' . $avatarPath)));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Social Sync & Avatar Manager — MediaFusion</title>
    
    <!-- CSS Library Imports -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <!-- Premium Cyberpunk Theme Custom Rules -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=Space+Grotesk:wght@400;700&display=swap');
        
        :root {
            --bg-color: #f8fafc;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --card-radius: 16px;
            --card-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
            
            --primary-bg: #4f46e5;
            --primary-hover: #4338ca;
            --primary-text: #ffffff;
            
            --neon-cyan: #06b6d4;
            --neon-magenta: #ec4899;
            --neon-green: #10b981;
            --danger-bg: #ef4444;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 1rem;
        }

        h1, h2, h3, h4 {
            font-family: 'Space Grotesk', sans-serif;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--card-radius);
            padding: 2.5rem;
            box-shadow: var(--card-shadow);
            width: 100%;
            max-width: 800px;
        }

        .text-gradient-cyan {
            background: linear-gradient(90deg, var(--neon-cyan), #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .text-gradient-magenta {
            background: linear-gradient(90deg, var(--neon-magenta), #d946ef);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .avatar-frame {
            width: 130px;
            height: 130px;
            border-radius: 50%;
            margin: 0 auto;
            position: relative;
            background: #f1f5f9;
            border: 3px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .avatar-frame:hover {
            border-color: var(--primary-bg);
            box-shadow: 0 4px 20px rgba(79, 70, 229, 0.2);
        }

        .avatar-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-placeholder-icon {
            font-size: 3.5rem;
            color: var(--primary-bg);
        }

        .cyber-input {
            background-color: #ffffff !important;
            border: 1px solid var(--card-border) !important;
            color: var(--text-primary) !important;
        }
        .cyber-input:focus {
            background-color: #ffffff !important;
            border-color: var(--primary-bg) !important;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15) !important;
            color: var(--text-primary) !important;
        }

        .sync-demo-pill {
            background: #f8fafc;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 0.8rem;
            cursor: pointer;
            transition: all 0.25s ease;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        .sync-demo-pill:hover {
            background: #f1f5f9;
            border-color: var(--primary-bg);
            color: var(--text-primary);
        }
    </style>
</head>
<body>

<div class="glass-card">
    <!-- Header segment -->
    <div class="d-flex align-items-center justify-content-between mb-5 border-bottom border-light pb-3">
        <div>
            <h2 class="text-gradient-cyan mb-1">Profile Picture</h2>
            <p class="text-secondary mb-0" style="font-size: 0.85rem;">
                Update your profile picture or copy it from your connected social account.
            </p>
        </div>
        <div>
            <a href="profile.php" class="btn btn-outline-dark btn-sm px-3" style="border-radius: 4px;">
                <i class="fa-solid fa-user me-2"></i>Profile Details
            </a>
        </div>
    </div>

    <!-- Feedback Alerts -->
    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success border-0 mb-4" style="background: rgba(16, 185, 129, 0.08); border-left: 3px solid var(--neon-green) !important; color: #065f46;">
            <i class="fa-solid fa-circle-check me-2"></i><?= $successMsg ?>
        </div>
    <?php endif; ?>
    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger border-0 mb-4" style="background: rgba(239, 68, 68, 0.08); border-left: 3px solid var(--danger-bg) !important; color: #991b1b;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $errorMsg ?>
        </div>
    <?php endif; ?>

    <div class="row g-4 align-items-center">
        <!-- Present Active Avatar and Identity details -->
        <div class="col-md-4 text-center border-end border-light pe-md-4">
            <div class="avatar-frame mb-3">
                <?php if ($hasCustomAvatar): ?>
                    <img src="<?= htmlspecialchars($avatarPath) ?>" alt="Profile Photo">
                <?php else: ?>
                    <i class="fa-solid fa-user-astronaut avatar-placeholder-icon"></i>
                <?php endif; ?>
            </div>
            <h4 class="mb-1"><?= $displayName ?></h4>
            <p class="text-secondary small">@<?= $username ?></p>
            <div class="badge bg-light border border-secondary text-secondary text-uppercase py-2 px-3" style="letter-spacing: 1px; font-size: 0.65rem; color: var(--text-secondary) !important;">
                <?= $hasCustomAvatar ? 'Custom Photo' : 'Default Icon' ?>
            </div>
        </div>

        <!-- Forms segments -->
        <div class="col-md-8 ps-md-4">
            <!-- Local upload framework -->
            <div class="mb-4 pb-4 border-bottom border-light">
                <h5 class="text-gradient-magenta mb-3"><i class="fa-solid fa-upload me-2"></i>Upload Profile Photo</h5>
                <form action="sync_social_profile.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_local">
                    <div class="input-group">
                        <input type="file" name="avatar_file" class="form-control cyber-input" accept="image/png, image/jpeg, image/jpg" required>
                        <button type="submit" class="btn btn-primary px-4 fw-bold text-uppercase" style="font-size: 0.8rem; letter-spacing: 0.5px; background: var(--primary-bg); border-color: var(--primary-bg); color: var(--primary-text);">Upload</button>
                    </div>
                    <div class="form-text text-secondary mt-2" style="font-size: 0.65rem;">
                        Allowed formats: PNG, JPG, JPEG (Max Size: 2MB).
                    </div>
                </form>
            </div>

            <!-- Social Authorization synchronization framework -->
            <div>
                <h5 class="text-gradient-cyan mb-3"><i class="fa-solid fa-rotate me-2"></i>Copy from Social Account</h5>
                <p class="text-secondary" style="font-size: 0.8rem;">
                    Import your profile picture from your online channel. Simply paste your profile image link below to save it as your profile photo.
                </p>
                <form action="sync_social_profile.php" method="POST" id="socialSyncForm">
                    <input type="hidden" name="action" value="sync_social">
                    <div class="mb-3">
                        <label class="form-label text-secondary small text-uppercase" style="letter-spacing: 1px; font-size: 0.7rem;">Profile Photo Link</label>
                        <input type="url" name="social_avatar_url" id="socialUrlField" class="form-control cyber-input" placeholder="https://lh3.googleusercontent.com/a/..." required>
                    </div>
                    <button type="submit" class="btn btn-outline-primary w-100 py-2 fw-bold text-uppercase" style="font-size: 0.8rem; letter-spacing: 0.5px; border-color: var(--primary-bg); color: var(--primary-bg);">
                        Copy Profile Picture <i class="fa-solid fa-link ms-1"></i>
                    </button>
                </form>

                <!-- Sandbox demo presets to quickly try it out -->
                <div class="mt-3">
                    <label class="form-label text-secondary small text-uppercase mb-2" style="font-size: 0.62rem; letter-spacing: 0.5px;">Try a Demo Picture</label>
                    <div class="d-flex flex-column gap-2">
                        <div class="sync-demo-pill" onclick="loadPreset('https://api.dicebear.com/7.x/bottts/png?seed=MEDIAFUSION_operator_one&backgroundColor=110e1f')">
                            <i class="fa-brands fa-google text-danger me-2"></i><strong>Use a sample Google photo</strong>
                        </div>
                        <div class="sync-demo-pill" onclick="loadPreset('https://api.dicebear.com/7.x/bottts/png?seed=cyber_operator_two&backgroundColor=110e1f')">
                            <i class="fa-brands fa-tiktok me-2"></i><strong>Use a sample TikTok photo</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    /**
     * Loads high-quality preset seeds directly into the Social Avatar URL field for immediate premium visual testing
     */
    function loadPreset(url) {
        document.getElementById('socialUrlField').value = url;
        // Subtle focus animation
        document.getElementById('socialUrlField').focus();
    }
</script>
</body>
</html>
