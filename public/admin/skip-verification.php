<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_SYSTEM_ADMIN]);
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadId = (int)($_POST['upload_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['PENDING_VERIFICATION', 'VERIFIED', 'REJECTED'], true)) {
        $stmt = db()->prepare('UPDATE compliance_uploads SET status = ?, officer_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $_POST['officer_notes'] ?? null, (int)$user['id'], $uploadId]);
        audit_log((int)$user['id'], 'ELIGIBILITY_VERIFICATION_SAVED', 'compliance_uploads', $uploadId, ['status' => $status]);

        // Notify landlord of verification outcome
        $landStmt = db()->prepare('SELECT landlord_id FROM compliance_uploads WHERE id = ?');
        $landStmt->execute([$uploadId]);
        $land = $landStmt->fetch();
        if ($land && !empty($land['landlord_id'])) {
            $landlordId = (int)$land['landlord_id'];
            $title = $status === 'VERIFIED' ? 'Eligibility Documents Verified' : 'Eligibility Documents Rejected';
            $message = $status === 'VERIFIED'
                ? 'Your eligibility documents have been verified by System Admin.'
                : 'Your eligibility documents were rejected by System Admin. Please review the officer notes and re-upload.';
            db()->prepare('INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, ?, ?, NOW())')
                ->execute([$landlordId, $title, $message]);
        }

        $_SESSION['flash_success'] = 'Document verification status saved by System Admin.';
        redirect('admin/skip-verification.php?id=' . $uploadId);
    }
}

$selectedId = (int)($_GET['id'] ?? 0);
$stmt = db()->query(
    'SELECT cu.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name, u.email
     FROM compliance_uploads cu
     JOIN users u ON u.id = cu.landlord_id
     ORDER BY cu.created_at DESC'
);
$uploads = $stmt->fetchAll();
$selected = null;
foreach ($uploads as $upload) {
    if ($selectedId && (int)$upload['id'] === $selectedId) {
        $selected = $upload;
        break;
    }
}
$selected = $selected ?: ($uploads[0] ?? null);

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">Document Verification: Skip Path</h1>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">Uploaded Legal Documents</h2>
            <div class="list-group">
                <?php foreach ($uploads as $upload): ?>
                    <a class="list-group-item list-group-item-action <?= $selected && (int)$selected['id'] === (int)$upload['id'] ? 'active' : '' ?>" href="skip-verification.php?id=<?= (int)$upload['id'] ?>">
                        <?= e($upload['property_title']) ?><br><small><?= e($upload['status']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$uploads): ?><div class="text-secondary small">No skip-path documents submitted.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($selected): ?>
            <form class="gov-card p-4" method="post" data-sensitive>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="upload_id" value="<?= (int)$selected['id'] ?>">
                <h2 class="h5"><?= e($selected['property_title']) ?></h2>
                <p class="text-secondary mb-3"><?= e($selected['landlord_name']) ?> · <?= e($selected['email']) ?></p>
                <div class="list-group mb-4">
                    <?php 
                    $docFields = [
                        'Approved Resolution / Endorsement' => 'approved_resolution_path',
                        'Zoning Clearance' => 'zoning_clearance_path',
                        'Proof of Ownership' => 'proof_of_ownership_path',
                        'Building Permit' => 'building_permit_path',
                        'Certificate of Occupancy' => 'certificate_of_occupancy_path',
                        'Barangay Business Clearance' => 'barangay_business_clearance_path',
                        'Mayor\'s Business Permit' => 'mayors_business_permit_path',
                        'Fire Safety Inspection Certificate' => 'fire_safety_inspection_certificate_path',
                        'Sanitary Permit' => 'sanitary_permit_path',
                        'BIR Registration' => 'bir_registration_path',
                        'Government ID' => 'government_id_path'
                    ];
                    foreach ($docFields as $label => $field): 
                        if (!empty($selected[$field])):
                            $previewUrl = '../document_preview.php?type=compliance&id=' . (int)$selected['id'] . '&field=' . urlencode($field);
                    ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong class="d-block"><?= e($label) ?></strong>
                                <small class="text-secondary"><?= e(basename($selected[$field])) ?></small>
                                <?php if ($field === 'government_id_path' && !empty($selected['government_id_type'])): ?>
                                    <div class="mt-1"><span class="badge text-bg-secondary"><?= e($selected['government_id_type']) ?> <?= e($selected['government_id_number']) ?></span></div>
                                <?php endif; ?>
                            </div>
                            <a href="<?= e($previewUrl) ?>" target="_blank" class="btn btn-sm btn-outline-primary flex-shrink-0 ms-3">Preview</a>
                        </div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
                <label class="form-label">Verification Status</label>
                <select class="form-select mb-3" name="status">
                    <option value="PENDING_VERIFICATION" <?= $selected['status'] === 'PENDING_VERIFICATION' ? 'selected' : '' ?>>Pending</option>
                    <option value="VERIFIED" <?= $selected['status'] === 'VERIFIED' ? 'selected' : '' ?>>Verified</option>
                    <option value="REJECTED" <?= $selected['status'] === 'REJECTED' ? 'selected' : '' ?>>Rejected</option>
                </select>
                <textarea class="form-control mb-3" name="officer_notes" rows="4" placeholder="Review notes"><?= e($selected['officer_notes']) ?></textarea>
                <button class="btn btn-primary">Save Verification</button>
            </form>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">No document set selected.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
