<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application = officer_application($applicationId);
    $signature = secure_upload($_FILES['signature_file'] ?? [], 'final_outputs/' . $applicationId);
    $resolution = secure_upload($_FILES['resolution_file'] ?? [], 'final_outputs/' . $applicationId);
    if (!$signature || !$resolution) {
        $_SESSION['flash_error'] = 'Committee signatures and official endorsement/resolution are required.';
        redirect('admin-officer/final-output.php?id=' . $applicationId);
    }

    $stmt = db()->prepare(
        'INSERT INTO final_outputs (application_id, signature_file_path, resolution_file_path, endorsement_number, uploaded_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$applicationId, $signature, $resolution, $_POST['endorsement_number'] ?? null, (int)$user['id']]);
    advance_application($applicationId, 'APPROVED', 14);
    audit_log((int)$user['id'], 'P13_P14_FINAL_RESOLUTION_UPLOADED', 'applications', $applicationId);
    $_SESSION['flash_success'] = 'Official endorsement/resolution generated. Application is now Approved.';
    redirect('admin-officer/final-output.php?id=' . $applicationId);
}

$applications = officer_applications(['DELIBERATION']);
$application = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">Final Resolution Upload</h1>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">Applications for Final Output</h2>
            <div class="list-group">
                <?php foreach ($applications as $row): ?>
                    <a class="list-group-item list-group-item-action <?= $application && (int)$application['id'] === (int)$row['id'] ? 'active' : '' ?>" href="final-output.php?id=<?= (int)$row['id'] ?>">
                        <?= e($row['registry_number']) ?><br><small><?= e($row['property_title']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$applications): ?><div class="text-secondary small">No deliberated applications awaiting final output.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($application): ?>
            <form class="gov-card p-4" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                <h2 class="h5"><?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?></h2>
                <label class="form-label">Endorsement / Resolution Number</label>
                <input class="form-control mb-3" name="endorsement_number" required>
                <label class="form-label">Committee Signatures</label>
                <input class="form-control mb-3" type="file" name="signature_file" accept=".pdf,.jpg,.jpeg,.png" required>
                <label class="form-label">Official Endorsement / Resolution</label>
                <input class="form-control mb-3" type="file" name="resolution_file" accept=".pdf,.jpg,.jpeg,.png" required>
                <button class="btn btn-success">Upload and Approve Application</button>
            </form>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">No final output is pending.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
