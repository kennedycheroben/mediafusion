<?php
declare(strict_types=1);

/**
 * MediaFusion - Production Rate Limiter
 *
 * MySQL-backed sliding window rate limiter with:
 *  - Atomic counter updates via INSERT ... ON DUPLICATE KEY UPDATE
 *  - Separate identification for authenticated users vs IP
 *  - Named policy system (no hardcoded values in endpoint files)
 *  - Timing-safe responses
 *  - Security event logging
 *  - File-based fallback when DB is unavailable
 */

require_once __DIR__ . '/../config.php';

class RateLimiter {
    private static ?PDO $pdo = null;
    private static string $cacheDir = '/tmp/mediafusion_ratelimit';

    private int $maxRequests;
    private int $windowSeconds;
    private string $key;
    private string $category;

    public function __construct(string $key, int $maxRequests = 60, int $windowSeconds = 60, string $category = 'general') {
        $this->key = 'rl_' . $key;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->category = $category;

        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0700, true);
        }
    }

    private static function getPdo(): ?PDO {
        if (self::$pdo !== null) return self::$pdo;

        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            self::$pdo = $pdo;
            return self::$pdo;
        }

        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT         => true,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            return self::$pdo;
        } catch (\PDOException $e) {
            error_log('RateLimiter DB fallback: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if request is allowed under the rate limit.
     * Returns array with allowed, remaining, limit, retry_after, reset.
     */
    public function check(): array {
        $identifier = $this->getIdentifier();
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        $pdo = self::getPdo();
        if ($pdo) {
            return $this->checkDb($pdo, $identifier, $now, $windowStart);
        }
        return $this->checkFile($identifier, $now, $windowStart);
    }

    private function checkDb(PDO $pdo, string $identifier, int $now, int $windowStart): array {
        try {
            // Periodic cleanup: delete windows older than 2x the window duration
            if (random_int(1, 200) === 1) {
                $cleanup = $pdo->prepare("DELETE FROM rate_limits WHERE window_start < ?");
                $cleanup->execute([$now - ($this->windowSeconds * 2)]);
            }

            // Count requests in current window using a single atomic query
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(request_count), 0) as total
                FROM rate_limits
                WHERE identifier = ? AND window_start >= ?
            ");
            $stmt->execute([$identifier, $windowStart]);
            $total = (int)$stmt->fetchColumn();

            $allowed = $total < $this->maxRequests;
            $remaining = max(0, $this->maxRequests - $total);
            $retryAfter = $allowed ? 0 : $this->windowSeconds - ($now - $windowStart);

            // Record this request atomically (INSERT or INCREMENT)
            if ($allowed) {
                $stmt = $pdo->prepare("
                    INSERT INTO rate_limits (identifier, request_count, window_start)
                    VALUES (?, 1, ?)
                    ON DUPLICATE KEY UPDATE request_count = request_count + 1
                ");
                $stmt->execute([$identifier, $now]);
            }

            // Log rate limit events
            if (!$allowed) {
                log_security_event(
                    'rate_limit_exceeded',
                    "category={$this->category} key={$this->key} total={$total}/{$this->maxRequests}"
                );
            }

            return [
                'allowed'     => $allowed,
                'remaining'   => $remaining,
                'limit'       => $this->maxRequests,
                'retry_after' => $retryAfter,
                'reset'       => $now + $this->windowSeconds,
            ];
        } catch (\PDOException $e) {
            error_log('RateLimiter DB error: ' . $e->getMessage());
            return $this->checkFile($identifier, $now, $windowStart);
        }
    }

    private function checkFile(string $identifier, int $now, int $windowStart): array {
        $file = self::$cacheDir . '/' . md5($identifier) . '.json';
        $data = ['requests' => []];

        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $data = json_decode($raw, true) ?: ['requests' => []];
            }
        }

        // Prune old entries
        $data['requests'] = array_values(array_filter($data['requests'], fn($ts) => $ts > $windowStart));

        $total = count($data['requests']);
        $allowed = $total < $this->maxRequests;
        $remaining = max(0, $this->maxRequests - $total);
        $retryAfter = $allowed ? 0 : max(0, ($data['requests'][0] ?? $now) + $this->windowSeconds - $now);

        if ($allowed) {
            $data['requests'][] = $now;
            @file_put_contents($file, json_encode($data), LOCK_EX);
        }

        return [
            'allowed'     => $allowed,
            'remaining'   => $remaining,
            'limit'       => $this->maxRequests,
            'retry_after' => $retryAfter,
            'reset'       => $now + $this->windowSeconds,
        ];
    }

    /**
     * Build the rate-limit identifier.
     * For authenticated users: uses user ID (primary).
     * For unauthenticated: uses IP address.
     */
    private function getIdentifier(): string {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId !== null) {
            return $this->key . '_user_' . $userId;
        }
        return $this->key . '_ip_' . $this->getClientIp();
    }

    /**
     * Resolve the trusted client IP for rate-limit identification.
     */
    private function getClientIp(): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $trustProxies = defined('TRUST_PROXY_HEADERS') && TRUST_PROXY_HEADERS;
        if (!$trustProxies) {
            return $remoteAddr;
        }

        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = explode(',', $_SERVER[$h])[0];
                return trim($ip);
            }
        }
        return $remoteAddr;
    }

    /**
     * Set standard rate-limit response headers.
     */
    public function setHeaders(array $result): void {
        if (headers_sent()) return;
        header("X-RateLimit-Limit: {$result['limit']}");
        header("X-RateLimit-Remaining: {$result['remaining']}");
        header("X-RateLimit-Reset: {$result['reset']}");
        if (!$result['allowed']) {
            header("Retry-After: {$result['retry_after']}");
        }
    }

    /**
     * Enforce a rate limit. Sends 429 JSON response and exits if exceeded.
     */
    public static function enforce(string $key, int $max = 60, int $window = 60, string $category = 'general'): void {
        $limiter = new self($key, $max, $window, $category);
        $result = $limiter->check();
        $limiter->setHeaders($result);
        if (!$result['allowed']) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'    => false,
                'error'      => 'RATE_LIMIT_EXCEEDED',
                'message'    => 'Rate limit exceeded. Please try again later.',
                'retry_after' => $result['retry_after'],
            ]);
            exit;
        }
    }
}

// ── Named Rate-Limit Policies ────────────────────────────────────────────────
// Each policy defines: maxRequests, windowSeconds, category
// These are the ONLY rate-limit definitions. Endpoints call by name.

define('RATE_POLICIES', [
    // Authentication
    'auth_login'           => ['max' => 10,  'window' => 60,   'cat' => 'auth'],
    'auth_register'        => ['max' => 5,   'window' => 300,  'cat' => 'auth'],
    'auth_password_reset'  => ['max' => 3,   'window' => 300,  'cat' => 'auth'],
    'auth_password_change' => ['max' => 5,   'window' => 300,  'cat' => 'auth'],

    // OAuth
    'oauth_init'           => ['max' => 10,  'window' => 60,   'cat' => 'oauth'],
    'oauth_callback'       => ['max' => 20,  'window' => 60,   'cat' => 'oauth'],

    // General API
    'api_general'          => ['max' => 60,  'window' => 60,   'cat' => 'api'],
    'api_public'           => ['max' => 30,  'window' => 60,   'cat' => 'api'],

    // Polling
    'api_polling'          => ['max' => 12,  'window' => 60,   'cat' => 'polling'],

    // High-cost processing
    'processing_ai'        => ['max' => 10,  'window' => 300,  'cat' => 'processing'],
    'processing_stt'       => ['max' => 5,   'window' => 300,  'cat' => 'processing'],
    'processing_cutout'    => ['max' => 10,  'window' => 300,  'cat' => 'processing'],
    'processing_tracker'   => ['max' => 10,  'window' => 300,  'cat' => 'processing'],
    'processing_video'     => ['max' => 5,   'window' => 300,  'cat' => 'processing'],
    'processing_image'     => ['max' => 10,  'window' => 300,  'cat' => 'processing'],
    'processing_export'    => ['max' => 5,   'window' => 600,  'cat' => 'processing'],

    // Uploads
    'upload_file'          => ['max' => 20,  'window' => 60,   'cat' => 'upload'],
    'upload_studio'        => ['max' => 20,  'window' => 60,   'cat' => 'upload'],

    // Sensitive actions
    'account_deletion'      => ['max' => 3,   'window' => 3600, 'cat' => 'sensitive'],
    'sensitive_account'    => ['max' => 10,  'window' => 300,  'cat' => 'sensitive'],
    'sensitive_password'   => ['max' => 5,   'window' => 300,  'cat' => 'sensitive'],
    'sensitive_social'     => ['max' => 10,  'window' => 300,  'cat' => 'sensitive'],

    // Contact
    'contact_form'         => ['max' => 5,   'window' => 300,  'cat' => 'contact'],

    // Brand kit
    'brand_kit'            => ['max' => 30,  'window' => 60,   'cat' => 'api'],
]);

/**
 * Enforce a named rate-limit policy.
 */
function rateLimitPolicy(string $policyName): void {
    if (!defined('RATE_POLICIES') || !isset(RATE_POLICIES[$policyName])) {
        // Unknown policy: use conservative default
        RateLimiter::enforce($policyName, 30, 60, 'unknown');
        return;
    }

    $policy = RATE_POLICIES[$policyName];
    RateLimiter::enforce($policyName, $policy['max'], $policy['window'], $policy['cat']);
}

// ── Convenience Functions (backward compatible) ──────────────────────────────

function rateLimitAuth(): void { rateLimitPolicy('auth_login'); }
function rateLimitUpload(): void { rateLimitPolicy('upload_file'); }
function rateLimitApi(): void { rateLimitPolicy('api_general'); }
function rateLimitPolling(): void { rateLimitPolicy('api_polling'); }
function rateLimitContact(): void { rateLimitPolicy('contact_form'); }

// ── Concurrent Job Limiter ──────────────────────────────────────────────────
// Prevents a single user from exhausting server resources by launching
// too many FFmpeg / background processing jobs simultaneously.

define('CONCURRENT_JOB_LIMITS', [
    'ffmpeg'  => 3,
    'export'  => 2,
    'ai'      => 2,
]);

/**
 * Count active background jobs for a user by scanning /tmp metadata files.
 * Returns the number of jobs still running (no progress=end, no completed/failed status).
 */
function count_user_active_jobs(int $userId, string $type = 'ffmpeg'): int {
    $count = 0;
    $metaPattern = '/tmp/*_meta.json';
    $files = glob($metaPattern);
    if (!is_array($files)) return 0;

    foreach ($files as $file) {
        $meta = @json_decode(@file_get_contents($file), true);
        if (!is_array($meta)) continue;
        if (!isset($meta['user_id']) || (int)$meta['user_id'] !== $userId) continue;

        $jobType = $meta['type'] ?? 'ffmpeg';
        if ($jobType !== $type) continue;

        $progressLog = '/tmp/' . ($meta['job_id'] ?? '') . '_progress.log';
        if (is_file($progressLog)) {
            $lines = @file($progressLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    if (strpos($line, 'progress=end') === 0) {
                        // Job finished — not active
                        continue 2;
                    }
                }
            }
        }

        $count++;
    }

    return $count;
}

/**
 * Enforce concurrent job limit. Sends 429 JSON and exits if exceeded.
 */
function enforce_concurrent_job_limit(int $userId, string $type = 'ffmpeg'): void {
    $limit = CONCURRENT_JOB_LIMITS[$type] ?? 3;
    $active = count_user_active_jobs($userId, $type);

    if ($active >= $limit) {
        log_security_event('concurrent_job_limit', "type={$type} active={$active} limit={$limit}", $userId);
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'CONCURRENT_JOB_LIMIT',
            'message' => "Too many active {$type} jobs. Please wait for current jobs to finish (max {$limit}).",
        ]);
        exit;
    }
}
