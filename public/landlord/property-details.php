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

// Dynamic Database Migration: ensure 'extended_details' column exists in properties table
try {
    db()->query("SELECT extended_details FROM properties LIMIT 1");
} catch (PDOException $e) {
    db()->exec("ALTER TABLE properties ADD COLUMN extended_details JSON NULL");
    // Re-fetch to populate
    $stmt->execute([$propertyId, (int)$user['id']]);
    $property = $stmt->fetch();
}

$details = json_decode($property['extended_details'] ?? '{}', true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Determine if Save as Draft was clicked
    $isDraft = !empty($_POST['save_draft']);

    // Retrieve values
    $title = trim($_POST['title'] ?? '');
    $listingType = trim($_POST['listing_type'] ?? '');
    $propertyCategory = trim($_POST['property_category'] ?? '');
    $listingPrice = (float)($_POST['listing_price'] ?? 0);
    $paymentFrequency = trim($_POST['payment_frequency'] ?? 'monthly');
    $securityDeposit = (float)($_POST['security_deposit'] ?? 0);
    $advanceRent = (float)($_POST['advance_rent'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    // Location components
    $unitFloor = trim($_POST['unit_floor'] ?? '');
    $buildingName = trim($_POST['building_name'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $cityMunicipality = trim($_POST['city_municipality'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $zipCode = trim($_POST['zip_code'] ?? '');

    // Construct unified address
    $addressParts = [];
    if ($unitFloor || $buildingName) {
        $addressParts[] = trim($unitFloor . ' ' . $buildingName);
    }
    if ($street) $addressParts[] = $street;
    if ($barangay) $addressParts[] = $barangay;
    if ($cityMunicipality) $addressParts[] = $cityMunicipality;
    if ($province) $addressParts[] = $province;
    if ($zipCode) $addressParts[] = $zipCode;
    $address = implode(', ', $addressParts);

    // Fallback if address ends up completely empty (though required in UI)
    if (empty($address)) {
        $address = $property['address'];
    }



    // Physical Specs
    $floorArea = (float)($_POST['floor_area'] ?? 0);
    $floorAreaUnit = trim($_POST['floor_area_unit'] ?? 'sqm');
    $lotSize = (float)($_POST['lot_size'] ?? 0);
    $bedrooms = (int)($_POST['bedrooms'] ?? 0);
    $bathroomsText = trim($_POST['bathrooms'] ?? '');
    $yearBuilt = $_POST['year_built'] !== '' ? (int)$_POST['year_built'] : null;
    $furnishingStatus = trim($_POST['furnishing_status'] ?? '');

    // Amenities
    $indoorFeatures = $_POST['indoor_features'] ?? [];
    $outdoorFeatures = $_POST['outdoor_features'] ?? [];

    // Media Links
    $virtualTourLink = trim($_POST['virtual_tour_link'] ?? '');

    // Keep existing images (handling deletions)
    $existingImages = $details['image_gallery'] ?? [];
    $imagesToKeep = [];
    if (isset($_POST['keep_images']) && is_array($_POST['keep_images'])) {
        foreach ($_POST['keep_images'] as $img) {
            if (in_array($img, $existingImages, true)) {
                $imagesToKeep[] = $img;
            }
        }
    }

    // Process new Image Gallery uploads
    $newImages = [];
    if (!empty($_FILES['image_gallery']['name'][0])) {
        $filesCount = count($_FILES['image_gallery']['name']);
        for ($i = 0; $i < $filesCount; $i++) {
            if ($_FILES['image_gallery']['error'][$i] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES['image_gallery']['name'][$i],
                    'type' => $_FILES['image_gallery']['type'][$i],
                    'tmp_name' => $_FILES['image_gallery']['tmp_name'][$i],
                    'error' => $_FILES['image_gallery']['error'][$i],
                    'size' => $_FILES['image_gallery']['size'][$i]
                ];
                try {
                    $path = secure_upload($file, 'properties', ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif']);
                    if ($path) {
                        $newImages[] = $path;
                    }
                } catch (Throwable $e) {
                    error_log('[CPDO PROPERTY DETAILS IMAGE UPLOAD] Failed: ' . $e->getMessage());
                }
            }
        }
    }
    $finalImages = array_merge($imagesToKeep, $newImages);

    // Keep existing videos (handling deletions)
    $existingVideos = $details['video_gallery'] ?? [];
    $videosToKeep = [];
    if (isset($_POST['keep_videos']) && is_array($_POST['keep_videos'])) {
        foreach ($_POST['keep_videos'] as $vid) {
            if (in_array($vid, $existingVideos, true)) {
                $videosToKeep[] = $vid;
            }
        }
    }

    // Process new Video Gallery uploads
    $newVideos = [];
    if (!empty($_FILES['video_gallery']['name'][0])) {
        $filesCount = count($_FILES['video_gallery']['name']);
        for ($i = 0; $i < $filesCount; $i++) {
            if ($_FILES['video_gallery']['error'][$i] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES['video_gallery']['name'][$i],
                    'type' => $_FILES['video_gallery']['type'][$i],
                    'tmp_name' => $_FILES['video_gallery']['tmp_name'][$i],
                    'error' => $_FILES['video_gallery']['error'][$i],
                    'size' => $_FILES['video_gallery']['size'][$i]
                ];
                try {
                    $path = secure_upload($file, 'properties', ['mp4', 'webm', 'ogg', 'mov', 'avi']);
                    if ($path) {
                        $newVideos[] = $path;
                    }
                } catch (Throwable $e) {
                    error_log('[CPDO PROPERTY DETAILS VIDEO UPLOAD] Failed: ' . $e->getMessage());
                }
            }
        }
    }
    $finalVideos = array_merge($videosToKeep, $newVideos);

    // Floor Plan Upload
    $floorPlanPath = $details['floor_plan'] ?? null;
    if (isset($_POST['delete_floor_plan']) && $_POST['delete_floor_plan'] === '1') {
        $floorPlanPath = null;
    }
    if (!empty($_FILES['floor_plan']['name']) && $_FILES['floor_plan']['error'] === UPLOAD_ERR_OK) {
        try {
            $path = secure_upload($_FILES['floor_plan'], 'properties', ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif']);
            if ($path) {
                $floorPlanPath = $path;
            }
        } catch (Throwable $e) {
            error_log('[CPDO PROPERTY DETAILS FLOOR PLAN UPLOAD] Failed: ' . $e->getMessage());
        }
    }

    // Vetting Criteria
    $vettingMinIncome = isset($_POST['vetting_min_income']) && $_POST['vetting_min_income'] !== '' ? (float)$_POST['vetting_min_income'] : 0.0;
    $vettingMaxOccupants = isset($_POST['vetting_max_occupants']) && $_POST['vetting_max_occupants'] !== '' ? (int)$_POST['vetting_max_occupants'] : 0;
    $vettingEmployment = trim($_POST['vetting_employment'] ?? 'Any');
    $vettingGenderPreference = trim($_POST['vetting_gender_preference'] ?? 'Any');
    $vettingPets = trim($_POST['vetting_pets'] ?? 'Yes');
    $vettingSmoking = trim($_POST['vetting_smoking'] ?? 'No');

    // Package to extended_details JSON
    $extendedDetails = [
        'listing_type' => $listingType,
        'property_category' => $propertyCategory,
        'payment_frequency' => $paymentFrequency,
        'security_deposit' => $securityDeposit,
        'advance_rent' => $advanceRent,
        'address_details' => [
            'unit_floor' => $unitFloor,
            'building_name' => $buildingName,
            'street' => $street,
            'barangay' => $barangay,
            'city_municipality' => $cityMunicipality,
            'province' => $province,
            'zip_code' => $zipCode
        ],

        'floor_area' => $floorArea,
        'floor_area_unit' => $floorAreaUnit,
        'lot_size' => $lotSize,
        'bedrooms' => $bedrooms,
        'bathrooms' => $bathroomsText,
        'year_built' => $yearBuilt,
        'furnishing_status' => $furnishingStatus,
        'indoor_features' => $indoorFeatures,
        'outdoor_features' => $outdoorFeatures,
        'image_gallery' => $finalImages,
        'video_gallery' => $finalVideos,
        'floor_plan' => $floorPlanPath,
        'virtual_tour_link' => $virtualTourLink,
        'vetting_criteria' => [
            'min_income' => $vettingMinIncome,
            'max_occupants' => $vettingMaxOccupants,
            'employment' => $vettingEmployment,
            'gender_preference' => $vettingGenderPreference,
            'pets' => $vettingPets,
            'smoking' => $vettingSmoking
        ]
    ];

    $extendedDetailsJson = json_encode($extendedDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Save details to DB
    $stmt = db()->prepare(
        'UPDATE properties 
         SET title = ?, address = ?, description = ?, monthly_rent = ?, extended_details = ? 
         WHERE id = ? AND landlord_id = ?'
    );
    $stmt->execute([
        $title ?: $property['title'],
        $address ?: $property['address'],
        $description ?: null,
        $listingPrice,
        $extendedDetailsJson,
        $propertyId,
        (int)$user['id']
    ]);

    audit_log((int)$user['id'], 'PROPERTY_DETAILS_UPDATED', 'properties', $propertyId);

    if ($isDraft) {
        $_SESSION['flash_success'] = 'Draft saved successfully.';
        redirect('landlord/dashboard.php');
    } else {
        $_SESSION['flash_success'] = 'Property details saved. Proceed to review and publish.';
        redirect('landlord/property-publish.php?id=' . $propertyId);
    }
}

// Prefill helpers
function get_detail(array $details, string $key, $default = '') {
    return $details[$key] ?? $default;
}

function get_sub_detail(array $details, string $section, string $key, $default = '') {
    return $details[$section][$key] ?? $default;
}

require __DIR__ . '/../partials/header.php';
?>

<style>
    .section-card {
        background: #ffffff;
        border: 1px solid #f0dfad;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 4px 12px rgba(36, 27, 11, 0.02);
    }
    
    .section-title {
        color: #241b0b;
        font-weight: 800;
        font-size: 1.1rem;
        margin-bottom: 18px;
        border-bottom: 2px solid #f6cf4a;
        padding-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .form-label {
        font-weight: 700;
        color: #241b0b;
        font-size: 0.88rem;
        margin-bottom: 6px;
    }

    .form-control, .form-select {
        border: 1px solid #d4c89a;
        border-radius: 8px;
        padding: 10px 12px;
        font-size: 0.9rem;
        background-color: #fff;
    }

    .form-control:focus, .form-select:focus {
        border-color: #f6cf4a;
        box-shadow: 0 0 0 3px rgba(246, 207, 74, 0.2);
        outline: none;
    }

    /* Custom Switch / Checkbox styling */
    .chip-checkbox-group {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .chip-checkbox-btn {
        display: none;
    }

    .chip-checkbox-label {
        background: #fffdf5;
        border: 1px solid #f0dfad;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.84rem;
        font-weight: 650;
        color: #5b4d30;
        cursor: pointer;
        user-select: none;
        transition: all 0.15s ease;
    }

    .chip-checkbox-btn:checked + .chip-checkbox-label {
        background: #f6cf4a;
        border-color: #f6cf4a;
        color: #241b0b;
        box-shadow: 0 4px 10px rgba(246, 207, 74, 0.15);
    }

    /* Image Gallery Grid */
    .gallery-preview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
        gap: 12px;
        margin-top: 12px;
    }

    .gallery-preview-item {
        position: relative;
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid #f0dfad;
        aspect-ratio: 1;
        background: #ccc;
    }

    .gallery-preview-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .gallery-delete-overlay {
        position: absolute;
        top: 4px;
        right: 4px;
        background: rgba(239, 68, 68, 0.9);
        color: white;
        border: none;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 0.75rem;
        font-weight: bold;
        transition: all 0.15s ease;
    }

    .gallery-preview-item.marked-delete img {
        filter: grayscale(1) opacity(0.4);
    }

    .gallery-preview-item.marked-delete {
        border-color: #ef4444;
        background: #fef2f2;
    }

    .gallery-delete-checkbox {
        display: none;
    }

    .upload-drag-zone {
        border: 2px dashed #d4c89a;
        background: #fffdf5;
        border-radius: 12px;
        padding: 32px 16px;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .upload-drag-zone:hover {
        border-color: #f6cf4a;
        background: #fffcef;
    }
</style>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="house-rules.php?id=<?= $propertyId ?>"><span aria-hidden="true">&larr;</span> Back</a>
    <h1 class="h3 mb-0">Property Details</h1>
</div>

<div class="mb-4">
    <div class="d-flex align-items-center mb-2">
        <span class="badge bg-primary rounded-pill me-2" style="background-color: #c59000 !important;">Step 3 of 4</span>
        <h2 class="h5 mb-0">Listing Details &amp; Attributes</h2>
    </div>
    <p class="text-secondary small">Provide detailed pricing, location specifications, structural layouts, and media uploads to optimize search listing visibility for "<?= e($property['title']) ?>".</p>
</div>

<form method="post" enctype="multipart/form-data" id="property-details-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$propertyId ?>">

    <div class="row">
        <div class="col-lg-8">

            <!-- SECTION 1: Core Listing & Pricing Details -->
            <div class="section-card">
                <h3 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    1. Core Listing &amp; Pricing Details
                </h3>

                <div class="mb-3">
                    <label class="form-label">Listing Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" required placeholder="e.g. Modern 2-BR Minimalist Condo with City View" value="<?= e($property['title']) ?>">
                    <div class="form-text text-secondary small">A short, descriptive headline to attract prospective tenants.</div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Listing Type <span class="text-danger">*</span></label>
                        <select class="form-select" name="listing_type" required>
                            <option value="" disabled>-- Select Intent --</option>
                            <option value="Rental" <?= get_detail($details, 'listing_type', 'Rental') === 'Rental' ? 'selected' : '' ?>>Rental</option>
                            <option value="Lease" <?= get_detail($details, 'listing_type') === 'Lease' ? 'selected' : '' ?>>Lease</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Property Category <span class="text-danger">*</span></label>
                        <select class="form-select" name="property_category" required>
                            <option value="" disabled>-- Select Category --</option>
                            <option value="Apartment" <?= get_detail($details, 'property_category') === 'Apartment' ? 'selected' : '' ?>>Apartment</option>
                            <option value="Condominium" <?= get_detail($details, 'property_category') === 'Condominium' ? 'selected' : '' ?>>Condominium</option>
                            <option value="Single-Family House" <?= get_detail($details, 'property_category') === 'Single-Family House' ? 'selected' : '' ?>>Single-Family House</option>
                            <option value="Townhouse" <?= get_detail($details, 'property_category') === 'Townhouse' ? 'selected' : '' ?>>Townhouse</option>
                            <option value="Vacant Land" <?= get_detail($details, 'property_category') === 'Vacant Land' ? 'selected' : '' ?>>Vacant Land</option>
                            <option value="Commercial Space" <?= get_detail($details, 'property_category') === 'Commercial Space' ? 'selected' : '' ?>>Commercial Space</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Listing Price / Rent (₱) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="listing_price" required min="0" value="<?= e((string)($property['monthly_rent'] ?: '0.00')) ?>" placeholder="0.00">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Payment Terms / Frequency <span class="text-danger">*</span></label>
                        <select class="form-select" name="payment_frequency" required>
                            <option value="monthly" <?= get_detail($details, 'payment_frequency') === 'monthly' ? 'selected' : '' ?>>Monthly Rent</option>
                            <option value="annually" <?= get_detail($details, 'payment_frequency') === 'annually' ? 'selected' : '' ?>>Annually</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Required Security Deposit (₱)</label>
                        <input type="number" step="0.01" class="form-control" name="security_deposit" min="0" value="<?= e((string)get_detail($details, 'security_deposit', '0.00')) ?>" placeholder="0.00">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Required Advance Rent (₱)</label>
                        <input type="number" step="0.01" class="form-control" name="advance_rent" min="0" value="<?= e((string)get_detail($details, 'advance_rent', '0.00')) ?>" placeholder="0.00">
                    </div>
                </div>

                <div class="mb-0">
                    <label class="form-label">Description <span class="text-secondary small">(optional)</span></label>
                    <textarea class="form-control" name="description" rows="4" placeholder="Describe unique highlights, landmarks, rules, or utilities included..."><?= e($property['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- SECTION 2: Address -->
            <div class="section-card">
                <h3 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2a8 8 0 0 0-8 8c0 5.25 8 12 8 12s8-6.75 8-12a8 8 0 0 0-8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                    2. Address
                </h3>

                <p class="text-secondary small mb-3">Structure your street address details below. This generates the display address.</p>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Unit / Floor Number</label>
                        <input type="text" class="form-control" name="unit_floor" placeholder="e.g. Unit 402B" value="<?= e(get_sub_detail($details, 'address_details', 'unit_floor')) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Building Name</label>
                        <input type="text" class="form-control" name="building_name" placeholder="e.g. Oakridge Condominium" value="<?= e(get_sub_detail($details, 'address_details', 'building_name')) ?>">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Street Address <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="street" required placeholder="e.g. 12 Orchard Road" value="<?= e(get_sub_detail($details, 'address_details', 'street')) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Barangay <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="barangay" required placeholder="e.g. Brgy. Matina Aplaya" value="<?= e(get_sub_detail($details, 'address_details', 'barangay')) ?>">
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">City / Municipality <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="city_municipality" required placeholder="e.g. Davao City" value="<?= e(get_sub_detail($details, 'address_details', 'city_municipality')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Province <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="province" required placeholder="e.g. Davao del Sur" value="<?= e(get_sub_detail($details, 'address_details', 'province')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Zip Code</label>
                        <input type="text" class="form-control" name="zip_code" placeholder="e.g. 8000" value="<?= e(get_sub_detail($details, 'address_details', 'zip_code')) ?>">
                    </div>
                </div>
            </div>

            <!-- SECTION 3: Physical Attributes & Specifications -->
            <div class="section-card">
                <h3 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16M8 21h8M8 7h2M8 11h2M14 7h2M14 11h2"/></svg>
                    3. Physical Attributes &amp; Specifications
                </h3>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Floor / Living Area <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" step="0.1" class="form-control" name="floor_area" required min="1" value="<?= e((string)get_detail($details, 'floor_area')) ?>" placeholder="e.g. 45">
                            <select class="form-select" name="floor_area_unit" style="max-width: 90px;">
                                <option value="sqm" <?= get_detail($details, 'floor_area_unit', 'sqm') === 'sqm' ? 'selected' : '' ?>>m²</option>
                                <option value="sqft" <?= get_detail($details, 'floor_area_unit') === 'sqft' ? 'selected' : '' ?>>ft²</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Lot Size <span class="text-secondary small">(if applicable)</span></label>
                        <div class="input-group">
                            <input type="number" step="0.1" class="form-control" name="lot_size" min="0" value="<?= e((string)get_detail($details, 'lot_size', '0')) ?>" placeholder="e.g. 150">
                            <span class="input-group-text bg-light text-secondary">m²</span>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Bedroom Count</label>
                        <select class="form-select" name="bedrooms">
                            <?php for ($i=0; $i<=10; $i++): ?>
                                <option value="<?= $i ?>" <?= (int)get_detail($details, 'bedrooms', 0) === $i ? 'selected' : '' ?>><?= $i === 0 ? 'Studio / None' : $i . ' Bedroom' . ($i > 1 ? 's' : '') ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Bathroom Details</label>
                        <select class="form-select" name="bathrooms">
                            <?php
                            $bathOptions = [
                                '1' => '1 Bathroom',
                                '1.5' => '1.5 Bathrooms',
                                '2' => '2 Bathrooms',
                                '2.5' => '2.5 Bathrooms',
                                '3' => '3 Bathrooms',
                                '3.5' => '3.5 Bathrooms',
                                '4' => '4 Bathrooms',
                                '5+' => '5+ Bathrooms'
                            ];
                            $currentBath = get_detail($details, 'bathrooms', '1');
                            if ($currentBath !== '' && !isset($bathOptions[$currentBath])) {
                                $bathOptions[$currentBath] = $currentBath;
                            }
                            foreach ($bathOptions as $val => $lbl):
                            ?>
                                <option value="<?= e($val) ?>" <?= $currentBath === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Year Built</label>
                        <input type="number" class="form-control" name="year_built" placeholder="e.g. 2021" min="1800" max="<?= date('Y') + 1 ?>" value="<?= e((string)get_detail($details, 'year_built', '')) ?>">
                    </div>
                </div>

                <div class="mb-0">
                    <label class="form-label">Furnishing Status <span class="text-danger">*</span></label>
                    <select class="form-select" name="furnishing_status" required>
                        <option value="" disabled>-- Select Furnishing Status --</option>
                        <option value="Unfurnished" <?= get_detail($details, 'furnishing_status', 'Unfurnished') === 'Unfurnished' ? 'selected' : '' ?>>Unfurnished</option>
                        <option value="Semi-Furnished" <?= get_detail($details, 'furnishing_status') === 'Semi-Furnished' ? 'selected' : '' ?>>Semi-Furnished</option>
                        <option value="Fully Furnished" <?= get_detail($details, 'furnishing_status') === 'Fully Furnished' ? 'selected' : '' ?>>Fully Furnished</option>
                    </select>
                </div>
            </div>

            <!-- SECTION 4: Amenities & Features -->
            <div class="section-card">
                <h3 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    4. Amenities &amp; Features
                </h3>

                <div class="mb-4">
                    <label class="form-label d-block mb-2">Indoor Features</label>
                    <div class="chip-checkbox-group">
                        <?php
                        $defaultIndoor = ['Built-in wardrobes', 'Air conditioning units', 'Fiber internet readiness', "Maid's room", 'Elevators'];
                        $savedIndoor = get_detail($details, 'indoor_features', []);
                        $indoorOptions = array_unique(array_merge($defaultIndoor, $savedIndoor));
                        foreach ($indoorOptions as $opt):
                            $id = 'chk_in_' . md5($opt);
                            $checked = in_array($opt, $savedIndoor, true) ? 'checked' : '';
                        ?>
                            <input type="checkbox" name="indoor_features[]" value="<?= e($opt) ?>" id="<?= $id ?>" class="chip-checkbox-btn" <?= $checked ?>>
                            <label for="<?= $id ?>" class="chip-checkbox-label"><?= e($opt) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="input-group mt-2" style="max-width: 320px;">
                        <input type="text" id="add-indoor-input" class="form-control form-control-sm" placeholder="Add custom indoor feature...">
                        <button class="btn btn-sm btn-outline-warning text-dark fw-bold" style="border-color: #d4c89a;" type="button" onclick="addCustomFeature('indoor')">+ Add</button>
                    </div>
                </div>

                <div class="mb-0">
                    <label class="form-label d-block mb-2">Outdoor / Shared Features</label>
                    <div class="chip-checkbox-group">
                        <?php
                        $defaultOutdoor = ['Balcony', 'Swimming pool', 'Private garage', 'Gym access', '24/7 Security', 'Gated community'];
                        $savedOutdoor = get_detail($details, 'outdoor_features', []);
                        $outdoorOptions = array_unique(array_merge($defaultOutdoor, $savedOutdoor));
                        foreach ($outdoorOptions as $opt):
                            $id = 'chk_out_' . md5($opt);
                            $checked = in_array($opt, $savedOutdoor, true) ? 'checked' : '';
                        ?>
                            <input type="checkbox" name="outdoor_features[]" value="<?= e($opt) ?>" id="<?= $id ?>" class="chip-checkbox-btn" <?= $checked ?>>
                            <label for="<?= $id ?>" class="chip-checkbox-label"><?= e($opt) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="input-group mt-2" style="max-width: 320px;">
                        <input type="text" id="add-outdoor-input" class="form-control form-control-sm" placeholder="Add custom outdoor feature...">
                        <button class="btn btn-sm btn-outline-warning text-dark fw-bold" style="border-color: #d4c89a;" type="button" onclick="addCustomFeature('outdoor')">+ Add</button>
                    </div>
                </div>
            </div>

            <!-- SECTION 6: Tenant Vetting Criteria -->
            <div class="section-card">
                <h3 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    6. Tenant Vetting Criteria
                </h3>
                <p class="text-secondary small mb-3">Set eligibility thresholds for prospective tenants. These criteria will be matched against tenant answers in their inquiry form.</p>
                
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Minimum Monthly Income (₱)</label>
                        <input type="number" step="0.01" class="form-control" name="vetting_min_income" placeholder="e.g. 20000" min="0" value="<?= e((string)get_sub_detail($details, 'vetting_criteria', 'min_income', '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Maximum Occupants</label>
                        <input type="number" class="form-control" name="vetting_max_occupants" placeholder="e.g. 4" min="1" value="<?= e((string)get_sub_detail($details, 'vetting_criteria', 'max_occupants', '')) ?>">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Preferred Employment Status</label>
                        <select class="form-select" name="vetting_employment">
                            <option value="Any" <?= get_sub_detail($details, 'vetting_criteria', 'employment', 'Any') === 'Any' ? 'selected' : '' ?>>Any</option>
                            <option value="Employed" <?= get_sub_detail($details, 'vetting_criteria', 'employment') === 'Employed' ? 'selected' : '' ?>>Employed</option>
                            <option value="Self-Employed" <?= get_sub_detail($details, 'vetting_criteria', 'employment') === 'Self-Employed' ? 'selected' : '' ?>>Self-Employed</option>
                            <option value="Student" <?= get_sub_detail($details, 'vetting_criteria', 'employment') === 'Student' ? 'selected' : '' ?>>Student</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Gender Preference</label>
                        <select class="form-select" name="vetting_gender_preference">
                            <option value="Any" <?= get_sub_detail($details, 'vetting_criteria', 'gender_preference', 'Any') === 'Any' ? 'selected' : '' ?>>Any / No Preference</option>
                            <option value="Male" <?= get_sub_detail($details, 'vetting_criteria', 'gender_preference') === 'Male' ? 'selected' : '' ?>>Male Only</option>
                            <option value="Female" <?= get_sub_detail($details, 'vetting_criteria', 'gender_preference') === 'Female' ? 'selected' : '' ?>>Female Only</option>
                        </select>
                        <div class="form-text text-secondary small">Set to <em>Any</em> unless applicable to shared/dormitory units.</div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Pets Allowed</label>
                        <select class="form-select" name="vetting_pets">
                            <option value="Yes" <?= get_sub_detail($details, 'vetting_criteria', 'pets', 'Yes') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                            <option value="No" <?= get_sub_detail($details, 'vetting_criteria', 'pets') === 'No' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Smoking Allowed</label>
                        <select class="form-select" name="vetting_smoking">
                            <option value="No" <?= get_sub_detail($details, 'vetting_criteria', 'smoking', 'No') === 'No' ? 'selected' : '' ?>>No</option>
                            <option value="Yes" <?= get_sub_detail($details, 'vetting_criteria', 'smoking') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                </div>
            </div>

        </div>

        <div class="col-lg-4">

            <!-- SECTION 5: Media & Documentation -->
            <div class="section-card">
                <h3 class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    5. Media &amp; Documentation
                </h3>

                <div class="mb-4">
                    <label class="form-label">Image Gallery (JPEG/PNG)</label>
                    <div class="upload-drag-zone" onclick="document.getElementById('gallery-input').click();">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none" stroke="#a89562" stroke-width="2" class="mb-2" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                        <p class="mb-1 text-dark small fw-bold">Click to Upload Images</p>
                        <span class="text-secondary small">Supports multiple files</span>
                    </div>
                    <input type="file" id="gallery-input" name="image_gallery[]" multiple accept="image/*" class="d-none" onchange="previewNewImages(this)">
                    
                    <!-- Previews & Deletions -->
                    <div id="new-gallery-previews" class="gallery-preview-grid"></div>
                    
                    <?php if (!empty($details['image_gallery'])): ?>
                        <p class="small fw-bold text-dark mt-3 mb-2">Existing Uploaded Images:</p>
                        <div class="gallery-preview-grid">
                            <?php foreach ($details['image_gallery'] as $imgIndex => $imgUrl): ?>
                                <div class="gallery-preview-item" id="existing-img-<?= $imgIndex ?>">
                                    <img src="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $imgUrl) ?>" alt="Uploaded property visual">
                                    <input type="checkbox" name="keep_images[]" value="<?= e($imgUrl) ?>" checked class="gallery-delete-checkbox" id="keep-check-<?= $imgIndex ?>">
                                    <button type="button" class="gallery-delete-overlay" onclick="toggleImageDelete(<?= $imgIndex ?>)" title="Mark for removal">✖</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text text-secondary small mt-1">Click the '✖' overlays to delete existing images.</div>
                    <?php endif; ?>
                </div>

                <div class="mb-4">
                    <label class="form-label">Video Gallery (MP4/WebM)</label>
                    <div class="upload-drag-zone" onclick="document.getElementById('video-input').click();">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none" stroke="#a89562" stroke-width="2" class="mb-2" viewBox="0 0 24 24"><path d="M23 7a2 2 0 0 0-2-2H3a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V7z"/><path d="m10 9 5 3-5 3V9z"/></svg>
                        <p class="mb-1 text-dark small fw-bold">Click to Upload Videos</p>
                        <span class="text-secondary small">Supports multiple files</span>
                    </div>
                    <input type="file" id="video-input" name="video_gallery[]" multiple accept="video/*" class="d-none" onchange="previewNewVideos(this)">
                    
                    <!-- Previews & Deletions -->
                    <div id="new-video-previews" class="gallery-preview-grid"></div>
                    
                    <?php if (!empty($details['video_gallery'])): ?>
                        <p class="small fw-bold text-dark mt-3 mb-2">Existing Uploaded Videos:</p>
                        <div class="gallery-preview-grid">
                            <?php foreach ($details['video_gallery'] as $vidIndex => $vidUrl): ?>
                                <div class="gallery-preview-item" id="existing-vid-<?= $vidIndex ?>">
                                    <video src="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $vidUrl) ?>" style="width:100%; height:100%; object-fit:cover;" muted loop playsinline></video>
                                    <input type="checkbox" name="keep_videos[]" value="<?= e($vidUrl) ?>" checked class="gallery-delete-checkbox" id="keep-vid-check-<?= $vidIndex ?>">
                                    <button type="button" class="gallery-delete-overlay" onclick="toggleVideoDelete(<?= $vidIndex ?>)" title="Mark for removal">✖</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text text-secondary small mt-1">Click the '✖' overlays to delete existing videos.</div>
                    <?php endif; ?>
                </div>

                <div class="mb-4">
                    <label class="form-label">Virtual Tour / Video Link</label>
                    <input type="url" class="form-control" name="virtual_tour_link" placeholder="e.g. https://my.matterport.com/show/?m=..." value="<?= e(get_detail($details, 'virtual_tour_link')) ?>">
                </div>

                <div class="mb-0">
                    <label class="form-label">Floor Plan Blueprint (JPEG/PNG/PDF)</label>
                    <input type="file" class="form-control mb-2" name="floor_plan" accept="image/*,application/pdf">
                    <?php if (!empty($details['floor_plan'])): ?>
                        <div class="p-2 border rounded bg-light d-flex align-items-center justify-content-between small text-secondary">
                            <span class="text-truncate">📄 <?= basename($details['floor_plan']) ?></span>
                            <div class="form-check m-0">
                                <input class="form-check-input" type="checkbox" name="delete_floor_plan" value="1" id="del-fp-check">
                                <label class="form-check-label fw-bold text-danger" for="del-fp-check">Delete</label>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SUBMIT ACTIONS -->
            <div class="gov-card p-3 d-flex flex-column gap-2 text-center" style="border-radius: 16px; border-color: #f0dfad;">
                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold" style="background-color: #c59000; border-color: #c59000;">
                    Save &amp; Continue
                </button>
                <button type="submit" name="save_draft" value="1" formnovalidate class="btn btn-outline-secondary w-100 py-2">
                    Save as Draft
                </button>
            </div>

        </div>
    </div>
</form>

<script>
function toggleImageDelete(idx) {
    const item = document.getElementById('existing-img-' + idx);
    const chk = document.getElementById('keep-check-' + idx);
    if (chk.checked) {
        chk.checked = false;
        item.classList.add('marked-delete');
    } else {
        chk.checked = true;
        item.classList.remove('marked-delete');
    }
}

// Keep track of files to be uploaded
let selectedImages = [];
let selectedVideos = [];

function previewNewImages(input) {
    if (input.files) {
        Array.from(input.files).forEach(file => {
            // Avoid duplicates
            if (!selectedImages.some(f => f.name === file.name && f.size === file.size)) {
                selectedImages.push(file);
            }
        });
    }
    
    syncInputFiles(input, selectedImages);
    renderImagePreviews();
}

function renderImagePreviews() {
    const previewGrid = document.getElementById('new-gallery-previews');
    previewGrid.innerHTML = '';
    
    selectedImages.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const item = document.createElement('div');
            item.className = 'gallery-preview-item';
            item.innerHTML = `
                <img src="${e.target.result}" alt="New visual preview">
                <button type="button" class="gallery-delete-overlay" onclick="removeNewImage(${index})" title="Remove">✖</button>
            `;
            previewGrid.appendChild(item);
        };
        reader.readAsDataURL(file);
    });
}

function removeNewImage(index) {
    selectedImages.splice(index, 1);
    const input = document.getElementById('gallery-input');
    syncInputFiles(input, selectedImages);
    renderImagePreviews();
}

function toggleVideoDelete(idx) {
    const item = document.getElementById('existing-vid-' + idx);
    const chk = document.getElementById('keep-vid-check-' + idx);
    if (chk.checked) {
        chk.checked = false;
        item.classList.add('marked-delete');
    } else {
        chk.checked = true;
        item.classList.remove('marked-delete');
    }
}

function previewNewVideos(input) {
    if (input.files) {
        Array.from(input.files).forEach(file => {
            if (!selectedVideos.some(f => f.name === file.name && f.size === file.size)) {
                selectedVideos.push(file);
            }
        });
    }
    
    syncInputFiles(input, selectedVideos);
    renderVideoPreviews();
}

function renderVideoPreviews() {
    const previewGrid = document.getElementById('new-video-previews');
    previewGrid.innerHTML = '';
    
    selectedVideos.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const item = document.createElement('div');
            item.className = 'gallery-preview-item';
            item.innerHTML = `
                <video src="${e.target.result}" style="width:100%; height:100%; object-fit:cover;" muted playsinline></video>
                <button type="button" class="gallery-delete-overlay" onclick="removeNewVideo(${index})" title="Remove">✖</button>
            `;
            previewGrid.appendChild(item);
        };
        reader.readAsDataURL(file);
    });
}

function removeNewVideo(index) {
    selectedVideos.splice(index, 1);
    const input = document.getElementById('video-input');
    syncInputFiles(input, selectedVideos);
    renderVideoPreviews();
}

function syncInputFiles(input, fileArray) {
    const dt = new DataTransfer();
    fileArray.forEach(file => dt.items.add(file));
    input.files = dt.files;
}

function addCustomFeature(type) {
    const input = document.getElementById(`add-${type}-input`);
    const val = input.value.trim();
    if (!val) return;
    
    // Check if feature already exists in the container
    const container = input.closest('.mb-4, .mb-0').querySelector('.chip-checkbox-group');
    const existingLabels = Array.from(container.querySelectorAll('.chip-checkbox-label')).map(el => el.textContent.trim().toLowerCase());
    
    if (existingLabels.includes(val.toLowerCase())) {
        alert('This feature already exists.');
        input.value = '';
        return;
    }
    
    // Generate unique ID using random string
    const id = `chk_${type}_custom_${Math.random().toString(36).substring(2, 9)}`;
    
    // Create elements
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.name = `${type}_features[]`;
    checkbox.value = val;
    checkbox.id = id;
    checkbox.className = 'chip-checkbox-btn';
    checkbox.checked = true;
    
    const label = document.createElement('label');
    label.htmlFor = id;
    label.className = 'chip-checkbox-label';
    label.textContent = val;
    
    container.appendChild(checkbox);
    container.appendChild(label);
    
    input.value = '';
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
