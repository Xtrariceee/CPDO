<?php
/**
 * Stores the selected role in session and redirects to Google OAuth.
 * Called from the register page when user clicks "Sign up with Google".
 */
require_once __DIR__ . '/../app/bootstrap.php';
verify_csrf();

$role             = $_POST['role'] ?? '';
$allowedSelfRoles = [ROLE_LANDLORD, ROLE_TENANT];

if (!in_array($role, $allowedSelfRoles, true)) {
    $_SESSION['flash_error'] = 'Invalid account type selected.';
    redirect('register.php');
}

if (empty($config['google']['client_id'])) {
    $_SESSION['flash_error'] = 'Google OAuth is not configured.';
    redirect('register.php');
}

// Store role in session so the callback knows this is a sign-up
$_SESSION['google_signup_role'] = $role;

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
