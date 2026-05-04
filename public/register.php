<?php
require_once __DIR__ . '/../app/bootstrap.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? ROLE_LANDLORD;
    $allowedSelfRoles = [ROLE_LANDLORD, ROLE_TENANT];
    $agreed = isset($_POST['terms']);

    if (!$firstName || !$lastName || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowedSelfRoles, true)) {
        $_SESSION['flash_error'] = 'Please provide valid registration details.';
    } elseif ($password !== $confirmPassword) {
        $_SESSION['flash_error'] = 'Password and confirm password must match.';
    } elseif (!password_is_strong($password)) {
        $_SESSION['flash_error'] = 'Password must be at least 8 characters and include uppercase, lowercase, and a number.';
    } elseif (!$agreed) {
        $_SESSION['flash_error'] = 'You must agree to the Terms and Conditions before creating an account.';
    } else {
        $stmt = db()->prepare(
            'INSERT INTO users (first_name, middle_name, last_name, email, password_hash, role, is_verified)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        try {
            $stmt->execute([$firstName, $middleName ?: null, $lastName, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
            $userId = (int)db()->lastInsertId();
            $displayName = trim($firstName . ' ' . $lastName);
            $otpSent = issue_user_otp($userId, $email, $displayName);
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

require __DIR__ . '/partials/header.php';
?>
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
                <input class="form-control" type="password" name="password" required minlength="8" data-password>
                <div class="password-meter" data-password-meter>
                    <span data-rule-length>8 or more characters</span>
                    <span data-rule-upper>Uppercase letter</span>
                    <span data-rule-lower>Lowercase letter</span>
                    <span data-rule-number>Number</span>
                </div>
            </div>
            <div>
                <label class="form-label">Confirm Password</label>
                <input class="form-control" type="password" name="confirm_password" required minlength="8" data-confirm-password>
                <div class="match-meter" data-match-meter>Passwords have not been matched yet.</div>
            </div>
        </div>
        <div class="mt-3">
            <label class="form-label">Account Type</label>
            <select class="form-select" name="role">
                <option value="<?= ROLE_LANDLORD ?>">Landlord</option>
                <option value="<?= ROLE_TENANT ?>">Tenant</option>
            </select>
        </div>
        <label class="check-row mt-3">
            <input type="checkbox" name="terms" required>
            <span>I agree to the <a href="#" class="link-primary">Terms and Conditions</a>.</span>
        </label>
        <button class="btn btn-primary w-100 mt-4">Register and Send OTP</button>
        <p class="text-center mt-3 mb-0">Already have an account? <a href="login.php">Login</a></p>
    </form>
</section>
<script>
const passwordInput = document.querySelector('[data-password]');
const confirmInput = document.querySelector('[data-confirm-password]');
const rules = {
    length: document.querySelector('[data-rule-length]'),
    upper: document.querySelector('[data-rule-upper]'),
    lower: document.querySelector('[data-rule-lower]'),
    number: document.querySelector('[data-rule-number]')
};
const matchMeter = document.querySelector('[data-match-meter]');

function setRule(element, valid) {
    element.classList.toggle('is-valid', valid);
}

function updatePasswordState() {
    const password = passwordInput.value;
    const confirm = confirmInput.value;
    setRule(rules.length, password.length >= 8);
    setRule(rules.upper, /[A-Z]/.test(password));
    setRule(rules.lower, /[a-z]/.test(password));
    setRule(rules.number, /[0-9]/.test(password));

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

passwordInput.addEventListener('input', updatePasswordState);
confirmInput.addEventListener('input', updatePasswordState);
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
