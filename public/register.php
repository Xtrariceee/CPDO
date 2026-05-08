<?php
require_once __DIR__ . '/../app/bootstrap.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName       = trim($_POST['first_name'] ?? '');
    $middleName      = trim($_POST['middle_name'] ?? '');
    $lastName        = trim($_POST['last_name'] ?? '');
    $email           = strtolower(trim($_POST['email'] ?? ''));
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $agreed          = isset($_POST['terms']);

    if (!$firstName || !$lastName || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_error'] = 'Please provide valid registration details.';
    } elseif ($password !== $confirmPassword) {
        $_SESSION['flash_error'] = 'Password and confirm password must match.';
    } elseif (!password_is_strong($password)) {
        $_SESSION['flash_error'] = 'Password must be at least 8 characters and include uppercase, lowercase, and a number.';
    } elseif (!$agreed) {
        $_SESSION['flash_error'] = 'You must agree to the Terms and Conditions before creating an account.';
    } else {
        $pdo  = db();
        $stmt = $pdo->prepare(
            'INSERT INTO users (first_name, middle_name, last_name, email, password_hash, role, is_verified)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        try {
            $stmt->execute([$firstName, $middleName ?: null, $lastName, $email,
                            password_hash($password, PASSWORD_BCRYPT), ROLE_TENANT]);
            $userId      = (int)$pdo->lastInsertId();
            $displayName = trim($firstName . ' ' . $lastName);
            $otpSent     = issue_user_otp($userId, $email, $displayName);
            audit_log($userId, 'REGISTERED_PENDING_OTP', 'users', $userId);
            $_SESSION['pending_verification_email'] = $email;
            $_SESSION[$otpSent ? 'flash_success' : 'flash_error'] = $otpSent
                ? 'Account created. Enter the 6-digit OTP sent to your email.'
                : 'Account created, but the OTP email could not be sent. Please try again or contact support.';
            redirect('verify_otp.php');
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = 'Email is already registered.';
        }
    }
}

$googleConfigured = !empty($config['google']['client_id']);
$baseUrl = rtrim($config['app']['base_url'], '/');
$cpdoUrl = rtrim($config['app']['cpdo_url'] ?? str_replace('/public', '/cpdo', $baseUrl), '/');
$renteaseLogoUrl = rentease_logo_url($config);
$flashError   = $_SESSION['flash_error']   ?? null; unset($_SESSION['flash_error']);
$flashSuccess = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Account — RentEase</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($baseUrl) ?>/assets/css/app.css" rel="stylesheet">
    <?php if ($renteaseLogoUrl): ?>
        <link rel="icon" type="image/png" href="<?= e($renteaseLogoUrl) ?>">
    <?php endif; ?>
    <style>
        html, body { height: 100%; margin: 0; font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }

        .re-page {
            min-height: 100vh;
            background: #fff;
            position: relative; overflow-x: hidden;
            display: flex; flex-direction: column;
        }
        .re-page::before {
            content: ''; position: fixed;
            width: 600px; height: 600px; border-radius: 50%;
            display: none;
            background: rgba(246,207,74,.18); filter: blur(100px);
            top: -150px; left: -150px; pointer-events: none; z-index: 0;
        }
        .re-page::after {
            content: ''; position: fixed;
            width: 500px; height: 500px; border-radius: 50%;
            display: none;
            background: rgba(246,207,74,.14); filter: blur(90px);
            bottom: -100px; right: -100px; pointer-events: none; z-index: 0;
        }

        /* Navbar */
        .re-nav { position: relative; z-index: 10; padding: 16px 0; border-bottom: 2px solid #f0dfad; }
        .re-nav-inner { max-width: 1100px; margin: 0 auto; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
        .re-nav-brand { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; }
        .re-nav-mark { width: 38px; height: 38px; border-radius: 50%; background: #241b0b; border: 1px solid #f0dfad; overflow: hidden; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: .8rem; color: #f6cf4a; }
        .re-nav-name { font-size: 1rem; font-weight: 800; color: #241b0b; }
        .re-nav-link { color: #76684b; font-size: .85rem; font-weight: 600; text-decoration: none; padding: 7px 14px; border-radius: 8px; transition: color .15s, background .15s; }
        .re-nav-link:hover { color: #241b0b; background: #fff7d6; }

        /* Main */
        .re-main { position: relative; z-index: 1; flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px 60px; }

        /* Glass card */
        .re-glass-card {
            width: 100%; max-width: 560px;
            background: #fff;
            backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
            border: 1px solid #f0dfad; border-radius: 24px;
            padding: clamp(28px, 5vw, 44px);
            box-shadow: 0 8px 32px rgba(36,27,11,.10), inset 0 1px 0 rgba(255,255,255,.12);
        }
        .re-card-title { font-size: 1.5rem; font-weight: 800; color: #241b0b; margin-bottom: 4px; letter-spacing: -.02em; }
        .re-card-sub { font-size: .85rem; color: #76684b; margin-bottom: 24px; }

        /* Form labels */
        .re-glass-card .form-label { color: #3a2d12; font-size: .78rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; margin-bottom: 6px; }

        /* Form controls */
        .re-glass-card .form-control {
            background: #fffdf5; border: 1px solid #f0dfad;
            border-radius: 10px; color: #241b0b; font-size: .9rem; padding: 10px 13px; min-height: 42px;
            transition: border-color .15s, background .15s, box-shadow .15s;
        }
        .re-glass-card .form-control::placeholder { color: #a89562; }
        .re-glass-card .form-control:focus { background: #fff; border-color: #e6b82f; box-shadow: 0 0 0 3px rgba(246,207,74,.28); color: #241b0b; outline: none; }
        .re-glass-card .pw-toggle { color: #a89562; }
        .re-glass-card .pw-toggle:hover { color: #241b0b; }

        /* Password meter inside glass */
        .re-glass-card .password-meter { margin-top: 10px; }
        .re-glass-card .password-meter span, .re-glass-card .match-meter {
            border-left-color: #f0dfad; color: #76684b; font-size: .75rem;
        }
        .re-glass-card .match-meter {
            border-left: 3px solid #f0dfad;
            padding-left: 8px;
            margin-top: 8px;
            font-size: .75rem;
            display: block;
        }
        .re-glass-card .password-meter .is-valid, .re-glass-card .match-meter.is-valid { color: #86efac; border-left-color: #4ade80; }
        .re-glass-card .match-meter.is-invalid { color: #fca5a5; border-left-color: #f87171; }

        /* Terms checkbox */
        .re-glass-card .check-row { color: #76684b; font-size: .83rem; }
        .re-glass-card .check-row a { color: #8a6400; }

        /* Submit button */
        .re-btn-submit {
            width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #e6b82f;
            background: #f6cf4a;
            color: #241b0b; font-weight: 700; font-size: .95rem; cursor: pointer;
            box-shadow: 0 4px 18px rgba(197,144,0,.22);
            transition: opacity .15s, transform .15s, box-shadow .15s;
        }
        .re-btn-submit:hover { opacity: .92; transform: translateY(-1px); box-shadow: 0 6px 24px rgba(197,144,0,.28); }

        /* Google button */
        .re-glass-card .btn-google {
            background: #fff; border: 1px solid #f0dfad;
            color: #3a2d12; border-radius: 10px; font-weight: 600; font-size: .88rem;
            transition: background .15s, border-color .15s;
        }
        .re-glass-card .btn-google:hover { background: #fff7d6; border-color: #e6b82f; color: #241b0b; }

        /* Divider */
        .re-divider { display: flex; align-items: center; gap: 12px; color: #a89562; font-size: .72rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; margin: 18px 0; }
        .re-divider::before, .re-divider::after { content: ''; flex: 1; height: 1px; background: #f0dfad; }

        /* Footer links */
        .re-card-footer-link { color: #76684b; font-size: .82rem; text-align: center; margin-top: 16px; }
        .re-card-footer-link a { color: #8a6400; text-decoration: none; font-weight: 600; }
        .re-card-footer-link a:hover { color: #241b0b; text-decoration: underline; }

        /* Alerts */
        .re-alert { border-radius: 10px; padding: 11px 14px; font-size: .85rem; font-weight: 600; margin-bottom: 20px; border: 1px solid; }
        .re-alert-danger  { background: #fff2f1; border-color: #f5c6c2; color: #7a1a10; }
        .re-alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; }

        /* 2-col name grid */
        .re-name-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 480px) { .re-name-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="rental-interface">
<div class="re-page">

    <nav class="re-nav">
        <div class="re-nav-inner">
            <a class="re-nav-brand" href="<?= e($baseUrl) ?>/index.php">
                <span class="re-nav-mark">
                    <?php if ($renteaseLogoUrl): ?>
                        <img class="rentease-logo-img" src="<?= e($renteaseLogoUrl) ?>" alt="RentEase logo">
                    <?php else: ?>
                        RE
                    <?php endif; ?>
                </span>
                <span class="re-nav-name">RentEase</span>
            </a>
            <a class="re-nav-link" href="<?= e($baseUrl) ?>/login.php">Already have an account? Sign in</a>
        </div>
    </nav>

    <main class="re-main">
        <div class="re-glass-card">
            <h1 class="re-card-title">Create your account</h1>
            <p class="re-card-sub">All new accounts start as <strong style="color:#241b0b;">Tenant</strong>. Request a Landlord upgrade from your dashboard after signing up.</p>

            <?php if ($flashError): ?>
                <div class="re-alert re-alert-danger" role="alert"><?= e($flashError) ?></div>
            <?php endif; ?>
            <?php if ($flashSuccess): ?>
                <div class="re-alert re-alert-success" role="alert"><?= e($flashSuccess) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <!-- Name row -->
                <div class="re-name-grid mb-3">
                    <div>
                        <label class="form-label" for="reg-first">First Name</label>
                        <input class="form-control" type="text" id="reg-first" name="first_name" required autocomplete="given-name" placeholder="Juan">
                    </div>
                    <div>
                        <label class="form-label" for="reg-last">Last Name</label>
                        <input class="form-control" type="text" id="reg-last" name="last_name" required autocomplete="family-name" placeholder="Dela Cruz">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="reg-middle">Middle Name <span style="color:#a89562;font-weight:400;text-transform:none;">(optional)</span></label>
                    <input class="form-control" type="text" id="reg-middle" name="middle_name" autocomplete="additional-name" placeholder="Santos">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="reg-email">Email Address</label>
                    <input class="form-control" type="email" id="reg-email" name="email" required autocomplete="email" placeholder="you@example.com">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="reg-password">Password</label>
                    <div class="password-wrap">
                        <input class="form-control" type="password" id="reg-password" name="password"
                               required minlength="8" data-password autocomplete="new-password" placeholder="Min. 8 characters">
                        <button type="button" class="pw-toggle" aria-label="Show password" data-target="reg-password">
                            <svg class="pw-eye pw-eye-show" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4C5.5 4 2 10 2 10s3.5 6 8 6 8-6 8-6-3.5-6-8-6z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                            <svg class="pw-eye pw-eye-hide" viewBox="0 0 20 20" fill="none" aria-hidden="true" style="display:none"><path d="M3 3l14 14M10 4C5.5 4 2 10 2 10s.9 1.5 2.5 3M17.5 7C18.8 8.5 18 10 18 10s-3.5 6-8 6c-1.5 0-2.9-.4-4-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                        </button>
                    </div>
                    <div class="password-meter" data-password-meter>
                        <span data-rule-length>8 or more characters</span>
                        <span data-rule-upper>Uppercase letter</span>
                        <span data-rule-lower>Lowercase letter</span>
                        <span data-rule-number>Number</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="reg-confirm">Confirm Password</label>
                    <div class="password-wrap">
                        <input class="form-control" type="password" id="reg-confirm" name="confirm_password"
                               required minlength="8" data-confirm-password autocomplete="new-password" placeholder="Re-enter password">
                        <button type="button" class="pw-toggle" aria-label="Show password" data-target="reg-confirm">
                            <svg class="pw-eye pw-eye-show" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4C5.5 4 2 10 2 10s3.5 6 8 6 8-6 8-6-3.5-6-8-6z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                            <svg class="pw-eye pw-eye-hide" viewBox="0 0 20 20" fill="none" aria-hidden="true" style="display:none"><path d="M3 3l14 14M10 4C5.5 4 2 10 2 10s.9 1.5 2.5 3M17.5 7C18.8 8.5 18 10 18 10s-3.5 6-8 6c-1.5 0-2.9-.4-4-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                        </button>
                    </div>
                    <div class="match-meter" data-match-meter style="margin-top:8px;font-size:.75rem;padding-left:8px;color:#76684b;">Passwords have not been matched yet.</div>
                </div>

                <label class="check-row mb-4">
                    <input type="checkbox" name="terms" required>
                    <span>I agree to the <a href="#">Terms and Conditions</a>.</span>
                </label>

                <button type="submit" class="re-btn-submit">Create Account</button>
            </form>

            <div class="re-divider">or sign up with</div>

            <?php if ($googleConfigured): ?>
                <a class="btn btn-google w-100" href="<?= e($baseUrl) ?>/google_signup_init.php">
                    <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" style="flex-shrink:0">
                        <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.875 2.684-6.615z"/>
                        <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z"/>
                        <path fill="#FBBC05" d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z"/>
                        <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 6.29C4.672 4.163 6.656 3.58 9 3.58z"/>
                    </svg>
                    Sign up with Google
                </a>
                <p class="re-card-footer-link" style="font-size:.73rem;margin-top:8px;">Google sign-up creates a Tenant account.</p>
            <?php else: ?>
                <button class="btn btn-google w-100 disabled" disabled>Sign up with Google</button>
            <?php endif; ?>

            <p class="re-card-footer-link">Already have an account? <a href="<?= e($baseUrl) ?>/login.php">Sign in</a></p>
        </div>
    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e($baseUrl) ?>/assets/js/dlp.js"></script>
<script>
const passwordInput = document.querySelector('[data-password]');
const confirmInput  = document.querySelector('[data-confirm-password]');
const rules = {
    length: document.querySelector('[data-rule-length]'),
    upper:  document.querySelector('[data-rule-upper]'),
    lower:  document.querySelector('[data-rule-lower]'),
    number: document.querySelector('[data-rule-number]'),
};
const matchMeter = document.querySelector('[data-match-meter]');

function setRule(el, valid) { if (el) el.classList.toggle('is-valid', valid); }
function updatePasswordState() {
    if (!passwordInput) return;
    const pw = passwordInput.value, cf = confirmInput ? confirmInput.value : '';
    setRule(rules.length, pw.length >= 8);
    setRule(rules.upper,  /[A-Z]/.test(pw));
    setRule(rules.lower,  /[a-z]/.test(pw));
    setRule(rules.number, /[0-9]/.test(pw));
    if (!matchMeter) return;
    if (!cf) { matchMeter.textContent = 'Passwords have not been matched yet.'; matchMeter.classList.remove('is-valid','is-invalid'); return; }
    const ok = pw === cf;
    matchMeter.textContent = ok ? 'Passwords match.' : 'Passwords do not match.';
    matchMeter.classList.toggle('is-valid', ok);
    matchMeter.classList.toggle('is-invalid', !ok);
}
if (passwordInput) passwordInput.addEventListener('input', updatePasswordState);
if (confirmInput)  confirmInput.addEventListener('input', updatePasswordState);

document.querySelectorAll('.pw-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var input = document.getElementById(btn.dataset.target);
        var isText = input.type === 'text';
        input.type = isText ? 'password' : 'text';
        btn.querySelector('.pw-eye-show').style.display = isText ? '' : 'none';
        btn.querySelector('.pw-eye-hide').style.display = isText ? 'none' : '';
        btn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
    });
});
</script>
</body>
</html>
