<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);

$inspection = officer_applications(['INSPECTION_SCHEDULED']);
$meeting = officer_applications(['FOR_MEETING']);
$voting = officer_applications(['DELIBERATION']);

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">LZRC TWG Member Dashboard</h1>
<div class="row g-3 mb-4">
    <div class="col-md-4"><a class="gov-card p-4 d-block text-decoration-none" href="inspection.php"><strong>P8-P9 Inspection</strong><div class="display-6"><?= count($inspection) ?></div></a></div>
    <div class="col-md-4"><a class="gov-card p-4 d-block text-decoration-none" href="meeting.php"><strong>P11 Meeting</strong><div class="display-6"><?= count($meeting) ?></div></a></div>
    <div class="col-md-4"><a class="gov-card p-4 d-block text-decoration-none" href="voting.php"><strong>P12 Voting</strong><div class="display-6"><?= count($voting) ?></div></a></div>
</div>
<section class="gov-card p-4">
    <h2 class="h5">Assigned TWG Tasks</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Registry</th><th>Property</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach (officer_applications(['INSPECTION_SCHEDULED', 'FOR_MEETING', 'DELIBERATION']) as $application): ?>
                <tr>
                    <td><?= e($application['registry_number']) ?></td>
                    <td><?= e($application['property_title']) ?></td>
                    <td><?= e(workflow_status_label($application['phase_status'])) ?></td>
                    <td>
                        <?php if ($application['phase_status'] === 'INSPECTION_SCHEDULED'): ?>
                            <a class="btn btn-sm btn-primary" href="inspection.php?id=<?= (int)$application['id'] ?>">Inspect</a>
                        <?php elseif ($application['phase_status'] === 'FOR_MEETING'): ?>
                            <a class="btn btn-sm btn-primary" href="meeting.php?id=<?= (int)$application['id'] ?>">Meeting</a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-primary" href="voting.php?id=<?= (int)$application['id'] ?>">Vote</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../partials/footer.php'; ?>
