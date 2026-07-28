<?php
declare(strict_types=1);

use MediaFusion\Storage\StorageManager;

final class AccountDeletionService
{
    private PDO $pdo;
    private StorageManager $storage;
    private string $projectRoot;
    private string $uploadsRoot;
    private array $localRoots = [];
    private string $tempRoot;
    private array $tableCache = [];
    private array $columnCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->storage = StorageManager::getInstance();
        $this->projectRoot = dirname(__DIR__);
        $projectUploads = $this->canonicalDir($this->projectRoot . '/uploads') ?? ($this->projectRoot . '/uploads');
        $storageUploads = $this->canonicalDir($this->storage->getStorageRoot()) ?? $this->storage->getStorageRoot();
        $this->uploadsRoot = $storageUploads;
        $this->localRoots = array_values(array_unique([$storageUploads, $projectUploads]));
        $this->tempRoot = $this->canonicalDir(sys_get_temp_dir()) ?? sys_get_temp_dir();
    }

    /**
     * Permanently deletes the authenticated user's account and owned data.
     *
     * @return array{success: bool, message: string, deleted_storage: int, storage_failures: int}
     */
    public function deleteAccount(int $userId, string $currentPassword, string $confirmation): array
    {
        if ($userId < 1) {
            return ['success' => false, 'message' => 'Invalid authenticated user.', 'deleted_storage' => 0, 'storage_failures' => 0];
        }

        $user = $this->fetchUser($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'Account not found.', 'deleted_storage' => 0, 'storage_failures' => 0];
        }

        if (trim($confirmation) !== 'DELETE MY ACCOUNT') {
            return ['success' => false, 'message' => 'Type DELETE MY ACCOUNT to confirm permanent deletion.', 'deleted_storage' => 0, 'storage_failures' => 0];
        }

        if ($this->requiresPassword($user) && !password_verify($currentPassword, (string)$user['password_hash'])) {
            log_security_event('account_deletion_failure', 'incorrect_password', $userId);
            return ['success' => false, 'message' => 'Current password is incorrect.', 'deleted_storage' => 0, 'storage_failures' => 0];
        }

        $snapshot = $this->buildDeletionSnapshot($userId, $user);
        $this->cancelUserJobs($userId);

        $storageResult = $this->deleteStorageSnapshot($snapshot);
        if ($storageResult['failures'] > 0) {
            log_security_event('account_deletion_storage_failure', 'failures=' . $storageResult['failures'], $userId);
            return [
                'success' => false,
                'message' => 'Some account files could not be deleted. Please try again or contact support.',
                'deleted_storage' => $storageResult['deleted'],
                'storage_failures' => $storageResult['failures'],
            ];
        }

        $this->revokeOtherSessions($userId);

        try {
            $this->pdo->beginTransaction();
            $this->deleteDatabaseRows($userId, $user);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Account deletion DB failure for user ' . $userId . ': ' . $e->getMessage());
            log_security_event('account_deletion_failure', 'database_failure', $userId);
            return [
                'success' => false,
                'message' => 'The account could not be deleted because of a database error.',
                'deleted_storage' => $storageResult['deleted'],
                'storage_failures' => 0,
            ];
        }

        log_security_event('account_deleted', 'status=completed', $userId);
        return [
            'success' => true,
            'message' => 'Your account has been permanently deleted.',
            'deleted_storage' => $storageResult['deleted'],
            'storage_failures' => 0,
        ];
    }

    public function requiresPassword(array $user): bool
    {
        $hash = (string)($user['password_hash'] ?? '');
        if ($hash === '') {
            return false;
        }

        $hasGoogle = isset($user['google_id']) && trim((string)$user['google_id']) !== '';
        return !$hasGoogle;
    }

    private function fetchUser(int $userId): ?array
    {
        $columns = ['id', 'password_hash'];
        foreach (['username', 'email', 'avatar_path', 'profile_pic', 'google_id', 'avatar_url'] as $optional) {
            if ($this->hasColumn('users', $optional)) {
                $columns[] = $optional;
            }
        }

        $sql = 'SELECT ' . implode(', ', array_map(fn($c) => "`{$c}`", $columns)) . ' FROM users WHERE id = ? LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function revokeOtherSessions(int $userId): void
    {
        if (!$this->hasColumn('users', 'security_version')) {
            return;
        }
        try {
            $stmt = $this->pdo->prepare('UPDATE users SET security_version = security_version + 1 WHERE id = ?');
            $stmt->execute([$userId]);
            log_security_event('all_sessions_invalidated', 'reason=account_deletion', $userId);
        } catch (Throwable $e) {
            error_log('Failed to revoke sessions during account deletion for user ' . $userId . ': ' . $e->getMessage());
        }
    }

    private function buildDeletionSnapshot(int $userId, array $user): array
    {
        $snapshot = ['storage_keys' => [], 'local_files' => [], 'incomplete_uploads' => []];

        foreach (['avatar_path', 'profile_pic'] as $col) {
            if (!empty($user[$col])) {
                $this->addOwnedPathOrKey($snapshot, (string)$user[$col], $userId, 'users');
            }
        }

        if ($this->hasTable('uploads')) {
            $stmt = $this->pdo->prepare('SELECT id, file_path FROM uploads WHERE user_id = ?');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $path = (string)($row['file_path'] ?? '');
                if ($path !== '' && !$this->isSharedUploadPath($path, $userId)) {
                    $this->addOwnedPathOrKey($snapshot, $path, $userId, 'uploads');
                }
            }
        }

        if ($this->hasTable('studio_media')) {
            $cols = $this->columnsFor('studio_media');
            $select = array_values(array_intersect(
                ['storage_path', 'storage_key', 'thumbnail_url', 'thumbnail_key', 'public_url'],
                $cols
            ));
            if ($select) {
                $stmt = $this->pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM studio_media WHERE user_id = ?');
                $stmt->execute([$userId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    foreach ($select as $col) {
                        if (!empty($row[$col])) {
                            $this->addOwnedPathOrKey($snapshot, (string)$row[$col], $userId, 'studio_media');
                        }
                    }
                }
            }
        }

        if ($this->hasTable('incomplete_uploads')) {
            $stmt = $this->pdo->prepare('SELECT temp_target_path, total_chunks FROM incomplete_uploads WHERE user_id = ?');
            $stmt->execute([$userId]);
            $snapshot['incomplete_uploads'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $this->addBrandKitFiles($snapshot, $userId);
        $this->addJobFiles($snapshot, $userId);

        $snapshot['storage_keys'] = array_values(array_unique($snapshot['storage_keys']));
        $snapshot['local_files'] = array_values(array_unique($snapshot['local_files']));
        return $snapshot;
    }

    private function addOwnedPathOrKey(array &$snapshot, string $value, int $userId, string $source): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $key = $this->extractStorageKey($value, $userId, $source);
        if ($key !== null) {
            $snapshot['storage_keys'][] = $key;
            return;
        }

        if ($source === 'brand' && !$this->isOwnedBrandPath($value, $userId)) {
            return;
        }

        $path = $this->resolveSafeLocalFile($value);
        if ($path !== null) {
            $snapshot['local_files'][] = $path;
        }
    }

    private function extractStorageKey(string $value, int $userId, string $source): ?string
    {
        $candidate = $value;
        if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
            $path = parse_url($candidate, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                return null;
            }
            $candidate = rawurldecode(ltrim($path, '/'));
        }

        $candidate = ltrim($candidate, '/');
        if (str_starts_with($candidate, 'uploads/')) {
            $candidate = substr($candidate, 8);
        }

        if (preg_match('#^users/' . preg_quote((string)$userId, '#') . '/[A-Za-z0-9._/-]+$#', $candidate)) {
            return $candidate;
        }

        if ($source === 'uploads' && preg_match('#(^|/)videos/[A-Za-z0-9._-]+$#', $candidate, $m)) {
            return ltrim(substr($candidate, (int)strpos($candidate, 'videos/')), '/');
        }

        return null;
    }

    private function resolveSafeLocalFile(string $value): ?string
    {
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return null;
        }

        $raw = str_replace('\\', '/', $value);
        $candidates = [];
        if (str_starts_with($raw, '/')) {
            $candidates[] = $raw;
        } else {
            $relative = ltrim($raw, '/');
            $candidates[] = $this->projectRoot . '/' . $relative;
            $storageRelative = str_starts_with($relative, 'uploads/') ? substr($relative, 8) : $relative;
            foreach ($this->localRoots as $root) {
                $candidates[] = $root . '/' . $storageRelative;
            }
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (is_link($candidate) || !is_file($candidate)) {
                continue;
            }

            $real = realpath($candidate);
            if ($real === false) {
                continue;
            }

            if ($this->isPathInsideAny($real, $this->localRoots)) {
                return $real;
            }
        }

        return null;
    }

    private function isOwnedBrandPath(string $value, int $userId): bool
    {
        $path = parse_url($value, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $value;
        $base = basename(str_replace('\\', '/', $path));
        return $base === 'brand_kit_' . $userId . '.json'
            || preg_match('/^brand_logo_' . preg_quote((string)$userId, '/') . '_[0-9]+\\.[a-z0-9]+$/i', $base) === 1;
    }

    private function addBrandKitFiles(array &$snapshot, int $userId): void
    {
        foreach ($this->localRoots as $root) {
            $brandFile = $root . '/brand/brand_kit_' . $userId . '.json';
            if (is_file($brandFile)) {
                $snapshot['local_files'][] = realpath($brandFile) ?: $brandFile;
                $kit = json_decode((string)@file_get_contents($brandFile), true);
                foreach ($this->walkValues(is_array($kit) ? $kit : []) as $value) {
                    if (is_string($value) && str_contains($value, 'brand/')) {
                        $this->addOwnedPathOrKey($snapshot, $value, $userId, 'brand');
                    }
                }
            }
        }
    }

    private function addJobFiles(array &$snapshot, int $userId): void
    {
        foreach (glob('/tmp/*_meta.json') ?: [] as $file) {
            $meta = json_decode((string)@file_get_contents($file), true);
            if (!is_array($meta) || (int)($meta['user_id'] ?? 0) !== $userId) {
                continue;
            }
            foreach (['out_path', 'output_url'] as $key) {
                if (!empty($meta[$key])) {
                    $this->addOwnedPathOrKey($snapshot, (string)$meta[$key], $userId, 'job');
                }
            }
            if (is_file($file)) {
                $snapshot['local_files'][] = realpath($file) ?: $file;
            }
            $jobId = preg_replace('/[^A-Za-z0-9_]/', '', (string)($meta['job_id'] ?? ''));
            foreach (['/tmp/' . $jobId . '_progress.log', '/tmp/' . $jobId . '_ffmpeg.out'] as $jobFile) {
                if ($jobId !== '' && is_file($jobFile) && !is_link($jobFile)) {
                    $snapshot['local_files'][] = realpath($jobFile) ?: $jobFile;
                }
            }
        }

        foreach ($this->localRoots as $root) {
            $trackingDir = $root . '/tracking_jobs';
            foreach (glob($trackingDir . '/*.json') ?: [] as $file) {
                $job = json_decode((string)@file_get_contents($file), true);
                if (is_array($job) && (int)($job['user_id'] ?? 0) === $userId && !is_link($file)) {
                    $snapshot['local_files'][] = realpath($file) ?: $file;
                }
            }
        }
    }

    private function cancelUserJobs(int $userId): void
    {
        if ($this->hasTable('uploads')) {
            $stmt = $this->pdo->prepare("UPDATE uploads SET status = 'failed', results_json = ?, updated_at = NOW() WHERE user_id = ? AND status IN ('pending', 'uploading', 'processing')");
            $stmt->execute([json_encode(['cancelled' => true, 'reason' => 'account_deleted']), $userId]);
        }

        foreach (glob('/tmp/*_meta.json') ?: [] as $file) {
            $meta = json_decode((string)@file_get_contents($file), true);
            if (is_array($meta) && (int)($meta['user_id'] ?? 0) === $userId) {
                $meta['status'] = 'cancelled';
                $meta['cancelled_at'] = time();
                @file_put_contents($file, json_encode($meta));
            }
        }

        foreach ($this->localRoots as $root) {
            $trackingDir = $root . '/tracking_jobs';
            foreach (glob($trackingDir . '/*.json') ?: [] as $file) {
                $job = json_decode((string)@file_get_contents($file), true);
                if (is_array($job) && (int)($job['user_id'] ?? 0) === $userId) {
                    $job['status'] = 'cancelled';
                    $job['updated_at'] = time();
                    @file_put_contents($file, json_encode($job));
                }
            }
        }
    }

    private function deleteStorageSnapshot(array $snapshot): array
    {
        $deleted = 0;
        $failures = 0;

        foreach ($snapshot['storage_keys'] as $key) {
            if ($this->storage->delete($key)) {
                $deleted++;
            } elseif ($this->storage->exists($key)) {
                $failures++;
            }
        }

        foreach ($snapshot['local_files'] as $file) {
            if (!$this->deleteSafeLocalFile($file)) {
                $failures++;
            } else {
                $deleted++;
            }
        }

        foreach ($snapshot['incomplete_uploads'] as $row) {
            $base = (string)($row['temp_target_path'] ?? '');
            $total = max(0, (int)($row['total_chunks'] ?? 0));
            for ($i = 1; $i <= $total; $i++) {
                $chunk = $base . '_' . $i;
                if (is_file($chunk) && $this->deleteSafeLocalFile($chunk)) {
                    $deleted++;
                }
            }
        }

        return ['deleted' => $deleted, 'failures' => $failures];
    }

    private function deleteSafeLocalFile(string $file): bool
    {
        if (!is_file($file)) {
            return true;
        }
        if (is_link($file)) {
            return false;
        }
        $real = realpath($file);
        if ($real === false) {
            return false;
        }
        if (!$this->isPathInsideAny($real, $this->localRoots) && !$this->isSafeTempJobFile($real)) {
            return false;
        }
        return @unlink($real);
    }

    private function isSafeTempJobFile(string $file): bool
    {
        if (!$this->isPathInside($file, $this->tempRoot)) {
            return false;
        }
        $base = basename($file);
        return preg_match('/^[A-Za-z0-9_]+_(meta\\.json|progress\\.log|ffmpeg\\.out)$/', $base) === 1;
    }

    private function deleteDatabaseRows(int $userId, array $user): void
    {
        if ($this->hasTable('password_resets') && !empty($user['email'])) {
            $stmt = $this->pdo->prepare('DELETE FROM password_resets WHERE email = ?');
            $stmt->execute([(string)$user['email']]);
        }

        $deleteTables = [
            'studio_projects',
            'studio_media',
            'incomplete_uploads',
            'uploads',
            'oauth_tokens',
        ];
        foreach ($deleteTables as $table) {
            if ($this->hasTable($table) && $this->hasColumn($table, 'user_id')) {
                $stmt = $this->pdo->prepare("DELETE FROM `{$table}` WHERE user_id = ?");
                $stmt->execute([$userId]);
            }
        }

        if ($this->hasTable('contact_inquiries') && $this->hasColumn('contact_inquiries', 'user_id')) {
            $stmt = $this->pdo->prepare(
                "UPDATE contact_inquiries
                 SET user_id = NULL, name = 'Deleted user', email = ?, status = status
                 WHERE user_id = ?"
            );
            $stmt->execute(['deleted-user-' . $userId . '@mediafusion.local', $userId]);
        }

        if ($this->hasTable('rate_limits')) {
            $patterns = ['rl_%_user_' . $userId, 'rl_%user_' . $userId];
            foreach ($patterns as $pattern) {
                $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE identifier LIKE ?');
                $stmt->execute([$pattern]);
            }
        }

        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('User row was not deleted.');
        }
    }

    private function isSharedUploadPath(string $path, int $userId): bool
    {
        if (!$this->hasTable('uploads') || !$this->hasColumn('uploads', 'file_path')) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM uploads WHERE file_path = ? AND user_id <> ?');
        $stmt->execute([$path, $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            return $this->tableCache[$table] = ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            return $this->tableCache[$table] = false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columnsFor($table), true);
    }

    private function columnsFor(string $table): array
    {
        if (array_key_exists($table, $this->columnCache)) {
            return $this->columnCache[$table];
        }
        if (!$this->hasTable($table)) {
            return $this->columnCache[$table] = [];
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            return $this->columnCache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return $this->columnCache[$table] = [];
        }
    }

    private function walkValues(array $data): Generator
    {
        foreach ($data as $value) {
            if (is_array($value)) {
                yield from $this->walkValues($value);
            } else {
                yield $value;
            }
        }
    }

    private function canonicalDir(string $dir): ?string
    {
        $real = realpath($dir);
        return $real !== false && is_dir($real) ? rtrim($real, '/') : null;
    }

    private function isPathInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function isPathInsideAny(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($this->isPathInside($path, (string)$root)) {
                return true;
            }
        }
        return false;
    }
}
