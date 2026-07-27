<?php
// Meta OAuth 2.0 Authorization Redirect Endpoint
// Supports independent Facebook-only or Instagram-only OAuth flows.

require_once __DIR__ . '/backend/bootstrap.php';

// ── Authentication required ──────────────────────────────────────────────────
$userId = requireAuth();

// Determine which platform this OAuth flow is for.
// Accepted values: 'facebook' (default) | 'instagram'
$platform = $_GET['platform'] ?? 'facebook';
if (!in_array($platform, ['facebook', 'instagram'], true)) {
    $platform = 'facebook';
}

// Persist the platform intent so callback_meta.php knows which token row to write.
$_SESSION['meta_pending_platform'] = $platform;

// CSRF Security: Generate or retrieve state token
if (empty($_SESSION['meta_oauth_state'])) {
    $_SESSION['meta_oauth_state'] = bin2hex(random_bytes(16));
}

// Per-platform scope sets
// Facebook: pages management + posting
// Instagram: Instagram content publishing (still needs pages_show_list to resolve IG account)
$scopesByPlatform = [
    'facebook'  => 'public_profile,email,pages_show_list,pages_read_engagement,pages_manage_posts',
    'instagram' => 'public_profile,instagram_basic,instagram_content_publish,pages_show_list',
];

// Parameter Assembly for Meta Business App
$params = [
    'client_id'    => META_APP_ID,
    'config_id'    => META_CONFIG_ID,
    'redirect_uri' => META_REDIRECT_URI,
    'state'        => $_SESSION['meta_oauth_state'],
    'response_type'=> 'code',
    'scope'        => $scopesByPlatform[$platform],
    'auth_type'    => 'rerequest',
    'prompt'       => 'select_account',
];

// Construct authorization URL
$auth_url = 'https://www.facebook.com/' . META_GRAPH_VERSION . '/dialog/oauth?' . http_build_query($params);

// Redirect user to Meta OAuth dialog
header('Location: ' . $auth_url);
exit;
