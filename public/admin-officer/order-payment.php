<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application = officer_application($applicationId);
    if (!requirements_all_passed($applicationId)) {
        $_SESSION['flash_error'] = 'Order of Payment can only be generated after all P3 requirements pass evaluation.';
        redirect('admin-officer/order-payment.php?id=' . $applicationId);
    }
    $order = create_payment_order_if_missing($application, (int)$user['id']);
    $_SESSION['flash_success'] = 'Order of Payment generated: ' . $order['op_number'];
    redirect('admin-officer/order-payment.php?id=' . $applicationId);
}

$applications = officer_applications(['SUBMITTED', 'PRE_EVALUATION', 'PAYMENT_PENDING']);
$application = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$order = $application ? payment_order_for_application((int)$application['id']) : null;

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">P4 Order of Payment Generator</h1>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">Applications</h2>
            <div class="list-group">
                <?php foreach ($applications as $row): ?>
                    <a class="list-group-item list-group-item-action <?= $application && (int)$application['id'] === (int)$row['id'] ? 'active' : '' ?>" href="order-payment.php?id=<?= (int)$row['id'] ?>">
                        <?= e($row['registry_number']) ?><br><small><?= e($row['property_title']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$applications): ?><div class="text-secondary small">No applications available for payment order generation.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($application): ?>
            <section class="gov-card p-4" data-sensitive>
                <h2 class="h5">Digital Payment Slip</h2>
                <dl class="row">
                    <dt class="col-sm-4">OP Number</dt><dd class="col-sm-8"><?= e($order['op_number'] ?? 'Generated after save') ?></dd>
                    <dt class="col-sm-4">Registry Number</dt><dd class="col-sm-8"><?= e($application['registry_number']) ?></dd>
                    <dt class="col-sm-4">Date</dt><dd class="col-sm-8"><?= e(date('Y-m-d')) ?></dd>
                    <dt class="col-sm-4">Account Name</dt><dd class="col-sm-8"><?= e($application['account_name']) ?></dd>
                    <dt class="col-sm-4">Account Address</dt><dd class="col-sm-8"><?= e($application['account_address']) ?></dd>
                    <dt class="col-sm-4">Service Fee</dt><dd class="col-sm-8"><?= currency_php(1500.00) ?></dd>
                    <dt class="col-sm-4">Account Code</dt><dd class="col-sm-8">4-02-01-020-8-6</dd>
                </dl>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                    <button class="btn btn-primary"><?= $order ? 'Regenerate View' : 'Generate Payment Slip' ?></button>
                </form>
            </section>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">No application selected.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
