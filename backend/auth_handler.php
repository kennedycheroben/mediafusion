<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Determine redirect target based on action
    $redirectBack = ($action === 'register') ? '../register.php' : '../login.php';

    if (empty($username) || empty($password)) {
        $_SESSION['auth_error'] = "Username and password are required.";
        header("Location: $redirectBack");
        exit;
    }

    if (!isset($pdo)) {
        $_SESSION['auth_error'] = "Database connection not established. Please run the database schema first.";
        header("Location: $redirectBack");
        exit;
    }

    if ($action === 'register') {
        // Check if username already taken
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $_SESSION['auth_error'] = "Username is already taken.";
            header("Location: ../register.php");
            exit;
        }

        // Register new user
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
        if ($stmt->execute([$username, $hash])) {
            // Auto-login after successful registration
            session_regenerate_id(true);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            header("Location: ../history.php");
            exit;
        } else {
            $_SESSION['auth_error'] = "Registration failed due to a server error.";
            header("Location: ../register.php");
            exit;
        }

    } elseif ($action === 'login') {
        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            header("Location: ../history.php");
            exit;
        } else {
            $_SESSION['auth_error'] = "Invalid username or password.";
            header("Location: ../login.php");
            exit;
        }
    }
}

// Fallback
header("Location: ../login.php");
exit;
