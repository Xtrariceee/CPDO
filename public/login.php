<?php
require_once __DIR__ . '/../app/bootstrap.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    // Two Doors guard: public portal only accepts tenant and landlord
    $stmt = db()->prepare(
        'SELECT * FROM users
         WHERE email = ?
           AND status = "ACTIVE"
           AND role IN ("tenant","landlord")'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
        if (!(int)$user['is_verified']) {
            $_SESSION['pending_verification_email'] = $email;
            audit_log((int)$user['id'], 'LOGIN_BLOCKED_UNVERIFIED', 'users', (int)$user['id']);
            $_SESSION['flash_error'] = 'Please verify your email before logging in.';
            redirect('verify_otp.php');
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        audit_log((int)$user['id'], 'LOGIN_SUCCESS', 'users', (int)$user['id']);
        redirect(dashboard_for_role($user['role']));
    }

    audit_log(null, 'LOGIN_FAILED', 'users', null, ['email' => $email]);
    $_SESSION['flash_error'] = 'Invalid credentials.';
}

$googleConfigured = !empty($config['google']['client_id']);
$googleUrl = '#';
if ($googleConfigured) {
    $params = http_build_query([
        'client_id'     => $config['google']['client_id'],
        'redirect_uri'  => $config['google']['redirect_uri'],
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ]);
    $googleUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
}

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
    <title>Sign In — RentEase</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($baseUrl) ?>/assets/css/app.css" rel="stylesheet">
    <?php if ($renteaseLogoUrl): ?>
        <link rel="icon" type="image/png" href="<?= e($renteaseLogoUrl) ?>">
    <?php endif; ?>
    <style>
        /* ── Glassmorphism login page ── */
        html, body {
            height: 100%;
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        .re-login-page {
            min-height: 100vh;
            display: flex;
            align-items: stretch;
            background: #fff;
            position: relative;
            overflow: hidden;
        }

        /* Decorative blurred orbs for depth */
        .re-login-page::before,
        .re-login-page::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            pointer-events: none;
            z-index: 0;
            display: none;
        }
        .re-login-page::before {
            width: 520px;
            height: 520px;
            background: rgba(246, 207, 74, 0.18);
            top: -120px;
            left: -100px;
        }
        .re-login-page::after {
            width: 420px;
            height: 420px;
            background: rgba(246, 207, 74, 0.14);
            bottom: -80px;
            right: -80px;
        }

        /* Left value-prop panel */
        .re-left {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: clamp(40px, 6vw, 80px);
            color: #241b0b;
        }

        .re-brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 56px;
            text-decoration: none;
        }
        .re-brand-mark {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #241b0b;
            border: 1px solid #f0dfad;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1rem;
            color: #f6cf4a;
            flex-shrink: 0;
        }
        .re-brand-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: #241b0b;
            letter-spacing: -.01em;
        }

        .re-tagline {
            font-size: .75rem;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #8a6400;
            margin-bottom: 20px;
        }
        .re-headline {
            font-size: clamp(2rem, 4vw, 3.2rem);
            font-weight: 900;
            line-height: 1.08;
            color: #241b0b;
            margin-bottom: 20px;
            letter-spacing: -.02em;
        }
        .re-headline span {
            background: linear-gradient(90deg, #c59000, #7a5700);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .re-subtext {
            font-size: 1.05rem;
            color: #76684b;
            line-height: 1.65;
            max-width: 420px;
            margin-bottom: 44px;
        }

        /* Feature pills */
        .re-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .re-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 14px;
            border-radius: 999px;
            background: #fff7d6;
            border: 1px solid #f0dfad;
            color: #3a2d12;
            font-size: .8rem;
            font-weight: 600;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .re-pill-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #c59000;
            flex-shrink: 0;
        }

        /* Right glass card column */
        .re-right {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(24px, 4vw, 56px);
        }

        /* The frosted glass card */
        .re-glass-card {
            width: 100%;
            max-width: 440px;
            background: #fff;
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid #f0dfad;
            border-radius: 24px;
            padding: clamp(28px, 5vw, 44px);
            box-shadow:
                0 8px 32px rgba(36, 27, 11, 0.10),
                inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        .re-card-title {
            font-size: 1.55rem;
            font-weight: 800;
            color: #241b0b;
            margin-bottom: 6px;
            letter-spacing: -.02em;
        }
        .re-card-sub {
            font-size: .88rem;
            color: #76684b;
            margin-bottom: 28px;
        }

        /* Glass form controls */
        .re-glass-card .form-label {
            color: #3a2d12;
            font-size: .8rem;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: 7px;
        }
        .re-glass-card .form-control {
            background: #fffdf5;
            border: 1px solid #f0dfad;
            border-radius: 10px;
            color: #241b0b;
            font-size: .92rem;
            padding: 11px 14px;
            min-height: 44px;
            transition: border-color .18s, background .18s, box-shadow .18s;
        }
        .re-glass-card .form-control::placeholder { color: #a89562; }
        .re-glass-card .form-control:focus {
            background: #fff;
            border-color: #e6b82f;
            box-shadow: 0 0 0 3px rgba(246,207,74,.28);
            color: #241b0b;
            outline: none;
        }
        /* Password wrap inside glass card */
        .re-glass-card .password-wrap .pw-toggle { color: #a89562; }
        .re-glass-card .password-wrap .pw-toggle:hover { color: #241b0b; }

        /* Primary sign-in button */
        .re-btn-signin {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid #e6b82f;
            background: #f6cf4a;
            color: #241b0b;
            font-weight: 700;
            font-size: .95rem;
            letter-spacing: .01em;
            cursor: pointer;
            transition: opacity .18s, transform .18s, box-shadow .18s;
            box-shadow: 0 4px 18px rgba(197,144,0,.22);
        }
        .re-btn-signin:hover {
            opacity: .92;
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(197,144,0,.28);
        }
        .re-btn-signin:active { transform: translateY(0); }

        /* Google button inside glass */
        .re-glass-card .btn-google {
            background: #fff;
            border: 1px solid #f0dfad;
            color: #3a2d12;
            border-radius: 10px;
            font-weight: 600;
            font-size: .88rem;
            transition: background .18s, border-color .18s;
        }
        .re-glass-card .btn-google:hover {
            background: #fff7d6;
            border-color: #e6b82f;
            color: #241b0b;
        }

        /* Divider */
        .re-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #a89562;
            font-size: .75rem;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            margin: 20px 0;
        }
        .re-divider::before,
        .re-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #f0dfad;
        }

        /* Footer links inside card */
        .re-card-footer-link {
            color: #76684b;
            font-size: .8rem;
            text-align: center;
            margin-top: 18px;
        }
        .re-card-footer-link a {
            color: #8a6400;
            text-decoration: none;
            font-weight: 600;
        }
        .re-card-footer-link a:hover { color: #241b0b; text-decoration: underline; }

        /* Staff portal link — distinct style */
        .re-staff-link {
            display: block;
            text-align: center;
            margin-top: 14px;
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid #f0dfad;
            background: #fffdf5;
            color: #76684b;
            font-size: .78rem;
            font-weight: 600;
            text-decoration: none;
            transition: background .18s, color .18s, border-color .18s;
        }
        .re-staff-link:hover {
            background: #fff7d6;
            border-color: #e6b82f;
            color: #241b0b;
        }
        .re-staff-link span { color: #8a6400; }

        /* Flash alerts inside glass */
        .re-alert {
            border-radius: 10px;
            padding: 11px 14px;
            font-size: .85rem;
            font-weight: 600;
            margin-bottom: 20px;
            border: 1px solid;
        }
        .re-alert-danger  { background: #fff2f1; border-color: #f5c6c2; color: #7a1a10; }
        .re-alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; }

        /* Responsive: stack on mobile */
        @media (max-width: 767px) {
            .re-left { display: none; }
            .re-right { padding: 24px 16px; min-height: 100vh; }
            .re-glass-card { max-width: 100%; }
        }
    </style>
</head>
<body class="rental-interface">
<div class="re-login-page">

    <!-- Left: value proposition -->
    <div class="re-left col-lg-6 d-none d-lg-flex">
        <a class="re-brand" href="<?= e($baseUrl) ?>/index.php">
            <span class="re-brand-mark">
                <?php if ($renteaseLogoUrl): ?>
                    <img class="rentease-logo-img" src="<?= e($renteaseLogoUrl) ?>" alt="RentEase logo">
                <?php else: ?>
                    RE
                <?php endif; ?>
            </span>
            <span class="re-brand-name">RentEase</span>
        </a>

        <p class="re-tagline">Rental Management Platform</p>
        <h1 class="re-headline">
            Manage properties<br>and rentals<br><span>seamlessly.</span>
        </h1>
        <p class="re-subtext">
            Find verified rental listings, track your applications, and connect with compliant landlords — all in one secure platform built for Davao City.
        </p>

        <div class="re-pills">
            <span class="re-pill"><span class="re-pill-dot"></span>Verified Listings</span>
            <span class="re-pill"><span class="re-pill-dot"></span>Lease Tracking</span>
            <span class="re-pill"><span class="re-pill-dot"></span>Real-time Payment Monitoring</span>
            <span class="re-pill"><span class="re-pill-dot"></span>Automated Reminders</span>
        </div>
    </div>

    <!-- Right: glass login card -->
    <div class="re-right col-12 col-lg-6">
        <div class="re-glass-card">

            <!-- Mobile brand (hidden on desktop) -->
            <a class="re-brand d-flex d-lg-none mb-4" href="<?= e($baseUrl) ?>/index.php">
                <span class="re-brand-mark">
                    <?php if ($renteaseLogoUrl): ?>
                        <img class="rentease-logo-img" src="<?= e($renteaseLogoUrl) ?>" alt="RentEase logo">
                    <?php else: ?>
                        RE
                    <?php endif; ?>
                </span>
                <span class="re-brand-name">RentEase</span>
            </a>

            <h2 class="re-card-title">Welcome back</h2>
            <p class="re-card-sub">Sign in to your account.</p>

            <?php if ($flashError): ?>
                <div class="re-alert re-alert-danger" role="alert"><?= e($flashError) ?></div>
            <?php endif; ?>
            <?php if ($flashSuccess): ?>
                <div class="re-alert re-alert-success" role="alert"><?= e($flashSuccess) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="mb-3">
                    <label class="form-label" for="login-email">Email address</label>
                    <input class="form-control" type="email" id="login-email" name="email"
                           required autocomplete="email" placeholder="you@example.com">
                </div>

                <div class="mb-4">
                    <label class="form-label" for="login-password">Password</label>
                    <div class="password-wrap">
                        <input class="form-control" type="password" id="login-password" name="password"
                               required autocomplete="current-password" placeholder="••••••••">
                        <button type="button" class="pw-toggle" aria-label="Show password"
                                data-target="login-password">
                            <svg class="pw-eye pw-eye-show" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4C5.5 4 2 10 2 10s3.5 6 8 6 8-6 8-6-3.5-6-8-6z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                            <svg class="pw-eye pw-eye-hide" viewBox="0 0 20 20" fill="none" aria-hidden="true" style="display:none"><path d="M3 3l14 14M10 4C5.5 4 2 10 2 10s.9 1.5 2.5 3M17.5 7C18.8 8.5 18 10 18 10s-3.5 6-8 6c-1.5 0-2.9-.4-4-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="re-btn-signin">Sign In</button>
            </form>

            <div class="re-divider">or</div>

            <?php if ($googleConfigured): ?>
                <a class="btn btn-google w-100" href="<?= e($googleUrl) ?>">
                    <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" style="flex-shrink:0">
                        <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.875 2.684-6.615z"/>
                        <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z"/>
                        <path fill="#FBBC05" d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z"/>
                        <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 6.29C4.672 4.163 6.656 3.58 9 3.58z"/>
                    </svg>
                    Sign in with Google
                </a>
                <p class="re-card-footer-link" style="font-size:.73rem;margin-top:8px;">
                    Google sign-in requires a registered account.
                </p>
            <?php else: ?>
                <button class="btn btn-google w-100 disabled" disabled>Sign in with Google</button>
            <?php endif; ?>

            <p class="re-card-footer-link">
                No account yet? <a href="<?= e($baseUrl) ?>/register.php">Create one free</a>
            </p>

            <a class="re-staff-link" href="<?= e($cpdoUrl) ?>/login.php">
                Government Official? <span>Access the CPDO Staff Portal →</span>
            </a>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e($baseUrl) ?>/assets/js/dlp.js"></script>
<script>
document.querySelectorAll('.pw-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var input  = document.getElementById(btn.dataset.target);
        var isText = input.type === 'text';
        input.type = isText ? 'password' : 'text';
        btn.querySelector('.pw-eye-show').style.display = isText ? ''     : 'none';
        btn.querySelector('.pw-eye-hide').style.display = isText ? 'none' : '';
        btn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
    });
});
</script>
</body>
</html>
