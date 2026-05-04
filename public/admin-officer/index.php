<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);

$forPayment = officer_applications(['PRE_EVALUATION', 'SUBMITTED']);
$approvedForFinal = officer_applications(['DELIBERATION']);
$skipStmt = db()->query('SELECT * FROM compliance_uploads WHERE status = "PENDING_VERIFICATION" ORDER BY created_at DESC');
$skipPending = $skipStmt->fetchAll();

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">Administrative Officer Dashboard</h1>
<div class="row g-3 mb-4">
    <div class="col-md-4"><a class="gov-card p-4 d-block text-decoration-none" href="order-payment.php"><strong>P4 Order of Payment</strong><div class="display-6"><?= count($forPayment) ?></div></a></div>
    <div class="col-md-4"><a class="gov-card p-4 d-block text-decoration-none" href="final-output.php"><strong>P13-P14 Final Output</strong><div class="display-6"><?= count($approvedForFinal) ?></div></a></div>
    <div class="col-md-4"><a class="gov-card p-4 d-block text-decoration-none" href="skip-verification.php"><strong>Skip Path Verification</strong><div class="display-6"><?= count($skipPending) ?></div></a></div>
</div>
<section class="gov-card p-4">
    <h2 class="h5">Pending Administrative Actions</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Type</th><th>Record</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($forPayment as $application): ?>
                <tr>
                    <td>CPDO Application</td>
                    <td><?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?></td>
                    <td><?= e(workflow_status_label($application['phase_status'])) ?></td>
                    <td><a class="btn btn-sm btn-primary" href="order-payment.php?id=<?= (int)$application['id'] ?>">Generate OP</a></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($skipPending as $upload): ?>
                <tr>
                    <td>Listing Skip Path</td>
                    <td><?= e($upload['property_title']) ?></td>
                    <td><?= e($upload['status']) ?></td>
                    <td><a class="btn btn-sm btn-primary" href="skip-verification.php?id=<?= (int)$upload['id'] ?>">Review</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
