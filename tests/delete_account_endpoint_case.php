<?php
declare(strict_types=1);

$scenario = $argv[1] ?? '';
$targetId = (int)(getenv('MEDIAFUSION_TEST_TARGET_ID') ?: 0);
$otherId = (int)(getenv('MEDIAFUSION_TEST_OTHER_ID') ?: 0);

register_shutdown_function(static function (): void {
    if (getenv('MEDIAFUSION_ENDPOINT_DEBUG') === '1') {
        fwrite(STDERR, 'shutdown_http_code=' . http_response_code() . ' ob_level=' . ob_get_level() . PHP_EOL);
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
});

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['SCRIPT_NAME'] = '/backend/delete_account.php';

if ($scenario !== 'unauthenticated') {
    $sessionDir = sys_get_temp_dir() . '/mediafusion_endpoint_sessions';
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0700, true);
    }
    session_save_path($sessionDir);
    session_id('mf-endpoint-' . preg_replace('/[^a-z0-9-]/i', '', str_replace('_', '-', $scenario)) . '-' . bin2hex(random_bytes(4)));
    session_start();
    $_SESSION['user_id'] = $targetId;
    $_SESSION['session_created_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['last_session_regen'] = time();
    $_SESSION['session_version'] = 0;
    $_SESSION['csrf_token'] = 'endpoint-test-token';
    if (getenv('MEDIAFUSION_ENDPOINT_DEBUG') === '1') {
        fwrite(STDERR, 'session_user=' . ($_SESSION['user_id'] ?? '-') . ' csrf=' . ($_SESSION['csrf_token'] ?? '-') . PHP_EOL);
    }
}

if ($scenario === 'invalid_csrf') {
    $_POST = [
        'csrf_token' => 'wrong-token',
        'current_password' => 'correct-password',
        'confirmation' => 'DELETE MY ACCOUNT',
    ];
} elseif ($scenario === 'success') {
    $_POST = [
        'csrf_token' => 'endpoint-test-token',
        'current_password' => 'correct-password',
        'confirmation' => 'DELETE MY ACCOUNT',
        'user_id' => (string)$otherId,
    ];
} else {
    $_POST = [
        'csrf_token' => 'endpoint-test-token',
        'current_password' => 'correct-password',
        'confirmation' => 'DELETE MY ACCOUNT',
    ];
}

require dirname(__DIR__) . '/backend/delete_account.php';
