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

/**
 * Minimal ".env simulation" loader.
 * - Optional file: /opt/lampp/htdocs/mediafusion/.env
 * - Never prints secrets; only sets process env via putenv().
 */
function mediafusion_load_env_file(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

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
        
        // Put in environment
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
}

// Instantiate environment loader
mediafusion_load_env_file(__DIR__ . '/.env');

function mediafusion_is_https(): bool {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

function mediafusion_detect_redirect_uri(): string {
    // If explicitly configured, use exact string (console must match exactly).
    $forced = getenv('MEDIAFUSION_OAUTH_REDIRECT_URI') ?: getenv('TIKTOK_REDIRECT_URI');
    if (is_string($forced) && $forced !== '') {
        return $forced;
    }
    // Derive from current request (http vs https matters for Meta).
    if (!isset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME'])) {
        return 'http://localhost/mediafusion/connect.php';
    }
    $scheme = mediafusion_is_https() ? 'https' : 'http';
    $host = (string)$_SERVER['HTTP_HOST'];
    $basePath = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
    $path = $basePath === '' ? '/connect.php' : $basePath . '/connect.php';
    return $scheme . '://' . $host . $path;
}

/**
 * Detect absolute URL for an arbitrary script in the app (e.g. callback.php)
 * Falls back to localhost path when server variables are not available.
 */
function mediafusion_detect_script_uri(string $script = 'callback.php'): string {
    if (!isset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME'])) {
        return 'http://localhost/mediafusion/' . ltrim($script, '/');
    }
    $scheme = mediafusion_is_https() ? 'https' : 'http';
    $host = (string)$_SERVER['HTTP_HOST'];
    $basePath = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
    $path = $basePath === '' ? '/' . ltrim($script, '/') : $basePath . '/' . ltrim($script, '/');
    return $scheme . '://' . $host . $path;
}

// ---- Production overrides: when running on the live domain, prefer explicit production credentials
$productionHost = 'fusionmedia.top';
$hostLower = strtolower($_SERVER['HTTP_HOST'] ?? '');
if (strpos($hostLower, $productionHost) !== false || getenv('MEDIAFUSION_FORCE_PRODUCTION') === '1') {
    // Only set these if they are not already provided by environment (.env or server)
    if (strpos($hostLower, $productionHost) !== false || getenv('RAILWAY_ENVIRONMENT') !== false) {
    
    $prodBase = 'https://' . $productionHost;
    if (getenv('MEDIAFUSION_OAUTH_REDIRECT_URI') === false) putenv('MEDIAFUSION_OAUTH_REDIRECT_URI=' . $prodBase . '/callback.php');
    if (getenv('TIKTOK_REDIRECT_URI') === false) putenv('TIKTOK_REDIRECT_URI=' . $prodBase . '/callback.php');
    if (getenv('META_REDIRECT_URI') === false) putenv('META_REDIRECT_URI=' . $prodBase . '/callback_meta.php');
    
}('DB_NAME=if0_42025520_mediafusion');

    $prodBase = 'https://' . $productionHost;
    if (getenv('MEDIAFUSION_OAUTH_REDIRECT_URI') === false) putenv('MEDIAFUSION_OAUTH_REDIRECT_URI=' . $prodBase . '/callback.php');
    if (getenv('TIKTOK_REDIRECT_URI') === false) putenv('TIKTOK_REDIRECT_URI=' . $prodBase . '/callback.php');
    if (getenv('META_REDIRECT_URI') === false) putenv('META_REDIRECT_URI=' . $prodBase . '/callback_meta.php');
}

// 1. Dynamic Database Connection Constants
define('DB_HOST', getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: '3306');
define('DB_USER', getenv('DB_USER') ?: getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : (getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : ''));
define('DB_NAME', getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'railway');
// 2. Dynamic Meta / Instagram API Credentials
define('FB_APP_ID', getenv('FB_APP_ID') ?: getenv('MEDIAFUSION_FB_APP_ID') ?: '');
define('FB_APP_SECRET', getenv('FB_APP_SECRET') ?: getenv('MEDIAFUSION_FB_APP_SECRET') ?: '');
define('IG_APP_ID', getenv('IG_APP_ID') ?: getenv('MEDIAFUSION_IG_APP_ID') ?: '');
define('IG_APP_SECRET', getenv('IG_APP_SECRET') ?: getenv('MEDIAFUSION_IG_APP_SECRET') ?: '');

// 3. Dynamic Google / YouTube API Credentials
define('YOUTUBE_CLIENT_ID', getenv('YOUTUBE_CLIENT_ID') ?: '903707729051-g7dlb4g53b907mv4mpbok22tao5rmmml.apps.googleusercontent.com');
define('YOUTUBE_CLIENT_SECRET', getenv('YOUTUBE_CLIENT_SECRET') ?: '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');

// 4. Dynamic TikTok API Sandbox Credentials
// REDIRECT_URI is used as the OAuth callback for Google/TikTok flows. Default to the detected callback URL.
define('REDIRECT_URI', getenv('TIKTOK_REDIRECT_URI') ?: mediafusion_detect_script_uri('callback.php'));
define('TIKTOK_CLIENT_KEY', getenv('TIKTOK_CLIENT_KEY') ?: 'sbaww7xjk9qim5act5');
define('TIKTOK_CLIENT_SECRET', getenv('TIKTOK_CLIENT_SECRET') ?: 'IMzS614FNuWf9e9jNflA1lQLpNVVv2fj');

// 5. Instagram Access Token
define('IG_TEMP_TOKEN', getenv('MEDIAFUSION_IG_TEMP_TOKEN') ?: '');

// 6. Dynamic Meta API Integration Keys (Meta FB & IG)
define('META_APP_ID', getenv('META_APP_ID') ?: '950500347761069'); 
define('META_APP_SECRET', getenv('META_APP_SECRET') ?: 'f64ae46693cbaecf191eb0a31417df5e');
define('META_CONFIG_ID', getenv('META_CONFIG_ID') ?: '1731252727876526');
define('META_REDIRECT_URI', getenv('META_REDIRECT_URI') ?: mediafusion_detect_script_uri('callback_meta.php'));
define('META_GRAPH_VERSION', getenv('META_GRAPH_VERSION') ?: 'v25.0');

// 7. Dynamic SMTP Transactional Settings
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'mail.example.com');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: '465'));
define('SMTP_USER', getenv('SMTP_USER') ?: 'support@example.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'superSecurePassword123');
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'ssl');
?>
