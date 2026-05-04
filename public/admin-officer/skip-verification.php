<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadId = (int)($_POST['upload_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['PENDING_VERIFICATION', 'VERIFIED', 'REJECTED'], true)) {
        $stmt = db()->prepare('UPDATE compliance_uploads SET status = ?, officer_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $_POST['officer_notes'] ?? null, (int)$user['id'], $uploadId]);
        audit_log((int)$user['id'], 'SKIP_PATH_DOCUMENT_VERIFICATION_SAVED', 'compliance_uploads', $uploadId, ['status' => $status]);
        $_SESSION['flash_success'] = 'Document verification status saved.';
        redirect('admin-officer/skip-verification.php?id=' . $uploadId);
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
                <p class="text-secondary"><?= e($selected['landlord_name']) ?> · <?= e($selected['email']) ?></p>
                <ul>
                    <li>Approved Resolution / Endorsement: <?= e($selected['approved_resolution_path']) ?></li>
                    <li>Zoning Clearance: <?= e($selected['zoning_clearance_path']) ?></li>
                    <li>Proof of Ownership: <?= e($selected['proof_of_ownership_path']) ?></li>
                </ul>
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
