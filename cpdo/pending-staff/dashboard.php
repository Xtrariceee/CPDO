<?php
/**
 * CPDO Staff Portal — Pending Staff Dashboard
 * Shown to self-registered employees whose role is still "pending_staff".
 * The only action available is submitting a designation request for admin approval.
 */
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_pending_staff(); // redirects fully-provisioned staff to their real dashboard

verify_csrf();

// ── Fetch any existing pending/approved/rejected designation request ─────────
$existingReq = db()->prepare(
    'SELECT * FROM staff_designation_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
);
$existingReq->execute([(int)$user['id']]);
$request = $existingReq->fetch();

$successMsg = null;
$errorMsg   = null;

// ── Handle form submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$request) {
    $requested = $_POST['requested_role'] ?? '';
    $allowed   = [ROLE_ADMIN_OFFICER, ROLE_ZONING, ROLE_TWG];

    if (!in_array($requested, $allowed, true)) {
        $errorMsg = 'Please select a valid designation.';
    } else {
        db()->prepare(
            'INSERT INTO staff_designation_requests (user_id, requested_role, status)
             VALUES (?, ?, "PENDING")'
        )->execute([(int)$user['id'], $requested]);

        audit_log(
            (int)$user['id'],
            'DESIGNATION_REQUEST_SUBMITTED',
            'staff_designation_requests',
            null,
            ['requested_role' => $requested]
        );

        // Re-fetch so the UI reflects the new request immediately
        $existingReq->execute([(int)$user['id']]);
        $request = $existingReq->fetch();

        $successMsg = 'Designation request submitted. The System Administrator will review it shortly.';
    }
}

$cpdoUrl = rtrim($config['app']['cpdo_url'] ?? str_replace('/public', '/cpdo', $config['app']['base_url']), '/');
$pubUrl  = rtrim($config['app']['base_url'], '/');
$cpdoLogoUrl = cpdo_logo_url($config);

// ── Status display helpers ────────────────────────────────────────────────────
$statusConfig = [
    'PENDING'  => ['label' => 'Pending Review',  'badge' => 'text-bg-warning',   'icon' => 'Pending'],
    'APPROVED' => ['label' => 'Approved',         'badge' => 'text-bg-success',   'icon' => 'Approved'],
    'REJECTED' => ['label' => 'Rejected',         'badge' => 'text-bg-danger',    'icon' => 'Rejected'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pending Designation — CPDO Land Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($pubUrl) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        /* ── Matches the CPDO portal solid government theme ── */
        body {
            background-color: #eef2f7;
            background-image: radial-gradient(circle, #c8d4e3 1px, transparent 1px);
            background-size: 28px 28px;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        /* ── Topbar — identical to CPDO portal header ── */
        .topbar {
            background: #0b2a4a;
            border-bottom: 3px solid #1d6aad;
            box-shadow: 0 2px 12px rgba(11,42,74,.25);
        }
        .gov-nav-seal {
            width: 34px; height: 34px; border-radius: 50%;
            background: #fff; border: 2px solid rgba(255,255,255,.25);
            display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .gov-nav-seal-inner {
            width: 26px; height: 26px; border-radius: 50%;
            background: linear-gradient(135deg, #0b2a4a, #1d6aad);
            display: flex; align-items: center; justify-content: center;
            font-size: .55rem; font-weight: 900; color: #fff;
            letter-spacing: .02em; text-align: center; line-height: 1.1;
        }
        .cpdo-portal-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 9px; border-radius: 4px;
            background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18);
            color: rgba(255,255,255,.7); font-size: .65rem; font-weight: 800;
            letter-spacing: .08em; text-transform: uppercase;
        }
        .cpdo-portal-badge-dot { width: 5px; height: 5px; border-radius: 50%; background: #f59e0b; flex-shrink: 0; }
        .topbar .navbar-brand { font-weight: 800; font-size: .95rem; }
        .topbar .nav-link { font-size: .83rem; font-weight: 600; opacity: .8; }
        .topbar .nav-link:hover { opacity: 1; }

        /* ── Page shell ── */
        .page-shell {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 20px 56px;
        }

        /* ── Centered layout ── */
        .pending-wrap {
            max-width: 600px;
            margin: 0 auto;
        }

        /* ── Access restriction banner ── */
        .access-banner {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-left: 5px solid #f59e0b;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .access-banner-icon {
            font-size: 1.4rem;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .access-banner-title {
            font-size: .92rem;
            font-weight: 800;
            color: #78350f;
            margin-bottom: 3px;
        }
        .access-banner-body {
            font-size: .8rem;
            color: #92400e;
            margin: 0;
            line-height: 1.55;
        }

        /* ── Main card ── */
        .gov-card {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(11,42,74,.05), 0 8px 24px rgba(11,42,74,.08);
        }
        .gov-card-header {
            padding: 22px 28px 18px;
            border-bottom: 1px solid #e8eef5;
        }
        .gov-card-header-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #0b2a4a;
            margin-bottom: 3px;
        }
        .gov-card-header-sub {
            font-size: .8rem;
            color: #62748a;
            margin: 0;
        }
        .gov-card-body { padding: 24px 28px 28px; }

        /* ── Step indicator ── */
        .step-list {
            display: flex;
            flex-direction: column;
            gap: 0;
            margin-bottom: 28px;
        }
        .step-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid #edf2f7;
        }
        .step-item:last-child { border-bottom: none; }
        .step-num {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .72rem; font-weight: 900; flex-shrink: 0;
            margin-top: 1px;
        }
        .step-num--done    { background: #dcfce7; color: #14532d; }
        .step-num--active  { background: #0b2a4a; color: #fff; }
        .step-num--pending { background: #f0f5fb; color: #9aaabd; }
        .step-label { font-size: .85rem; font-weight: 700; color: #0b2a4a; margin-bottom: 2px; }
        .step-sub   { font-size: .75rem; color: #62748a; margin: 0; }
        .step-label--pending { color: #9aaabd; }

        /* ── Form elements ── */
        .form-label {
            font-size: .78rem; font-weight: 700; color: #3a5068;
            letter-spacing: .04em; text-transform: uppercase; margin-bottom: 6px; display: block;
        }
        .form-select {
            border: 1.5px solid #c5d3df; border-radius: 6px;
            background: #f8fbff; color: #121212;
            font-size: .9rem; padding: 10px 13px; min-height: 42px;
            width: 100%;
            transition: border-color .15s, box-shadow .15s;
        }
        .form-select:focus {
            border-color: #1d6aad; background: #fff;
            box-shadow: 0 0 0 3px rgba(29,106,173,.14); outline: none;
        }
        .form-hint {
            font-size: .75rem; color: #62748a; margin-top: 6px; line-height: 1.5;
        }

        /* ── Submit button ── */
        .gov-btn-submit {
            padding: 10px 28px; border-radius: 6px; border: none;
            background: #0b2a4a; color: #fff; font-weight: 700;
            font-size: .9rem; cursor: pointer;
            transition: background .15s, box-shadow .15s;
            box-shadow: 0 2px 8px rgba(11,42,74,.22);
        }
        .gov-btn-submit:hover { background: #0e3560; box-shadow: 0 4px 14px rgba(11,42,74,.30); }
        .gov-btn-submit:active { background: #061b31; }

        /* ── Status card (shown after request submitted) ── */
        .status-card {
            border-radius: 8px;
            padding: 20px 22px;
            border: 1px solid;
        }
        .status-card--pending  { background: #fffbeb; border-color: #fde68a; }
        .status-card--approved { background: #f0fdf4; border-color: #bbf7d0; }
        .status-card--rejected { background: #fff2f1; border-color: #fecaca; }

        .status-card-title {
            font-size: .92rem; font-weight: 800; margin-bottom: 4px;
        }
        .status-card--pending  .status-card-title { color: #78350f; }
        .status-card--approved .status-card-title { color: #14532d; }
        .status-card--rejected .status-card-title { color: #7a1a10; }

        .status-card-body {
            font-size: .8rem; margin: 0; line-height: 1.55;
        }
        .status-card--pending  .status-card-body { color: #92400e; }
        .status-card--approved .status-card-body { color: #166534; }
        .status-card--rejected .status-card-body { color: #991b1b; }

        /* ── Alerts ── */
        .gov-alert { border-radius: 6px; padding: 10px 14px; font-size: .83rem; font-weight: 600; margin-bottom: 18px; border: 1px solid; }
        .gov-alert-danger  { background: #fff2f1; border-color: #f5c6c2; color: #7a1a10; border-left: 4px solid #c0392b; }
        .gov-alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; border-left: 4px solid #1e9e57; }

        /* ── User info strip ── */
        .user-strip {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px; border-radius: 8px;
            background: #f4f8fc; border: 1px solid #d0dae6;
            margin-bottom: 24px;
        }
        .user-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: #0b2a4a; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 900; font-size: .9rem; flex-shrink: 0;
        }
        .user-name  { font-size: .88rem; font-weight: 700; color: #0b2a4a; }
        .user-email { font-size: .75rem; color: #62748a; }
    </style>
</head>
<body>

<!-- Topbar -->
<nav class="navbar navbar-expand-lg navbar-dark topbar">
    <div class="container-fluid page-shell py-0" style="padding-top:10px!important;padding-bottom:10px!important;">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e($cpdoUrl) ?>/index.php">
            <div class="gov-nav-seal">
                <?php if ($cpdoLogoUrl): ?>
                    <img class="cpdo-logo-img cpdo-logo-img--nav" src="<?= e($cpdoLogoUrl) ?>" alt="CPDO logo">
                <?php else: ?>
                    <div class="gov-nav-seal-inner">CPDO</div>
                <?php endif; ?>
            </div>
            <div>
                <span style="display:block;font-size:.9rem;font-weight:800;line-height:1.2;">CPDO Land Portal</span>
                <span style="display:block;font-size:.62rem;color:rgba(255,255,255,.5);font-weight:500;line-height:1;">City Planning &amp; Development Office</span>
            </div>
            <span class="cpdo-portal-badge ms-1">
                <span class="cpdo-portal-badge-dot"></span>
                Staff
            </span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <li class="nav-item">
                    <span class="nav-link"><?= e(user_full_name($user)) ?> · Pending Staff</span>
                </li>
                <li class="nav-item">
                    <a class="btn btn-outline-light btn-sm" href="<?= e($cpdoUrl) ?>/logout.php">Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main class="page-shell">
    <div class="pending-wrap">

        <!-- Access restriction banner -->
        <div class="access-banner" role="alert">
            <div class="access-banner-icon" aria-hidden="true">LOCK</div>
            <div>
                <p class="access-banner-title">Access Restricted — Designation Pending</p>
                <p class="access-banner-body">
                    This account has not yet been assigned an official designation.
                    Submit a request below and a System Administrator will review it.
                    Full portal access is granted only after approval.
                </p>
            </div>
        </div>

        <!-- Main card -->
        <div class="gov-card">
            <div class="gov-card-header">
                <h1 class="gov-card-header-title">Submit Designation Request</h1>
                <p class="gov-card-header-sub">Select the official role that matches your CPDO position.</p>
            </div>
            <div class="gov-card-body">

                <!-- User info strip -->
                <div class="user-strip">
                    <div class="user-avatar" aria-hidden="true">
                        <?= mb_strtoupper(mb_substr($user['first_name'], 0, 1)) ?>
                    </div>
                    <div>
                        <p class="user-name mb-0"><?= e(user_full_name($user)) ?></p>
                        <p class="user-email mb-0"><?= e($user['email']) ?></p>
                    </div>
                    <span class="badge text-bg-warning ms-auto">Pending Staff</span>
                </div>

                <!-- Onboarding steps -->
                <div class="step-list" aria-label="Onboarding steps">
                    <div class="step-item">
                        <div class="step-num step-num--done" aria-label="Completed">OK</div>
                        <div>
                            <p class="step-label">Account Created</p>
                            <p class="step-sub">Registration complete.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num step-num--done" aria-label="Completed">OK</div>
                        <div>
                            <p class="step-label">Signed In</p>
                            <p class="step-sub">Identity verified via credentials.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num <?= $request ? 'step-num--done' : 'step-num--active' ?>" aria-label="<?= $request ? 'Completed' : 'Current step' ?>">
                            <?= $request ? 'OK' : '3' ?>
                        </div>
                        <div>
                            <p class="step-label">Submit Designation Request</p>
                            <p class="step-sub">Choose your official CPDO role for admin review.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num step-num--pending" aria-label="Pending">4</div>
                        <div>
                            <p class="step-label step-label--pending">Admin Approval</p>
                            <p class="step-sub">System Administrator reviews and assigns the role.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num step-num--pending" aria-label="Pending">5</div>
                        <div>
                            <p class="step-label step-label--pending">Full Portal Access</p>
                            <p class="step-sub">Sign out and back in to activate the new role.</p>
                        </div>
                    </div>
                </div>

                <!-- Flash messages -->
                <?php if ($successMsg): ?>
                    <div class="gov-alert gov-alert-success" role="alert"><?= e($successMsg) ?></div>
                <?php endif; ?>
                <?php if ($errorMsg): ?>
                    <div class="gov-alert gov-alert-danger" role="alert"><?= e($errorMsg) ?></div>
                <?php endif; ?>

                <?php if ($request): ?>
                    <!-- ── Request already submitted — show status ── -->
                    <?php
                        $sc  = $statusConfig[$request['status']] ?? $statusConfig['PENDING'];
                        $cls = strtolower($request['status']);
                    ?>
                    <div class="status-card status-card--<?= e($cls) ?>">
                        <p class="status-card-title">
                            <?= $sc['icon'] ?> Designation Request: <?= e($sc['label']) ?>
                        </p>
                        <p class="status-card-body">
                            <?php if ($request['status'] === 'PENDING'): ?>
                                Request for <strong><?= e(role_label($request['requested_role'])) ?></strong>
                                is awaiting review. Check back later or contact the System Administrator.
                            <?php elseif ($request['status'] === 'APPROVED'): ?>
                                Request for <strong><?= e(role_label($request['requested_role'])) ?></strong>
                                was approved. Sign out and sign back in to activate full portal access.
                            <?php else: ?>
                                Request was rejected.
                                <?php if (!empty($request['admin_notes'])): ?>
                                    Admin note: <?= e($request['admin_notes']) ?>
                                <?php endif; ?>
                                Contact the System Administrator for assistance.
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php if ($request['status'] === 'APPROVED'): ?>
                        <div class="mt-3 text-center">
                            <a href="<?= e($cpdoUrl) ?>/logout.php" class="gov-btn-submit" style="display:inline-block;text-decoration:none;">
                                Sign Out &amp; Re-Login to Activate
                            </a>
                        </div>
                    <?php elseif ($request['status'] === 'REJECTED'): ?>
                        <!-- Allow re-submission after rejection -->
                        <div class="mt-3">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <?php
                                    // Temporarily unset $request so the form renders
                                    // (handled by re-checking $request in the outer if)
                                ?>
                                <p class="form-hint mb-2">Submit a new designation request:</p>
                                <div class="mb-3">
                                    <label class="form-label" for="requested-role-retry">Designation *</label>
                                    <select class="form-select" id="requested-role-retry" name="requested_role" required>
                                        <option value="" disabled selected>— Select your designation —</option>
                                        <option value="<?= ROLE_ADMIN_OFFICER ?>">Administrative Officer</option>
                                        <option value="<?= ROLE_ZONING ?>">Zoning Officer IV</option>
                                        <option value="<?= ROLE_TWG ?>">LZRC TWG Member</option>
                                    </select>
                                </div>
                                <button type="submit" class="gov-btn-submit">Resubmit Request</button>
                            </form>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- ── No request yet — show the form ── -->
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                        <div class="mb-4">
                            <label class="form-label" for="requested-role">Official Designation *</label>
                            <select class="form-select" id="requested-role" name="requested_role" required>
                                <option value="" disabled selected>— Select your designation —</option>
                                <option value="<?= ROLE_ADMIN_OFFICER ?>">Administrative Officer</option>
                                <option value="<?= ROLE_ZONING ?>">Zoning Officer IV</option>
                                <option value="<?= ROLE_TWG ?>">LZRC TWG Member</option>
                            </select>
                            <p class="form-hint">
                                Select the role that matches your official CPDO position.
                                The System Administrator will verify this before granting access.
                            </p>
                        </div>

                        <button type="submit" class="gov-btn-submit">Submit Designation Request</button>
                    </form>
                <?php endif; ?>

            </div><!-- /gov-card-body -->
        </div><!-- /gov-card -->

    </div><!-- /pending-wrap -->
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e($pubUrl) ?>/assets/js/dlp.js"></script>
</body>
</html>
