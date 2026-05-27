<?php
// Meta OAuth 2.0 Authorization Redirect Endpoint

require_once 'config.php';
session_start();

// CSRF Security: Generate or retrieve state token
if (empty($_SESSION['meta_oauth_state'])) {
    $_SESSION['meta_oauth_state'] = bin2hex(random_bytes(16));
}

// Parameter Assembly for Meta Business App
$params = [
    'client_id'    => META_APP_ID,
    'config_id'    => META_CONFIG_ID,
    'redirect_uri' => META_REDIRECT_URI,
    'state'        => $_SESSION['meta_oauth_state'],
    'response_type'=> 'code',
    // Static, comma‑separated scope list
    'scope'        => 'public_profile,email,pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish',
    'auth_type'    => 'rerequest',
    'prompt'       => 'select_account'
];

// Construct authorization URL
$auth_url = 'https://www.facebook.com/' . META_GRAPH_VERSION . '/dialog/oauth?' . http_build_query($params);

// Redirect user to Meta OAuth dialog
header('Location: ' . $auth_url);
exit;
?>
