<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Strict authorization check
$userId = requireAdmin();

rateLimitPolicy('sensitive_account');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.']);
    exit;
}

require_csrf();

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'mark_message_status':
            $messageId = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            
            if ($messageId <= 0 || !in_array($status, ['unread', 'read', 'replied'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE `contact_inquiries` SET `status` = ? WHERE `id` = ?");
            $stmt->execute([$status, $messageId]);
            
            echo json_encode(['success' => true, 'message' => 'Message status updated.']);
            break;

        case 'delete_message':
            $messageId = (int)($_POST['id'] ?? 0);
            if ($messageId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM `contact_inquiries` WHERE `id` = ?");
            $stmt->execute([$messageId]);
            
            echo json_encode(['success' => true, 'message' => 'Message deleted successfully.']);
            break;

        case 'toggle_user_admin':
            $targetUserId = (int)($_POST['id'] ?? 0);
            if ($targetUserId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid User ID.']);
                exit;
            }
            
            // Prevent self-demotion
            if ($targetUserId === (int)$_SESSION['user_id']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'You cannot change your own administrator status.']);
                exit;
            }
            
            // Fetch current state
            $stmt = $pdo->prepare("SELECT `is_admin` FROM `users` WHERE `id` = ?");
            $stmt->execute([$targetUserId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found.']);
                exit;
            }
            
            $newAdminState = ($user['is_admin'] == 1) ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE `users` SET `is_admin` = ? WHERE `id` = ?");
            $stmt->execute([$newAdminState, $targetUserId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'User privileges updated.',
                'is_admin' => $newAdminState
            ]);
            break;

        case 'delete_user':
            $targetUserId = (int)($_POST['id'] ?? 0);
            if ($targetUserId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid User ID.']);
                exit;
            }
            
            // Prevent self-deletion
            if ($targetUserId === (int)$_SESSION['user_id']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']);
                exit;
            }
            
            // Fetch any uploads to delete local files
            $stmt = $pdo->prepare("SELECT `file_path` FROM `uploads` WHERE `user_id` = ?");
            $stmt->execute([$targetUserId]);
            $uploads = $stmt->fetchAll();
            foreach ($uploads as $up) {
                if (!empty($up['file_path'])) {
                    $absPath = __DIR__ . '/../' . $up['file_path'];
                    if (is_file($absPath)) {
                        @unlink($absPath);
                    }
                }
            }
            
            // Delete the user (foreign keys Cascade will handle tokens and DB uploads)
            $stmt = $pdo->prepare("DELETE FROM `users` WHERE `id` = ?");
            $stmt->execute([$targetUserId]);
            
            echo json_encode(['success' => true, 'message' => 'User and associated data deleted.']);
            break;

        case 'delete_upload':
            $uploadId = (int)($_POST['id'] ?? 0);
            if ($uploadId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid Upload ID.']);
                exit;
            }
            
            // Fetch upload row to get local path
            $stmt = $pdo->prepare("SELECT `file_path` FROM `uploads` WHERE `id` = ?");
            $stmt->execute([$uploadId]);
            $upload = $stmt->fetch();
            
            if (!$upload) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Upload record not found.']);
                exit;
            }
            
            // Delete local file
            if (!empty($upload['file_path'])) {
                $absPath = __DIR__ . '/../' . $upload['file_path'];
                if (is_file($absPath)) {
                    @unlink($absPath);
                }
            }
            
            $stmt = $pdo->prepare("DELETE FROM `uploads` WHERE `id` = ?");
            $stmt->execute([$uploadId]);
            
            echo json_encode(['success' => true, 'message' => 'Media submission deleted successfully.']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action command.']);
            break;
    }
} catch (Throwable $e) {
    error_log('Admin handler exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A system error occurred. Please try again.']);
}
