<?php
require_once __DIR__ . '/../app/bootstrap.php';
verify_csrf();

$email = strtolower(trim($_POST['email'] ?? $_GET['email'] ?? ($_SESSION['pending_verification_email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';
    $stmt   = db()->prepare('SELECT * FROM users WHERE email = ? AND status = "ACTIVE"');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION['flash_error'] = 'No active account was found for that email.';
    } elseif ($action === 'resend') {
        $otpSent = issue_user_otp((int)$user['id'], $user['email'], user_full_name($user));
        $_SESSION['pending_verification_email'] = $user['email'];
        $_SESSION[$otpSent ? 'flash_success' : 'flash_error'] = $otpSent
            ? 'A new OTP has been sent. It expires in 5 minutes.'
            : 'Could not resend the OTP email. Please try again later.';
    } else {
        $otp = trim($_POST['otp_code'] ?? '');
        if (!preg_match('/^\d{6}$/', $otp)) {
            $_SESSION['flash_error'] = 'Enter the 6-digit OTP.';
        } elseif ((int)$user['is_verified']) {
            $_SESSION['flash_success'] = 'Your email is already verified. You can log in.';
            redirect('login.php');
        } elseif (!hash_equals((string)$user['otp_code'], $otp)) {
            audit_log((int)$user['id'], 'OTP_VERIFY_FAILED', 'users', (int)$user['id']);
            $_SESSION['flash_error'] = 'Invalid OTP. Check your email and try again.';
        } elseif (strtotime((string)$user['otp_expiry']) < time()) {
            audit_log((int)$user['id'], 'OTP_EXPIRED', 'users', (int)$user['id']);
            $_SESSION['flash_error'] = 'OTP expired. Request a new code below.';
        } else {
            db()->prepare('UPDATE users SET is_verified = 1, otp_code = NULL, otp_expiry = NULL WHERE id = ?')
               ->execute([(int)$user['id']]);
            audit_log((int)$user['id'], 'EMAIL_VERIFIED', 'users', (int)$user['id']);
            unset($_SESSION['pending_verification_email']);
            $_SESSION['flash_success'] = 'Email verified. You can now sign in.';
            redirect('login.php');
        }
    }
}

$baseUrl = rtrim($config['app']['base_url'], '/');
$renteaseLogoUrl = rentease_logo_url($config);
$flashError   = $_SESSION['flash_error']   ?? null; unset($_SESSION['flash_error']);
$flashSuccess = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Email — RentEase</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($baseUrl) ?>/assets/css/app.css" rel="stylesheet">
    <?php if ($renteaseLogoUrl): ?>
        <link rel="icon" type="image/png" href="<?= e($renteaseLogoUrl) ?>">
    <?php endif; ?>
    <style>
        html, body { height: 100%; margin: 0; font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        body {
            min-height: 100vh; display: flex; flex-direction: column;
            background: #fff;
        }
        body::before {
            content: ''; position: fixed; width: 500px; height: 500px; border-radius: 50%;
            display: none;
            background: rgba(246,207,74,.18); filter: blur(90px);
            top: -120px; left: -100px; pointer-events: none; z-index: 0;
        }
        body::after {
            content: ''; position: fixed; width: 400px; height: 400px; border-radius: 50%;
            display: none;
            background: rgba(246,207,74,.14); filter: blur(80px);
            bottom: -80px; right: -80px; pointer-events: none; z-index: 0;
        }

        .re-main { position: relative; z-index: 1; flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px 16px; }

        .re-glass-card {
            width: 100%; max-width: 420px;
            background: #fff;
            backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
            border: 1px solid #f0dfad; border-radius: 24px;
            padding: clamp(28px, 5vw, 44px);
            box-shadow: 0 8px 32px rgba(36,27,11,.10), inset 0 1px 0 rgba(255,255,255,.12);
        }

        /* Icon */
        .re-otp-icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: #241b0b;
            border: 1px solid #f0dfad;
            overflow: hidden;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; font-weight: 900; margin-bottom: 20px;
            color: #f6cf4a;
        }

        .re-card-title { font-size: 1.4rem; font-weight: 800; color: #241b0b; margin-bottom: 6px; letter-spacing: -.02em; }
        .re-card-sub { font-size: .85rem; color: #76684b; margin-bottom: 24px; line-height: 1.6; }

        .re-glass-card .form-label { color: #3a2d12; font-size: .78rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; margin-bottom: 6px; }
        .re-glass-card .form-control {
            background: #fffdf5; border: 1px solid #f0dfad;
            border-radius: 10px; color: #241b0b; font-size: .9rem; padding: 10px 13px; min-height: 42px;
            transition: border-color .15s, background .15s, box-shadow .15s;
        }
        .re-glass-card .form-control::placeholder { color: #a89562; }
        .re-glass-card .form-control:focus { background: #fff; border-color: #e6b82f; box-shadow: 0 0 0 3px rgba(246,207,74,.28); color: #241b0b; outline: none; }

        /* OTP input — large centered digits */
        .re-otp-input {
            text-align: center; font-size: 2rem; font-weight: 900;
            letter-spacing: .5em; padding-left: calc(.5em + 13px);
        }

        .re-btn-verify {
            width: 100%; padding: 12px; border-radius: 10px; border: none;
            background: #f6cf4a;
            color: #241b0b; font-weight: 700; font-size: .95rem; cursor: pointer;
            box-shadow: 0 4px 18px rgba(197,144,0,.22);
            transition: opacity .15s, transform .15s;
        }
        .re-btn-verify:hover { opacity: .92; transform: translateY(-1px); }

        .re-btn-resend {
            width: 100%; padding: 10px; border-radius: 10px; border: 1px solid rgba(255,255,255,.15);
            background: #fff; color: #76684b; font-weight: 600; font-size: .88rem;
            border-color: #f0dfad;
            cursor: pointer; margin-top: 10px; transition: background .15s, color .15s;
        }
        .re-btn-resend:hover { background: #fff7d6; color: #241b0b; }

        .re-card-footer-link { color: #76684b; font-size: .82rem; text-align: center; margin-top: 18px; }
        .re-card-footer-link a { color: #8a6400; text-decoration: none; font-weight: 600; }
        .re-card-footer-link a:hover { color: #241b0b; text-decoration: underline; }

        .re-alert { border-radius: 10px; padding: 11px 14px; font-size: .85rem; font-weight: 600; margin-bottom: 20px; border: 1px solid; }
        .re-alert-danger  { background: #fff2f1; border-color: #f5c6c2; color: #7a1a10; }
        .re-alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; }
    </style>
</head>
<body class="rental-interface">
<main class="re-main">
    <div class="re-glass-card">
        <div class="re-otp-icon">
            <?php if ($renteaseLogoUrl): ?>
                <img class="rentease-logo-img" src="<?= e($renteaseLogoUrl) ?>" alt="RentEase logo">
            <?php else: ?>
                OTP
            <?php endif; ?>
        </div>
        <h1 class="re-card-title">Check your email</h1>
        <p class="re-card-sub">
            A 6-digit verification code was sent to
            <?php if ($email): ?>
                <strong style="color:#241b0b;"><?= e($email) ?></strong>.
            <?php else: ?>
                your email address.
            <?php endif; ?>
            It expires in 5 minutes.
        </p>

        <?php if ($flashError): ?>
            <div class="re-alert re-alert-danger" role="alert"><?= e($flashError) ?></div>
        <?php endif; ?>
        <?php if ($flashSuccess): ?>
            <div class="re-alert re-alert-success" role="alert"><?= e($flashSuccess) ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="email" value="<?= e($email) ?>">

            <div class="mb-4">
                <label class="form-label" for="otp-code">Verification Code</label>
                <input class="form-control re-otp-input" type="text" id="otp-code" name="otp_code"
                       inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000"
                       autocomplete="one-time-code">
            </div>

            <button type="submit" class="re-btn-verify" name="action" value="verify">Verify Account</button>
            <button type="submit" class="re-btn-resend" name="action" value="resend">Resend Code</button>
        </form>

        <p class="re-card-footer-link"><a href="<?= e($baseUrl) ?>/login.php">&larr; Back to sign in</a></p>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e($baseUrl) ?>/assets/js/dlp.js"></script>
</body>
</html>
