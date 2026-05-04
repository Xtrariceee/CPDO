<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);

$underEvaluation = officer_applications(['SUBMITTED', 'PRE_EVALUATION']);
$paid = officer_applications(['PAID']);
$forMeeting = officer_applications(['FOR_MEETING']);

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">Zoning Officer IV Dashboard</h1>
<div class="row g-3 mb-4">
    <div class="col-md-4"><a class="gov-card p-4 d-block text-decoration-none" href="pre-evaluation.php"><strong>P3 Pre-Evaluation</strong><div class="display-6"><?= count($underEvaluation) ?></div></a></div>
    <div class="col-md-4"><a class="gov-card p-4 d-block text-decoration-none" href="payment-scheduling.php"><strong>P6-P7 Payment / Scheduling</strong><div class="display-6"><?= count($paid) ?></div></a></div>
    <div class="col-md-4"><a class="gov-card p-4 d-block text-decoration-none" href="consolidation.php"><strong>P10 Consolidation</strong><div class="display-6"><?= count($forMeeting) ?></div></a></div>
</div>
<section class="gov-card p-4">
    <h2 class="h5">Assigned Workflow Status</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Registry</th><th>Landlord</th><th>Property</th><th>Status</th><th>Pending Action</th></tr></thead>
            <tbody>
            <?php foreach (officer_applications() as $application): ?>
                <tr>
                    <td><?= e($application['registry_number']) ?></td>
                    <td><?= e($application['landlord_name']) ?></td>
                    <td><?= e($application['property_title']) ?></td>
                    <td><?= e(workflow_status_label($application['phase_status'])) ?></td>
                    <td>
                        <?php if (in_array($application['phase_status'], ['SUBMITTED', 'PRE_EVALUATION'], true)): ?>
                            <a class="btn btn-sm btn-primary" href="pre-evaluation.php?id=<?= (int)$application['id'] ?>">Evaluate</a>
                        <?php elseif ($application['phase_status'] === 'PAID'): ?>
                            <a class="btn btn-sm btn-primary" href="payment-scheduling.php?id=<?= (int)$application['id'] ?>">Schedule</a>
                        <?php elseif ($application['phase_status'] === 'FOR_MEETING'): ?>
                            <a class="btn btn-sm btn-primary" href="consolidation.php?id=<?= (int)$application['id'] ?>">Consolidate</a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline-primary" href="../application_show.php?id=<?= (int)$application['id'] ?>">View</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
