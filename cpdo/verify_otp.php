<?php
require_once __DIR__ . '/../app/bootstrap_cpdo.php';
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
            $_SESSION['flash_success'] = 'Email verified. You can now log in.';
            redirect('login.php');
        }
    }
}

$cpdoUrl = rtrim($config['app']['cpdo_url'] ?? str_replace('/public', '/cpdo', $config['app']['base_url']), '/');
$pubUrl  = rtrim($config['app']['base_url'], '/');
$cpdoLogoUrl = cpdo_logo_url($config);
$flashError   = $_SESSION['flash_error']   ?? null; unset($_SESSION['flash_error']);
$flashSuccess = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Verification — CPDO Staff Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($pubUrl) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        html, body {
            height: 100%; margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        body {
            background-color: #eef2f7;
            background-image: radial-gradient(circle, #c8d4e3 1px, transparent 1px);
            background-size: 28px 28px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Government header bar — identical to login page ── */
        .gov-header-bar {
            background: #0b2a4a;
            border-bottom: 3px solid #1d6aad;
        }
        .gov-header-inner {
            max-width: 1100px; margin: 0 auto; padding: 14px 24px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .gov-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .gov-seal {
            width: 44px; height: 44px; border-radius: 50%; background: #fff;
            border: 2px solid rgba(255,255,255,.3);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .gov-seal-inner {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #0b2a4a, #1d6aad);
            display: flex; align-items: center; justify-content: center;
            font-size: .65rem; font-weight: 900; color: #fff;
            letter-spacing: .04em; text-align: center; line-height: 1.2;
        }
        .gov-brand-name { display: block; font-size: .95rem; font-weight: 800; color: #fff; line-height: 1.2; }
        .gov-brand-dept { display: block; font-size: .7rem; color: rgba(255,255,255,.55); font-weight: 500; }
        .gov-header-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 4px;
            background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18);
            color: rgba(255,255,255,.7); font-size: .7rem; font-weight: 700;
            letter-spacing: .08em; text-transform: uppercase;
        }
        .gov-header-badge-dot { width: 6px; height: 6px; border-radius: 50%; background: #f59e0b; }

        /* ── Main content ── */
        .gov-main {
            flex: 1; display: flex; align-items: center; justify-content: center;
            padding: 40px 16px;
        }
        .gov-otp-wrap { width: 100%; max-width: 440px; }

        /* ── OTP card ── */
        .gov-card {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(11,42,74,.06), 0 8px 24px rgba(11,42,74,.08);
            padding: 36px 36px 28px;
        }

        /* Icon block */
        .gov-otp-icon-wrap { text-align: center; margin-bottom: 22px; }
        .gov-otp-icon {
            width: 60px; height: 60px; border-radius: 50%;
            background: #eef2f7; border: 2px solid #c5d3df;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.6rem; margin-bottom: 12px;
        }
        .gov-card-title { font-size: 1.15rem; font-weight: 800; color: #0b2a4a; margin-bottom: 4px; }
        .gov-card-sub { font-size: .82rem; color: #62748a; line-height: 1.6; margin-bottom: 0; }

        /* Divider */
        .gov-card-divider { border: none; border-top: 1px solid #e8eef5; margin: 20px 0; }

        /* Form labels */
        .gov-card .form-label {
            font-size: .78rem; font-weight: 700; color: #3a5068;
            letter-spacing: .04em; text-transform: uppercase; margin-bottom: 6px;
        }

        /* Form controls */
        .gov-card .form-control {
            border: 1.5px solid #c5d3df; border-radius: 6px;
            background: #f8fbff; color: #121212; font-size: .9rem;
            padding: 10px 13px; min-height: 42px;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .gov-card .form-control:focus {
            border-color: #1d6aad; background: #fff;
            box-shadow: 0 0 0 3px rgba(29,106,173,.14); outline: none;
        }
        .gov-card .form-control::placeholder { color: #9aaabd; }

        /* OTP input — large centered digits */
        .gov-otp-input {
            text-align: center; font-size: 2rem; font-weight: 900;
            letter-spacing: .5em; padding-left: calc(.5em + 13px);
            font-variant-numeric: tabular-nums;
        }

        /* Buttons */
        .gov-btn-verify {
            width: 100%; padding: 11px; border-radius: 6px; border: none;
            background: #0b2a4a; color: #fff; font-weight: 700; font-size: .92rem;
            cursor: pointer; box-shadow: 0 2px 8px rgba(11,42,74,.25);
            transition: background .15s, box-shadow .15s;
        }
        .gov-btn-verify:hover { background: #0e3560; box-shadow: 0 4px 14px rgba(11,42,74,.32); }

        .gov-btn-resend {
            width: 100%; padding: 9px; border-radius: 6px; margin-top: 10px;
            border: 1.5px solid #c5d3df; background: #f4f8fc;
            color: #3a5068; font-weight: 600; font-size: .85rem; cursor: pointer;
            transition: background .15s, border-color .15s;
        }
        .gov-btn-resend:hover { background: #eaf2fb; border-color: #b0c4d8; }

        /* Back link */
        .gov-back-link {
            display: block; text-align: center; margin-top: 16px;
            font-size: .8rem; color: #62748a; text-decoration: none;
        }
        .gov-back-link:hover { color: #1d6aad; text-decoration: underline; }

        /* Alerts */
        .gov-alert {
            border-radius: 6px; padding: 10px 14px; font-size: .83rem;
            font-weight: 600; margin-bottom: 18px; border: 1px solid;
        }
        .gov-alert-danger  { background: #fff2f1; border-color: #f5c6c2; color: #7a1a10; border-left: 4px solid #c0392b; }
        .gov-alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; border-left: 4px solid #1e9e57; }

        /* Footer */
        .gov-footer {
            background: #0b2a4a; border-top: 1px solid rgba(255,255,255,.07);
            padding: 14px 24px; text-align: center;
        }
        .gov-footer p { margin: 0; font-size: .72rem; color: rgba(255,255,255,.35); }

        @media (max-width: 480px) {
            .gov-card { padding: 24px 20px 20px; }
            .gov-header-badge { display: none; }
        }
    </style>
</head>
<body>

    <!-- Government header bar -->
    <header class="gov-header-bar">
        <div class="gov-header-inner">
            <a class="gov-brand" href="<?= e($cpdoUrl) ?>/login.php">
                <div class="gov-seal">
                    <?php if ($cpdoLogoUrl): ?>
                        <img class="cpdo-logo-img cpdo-logo-img--seal" src="<?= e($cpdoLogoUrl) ?>" alt="CPDO logo">
                    <?php else: ?>
                        <div class="gov-seal-inner">CPDO</div>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="gov-brand-name">CPDO Land Portal</span>
                    <span class="gov-brand-dept">City Planning and Development Office · Davao City</span>
                </div>
            </a>
            <div class="gov-header-badge">
                <span class="gov-header-badge-dot"></span>
                Restricted System
            </div>
        </div>
    </header>

    <!-- Main -->
    <main class="gov-main">
        <div class="gov-otp-wrap">
            <div class="gov-card">

                <div class="gov-otp-icon-wrap">
                    <div class="gov-otp-icon">OTP</div>
                    <h1 class="gov-card-title">Email Verification Required</h1>
                    <p class="gov-card-sub">
                        A 6-digit verification code was sent to
                        <?php if ($email): ?>
                            <strong style="color:#0b2a4a;"><?= e($email) ?></strong>.
                        <?php else: ?>
                            your registered email address.
                        <?php endif; ?>
                        The code expires in 5 minutes.
                    </p>
                </div>

                <hr class="gov-card-divider">

                <?php if ($flashError): ?>
                    <div class="gov-alert gov-alert-danger" role="alert"><?= e($flashError) ?></div>
                <?php endif; ?>
                <?php if ($flashSuccess): ?>
                    <div class="gov-alert gov-alert-success" role="alert"><?= e($flashSuccess) ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="email" value="<?= e($email) ?>">

                    <div class="mb-4">
                        <label class="form-label" for="otp-code">Verification Code</label>
                        <input class="form-control gov-otp-input" type="text" id="otp-code"
                               name="otp_code" inputmode="numeric" pattern="\d{6}"
                               maxlength="6" placeholder="000000" autocomplete="one-time-code">
                    </div>

                    <button type="submit" class="gov-btn-verify" name="action" value="verify">
                        Verify and Continue
                    </button>
                    <button type="submit" class="gov-btn-resend" name="action" value="resend">
                        Resend Verification Code
                    </button>
                </form>

                <a class="gov-back-link" href="<?= e($cpdoUrl) ?>/login.php">&larr; Back to Staff Login</a>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="gov-footer">
        <p>City Planning and Development Office &mdash; Davao City &mdash; Authorized Personnel Only</p>
    </footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e($pubUrl) ?>/assets/js/dlp.js"></script>
</body>
</html>
