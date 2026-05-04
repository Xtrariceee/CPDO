<?php
require_once __DIR__ . '/../app/bootstrap.php';
verify_csrf();

$email = strtolower(trim($_POST['email'] ?? $_GET['email'] ?? ($_SESSION['pending_verification_email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND status = "ACTIVE"');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION['flash_error'] = 'No active account was found for that email.';
    } elseif ($action === 'resend') {
        $otpSent = issue_user_otp((int)$user['id'], $user['email'], user_full_name($user));
        $_SESSION['pending_verification_email'] = $user['email'];
        if ($otpSent) {
            $_SESSION['flash_success'] = 'A new OTP has been sent. It expires in 5 minutes.';
        } else {
            $_SESSION['flash_error'] = 'We could not resend the OTP email. Please try again later or contact support.';
        }
    } else {
        $otp = trim($_POST['otp_code'] ?? '');
        if (!preg_match('/^\d{6}$/', $otp)) {
            $_SESSION['flash_error'] = 'Enter the 6-digit OTP.';
        } elseif ((int)$user['is_verified']) {
            $_SESSION['flash_success'] = 'Your email is already verified. You can log in.';
            redirect('login.php');
        } elseif (!hash_equals((string)$user['otp_code'], $otp)) {
            audit_log((int)$user['id'], 'OTP_VERIFY_FAILED', 'users', (int)$user['id']);
            $_SESSION['flash_error'] = 'Invalid OTP.';
        } elseif (strtotime((string)$user['otp_expiry']) < time()) {
            audit_log((int)$user['id'], 'OTP_EXPIRED', 'users', (int)$user['id']);
            $_SESSION['flash_error'] = 'OTP expired. Please request a new code.';
        } else {
            $update = db()->prepare('UPDATE users SET is_verified = 1, otp_code = NULL, otp_expiry = NULL WHERE id = ?');
            $update->execute([(int)$user['id']]);
            audit_log((int)$user['id'], 'EMAIL_VERIFIED', 'users', (int)$user['id']);
            unset($_SESSION['pending_verification_email']);
            $_SESSION['flash_success'] = 'Email verified. You can now log in.';
            redirect('login.php');
        }
    }
}

require __DIR__ . '/partials/header.php';
?>
<section class="auth-shell compact">
    <div class="auth-panel">
        <p class="eyebrow">Email Verification</p>
        <h1>Enter your 6-digit OTP</h1>
        <p>The code expires after 5 minutes to protect your account.</p>
    </div>
    <form class="auth-card" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label class="form-label">Email</label>
        <input class="form-control mb-3" type="email" name="email" value="<?= e($email) ?>" required>
        <label class="form-label">OTP Code</label>
        <input class="form-control otp-input mb-3" name="otp_code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000">
        <button class="btn btn-primary w-100" name="action" value="verify">Verify Account</button>
        <button class="btn btn-link w-100 mt-2" name="action" value="resend">Resend OTP</button>
        <p class="text-center mt-3 mb-0"><a href="login.php">Back to login</a></p>
    </form>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
