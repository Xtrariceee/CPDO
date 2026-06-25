<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$user = require_role([ROLE_LANDLORD]);
verify_csrf();

/* ── address/title carried from compliance-gateway ───────────────────────── */
$propertyId = (int)($_GET['property_id'] ?? $_POST['property_id'] ?? 0);
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

$prefilledAddress = trim($_GET['address'] ?? $_POST['property_address'] ?? ($property['address'] ?? ''));
$prefilledTitle   = trim($_GET['title'] ?? $_POST['property_title'] ?? ($property['title'] ?? ''));

/*
|--------------------------------------------------------------------------
| Required documents for already-compliant rental/property verification
|--------------------------------------------------------------------------
*/
$requiredDocuments = [
    'building_permit' => [
        'title' => 'Building Permit',
        'description' => 'Official building permit issued for the property or structure.',
    ],
    'certificate_of_occupancy' => [
        'title' => 'Certificate of Occupancy',
        'description' => 'Certificate confirming that the building or unit is approved for occupancy.',
    ],
    
    'fire_safety_inspection_certificate' => [
        'title' => 'Fire Safety Inspection Certificate (FSIC)',
        'description' => 'Fire safety clearance or certificate issued after fire safety inspection.',
    ],
    'sanitary_permit' => [
        'title' => 'Sanitary Permit',
        'description' => 'Sanitary permit confirming health and sanitation compliance.',
    ],
    'bir_registration' => [
        'title' => 'BIR Registration',
        'description' => 'BIR registration document for the rental business or property operation.',
    ],
];

/*
|--------------------------------------------------------------------------
| Lightweight schema helpers
| This lets the page add the new document path columns if they do not exist.
|--------------------------------------------------------------------------
*/
if (!function_exists('skip_compliance_ident')) {
    function skip_compliance_ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

if (!function_exists('skip_compliance_column_exists')) {
    function skip_compliance_column_exists(string $table, string $column): bool
    {
        try {
            $stmt = db()->prepare('SHOW COLUMNS FROM ' . skip_compliance_ident($table) . ' LIKE ' . db()->quote($column));
            $stmt->execute();
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $exception) {
            return false;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $propertyTitle   = trim($_POST['property_title']   ?? '');
    $propertyAddress = trim($_POST['property_address'] ?? '');

    if ($propertyTitle === '') {
        $_SESSION['flash_error'] = 'Property title is required.';
        $query = http_build_query(array_filter([
            'address' => $prefilledAddress ?: $propertyAddress,
            'title' => $prefilledTitle ?: $propertyTitle,
            'property_id' => $propertyId ?: null,
        ]));
        redirect('landlord/compliance-verification.php' . ($query ? '?' . $query : ''));
    }

    if ($propertyAddress === '') {
        $_SESSION['flash_error'] = 'Property address is required so compliance can be tied to the correct property.';
        $query = http_build_query(array_filter([
            'address' => $prefilledAddress ?: $propertyAddress,
            'title' => $prefilledTitle ?: $propertyTitle,
            'property_id' => $propertyId ?: null,
        ]));
        redirect('landlord/compliance-verification.php' . ($query ? '?' . $query : ''));
    }

    try {
        foreach ([
            'property_address',
            'building_permit_path',
            'certificate_of_occupancy_path',
            'fire_safety_inspection_certificate_path',
            'sanitary_permit_path',
            'bir_registration_path',
        ] as $column) {
            if (!skip_compliance_column_exists('compliance_uploads', $column)) {
                throw new RuntimeException('Database schema is missing required compliance_uploads column: ' . $column);
            }
        }
    } catch (Throwable $exception) {
        $_SESSION['flash_error'] = 'Database schema is missing required compliance document columns. Please update your database schema and try again.';
        redirect('landlord/compliance-verification.php');
    }

    $uploadedPaths = [];

    foreach ($requiredDocuments as $key => $document) {
        if (empty($_FILES[$key]['name'])) {
            $_SESSION['flash_error'] = 'Please upload all required compliance documents.';
            redirect('landlord/compliance-verification.php');
        }

        $path = secure_upload($_FILES[$key], 'compliance/' . $user['id']);

        if (!$path) {
            $_SESSION['flash_error'] = 'Upload failed for: ' . $document['title'];
            redirect('landlord/compliance-verification.php');
        }

        $uploadedPaths[$key] = $path;
    }

    $pdo = db();

    /*
     * Insert new document columns.
     * Old columns are also populated for compatibility with older verification screens.
     */
    $insertData = [
        'landlord_id'      => (int)$user['id'],
        'property_title'   => $propertyTitle,
        'property_address' => $propertyAddress,

        'building_permit_path'                    => $uploadedPaths['building_permit'],
        'certificate_of_occupancy_path'           => $uploadedPaths['certificate_of_occupancy'],
        'fire_safety_inspection_certificate_path' => $uploadedPaths['fire_safety_inspection_certificate'],
        'sanitary_permit_path'                    => $uploadedPaths['sanitary_permit'],
        'bir_registration_path'                   => $uploadedPaths['bir_registration'],
    ];

    /*
     * Legacy compatibility.
     * These columns existed in your old compliance-verification.php.
     */
    if (skip_compliance_column_exists('compliance_uploads', 'approved_resolution_path')) {
        $insertData['approved_resolution_path'] = $uploadedPaths['building_permit'];
    }

    if (skip_compliance_column_exists('compliance_uploads', 'zoning_clearance_path')) {
        $insertData['zoning_clearance_path'] = $uploadedPaths['certificate_of_occupancy'];
    }

    

    $columns = array_keys($insertData);
    $placeholders = array_fill(0, count($columns), '?');

    $stmt = $pdo->prepare(
        'INSERT INTO compliance_uploads (' .
        implode(', ', array_map('skip_compliance_ident', $columns)) .
        ') VALUES (' .
        implode(', ', $placeholders) .
        ')'
    );

    $stmt->execute(array_values($insertData));
    $complianceId = (int)$pdo->lastInsertId();

    if ($propertyId) {
        $updateStmt = $pdo->prepare(
            'UPDATE properties SET compliance_upload_id = ? WHERE id = ? AND landlord_id = ?'
        );
        $updateStmt->execute([$complianceId, $propertyId, (int)$user['id']]);
    }

    audit_log(
        (int)$user['id'],
        'SKIP_COMPLIANCE_SUBMITTED',
        'compliance_uploads',
        $complianceId
    );

    $_SESSION['flash_success'] = 'Documents submitted successfully. The System Admin will verify them shortly.';
    if ($propertyId) {
        redirect('landlord/property-form.php?id=' . $propertyId);
    }
    redirect('landlord/dashboard.php');
}

require __DIR__ . '/../partials/header.php';
?>

<style>
    :root {
        --skip-ink: #241b0b;
        --skip-muted: #6f6656;
        --skip-muted-dark: #4f4738;
        --skip-soft: #fff8dd;
        --skip-soft-2: #fffdf6;
        --skip-gold: #f6cf4a;
        --skip-gold-dark: #c59000;
        --skip-border: #f0dfad;
        --skip-success: #157347;
        --skip-danger: #dc3545;
        --skip-card: #ffffff;
        --skip-shadow: 0 18px 44px rgba(36, 27, 11, 0.08);
        --skip-shadow-strong: 0 24px 58px rgba(36, 27, 11, 0.12);
    }

    body.skip-compliance-page {
        background:
            radial-gradient(circle at 1px 1px, rgba(246, 207, 74, 0.15) 1px, transparent 0),
            linear-gradient(180deg, #ffffff 0%, #fffdf7 100%);
        background-size: 28px 28px, 100% 100%;
        color: var(--skip-ink);
    }

    .skip-shell {
        max-width: 1180px;
        margin: 0 auto;
        padding: 34px 18px 64px;
    }

    .skip-topbar {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 24px;
    }

    .skip-back {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 40px;
        padding: 9px 15px;
        border-radius: 11px;
        background: #ffffff;
        color: var(--skip-ink);
        border: 1px solid #d6e0ec;
        text-decoration: none;
        font-size: 0.84rem;
        font-weight: 850;
        box-shadow: 0 10px 24px rgba(36, 27, 11, 0.06);
        transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .skip-back:hover {
        color: var(--skip-ink);
        background: var(--skip-soft);
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(36, 27, 11, 0.09);
    }

    .skip-eyebrow {
        margin: 0 0 6px;
        color: var(--skip-muted-dark);
        font-size: 0.72rem;
        font-weight: 950;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .skip-title {
        margin: 0;
        color: var(--skip-ink);
        font-size: clamp(1.85rem, 4vw, 2.75rem);
        font-weight: 950;
        line-height: 1.05;
        letter-spacing: -0.045em;
    }

    .skip-subtitle {
        max-width: 760px;
        margin: 12px 0 0;
        color: var(--skip-muted);
        font-size: 0.96rem;
        line-height: 1.7;
        font-weight: 550;
    }

    .skip-grid {
        display: grid;
        grid-template-columns: 0.82fr 1.18fr;
        gap: 26px;
        align-items: start;
    }

    .skip-info-card,
    .skip-form-card {
        position: relative;
        overflow: hidden;
        border-radius: 24px;
        background:
            radial-gradient(circle at top right, rgba(246, 207, 74, 0.16), transparent 36%),
            #ffffff;
        border: 1px solid var(--skip-border);
        box-shadow: var(--skip-shadow);
    }

    .skip-info-card {
        padding: 26px;
    }

    .skip-info-header {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        margin-bottom: 20px;
    }

    .skip-info-icon {
        width: 56px;
        height: 56px;
        flex: 0 0 56px;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--skip-soft);
        color: var(--skip-gold-dark);
        border: 1px solid #efd185;
        box-shadow: 0 12px 24px rgba(197, 144, 0, 0.10);
    }

    .skip-info-icon svg {
        width: 28px;
        height: 28px;
        stroke: currentColor;
    }

    .skip-info-title {
        margin: 0;
        color: var(--skip-ink);
        font-size: 1.28rem;
        font-weight: 950;
        letter-spacing: -0.025em;
    }

    .skip-info-text {
        margin: 6px 0 0;
        color: var(--skip-muted);
        font-size: 0.9rem;
        line-height: 1.6;
        font-weight: 550;
    }

    .skip-doc-list {
        display: grid;
        gap: 10px;
        margin: 20px 0;
        padding: 0;
        list-style: none;
    }

    .skip-doc-list li {
        display: grid;
        grid-template-columns: 26px minmax(0, 1fr);
        gap: 10px;
        align-items: start;
        color: var(--skip-ink);
        font-size: 0.9rem;
        line-height: 1.5;
        font-weight: 700;
    }

    .skip-doc-num {
        width: 26px;
        height: 26px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--skip-ink);
        color: #ffffff;
        font-size: 0.72rem;
        font-weight: 950;
    }

    .skip-note {
        margin-top: 22px;
        padding: 16px;
        border-radius: 16px;
        background: var(--skip-soft);
        border: 1px solid var(--skip-border);
        color: var(--skip-muted-dark);
        font-size: 0.84rem;
        line-height: 1.65;
        font-weight: 600;
    }

    .skip-note strong {
        color: var(--skip-ink);
    }

    .skip-progress-card {
        margin-top: 18px;
        padding: 16px;
        border-radius: 16px;
        background: var(--skip-soft-2);
        border: 1px solid var(--skip-border);
    }

    .skip-progress-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
        color: var(--skip-ink);
        font-size: 0.84rem;
        font-weight: 900;
    }

    .skip-progress-track {
        height: 9px;
        border-radius: 999px;
        background: #eee6d1;
        overflow: hidden;
    }

    .skip-progress-fill {
        width: 0;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, var(--skip-gold), var(--skip-success));
        transition: width 0.2s ease;
    }

    .skip-form-card {
        padding: 26px;
    }

    .skip-field {
        margin-bottom: 24px;
    }

    .skip-label {
        display: block;
        margin-bottom: 8px;
        color: var(--skip-ink);
        font-size: 0.86rem;
        font-weight: 900;
    }

    .skip-control {
        width: 100%;
        min-height: 48px;
        padding: 12px 14px;
        border-radius: 13px;
        border: 1px solid var(--skip-border);
        background: #ffffff;
        color: var(--skip-ink);
        font-size: 0.92rem;
        font-weight: 650;
        outline: none;
        transition: border-color 0.16s ease, box-shadow 0.16s ease;
    }

    .skip-control:focus {
        border-color: #e6b82f;
        box-shadow: 0 0 0 4px rgba(246, 207, 74, 0.18);
    }

    .skip-upload-group {
        margin-top: 22px;
        border-radius: 18px;
        overflow: hidden;
        border: 1px solid var(--skip-border);
        background: #ffffff;
    }

    .skip-upload-header {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 15px 18px;
        background: #f9f7ef;
        border-left: 4px solid var(--skip-gold);
        color: var(--skip-ink);
    }

    .skip-upload-label {
        color: var(--skip-muted-dark);
        font-size: 0.72rem;
        font-weight: 950;
        letter-spacing: 0.10em;
        text-transform: uppercase;
    }

    .skip-upload-header strong {
        font-size: 0.96rem;
        font-weight: 950;
    }

    .skip-upload-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        min-height: 106px;
        padding: 18px;
        background: #ffffff;
    }

    .skip-upload-copy {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        flex: 1 1 auto;
        min-width: 0;
    }

    .skip-upload-number {
        width: 28px;
        height: 28px;
        flex: 0 0 28px;
        border-radius: 999px;
        background: var(--skip-ink);
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.76rem;
        font-weight: 950;
    }

    .skip-upload-title {
        display: block;
        margin-bottom: 4px;
        color: var(--skip-ink);
        font-size: 0.92rem;
        font-weight: 950;
        line-height: 1.35;
    }

    .skip-upload-description {
        display: block;
        max-width: 520px;
        color: var(--skip-muted);
        font-size: 0.78rem;
        line-height: 1.45;
        font-weight: 550;
        margin-bottom: 5px;
    }

    .skip-upload-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--skip-danger);
        font-size: 0.74rem;
        font-weight: 900;
    }

    .skip-upload-status.is-selected {
        color: var(--skip-success);
    }

    .skip-upload-control {
        margin-left: auto;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        flex: 0 0 auto;
        min-width: 350px;
        text-align: right;
    }

    .skip-file-picker {
        position: relative;
        display: inline-flex;
        margin: 0;
        cursor: pointer;
    }

    .skip-file-picker input[type="file"] {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
    }

    .skip-file-picker span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        padding: 10px 16px;
        border-radius: 10px;
        background: var(--skip-ink);
        color: #ffffff;
        border: 1px solid var(--skip-ink);
        font-size: 0.78rem;
        font-weight: 950;
        white-space: nowrap;
        box-shadow: 0 10px 20px rgba(36, 27, 11, 0.14);
        transition: transform 0.16s ease, opacity 0.16s ease;
    }

    .skip-file-picker:hover span {
        transform: translateY(-1px);
        opacity: 0.92;
    }

    .skip-file-name {
        max-width: 150px;
        color: var(--skip-muted);
        font-size: 0.8rem;
        font-weight: 650;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        text-align: left;
    }

    .skip-preview-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 36px;
        padding: 8px 14px;
        border-radius: 10px;
        border: 1px solid #d2c3a2;
        background: #ffffff;
        color: var(--skip-muted-dark);
        font-size: 0.76rem;
        font-weight: 950;
        text-decoration: none;
        cursor: pointer;
        transition: background 0.16s ease, color 0.16s ease, transform 0.16s ease;
        white-space: nowrap;
    }

    .skip-preview-btn:hover {
        background: var(--skip-soft);
        color: var(--skip-ink);
        transform: translateY(-1px);
    }

    .skip-preview-btn.is-hidden {
        display: none !important;
    }

    .skip-submit-wrap {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        margin-top: 26px;
        padding-top: 22px;
        border-top: 1px solid var(--skip-border);
    }

    .skip-submit-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        min-height: 48px;
        padding: 12px 22px;
        border-radius: 13px;
        background: var(--skip-gold);
        color: var(--skip-ink);
        border: 1px solid #e6b82f;
        font-size: 0.9rem;
        font-weight: 950;
        box-shadow: 0 14px 28px rgba(197, 144, 0, 0.22);
        transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
    }

    .skip-submit-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 34px rgba(197, 144, 0, 0.28);
    }

    .skip-submit-btn:disabled {
        opacity: 0.55;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    .skip-submit-note {
        color: var(--skip-muted);
        font-size: 0.82rem;
        line-height: 1.55;
        font-weight: 600;
    }

    @media (max-width: 991.98px) {
        .skip-grid {
            grid-template-columns: 1fr;
        }

        .skip-upload-row {
            align-items: flex-start;
        }

        .skip-upload-control {
            min-width: 310px;
            flex-wrap: wrap;
        }
    }

    @media (max-width: 767.98px) {
        .skip-shell {
            padding: 24px 14px 44px;
        }

        .skip-topbar {
            align-items: flex-start;
            flex-direction: column;
        }

        .skip-upload-row {
            flex-direction: column;
            align-items: stretch;
        }

        .skip-upload-control {
            width: 100%;
            min-width: 0;
            margin-left: 0;
            justify-content: flex-start;
            text-align: left;
        }

        .skip-file-name {
            max-width: calc(100vw - 230px);
        }
    }

    @media (max-width: 420px) {
        .skip-upload-control {
            flex-direction: column;
            align-items: stretch;
        }

        .skip-file-picker,
        .skip-file-picker span,
        .skip-preview-btn {
            width: 100%;
        }

        .skip-file-name {
            max-width: 100%;
            width: 100%;
            text-align: center;
        }

        .skip-submit-btn {
            width: 100%;
        }
    }
</style>

<script>
    document.body.classList.add('skip-compliance-page');
</script>

<div class="skip-shell">

    <div class="skip-topbar">
        <a class="skip-back" href="compliance-gateway.php">
            <span aria-hidden="true">&larr;</span>
            Back
        </a>

        <div>
            <p class="skip-eyebrow">Option B · Fast Verification</p>
            <h1 class="skip-title">Upload Compliance Documents</h1>
            <p class="skip-subtitle">
                Submit the required permits, clearances, and registrations for Administrative Officer verification.
                Once approved, your property can proceed to listing creation.
            </p>
        </div>
    </div>

    <div class="skip-grid">

        <!-- Left guide panel -->
        <aside class="skip-info-card">
            <div class="skip-info-header">
                <div class="skip-info-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <path d="M14 2v6h6"></path>
                        <path d="m9 15 2 2 4-4"></path>
                    </svg>
                </div>

                <div>
                    <h2 class="skip-info-title">
                        <?= count($requiredDocuments) ?> Required Documents
                    </h2>

                    <p class="skip-info-text">
                        These documents will be reviewed before your property listing is unlocked.
                    </p>
                </div>
            </div>

            <ul class="skip-doc-list">
                <?php $docCounter = 0; ?>
                <?php foreach ($requiredDocuments as $document): ?>
                    <?php $docCounter++; ?>
                    <li>
                        <span class="skip-doc-num"><?= $docCounter ?></span>
                        <span><?= e($document['title']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="skip-note">
                <strong>Note:</strong> Upload clear and readable PDF, JPG, JPEG, or PNG files.
                Documents will be reviewed by an Administrative Officer, and you will be notified once verification is complete.
            </div>

            <div class="skip-progress-card">
                <div class="skip-progress-row">
                    <span>Upload Progress</span>
                    <span id="skipProgressText">0 / <?= count($requiredDocuments) ?> selected</span>
                </div>

                <div class="skip-progress-track">
                    <div class="skip-progress-fill" id="skipProgressFill"></div>
                </div>
            </div>
        </aside>

        <!-- Upload form panel -->
        <main>
            <form class="skip-form-card" method="post" enctype="multipart/form-data" id="skip-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="property_id" value="<?= (int)$propertyId ?>">

                <div class="skip-field">
                    <label class="skip-label" for="property_title">
                        Property Title / Name <span class="text-danger">*</span>
                    </label>

                    <input
                        class="skip-control"
                        id="property_title"
                        name="property_title"
                        type="text"
                        value="<?= e($prefilledTitle) ?>"
                        required
                        placeholder="e.g. RNSR Apartment, Buhangin"
                    >
                </div>

                <div class="skip-field">
                    <label class="skip-label" for="property_address">
                        Property Address <span class="text-danger">*</span>
                        <span style="font-weight:600;font-size:.78rem;text-transform:none;letter-spacing:0;color:#76684b;">
                            (must match the address you intend to list)
                        </span>
                    </label>
                    <?php if ($prefilledAddress): ?>
                        <div style="padding:10px 14px;border-radius:10px;background:#fff8dd;border:1px solid #f0dfad;font-size:.88rem;font-weight:700;color:#241b0b;margin-bottom:6px;">
                            <?= e($prefilledAddress) ?>
                        </div>
                        <input type="hidden" name="property_address" value="<?= e($prefilledAddress) ?>">
                    <?php else: ?>
                        <textarea
                            class="skip-control"
                            id="property_address"
                            name="property_address"
                            rows="2"
                            required
                            placeholder="e.g. Lot 12 Block 3, Brgy. Matina Aplaya, Davao City"
                        ></textarea>
                    <?php endif; ?>
                    <p style="margin:6px 0 0;font-size:.76rem;color:#76684b;line-height:1.5;">
                        Compliance verification is tied to this exact address. A verified record for a different address
                        will <strong>not</strong> qualify another property.
                    </p>
                </div>

                <?php $uploadCounter = 0; ?>
                <?php foreach ($requiredDocuments as $key => $document): ?>
                    <?php $uploadCounter++; ?>

                    <div class="skip-upload-group" data-upload-row>
                        <div class="skip-upload-header">
                            <span class="skip-upload-label">Document <?= $uploadCounter ?></span>
                            <strong><?= e($document['title']) ?></strong>
                        </div>

                        <div class="skip-upload-row">
                            <div class="skip-upload-copy">
                                <div class="skip-upload-number"><?= $uploadCounter ?></div>

                                <div>
                                    <strong class="skip-upload-title">
                                        <?= e($document['title']) ?>
                                    </strong>

                                    <span class="skip-upload-description">
                                        <?= e($document['description']) ?>
                                    </span>

                                    <span data-upload-status class="skip-upload-status">
                                        Required
                                    </span>
                                </div>
                            </div>

                            <div class="skip-upload-control">
                                <label class="skip-file-picker">
                                    <input
                                        type="file"
                                        name="<?= e($key) ?>"
                                        data-preview-input
                                        data-document-key="<?= e($key) ?>"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        required
                                    >
                                    <span>Choose file</span>
                                </label>

                                <span class="skip-file-name" data-file-name>
                                    No file chosen
                                </span>

                                <button
                                    type="button"
                                    class="skip-preview-btn is-hidden"
                                    data-preview-btn
                                    data-preview-url=""
                                    data-preview-type=""
                                >
                                    Preview
                                </button>

                                
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="skip-submit-wrap">
                    <button class="skip-submit-btn" id="skipSubmitBtn" type="submit">
                        Submit for Verification
                    </button>

                    <span class="skip-submit-note" id="skipSubmitHint">
                        All <?= count($requiredDocuments) ?> documents are required before submission.
                    </span>
                </div>
            </form>
        </main>

    </div>
</div>

<!-- Preview Modal injected or handled globally by dlp.js -->

<script>
(function () {
    var form = document.getElementById('skip-form');
    var submitBtn = document.getElementById('skipSubmitBtn');
    var progressText = document.getElementById('skipProgressText');
    var progressFill = document.getElementById('skipProgressFill');
    var submitHint = document.getElementById('skipSubmitHint');

    var inputs = Array.prototype.slice.call(document.querySelectorAll('[data-preview-input]'));
    var total = inputs.length;

    function getSelectedCount() {
        return inputs.filter(function (input) {
            return input.files && input.files.length > 0;
        }).length;
    }

    function updateProgress() {
        var selected = getSelectedCount();
        var pct = total > 0 ? Math.round((selected / total) * 100) : 0;

        if (progressText) {
            progressText.textContent = selected + ' / ' + total + ' selected';
        }

        if (progressFill) {
            progressFill.style.width = pct + '%';
        }

        if (submitHint) {
            if (selected < total) {
                submitHint.textContent = (total - selected) + ' document(s) still required before submission.';
            } else {
                submitHint.textContent = 'All documents selected. Ready to submit for verification.';
            }
        }
    }

    inputs.forEach(function (input) {
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            var row = input.closest('[data-upload-row]');

            if (!row) {
                updateProgress();
                return;
            }

            var statusEl = row.querySelector('[data-upload-status]');
            var fileNameEl = row.querySelector('[data-file-name]');
            var previewBtn = row.querySelector('[data-preview-btn]');
            var titleEl = row.querySelector('.skip-upload-title');

            if (!file) {
                if (fileNameEl) {
                    fileNameEl.textContent = 'No file chosen';
                }

                if (statusEl) {
                    statusEl.textContent = 'Required';
                    statusEl.classList.remove('is-selected');
                }

                if (previewBtn) {
                    previewBtn.classList.add('is-hidden');
                    previewBtn.dataset.previewUrl = '';
                    previewBtn.dataset.previewType = '';
                }

                updateProgress();
                return;
            }

            if (fileNameEl) {
                fileNameEl.textContent = file.name;
            }

            if (statusEl) {
                statusEl.textContent = 'Selected';
                statusEl.classList.add('is-selected');
            }

            if (previewBtn) {
                previewBtn.dataset.previewUrl = URL.createObjectURL(file);
                previewBtn.dataset.previewType = file.name.split('.').pop().toLowerCase();
                previewBtn.dataset.previewTitle = titleEl ? titleEl.textContent.trim() : 'Document Preview';
                previewBtn.classList.remove('is-hidden');
            }

            updateProgress();
        });
    });

    if (form) {
        form.addEventListener('submit', function (event) {
            var selected = getSelectedCount();

            if (selected < total) {
                event.preventDefault();
                alert('Please upload all ' + total + ' required compliance documents before submitting.');
                updateProgress();
            }
        });
    }

    updateProgress();
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>