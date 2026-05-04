<?php
require_once __DIR__ . '/../app/bootstrap.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND status = "ACTIVE"');
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
        'client_id' => $config['google']['client_id'],
        'redirect_uri' => $config['google']['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'access_type' => 'online',
        'prompt' => 'select_account',
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
            <input class="form-control" type="email" name="email" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Password</label>
            <input class="form-control" type="password" name="password" required>
        </div>
        <button class="btn btn-primary w-100 mb-3">Login</button>
        <a class="btn btn-outline-dark w-100 <?= $googleConfigured ? '' : 'disabled' ?>" href="<?= e($googleUrl) ?>">Sign in with Google</a>
        <?php if (!$googleConfigured): ?>
            <p class="small text-secondary mt-2 mb-0">Google OAuth is ready; add credentials in <code>config/env.php</code>.</p>
        <?php endif; ?>
        <p class="text-center mt-3 mb-0">Need an account? <a href="register.php">Register</a></p>
    </form>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
