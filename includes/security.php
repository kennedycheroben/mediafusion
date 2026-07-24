<?php
/**
 * MediaFusion - Centralized Security Helpers
 *
 * Provides:
 *  - CSRF token generation and verification (timing-safe)
 *  - Security header helpers
 *  - Security event logging
 *  - Input sanitization helpers
 */

// Prevent direct access
if (!defined('APP_IS_PRODUCTION')) {
    require_once __DIR__ . '/../config.php';
}

// ── CSRF Protection ──────────────────────────────────────────────────────────

/**
 * Generate or retrieve the CSRF token for the current session.
 * Regenerates periodically based on SESSION_REGEN_INTERVAL.
 */
function generate_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $now = time();
    $lastGen = $_SESSION['csrf_token_generated_at'] ?? 0;
    $interval = defined('SESSION_REGEN_INTERVAL') ? SESSION_REGEN_INTERVAL : 300;

    // Regenerate token periodically or if not set
    if (empty($_SESSION['csrf_token']) || ($now - $lastGen) > $interval) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_generated_at'] = $now;
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify a CSRF token using timing-safe comparison.
 * Returns false for missing, empty, or invalid tokens.
 */
function verify_csrf_token(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate a hidden HTML input field containing the CSRF token.
 */
function csrf_field(): string {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Generate a meta tag for CSRF token (for AJAX use via window.csrfToken).
 */
function csrf_meta(): string {
    $token = generate_csrf_token();
    return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Extract CSRF token from request (POST body, X-CSRF-Token header, or X-XSRF-TOKEN header).
 */
function extract_csrf_token(): string {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (empty($token)) {
        $token = $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '';
    }

    if (empty($token)) {
        $token = $_POST['csrf_token'] ?? '';
    }

    return $token;
}

/**
 * Validate CSRF on state-changing requests. Sends 403 JSON and exits on failure.
 * Use in POST/PUT/PATCH/DELETE handlers.
 */
function require_csrf(): void {
    $token = extract_csrf_token();
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'CSRF_TOKEN_INVALID',
            'message' => 'Security token is missing or invalid. Please refresh the page.'
        ]);
        exit;
    }
}

// ── Security Headers ─────────────────────────────────────────────────────────

/**
 * Send security headers for API responses.
 */
function send_security_headers(): void {
    if (headers_sent()) return;

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    if (defined('APP_IS_PRODUCTION') && APP_IS_PRODUCTION) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Set CORS headers for API endpoints.
 * Restricts to same-origin by default.
 */
function send_api_headers(): void {
    if (headers_sent()) return;
    header('Content-Type: application/json; charset=utf-8');
    send_security_headers();
}

// ── Security Event Logging ───────────────────────────────────────────────────

/**
 * Log a security event to the centralized security log.
 *
 * @param string $event  Event category (e.g. 'auth_failure', 'rate_limit', 'csrf_failure')
 * @param string $detail Human-readable detail
 * @param int    $userId Optional user ID
 * @param string $ip     Optional IP address
 */
function log_security_event(string $event, string $detail = '', ?int $userId = null, ?string $ip = null): void {
    $logDir = defined('APP_LOG_DIR') ? APP_LOG_DIR : __DIR__ . '/../logs';
    $logFile = defined('SECURITY_LOG_FILE') ? SECURITY_LOG_FILE : $logDir . '/security.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }

    if ($ip === null) {
        $ip = resolve_client_ip();
    }
    if ($userId === null && isset($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
    }

    $entry = sprintf(
        "[%s] [%s] IP=%s USER=%s DETAIL=%s\n",
        date('Y-m-d H:i:s'),
        $event,
        $ip,
        $userId !== null ? $userId : '-',
        $detail
    );

    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// ── IP Resolution ────────────────────────────────────────────────────────────

/**
 * Resolve the trusted client IP address.
 * Only trusts proxy headers when TRUST_PROXY_HEADERS is enabled.
 */
function resolve_client_ip(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $trustProxies = defined('TRUST_PROXY_HEADERS') && TRUST_PROXY_HEADERS;
    if (!$trustProxies) {
        return $remoteAddr;
    }

    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = explode(',', $_SERVER[$h])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $remoteAddr;
}

// ── Input Sanitization ───────────────────────────────────────────────────────

/**
 * Sanitize a string for safe use in output.
 */
function sanitize_output(string $input): string {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate that a value is a positive integer.
 */
function is_positive_int($value): bool {
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
}
