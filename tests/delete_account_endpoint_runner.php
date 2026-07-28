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

function endpointOk(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function endpointScalar(PDO $pdo, string $sql, array $params = []): int|string|null
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

function endpointEnsureSchema(PDO $pdo): void
{
    $columns = $pdo->query('DESCRIBE users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('security_version', $columns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN security_version INT NOT NULL DEFAULT 0');
    }
}

function endpointReset(PDO $pdo): void
{
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE 'delete_endpoint_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
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

function endpointCreateUser(PDO $pdo, string $username): int
{
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, security_version) VALUES (?, ?, ?, 0)');
    $stmt->execute([$username, $username . '@example.test', password_hash('correct-password', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
}

function runEndpointCase(string $scenario, int $targetId, int $otherId): array
{
    $cmd = sprintf(
        'DB_HOST=localhost DB_NAME=mediafusion DB_USER=root DB_PASS= MEDIA_STORAGE_ROOT=%s S3_ENABLED=false MEDIAFUSION_TEST_TARGET_ID=%d MEDIAFUSION_TEST_OTHER_ID=%d %s -d pdo_mysql.default_socket=%s %s %s',
        escapeshellarg('/tmp/mediafusion_endpoint_uploads'),
        $targetId,
        $otherId,
        escapeshellarg(PHP_BINARY),
        escapeshellarg('/tmp/mediafusion_mysql_test.sock'),
        escapeshellarg(__DIR__ . '/delete_account_endpoint_case.php'),
        escapeshellarg($scenario)
    );
    $cmd .= ' 2>&1';

    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $body = implode("\n", $output);
    $json = json_decode($body, true);
    return ['code' => $code, 'body' => $body, 'json' => is_array($json) ? $json : []];
}

endpointEnsureSchema($pdo);
endpointReset($pdo);

$unauth = runEndpointCase('unauthenticated', 0, 0);
endpointOk(($unauth['json']['success'] ?? true) === false, 'Unauthenticated endpoint request should fail.');

$targetInvalid = endpointCreateUser($pdo, 'delete_endpoint_invalid_csrf');
$otherInvalid = endpointCreateUser($pdo, 'delete_endpoint_other_invalid');
$invalid = runEndpointCase('invalid_csrf', $targetInvalid, $otherInvalid);
endpointOk(($invalid['json']['error'] ?? '') === 'CSRF_TOKEN_INVALID', 'Invalid CSRF should fail. Exit=' . $invalid['code'] . ' Raw body: ' . $invalid['body']);
endpointOk((int)endpointScalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = ?', [$targetInvalid]) === 1, 'Invalid CSRF must not delete user.');

$targetSuccess = endpointCreateUser($pdo, 'delete_endpoint_success');
$otherSuccess = endpointCreateUser($pdo, 'delete_endpoint_other_success');
$pdo->prepare("INSERT INTO oauth_tokens (user_id, platform, access_token, refresh_token) VALUES (?, 'youtube', 'endpoint-token', 'endpoint-refresh')")->execute([$targetSuccess]);
$pdo->prepare("INSERT INTO oauth_tokens (user_id, platform, access_token, refresh_token) VALUES (?, 'youtube', 'other-token', 'other-refresh')")->execute([$otherSuccess]);
$success = runEndpointCase('success', $targetSuccess, $otherSuccess);
endpointOk(($success['json']['success'] ?? false) === true, 'Endpoint success case should succeed: ' . $success['body']);
endpointOk((int)endpointScalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = ?', [$targetSuccess]) === 0, 'Endpoint should delete authenticated target user.');
endpointOk((int)endpointScalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = ?', [$otherSuccess]) === 1, 'Endpoint must ignore posted user_id and preserve other user.');
endpointOk((int)endpointScalar($pdo, 'SELECT COUNT(*) FROM oauth_tokens WHERE user_id = ?', [$targetSuccess]) === 0, 'Endpoint should delete target OAuth tokens.');
endpointOk((int)endpointScalar($pdo, 'SELECT COUNT(*) FROM oauth_tokens WHERE user_id = ?', [$otherSuccess]) === 1, 'Endpoint should preserve other user OAuth tokens.');

echo "Delete account endpoint tests passed.\n";
