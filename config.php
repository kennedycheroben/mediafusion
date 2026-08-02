<?php
/**
 * MediaFusion - Core Configuration
 * 
 * CORE ARCHITECTURAL SECURITY:
 * 1. Env Loader: Lightweight pure-PHP environment loader registers process-level variables.
 * 2. DB Encapsulation: Pulls active DB connection metrics from environment controls.
 * 3. Meta Credentials Hook: Dynamically overrides Instagram and Facebook parameters from .env.
 * 4. SMTP Transactional Constants: Registers custom cPanel SMTP configurations for secure resets.
 */

declare(strict_types=1);

// --- Performance: Output buffering for faster page load ---
if (function_exists('ob_start')) {
    if (!headers_sent()) {
        ob_start('ob_gzhandler', 8192);
    }
}

// --- Performance: Optimize PHP runtime for high concurrency ---
@ini_set('output_buffering', '4096');
@ini_set('zlib.output_compression', '16384');

// --- Security: Session cookie attributes (applied before session_start) ---
@ini_set('session.use_only_cookies', '1');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.use_strict_mode', '1');
@ini_set('session.cookie_samesite', 'Lax');

/**
 * Minimal ".env simulation" loader.
 * - Optional file: /opt/lampp/htdocs/MediaFusion/.env
 * - Populates $_ENV for all variables.
 * - Only exposes safe (non-secret) prefixes to child processes via putenv().
 * - Secrets (DB_*, API keys, OAuth secrets, SMTP_*) are available only through
 *   PHP constants and $_ENV, never leaked to child processes (e.g. FFmpeg).
 */
function MEDIAFUSION_load_env_file(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    // Prefixes safe to expose to child processes (no secrets)
    // S3_*: Object storage config (not secrets, needed by s3_client.php via getenv())
    // APP_*: Application config
    // MEDIAFUSION_*: Redirect URIs and app-level settings
    // PHP_*: PHP runtime settings
    $safePutenvPrefixes = ['S3_', 'APP_', 'MEDIAFUSION_', 'PHP_'];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;
        $key = trim(substr($line, 0, $eqPos));
        $val = trim(substr($line, $eqPos + 1));
        if ($key === '') continue;
        if (($val[0] ?? '') === '"' && str_ends_with($val, '"')) $val = substr($val, 1, -1);
        if (($val[0] ?? '') === "'" && str_ends_with($val, "'")) $val = substr($val, 1, -1);

        // Real process environment takes precedence over .env so deployments,
        // workers, and tests can override local file defaults safely.
        $existing = getenv($key);
        if (array_key_exists($key, $_ENV) || $existing !== false) {
            if (!array_key_exists($key, $_ENV) && $existing !== false) {
                $_ENV[$key] = $existing;
            }
            continue;
        }

        // Always populate $_ENV (readable via $_ENV[] and getenv() in same process)
        $_ENV[$key] = $val;

        // Only expose safe prefixes to child processes via putenv()
        $exposed = false;
        foreach ($safePutenvPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                putenv($key . '=' . $val);
                $exposed = true;
                break;
            }
        }
        // DB and MAIL vars are read via PHP constants only — never leaked to children
    }
}

// Instantiate environment loader
MEDIAFUSION_load_env_file(__DIR__ . '/.env');

/**
 * Retrieve environment variable checking $_ENV first, then getenv(), and falling back to default.
 */
function MEDIAFUSION_get_env(string $key, $default = '') {
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    $val = getenv($key);
    return $val !== false ? $val : $default;
}

// Debug mode: only enabled when APP_DEBUG=true is explicitly set in environment
define('APP_DEBUG', (MEDIAFUSION_get_env('APP_DEBUG') ?: '') === 'true');

function MEDIAFUSION_is_https(): bool {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}



/**
 * Detect absolute URL for an arbitrary script in the app (e.g. callback.php)
 * Falls back to localhost path when server variables are not available.
 */
function MEDIAFUSION_detect_script_uri(string $script = 'callback.php'): string {
    if (!isset($_SERVER['HTTP_HOST'])) {
        return 'http://localhost/MediaFusion/' . ltrim($script, '/');
    }
    $scheme = MEDIAFUSION_is_https() ? 'https' : 'http';
    $host = (string)$_SERVER['HTTP_HOST'];
    
    // Calculate the subdirectory relative to the document root
    $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
    $projRoot = str_replace('\\', '/', __DIR__);
    
    $basePath = '';
    if ($docRoot !== '' && str_starts_with($projRoot, $docRoot)) {
        $basePath = substr($projRoot, strlen($docRoot));
    } else {
        // Fallback: use directory name of script but strip subdirectories if we are inside them
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if (str_ends_with($basePath, '/backend')) {
            $basePath = substr($basePath, 0, -8);
        } elseif (str_ends_with($basePath, '/includes')) {
            $basePath = substr($basePath, 0, -9);
        }
    }
    
    $basePath = '/' . ltrim(str_replace('\\', '/', $basePath), '/');
    $basePath = rtrim($basePath, '/');
    
    $path = $basePath === '' ? '/' . ltrim($script, '/') : $basePath . '/' . ltrim($script, '/');
    return $scheme . '://' . $host . $path;
}


// ---- Production overrides: when running on the live domain, prefer explicit production credentials
$appHostEnv = MEDIAFUSION_get_env('APP_HOST') ?: (parse_url(MEDIAFUSION_get_env('APP_URL'), PHP_URL_HOST) ?: '');
$productionHost = $appHostEnv ?: 'fusionmedia.top';
$hostLower = strtolower($_SERVER['HTTP_HOST'] ?? '');
if (($productionHost !== '' && strpos($hostLower, strtolower($productionHost)) !== false) || MEDIAFUSION_get_env('MEDIAFUSION_FORCE_PRODUCTION') === '1') {
    // Only set these if they are not already provided by environment (.env or server)
    $prodBase = 'https://' . $productionHost;
    if (MEDIAFUSION_get_env('MEDIAFUSION_OAUTH_REDIRECT_URI') === '') putenv('MEDIAFUSION_OAUTH_REDIRECT_URI=' . $prodBase . '/callback.php');
    if (MEDIAFUSION_get_env('TIKTOK_REDIRECT_URI') === '') putenv('TIKTOK_REDIRECT_URI=' . $prodBase . '/callback.php');
    if (MEDIAFUSION_get_env('META_REDIRECT_URI') === '') putenv('META_REDIRECT_URI=' . $prodBase . '/callback_meta.php');
    if (MEDIAFUSION_get_env('GOOGLE_REDIRECT_URI') === '') putenv('GOOGLE_REDIRECT_URI=' . $prodBase . '/backend/google_auth.php');
}

// 1. Dynamic Database Connection Constants
define('DB_HOST', MEDIAFUSION_get_env('DB_HOST') ?: MEDIAFUSION_get_env('MYSQLHOST') ?: 'localhost');
define('DB_PORT', MEDIAFUSION_get_env('DB_PORT') ?: MEDIAFUSION_get_env('MYSQLPORT') ?: '3306');
define('DB_USER', MEDIAFUSION_get_env('DB_USER') ?: MEDIAFUSION_get_env('MYSQLUSER') ?: 'root');
define('DB_PASS', MEDIAFUSION_get_env('DB_PASS') ?: MEDIAFUSION_get_env('MYSQLPASSWORD') ?: '');
define('DB_NAME', MEDIAFUSION_get_env('DB_NAME') ?: MEDIAFUSION_get_env('MYSQLDATABASE') ?: 'mediafusion');

// 2. Dynamic Meta / Instagram API Credentials
define('FB_APP_ID', MEDIAFUSION_get_env('FB_APP_ID') ?: MEDIAFUSION_get_env('MEDIAFUSION_FB_APP_ID') ?: '');
define('FB_APP_SECRET', MEDIAFUSION_get_env('FB_APP_SECRET') ?: MEDIAFUSION_get_env('MEDIAFUSION_FB_APP_SECRET') ?: '');
define('IG_APP_ID', MEDIAFUSION_get_env('IG_APP_ID') ?: MEDIAFUSION_get_env('MEDIAFUSION_IG_APP_ID') ?: '');
define('IG_APP_SECRET', MEDIAFUSION_get_env('IG_APP_SECRET') ?: MEDIAFUSION_get_env('MEDIAFUSION_IG_APP_SECRET') ?: '');

// 3. Dynamic Google / YouTube API Credentials
define('GOOGLE_CLIENT_ID', MEDIAFUSION_get_env('GOOGLE_CLIENT_ID') ?: MEDIAFUSION_get_env('YOUTUBE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', MEDIAFUSION_get_env('GOOGLE_CLIENT_SECRET') ?: MEDIAFUSION_get_env('YOUTUBE_CLIENT_SECRET') ?: '');
define('YOUTUBE_CLIENT_ID', MEDIAFUSION_get_env('YOUTUBE_CLIENT_ID') ?: '');
define('YOUTUBE_CLIENT_SECRET', MEDIAFUSION_get_env('YOUTUBE_CLIENT_SECRET') ?: '');
// Google Sign-In redirect URI (points to the google_auth callback handler)
define('GOOGLE_REDIRECT_URI', MEDIAFUSION_get_env('GOOGLE_REDIRECT_URI') ?: MEDIAFUSION_detect_script_uri('backend/google_auth.php'));

// 4. Dynamic TikTok API Sandbox Credentials
// REDIRECT_URI is used as the OAuth callback for Google/TikTok flows. Default to the detected callback URL.
define('REDIRECT_URI', MEDIAFUSION_get_env('TIKTOK_REDIRECT_URI') ?: MEDIAFUSION_detect_script_uri('callback.php'));
define('TIKTOK_CLIENT_KEY', MEDIAFUSION_get_env('TIKTOK_CLIENT_KEY') ?: '');
define('TIKTOK_CLIENT_SECRET', MEDIAFUSION_get_env('TIKTOK_CLIENT_SECRET') ?: '');

// 5. Instagram Access Token
define('IG_TEMP_TOKEN', MEDIAFUSION_get_env('MEDIAFUSION_IG_TEMP_TOKEN') ?: '');

// 6. Dynamic Meta API Integration Keys (Unified FB & IG)
define('META_APP_ID', MEDIAFUSION_get_env('META_APP_ID') ?: '');
define('META_APP_SECRET', MEDIAFUSION_get_env('META_APP_SECRET') ?: '');
define('META_CONFIG_ID', MEDIAFUSION_get_env('META_CONFIG_ID') ?: '');
define('META_REDIRECT_URI', MEDIAFUSION_get_env('META_REDIRECT_URI') ?: MEDIAFUSION_detect_script_uri('callback_meta.php'));
define('META_GRAPH_VERSION', MEDIAFUSION_get_env('META_GRAPH_VERSION') ?: 'v25.0');

// 7. Dynamic SMTP Transactional Settings
define('SMTP_HOST', MEDIAFUSION_get_env('SMTP_HOST') ?: 'mail.example.com');
define('SMTP_PORT', (int)(MEDIAFUSION_get_env('SMTP_PORT') ?: '465'));
define('SMTP_USER', MEDIAFUSION_get_env('SMTP_USER') ?: '');
define('SMTP_PASS', MEDIAFUSION_get_env('SMTP_PASS') ?: '');
define('SMTP_ENCRYPTION', MEDIAFUSION_get_env('SMTP_ENCRYPTION') ?: 'ssl');

// 8. Security Configuration Constants
define('APP_IS_PRODUCTION', (MEDIAFUSION_get_env('APP_ENV') ?: '') === 'production');
define('APP_IS_HTTPS', MEDIAFUSION_is_https());

// Session security
define('SESSION_IDLE_TIMEOUT',      (int)(MEDIAFUSION_get_env('SESSION_IDLE_TIMEOUT') ?: '1800'));   // 30 minutes
define('SESSION_ABSOLUTE_TIMEOUT',  (int)(MEDIAFUSION_get_env('SESSION_ABSOLUTE_TIMEOUT') ?: '28800')); // 8 hours
define('SESSION_REGEN_INTERVAL',    (int)(MEDIAFUSION_get_env('SESSION_REGEN_INTERVAL') ?: '300'));  // 5 minutes

// 9. Token Encryption Key (AES-256-GCM, 32 bytes = 64 hex chars)
define('ENCRYPTION_KEY', MEDIAFUSION_get_env('ENCRYPTION_KEY') ?: '');

// Security logging
define('SECURITY_LOG_FILE', __DIR__ . '/logs/security.log');
define('APP_LOG_DIR', __DIR__ . '/logs');

// Trusted proxy configuration
define('TRUST_PROXY_HEADERS', (MEDIAFUSION_get_env('TRUST_PROXY_HEADERS') ?: '') === 'true');
?>
