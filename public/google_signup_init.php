<?php
/**
 * Stores the Tenant role in session and redirects to Google OAuth.
 * All Google sign-ups create a Tenant account by default.
 * Users can request an upgrade to Landlord from their dashboard.
 */
require_once __DIR__ . '/../app/bootstrap.php';
verify_csrf();

if (empty($config['google']['client_id'])) {
    $_SESSION['flash_error'] = 'Google OAuth is not configured.';
    redirect('register.php');
}

// All self-registered accounts start as Tenant
$_SESSION['google_signup_role'] = ROLE_TENANT;

$params = http_build_query([
    'client_id'     => $config['google']['client_id'],
    'redirect_uri'  => $config['google']['redirect_uri'],
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
