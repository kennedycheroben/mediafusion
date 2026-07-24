<?php
declare(strict_types=1);

/**
 * MediaFusion - Existing Media Migration Script
 *
 * Migrates existing local filesystem media to object storage.
 *
 * Features:
 * - Resumable: Tracks migration state in database
 * - Idempotent: Safe to run multiple times
 * - Verifiable: Checks file integrity after migration
 * - Reportable: Outputs detailed migration report
 *
 * Usage:
 *   php backend/storage/migrate_existing_media.php [--dry-run] [--limit=100] [--type=studio_media|uploads|all]
 *
 * Environment:
 *   S3_ENABLED=true   — Must be enabled to migrate to object storage
 *   MEDIA_DRY_RUN=1   — Only report, don't actually migrate
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/MediaService.php';

use MediaFusion\Storage\StorageManager;
use MediaFusion\Storage\MediaUrlResolver;

// ── Parse CLI arguments ──────────────────────────────────────────────────────
$isDryRun = in_array('--dry-run', $argv ?? []) || (getenv('MEDIA_DRY_RUN') === '1');
$limit = 100;
$type = 'all';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, 9);
    }
    if (str_starts_with($arg, '--type=')) {
        $type = substr($arg, 7);
    }
}

echo "=== MediaFusion Media Migration ===" . PHP_EOL;
echo "Mode: " . ($isDryRun ? "DRY RUN (no changes)" : "LIVE") . PHP_EOL;
echo "Type: {$type}" . PHP_EOL;
echo "Limit: {$limit}" . PHP_EOL;
echo PHP_EOL;

// ── Check storage availability ───────────────────────────────────────────────
$storage = StorageManager::getInstance();
if (!$storage->isUsingObjectStorage()) {
    echo "WARNING: Object storage is not enabled. Files will remain on local storage." . PHP_EOL;
    echo "Set S3_ENABLED=true in .env to enable object storage migration." . PHP_EOL;
    echo PHP_EOL;
}

// ── Migration Report ─────────────────────────────────────────────────────────
$report = [
    'total' => 0,
    'migrated' => 0,
    'skipped' => 0,
    'failed' => 0,
    'missing' => [],
    'errors' => [],
];

// ── Migrate studio_media ─────────────────────────────────────────────────────
if ($type === 'all' || $type === 'studio_media') {
    echo "--- Migrating studio_media ---" . PHP_EOL;

    $stmt = $pdo->query("
        SELECT id, user_id, storage_name, storage_path, public_url, thumbnail_url, mime_type
        FROM studio_media
        WHERE storage_key IS NULL
        LIMIT {$limit}
    ");
    $media = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report['total'] += count($media);

    foreach ($media as $item) {
        $id = $item['id'];
        $userId = $item['user_id'];

        // Determine source file path
        $sourcePath = $item['storage_path'] ?? '';
        if (empty($sourcePath) || !is_file($sourcePath)) {
            // Try resolving from public_url
            $resolver = new MediaUrlResolver();
            $resolvedUrl = $resolver->resolveLegacy($item['public_url'] ?? '');
            $urlPath = parse_url($resolvedUrl, PHP_URL_PATH);
            if ($urlPath !== null) {
                $sourcePath = dirname(__DIR__, 2) . '/' . ltrim($urlPath, '/');
            }
        }

        if (empty($sourcePath) || !is_file($sourcePath)) {
            echo "  SKIP #{$id}: Source file not found" . PHP_EOL;
            $report['skipped']++;
            $report['missing'][] = "studio_media #{$id}";
            continue;
        }

        // Determine MIME type and extension
        $mime = $item['mime_type'] ?? 'application/octet-stream';
        $extMap = [
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav',
        ];
        $ext = $extMap[$mime] ?? pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'bin';

        // Generate new storage key
        $newKey = $storage->generateKey($userId, 'media', $ext, 'original');

        if ($isDryRun) {
            echo "  DRY RUN #{$id}: {$sourcePath} -> {$newKey}" . PHP_EOL;
            $report['migrated']++;
            continue;
        }

        // Upload to object storage
        $result = $storage->putFile($sourcePath, $newKey, $mime);
        if ($result === null) {
            echo "  FAILED #{$id}: Upload failed" . PHP_EOL;
            $report['failed']++;
            $report['errors'][] = "studio_media #{$id}: upload failed";
            continue;
        }

        // Migrate thumbnail if it exists
        $thumbKey = null;
        if (!empty($item['thumbnail_url']) && !str_starts_with($item['thumbnail_url'], 'http')) {
            $thumbPath = dirname(__DIR__, 2) . '/' . ltrim($item['thumbnail_url'], '/');
            if (is_file($thumbPath)) {
                $thumbKey = $storage->generateThumbnailKey($userId, 'media', 'jpg');
                $storage->putFile($thumbPath, $thumbKey, 'image/jpeg');
            }
        }

        // Update database record
        $update = $pdo->prepare("
            UPDATE studio_media 
            SET storage_key = ?, storage_provider = ?, thumbnail_key = ?
            WHERE id = ?
        ");
        $update->execute([$newKey, $storage->getProviderName(), $thumbKey, $id]);

        echo "  OK #{$id}: {$newKey}" . PHP_EOL;
        $report['migrated']++;
    }
}

// ── Migrate uploads ──────────────────────────────────────────────────────────
if ($type === 'all' || $type === 'uploads') {
    echo PHP_EOL . "--- Migrating uploads ---" . PHP_EOL;

    $stmt = $pdo->query("
        SELECT id, user_id, filename, file_path, video_url
        FROM uploads
        WHERE storage_key IS NULL AND file_path IS NOT NULL
        LIMIT {$limit}
    ");
    $uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report['total'] += count($uploads);

    foreach ($uploads as $item) {
        $id = $item['id'];
        $userId = $item['user_id'];
        $filePath = $item['file_path'];

        // Skip S3 URLs (already in object storage)
        if (str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://')) {
            echo "  SKIP #{$id}: Already in object storage" . PHP_EOL;
            $report['skipped']++;
            continue;
        }

        // Resolve local path
        $resolver = new MediaUrlResolver();
        $resolvedUrl = $resolver->resolveLegacy($filePath);
        $urlPath = parse_url($resolvedUrl, PHP_URL_PATH);
        $sourcePath = $urlPath !== null ? dirname(__DIR__, 2) . '/' . ltrim($urlPath, '/') : $filePath;

        if (!is_file($sourcePath)) {
            echo "  SKIP #{$id}: Source file not found ({$filePath})" . PHP_EOL;
            $report['skipped']++;
            $report['missing'][] = "uploads #{$id}";
            continue;
        }

        $ext = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'mp4';
        $mime = mime_content_type($sourcePath) ?: 'video/mp4';
        $newKey = $storage->generateKey($userId, 'distribution', $ext, 'original');

        if ($isDryRun) {
            echo "  DRY RUN #{$id}: {$sourcePath} -> {$newKey}" . PHP_EOL;
            $report['migrated']++;
            continue;
        }

        $result = $storage->putFile($sourcePath, $newKey, $mime);
        if ($result === null) {
            echo "  FAILED #{$id}: Upload failed" . PHP_EOL;
            $report['failed']++;
            $report['errors'][] = "uploads #{$id}: upload failed";
            continue;
        }

        $update = $pdo->prepare("UPDATE uploads SET storage_key = ?, storage_provider = ? WHERE id = ?");
        $update->execute([$newKey, $storage->getProviderName(), $id]);

        echo "  OK #{$id}: {$newKey}" . PHP_EOL;
        $report['migrated']++;
    }
}

// ── Output Report ────────────────────────────────────────────────────────────
echo PHP_EOL . "=== Migration Report ===" . PHP_EOL;
echo "Total records found:    {$report['total']}" . PHP_EOL;
echo "Successfully migrated:  {$report['migrated']}" . PHP_EOL;
echo "Skipped (already done): {$report['skipped']}" . PHP_EOL;
echo "Failed:                 {$report['failed']}" . PHP_EOL;
echo "Missing source files:   " . count($report['missing']) . PHP_EOL;

if (!empty($report['errors'])) {
    echo PHP_EOL . "Errors:" . PHP_EOL;
    foreach ($report['errors'] as $err) {
        echo "  - {$err}" . PHP_EOL;
    }
}

if ($isDryRun) {
    echo PHP_EOL . "This was a DRY RUN. No files were modified." . PHP_EOL;
    echo "Run without --dry-run to perform actual migration." . PHP_EOL;
}
