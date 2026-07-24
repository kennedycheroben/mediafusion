<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

rateLimitPolicy('auth_login');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../login.php");
    exit;
}

require_csrf();

$action   = $_POST['action'] ?? '';
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$redirectBack = ($action === 'register') ? '../register.php' : '../login.php';

if (empty($username) || empty($password)) {
    $_SESSION['auth_error'] = "Username and password are required.";
    header("Location: $redirectBack");
    exit;
}

if (!isset($pdo)) {
    $_SESSION['auth_error'] = "Could not connect to the system. Please try again later.";
    header("Location: $redirectBack");
    exit;
}

if ($action === 'register') {
    rateLimitPolicy('auth_register');

    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['auth_error'] = "A valid email address is required.";
        header("Location: ../register.php");
        exit;
    }

    // Ensure email column exists (self-healing migration)
    try {
        $columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('email', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100) DEFAULT NULL UNIQUE");
        }
    } catch (Exception $e) {
        // Column likely already exists
    }

    // Use a generic error message to prevent account enumeration
    $genericError = "Registration could not be completed. Please try again.";

    // Check if username already taken
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        $_SESSION['auth_error'] = "Username is already taken.";
        header("Location: ../register.php");
        exit;
    }

    // Check if email already taken
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $_SESSION['auth_error'] = "Email address is already registered.";
        header("Location: ../register.php");
        exit;
    }

    // Register new user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    if ($stmt->execute([$username, $email, $hash])) {
        $newUserId = (int)$pdo->lastInsertId();
        init_session_metadata($newUserId);
        log_security_event('user_registered', "username={$username}", $newUserId);
        header("Location: ../history.php");
        exit;
    } else {
        $_SESSION['auth_error'] = $genericError;
        header("Location: ../register.php");
        exit;
    }

} elseif ($action === 'login') {
    rateLimitPolicy('auth_login');

    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $userId = (int)$user['id'];
        init_session_metadata($userId);
        log_security_event('login_success', "username={$username}", $userId);
        header("Location: ../history.php");
        exit;
    } else {
        // Use consistent error message to prevent username enumeration
        $_SESSION['auth_error'] = "Invalid username or password.";
        log_security_event('login_failure', "username={$username}");
        header("Location: ../login.php");
        exit;
    }
}

// Fallback
header("Location: ../login.php");
exit;
