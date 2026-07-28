<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/AccountDeletionService.php';

send_api_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = requireAuth();
rateLimitPolicy('account_deletion');
require_csrf();

$currentPassword = (string)($_POST['current_password'] ?? '');
$confirmation = (string)($_POST['confirmation'] ?? '');

try {
    $service = new AccountDeletionService($pdo);
    $result = $service->deleteAccount($userId, $currentPassword, $confirmation);

    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    destroy_session('account_deleted');
    echo json_encode(array_merge($result, ['redirect' => 'index.php?account_deleted=1']));
    exit;
} catch (Throwable $e) {
    error_log('Unhandled account deletion exception: ' . $e->getMessage());
    log_security_event('account_deletion_failure', 'unhandled_exception', $userId);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A system error occurred. Please try again.',
    ]);
}
