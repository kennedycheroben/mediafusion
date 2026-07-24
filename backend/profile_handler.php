<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$userId = requireAuth();
rateLimitPolicy('sensitive_account');

require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$displayName = trim($_POST['display_name'] ?? '');
$newPassword = $_POST['new_password'] ?? '';
$curPassword = $_POST['current_password'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User profile not found.']);
    exit;
}

$fields = [];
$params = [];
$response = ['success' => false, 'message' => ''];

if ($displayName !== '' && $displayName !== ($user['display_name'] ?? '')) {
    $fields[] = "display_name = ?";
    $params[] = $displayName;
    $response['display_name'] = $displayName;
}

if ($newPassword !== '') {
    rateLimitPolicy('sensitive_password');

    if (!password_verify($curPassword, $user['password_hash'])) {
        log_security_event('password_change_failure', 'incorrect_current_password', $userId);
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }
    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
        exit;
    }
    $fields[] = "password_hash = ?";
    $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
}

if (!empty($fields)) {
    $params[] = $userId;
    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
    
    try {
        $pdo->prepare($sql)->execute($params);

        // If password changed, invalidate all other sessions for this user
        if ($newPassword !== '') {
            invalidate_all_user_sessions($userId, 'password_change');
            log_security_event('password_changed', 'user_initiated', $userId);
        }

        $response['success'] = true;
        $response['message'] = 'Profile updated successfully.';
    } catch (Exception $e) {
        error_log("Database profile update exception: " . $e->getMessage());
        $response['success'] = false;
        $response['message'] = 'A system error occurred. Please try again.';
    }
} else {
    $response['success'] = true;
    $response['message'] = 'No changes detected.';
}

echo json_encode($response);
exit;
