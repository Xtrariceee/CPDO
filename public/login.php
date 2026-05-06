<?php
require_once __DIR__ . '/../app/bootstrap.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $stmt     = db()->prepare('SELECT * FROM users WHERE email = ? AND status = "ACTIVE"');
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

    audit_log($user ? (int)$user['id'] : null, 'LOGIN_FAILED', 'users', $user ? (int)$user['id'] : null, ['email' => $email]);
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

require __DIR__ . '/partials/header.php';
?>
<section class="auth-shell compact">
    <div class="auth-panel">
        <p class="eyebrow">Welcome Back</p>
        <h1>Sign in to continue</h1>
        <p>Verified accounts can access role-based dashboards, CPDO workflows, and rental tools.</p>
    </div>
    <form class="auth-card" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email" required autocomplete="email">
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <div class="password-wrap">
                <input class="form-control" type="password" name="password" id="login-password"
                       required autocomplete="current-password">
                <button type="button" class="pw-toggle" aria-label="Show password"
                        data-target="login-password">
                    <svg class="pw-eye pw-eye-show" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4C5.5 4 2 10 2 10s3.5 6 8 6 8-6 8-6-3.5-6-8-6z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                    <svg class="pw-eye pw-eye-hide" viewBox="0 0 20 20" fill="none" aria-hidden="true" style="display:none"><path d="M3 3l14 14M10 4C5.5 4 2 10 2 10s.9 1.5 2.5 3M17.5 7C18.8 8.5 18 10 18 10s-3.5 6-8 6c-1.5 0-2.9-.4-4-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                </button>
            </div>
        </div>
        <button class="btn btn-primary w-100 mb-3">Login</button>

        <div class="divider-text my-3">or</div>

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
            <p class="small text-secondary text-center mt-2 mb-0">
                Google sign-in only works if you registered with Google and selected an account type.
            </p>
        <?php else: ?>
            <button class="btn btn-google w-100 disabled" disabled>Sign in with Google</button>
            <p class="small text-secondary mt-2 mb-0">Google OAuth: add credentials in <code>config/env.php</code>.</p>
        <?php endif; ?>

        <p class="text-center mt-3 mb-0">Need an account? <a href="register.php">Register</a></p>
    </form>
</section>

<script>
document.querySelectorAll('.pw-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input  = document.getElementById(btn.dataset.target);
        var isText = input.type === 'text';
        input.type = isText ? 'password' : 'text';
        btn.querySelector('.pw-eye-show').style.display = isText ? ''     : 'none';
        btn.querySelector('.pw-eye-hide').style.display = isText ? 'none' : '';
        btn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
    });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
