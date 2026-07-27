<?php
declare(strict_types=1);

/**
 * backend/migrate_encrypt_tokens.php
 *
 * One-time migration script to encrypt existing plaintext OAuth tokens.
 * Safe to run multiple times (skips already-encrypted values).
 *
 * Usage: php backend/migrate_encrypt_tokens.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/token_crypto.php';

echo "=== OAuth Token Encryption Migration ===" . PHP_EOL;
echo "Time: " . date('Y-m-d H:i:s') . PHP_EOL;

$crypto = getTokenCrypto();

// Count total tokens
$stmt = $pdo->query("SELECT COUNT(*) FROM oauth_tokens");
$total = (int)$stmt->fetchColumn();
echo "Total token rows: {$total}" . PHP_EOL;

// Find unencrypted tokens
$stmt = $pdo->query("SELECT id, user_id, platform, access_token, refresh_token FROM oauth_tokens");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$encrypted = 0;
$migrated = 0;
$errors = 0;

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $userId = (int)$row['user_id'];
    $platform = (string)$row['platform'];
    $accessToken = (string)$row['access_token'];
    $refreshToken = (string)($row['refresh_token'] ?? '');

    if ($accessToken !== '' && !TokenCrypto::isEncrypted($accessToken)) {
        try {
            $encAccess = $crypto->encrypt($accessToken);
            $pdo->prepare("UPDATE oauth_tokens SET access_token = ? WHERE id = ?")
                ->execute([$encAccess, $id]);
            $migrated++;
            echo "  [MIGRATED] id={$id} user={$userId} platform={$platform} access_token" . PHP_EOL;
        } catch (Throwable $e) {
            $errors++;
            echo "  [ERROR] id={$id} user={$userId} platform={$platform}: " . $e->getMessage() . PHP_EOL;
        }
    } else {
        $encrypted++;
    }

    if ($refreshToken !== '' && !TokenCrypto::isEncrypted($refreshToken)) {
        try {
            $encRefresh = $crypto->encrypt($refreshToken);
            $pdo->prepare("UPDATE oauth_tokens SET refresh_token = ? WHERE id = ?")
                ->execute([$encRefresh, $id]);
            echo "  [MIGRATED] id={$id} user={$userId} platform={$platform} refresh_token" . PHP_EOL;
        } catch (Throwable $e) {
            $errors++;
            echo "  [ERROR] id={$id} user={$userId} platform={$platform} refresh_token: " . $e->getMessage() . PHP_EOL;
        }
    }
}

echo PHP_EOL . "=== Results ===" . PHP_EOL;
echo "Already encrypted: {$encrypted}" . PHP_EOL;
echo "Newly migrated:    {$migrated}" . PHP_EOL;
echo "Errors:            {$errors}" . PHP_EOL;
echo "Done." . PHP_EOL;
