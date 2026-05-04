<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application = officer_application($applicationId);
    $order = payment_order_for_application($applicationId);
    if (!$order || $order['status'] !== 'PAID') {
        $_SESSION['flash_error'] = 'Payment receipt must be paid before inspection can be scheduled.';
        redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId);
    }

    $scheduledAt = trim($_POST['scheduled_at'] ?? '');
    $stmt = db()->prepare('INSERT INTO inspections (application_id, scheduled_at, assigned_by) VALUES (?, ?, ?)');
    $stmt->execute([$applicationId, $scheduledAt ?: null, (int)$user['id']]);
    advance_application($applicationId, 'INSPECTION_SCHEDULED', 7);
    notify_user((int)$application['landlord_id'], $applicationId, 'Inspection scheduled', 'Your CPDO inspection has been scheduled.');
    notify_role(ROLE_TWG, $applicationId, 'New inspection assignment', 'A CPDO inspection is ready for TWG field validation.');
    audit_log((int)$user['id'], 'P6_P7_PAYMENT_VERIFIED_INSPECTION_SCHEDULED', 'applications', $applicationId, ['scheduled_at' => $scheduledAt]);
    $_SESSION['flash_success'] = 'Payment verified, inspection scheduled, and notifications queued for landlord and TWG members.';
    redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId);
}

$applications = officer_applications(['PAID']);
$application = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$order = $application ? payment_order_for_application((int)$application['id']) : null;

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">P6-P7 Payment Verification and Inspection Scheduling</h1>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">Paid Applications</h2>
            <div class="list-group">
                <?php foreach ($applications as $row): ?>
                    <a class="list-group-item list-group-item-action <?= $application && (int)$application['id'] === (int)$row['id'] ? 'active' : '' ?>" href="payment-scheduling.php?id=<?= (int)$row['id'] ?>">
                        <?= e($row['registry_number']) ?><br><small><?= e($row['property_title']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$applications): ?><div class="text-secondary small">No paid applications are ready for scheduling.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($application && $order): ?>
            <form class="gov-card p-4" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                <h2 class="h5">Payment Receipt</h2>
                <dl class="row">
                    <dt class="col-sm-4">OP Number</dt><dd class="col-sm-8"><?= e($order['op_number']) ?></dd>
                    <dt class="col-sm-4">Registry Number</dt><dd class="col-sm-8"><?= e($order['registry_number']) ?></dd>
                    <dt class="col-sm-4">Receipt Number</dt><dd class="col-sm-8"><?= e($order['receipt_number'] ?? 'Pending receipt') ?></dd>
                    <dt class="col-sm-4">Paid At</dt><dd class="col-sm-8"><?= e($order['paid_at'] ?? 'Not paid') ?></dd>
                    <dt class="col-sm-4">Fee</dt><dd class="col-sm-8"><?= currency_php((float)$order['service_fee']) ?></dd>
                </dl>
                <label class="form-label">Inspection Schedule</label>
                <input class="form-control mb-3" type="datetime-local" name="scheduled_at" required>
                <button class="btn btn-primary">Verify Payment and Schedule Inspection</button>
            </form>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">No payment receipts ready for verification.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
