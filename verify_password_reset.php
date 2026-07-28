<?php
/**
 * MediaFusion - SMTP-Driven Secure Password Reset Verification
 * 
 * CORE VERIFICATION DIRECTIVES:
 * 1. Safe Parameter Capture: Validates email and raw token from URL query string.
 * 2. Hash Verification: Recomputes SHA-256 token hash and matches database record.
 * 3. Expiration Constraints: Confirms the reset request is within the 1-hour window (expires_at > NOW()).
 * 4. DB Integrity Updates: Updates user's password_hash securely using native PASSWORD_DEFAULT.
 * 5. Replay Attack Prevention: Immediately deletes all reset tokens for the verified email upon success.
 * 6. Automated Redirection: Offers premium interface with dynamic count redirection back to login.php.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) {
    // Session is active
} else {
    session_start();
}

try {
    require_once __DIR__ . '/backend/db.php';
} catch (Exception $e) {
    die("Database bootstrapping failed: " . htmlspecialchars($e->getMessage()));
}

// Extract credentials
$email    = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
$rawToken = isset($_GET['token']) ? trim((string)$_GET['token']) : '';

$errorMessage = '';
$successMessage = '';
$isValidRequest = false;
$tokenHash = hash('sha256', $rawToken);

// ----------------------------------------------------
// 1. Transaction Validation Check
// ----------------------------------------------------
if ($email === '' || $rawToken === '') {
    $errorMessage = "Invalid verification request parameters. Please verify your link.";
} else {
    // Query resets helper to find valid non-expired entries
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ? AND token_hash = ? AND expires_at > NOW()");
    $stmt->execute([$email, $tokenHash]);
    $resetRequest = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetRequest) {
        $errorMessage = "This security reset token has either expired or is invalid. Please request a new link.";
    } else {
        $isValidRequest = true;
    }
}

// ----------------------------------------------------
// 2. Commit Updates POST Actions
// ----------------------------------------------------
if ($isValidRequest && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPass = $_POST['new_password'] ?? '';
    $confPass = $_POST['confirm_password'] ?? '';

    if (strlen($newPass) < 6) {
        $errorMessage = "New password must be at least 6 characters in length.";
    } elseif ($newPass !== $confPass) {
        $errorMessage = "Passwords do not match. Please verify your inputs.";
    } else {
        // Safe, native cryptographic hashing
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);

        try {
            $pdo->beginTransaction();

            // Step A: Update user password
            $update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
            $update->execute([$newHash, $email]);

            // Step B: Expunge reset tokens to prevent replay attacks (critical security protocol)
            $delete = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
            $delete->execute([$email]);

            $pdo->commit();

            $successMessage = "Your password has been successfully updated. Redirecting to login portal shortly...";
            $isValidRequest = false; // Disable form render
        } catch (Exception $e) {
            $pdo->rollBack();
            $errorMessage = "Transaction failed: " . htmlspecialchars($e->getMessage());
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Password Reset — MediaFusion</title>
    
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
            border-color: var(--neon-cyan) !important;
            box-shadow: 0 0 12px rgba(0, 243, 255, 0.25) !important;
            color: #fff !important;
        }
    </style>
</head>
<body>

<div class="glass-card">
    <div class="text-center mb-4">
        <i class="fa-solid fa-key fa-3x text-gradient-cyan mb-3"></i>
        <h2 class="text-gradient-magenta">Credential Verification</h2>
        <p class="text-secondary small">Finalize updates for operator account safety.</p>
    </div>

    <!-- Alert systems -->
    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success border-0 mb-4" style="background: rgba(0, 255, 102, 0.08); border-left: 3px solid var(--neon-green) !important; color: #80ffaa;">
            <i class="fa-solid fa-circle-check me-2"></i><?= $successMessage ?>
        </div>
        
        <script>
            // Automated redirect to portal entry after 3 seconds
            setTimeout(() => {
                window.location.href = "login.php";
            }, 3000);
        </script>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger border-0 mb-4" style="background: rgba(255, 0, 0, 0.08); border-left: 3px solid #ff4444 !important; color: #ff8080;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $errorMessage ?>
        </div>
    <?php endif; ?>

    <!-- Form segment -->
    <?php if ($isValidRequest): ?>
        <form method="POST" action="verify_password_reset.php?email=<?= urlencode($email) ?>&token=<?= urlencode($rawToken) ?>" class="mt-4">
            <div class="mb-3">
                <label class="form-label text-secondary text-uppercase small" style="letter-spacing: 1px;">New Operator Password</label>
                <input type="password" name="new_password" class="form-control cyber-input py-2" placeholder="Minimum 6 characters" required autocomplete="new-password">
            </div>

            <div class="mb-4">
                <label class="form-label text-secondary text-uppercase small" style="letter-spacing: 1px;">Confirm Operator Password</label>
                <input type="password" name="confirm_password" class="form-control cyber-input py-2" placeholder="Re-type new password" required autocomplete="new-password">
            </div>
            
            <button type="submit" class="btn btn-info w-100 py-3 fw-bold text-uppercase" style="border-radius: 4px; box-shadow: 0 0 15px rgba(0, 243, 255, 0.25);">
                Update Password and Terminate Tokens <i class="fa-solid fa-square-check ms-2"></i>
            </button>
        </form>
    <?php endif; ?>

    <!-- Back to login utilities -->
    <div class="text-center mt-4 pt-3 border-top border-secondary">
        <a href="login.php" class="text-secondary text-decoration-none small">
            Return to Portal Entry <i class="fa-solid fa-arrow-right ms-2"></i>
        </a>
    </div>
</div>

</body>
</html>
