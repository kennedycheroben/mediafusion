<?php
// Load configuration constants
require_once __DIR__ . '/../config.php'; 

$host = DB_HOST;
$port = defined('DB_PORT') ? DB_PORT : '3306';
$db   = DB_NAME;
$user = DB_USER;
$pass = DB_PASS;
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => true,
    PDO::MYSQL_ATTR_FOUND_ROWS   => true,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Performance: Set connection-level options for high concurrency
    try {
        $pdo->exec("SET SESSION wait_timeout=28800");
        $pdo->exec("SET SESSION interactive_timeout=28800");
    } catch (\PDOException $opt_e) {
        // Ignored, session variable setting might not be supported or allowed by the server configuration
    }
} catch (\PDOException $e) {
    // Avoid leaking sensitive details to clients. Log server-side only.
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    if (APP_DEBUG && (isset($_GET['debug']) || isset($_POST['debug']))) {
        echo 'Database connection failed: ' . htmlspecialchars($e->getMessage()) . ' (DSN: ' . htmlspecialchars($dsn) . ')';
    } else {
        echo 'Database connection failed.';
    }
    exit;
}
?>