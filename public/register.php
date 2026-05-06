<?php
require_once __DIR__ . '/../app/bootstrap.php';
verify_csrf();

// ── Handle Google "complete registration" flow (set role for existing Google account) ──
$googleComplete = !empty($_GET['google_complete']) && !empty($_SESSION['google_pending_role_user_id']);
if ($googleComplete && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'google_set_role') {
    $pendingId = (int)$_SESSION['google_pending_role_user_id'];
    $role      = $_POST['role'] ?? '';
    $allowedSelfRoles = [ROLE_LANDLORD, ROLE_TENANT];
    if ($pendingId > 0 && in_array($role, $allowedSelfRoles, true)) {
        db()->prepare('UPDATE users SET role = ?, google_registered_role = ? WHERE id = ?')
           ->execute([$role, $role, $pendingId]);
        unset($_SESSION['google_pending_role_user_id']);
        audit_log($pendingId, 'GOOGLE_ROLE_SET', 'users', $pendingId, ['role' => $role]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $pendingId;
        $fresh = db()->prepare('SELECT role FROM users WHERE id = ?');
        $fresh->execute([$pendingId]);
        redirect(dashboard_for_role((string)$fresh->fetchColumn()));
    }
    $_SESSION['flash_error'] = 'Invalid account type.';
}

// ── Standard email/password registration ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'google_set_role') {
    $firstName       = trim($_POST['first_name'] ?? '');
    $middleName      = trim($_POST['middle_name'] ?? '');
    $lastName        = trim($_POST['last_name'] ?? '');
    $email           = strtolower(trim($_POST['email'] ?? ''));
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role            = $_POST['role'] ?? ROLE_LANDLORD;
    $allowedSelfRoles = [ROLE_LANDLORD, ROLE_TENANT];
    $agreed          = isset($_POST['terms']);

    if (!$firstName || !$lastName || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowedSelfRoles, true)) {
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
            $stmt->execute([$firstName, $middleName ?: null, $lastName, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
            $userId      = (int)$pdo->lastInsertId();
            $displayName = trim($firstName . ' ' . $lastName);
            $otpSent     = issue_user_otp($userId, $email, $displayName);
            audit_log($userId, 'REGISTERED_PENDING_OTP', 'users', $userId);
            $_SESSION['pending_verification_email'] = $email;

            if ($otpSent) {
                $_SESSION['flash_success'] = 'Account created. Enter the 6-digit OTP sent to your email.';
            } else {
                $_SESSION['flash_error'] = 'Account created, but we could not send the OTP email. Please try again or contact support.';
            }

            redirect('verify_otp.php');
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = 'Email is already registered.';
        }
    }
}

// Build Google OAuth URL for sign-up
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

<?php if ($googleComplete && !empty($_SESSION['google_pending_role_user_id'])): ?>
<!-- ── Google Complete Registration: choose role ── -->
<section class="auth-shell compact">
    <div class="auth-panel">
        <p class="eyebrow">Almost there</p>
        <h1>Choose your account type</h1>
        <p>Your Google account was found. Select how you'll use the CPDO Portal to complete registration.</p>
    </div>
    <form class="auth-card" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="google_set_role">
        <div class="mb-4">
            <label class="form-label">Account Type</label>
            <select class="form-select" name="role" required>
                <option value="<?= ROLE_LANDLORD ?>">Landlord — I own or manage rental properties</option>
                <option value="<?= ROLE_TENANT ?>">Tenant — I'm looking for a rental property</option>
            </select>
        </div>
        <button class="btn btn-primary w-100">Complete Registration</button>
    </form>
</section>

<?php else: ?>
<!-- ── Standard Registration ── -->
<section class="auth-shell">
    <div class="auth-panel">
        <p class="eyebrow">Secure Registration</p>
        <h1>Create your CPDO Portal account</h1>
        <p>Verify your email with a one-time code before accessing workflow or rental services.</p>
    </div>
    <form class="auth-card" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-grid">
            <div>
                <label class="form-label">First Name</label>
                <input class="form-control" name="first_name" required>
            </div>
            <div>
                <label class="form-label">Middle Name <span class="text-secondary">(optional)</span></label>
                <input class="form-control" name="middle_name">
            </div>
            <div>
                <label class="form-label">Last Name</label>
                <input class="form-control" name="last_name" required>
            </div>
            <div>
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="email" required>
            </div>
            <div>
                <label class="form-label">Password</label>
                <div class="password-wrap">
                    <input class="form-control" type="password" name="password" id="reg-password"
                           required minlength="8" data-password autocomplete="new-password">
                    <button type="button" class="pw-toggle" aria-label="Show password"
                            data-target="reg-password">
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
            <div>
                <label class="form-label">Confirm Password</label>
                <div class="password-wrap">
                    <input class="form-control" type="password" name="confirm_password" id="reg-confirm"
                           required minlength="8" data-confirm-password autocomplete="new-password">
                    <button type="button" class="pw-toggle" aria-label="Show password"
                            data-target="reg-confirm">
                        <svg class="pw-eye pw-eye-show" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4C5.5 4 2 10 2 10s3.5 6 8 6 8-6 8-6-3.5-6-8-6z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                        <svg class="pw-eye pw-eye-hide" viewBox="0 0 20 20" fill="none" aria-hidden="true" style="display:none"><path d="M3 3l14 14M10 4C5.5 4 2 10 2 10s.9 1.5 2.5 3M17.5 7C18.8 8.5 18 10 18 10s-3.5 6-8 6c-1.5 0-2.9-.4-4-1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                    </button>
                </div>
                <div class="match-meter" data-match-meter>Passwords have not been matched yet.</div>
            </div>
        </div>
        <div class="mt-3">
            <label class="form-label">Account Type</label>
            <select class="form-select" name="role">
                <option value="<?= ROLE_LANDLORD ?>">Landlord — I own or manage rental properties</option>
                <option value="<?= ROLE_TENANT ?>">Tenant — I'm looking for a rental property</option>
            </select>
        </div>
        <label class="check-row mt-3">
            <input type="checkbox" name="terms" required>
            <span>I agree to the <a href="#" class="link-primary">Terms and Conditions</a>.</span>
        </label>
        <button class="btn btn-primary w-100 mt-4">Register and Send OTP</button>

        <!-- Google Sign-Up -->
        <div class="divider-text my-3">or sign up with</div>
        <?php if ($googleConfigured): ?>
            <div class="mb-3">
                <label class="form-label">Account Type for Google Sign-Up</label>
                <select class="form-select" id="google-role-select">
                    <option value="<?= ROLE_LANDLORD ?>">Landlord</option>
                    <option value="<?= ROLE_TENANT ?>">Tenant</option>
                </select>
            </div>
            <button type="button" class="btn btn-google w-100" id="google-signup-btn">
                <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" style="flex-shrink:0">
                    <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.875 2.684-6.615z"/>
                    <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z"/>
                    <path fill="#FBBC05" d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z"/>
                    <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 6.29C4.672 4.163 6.656 3.58 9 3.58z"/>
                </svg>
                Sign up with Google
            </button>
        <?php else: ?>
            <button class="btn btn-google w-100 disabled" disabled>
                Sign up with Google
            </button>
            <p class="small text-secondary mt-2 mb-0">Google OAuth: add credentials in <code>config/env.php</code>.</p>
        <?php endif; ?>

        <p class="text-center mt-3 mb-0">Already have an account? <a href="login.php">Login</a></p>
    </form>
</section>
<?php endif; ?>

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

function setRule(element, valid) {
    if (element) element.classList.toggle('is-valid', valid);
}

function updatePasswordState() {
    if (!passwordInput) return;
    const password = passwordInput.value;
    const confirm  = confirmInput ? confirmInput.value : '';
    setRule(rules.length, password.length >= 8);
    setRule(rules.upper,  /[A-Z]/.test(password));
    setRule(rules.lower,  /[a-z]/.test(password));
    setRule(rules.number, /[0-9]/.test(password));

    if (!matchMeter) return;
    if (!confirm) {
        matchMeter.textContent = 'Passwords have not been matched yet.';
        matchMeter.classList.remove('is-valid', 'is-invalid');
        return;
    }
    const matches = password === confirm;
    matchMeter.textContent = matches ? 'Passwords match.' : 'Passwords do not match.';
    matchMeter.classList.toggle('is-valid', matches);
    matchMeter.classList.toggle('is-invalid', !matches);
}

if (passwordInput) passwordInput.addEventListener('input', updatePasswordState);
if (confirmInput)  confirmInput.addEventListener('input', updatePasswordState);

// Password visibility toggles
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

// Google sign-up: store selected role in session via hidden form, then redirect
const googleBtn = document.getElementById('google-signup-btn');
if (googleBtn) {
    googleBtn.addEventListener('click', function () {
        const role = document.getElementById('google-role-select').value;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'google_signup_init.php';
        const csrf = document.createElement('input');
        csrf.type  = 'hidden';
        csrf.name  = 'csrf_token';
        csrf.value = <?= json_encode(csrf_token()) ?>;
        const roleInput = document.createElement('input');
        roleInput.type  = 'hidden';
        roleInput.name  = 'role';
        roleInput.value = role;
        form.appendChild(csrf);
        form.appendChild(roleInput);
        document.body.appendChild(form);
        form.submit();
    });
}
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
