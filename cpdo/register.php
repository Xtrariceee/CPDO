<?php
/**
 * CPDO Staff Portal — Self-Registration
 * New CPDO employees register here. They are assigned the "pending_staff" role
 * and must request their official designation from the System Administrator
 * before gaining access to any staff functionality.
 */
require_once __DIR__ . '/../app/bootstrap_cpdo.php';

// Already logged in — send to their dashboard
$existing = current_user();
if ($existing) {
    header('Location: ' . dashboard_for_role($existing['role']));
    exit;
}

verify_csrf();

$errors      = [];
$formData    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'first_name'  => trim($_POST['first_name']  ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name'   => trim($_POST['last_name']   ?? ''),
        'email'       => strtolower(trim($_POST['email'] ?? '')),
        'password'    => $_POST['password']         ?? '',
        'password2'   => $_POST['password2']        ?? '',
    ];

    // ── Validation ──────────────────────────────────────────────────────────
    if ($formData['first_name'] === '') {
        $errors['first_name'] = 'First name is required.';
    }
    if ($formData['last_name'] === '') {
        $errors['last_name'] = 'Last name is required.';
    }
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'A valid email address is required.';
    }
    if (!password_is_strong($formData['password'])) {
        $errors['password'] = 'Password must be at least 8 characters and include uppercase, lowercase, and a number.';
    }
    if ($formData['password'] !== $formData['password2']) {
        $errors['password2'] = 'Passwords do not match.';
    }

    // Check email uniqueness
    if (empty($errors['email'])) {
        $check = db()->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$formData['email']]);
        if ($check->fetch()) {
            $errors['email'] = 'An account with this email already exists.';
        }
    }

    // ── Insert if clean ──────────────────────────────────────────────────────
    if (empty($errors)) {
        $pdo  = db();
        $stmt = $pdo->prepare(
            'INSERT INTO users
                (first_name, middle_name, last_name, email, password_hash, role, status, is_verified)
             VALUES (?, ?, ?, ?, ?, "pending_staff", "ACTIVE", 1)'
        );
        $stmt->execute([
            $formData['first_name'],
            $formData['middle_name'] !== '' ? $formData['middle_name'] : null,
            $formData['last_name'],
            $formData['email'],
            password_hash($formData['password'], PASSWORD_BCRYPT),
        ]);
        $newId = (int)$pdo->lastInsertId();
        audit_log($newId, 'CPDO_SELF_REGISTER', 'users', $newId, ['email' => $formData['email']]);

        $_SESSION['flash_success'] = 'Account created. Sign in and submit your designation request.';
        redirect('login.php');
    }
}

$cpdoUrl = rtrim($config['app']['cpdo_url'] ?? str_replace('/public', '/cpdo', $config['app']['base_url']), '/');
$pubUrl  = rtrim($config['app']['base_url'], '/');
$cpdoLogoUrl = cpdo_logo_url($config);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Registration — CPDO Land Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($pubUrl) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        /* ── Mirrors the CPDO login page exactly ── */
        html, body {
            height: 100%;
            margin: 0;
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

        /* ── Government header bar — identical to login ── */
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
            width: 44px; height: 44px; border-radius: 50%;
            background: #fff;
            border: 2px solid rgba(255,255,255,.3);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .gov-seal-inner {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #0b2a4a 0%, #1d6aad 100%);
            display: flex; align-items: center; justify-content: center;
            font-size: .65rem; font-weight: 900; color: #fff;
            letter-spacing: .04em; text-align: center; line-height: 1.2;
        }
        .gov-brand-text { color: #fff; }
        .gov-brand-name { display: block; font-size: .95rem; font-weight: 800; letter-spacing: .01em; line-height: 1.2; }
        .gov-brand-dept { display: block; font-size: .7rem; color: rgba(255,255,255,.55); font-weight: 500; letter-spacing: .03em; }
        .gov-header-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 4px;
            background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18);
            color: rgba(255,255,255,.7); font-size: .7rem; font-weight: 700;
            letter-spacing: .08em; text-transform: uppercase;
        }
        .gov-header-badge-dot { width: 6px; height: 6px; border-radius: 50%; background: #f59e0b; }

        /* ── Main area ── */
        .gov-main {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 16px 56px;
        }
        .gov-register-wrap { width: 100%; max-width: 520px; }

        /* ── Logo block ── */
        .gov-logo-block { text-align: center; margin-bottom: 24px; }
        .gov-logo-circle {
            width: 72px; height: 72px; border-radius: 50%;
            background: #0b2a4a; border: 3px solid #1d6aad;
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 12px;
            box-shadow: 0 4px 16px rgba(11,42,74,.25);
        }
        .gov-logo-circle svg { width: 36px; height: 36px; }
        .gov-logo-title { font-size: 1rem; font-weight: 800; color: #0b2a4a; margin-bottom: 2px; }
        .gov-logo-subtitle { font-size: .75rem; color: #62748a; font-weight: 500; }

        /* ── Info notice ── */
        .gov-info-notice {
            background: #f0f5fb;
            border: 1px solid #c5d3df;
            border-left: 4px solid #1d6aad;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .gov-info-notice p { margin: 0; font-size: .78rem; color: #3a5068; line-height: 1.55; }
        .gov-info-notice strong { color: #0b2a4a; font-weight: 800; }

        /* ── Card ── */
        .gov-card {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(11,42,74,.06), 0 8px 24px rgba(11,42,74,.08);
            padding: 36px 36px 28px;
        }
        .gov-card-title { font-size: 1.2rem; font-weight: 800; color: #0b2a4a; margin-bottom: 4px; letter-spacing: -.01em; }
        .gov-card-sub   { font-size: .82rem; color: #62748a; margin-bottom: 24px; }

        /* ── Form elements ── */
        .gov-card .form-label {
            font-size: .78rem; font-weight: 700; color: #3a5068;
            letter-spacing: .04em; text-transform: uppercase; margin-bottom: 6px;
        }
        .gov-card .form-control {
            border: 1.5px solid #c5d3df; border-radius: 6px;
            background: #f8fbff; color: #121212;
            font-size: .9rem; padding: 10px 13px; min-height: 42px;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .gov-card .form-control:focus {
            border-color: #1d6aad; background: #fff;
            box-shadow: 0 0 0 3px rgba(29,106,173,.14); outline: none;
        }
        .gov-card .form-control::placeholder { color: #9aaabd; }
        .gov-card .form-control.is-invalid { border-color: #c0392b; }
        .gov-card .invalid-feedback { font-size: .76rem; color: #c0392b; margin-top: 4px; }

        /* Password toggle */
        .password-wrap { position: relative; }
        .pw-toggle {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: none; border: none; padding: 4px; cursor: pointer; line-height: 1;
            color: #9aaabd;
        }
        .pw-toggle:hover { color: #3a5068; }
        .pw-toggle svg { width: 18px; height: 18px; display: block; }

        /* Password strength meter */
        .pw-strength-bar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px;
            margin-top: 8px;
        }
        .pw-strength-bar span {
            height: 3px;
            border-radius: 2px;
            background: #d0dae6;
            transition: background .2s;
        }
        .pw-strength-bar span.active-1 { background: #c0392b; }
        .pw-strength-bar span.active-2 { background: #d97706; }
        .pw-strength-bar span.active-3 { background: #2f80c7; }
        .pw-strength-bar span.active-4 { background: #1e9e57; }
        .pw-strength-label { font-size: .72rem; color: #62748a; margin-top: 4px; }

        /* Submit button */
        .gov-btn-submit {
            width: 100%; padding: 11px; border-radius: 6px; border: none;
            background: #0b2a4a; color: #fff; font-weight: 700;
            font-size: .92rem; letter-spacing: .02em; cursor: pointer;
            transition: background .15s, box-shadow .15s;
            box-shadow: 0 2px 8px rgba(11,42,74,.25);
        }
        .gov-btn-submit:hover { background: #0e3560; box-shadow: 0 4px 14px rgba(11,42,74,.32); }
        .gov-btn-submit:active { background: #061b31; }

        /* Alerts */
        .gov-alert { border-radius: 6px; padding: 10px 14px; font-size: .83rem; font-weight: 600; margin-bottom: 18px; border: 1px solid; }
        .gov-alert-danger  { background: #fff2f1; border-color: #f5c6c2; color: #7a1a10; border-left: 4px solid #c0392b; }
        .gov-alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; border-left: 4px solid #1e9e57; }

        /* Divider + back link */
        .gov-card-divider { border: none; border-top: 1px solid #e8eef5; margin: 22px 0 18px; }
        .gov-portal-link {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 14px; border-radius: 6px;
            background: #f4f8fc; border: 1px solid #d0dae6;
            text-decoration: none; transition: background .15s, border-color .15s;
        }
        .gov-portal-link:hover { background: #eaf2fb; border-color: #b0c4d8; }
        .gov-portal-link-text   { font-size: .78rem; color: #62748a; font-weight: 500; }
        .gov-portal-link-action { font-size: .78rem; color: #1d6aad; font-weight: 700; white-space: nowrap; }

        /* Footer */
        .gov-footer { background: #0b2a4a; border-top: 1px solid rgba(255,255,255,.07); padding: 14px 24px; text-align: center; }
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

    <main class="gov-main">
        <div class="gov-register-wrap">

            <!-- Logo block -->
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
                <p class="gov-logo-subtitle">Staff Account Registration</p>
            </div>

            <!-- Info notice -->
            <div class="gov-info-notice" role="note">
                <p>
                    <strong>For CPDO Employees Only.</strong>
                    After registering, sign in and submit a designation request. A System Administrator will review and assign your official role before access is granted.
                </p>
            </div>

            <!-- Registration card -->
            <div class="gov-card">
                <h1 class="gov-card-title">Create Staff Account</h1>
                <p class="gov-card-sub">All fields marked with an asterisk (*) are required.</p>

                <?php if (!empty($_SESSION['flash_error'])): ?>
                    <div class="gov-alert gov-alert-danger" role="alert"><?= e($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <!-- Name row -->
                    <div class="row g-3 mb-3">
                        <div class="col-sm-5">
                            <label class="form-label" for="reg-first">First Name *</label>
                            <input class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>"
                                   type="text" id="reg-first" name="first_name"
                                   value="<?= e($formData['first_name'] ?? '') ?>"
                                   required autocomplete="given-name" placeholder="Juan">
                            <?php if (isset($errors['first_name'])): ?>
                                <div class="invalid-feedback"><?= e($errors['first_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label" for="reg-middle">Middle Name</label>
                            <input class="form-control" type="text" id="reg-middle" name="middle_name"
                                   value="<?= e($formData['middle_name'] ?? '') ?>"
                                   autocomplete="additional-name" placeholder="Optional">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="reg-last">Last Name *</label>
                            <input class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>"
                                   type="text" id="reg-last" name="last_name"
                                   value="<?= e($formData['last_name'] ?? '') ?>"
                                   required autocomplete="family-name" placeholder="Dela Cruz">
                            <?php if (isset($errors['last_name'])): ?>
                                <div class="invalid-feedback"><?= e($errors['last_name']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Email -->
                    <div class="mb-3">
                        <label class="form-label" for="reg-email">Official Email Address *</label>
                        <input class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               type="email" id="reg-email" name="email"
                               value="<?= e($formData['email'] ?? '') ?>"
                               required autocomplete="email" placeholder="officer@cpdo.gov.ph">
                        <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Password -->
                    <div class="mb-3">
                        <label class="form-label" for="reg-password">Password *</label>
                        <div class="password-wrap">
                            <input class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                                   type="password" id="reg-password" name="password"
                                   required autocomplete="new-password" placeholder="Min. 8 chars, uppercase, number">
                            <button type="button" class="pw-toggle" aria-label="Show password" data-target="reg-password">
                                <svg class="pw-eye pw-eye-show" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4C5.5 4 2 10 2 10s3.5 6 8 6 8-6 8-6-3.5-6-8-6z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                                <svg class="pw-eye pw-eye-hide" viewBox="0 0 20 20" fill="none" aria-hidden="true" style="display:none"><path d="M3 3l14 14M10 4C5.5 4 2 10 2 10s.9 1.5 2.5 3M17.5 7C18.8 8.5 18 10 18 10s-3.5 6-8 6c-1.5 0-2.9-.4-4-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                            </button>
                        </div>
                        <!-- Strength bar -->
                        <div class="pw-strength-bar" id="pw-strength-bar" aria-hidden="true">
                            <span id="ps1"></span><span id="ps2"></span><span id="ps3"></span><span id="ps4"></span>
                        </div>
                        <p class="pw-strength-label" id="pw-strength-label"></p>
                        <?php if (isset($errors['password'])): ?>
                            <div class="invalid-feedback d-block"><?= e($errors['password']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Confirm password -->
                    <div class="mb-4">
                        <label class="form-label" for="reg-password2">Confirm Password *</label>
                        <div class="password-wrap">
                            <input class="form-control <?= isset($errors['password2']) ? 'is-invalid' : '' ?>"
                                   type="password" id="reg-password2" name="password2"
                                   required autocomplete="new-password" placeholder="Re-enter password">
                            <button type="button" class="pw-toggle" aria-label="Show password" data-target="reg-password2">
                                <svg class="pw-eye pw-eye-show" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4C5.5 4 2 10 2 10s3.5 6 8 6 8-6 8-6-3.5-6-8-6z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                                <svg class="pw-eye pw-eye-hide" viewBox="0 0 20 20" fill="none" aria-hidden="true" style="display:none"><path d="M3 3l14 14M10 4C5.5 4 2 10 2 10s.9 1.5 2.5 3M17.5 7C18.8 8.5 18 10 18 10s-3.5 6-8 6c-1.5 0-2.9-.4-4-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                            </button>
                        </div>
                        <?php if (isset($errors['password2'])): ?>
                            <div class="invalid-feedback d-block"><?= e($errors['password2']) ?></div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="gov-btn-submit">Create Account</button>
                </form>

                <hr class="gov-card-divider">

                <a class="gov-portal-link" href="<?= e($cpdoUrl) ?>/login.php">
                    <span class="gov-portal-link-text">Already have an account?</span>
                    <span class="gov-portal-link-action">Sign In &rarr;</span>
                </a>
            </div>

        </div>
    </main>

    <footer class="gov-footer">
        <p>City Planning and Development Office &mdash; Davao City &mdash; Authorized Personnel Only</p>
    </footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e($pubUrl) ?>/assets/js/dlp.js"></script>
<script>
// Password show/hide toggle
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

// Password strength meter
(function() {
    var input  = document.getElementById('reg-password');
    var bars   = [document.getElementById('ps1'), document.getElementById('ps2'),
                  document.getElementById('ps3'), document.getElementById('ps4')];
    var label  = document.getElementById('pw-strength-label');
    var levels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
    var colors = ['', 'active-1', 'active-2', 'active-3', 'active-4'];

    function score(pw) {
        var s = 0;
        if (pw.length >= 8)              s++;
        if (/[A-Z]/.test(pw))            s++;
        if (/[0-9]/.test(pw))            s++;
        if (/[^A-Za-z0-9]/.test(pw))     s++;
        return s;
    }

    input.addEventListener('input', function() {
        var s = input.value.length ? score(input.value) : 0;
        bars.forEach(function(b, i) {
            b.className = (i < s) ? colors[s] : '';
        });
        label.textContent = s > 0 ? levels[s] : '';
    });
})();
</script>
</body>
</html>
