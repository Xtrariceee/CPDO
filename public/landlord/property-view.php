<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_login();
$propertyId = (int)($_GET['id'] ?? 0);

$sql = 'SELECT p.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name
        FROM properties p
        JOIN users u ON u.id = p.landlord_id
        WHERE p.id = ?';
$params = [$propertyId];
if ($user['role'] === ROLE_LANDLORD) {
    $sql .= ' AND p.landlord_id = ?';
    $params[] = (int)$user['id'];
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$property = $stmt->fetch();
if (!$property) {
    http_response_code(404);
    exit('Property not found.');
}

$propDetails = json_decode($property['extended_details'] ?? '{}', true) ?: [];
$images = $propDetails['image_gallery'] ?? [];
$videos = $propDetails['video_gallery'] ?? [];

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="javascript:history.back()"><span aria-hidden="true">&larr;</span> Back</a>
    <h1 class="h3 mb-0"><?= e($property['title']) ?></h1>
    <span class="badge ms-auto align-self-start <?= $property['status'] === 'ACTIVE' ? 'text-bg-success' : ($property['status'] === 'INACTIVE' ? 'text-bg-secondary' : 'text-bg-warning') ?>">
        <?= e($property['status']) ?>
    </span>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <article class="gov-card p-4 mb-4">
            <!-- Hero image or placeholder -->
            <?php if (!empty($images)): 
                $firstImgUrl = rtrim($config['app']['base_url'], '/') . '/' . $images[0];
            ?>
                <div class="property-hero-img mb-4 p-0" style="overflow:hidden; height: 350px;">
                    <img src="<?= e($firstImgUrl) ?>" alt="<?= e($property['title']) ?>" style="width:100%; height:100%; object-fit:cover;">
                </div>
            <?php elseif (!empty($videos)): 
                $firstVidUrl = rtrim($config['app']['base_url'], '/') . '/' . $videos[0];
            ?>
                <div class="property-hero-img mb-4 p-0" style="overflow:hidden; height: 350px; background:#000;">
                    <video src="<?= e($firstVidUrl) ?>" style="width:100%; height:100%; object-fit:cover;" controls></video>
                </div>
            <?php else: ?>
                <div class="property-hero-img mb-4">
                    <span><?= mb_strtoupper(mb_substr($property['title'], 0, 2)) ?></span>
                </div>
            <?php endif; ?>

            <h2 class="h5">About this Property</h2>
            <?php if (!empty($property['description'])): ?>
                <p class="text-secondary"><?= nl2br(e($property['description'])) ?></p>
            <?php else: ?>
                <p class="text-secondary">No description provided.</p>
            <?php endif; ?>

            <?php if (!empty($images) || !empty($videos) || !empty($propDetails['floor_plan']) || !empty($propDetails['virtual_tour_link'])): ?>
                <div class="mt-4 pt-3 border-top">
                    <h3 class="h6 fw-bold text-dark mb-3">Media & Documentation</h3>
                    
                    <?php if (!empty($images)): ?>
                        <div class="mb-4">
                            <span class="text-secondary small d-block mb-2">Image Gallery:</span>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($images as $img): 
                                    $imgUrl = rtrim($config['app']['base_url'], '/') . '/' . $img;
                                ?>
                                    <a href="<?= e($imgUrl) ?>" target="_blank" class="border rounded overflow-hidden d-block" style="width: 80px; height: 80px;">
                                        <img src="<?= e($imgUrl) ?>" alt="Property thumbnail" style="width:100%; height:100%; object-fit:cover;">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($videos)): ?>
                        <div class="mb-4">
                            <span class="text-secondary small d-block mb-2">Video Gallery:</span>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($videos as $vid): 
                                    $vidUrl = rtrim($config['app']['base_url'], '/') . '/' . $vid;
                                ?>
                                    <div class="border rounded overflow-hidden" style="width: 220px; height: 130px; background: #000;">
                                        <video src="<?= e($vidUrl) ?>" style="width:100%; height:100%; object-fit:cover;" controls></video>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($propDetails['floor_plan'])): ?>
                        <div class="mb-3">
                            <span class="text-secondary small d-block mb-2">Floor Plan:</span>
                            <a href="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $propDetails['floor_plan']) ?>" target="_blank" class="btn btn-sm btn-outline-warning text-dark fw-bold d-inline-block" style="border-color: #d4c89a;">
                                📄 View Floor Plan Blueprint
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($propDetails['virtual_tour_link'])): ?>
                        <div class="mb-2">
                            <span class="text-secondary small d-block mb-2">Virtual Tour:</span>
                            <a href="<?= e($propDetails['virtual_tour_link']) ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-bold d-inline-block">
                                🌐 Launch Virtual 360° Tour
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <hr>
            <dl class="row mb-0">
                <dt class="col-sm-4">Address</dt>
                <dd class="col-sm-8"><?= e($property['address']) ?></dd>
                
                <dt class="col-sm-4">Listing Type</dt>
                <dd class="col-sm-8"><?= e($propDetails['listing_type'] ?? 'Rental') ?></dd>
                
                <dt class="col-sm-4">Category</dt>
                <dd class="col-sm-8"><?= e($propDetails['property_category'] ?? 'N/A') ?></dd>
                
                <dt class="col-sm-4">Monthly Rent</dt>
                <dd class="col-sm-8 fw-bold text-success"><?= currency_php((float)$property['monthly_rent']) ?></dd>
                
                <?php if (!empty($propDetails['security_deposit'])): ?>
                    <dt class="col-sm-4">Security Deposit</dt>
                    <dd class="col-sm-8"><?= currency_php((float)$propDetails['security_deposit']) ?></dd>
                <?php endif; ?>
                
                <?php if (!empty($propDetails['advance_rent'])): ?>
                    <dt class="col-sm-4">Advance Rent</dt>
                    <dd class="col-sm-8"><?= currency_php((float)$propDetails['advance_rent']) ?></dd>
                <?php endif; ?>
                
                <dt class="col-sm-4">Bedrooms</dt>
                <dd class="col-sm-8"><?= (int)($propDetails['bedrooms'] ?? 0) === 0 ? 'Studio / None' : (int)($propDetails['bedrooms'] ?? 0) . ' Bed(s)' ?></dd>
                
                <dt class="col-sm-4">Bathrooms</dt>
                <dd class="col-sm-8"><?= e($propDetails['bathrooms'] ?? 'N/A') ?></dd>
                
                <dt class="col-sm-4">Floor Area</dt>
                <dd class="col-sm-8"><?= !empty($propDetails['floor_area']) ? e($propDetails['floor_area']) . ' ' . e($propDetails['floor_area_unit'] ?? 'sqm') : 'N/A' ?></dd>
                
                <?php if (!empty($propDetails['lot_size'])): ?>
                    <dt class="col-sm-4">Lot Size</dt>
                    <dd class="col-sm-8"><?= e($propDetails['lot_size']) ?> sqm</dd>
                <?php endif; ?>
                
                <dt class="col-sm-4">Furnishing Status</dt>
                <dd class="col-sm-8"><?= e($propDetails['furnishing_status'] ?? 'N/A') ?></dd>
                
                <?php if (!empty($propDetails['year_built'])): ?>
                    <dt class="col-sm-4">Year Built</dt>
                    <dd class="col-sm-8"><?= e($propDetails['year_built']) ?></dd>
                <?php endif; ?>
                
                <dt class="col-sm-4">Listed by</dt>
                <dd class="col-sm-8"><?= e($property['landlord_name']) ?></dd>
            </dl>

            <?php if (!empty($propDetails['indoor_features']) || !empty($propDetails['outdoor_features'])): ?>
                <div class="mt-4 pt-3 border-top">
                    <h3 class="h6 fw-bold text-dark mb-3">Amenities &amp; Features</h3>
                    <?php if (!empty($propDetails['indoor_features'])): ?>
                        <div class="mb-3">
                            <span class="text-secondary small d-block mb-1">Indoor Features:</span>
                            <?php foreach ($propDetails['indoor_features'] as $f): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1"><?= e($f) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($propDetails['outdoor_features'])): ?>
                        <div class="mb-3">
                            <span class="text-secondary small d-block mb-1">Outdoor Features:</span>
                            <?php foreach ($propDetails['outdoor_features'] as $f): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1"><?= e($f) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>

        <!-- House Rules & Guidelines Section -->
        <article class="gov-card p-4 mb-4">
            <h2 class="h5 mb-3">House Rules &amp; Guidelines</h2>
            
            <?php if (!empty($property['rules_accepted'])): ?>
                <div class="alert alert-success d-flex align-items-center gap-2 py-2 px-3 mb-4" style="font-size: 0.85rem;">
                    <span><strong>✓ Verified CPDO Regulatory Compliance</strong></span> 
                    <span class="text-secondary ms-auto">Accepted on <?= date('M d, Y · g:i A', strtotime($property['rules_accepted_at'])) ?></span>
                </div>
            <?php else: ?>
                <div class="alert alert-warning d-flex align-items-center gap-2 py-2 px-3 mb-4" style="font-size: 0.85rem;">
                    <span><strong>⚠ Compliance Pending</strong></span> 
                    <span class="text-secondary ms-auto">Guidelines acceptance is required to activate listing.</span>
                </div>
            <?php endif; ?>

            <div class="mb-4">
                <h3 class="h6 text-dark font-weight-bold mb-2">Mandatory Guidelines</h3>
                <ul class="text-secondary small ps-3 mb-0">
                    <li>Strict adherence to local zoning classifications (verified by CPDO).</li>
                    <li>Full compliance with the National Building Code, Fire Code, and sanitary standards.</li>
                    <li>Adherence to rent and tenant regulations under the Rent Control Act (R.A. 9653).</li>
                    <li>Accurate registry of all leases and active tenant occupants.</li>
                </ul>
            </div>

            <div class="mb-2">
                <h3 class="h6 text-dark font-weight-bold mb-2">Listing-Specific House Rules</h3>
                <?php if (!empty($property['house_rules'])): ?>
                    <div class="bg-light p-3 rounded text-secondary" style="font-size: 0.9rem; white-space: pre-wrap; border-left: 3px solid #f6cf4a;"><?= e($property['house_rules']) ?></div>
                <?php else: ?>
                    <p class="text-secondary small fst-italic mb-0">No listing-specific house rules have been added. Standard guidelines apply.</p>
                <?php endif; ?>
            </div>
            
            <?php if ($user['role'] === ROLE_LANDLORD && (int)$property['landlord_id'] === (int)$user['id']): ?>
                <div class="mt-4">
                    <a class="btn btn-sm btn-outline-warning text-dark fw-bold" href="house-rules.php?id=<?= (int)$property['id'] ?>">Update Rules &amp; Guidelines</a>
                </div>
            <?php endif; ?>
        </article>
    </div>
    <div class="col-lg-4">
        <div class="gov-card p-4">
            <h2 class="h5 mb-3">Interested?</h2>
            <p class="text-secondary small">Contact the landlord to inquire about this property.</p>
            <?php
            $existingApp = null;
            if ($user && $user['role'] === ROLE_TENANT) {
                $stmtApp = db()->prepare('SELECT id, status FROM rental_applications WHERE tenant_id = ? AND property_id = ? ORDER BY id DESC LIMIT 1');
                $stmtApp->execute([(int)$user['id'], (int)$property['id']]);
                $existingApp = $stmtApp->fetch();
            }
            ?>
            <?php if ($user['role'] === ROLE_LANDLORD && (int)$property['landlord_id'] === (int)$user['id']): ?>
                <h2 class="h5 mb-3">Management Actions</h2>
                <a class="btn btn-outline-primary w-100 mb-2" href="property-form.php?id=<?= (int)$property['id'] ?>">Edit Listing</a>
                <a class="btn btn-outline-secondary w-100 mb-4" href="dashboard.php">Back to Dashboard</a>

                <h2 class="h5 mb-3 border-top pt-3">Tenant Inquiries</h2>
                <?php
                $stmtInquiries = db()->prepare(
                    'SELECT ra.*, CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name
                     FROM rental_applications ra
                     JOIN users u ON u.id = ra.tenant_id
                     WHERE ra.property_id = ?
                     ORDER BY ra.created_at DESC'
                );
                $stmtInquiries->execute([(int)$property['id']]);
                $inquiries = $stmtInquiries->fetchAll();

                if (empty($inquiries)):
                ?>
                    <p class="text-secondary small">No inquiries received for this listing yet.</p>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($inquiries as $inq): ?>
                            <div class="p-2 border rounded bg-light">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="small text-dark"><?= e($inq['tenant_name']) ?></strong>
                                    <span class="badge text-bg-warning" style="font-size:0.65rem;"><?= e($inq['status']) ?></span>
                                </div>
                                <div class="text-secondary" style="font-size:0.75rem;">Income: ₱<?= number_format((float)$inq['monthly_income'], 0) ?></div>
                                <a href="application-review.php?id=<?= (int)$inq['id'] ?>" class="btn btn-sm btn-warning text-dark fw-bold w-100 mt-2" style="font-size:0.75rem;">Review &amp; Screen</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php elseif ($user['role'] === ROLE_TENANT): ?>
                <?php if ($existingApp): ?>
                    <div class="alert alert-warning py-2 px-3 mb-3 small border border-warning-subtle" style="background:#fffdf5; color:#8a6400;">
                        Application: <strong><?= e($existingApp['status']) ?></strong>
                    </div>
                    <a class="btn btn-primary w-100" style="background-color: #c59000; border-color: #c59000; color: #241b0b; font-weight: 700;" href="../tenant/application-status.php?id=<?= (int)$existingApp['id'] ?>">View Application Status</a>
                <?php else: ?>
                    <a class="btn btn-primary w-100" style="background-color: #c59000; border-color: #c59000; color: #241b0b; font-weight: 700;" href="../tenant/inquire.php?property_id=<?= (int)$property['id'] ?>">Apply &amp; Send Inquiry</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="btn btn-primary w-100" href="mailto:?subject=Inquiry: <?= rawurlencode($property['title']) ?>">Send Inquiry</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.property-hero-img {
    width: 100%;
    height: 200px;
    border-radius: var(--radius-md);
    background: linear-gradient(135deg, var(--cpdo-navy), var(--cpdo-blue));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    font-weight: 900;
    color: rgba(255,255,255,.4);
    letter-spacing: .1em;
}
</style>

<?php require __DIR__ . '/../partials/footer.php'; ?>
