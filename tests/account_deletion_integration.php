<?php
declare(strict_types=1);

$socket = getenv('MEDIAFUSION_TEST_SOCKET') ?: '/tmp/mediafusion_mysql_test.sock';
$dbName = getenv('MEDIAFUSION_TEST_DB') ?: 'mediafusion';
$dsn = "mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4";

$pdo = new PDO($dsn, 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$projectRoot = dirname(__DIR__);
$uploadsRoot = '/tmp/mediafusion_account_deletion_uploads';
ensureDir($uploadsRoot);
putenv('MEDIA_STORAGE_ROOT=' . $uploadsRoot);
putenv('S3_ENABLED=false');

if (!function_exists('log_security_event')) {
    function log_security_event(string $event, string $detail = '', ?int $userId = null, ?string $ip = null): void
    {
        $line = json_encode(compact('event', 'detail', 'userId', 'ip')) . PHP_EOL;
        @file_put_contents('/tmp/mediafusion_account_deletion_security.log', $line, FILE_APPEND | LOCK_EX);
    }
}

require_once $projectRoot . '/backend/storage/autoload.php';
require_once $projectRoot . '/backend/storage/config.php';
require_once $projectRoot . '/backend/AccountDeletionService.php';

function ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scalar(PDO $pdo, string $sql, array $params = []): int|string|null
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create ' . $dir);
    }
}

function touchFile(string $file, string $contents = 'x'): string
{
    ensureDir(dirname($file));
    file_put_contents($file, $contents);
    return $file;
}

function resetFixture(PDO $pdo, string $prefix): void
{
    try {
        $pdo->prepare("DELETE FROM contact_inquiries WHERE subject LIKE ? OR email LIKE 'deleted-user-%@mediafusion.local'")->execute([$prefix . '%']);
    } catch (Throwable $e) {
    }

    $ids = $pdo->prepare("SELECT id FROM users WHERE username LIKE ?");
    $ids->execute([$prefix . '%']);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $uid = (int)$id;
        foreach (['studio_media', 'studio_projects', 'incomplete_uploads', 'uploads', 'oauth_tokens'] as $table) {
            try {
                $pdo->prepare("DELETE FROM `{$table}` WHERE user_id = ?")->execute([$uid]);
            } catch (Throwable $e) {
            }
        }
        try {
            $pdo->prepare('DELETE FROM contact_inquiries WHERE user_id = ?')->execute([$uid]);
        } catch (Throwable $e) {
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    }
}

function ensureSchema(PDO $pdo): void
{
    $columns = $pdo->query('DESCRIBE users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('security_version', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN security_version INT NOT NULL DEFAULT 0');
    }
    if (!in_array('google_id', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN google_id VARCHAR(32) DEFAULT NULL UNIQUE');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS studio_media (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            storage_path VARCHAR(512) DEFAULT NULL,
            storage_key VARCHAR(512) DEFAULT NULL,
            public_url VARCHAR(512) DEFAULT NULL,
            thumbnail_url VARCHAR(512) DEFAULT NULL,
            thumbnail_key VARCHAR(512) DEFAULT NULL,
            status VARCHAR(32) DEFAULT 'ready',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_studio_media_user_id (user_id),
            CONSTRAINT fk_test_studio_media_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function createUser(PDO $pdo, string $username, string $password, string $email, ?string $googleId = null): int
{
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, google_id, security_version) VALUES (?, ?, ?, ?, 0)');
    $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $googleId]);
    return (int)$pdo->lastInsertId();
}

ensureSchema($pdo);
resetFixture($pdo, 'delete_test_');

$targetId = createUser($pdo, 'delete_test_target', 'correct-password', 'delete-target@example.test');
$otherId = createUser($pdo, 'delete_test_other', 'other-password', 'delete-other@example.test');
$contactSubject = 'delete_test_help_' . $targetId;

$targetAvatar = $uploadsRoot . '/avatars/delete_test_avatar_' . $targetId . '.jpg';
$otherAvatar = $uploadsRoot . '/avatars/delete_test_avatar_' . $otherId . '.jpg';
touchFile($targetAvatar, 'target avatar');
touchFile($otherAvatar, 'other avatar');
$pdo->prepare('UPDATE users SET avatar_path = ?, profile_pic = ? WHERE id = ?')->execute([$targetAvatar, $targetAvatar, $targetId]);
$pdo->prepare('UPDATE users SET avatar_path = ?, profile_pic = ? WHERE id = ?')->execute([$otherAvatar, $otherAvatar, $otherId]);

$targetUploadPath = $uploadsRoot . '/videos/delete_test_upload_' . $targetId . '.mp4';
$otherUploadPath = $uploadsRoot . '/videos/delete_test_upload_' . $otherId . '.mp4';
touchFile($targetUploadPath, 'target upload');
touchFile($otherUploadPath, 'other upload');
$pdo->prepare("INSERT INTO uploads (user_id, filename, title, description, platforms, status, file_path) VALUES (?, ?, 'Target', '', '[\"youtube\"]', 'pending', ?)")->execute([$targetId, basename($targetUploadPath), $targetUploadPath]);
$pdo->prepare("INSERT INTO uploads (user_id, filename, title, description, platforms, status, file_path) VALUES (?, ?, 'Other', '', '[\"youtube\"]', 'pending', ?)")->execute([$otherId, basename($otherUploadPath), $otherUploadPath]);

$targetMediaKey = 'users/' . $targetId . '/media/delete_test/original.mp4';
$targetThumbKey = 'users/' . $targetId . '/media/delete_test/thumbnail.jpg';
$otherMediaKey = 'users/' . $otherId . '/media/delete_test/original.mp4';
touchFile($uploadsRoot . '/' . $targetMediaKey, 'target media key');
touchFile($uploadsRoot . '/' . $targetThumbKey, 'target thumb key');
touchFile($uploadsRoot . '/' . $otherMediaKey, 'other media key');
$pdo->prepare('INSERT INTO studio_media (user_id, name, storage_key, thumbnail_key) VALUES (?, ?, ?, ?)')->execute([$targetId, 'Target Media', $targetMediaKey, $targetThumbKey]);
$pdo->prepare('INSERT INTO studio_media (user_id, name, storage_key) VALUES (?, ?, ?)')->execute([$otherId, 'Other Media', $otherMediaKey]);

$pdo->prepare("INSERT INTO studio_projects (user_id, name, aspect_ratio, timeline_json) VALUES (?, 'Target Project', '16:9', '{}')")->execute([$targetId]);
$pdo->prepare("INSERT INTO studio_projects (user_id, name, aspect_ratio, timeline_json) VALUES (?, 'Other Project', '16:9', '{}')")->execute([$otherId]);
$pdo->prepare("INSERT INTO oauth_tokens (user_id, platform, access_token, refresh_token) VALUES (?, 'youtube', 'secret-token', 'secret-refresh')")->execute([$targetId]);
$pdo->prepare("INSERT INTO oauth_tokens (user_id, platform, access_token, refresh_token) VALUES (?, 'youtube', 'other-token', 'other-refresh')")->execute([$otherId]);

$tempBase = $uploadsRoot . '/temp/delete_test_chunks_' . $targetId;
touchFile($tempBase . '_1', 'chunk1');
touchFile($tempBase . '_2', 'chunk2');
$pdo->prepare("INSERT INTO incomplete_uploads (user_id, file_hash, file_name, file_size, total_chunks, last_uploaded_chunk, temp_target_path, status) VALUES (?, ?, 'chunked.mp4', 20, 2, 2, ?, 'processing')")->execute([$targetId, hash('sha256', 'delete-test-' . $targetId), $tempBase]);

$brandFile = $uploadsRoot . '/brand/brand_kit_' . $targetId . '.json';
$brandLogoRel = 'brand/brand_logo_' . $targetId . '_' . time() . '.png';
touchFile($uploadsRoot . '/' . $brandLogoRel, 'logo');
touchFile($brandFile, json_encode(['logos' => [['url' => $brandLogoRel]]]));

$exportOut = $uploadsRoot . '/processed/delete_test_export_' . $targetId . '.mp4';
touchFile($exportOut, 'export');
$jobId = 'delete_test_job_' . $targetId;
$metaFile = '/tmp/' . $jobId . '_meta.json';
touchFile($metaFile, json_encode(['job_id' => $jobId, 'user_id' => $targetId, 'out_path' => $exportOut, 'output_url' => 'uploads/processed/' . basename($exportOut)]));
touchFile('/tmp/' . $jobId . '_progress.log', 'progress=continue');
touchFile('/tmp/' . $jobId . '_ffmpeg.out', 'ffmpeg log');

$trackingFile = $uploadsRoot . '/tracking_jobs/delete_test_tracking_' . $targetId . '.json';
touchFile($trackingFile, json_encode(['jobId' => 'delete_test_tracking_' . $targetId, 'user_id' => $targetId, 'status' => 'processing']));

$pdo->prepare("INSERT INTO contact_inquiries (user_id, name, email, subject, message) VALUES (?, 'Target Person', 'target@example.test', ?, 'Message')")->execute([$targetId, $contactSubject]);
$pdo->prepare("INSERT INTO rate_limits (identifier, request_count, window_start) VALUES (?, 1, ?)")->execute(['rl_account_deletion_user_' . $targetId, time()]);

$service = new AccountDeletionService($pdo);

$badConfirm = $service->deleteAccount($targetId, 'correct-password', 'DELETE');
ok($badConfirm['success'] === false, 'Invalid confirmation should fail.');
ok((int)scalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = ?', [$targetId]) === 1, 'User should remain after invalid confirmation.');

$badPassword = $service->deleteAccount($targetId, 'wrong-password', 'DELETE MY ACCOUNT');
ok($badPassword['success'] === false, 'Wrong password should fail.');
ok((int)scalar($pdo, 'SELECT COUNT(*) FROM oauth_tokens WHERE user_id = ?', [$targetId]) === 1, 'OAuth token should remain after wrong password.');

$success = $service->deleteAccount($targetId, 'correct-password', 'DELETE MY ACCOUNT');
ok($success['success'] === true, 'Correct deletion should succeed: ' . json_encode($success));

ok((int)scalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = ?', [$targetId]) === 0, 'Target user row should be gone.');
foreach (['oauth_tokens', 'uploads', 'studio_media', 'studio_projects', 'incomplete_uploads'] as $table) {
    ok((int)scalar($pdo, "SELECT COUNT(*) FROM `{$table}` WHERE user_id = ?", [$targetId]) === 0, "{$table} target rows should be gone.");
}
ok((int)scalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = ?', [$otherId]) === 1, 'Other user row should remain.');
foreach (['oauth_tokens', 'uploads', 'studio_media', 'studio_projects'] as $table) {
    ok((int)scalar($pdo, "SELECT COUNT(*) FROM `{$table}` WHERE user_id = ?", [$otherId]) === 1, "{$table} other rows should remain.");
}

ok((int)scalar($pdo, 'SELECT COUNT(*) FROM contact_inquiries WHERE user_id = ?', [$targetId]) === 0, 'Contact inquiry should be unlinked.');
ok((string)scalar($pdo, 'SELECT email FROM contact_inquiries WHERE subject = ?', [$contactSubject]) === 'deleted-user-' . $targetId . '@mediafusion.local', 'Contact email should be anonymized.');
ok((int)scalar($pdo, 'SELECT COUNT(*) FROM rate_limits WHERE identifier LIKE ?', ['%user_' . $targetId]) === 0, 'User rate limits should be deleted.');

foreach ([
    $targetAvatar,
    $targetUploadPath,
    $uploadsRoot . '/' . $targetMediaKey,
    $uploadsRoot . '/' . $targetThumbKey,
    $tempBase . '_1',
    $tempBase . '_2',
    $uploadsRoot . '/' . $brandLogoRel,
    $brandFile,
    $exportOut,
    $metaFile,
    '/tmp/' . $jobId . '_progress.log',
    '/tmp/' . $jobId . '_ffmpeg.out',
    $trackingFile,
] as $deletedFile) {
    ok(!file_exists($deletedFile), 'Expected deleted file to be gone: ' . $deletedFile);
}

foreach ([
    $otherAvatar,
    $otherUploadPath,
    $uploadsRoot . '/' . $otherMediaKey,
] as $keptFile) {
    ok(file_exists($keptFile), 'Expected other user file to remain: ' . $keptFile);
}

echo "Account deletion integration tests passed.\n";
