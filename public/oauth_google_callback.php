<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (empty($config['google']['client_id']) || empty($_GET['code'])) {
    $_SESSION['flash_error'] = 'Google OAuth is not configured.';
    redirect('login.php');
}

// Determine if this is a sign-up flow (came from register page with a role)
$signupRole = null;
if (!empty($_SESSION['google_signup_role'])) {
    $signupRole = $_SESSION['google_signup_role'];
    unset($_SESSION['google_signup_role']);
}

// Exchange code for access token
$tokenPayload = http_build_query([
    'code'          => $_GET['code'],
    'client_id'     => $config['google']['client_id'],
    'client_secret' => $config['google']['client_secret'],
    'redirect_uri'  => $config['google']['redirect_uri'],
    'grant_type'    => 'authorization_code',
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => $tokenPayload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$tokenResponse = curl_exec($ch);
curl_close($ch);
$token = json_decode((string)$tokenResponse, true);

if (empty($token['access_token'])) {
    $_SESSION['flash_error'] = 'Google sign-in failed. Please try again.';
    redirect('login.php');
}

// Fetch Google profile
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
]);
$profileResponse = curl_exec($ch);
curl_close($ch);
$profile = json_decode((string)$profileResponse, true);

if (empty($profile['email']) || empty($profile['sub'])) {
    $_SESSION['flash_error'] = 'Google profile could not be verified.';
    redirect('login.php');
}

$googleEmail = strtolower(trim($profile['email']));
$googleSub   = $profile['sub'];

// Look up existing user
$stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR google_id = ? LIMIT 1');
$stmt->execute([$googleEmail, $googleSub]);
$user = $stmt->fetch();

/* ─────────────────────────────────────────────────────────────
   SIGN-UP FLOW  (came from register page with a role selected)
   ───────────────────────────────────────────────────────────── */
if ($signupRole !== null) {
    $allowedSelfRoles = [ROLE_LANDLORD, ROLE_TENANT];
    if (!in_array($signupRole, $allowedSelfRoles, true)) {
        $_SESSION['flash_error'] = 'Invalid account type selected.';
        redirect('register.php');
    }

    if ($user) {
        // Account already exists — link Google ID if not yet linked, then log in
        if (empty($user['google_id'])) {
            db()->prepare('UPDATE users SET google_id = ?, google_registered_role = ? WHERE id = ?')
               ->execute([$googleSub, $signupRole, (int)$user['id']]);
        }
        // Fall through to login logic below
    } else {
        // Create new account via Google sign-up
        $fullName   = trim((string)($profile['name'] ?? ''));
        $parts      = preg_split('/\s+/', $fullName) ?: [];
        $firstName  = $parts[0] ?? 'Google';
        $lastName   = count($parts) > 1 ? $parts[count($parts) - 1] : 'User';
        $middleName = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : null;

        $pdo    = db();
        $insert = $pdo->prepare(
            'INSERT INTO users (first_name, middle_name, last_name, email, google_id, role, google_registered_role, is_verified)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $insert->execute([$firstName, $middleName, $lastName, $googleEmail, $googleSub, $signupRole, $signupRole]);
        $userId = (int)$pdo->lastInsertId();

        $user = [
            'id'         => $userId,
            'first_name' => $firstName,
            'middle_name'=> $middleName,
            'last_name'  => $lastName,
            'email'      => $googleEmail,
            'is_verified'=> 0,
            'role'       => $signupRole,
        ];

        audit_log($userId, 'GOOGLE_SIGNUP', 'users', $userId, ['role' => $signupRole]);
    }

    $userId = (int)$user['id'];

    // Send OTP if not yet verified
    if (!(int)$user['is_verified']) {
        $otpSent = issue_user_otp($userId, $googleEmail, user_full_name($user));
        $_SESSION['pending_verification_email'] = $googleEmail;
        audit_log($userId, 'GOOGLE_SIGNUP_PENDING_OTP', 'users', $userId);
        $_SESSION['flash_success'] = $otpSent
            ? 'Google account registered. Enter the OTP sent to your email to verify.'
            : 'Account created, but we could not send the OTP email. Please use the Resend OTP button on the next page.';
        redirect('verify_otp.php');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    audit_log($userId, 'GOOGLE_LOGIN_SUCCESS', 'users', $userId);
    redirect(dashboard_for_role($user['role']));
}

/* ─────────────────────────────────────────────────────────────
   SIGN-IN FLOW  (came from login page — must already be registered)
   ───────────────────────────────────────────────────────────── */
if (!$user) {
    // Google account not registered — block sign-in
    $_SESSION['flash_error'] = 'No account found for this Google address. Please register first and choose your account type.';
    redirect('register.php');
}

// Account exists but google_registered_role is NULL → was auto-created without explicit role selection
// Require them to set their role before proceeding
if (empty($user['google_registered_role']) && empty($user['password_hash'])) {
    // Pure Google account with no role set — send to a role-selection step
    $_SESSION['google_pending_role_user_id'] = (int)$user['id'];
    $_SESSION['flash_error'] = 'Please complete your registration by selecting an account type.';
    redirect('register.php?google_complete=1&email=' . urlencode($googleEmail));
}

$userId = (int)$user['id'];

// Link Google ID if not yet linked
if (empty($user['google_id'])) {
    db()->prepare('UPDATE users SET google_id = ? WHERE id = ?')->execute([$googleSub, $userId]);
}

// Require OTP verification
if (!(int)$user['is_verified']) {
    $otpSent = issue_user_otp($userId, $googleEmail, user_full_name($user));
    $_SESSION['pending_verification_email'] = $googleEmail;
    audit_log($userId, 'GOOGLE_LOGIN_PENDING_OTP', 'users', $userId);
    $_SESSION['flash_success'] = $otpSent
        ? 'Google account connected. Enter the OTP sent to your email before logging in.'
        : 'Google account connected, but the OTP email could not be sent. Use the Resend OTP button below.';
    redirect('verify_otp.php');
}

// Check account is active
if ($user['status'] !== 'ACTIVE') {
    audit_log($userId, 'LOGIN_BLOCKED_DISABLED', 'users', $userId);
    $_SESSION['flash_error'] = 'Your account has been disabled. Contact support.';
    redirect('login.php');
}

$fresh = db()->prepare('SELECT role FROM users WHERE id = ?');
$fresh->execute([$userId]);
session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
audit_log($userId, 'GOOGLE_LOGIN_SUCCESS', 'users', $userId);
redirect(dashboard_for_role((string)$fresh->fetchColumn()));
