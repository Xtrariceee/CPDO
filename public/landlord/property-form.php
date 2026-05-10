<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

$propertyId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$status = landlord_compliance_status((int)$user['id']);

$property = null;
if ($propertyId) {
    $stmt = db()->prepare('SELECT * FROM properties WHERE id = ? AND landlord_id = ?');
    $stmt->execute([$propertyId, (int)$user['id']]);
    $property = $stmt->fetch();
    if (!$property) {
        http_response_code(404);
        exit('Property not found.');
    }
}

if (!$property && $status['state'] !== 'ELIGIBLE') {
    $_SESSION['flash_error'] = 'You cannot add a property listing yet. Please complete CPDO zoning approval and document verification first.';
    redirect('landlord/compliance-gateway.php');
}

// Even for editing, block if somehow not eligible (extra safety)
if ($property && $status['state'] !== 'ELIGIBLE') {
    $_SESSION['flash_error'] = 'Property listing management is locked until CPDO approval and Admin Officer verification are complete.';
    redirect('landlord/compliance-gateway.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title         = trim($_POST['title'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $rent          = (float)($_POST['monthly_rent'] ?? 0);
    $listingStatus = $_POST['status'] ?? 'PENDING';

    if (!$title || !$address || !in_array($listingStatus, ['ACTIVE', 'PENDING', 'INACTIVE'], true)) {
        $_SESSION['flash_error'] = 'Please complete all required listing details.';
        redirect('landlord/property-form.php' . ($propertyId ? '?id=' . $propertyId : ''));
    }

    if ($propertyId) {
        $stmt = db()->prepare(
            'UPDATE properties SET title = ?, address = ?, description = ?, monthly_rent = ?, status = ? WHERE id = ? AND landlord_id = ?'
        );
        $stmt->execute([$title, $address, $description ?: null, $rent, $listingStatus, $propertyId, (int)$user['id']]);
        audit_log((int)$user['id'], 'PROPERTY_UPDATED', 'properties', $propertyId);
    } else {
        $applicationId      = $status['source'] === 'application' ? (int)$status['record']['id'] : null;
        $complianceUploadId = $status['source'] === 'skip'        ? (int)$status['record']['id'] : null;
        $pdo  = db();
        $stmt = $pdo->prepare(
            'INSERT INTO properties (landlord_id, application_id, compliance_upload_id, title, address, description, monthly_rent, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([(int)$user['id'], $applicationId, $complianceUploadId, $title, $address, $description ?: null, $rent, $listingStatus]);
        $propertyId = (int)$pdo->lastInsertId();
        audit_log((int)$user['id'], 'PROPERTY_CREATED', 'properties', $propertyId);
    }

    $_SESSION['flash_success'] = 'Property listing saved.';
    redirect('landlord/dashboard.php');
}

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="dashboard.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
    <h1 class="h3 mb-0"><?= $property ? 'Edit Property Listing' : 'Create Property Listing' ?></h1>
</div>

<div class="row g-4 justify-content-center">
    <div class="col-lg-7">
        <form class="gov-card p-4" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$propertyId ?>">

            <div class="mb-3">
                <label class="form-label">Property Name / Title <span class="text-danger">*</span></label>
                <input class="form-control" name="title" value="<?= e($property['title'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Address <span class="text-danger">*</span></label>
                <textarea class="form-control" name="address" rows="2" required><?= e($property['address'] ?? '') ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Description <span class="text-secondary small">(optional)</span></label>
                <textarea class="form-control" name="description" rows="3" placeholder="Describe the property, amenities, nearby landmarks…"><?= e($property['description'] ?? '') ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Monthly Rent (₱) <span class="text-danger">*</span></label>
                <input class="form-control" type="number" step="0.01" min="0" name="monthly_rent"
                    value="<?= e((string)($property['monthly_rent'] ?? '0.00')) ?>">
            </div>
            <div class="mb-4">
                <label class="form-label">Listing Status</label>
                <select class="form-select" name="status">
                    <?php foreach (['ACTIVE', 'PENDING', 'INACTIVE'] as $option): ?>
                        <option value="<?= $option ?>" <?= (($property['status'] ?? 'PENDING') === $option) ? 'selected' : '' ?>>
                            <?= $option ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="d-flex gap-3">
                <button class="btn btn-primary">Save Listing</button>
                <a class="btn btn-outline-secondary" href="dashboard.php">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
