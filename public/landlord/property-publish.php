<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

$propertyId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$propertyId) {
    http_response_code(400);
    exit('Invalid property ID.');
}

// Fetch property
$stmt = db()->prepare('SELECT * FROM properties WHERE id = ? AND landlord_id = ?');
$stmt->execute([$propertyId, (int)$user['id']]);
$property = $stmt->fetch();

if (!$property) {
    http_response_code(404);
    exit('Property not found.');
}

$details = json_decode($property['extended_details'] ?? '{}', true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if property is allowed to be listed
    $allowed = property_listing_allowed($propertyId);
    if (!$allowed['allowed']) {
        $_SESSION['flash_error'] = 'This property cannot be activated: ' . implode(', ', $allowed['reasons']);
        redirect('landlord/property-publish.php?id=' . $propertyId);
    }

    $stmt = db()->prepare(
        'UPDATE properties SET status = "ACTIVE" WHERE id = ? AND landlord_id = ?'
    );
    $stmt->execute([$propertyId, (int)$user['id']]);
    audit_log((int)$user['id'], 'PROPERTY_PUBLISHED', 'properties', $propertyId);

    $_SESSION['flash_success'] = 'Property successfully published! It is now visible to tenants.';
    redirect('landlord/dashboard.php');
}

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="property-details.php?id=<?= $propertyId ?>"><span aria-hidden="true">&larr;</span> Back</a>
    <h1 class="h3 mb-0">Publish Listing</h1>
</div>

<div class="mb-4">
    <div class="d-flex align-items-center mb-2">
        <span class="badge bg-primary rounded-pill me-2" style="background-color: #157347 !important;">Step 4 of 4</span>
        <h2 class="h5 mb-0">Review &amp; Publish</h2>
    </div>
    <p class="text-secondary small">Review your listing details before making it public to tenants.</p>
</div>

<div class="row g-4 justify-content-center">
    <div class="col-lg-10">
        <div class="gov-card p-4 shadow-sm border border-secondary-subtle" style="border-radius: 16px;">
            <h3 class="h5 border-bottom pb-2 mb-3 text-dark fw-bold">Listing Summary Review</h3>
            
            <div class="row">
                <!-- Left Details Grid -->
                <div class="col-md-7">
                    <h4 class="h6 fw-bold text-dark mb-2">Core &amp; Pricing Info</h4>
                    <dl class="row mb-3">
                        <dt class="col-sm-4 text-secondary small">Title</dt>
                        <dd class="col-sm-8 fw-bold text-dark"><?= e($property['title']) ?></dd>
                        
                        <dt class="col-sm-4 text-secondary small">Listing Type</dt>
                        <dd class="col-sm-8 text-dark"><?= e($details['listing_type'] ?? 'Rental') ?></dd>
                        
                        <dt class="col-sm-4 text-secondary small">Category</dt>
                        <dd class="col-sm-8 text-dark"><?= e($details['property_category'] ?? 'Apartment') ?></dd>
                        
                        <dt class="col-sm-4 text-secondary small">Price / Rent</dt>
                        <dd class="col-sm-8 fw-bold text-success">
                            ₱<?= number_format((float)$property['monthly_rent'], 2) ?> 
                            <span class="text-secondary font-weight-normal small">/ <?= e($details['payment_frequency'] ?? 'monthly') ?></span>
                        </dd>
                        
                        <?php if (!empty($details['security_deposit'])): ?>
                            <dt class="col-sm-4 text-secondary small">Security Deposit</dt>
                            <dd class="col-sm-8 text-dark">₱<?= number_format((float)$details['security_deposit'], 2) ?></dd>
                        <?php endif; ?>
                        
                        <?php if (!empty($details['advance_rent'])): ?>
                            <dt class="col-sm-4 text-secondary small">Advance Rent</dt>
                            <dd class="col-sm-8 text-dark">₱<?= number_format((float)$details['advance_rent'], 2) ?></dd>
                        <?php endif; ?>

                        <dt class="col-sm-4 text-secondary small">Description</dt>
                        <dd class="col-sm-8 text-secondary small"><?= nl2br(e($property['description'] ?: 'No description provided.')) ?></dd>
                    </dl>

                    <h4 class="h6 fw-bold text-dark mb-2">Address</h4>
                    <dl class="row mb-3">
                        <dt class="col-sm-4 text-secondary small">Street Address</dt>
                        <dd class="col-sm-8 text-dark"><?= e($property['address']) ?></dd>
                    </dl>

                    <h4 class="h6 fw-bold text-dark mb-2">Physical Specifications</h4>
                    <dl class="row mb-3">
                        <dt class="col-sm-4 text-secondary small">Floor Area</dt>
                        <dd class="col-sm-8 text-dark"><?= e((string)($details['floor_area'] ?? '')) ?> <?= e($details['floor_area_unit'] ?? 'sqm') ?></dd>
                        
                        <?php if (!empty($details['lot_size'])): ?>
                            <dt class="col-sm-4 text-secondary small">Lot Size</dt>
                            <dd class="col-sm-8 text-dark"><?= e((string)$details['lot_size']) ?> sqm</dd>
                        <?php endif; ?>
                        
                        <dt class="col-sm-4 text-secondary small">Bedrooms</dt>
                        <dd class="col-sm-8 text-dark"><?= e((string)($details['bedrooms'] ?? 0)) ?> Bed</dd>
                        
                        <dt class="col-sm-4 text-secondary small">Bathrooms</dt>
                        <dd class="col-sm-8 text-dark">
                            <?php
                            $bathroomsVal = $details['bathrooms'] ?? '1';
                            if (is_numeric($bathroomsVal)) {
                                echo e($bathroomsVal) . ' Bathroom' . ($bathroomsVal > 1 ? 's' : '');
                            } else if ($bathroomsVal === '5+') {
                                echo '5+ Bathrooms';
                            } else {
                                echo e($bathroomsVal);
                            }
                            ?>
                        </dd>
                        
                        <?php if (!empty($details['year_built'])): ?>
                            <dt class="col-sm-4 text-secondary small">Year Built</dt>
                            <dd class="col-sm-8 text-dark"><?= e((string)$details['year_built']) ?></dd>
                        <?php endif; ?>
                        
                        <dt class="col-sm-4 text-secondary small">Furnishing</dt>
                        <dd class="col-sm-8 text-dark"><?= e($details['furnishing_status'] ?? 'Unfurnished') ?></dd>
                    </dl>
                </div>

                <!-- Right Side Media & Features Grid -->
                <div class="col-md-5 border-start">
                    <div class="ps-md-3">
                        <h4 class="h6 fw-bold text-dark mb-2">Amenities &amp; Features</h4>
                        <div class="mb-3">
                            <span class="text-secondary small d-block mb-1">Indoor:</span>
                            <?php if (!empty($details['indoor_features'])): ?>
                                <?php foreach ($details['indoor_features'] as $f): ?>
                                    <span class="badge bg-light text-dark border me-1 mb-1"><?= e($f) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-secondary small fst-italic">None listed</span>
                            <?php endif; ?>
                        </div>
                        <div class="mb-4">
                            <span class="text-secondary small d-block mb-1">Outdoor:</span>
                            <?php if (!empty($details['outdoor_features'])): ?>
                                <?php foreach ($details['outdoor_features'] as $f): ?>
                                    <span class="badge bg-light text-dark border me-1 mb-1"><?= e($f) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-secondary small fst-italic">None listed</span>
                            <?php endif; ?>
                        </div>

                        <h4 class="h6 fw-bold text-dark mb-2">Uploaded Media</h4>
                        
                        <!-- Image gallery strip -->
                        <div class="mb-3">
                            <span class="text-secondary small d-block mb-2">Image Gallery:</span>
                            <?php if (!empty($details['image_gallery'])): ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($details['image_gallery'] as $imgUrl): ?>
                                        <a href="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $imgUrl) ?>" target="_blank" class="border rounded overflow-hidden d-block" style="width: 54px; height: 54px;">
                                            <img src="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $imgUrl) ?>" alt="Property thumbnail" style="width:100%; height:100%; object-fit:cover;">
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-secondary small fst-italic d-block">No gallery images uploaded</span>
                            <?php endif; ?>
                        </div>

                        <!-- Video gallery strip -->
                        <div class="mb-3">
                            <span class="text-secondary small d-block mb-2">Video Gallery:</span>
                            <?php if (!empty($details['video_gallery'])): ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($details['video_gallery'] as $vidUrl): ?>
                                        <div class="border rounded overflow-hidden" style="width: 120px; height: 70px; background: #000;">
                                            <video src="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $vidUrl) ?>" style="width:100%; height:100%; object-fit:cover;" controls></video>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-secondary small fst-italic d-block">No videos uploaded</span>
                            <?php endif; ?>
                        </div>

                        <!-- Floor plan link -->
                        <div class="mb-3">
                            <span class="text-secondary small d-block mb-1">Floor Plan:</span>
                            <?php if (!empty($details['floor_plan'])): ?>
                                <a href="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $details['floor_plan']) ?>" target="_blank" class="btn btn-sm btn-outline-warning text-dark fw-bold d-inline-block">
                                    📄 View Floor Plan Blueprint
                                </a>
                            <?php else: ?>
                                <span class="text-secondary small fst-italic">No floor plan uploaded</span>
                            <?php endif; ?>
                        </div>

                        <!-- Virtual tour link -->
                        <div class="mb-3">
                            <span class="text-secondary small d-block mb-1">Virtual Tour:</span>
                            <?php if (!empty($details['virtual_tour_link'])): ?>
                                <a href="<?= e($details['virtual_tour_link']) ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold d-inline-block">
                                    🌐 Launch Virtual 360° Tour
                                </a>
                            <?php else: ?>
                                <span class="text-secondary small fst-italic">No tour link provided</span>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 pt-3 border-top">
                            <span class="text-secondary small d-block mb-1">CPDO Regulatory Compliance:</span>
                            <?php if ($property['rules_accepted']): ?>
                                <span class="badge bg-success">Mandatory Guidelines Accepted</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Pending Guidelines Acceptance</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <form method="post" class="mt-4 pt-3 border-top text-center">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$propertyId ?>">
                
                <div class="d-flex justify-content-center gap-3">
                    <button class="btn btn-primary px-5 py-2 fw-bold" style="background-color: #157347; border-color: #157347;">
                        Publish Listing
                    </button>
                    <a class="btn btn-outline-secondary px-4 py-2" href="dashboard.php">Save as Draft</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
