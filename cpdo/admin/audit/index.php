<?php
require_once __DIR__ . '/../../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_SYSTEM_ADMIN]);

$logs = db()->query(
    'SELECT al.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS name, u.email
     FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC LIMIT 250'
)->fetchAll();

require __DIR__ . '/../../partials/header.php';
?>
<div class="layout-grid">
    <aside class="side-panel">
        <a class="side-link" href="../dashboard/">Overview</a>
        <a class="side-link" href="../users/">Users</a>
        <a class="side-link" href="../applications/">Applications</a>
        <a class="side-link active" href="../audit/">Audit Logs</a>
    </aside>
    <section>
        <h1 class="h3 mb-3">Audit Logs</h1>
        <div class="gov-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= e($log['created_at']) ?></td>
                            <td><?= e($log['name'] ?? 'System') ?><br><span class="small text-secondary"><?= e($log['email'] ?? '') ?></span></td>
                            <td><?= e($log['action']) ?></td>
                            <td><?= e($log['entity_type']) ?> #<?= e((string)$log['entity_id']) ?></td>
                            <td><?= e($log['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
