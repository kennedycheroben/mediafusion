<?php
declare(strict_types=1);

/**
 * backend/bootstrap.php
 *
 * Centralized security bootstrap for MediaFusion.
 *
 * Provides in a single require:
 *  - Environment configuration (config.php)
 *  - Hardened session management with lifecycle validation
 *  - Database access ($pdo via db.php)
 *  - CSRF helper functions (security.php)
 *  - Rate limiting (rate_limit.php)
 *  - Authentication guard helpers
 *  - Security headers
 *  - Security event logging
 *
 * Usage in backend AJAX handlers:
 *   require_once __DIR__ . '/bootstrap.php';
 *   $userId = requireAuth();
 *   require_csrf();
 *   rateLimitApi();
 *
 * Usage in frontend page scripts (already have header.php for auth):
 *   require_once __DIR__ . '/backend/bootstrap.php';
 *   // CSRF functions now available for forms
 *
 * CLI tools (no session needed):
 *   Do NOT use this file. Require config.php and db.php directly.
 */

// ── 1. Environment & Constants ───────────────────────────────────────────────
require_once __DIR__ . '/../config.php';

// ── 2. Centralized Session Bootstrap ─────────────────────────────────────────
mediafusion_session_start();

// ── 3. Database Connection ───────────────────────────────────────────────────
require_once __DIR__ . '/db.php';

// ── 4. CSRF Protection ───────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/security.php';

// ── 5. Rate Limiting ─────────────────────────────────────────────────────────
require_once __DIR__ . '/rate_limit.php';

// ── 6. Storage Abstraction ───────────────────────────────────────────────────
require_once __DIR__ . '/storage/autoload.php';
require_once __DIR__ . '/storage/config.php';

// ── Session Lifecycle Functions ───────────────────────────────────────────────

/**
 * Start a session with hardened security settings.
 * Prevents session fixation, sets secure cookie attributes,
 * and validates session lifecycle (idle/absolute timeout).
 */
function mediafusion_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Configure isolated session save path if writable
    $username = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'default') : 'default';
    $sessionDir = sys_get_temp_dir() . '/mediafusion_sessions_' . preg_replace('/[^a-zA-Z0-9]/', '', $username);
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        @ini_set('session.save_path', $sessionDir);
    }

    // Set secure cookie parameters before starting session
    $isHttps = defined('APP_IS_HTTPS') && APP_IS_HTTPS;

    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.use_trans_sid', '0');
    @ini_set('session.sid_length', '48');
    @ini_set('session.sid_bits_per_character', '6');

    session_start();

    // Validate session lifecycle after start
    validate_session_lifecycle();
}

/**
 * Validate session idle timeout and absolute lifetime.
 * Destroys session and redirects to login if expired.
 */
function validate_session_lifecycle(): void {
    $idleTimeout    = defined('SESSION_IDLE_TIMEOUT') ? SESSION_IDLE_TIMEOUT : 1800;
    $absoluteTimeout = defined('SESSION_ABSOLUTE_TIMEOUT') ? SESSION_ABSOLUTE_TIMEOUT : 28800;

    $now = time();

    // Only validate for authenticated sessions
    if (empty($_SESSION['user_id'])) {
        return;
    }

    // Check absolute lifetime (session creation time)
    $createdAt = $_SESSION['session_created_at'] ?? 0;
    if ($createdAt > 0 && ($now - $createdAt) > $absoluteTimeout) {
        destroy_session('absolute_timeout');
        header('Location: /login.php');
        exit;
    }

    // Check idle timeout (last activity)
    $lastActivity = $_SESSION['last_activity'] ?? 0;
    if ($lastActivity > 0 && ($now - $lastActivity) > $idleTimeout) {
        destroy_session('idle_timeout');
        header('Location: /login.php');
        exit;
    }

    // Update last activity timestamp
    $_SESSION['last_activity'] = $now;

    // Regenerate session ID periodically to prevent fixation
    $lastRegen = $_SESSION['last_session_regen'] ?? 0;
    $regenInterval = defined('SESSION_REGEN_INTERVAL') ? SESSION_REGEN_INTERVAL : 300;
    if (($now - $lastRegen) > $regenInterval) {
        regenerate_session_id();
    }
}

/**
 * Regenerate session ID securely. Called periodically or after privilege changes.
 */
function regenerate_session_id(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    session_regenerate_id(true);
    $_SESSION['last_session_regen'] = time();
}

/**
 * Initialize session metadata after successful authentication.
 * Called after login, registration, or OAuth callback.
 */
function init_session_metadata(int $userId): void {
    global $pdo;
    $now = time();

    $_SESSION['user_id'] = $userId;
    $_SESSION['session_created_at'] = $now;
    $_SESSION['last_activity'] = $now;
    $_SESSION['last_session_regen'] = $now;
    $_SESSION['login_ip'] = resolve_client_ip();

    // Regenerate CSRF token upon successful authentication
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_generated_at'] = $now;

    // Store current security_version from DB so revoke checks compare correctly
    try {
        $stmt = $pdo->prepare("SELECT security_version FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['session_version'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $_SESSION['session_version'] = 0;
    }

    // Regenerate session ID to prevent fixation
    regenerate_session_id();
}

/**
 * Destroy session completely. Called on logout, timeout, or security events.
 *
 * @param string $reason Reason for destruction (for logging)
 */
function destroy_session(string $reason = 'logout'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $userId = $_SESSION['user_id'] ?? null;
    $sessionId = session_id();

    // Log the session destruction
    if ($userId !== null) {
        log_security_event('session_destroyed', "reason={$reason} session={$sessionId}", (int)$userId);
    }

    // Unset all session variables
    $_SESSION = [];

    // Delete the session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?? '/',
                'domain'   => $params['domain'] ?? '',
                'secure'   => $params['secure'] ?? false,
                'httponly'  => true,
                'samesite' => 'Lax',
            ]
        );
    }

    // Destroy the session on the server
    session_destroy();
}

/**
 * Invalidate all sessions for a specific user by rotating a security version.
 * Use this when: password changes, security events, "log out all sessions".
 */
function invalidate_all_user_sessions(int $userId, string $reason = 'security_event'): void {
    global $pdo;

    try {
        $stmt = $pdo->prepare("UPDATE users SET security_version = security_version + 1 WHERE id = ?");
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        error_log("Failed to increment security_version for user {$userId}: " . $e->getMessage());
    }

    log_security_event('all_sessions_invalidated', "reason={$reason}", $userId);
}

/**
 * Check if the current session's security version matches the database.
 * Returns true if valid, false if the session has been revoked.
 */
function is_session_security_valid(int $userId): bool {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT security_version FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $dbVersion = (int)$stmt->fetchColumn();

        $sessionVersion = $_SESSION['session_version'] ?? 0;

        // Session is valid only if DB version exactly matches session version.
        // If invalidate_all_user_sessions() incremented the DB value,
        // all old sessions will fail this check and be revoked.
        return $dbVersion === $sessionVersion;
    } catch (Throwable $e) {
        error_log("Security version check failed for user {$userId}: " . $e->getMessage());
        return true; // Fail-open for availability (log but don't lock out)
    }
}

// ── Authentication Guard Functions ────────────────────────────────────────────

/**
 * Require an authenticated session. Returns the user ID.
 * Sends appropriate JSON or redirect response and exits if not authenticated.
 * Also validates session security version.
 */
function requireAuth(): int {
    if (empty($_SESSION['user_id'])) {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
               && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
        } else {
            header('Location: /login.php');
        }
        exit;
    }

    $userId = (int)$_SESSION['user_id'];

    // Validate session hasn't been revoked
    if (!is_session_security_valid($userId)) {
        destroy_session('security_revocation');
        log_security_event('session_revoked', 'security_version_mismatch', $userId);
        header('Location: /login.php');
        exit;
    }

    return $userId;
}

/**
 * Require administrator privileges. Returns the user ID.
 * Sends 403 JSON response and exits if not admin.
 */
function requireAdmin(): int {
    $userId = requireAuth();
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $isAdmin = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('requireAdmin check failed: ' . $e->getMessage());
        $isAdmin = 0;
    }
    if (empty($isAdmin)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: Administrator privileges required.']);
        exit;
    }
    return $userId;
}

/**
 * Get the current session user ID, or null if not authenticated.
 * Does not exit or send any response.
 */
function get_session_user_id(): ?int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

// ── Custom Session Handler (PHP 8.1+ Strict Mode) ───────────────────────────
