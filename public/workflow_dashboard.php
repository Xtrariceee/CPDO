<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = require_role([ROLE_ZONING, ROLE_TWG, ROLE_SYSTEM_ADMIN]);

$stmt = db()->query('SELECT a.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name FROM applications a JOIN users u ON u.id = a.landlord_id ORDER BY a.updated_at DESC LIMIT 100');
$applications = $stmt->fetchAll();

require __DIR__ . '/partials/header.php';
?>
<h1 class="h3 mb-3">CPDO Workflow Dashboard</h1>
<div class="gov-card p-4">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Registry</th><th>Landlord</th><th>Property</th><th>Process</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($applications as $application): ?>
                <tr>
                    <td><?= e($application['registry_number']) ?></td>
                    <td><?= e($application['landlord_name']) ?></td>
                    <td><?= e($application['property_title']) ?></td>
                    <td>P<?= (int)$application['current_process'] ?></td>
                    <td><?= e($application['phase_status']) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="application_show.php?id=<?= (int)$application['id'] ?>">View</a>
                        <a class="btn btn-sm btn-primary" href="workflow_action.php?id=<?= (int)$application['id'] ?>">Act</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
