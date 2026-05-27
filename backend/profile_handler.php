<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'db.php';
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $displayName = trim($_POST['display_name'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $curPassword = $_POST['current_password'] ?? '';

    // Fetch current user row
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User record not found.']);
        exit;
    }

    $fields = [];
    $params = [];
    $response = ['success' => false, 'message' => ''];

    if ($displayName !== '' && $displayName !== $user['display_name']) {
        $fields[] = "display_name = ?";
        $params[] = $displayName;
        $response['display_name'] = $displayName;
    }

    if ($newPassword !== '') {
        if (!password_verify($curPassword, $user['password_hash'])) {
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
            $response['success'] = true;
            $response['message'] = 'Profile updated successfully.';
        } catch (Exception $e) {
            $response['success'] = false;
            $response['message'] = 'Database error occurred.';
        }
    } else {
        $response['success'] = true;
        $response['message'] = 'No changes detected.';
    }

    echo json_encode($response);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
exit;
