<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);

$underEvaluation = officer_applications(['SUBMITTED', 'PRE_EVALUATION']);
$paid            = officer_applications(['PAID']);
$paymentToVerify = [];
$scheduleReady   = [];
foreach ($paid as $paidApplication) {
    $order = payment_order_for_application((int)$paidApplication['id']);
    if ($order && payment_order_is_verified($order)) {
        $scheduleReady[] = $paidApplication;
    } else {
        $paymentToVerify[] = $paidApplication;
    }
}

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">Zoning Officer IV Dashboard</h1>
<style>
.zo-card {
    display: block;
    height: 100%;
    padding: 18px 20px;
    border: 1px solid #d0dae6;
    border-left: 4px solid #1d6aad;
    border-radius: 8px;
    background: #fff;
    color: #0b2a4a;
    text-decoration: none;
    box-shadow: 0 2px 4px rgba(11,42,74,.05), 0 8px 20px rgba(11,42,74,.07);
}
.zo-card:hover {
    border-color: #b0c4d8;
    border-left-color: #0b2a4a;
    color: #0b2a4a;
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(11,42,74,.10);
}
.zo-card-title {
    font-size: .96rem;
    font-weight: 800;
    margin-bottom: 8px;
}
.zo-card-count {
    font-size: 2rem;
    line-height: 1;
    font-weight: 900;
}
.zo-card-note {
    font-size: .78rem;
    color: #62748a;
    margin-top: 8px;
}
</style>
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <a class="zo-card" href="pre-evaluation.php">
            <div class="zo-card-title">Pre-evaluation</div>
            <div class="zo-card-count"><?= count($underEvaluation) ?></div>
            <div class="zo-card-note">Documents awaiting review</div>
        </a>
    </div>
    <div class="col-md-3">
        <a class="zo-card" href="payment-verification.php">
            <div class="zo-card-title">Payment</div>
            <div class="zo-card-count"><?= count($paymentToVerify) ?></div>
            <div class="zo-card-note">Paid receipts awaiting verification</div>
        </a>
    </div>
    <div class="col-md-3">
        <a class="zo-card" href="inspection-scheduling.php">
            <div class="zo-card-title">Schedule Site Inspection</div>
            <div class="zo-card-count"><?= count($scheduleReady) ?></div>
            <div class="zo-card-note">Verified payments ready to schedule</div>
        </a>
    </div>
    <div class="col-md-3">
        <a class="zo-card" href="guidelines.php">
            <div class="zo-card-title">Requirement Guidelines</div>
            <div class="zo-card-note">Edit titles and details shown to landlords</div>
        </a>
    </div>
</div>
<section class="gov-card p-4">
    <h2 class="h5">Assigned Workflow Status</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Registry</th><th>Landlord</th><th>Property</th><th>Status</th><th>Pending Action</th></tr></thead>
            <tbody>
            <?php foreach (officer_applications() as $app): ?>
                <tr>
                    <td><?= e($app['registry_number']) ?></td>
                    <td><?= e($app['landlord_name']) ?></td>
                    <td><?= e($app['property_title']) ?></td>
                    <td><?= e(workflow_status_label($app['phase_status'])) ?></td>
                    <td>
                        <?php if (in_array($app['phase_status'], ['SUBMITTED','PRE_EVALUATION'], true)): ?>
                            <a class="btn btn-sm btn-primary" href="pre-evaluation.php?id=<?= (int)$app['id'] ?>">Evaluate</a>
                        <?php elseif ($app['phase_status'] === 'PAID'): ?>
                            <?php $rowOrder = payment_order_for_application((int)$app['id']) ?: []; ?>
                            <a class="btn btn-sm btn-primary" href="<?= payment_order_is_verified($rowOrder) ? 'inspection-scheduling' : 'payment-verification' ?>.php?id=<?= (int)$app['id'] ?>"><?= payment_order_is_verified($rowOrder) ? 'Schedule' : 'Verify Payment' ?></a>
                        <?php elseif ($app['phase_status'] === 'PAYMENT_PENDING'): ?>
                            <span class="badge text-bg-warning">Awaiting Payment</span>
                        <?php elseif ($app['phase_status'] === 'FOR_MEETING'): ?>
                            <a class="btn btn-sm btn-outline-primary" href="application-view.php?id=<?= (int)$app['id'] ?>">View</a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline-primary" href="application-view.php?id=<?= (int)$app['id'] ?>">View</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
