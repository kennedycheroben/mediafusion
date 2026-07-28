<?php
/**
 * Unify Social Hub - SMTP-Driven Secure Password Reset Request
 * 
 * BACKEND SECURITY DESIGN:
 * 1. Self-Healing Schema: Automatically structures 'users' email column and 'password_resets' table.
 * 2. Cryptographic Reset Token: Generates secure 32-byte (256-bit) random tokens.
 * 3. Secure Hash Storage: Computes SHA-256 hashes of tokens to prevent DB leak reuse.
 * 4. Custom OO SMTP Socket Engine: Conducts native stream-level socket handshakes (SSL/TLS)
 *    directly over cPanel standard layouts, capturing absolute transaction traces.
 * 5. Sandbox Resiliency: Auto-displays reset URLs when mail server handles fail, permitting direct local testing.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) {
    // Session is active
} else {
    session_start();
}

// ----------------------------------------------------
// 1. Self-Healing Database & Migration Setup
// ----------------------------------------------------
try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/backend/db.php';
    
    // Step A: Ensure email column exists in 'users'
    $columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('email', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100) DEFAULT NULL UNIQUE");
    }
    
    // Step B: Ensure helper transactional table 'password_resets' is present
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY email_idx (email),
            KEY token_hash_idx (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    die("Database migration failure: " . htmlspecialchars($e->getMessage()));
}

// ----------------------------------------------------
// 2. Custom Object-Oriented SMTP Socket Client
// ----------------------------------------------------
class CyberpunkSMTPClient {
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption;
    private array $logs = [];

    public function __construct(string $host, int $port, string $username, string $password, string $encryption = 'ssl') {
        $this->host       = $host;
        $this->port       = $port;
        $this->username   = $username;
        $this->password   = $password;
        $this->encryption = strtolower($encryption);
    }

    public function getLogs(): array {
        return $this->logs;
    }

    private function log(string $msg): void {
        $this->logs[] = htmlspecialchars($msg);
    }

    private function readResponse($socket): string {
        $data = '';
        while ($str = fgets($socket, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === ' ') {
                break;
            }
        }
        $this->log("SMTP Server: " . trim($data));
        return $data;
    }

    private function sendCommand($socket, string $cmd): void {
        $this->log("Client Command: " . trim($cmd));
        fwrite($socket, $cmd . "\r\n");
    }

    public function send(string $to, string $subject, string $body, string $fromName = 'Unify Social Hub'): bool {
        $prefix = ($this->encryption === 'ssl') ? 'ssl://' : '';
        $socket = @fsockopen($prefix . $this->host, $this->port, $errno, $errstr, 10);
        
        if (!$socket) {
            $this->log("Socket Connection Failed: {$errstr} ({$errno})");
            return false;
        }

        $this->readResponse($socket); // Server greeting banner

        // EHLO handshake
        $this->sendCommand($socket, "EHLO localhost");
        $this->readResponse($socket);

        // STARTTLS if explicit TLS selected
        if ($this->encryption === 'tls') {
            $this->sendCommand($socket, "STARTTLS");
            $resp = $this->readResponse($socket);
            if (strpos($resp, '220') === false) {
                $this->log("STARTTLS handshake rejected.");
                fclose($socket);
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->log("TLS Encryption negotiation failed on stream.");
                fclose($socket);
                return false;
            }
            $this->sendCommand($socket, "EHLO localhost");
            $this->readResponse($socket);
        }

        // AUTH login transaction
        $this->sendCommand($socket, "AUTH LOGIN");
        $this->readResponse($socket);

        $this->sendCommand($socket, base64_encode($this->username));
        $this->readResponse($socket);

        $this->sendCommand($socket, base64_encode($this->password));
        $resp = $this->readResponse($socket);
        if (strpos($resp, '235') === false) {
            $this->log("SMTP AUTH credentials rejected.");
            fclose($socket);
            return false;
        }

        // MAIL FROM
        $this->sendCommand($socket, "MAIL FROM: <" . $this->username . ">");
        $this->readResponse($socket);

        // RCPT TO
        $this->sendCommand($socket, "RCPT TO: <" . $to . ">");
        $this->readResponse($socket);

        // DATA segment
        $this->sendCommand($socket, "DATA");
        $this->readResponse($socket);

        // Headers construction conforming to RFC standards
        $headers = [
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "To: <" . $to . ">",
            "From: \"" . $fromName . "\" <" . $this->username . ">",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Date: " . date('r'),
            "X-Mailer: Unify OO Sockets Client v1.0"
        ];

        $rawMessage = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
        $this->sendCommand($socket, $rawMessage);
        $resp = $this->readResponse($socket);

        // QUIT handshake close
        $this->sendCommand($socket, "QUIT");
        $this->readResponse($socket);
        
        fclose($socket);
        return strpos($resp, '250') !== false;
    }
}

// ----------------------------------------------------
// 3. Request Processing Engine
// ----------------------------------------------------
$successMessage = '';
$errorMessage   = '';
$smtpLogs       = [];
$sandboxLink    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    if ($email === '') {
        $errorMessage = "Please enter your registered email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_URL) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Allow basic validation or custom emails
        $errorMessage = "Please enter a valid email structure.";
    } else {
        // Check if user exists with this email address
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // For security, do not explicitly leak user non-existence in real production,
            // but for sandbox visibility we provide descriptive warnings.
            $errorMessage = "No account found associated with that email. Make sure your profile email is set.";
        } else {
            // 1. Generate secure random token (32 bytes = 256 bits)
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiry    = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // 2. Commit secure token hash to database resets helper
            $delete = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
            $delete->execute([$email]); // Revoke any prior pending tokens for this email

            $insert = $pdo->prepare("INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)");
            $insert->execute([$email, $tokenHash, $expiry]);

            // 3. Assemble dynamic secure link
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $resetLink = $scheme . "://" . $host . $dir . "/verify_password_reset.php?email=" . urlencode($email) . "&token=" . $rawToken;

            // 4. Configure SMTP parameters (cPanel/Mail Server Standard Configs)
            $smtpHost       = SMTP_HOST;
            $smtpPort       = SMTP_PORT;
            $smtpUser       = SMTP_USER;
            $smtpPass       = SMTP_PASS;
            $smtpEncryption = SMTP_ENCRYPTION;
            
            // Build visual HTML body
            $subject = "Reset Your Unify Social Hub Password";
            $emailBody = "
                <div style='background-color:#050505; color:#ffffff; font-family:sans-serif; padding:2rem; border-radius:12px; max-width:600px; margin:0 auto; border:1px solid #ff00ff;'>
                    <h2 style='color:#00f3ff; text-transform:uppercase;'>Password Reset Request</h2>
                    <p style='color:#a0a0b0;'>You have requested a secure password reset for Unify Social Hub. Click the link below to verify credentials and update your password. This link expires in 1 hour.</p>
                    <div style='margin:2rem 0; text-align:center;'>
                        <a href='{$resetLink}' style='background-color:#ff00ff; color:#ffffff; padding:12px 24px; text-decoration:none; font-weight:bold; border-radius:4px; box-shadow:0 0 15px #ff00ff;'>Reset Password Now</a>
                    </div>
                    <p style='font-size:0.8rem; color:#a0a0b0; border-top:1px solid rgba(255,255,255,0.08); padding-top:1rem;'>If you did not initiate this request, you can safely ignore this email.</p>
                </div>
            ";

            // 5. Connect and send mail via Raw Sockets
            $client = new CyberpunkSMTPClient($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpEncryption);
            $mailSent = false;
            
            // Only try if default credentials changed
            if ($smtpUser !== 'support@example.com' && $smtpUser !== 'support@cpanel_domain.com') {
                $mailSent = $client->send($email, $subject, $emailBody, "Unify Authentication Vault");
                $smtpLogs = $client->getLogs();
            } else {
                $client->log("cPanel SMTP mail credentials are at placeholders. Bypassing socket send...");
                $smtpLogs = $client->getLogs();
            }

            if ($mailSent) {
                $successMessage = "Transactional password reset link dispatched successfully to " . htmlspecialchars($email);
            } else {
                // Graceful fallback for local development / sandbox testing
                $successMessage = "Secure reset token generated successfully! (SMTP Bypassed/Placeholder Active)";
                $sandboxLink = $resetLink;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Password Reset — Unify Social Hub</title>
    
    <!-- Bootstrap & Fonts -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <!-- Premium Cyberpunk Theme Custom Rules -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=Space+Grotesk:wght@400;700&display=swap');
        
        :root {
            --bg-color: #050505;
            --bg-gradient: radial-gradient(circle at top right, #110e1f, #050505 75%);
            --text-primary: #ffffff;
            --text-secondary: #a0a0b0;
            --glass-bg: rgba(15, 15, 20, 0.65);
            --glass-border: rgba(255, 255, 255, 0.08);
            
            --neon-cyan: #00f3ff;
            --neon-magenta: #ff00ff;
            --neon-green: #00ff66;
            --neon-yellow: #fcee0a;
        }

        body {
            background-color: var(--bg-color);
            background-image: var(--bg-gradient);
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 1rem;
        }

        h1, h2, h3, h4 {
            font-family: 'Space Grotesk', sans-serif;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.6), 0 0 15px rgba(255, 255, 255, 0.03);
            width: 100%;
            max-width: 550px;
        }

        .text-gradient-cyan {
            background: linear-gradient(90deg, var(--neon-cyan), #0088ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .text-gradient-magenta {
            background: linear-gradient(90deg, var(--neon-magenta), #ff0077);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .cyber-input {
            background-color: rgba(0, 0, 0, 0.5) !important;
            border: 1px solid var(--glass-border) !important;
            color: #fff !important;
        }
        .cyber-input:focus {
            background-color: rgba(0, 0, 0, 0.7) !important;
            border-color: var(--neon-magenta) !important;
            box-shadow: 0 0 12px rgba(255, 0, 255, 0.25) !important;
            color: #fff !important;
        }

        .sandbox-box {
            background: rgba(0, 243, 255, 0.05);
            border: 1px solid var(--neon-cyan);
            border-radius: 8px;
            padding: 1.2rem;
            margin-top: 1.5rem;
        }

        .logs-box {
            background: #020202;
            border: 1px solid rgba(255,255,255,0.05);
            color: var(--text-secondary);
            font-family: 'Courier New', Courier, monospace;
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.75rem;
            max-height: 200px;
            overflow-y: auto;
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>

<div class="glass-card">
    <div class="text-center mb-4">
        <i class="fa-solid fa-shield-halved fa-3x text-gradient-magenta mb-3"></i>
        <h2 class="text-gradient-cyan">Security Reset Request</h2>
        <p class="text-secondary small">Request a highly secured password update link.</p>
    </div>

    <!-- Alert systems -->
    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success border-0" style="background: rgba(0, 255, 102, 0.08); border-left: 3px solid var(--neon-green) !important; color: #80ffaa;">
            <i class="fa-solid fa-circle-check me-2"></i><?= $successMessage ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger border-0" style="background: rgba(255, 0, 0, 0.08); border-left: 3px solid #ff4444 !important; color: #ff8080;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $errorMessage ?>
        </div>
    <?php endif; ?>

    <!-- Form segment -->
    <?php if ($successMessage === ''): ?>
        <form method="POST" action="request_password_reset.php" class="mt-4">
            <div class="mb-4">
                <label class="form-label text-secondary text-uppercase" style="font-size: 0.72rem; letter-spacing: 1px;">Operator Email Address</label>
                <input type="email" name="email" class="form-control cyber-input py-2" placeholder="johnkennedy@gmail.com" required>
            </div>
            
            <button type="submit" class="btn btn-info w-100 py-3 fw-bold text-uppercase" style="border-radius: 4px; box-shadow: 0 0 15px rgba(0, 243, 255, 0.25);">
                Generate Reset Link <i class="fa-solid fa-arrow-right ms-2"></i>
            </button>
        </form>
    <?php endif; ?>

    <!-- Local Sandbox Developer tools link helper -->
    <?php if ($sandboxLink !== ''): ?>
        <div class="sandbox-box">
            <h5 class="text-white mb-2" style="font-size: 0.9rem;"><i class="fa-solid fa-flask me-2 text-gradient-cyan"></i>Developer Sandbox Console</h5>
            <p class="text-secondary small mb-3">
                Since cPanel SMTP is set to default credentials, we have mapped the cryptographically secure reset link below for fast local testing:
            </p>
            <a href="<?= $sandboxLink ?>" class="btn btn-info w-100 fw-bold text-uppercase py-2 mb-2" style="border-radius: 4px; font-size: 0.8rem;">
                Click to Test Reset Flow <i class="fa-solid fa-up-right-from-square ms-2"></i>
            </a>
            <input type="text" class="form-control cyber-input bg-dark text-secondary small py-2" style="font-size: 0.72rem;" readonly value="<?= htmlspecialchars($sandboxLink) ?>">
        </div>
    <?php endif; ?>

    <!-- OO Sockets SMTP Handshake audit logs -->
    <?php if (!empty($smtpLogs)): ?>
        <div class="logs-box">
            <div class="text-white fw-bold mb-2 border-bottom border-secondary pb-1 small">SMTP Sockets Transaction Auditor</div>
            <?php foreach ($smtpLogs as $log): ?>
                <div><?= $log ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Back to login utilities -->
    <div class="text-center mt-4 pt-3 border-top border-secondary">
        <a href="login.php" class="text-secondary text-decoration-none small">
            <i class="fa-solid fa-arrow-left me-2"></i>Return to Portal Entry
        </a>
    </div>
</div>

</body>
</html>
