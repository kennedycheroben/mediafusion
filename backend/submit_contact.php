<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

rateLimitPolicy('contact_form');

// CSRF Validation
require_csrf();

// Retrieve input fields
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

// Validation
if (empty($name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter your name.']);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid email address is required for us to respond.']);
    exit;
}

if (empty($subject)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select a topic.']);
    exit;
}

if (empty($message) || mb_strlen($message) < 15) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message must be at least 15 characters long.']);
    exit;
}

require_once __DIR__ . '/db.php';

try {
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $stmt = $pdo->prepare("
        INSERT INTO `contact_inquiries` (`user_id`, `name`, `email`, `subject`, `message`, `status`, `created_at`)
        VALUES (?, ?, ?, ?, ?, 'unread', CURRENT_TIMESTAMP)
    ");

    $stmt->execute([$userId, $name, $email, $subject, $message]);

    echo json_encode(['success' => true, 'message' => 'Your message has been received.']);
    exit;
} catch (Throwable $e) {
    error_log('Contact form submission failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A system error occurred. Please try again later.']);
    exit;
}
