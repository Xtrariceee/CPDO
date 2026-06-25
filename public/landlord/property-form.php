<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

$propertyId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

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

/* ── For new listings: address and title are required BEFORE we can check compliance ─ */
$incomingAddress = trim($_POST['address'] ?? $_GET['address'] ?? '');
$incomingTitle   = trim($_POST['title'] ?? $_GET['title'] ?? '');

/* On GET with no address yet, or on POST, we check after address is known */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title         = trim($_POST['title'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $listingStatus = 'PENDING';

    if (!$title || !$address) {
        $_SESSION['flash_error'] = 'Please complete all required listing details.';
        redirect('landlord/property-form.php' . ($propertyId ? '?id=' . $propertyId : ''));
    }

    /* ── Property-specific compliance check ─────────────────────────────── */
    if (!$propertyId) {
        // New listing: require approved compliance for this exact address
        $compStatus = property_compliance_status((int)$user['id'], $address);
        if ($compStatus['state'] !== 'ELIGIBLE') {
            $pdo = db();
            $stmt = $pdo->prepare(
                'INSERT INTO properties (landlord_id, application_id, compliance_upload_id, title, address, monthly_rent, status)
                 VALUES (?, NULL, NULL, ?, ?, 0.00, "PENDING")'
            );
            $stmt->execute([(int)$user['id'], $title, $address]);
            $propertyId = (int)$pdo->lastInsertId();

            $encodedAddress = urlencode($address);
            $encodedTitle = urlencode($title);
            $_SESSION['flash_error'] = 'Compliance for this property address is required before creating a listing. '
                . ($compStatus['state'] === 'UNDER_REVIEW'
                    ? 'Your submission for this address is currently under review.'
                    : 'Please complete the CPDO workflow or upload compliance documents for this address.');
            redirect('landlord/compliance-gateway.php?address=' . $encodedAddress . '&title=' . $encodedTitle . '&property_id=' . $propertyId);
        }
        $applicationId      = $compStatus['source'] === 'application' ? (int)$compStatus['record']['id'] : null;
        $complianceUploadId = $compStatus['source'] === 'skip'        ? (int)$compStatus['record']['id'] : null;
    }

    if ($propertyId) {
        // If attempting to activate the listing, ensure property is allowed to be listed
        if ($listingStatus === 'ACTIVE') {
            $allowed = property_listing_allowed($propertyId);
            if (!$allowed['allowed']) {
                $_SESSION['flash_error'] = 'This property cannot be activated: ' . implode(', ', $allowed['reasons']);
                redirect('landlord/property-form.php?id=' . $propertyId);
            }
        }
        // Update basic details only
        $stmt = db()->prepare(
            'UPDATE properties SET title = ?, address = ? WHERE id = ? AND landlord_id = ?'
        );
        $stmt->execute([$title, $address, $propertyId, (int)$user['id']]);
        audit_log((int)$user['id'], 'PROPERTY_UPDATED', 'properties', $propertyId);

        $_SESSION['flash_success'] = 'Property basic details saved.';
        redirect('landlord/dashboard.php');
    } else {
        $pdo  = db();
        $stmt = $pdo->prepare(
            'INSERT INTO properties (landlord_id, application_id, compliance_upload_id, title, address, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([(int)$user['id'], $applicationId ?? null, $complianceUploadId ?? null, $title, $address, $listingStatus]);
        $propertyId = (int)$pdo->lastInsertId();
        audit_log((int)$user['id'], 'PROPERTY_CREATED', 'properties', $propertyId);

        $_SESSION['flash_success'] = 'Property registered. Please review and accept the mandatory house rules and guidelines.';
        redirect('landlord/house-rules.php?id=' . $propertyId);
    }
}/* ── GET: if no address yet, show address-first form before compliance check */
$addressForCheck = $incomingAddress ?: ($property['address'] ?? '');
$compStatus = $addressForCheck
    ? property_compliance_status((int)$user['id'], $addressForCheck)
    : ['state' => 'UNKNOWN', 'label' => 'Enter address to check compliance', 'source' => null, 'record' => null];

/* Block editing if property somehow lost its compliance record */
if ($property && $compStatus['state'] !== 'ELIGIBLE' && $compStatus['state'] !== 'UNKNOWN') {
    // Editing existing listings is allowed regardless — compliance was checked at creation
}

/* Block new listing if address was pre-supplied and not eligible */
if (!$property && $addressForCheck && $compStatus['state'] !== 'ELIGIBLE') {
    $encodedAddress = urlencode($addressForCheck);
    if ($compStatus['state'] === 'UNDER_REVIEW') {
        $_SESSION['flash_error'] = 'Compliance for "' . $addressForCheck . '" is currently under review. You will be notified when it is approved.';
    } else {
        $_SESSION['flash_error'] = 'Compliance verification is required for "' . $addressForCheck . '" before you can create a listing.';
    }
    redirect('landlord/compliance-gateway.php?address=' . $encodedAddress);
}

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="dashboard.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
    <h1 class="h3 mb-0"><?= $property ? 'Edit Property Basics' : 'Register Property' ?></h1>
</div>

<div class="mb-4">
    <div class="d-flex align-items-center mb-2">
        <span class="badge bg-primary rounded-pill me-2">Step 1</span>
        <h2 class="h5 mb-0">Initial Registration</h2>
    </div>
    <p class="text-secondary small">Set the basic property title and check address compliance.</p>
</div>

<?php if (!$property && $compStatus['state'] === 'ELIGIBLE'): ?>
<div class="alert alert-success mb-4">
    <strong>&#10003; Compliance verified</strong> for this address. You may proceed to register the property.
</div>
<?php endif; ?>

<div class="row g-4 justify-content-center">
    <div class="col-lg-7">
        <form class="gov-card p-4" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$propertyId ?>">

            <?php if (!$property): ?>
            <div class="mb-3">
                <label class="form-label">Address <span class="text-danger">*</span>
                    <span class="text-secondary small fw-normal">(enter first — compliance is checked per address)</span>
                </label>
                <textarea class="form-control" name="address" id="pf-address" rows="2" required
                    placeholder="e.g. Lot 12 Block 3, Brgy. Matina Aplaya, Davao City"><?= e($property['address'] ?? $incomingAddress) ?></textarea>
                <div class="form-text" id="pf-compliance-hint">
                    <?php if ($addressForCheck && $compStatus['state'] === 'ELIGIBLE'): ?>
                        <span class="text-success fw-bold">&#10003; Compliance verified for this address.</span>
                    <?php elseif ($addressForCheck && $compStatus['state'] === 'UNDER_REVIEW'): ?>
                        <span class="text-warning fw-bold">&#9679; Compliance review in progress for this address.</span>
                    <?php else: ?>
                        Compliance will be checked against this address when you submit.
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label">Property Name / Title <span class="text-danger">*</span></label>
                <input class="form-control" name="title" value="<?= e($property['title'] ?? $incomingTitle) ?>" required>
                <?php if (!$property): ?>
                    <div class="form-text text-secondary small">
                        This title will be prefilled on the verification page. Monthly rent is added later during listing details after registration is approved.
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($property): ?>
            <div class="mb-3">
                <label class="form-label">Address <span class="text-danger">*</span></label>
                <textarea class="form-control" name="address" rows="2" required><?= e($property['address'] ?? '') ?></textarea>
                <div class="form-text text-secondary small">Changing the address on an existing listing does not re-trigger compliance checks.</div>
            </div>
            <?php endif; ?>

            <div class="d-flex gap-3">
                <button class="btn btn-primary"><?= $property ? 'Save Listing' : 'Register Property' ?></button>
                <a class="btn btn-outline-secondary" href="dashboard.php">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
