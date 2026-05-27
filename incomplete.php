<?php
require_once 'config.php';
require_once 'backend/db.php';

$pageTitle  = 'Incomplete Uploads';
$activePage = 'incomplete';
$extraHead  = '<script src="https://cdnjs.cloudflare.com/ajax/libs/resumable.js/1.1.0/resumable.min.js"></script>';
include 'header.php';

$userId = $_SESSION['user_id'] ?? 1;

$stmt = $pdo->prepare("SELECT * FROM incomplete_uploads WHERE user_id = ? AND status != 'completed' ORDER BY updated_at DESC");
$stmt->execute([$userId]);
$incompleteUploads = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<main style="padding-top: 100px; min-height: 100vh;">
    <div class="container py-5">
        <h2 class="text-gradient-cyan mb-4">Incomplete Uploads Library</h2>
        <p class="text-secondary mb-5">Select a file below to resume its upload. You will need to select the file from your computer again to continue.</p>
        
        <?php if (empty($incompleteUploads)): ?>
            <div class="glass-card text-center p-5">
                <i class="fa-solid fa-check-circle fa-4x mb-3" style="color: var(--neon-green);"></i>
                <h4>All clear!</h4>
                <p class="text-secondary">You have no incomplete uploads.</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($incompleteUploads as $upload): ?>
                    <div class="col-md-6">
                        <div class="glass-card">
                            <h5><?= htmlspecialchars($upload['file_name']) ?></h5>
                            <p class="text-secondary mb-2">
                                Progress: <?= $upload['last_uploaded_chunk'] ?> / <?= $upload['total_chunks'] ?> chunks
                                (<?= round(($upload['last_uploaded_chunk'] / $upload['total_chunks']) * 100) ?>%)
                            </p>
                            <p class="text-secondary mb-3" style="font-size: 0.8rem;">Last updated: <?= $upload['updated_at'] ?></p>
                            
                            <div class="progress-cyber mb-4" style="height: 10px;">
                                <div class="progress-bar-cyber" id="progress-bar-<?= $upload['id'] ?>" style="width: <?= ($upload['last_uploaded_chunk'] / $upload['total_chunks']) * 100 ?>%"></div>
                            </div>
                            
                            <input type="file" id="file-input-<?= $upload['id'] ?>" style="display: none;" accept="video/*">
                            <div class="row g-2">
                                <div class="col-8">
                                    <button class="btn btn-outline-info resume-btn w-100" 
                                            data-id="<?= $upload['id'] ?>"
                                            data-filename="<?= htmlspecialchars($upload['file_name']) ?>"
                                            data-size="<?= htmlspecialchars($upload['file_size']) ?>"
                                            onclick="document.getElementById('file-input-<?= $upload['id'] ?>').click();">
                                        <i class="fa-solid fa-play"></i> Resume
                                    </button>
                                </div>
                                <div class="col-4">
                                    <button class="btn btn-outline-danger discard-btn w-100" 
                                            data-id="<?= $upload['id'] ?>">
                                        <i class="fa-solid fa-trash"></i> Discard
                                    </button>
                                </div>
                            </div>

                            <div id="status-<?= $upload['id'] ?>" class="mt-3 text-center" style="font-size: 0.9rem;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php
$extraScripts = '<script src="assets/js/resume_uploader.js"></script>';
include 'footer.php';
?>
