<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
$user = require_role([ROLE_SYSTEM_ADMIN]);
verify_csrf();

$statusMap = [
    'pending_documents' => ['DRAFT', 1],
    'under_evaluation' => ['PRE_EVALUATION', 3],
    'for_payment' => ['PAYMENT_PENDING', 4],
    'for_inspection' => ['INSPECTION_SCHEDULED', 7],
    'under_deliberation' => ['DELIBERATION', 12],
    'approved' => ['APPROVED', 14],
    'rejected' => ['DISAPPROVED', 12],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $applicationId = (int)($_POST['application_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($applicationId > 0 && isset($statusMap[$status])) {
        [$phaseStatus, $process] = $statusMap[$status];
        $stmt = db()->prepare('UPDATE applications SET status = ?, phase_status = ?, current_process = ? WHERE id = ?');
        $stmt->execute([$status, $phaseStatus, $process, $applicationId]);
        audit_log((int)$user['id'], 'ADMIN_APPLICATION_STATUS_OVERRIDE', 'applications', $applicationId, ['status' => $status]);
        $_SESSION['flash_success'] = 'Application status updated.';
    }
    redirect('admin/applications/');
}

$applications = officer_applications();
require __DIR__ . '/../../partials/header.php';
?>
<div class="layout-grid">
    <aside class="side-panel">
        <a class="side-link" href="../dashboard/">Overview</a>
        <a class="side-link" href="../users/">Users</a>
        <a class="side-link active" href="../applications/">Applications</a>
        <a class="side-link" href="../audit/">Audit Logs</a>
    </aside>
    <section>
        <h1 class="h3 mb-3">Application Status Override</h1>
        <div class="gov-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Registry</th><th>Landlord</th><th>Property</th><th>Status</th><th>Override</th></tr></thead>
                    <tbody>
                    <?php foreach ($applications as $application): ?>
                        <tr>
                            <td><?= e($application['registry_number']) ?></td>
                            <td><?= e($application['landlord_name']) ?></td>
                            <td><?= e($application['property_title']) ?></td>
                            <td><span class="status-pill status-<?= e($application['status']) ?>"><?= e(workflow_status_label($application['status'])) ?></span></td>
                            <td>
                                <form class="d-flex gap-2" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                                    <select class="form-select form-select-sm" name="status">
                                        <?php foreach (array_keys($statusMap) as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $application['status'] === $status ? 'selected' : '' ?>><?= e(workflow_status_label($status)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-sm btn-primary">Update</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
