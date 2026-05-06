<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
$user = require_role([ROLE_SYSTEM_ADMIN]);

$totalUsers = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeApplications = (int)db()->query('SELECT COUNT(*) FROM applications WHERE status NOT IN ("approved","rejected")')->fetchColumn();
$pendingApprovals = (int)db()->query('SELECT COUNT(*) FROM applications WHERE status IN ("under_evaluation","for_payment","for_inspection","under_deliberation")')->fetchColumn();
$pendingUpgrades = (int)db()->query('SELECT COUNT(*) FROM role_upgrade_requests WHERE status = "PENDING"')->fetchColumn();
$logs = db()->query(
    'SELECT al.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS name
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC
     LIMIT 10'
)->fetchAll();
$applications = officer_applications();

require __DIR__ . '/../../partials/header.php';
?>
<div class="layout-grid">
    <aside class="side-panel">
        <a class="side-link active" href="../dashboard/">Overview</a>
        <a class="side-link" href="../users/">Users <?= $pendingUpgrades > 0 ? '<span class="badge text-bg-warning ms-1">' . $pendingUpgrades . '</span>' : '' ?></a>
        <a class="side-link" href="../applications/">Applications</a>
        <a class="side-link" href="../audit/">Audit Logs</a>
    </aside>
    <section>
        <h1 class="h3 mb-3">System Admin Dashboard</h1>
        <div class="metric-grid mb-4" style="grid-template-columns:repeat(4,minmax(0,1fr));">
            <article class="metric-card"><span>Total Users</span><strong><?= $totalUsers ?></strong></article>
            <article class="metric-card"><span>Active Applications</span><strong><?= $activeApplications ?></strong></article>
            <article class="metric-card"><span>Pending Approvals</span><strong><?= $pendingApprovals ?></strong></article>
            <article class="metric-card" style="border-left:4px solid var(--cpdo-amber);">
                <span>Role Upgrade Requests</span>
                <strong><?= $pendingUpgrades ?></strong>
                <?php if ($pendingUpgrades > 0): ?>
                    <a href="../users/" class="small text-warning fw-bold d-block mt-1">Review →</a>
                <?php endif; ?>
            </article>
        </div>
        <div class="row g-4">
            <div class="col-xl-7">
                <div class="gov-card p-4">
                    <h2 class="h5">Application Oversight</h2>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>Registry</th><th>Property</th><th>Status</th><th>Process</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($applications, 0, 8) as $application): ?>
                                <tr>
                                    <td><?= e($application['registry_number']) ?></td>
                                    <td><?= e($application['property_title']) ?></td>
                                    <td><span class="status-pill status-<?= e($application['status']) ?>"><?= e(workflow_status_label($application['status'])) ?></span></td>
                                    <td>P<?= (int)$application['current_process'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$applications): ?>
                                <tr><td colspan="4" class="text-secondary">No applications have been created yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="gov-card p-4">
                    <h2 class="h5">Recent System Activity</h2>
                    <div class="activity-list">
                        <?php foreach ($logs as $log): ?>
                            <div class="activity-item">
                                <strong><?= e($log['action']) ?></strong>
                                <span><?= e($log['name'] ?? 'System') ?> · <?= e($log['created_at']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$logs): ?><p class="text-secondary mb-0">No audit activity yet.</p><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
