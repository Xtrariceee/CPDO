<?php
/**
 * CPDO Staff Portal — Login
 * Two Doors guard: only admin, zoning_officer, admin_officer, twg_member are accepted.
 * Tenants and Landlords are explicitly rejected with a generic error.
 */
require_once __DIR__ . '/../app/bootstrap_cpdo.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare(
        'SELECT * FROM users
         WHERE email = ?
           AND status = "ACTIVE"
           AND role IN ("admin","zoning_officer","admin_officer","twg_member","pending_staff")'
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
        header('Location: ' . dashboard_for_role($user['role']));
        exit;
    }

    audit_log(null, 'LOGIN_FAILED_CPDO_PORTAL', 'users', null, ['email' => $email]);
    $_SESSION['flash_error'] = 'Invalid credentials or insufficient access level.';
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
    <title>Staff Login — CPDO Land Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($pubUrl) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        /* ── Government portal login — professional, authoritative ── */
        html, body {
            height: 100%;
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        /* Subtle geometric dot-grid background — no images, pure CSS */
        body {
            background-color: #eef2f7;
            background-image:
                radial-gradient(circle, #c8d4e3 1px, transparent 1px);
            background-size: 28px 28px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Top government header bar ── */
        .gov-header-bar {
            background: #0b2a4a;
            border-bottom: 3px solid #1d6aad;
            padding: 0;
        }
        .gov-header-inner {
            max-width: 1100px;
            margin: 0 auto;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .gov-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        .gov-seal {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid rgba(255,255,255,.3);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }
        /* Placeholder seal — replace src with actual logo */
        .gov-seal-inner {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0b2a4a 0%, #1d6aad 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .65rem;
            font-weight: 900;
            color: #fff;
            letter-spacing: .04em;
            text-align: center;
            line-height: 1.2;
        }
        .gov-brand-text { color: #fff; }
        .gov-brand-name {
            display: block;
            font-size: .95rem;
            font-weight: 800;
            letter-spacing: .01em;
            line-height: 1.2;
        }
        .gov-brand-dept {
            display: block;
            font-size: .7rem;
            color: rgba(255,255,255,.55);
            font-weight: 500;
            letter-spacing: .03em;
        }
        .gov-header-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 4px;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.18);
            color: rgba(255,255,255,.7);
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .gov-header-badge-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #f59e0b;
        }

        /* ── Main content area ── */
        .gov-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 16px;
        }

        /* ── Login card ── */
        .gov-login-wrap {
            width: 100%;
            max-width: 460px;
        }

        /* Official seal / logo block above card */
        .gov-logo-block {
            text-align: center;
            margin-bottom: 24px;
        }
        .gov-logo-circle {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #0b2a4a;
            border: 3px solid #1d6aad;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            box-shadow: 0 4px 16px rgba(11,42,74,.25);
        }
        /* Replace this SVG with an <img> tag pointing to the actual government seal */
        .gov-logo-circle svg { width: 36px; height: 36px; }
        .gov-logo-title {
            font-size: 1rem;
            font-weight: 800;
            color: #0b2a4a;
            letter-spacing: .01em;
            margin-bottom: 2px;
        }
        .gov-logo-subtitle {
            font-size: .75rem;
            color: #62748a;
            font-weight: 500;
        }

        /* Restricted access notice */
        .gov-restricted-notice {
            background: #f0f5fb;
            border: 1px solid #c5d3df;
            border-left: 4px solid #0b2a4a;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .gov-restricted-notice p {
            margin: 0;
            font-size: .78rem;
            color: #3a5068;
            line-height: 1.55;
        }
        .gov-restricted-notice strong {
            color: #0b2a4a;
            font-weight: 800;
        }

        /* Card itself */
        .gov-card {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 10px;
            box-shadow:
                0 2px 4px rgba(11,42,74,.06),
                0 8px 24px rgba(11,42,74,.08);
            padding: 36px 36px 28px;
        }

        .gov-card-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: #0b2a4a;
            margin-bottom: 4px;
            letter-spacing: -.01em;
        }
        .gov-card-sub {
            font-size: .82rem;
            color: #62748a;
            margin-bottom: 24px;
        }

        /* Form labels */
        .gov-card .form-label {
            font-size: .78rem;
            font-weight: 700;
            color: #3a5068;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        /* Form controls */
        .gov-card .form-control {
            border: 1.5px solid #c5d3df;
            border-radius: 6px;
            background: #f8fbff;
            color: #121212;
            font-size: .9rem;
            padding: 10px 13px;
            min-height: 42px;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .gov-card .form-control:focus {
            border-color: #1d6aad;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(29,106,173,.14);
            outline: none;
        }
        .gov-card .form-control::placeholder { color: #9aaabd; }

        /* Password toggle inside gov card */
        .gov-card .pw-toggle { color: #9aaabd; }
        .gov-card .pw-toggle:hover { color: #3a5068; }

        /* Sign-in button */
        .gov-btn-signin {
            width: 100%;
            padding: 11px;
            border-radius: 6px;
            border: none;
            background: #0b2a4a;
            color: #fff;
            font-weight: 700;
            font-size: .92rem;
            letter-spacing: .02em;
            cursor: pointer;
            transition: background .15s, box-shadow .15s;
            box-shadow: 0 2px 8px rgba(11,42,74,.25);
        }
        .gov-btn-signin:hover {
            background: #0e3560;
            box-shadow: 0 4px 14px rgba(11,42,74,.32);
        }
        .gov-btn-signin:active { background: #061b31; }

        /* Flash alerts */
        .gov-alert {
            border-radius: 6px;
            padding: 10px 14px;
            font-size: .83rem;
            font-weight: 600;
            margin-bottom: 18px;
            border: 1px solid;
        }
        .gov-alert-danger  {
            background: #fff2f1;
            border-color: #f5c6c2;
            color: #7a1a10;
            border-left: 4px solid #c0392b;
        }
        .gov-alert-success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #14532d;
            border-left: 4px solid #1e9e57;
        }

        /* Card divider */
        .gov-card-divider {
            border: none;
            border-top: 1px solid #e8eef5;
            margin: 22px 0 18px;
        }

        /* Rental portal redirect link */
        .gov-portal-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 6px;
            background: #f4f8fc;
            border: 1px solid #d0dae6;
            text-decoration: none;
            transition: background .15s, border-color .15s;
        }
        .gov-portal-link:hover {
            background: #eaf2fb;
            border-color: #b0c4d8;
        }
        .gov-portal-link-text {
            font-size: .78rem;
            color: #62748a;
            font-weight: 500;
        }
        .gov-portal-link-action {
            font-size: .78rem;
            color: #1d6aad;
            font-weight: 700;
            white-space: nowrap;
        }

        /* ── Footer ── */
        .gov-footer {
            background: #0b2a4a;
            border-top: 1px solid rgba(255,255,255,.07);
            padding: 14px 24px;
            text-align: center;
        }
        .gov-footer p {
            margin: 0;
            font-size: .72rem;
            color: rgba(255,255,255,.35);
        }

        /* Responsive */
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
            <a class="gov-brand" href="<?= e($cpdoUrl) ?>/index.php">
                <div class="gov-seal">
                    <?php if ($cpdoLogoUrl): ?>
                        <img class="cpdo-logo-img cpdo-logo-img--seal" src="<?= e($cpdoLogoUrl) ?>" alt="CPDO logo">
                    <?php else: ?>
                        <div class="gov-seal-inner">CPDO</div>
                    <?php endif; ?>
                </div>
                <div class="gov-brand-text">
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

    <!-- Main login area -->
    <main class="gov-main">
        <div class="gov-login-wrap">

            <!-- Official logo / seal placeholder -->
            <div class="gov-logo-block">
                <div class="gov-logo-circle">
                    <?php if ($cpdoLogoUrl): ?>
                        <img class="cpdo-logo-img cpdo-logo-img--large" src="<?= e($cpdoLogoUrl) ?>" alt="CPDO logo">
                    <?php else: ?>
                        <svg viewBox="0 0 36 36" fill="none" aria-hidden="true">
                            <circle cx="18" cy="18" r="16" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
                            <circle cx="18" cy="18" r="10" stroke="rgba(255,255,255,.3)" stroke-width="1"/>
                            <path d="M18 8v20M8 18h20" stroke="rgba(255,255,255,.25)" stroke-width="1"/>
                            <circle cx="18" cy="18" r="3" fill="rgba(255,255,255,.6)"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <p class="gov-logo-title">CPDO Land Portal</p>
                <p class="gov-logo-subtitle">Staff Administrative System</p>
            </div>

            <!-- Restricted access notice -->
            <div class="gov-restricted-notice" role="note">
                <p>
                    <strong>Restricted Access.</strong>
                    This portal is strictly for authorized CPDO Administrative Officers, Zoning Officers IV, LZRC TWG Members, and System Administrators. Unauthorized access attempts are logged and monitored.
                </p>
            </div>

            <!-- Login card -->
            <div class="gov-card">
                <h1 class="gov-card-title">Staff Sign In</h1>
                <p class="gov-card-sub">Enter your official credentials to access the system.</p>

                <?php if ($flashError): ?>
                    <div class="gov-alert gov-alert-danger" role="alert"><?= e($flashError) ?></div>
                <?php endif; ?>
                <?php if ($flashSuccess): ?>
                    <div class="gov-alert gov-alert-success" role="alert"><?= e($flashSuccess) ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <div class="mb-3">
                        <label class="form-label" for="staff-email">Official Email Address</label>
                        <input class="form-control" type="email" id="staff-email" name="email"
                               required autocomplete="email" placeholder="officer@cpdo.gov.ph">
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="staff-password">Password</label>
                        <div class="password-wrap">
                            <input class="form-control" type="password" id="staff-password" name="password"
                                   required autocomplete="current-password" placeholder="••••••••">
                            <button type="button" class="pw-toggle" aria-label="Show password"
                                    data-target="staff-password">
                                <svg class="pw-eye pw-eye-show" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4C5.5 4 2 10 2 10s3.5 6 8 6 8-6 8-6-3.5-6-8-6z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                                <svg class="pw-eye pw-eye-hide" viewBox="0 0 20 20" fill="none" aria-hidden="true" style="display:none"><path d="M3 3l14 14M10 4C5.5 4 2 10 2 10s.9 1.5 2.5 3M17.5 7C18.8 8.5 18 10 18 10s-3.5 6-8 6c-1.5 0-2.9-.4-4-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="gov-btn-signin">Sign In to Staff Portal</button>
                </form>

                <hr class="gov-card-divider">

                <!-- Register link for new CPDO employees -->
                <a class="gov-portal-link mb-2" href="<?= e($cpdoUrl) ?>/register.php">
                    <span class="gov-portal-link-text">New CPDO employee?</span>
                    <span class="gov-portal-link-action">Create an Account &rarr;</span>
                </a>

                <hr class="gov-card-divider">

                <!-- Redirect to public rental portal -->
                <a class="gov-portal-link" href="<?= e($pubUrl) ?>/login.php">
                    <span class="gov-portal-link-text">Looking for the rental portal?</span>
                    <span class="gov-portal-link-action">Go to Tenant / Landlord Login &rarr;</span>
                </a>
            </div>

        </div>
    </main>

    <!-- Footer -->
    <footer class="gov-footer">
        <p>City Planning and Development Office &mdash; Davao City &mdash; Authorized Personnel Only</p>
    </footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e($pubUrl) ?>/assets/js/dlp.js"></script>
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
