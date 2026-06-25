<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application = officer_application($applicationId);
    $resolution  = secure_upload($_FILES['resolution_file'] ?? [], 'final_outputs/' . $applicationId);
    if (!$resolution) {
        $_SESSION['flash_error'] = 'The official resolution / endorsement document is required.';
        redirect('admin-officer/final-output.php?id=' . $applicationId);
    }

    $endorsementNumber = trim($_POST['endorsement_number'] ?? '');
    $adminNotes        = trim($_POST['admin_notes'] ?? '');

    // ── Validate endorsement number is unique per landlord ──────────────────────
    if ($endorsementNumber) {
        $checkDupStmt = db()->prepare(
            'SELECT fo.id FROM final_outputs fo
             JOIN applications a ON a.id = fo.application_id
             WHERE a.landlord_id = ? AND fo.endorsement_number = ? AND a.id != ?'
        );
        $checkDupStmt->execute([(int)$application['landlord_id'], $endorsementNumber, $applicationId]);
        if ($checkDupStmt->fetch()) {
            $_SESSION['flash_error'] = 'Endorsement number already issued to another property for this landlord. Please use a unique number.';
            redirect('admin-officer/final-output.php?id=' . $applicationId);
        }
    }

    $stmt = db()->prepare(
        'INSERT INTO final_outputs (application_id, resolution_file_path, endorsement_number, uploaded_by)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$applicationId, $resolution, $endorsementNumber ?: null, (int)$user['id']]);
    advance_application($applicationId, 'APPROVED', 14);
    notify_user(
        (int)$application['landlord_id'],
        $applicationId,
        'Application Approved — Endorsement Issued',
        'Congratulations! Your application (' . $application['registry_number'] . ') has been officially approved. '
        . 'Endorsement No. ' . ($endorsementNumber ?: 'N/A') . ' has been issued. '
        . ($adminNotes ? 'Note from the Administrative Officer: ' . $adminNotes . ' ' : '')
        . 'Please log in to view and download the final endorsement document.'
    );
    audit_log((int)$user['id'], 'AO_RESOLUTION_VALIDATED_ENDORSEMENT_ISSUED', 'applications', $applicationId, [
        'endorsement_number' => $endorsementNumber,
    ]);
    $_SESSION['flash_success'] = 'Resolution validated. Endorsement No. ' . ($endorsementNumber ?: 'N/A') . ' issued. Landlord has been notified.';
    redirect('admin-officer/final-output.php?id=' . $applicationId);
}

$applications = db()->prepare(
    'SELECT a.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name, u.email AS landlord_email
     FROM applications a
     JOIN users u ON u.id = a.landlord_id
     WHERE a.phase_status = ?
     ORDER BY u.last_name, u.first_name, a.property_title, a.updated_at DESC'
);
$applications->execute(['DELIBERATION']);
$applications = $applications->fetchAll();
$application  = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$existingOutput = $application ? db()->prepare('SELECT * FROM final_outputs WHERE application_id = ? ORDER BY id DESC LIMIT 1') : null;
if ($existingOutput) { $existingOutput->execute([(int)$application['id']]); $existingOutput = $existingOutput->fetch() ?: null; }

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">Validate Resolution &amp; Generate Endorsement</h1>
<p class="text-secondary mb-4">After the TWG deliberation is complete, validate the committee resolution and issue the official endorsement document to the landlord.</p>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">Applications for Endorsement</h2>
            <div class="list-group">
                <?php
                // Group applications by landlord_id
                $groupedByLandlord = [];
                foreach ($applications as $row) {
                    $landlordId = (int)$row['landlord_id'];
                    if (!isset($groupedByLandlord[$landlordId])) {
                        $groupedByLandlord[$landlordId] = [
                            'landlord_name' => $row['landlord_name'],
                            'apps' => []
                        ];
                    }
                    $groupedByLandlord[$landlordId]['apps'][] = $row;
                }
                ?>
                <?php foreach ($groupedByLandlord as $landlordApps): ?>
                    <div style="padding:6px 12px;background:#f8f9fa;border-bottom:1px solid #dee2e6;font-size:.75rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#6c757d;">
                        <?= e($landlordApps['landlord_name']) ?>
                    </div>
                    <?php foreach ($landlordApps['apps'] as $row): ?>
                        <a class="list-group-item list-group-item-action <?= $application && (int)$application['id'] === (int)$row['id'] ? 'active' : '' ?>" href="final-output.php?id=<?= (int)$row['id'] ?>">
                            <?= e($row['registry_number']) ?><br><small><?= e($row['property_title']) ?></small>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if (!$applications): ?><div class="text-secondary small p-2">No deliberated applications awaiting endorsement.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($application): ?>
            <?php if ($existingOutput): ?>
                <div class="gov-card p-4 mb-3">
                    <h2 class="h6 text-success">✓ Endorsement Already Issued</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Endorsement No.</dt>
                        <dd class="col-sm-8"><?= e($existingOutput['endorsement_number'] ?? '—') ?></dd>
                        <dt class="col-sm-4">Issued by</dt>
                        <dd class="col-sm-8">Administrative Officer</dd>
                        <dt class="col-sm-4">Uploaded at</dt>
                        <dd class="col-sm-8"><?= e($existingOutput['uploaded_at'] ?? '—') ?></dd>
                    </dl>
                </div>
            <?php endif; ?>
            <form class="gov-card p-4" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                <h2 class="h5 mb-1"><?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?></h2>
                <p class="text-secondary small mb-3"><?= e($application['landlord_name'] ?? '') ?></p>

                <label class="form-label fw-bold">Endorsement / Resolution Number <span class="text-danger">*</span></label>
                <input class="form-control mb-3" name="endorsement_number" required placeholder="e.g. CPDO-END-2025-001">

                <label class="form-label fw-bold">Official Resolution / Endorsement Document <span class="text-danger">*</span></label>
                <input class="form-control mb-1" type="file" name="resolution_file" accept=".pdf,.jpg,.jpeg,.png" required>
                <p class="text-secondary small mb-3">Upload the signed resolution or endorsement document (PDF or image). This will be sent to the landlord.</p>

                <label class="form-label fw-bold">Notes to Landlord <span class="text-secondary fw-normal">(optional)</span></label>
                <textarea class="form-control mb-3" name="admin_notes" rows="3" placeholder="Any additional instructions or remarks to include in the notification email…"></textarea>

                <button class="btn btn-success">Validate &amp; Issue Endorsement →</button>
            </form>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">No deliberated application selected.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
