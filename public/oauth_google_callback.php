<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (empty($config['google']['client_id']) || empty($_GET['code'])) {
    $_SESSION['flash_error'] = 'Google OAuth is not configured.';
    redirect('login.php');
}

$tokenPayload = http_build_query([
    'code' => $_GET['code'],
    'client_id' => $config['google']['client_id'],
    'client_secret' => $config['google']['client_secret'],
    'redirect_uri' => $config['google']['redirect_uri'],
    'grant_type' => 'authorization_code',
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => $tokenPayload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$tokenResponse = curl_exec($ch);
curl_close($ch);
$token = json_decode((string)$tokenResponse, true);

if (empty($token['access_token'])) {
    $_SESSION['flash_error'] = 'Google sign-in failed.';
    redirect('login.php');
}

$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token']],
]);
$profileResponse = curl_exec($ch);
curl_close($ch);
$profile = json_decode((string)$profileResponse, true);

if (empty($profile['email']) || empty($profile['sub'])) {
    $_SESSION['flash_error'] = 'Google profile could not be verified.';
    redirect('login.php');
}

$stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR google_id = ? LIMIT 1');
$stmt->execute([$profile['email'], $profile['sub']]);
$user = $stmt->fetch();

if (!$user) {
    $fullName = trim((string)($profile['name'] ?? ''));
    $parts = preg_split('/\s+/', $fullName) ?: [];
    $firstName = $parts[0] ?? 'Google';
    $lastName = count($parts) > 1 ? $parts[count($parts) - 1] : 'User';
    $middleName = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : null;
    $insert = db()->prepare('INSERT INTO users (first_name, middle_name, last_name, email, google_id, role, is_verified) VALUES (?, ?, ?, ?, ?, ?, 0)');
    $insert->execute([$firstName, $middleName, $lastName, strtolower($profile['email']), $profile['sub'], ROLE_LANDLORD]);
    $userId = (int)db()->lastInsertId();
    $user = [
        'id' => $userId,
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'email' => strtolower($profile['email']),
        'is_verified' => 0,
    ];
} else {
    $userId = (int)$user['id'];
    if (empty($user['google_id'])) {
        $update = db()->prepare('UPDATE users SET google_id = ? WHERE id = ?');
        $update->execute([$profile['sub'], $userId]);
    }
}

if (!(int)$user['is_verified']) {
    $otpSent = issue_user_otp($userId, (string)$user['email'], user_full_name($user));
    $_SESSION['pending_verification_email'] = $user['email'];
    audit_log($userId, 'GOOGLE_LOGIN_PENDING_OTP', 'users', $userId);
    if ($otpSent) {
        $_SESSION['flash_success'] = 'Google account connected. Enter the OTP sent to your email before logging in.';
    } else {
        $_SESSION['flash_error'] = 'Google account connected, but we could not send the OTP email. Please try again later.';
    }
    redirect('verify_otp.php');
}

$fresh = db()->prepare('SELECT role FROM users WHERE id = ?');
$fresh->execute([$userId]);
session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
audit_log($userId, 'GOOGLE_LOGIN_SUCCESS', 'users', $userId);
redirect(dashboard_for_role((string)$fresh->fetchColumn()));
