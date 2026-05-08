<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

$errors  = [];
$post    = [];

// ── Edit mode: load existing application ─────────────────────────────────────
$editId      = (int)($_GET['edit'] ?? 0);
$editApp     = null;
$isEditMode  = false;

if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM applications WHERE id = ? AND landlord_id = ?');
    $editStmt->execute([$editId, (int)$user['id']]);
    $editApp = $editStmt->fetch();
    if ($editApp) {
        $isEditMode = true;
        // Pre-populate $post from DB so the form renders with existing values
        if (empty($_POST)) {
            $post = (array)$editApp;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = $_POST;

    // ── Required field validation ────────────────────────────────────────────
    $requiredFields = [
        'account_name'      => 'Name of Applicant',
        'account_address'   => 'Address of Applicant',
        'property_title'    => 'Project / Property Title',
        'property_address'  => 'Project Location',
        'type_of_project'   => 'Type of Project',
        'lot_area'          => 'Lot Area',
        'right_over_land'   => 'Right over Land',
        'existing_land_use' => 'Existing Land Use',
    ];
    foreach ($requiredFields as $field => $label) {
        if (trim($post[$field] ?? '') === '') {
            $errors[$field] = $label . ' is required.';
        }
    }
    if (!in_array($post['nature_of_application'] ?? '', ['new_development','improvement','others'], true)) {
        $errors['nature_of_application'] = 'Nature of Application is required.';
    }
    if (($post['nature_of_application'] ?? '') === 'others' && trim($post['nature_of_application_other'] ?? '') === '') {
        $errors['nature_of_application_other'] = 'Please specify the nature of application.';
    }
    if (($post['existing_land_use'] ?? '') === 'others' && trim($post['existing_land_use_other'] ?? '') === '') {
        $errors['existing_land_use_other'] = 'Please specify the existing land use.';
    }
    if (empty($post['sworn_statement'])) {
        $errors['sworn_statement'] = 'You must confirm the sworn statement to proceed.';
    }
    if (isset($post['lot_area']) && $post['lot_area'] !== '' && (!is_numeric($post['lot_area']) || (float)$post['lot_area'] <= 0)) {
        $errors['lot_area'] = 'Lot Area must be a positive number.';
    }
    if (isset($post['building_area']) && $post['building_area'] !== '' && (!is_numeric($post['building_area']) || (float)$post['building_area'] < 0)) {
        $errors['building_area'] = 'Building Area must be a valid number.';
    }
    if (isset($post['project_cost']) && $post['project_cost'] !== '' && (!is_numeric($post['project_cost']) || (float)$post['project_cost'] < 0)) {
        $errors['project_cost'] = 'Project Cost must be a valid number.';
    }

    if (empty($errors)) {
        if ($isEditMode && $editApp) {
            // ── Update existing application ──────────────────────────────────
            $natureOther  = ($post['nature_of_application'] ?? '') === 'others'
                            ? trim($post['nature_of_application_other'] ?? '') : null;
            $landUseOther = ($post['existing_land_use'] ?? '') === 'others'
                            ? trim($post['existing_land_use_other'] ?? '') : null;

            db()->prepare(
                'UPDATE applications SET
                    account_name = ?, account_address = ?,
                    corporation_name = ?, representative_name = ?,
                    property_title = ?, property_address = ?, coordinates = ?,
                    type_of_project = ?, lot_area = ?, building_area = ?, project_cost = ?,
                    nature_of_application = ?, nature_of_application_other = ?,
                    right_over_land = ?,
                    existing_land_use = ?, existing_land_use_other = ?,
                    sworn_statement = ?
                 WHERE id = ? AND landlord_id = ?'
            )->execute([
                trim($post['account_name']),
                trim($post['account_address']),
                trim($post['corporation_name']    ?? '') ?: null,
                trim($post['representative_name'] ?? '') ?: null,
                trim($post['property_title']),
                trim($post['property_address']),
                trim($post['coordinates'] ?? '') ?: null,
                trim($post['type_of_project']    ?? '') ?: null,
                is_numeric($post['lot_area']      ?? '') ? (float)$post['lot_area']      : null,
                is_numeric($post['building_area'] ?? '') ? (float)$post['building_area'] : null,
                is_numeric($post['project_cost']  ?? '') ? (float)$post['project_cost']  : null,
                in_array($post['nature_of_application'] ?? '', ['new_development','improvement','others'], true)
                    ? $post['nature_of_application'] : null,
                $natureOther,
                in_array($post['right_over_land'] ?? '', ['owner','lessee'], true)
                    ? $post['right_over_land'] : null,
                in_array($post['existing_land_use'] ?? '', ['residential','commercial','industrial','institutional','agricultural','others'], true)
                    ? $post['existing_land_use'] : null,
                $landUseOther,
                !empty($post['sworn_statement']) ? 1 : 0,
                $editId,
                (int)$user['id'],
            ]);

            // Re-generate vicinity map PDF if new coordinates were pinned
            $newLat = is_numeric($post['latitude']  ?? '') ? (float)$post['latitude']  : null;
            $newLng = is_numeric($post['longitude'] ?? '') ? (float)$post['longitude'] : null;
            if ($newLat !== null && $newLng !== null) {
                try {
                    $pdfPath = generate_vicinity_map_pdf(
                        lat:            $newLat,
                        lng:            $newLng,
                        applicantName:  trim($post['account_name']),
                        projectName:    trim($post['property_title']),
                        registryNumber: $editApp['registry_number']
                    );
                    $pdo = db();
                    $pdo->prepare('UPDATE applications SET vicinity_map_pdf_path = ? WHERE id = ?')
                        ->execute([$pdfPath, $editId]);
                    $absPath  = __DIR__ . '/../../' . $pdfPath;
                    $pdfBytes = file_get_contents($absPath);
                    if ($pdfBytes !== false) {
                        $encName = encrypt_sensitive('vicinity_map_generated.pdf');
                        $pdo->prepare(
                            'UPDATE requirement_documents
                             SET file_data = ?, file_mime = "application/pdf", file_path = NULL,
                                 original_name_enc = ?, original_name_nonce = ?, uploaded_at = NOW()
                             WHERE application_id = ? AND requirement_key = "vicinity_map"'
                        )->execute([
                            $pdfBytes,
                            $encName['ciphertext'],
                            $encName['nonce'],
                            $editId,
                        ]);
                    }
                } catch (Throwable $e) {
                    audit_log((int)$user['id'], 'VICINITY_MAP_PDF_FAILED', 'applications', $editId,
                        ['error' => $e->getMessage()]);
                }
            }

            audit_log((int)$user['id'], 'APPLICATION_UPDATED', 'applications', $editId);
            $_SESSION['flash_success'] = 'Application updated successfully.';
            redirect('landlord/requirements-upload.php?id=' . $editId);
        } else {
            // ── Create new application ───────────────────────────────────────
            $applicationId = create_application((int)$user['id'], $post);
            redirect('landlord/requirements-upload.php?id=' . $applicationId);
        }
    }
}

// Helper: repopulate field value safely
$v = fn(string $key, string $default = '') => e($post[$key] ?? $default);

require __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Application form page ── */
.af-section-title {
    font-size: .7rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--cpdo-blue);
    margin: 0 0 14px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--cpdo-light);
}
.af-app-no {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: var(--cpdo-light);
    border: 1px solid var(--cpdo-border);
    border-radius: 6px;
    margin-bottom: 20px;
}
.af-app-no-label {
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--cpdo-blue);
    white-space: nowrap;
}
.af-app-no-value {
    font-size: .85rem;
    font-weight: 700;
    color: var(--cpdo-muted);
    font-style: italic;
}

/* Checkbox / radio group */
.af-check-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 20px;
    margin-top: 6px;
}
.af-check-item {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: .85rem;
    color: #33485f;
    cursor: pointer;
}
.af-check-item input[type="radio"],
.af-check-item input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--cpdo-blue);
    cursor: pointer;
    flex-shrink: 0;
}

/* "Others — specify" inline row */
.af-others-row {
    display: none;
    margin-top: 8px;
}
.af-others-row.is-visible { display: flex; align-items: center; gap: 8px; }
.af-others-row label {
    font-size: .78rem;
    font-weight: 700;
    color: #62748a;
    white-space: nowrap;
}

/* Sworn statement block */
.af-sworn-block {
    background: #f8fbff;
    border: 1.5px solid var(--cpdo-border);
    border-left: 4px solid var(--cpdo-blue);
    border-radius: 8px;
    padding: 16px 18px;
    margin-top: 8px;
}
.af-sworn-block.is-error {
    border-color: var(--cpdo-red);
    border-left-color: var(--cpdo-red);
    background: #fff5f5;
}
.af-sworn-text {
    font-size: .82rem;
    color: #3a5068;
    line-height: 1.6;
    margin-bottom: 10px;
}
.af-sworn-check {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: .85rem;
    font-weight: 600;
    color: var(--cpdo-navy);
    cursor: pointer;
}
.af-sworn-check input {
    width: 17px;
    height: 17px;
    accent-color: var(--cpdo-blue);
    flex-shrink: 0;
    margin-top: 2px;
}

/* Requirements checklist (left panel) */
.process-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--cpdo-blue);
    color: #fff;
    font-size: 13px;
    font-weight: 900;
    margin-right: 8px;
    flex-shrink: 0;
}
.req-office-block { margin-bottom: 16px; }
.req-office-label {
    font-size: .7rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--cpdo-blue);
    margin-bottom: 4px;
}
.req-list { list-style: none; padding: 0; margin: 0; }
.req-list li {
    display: flex;
    gap: 8px;
    font-size: var(--text-sm);
    color: #33485f;
    padding: 4px 0;
    border-bottom: 1px solid #f0f4f8;
    align-items: flex-start;
}
.req-num {
    flex-shrink: 0;
    width: 18px;
    font-weight: 700;
    color: var(--cpdo-blue);
    padding-top: 1px;
}
.req-details-dropdown { margin-top: 4px; }
.req-details-summary {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: .7rem;
    font-weight: 700;
    color: var(--cpdo-blue);
    cursor: pointer;
    user-select: none;
    list-style: none;
    padding: 2px 0;
    letter-spacing: .02em;
}
.req-details-summary::-webkit-details-marker,
.req-details-summary::marker { display: none; }
.req-details-summary:hover { color: var(--cpdo-blue-dark); }
.req-details-body {
    margin-top: 6px;
    padding: 8px 12px;
    background: var(--cpdo-light);
    border-left: 3px solid var(--cpdo-blue);
    border-radius: 0 6px 6px 0;
    font-size: .76rem;
    color: #3a5068;
    line-height: 1.55;
}

/* Validation error */
.af-field-error {
    font-size: .75rem;
    color: var(--cpdo-red);
    margin-top: 4px;
    font-weight: 600;
}
.is-invalid-field .form-control,
.is-invalid-field .form-select {
    border-color: var(--cpdo-red);
}

/* ── Vicinity Map glassmorphism card ── */
.vm-glass-card {
    background: rgba(255, 255, 255, .55);
    border: 1px solid rgba(47, 128, 199, .20);
    backdrop-filter: blur(12px) saturate(150%);
    -webkit-backdrop-filter: blur(12px) saturate(150%);
    border-radius: 14px;
    overflow: hidden;
    box-shadow:
        0 4px 20px rgba(11, 42, 74, .08),
        inset 0 1px 0 rgba(255, 255, 255, .7);
}
.vm-glass-header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 18px 12px;
    background: rgba(234, 244, 255, .6);
    border-bottom: 1px solid rgba(47, 128, 199, .12);
}
.vm-glass-header-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--cpdo-blue), var(--cpdo-blue-dark));
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.vm-glass-title {
    font-size: .88rem;
    font-weight: 800;
    color: var(--cpdo-navy);
}
.vm-glass-sub {
    font-size: .75rem;
    color: var(--cpdo-muted);
    line-height: 1.4;
}
.vm-map-container {
    width: 100%;
    height: 380px;
    background: #e8eef5;
    position: relative;
}
/* Placeholder shown before Maps API loads */
.vm-map-container::before {
    content: 'Loading satellite map…';
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .82rem;
    color: var(--cpdo-muted);
    font-weight: 600;
    letter-spacing: .03em;
    pointer-events: none;
}
.vm-coord-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    background: rgba(234, 244, 255, .5);
    border-top: 1px solid rgba(47, 128, 199, .10);
    flex-wrap: wrap;
}
.vm-coord-chip {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    background: rgba(255, 255, 255, .7);
    border: 1px solid rgba(47, 128, 199, .18);
    border-radius: 999px;
    font-size: .75rem;
}
.vm-coord-label {
    font-weight: 800;
    color: var(--cpdo-blue);
    text-transform: uppercase;
    letter-spacing: .06em;
    font-size: .65rem;
}
.vm-coord-value {
    font-weight: 700;
    color: var(--cpdo-navy);
    font-variant-numeric: tabular-nums;
    min-width: 80px;
}
.vm-coord-status {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-left: auto;
    font-size: .75rem;
    color: var(--cpdo-muted);
    font-weight: 600;
}
.vm-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.vm-status-dot--idle    { background: #c5d3df; }
.vm-status-dot--active  { background: var(--cpdo-green); box-shadow: 0 0 0 3px rgba(30,158,87,.2); }
.vm-status-dot--moving  { background: var(--cpdo-amber); }

/* ── Vicinity Map search bar ── */
.vm-search-wrap {
    position: relative;
    padding: 10px 14px;
    background: rgba(234, 244, 255, .5);
    border-bottom: 1px solid rgba(47, 128, 199, .10);
}
.vm-search-icon {
    position: absolute;
    left: 26px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--cpdo-muted);
    pointer-events: none;
}
.vm-search-input {
    padding-left: 34px !important;
    background: rgba(255, 255, 255, .85) !important;
    border-color: rgba(47, 128, 199, .25) !important;
    border-radius: 8px !important;
    font-size: .85rem !important;
    height: 38px !important;
}
.vm-search-input:focus {
    background: #fff !important;
    border-color: var(--cpdo-blue) !important;
    box-shadow: 0 0 0 3px rgba(47, 128, 199, .15) !important;
}
/* Google Places autocomplete dropdown — keep it above the map */
.pac-container { z-index: 9999 !important; }
</style>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="<?= $isEditMode ? 'requirements-upload.php?id=' . $editId : 'dashboard.php' ?>">
        <span aria-hidden="true">&larr;</span> <?= $isEditMode ? 'Back to Upload' : 'Dashboard' ?>
    </a>
    <div>
        <p class="eyebrow mb-0">Application Form</p>
        <h1 class="h3 mb-0">
            <?= $isEditMode ? 'Edit Application' : 'Application for Locational Clearance' ?>
        </h1>
        <?php if ($isEditMode && $editApp): ?>
            <p class="text-secondary small mb-0"><?= e($editApp['registry_number']) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-4" role="alert">
    <strong>Please correct the errors below</strong> before submitting.
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Left: Requirements checklist ──────────────────────────────────── -->
    <div class="col-lg-4">
        <div class="gov-card p-4" style="position:sticky;top:20px;">
            <h2 class="h6 mb-3 d-flex align-items-center">
                <span class="process-badge">18</span>
                Mandatory Requirements
            </h2>
            <p class="small text-secondary mb-3">
                After submitting this form, upload all 18 documents on the next page.
            </p>
            <?php $num = 0; foreach (effective_requirements() as $office => $documents): ?>
                <div class="req-office-block">
                    <p class="req-office-label"><?= e($office) ?></p>
                    <ul class="req-list">
                        <?php foreach ($documents as $key => $doc): $num++; ?>
                            <li>
                                <span class="req-num"><?= $num ?></span>
                                <span>
                                    <?= e($doc['title']) ?>
                                    <?php if (!empty($doc['details'])): ?>
                                    <details class="req-details-dropdown">
                                        <summary class="req-details-summary">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/></svg>
                                            What to prepare
                                        </summary>
                                        <div class="req-details-body"><?= nl2br(e($doc['details'])) ?></div>
                                    </details>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Right: Locational Clearance form ──────────────────────────────── -->
    <div class="col-lg-8">
        <form class="gov-card p-4" method="post" novalidate id="lc-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <!-- System-generated application number -->
            <div class="af-app-no">
                <span class="af-app-no-label">Application No.</span>
                <span class="af-app-no-value">
                    <?= $isEditMode && $editApp ? e($editApp['registry_number']) : 'Will be assigned upon submission' ?>
                </span>
            </div>

            <!-- ── Section 1: Applicant Details ─────────────────────────── -->
            <p class="af-section-title">Applicant Details</p>

            <div class="row g-3 mb-3">
                <div class="col-12 <?= isset($errors['account_name']) ? 'is-invalid-field' : '' ?>">
                    <label class="form-label" for="account_name">
                        Name of Applicant <span class="text-danger">*</span>
                    </label>
                    <input class="form-control" type="text" id="account_name" name="account_name"
                           value="<?= $v('account_name', user_full_name($user)) ?>" required
                           placeholder="Full legal name">
                    <?php if (isset($errors['account_name'])): ?>
                        <div class="af-field-error"><?= e($errors['account_name']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-12 <?= isset($errors['account_address']) ? 'is-invalid-field' : '' ?>">
                    <label class="form-label" for="account_address">
                        Address of Applicant <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control" id="account_address" name="account_address"
                              rows="2" required placeholder="Complete address"><?= $v('account_address') ?></textarea>
                    <?php if (isset($errors['account_address'])): ?>
                        <div class="af-field-error"><?= e($errors['account_address']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="corporation_name">
                        Name of Corporation
                        <span class="text-secondary fw-normal small">(optional)</span>
                    </label>
                    <input class="form-control" type="text" id="corporation_name" name="corporation_name"
                           value="<?= $v('corporation_name') ?>" placeholder="If applicable">
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="representative_name">
                        Representative of the Owner
                        <span class="text-secondary fw-normal small">(optional)</span>
                    </label>
                    <input class="form-control" type="text" id="representative_name" name="representative_name"
                           value="<?= $v('representative_name') ?>" placeholder="If applicable">
                </div>
            </div>

            <!-- ── Section 2: Project Details ───────────────────────────── -->
            <p class="af-section-title">Project Details</p>

            <div class="row g-3 mb-3">
                <div class="col-12 <?= isset($errors['property_title']) ? 'is-invalid-field' : '' ?>">
                    <label class="form-label" for="property_title">
                        Project / Property Title <span class="text-danger">*</span>
                    </label>
                    <input class="form-control" type="text" id="property_title" name="property_title"
                           value="<?= $v('property_title') ?>" required placeholder="Name of the project or property">
                    <?php if (isset($errors['property_title'])): ?>
                        <div class="af-field-error"><?= e($errors['property_title']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-12 <?= isset($errors['type_of_project']) ? 'is-invalid-field' : '' ?>">
                    <label class="form-label" for="type_of_project">
                        Type of Project <span class="text-danger">*</span>
                    </label>
                    <input class="form-control" type="text" id="type_of_project" name="type_of_project"
                           value="<?= $v('type_of_project') ?>" required
                           placeholder="e.g. Residential Subdivision, Commercial Building">
                    <?php if (isset($errors['type_of_project'])): ?>
                        <div class="af-field-error"><?= e($errors['type_of_project']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-12 <?= isset($errors['property_address']) ? 'is-invalid-field' : '' ?>">
                    <label class="form-label" for="property_address">
                        Project Location <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control" id="property_address" name="property_address"
                              rows="2" required placeholder="Complete address of the project site"><?= $v('property_address') ?></textarea>
                    <?php if (isset($errors['property_address'])): ?>
                        <div class="af-field-error"><?= e($errors['property_address']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-4 <?= isset($errors['lot_area']) ? 'is-invalid-field' : '' ?>">
                    <label class="form-label" for="lot_area">
                        Lot Area (sqm) <span class="text-danger">*</span>
                    </label>
                    <input class="form-control" type="number" id="lot_area" name="lot_area"
                           value="<?= $v('lot_area') ?>" min="0" step="0.01" required placeholder="0.00">
                    <?php if (isset($errors['lot_area'])): ?>
                        <div class="af-field-error"><?= e($errors['lot_area']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-4 <?= isset($errors['building_area']) ? 'is-invalid-field' : '' ?>">
                    <label class="form-label" for="building_area">
                        Building Area (sqm)
                        <span class="text-secondary fw-normal small">(optional)</span>
                    </label>
                    <input class="form-control" type="number" id="building_area" name="building_area"
                           value="<?= $v('building_area') ?>" min="0" step="0.01" placeholder="0.00">
                    <?php if (isset($errors['building_area'])): ?>
                        <div class="af-field-error"><?= e($errors['building_area']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-4 <?= isset($errors['project_cost']) ? 'is-invalid-field' : '' ?>">
                    <label class="form-label" for="project_cost">
                        Project Cost (PHP)
                        <span class="text-secondary fw-normal small">(optional)</span>
                    </label>
                    <input class="form-control" type="number" id="project_cost" name="project_cost"
                           value="<?= $v('project_cost') ?>" min="0" step="0.01" placeholder="0.00">
                    <?php if (isset($errors['project_cost'])): ?>
                        <div class="af-field-error"><?= e($errors['project_cost']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="coordinates">
                        Coordinates
                        <span class="text-secondary fw-normal small">(optional)</span>
                    </label>
                    <input class="form-control" type="text" id="coordinates" name="coordinates"
                           value="<?= $v('coordinates') ?>" placeholder="e.g. 7.0731, 125.6128">
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="land_title_reference">
                        Land Title Reference
                        <span class="text-secondary fw-normal small">(optional)</span>
                    </label>
                    <input class="form-control" type="text" id="land_title_reference"
                           name="land_title_reference" data-sensitive
                           value="<?= $v('land_title_reference') ?>" placeholder="TCT / OCT number">
                    <div class="form-text">Stored encrypted.</div>
                </div>
            </div>

            <!-- ── Section 3: Vicinity Map ──────────────────────────────── -->
            <p class="af-section-title">Vicinity Map — Pin Your Property Location</p>

            <div class="vm-glass-card mb-3">
                <div class="vm-glass-header">
                    <div class="vm-glass-header-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/></svg>
                    </div>
                    <div>
                        <p class="vm-glass-title mb-0">Satellite Pin Drop</p>
                        <p class="vm-glass-sub mb-0">Drag the red marker to your exact property location. Coordinates are captured automatically.</p>
                    </div>
                </div>

                <!-- Location search bar -->
                <div class="vm-search-wrap">
                    <svg class="vm-search-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.099zm-5.242 1.656a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/></svg>
                    <input
                        id="vm-search-input"
                        class="form-control vm-search-input"
                        type="text"
                        placeholder="Search for a street, landmark, or address…"
                        autocomplete="off"
                        aria-label="Search location"
                    >
                </div>

                <!-- Map container -->
                <div id="vicinity-map" class="vm-map-container" aria-label="Satellite map — drag the marker to your property location" role="application"></div>

                <!-- Coordinate readout -->
                <div class="vm-coord-row">
                    <div class="vm-coord-chip">
                        <span class="vm-coord-label">Latitude</span>
                        <span class="vm-coord-value" id="lat-display">—</span>
                    </div>
                    <div class="vm-coord-chip">
                        <span class="vm-coord-label">Longitude</span>
                        <span class="vm-coord-value" id="lng-display">—</span>
                    </div>
                    <div class="vm-coord-status" id="vm-status">
                        <span class="vm-status-dot vm-status-dot--idle" id="vm-status-dot"></span>
                        <span id="vm-status-text">Drop the pin to capture coordinates</span>
                    </div>
                </div>

                <!-- Hidden inputs submitted with the form -->
                <input type="hidden" name="latitude"    id="input-lat"    value="<?= $v('latitude') ?>">
                <input type="hidden" name="longitude"   id="input-lng"    value="<?= $v('longitude') ?>">
                <input type="hidden" name="coordinates" id="input-coords" value="<?= $v('coordinates') ?>">
            </div>

            <!-- ── Section 4: Land Use & Classification ──────────────────── -->
            <p class="af-section-title">Land Use &amp; Classification</p>

            <!-- Nature of Application -->
            <div class="mb-3 <?= isset($errors['nature_of_application']) ? 'is-invalid-field' : '' ?>">
                <label class="form-label d-block">
                    Nature of Application <span class="text-danger">*</span>
                </label>
                <div class="af-check-group">
                    <?php
                    $noa = $post['nature_of_application'] ?? '';
                    foreach (['new_development' => 'New Development', 'improvement' => 'Improvement', 'others' => 'Others'] as $val => $label):
                    ?>
                    <label class="af-check-item">
                        <input type="radio" name="nature_of_application" value="<?= $val ?>"
                               <?= $noa === $val ? 'checked' : '' ?>
                               data-toggle-other="noa-other">
                        <?= $label ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="af-others-row <?= $noa === 'others' ? 'is-visible' : '' ?>" id="noa-other">
                    <label for="nature_of_application_other">Specify:</label>
                    <input class="form-control form-control-sm" type="text"
                           id="nature_of_application_other" name="nature_of_application_other"
                           value="<?= $v('nature_of_application_other') ?>"
                           placeholder="Describe the nature of application">
                </div>
                <?php if (isset($errors['nature_of_application'])): ?>
                    <div class="af-field-error"><?= e($errors['nature_of_application']) ?></div>
                <?php endif; ?>
                <?php if (isset($errors['nature_of_application_other'])): ?>
                    <div class="af-field-error"><?= e($errors['nature_of_application_other']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Right over Land -->
            <div class="mb-3 <?= isset($errors['right_over_land']) ? 'is-invalid-field' : '' ?>">
                <label class="form-label d-block">
                    Right over Land <span class="text-danger">*</span>
                </label>
                <div class="af-check-group">
                    <?php $rol = $post['right_over_land'] ?? ''; ?>
                    <label class="af-check-item">
                        <input type="radio" name="right_over_land" value="owner"
                               <?= $rol === 'owner' ? 'checked' : '' ?>>
                        Owner
                    </label>
                    <label class="af-check-item">
                        <input type="radio" name="right_over_land" value="lessee"
                               <?= $rol === 'lessee' ? 'checked' : '' ?>>
                        Lessee
                    </label>
                </div>
                <?php if (isset($errors['right_over_land'])): ?>
                    <div class="af-field-error"><?= e($errors['right_over_land']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Existing Land Use -->
            <div class="mb-4 <?= isset($errors['existing_land_use']) ? 'is-invalid-field' : '' ?>">
                <label class="form-label d-block">
                    Existing Land Use of Project Site <span class="text-danger">*</span>
                </label>
                <div class="af-check-group">
                    <?php
                    $elu = $post['existing_land_use'] ?? '';
                    $landUseOptions = [
                        'residential'   => 'Residential',
                        'commercial'    => 'Commercial',
                        'industrial'    => 'Industrial',
                        'institutional' => 'Institutional',
                        'agricultural'  => 'Agricultural',
                        'others'        => 'Others',
                    ];
                    foreach ($landUseOptions as $val => $label):
                    ?>
                    <label class="af-check-item">
                        <input type="radio" name="existing_land_use" value="<?= $val ?>"
                               <?= $elu === $val ? 'checked' : '' ?>
                               data-toggle-other="elu-other">
                        <?= $label ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="af-others-row <?= $elu === 'others' ? 'is-visible' : '' ?>" id="elu-other">
                    <label for="existing_land_use_other">Specify:</label>
                    <input class="form-control form-control-sm" type="text"
                           id="existing_land_use_other" name="existing_land_use_other"
                           value="<?= $v('existing_land_use_other') ?>"
                           placeholder="Describe the existing land use">
                </div>
                <?php if (isset($errors['existing_land_use'])): ?>
                    <div class="af-field-error"><?= e($errors['existing_land_use']) ?></div>
                <?php endif; ?>
                <?php if (isset($errors['existing_land_use_other'])): ?>
                    <div class="af-field-error"><?= e($errors['existing_land_use_other']) ?></div>
                <?php endif; ?>
            </div>

            <!-- ── Section 5: Digital Sworn Statement ────────────────────── -->
            <p class="af-section-title">Digital Sworn Statement</p>

            <div class="af-sworn-block <?= isset($errors['sworn_statement']) ? 'is-error' : '' ?>">
                <p class="af-sworn-text">
                    By checking the box below, the applicant affirms under oath that all information
                    provided in this Application for Locational Clearance is true, correct, and complete
                    to the best of their knowledge. Any false declaration shall be subject to the
                    penalties prescribed by law.
                </p>
                <label class="af-sworn-check">
                    <input type="checkbox" name="sworn_statement" value="1"
                           <?= !empty($post['sworn_statement']) ? 'checked' : '' ?>>
                    <span>
                        I hereby swear that all details provided are true and correct to the best of my knowledge.
                    </span>
                </label>
                <?php if (isset($errors['sworn_statement'])): ?>
                    <div class="af-field-error mt-2"><?= e($errors['sworn_statement']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mt-4">
                <?php if ($isEditMode): ?>
                    <input type="hidden" name="edit_id" value="<?= $editId ?>">
                <?php endif; ?>
                <button class="btn btn-primary w-100" type="submit">
                    <?= $isEditMode ? 'Save Changes →' : 'Submit Application & Continue to Document Upload →' ?>
                </button>
                <p class="text-secondary small text-center mt-2 mb-0">
                    <?= $isEditMode
                        ? 'Changes will be saved and you will return to the document upload page.'
                        : 'An Application Number will be generated automatically upon submission.' ?>
                </p>
            </div>

        </form>
    </div><!-- /col-lg-8 -->

</div><!-- /row -->

<script>
// Show/hide "Others — specify" fields
(function () {
    function bindOthers(radioName, targetId) {
        var radios = document.querySelectorAll('input[name="' + radioName + '"]');
        var target = document.getElementById(targetId);
        if (!target) return;
        radios.forEach(function (r) {
            r.addEventListener('change', function () {
                target.classList.toggle('is-visible', r.value === 'others' && r.checked);
                if (r.value !== 'others') {
                    var inp = target.querySelector('input');
                    if (inp) inp.value = '';
                }
            });
        });
    }
    bindOthers('nature_of_application', 'noa-other');
    bindOthers('existing_land_use',     'elu-other');
}());
</script>

<?php
// Output the Google Maps API key safely for JS
$mapsKey = e($config['google']['maps_api_key'] ?? '');
?>
<?php if ($mapsKey !== ''): ?>
<script>
// ── Vicinity Map initialisation ──────────────────────────────────────────────
function initVicinityMap() {
    var DAVAO   = { lat: 7.1907, lng: 125.4553 };
    var RADIUS  = 200; // metres — strict requirement

    // Restore previously pinned position (edit mode or form re-submission)
    var savedLat = parseFloat(document.getElementById('input-lat').value) || DAVAO.lat;
    var savedLng = parseFloat(document.getElementById('input-lng').value) || DAVAO.lng;
    var hasSaved = document.getElementById('input-lat').value !== '';

    var map = new google.maps.Map(document.getElementById('vicinity-map'), {
        center:    { lat: savedLat, lng: savedLng },
        zoom:      17,
        mapTypeId: google.maps.MapTypeId.SATELLITE,
        tilt:      0,
        mapTypeControl:    false,
        streetViewControl: false,
        fullscreenControl: true,
        zoomControl:       true,
    });

    // ── 200-metre radius circle ──────────────────────────────────────────────
    var circle = new google.maps.Circle({
        map:           map,
        center:        { lat: savedLat, lng: savedLng },
        radius:        RADIUS,
        fillColor:     '#FF0000',
        fillOpacity:   0.15,
        strokeColor:   '#FF0000',
        strokeOpacity: 0.6,
        strokeWeight:  2,
        clickable:     false,
    });

    // ── Draggable red marker ─────────────────────────────────────────────────
    var marker = new google.maps.Marker({
        position:  { lat: savedLat, lng: savedLng },
        map:       map,
        draggable: true,
        title:     'Drag to your property location',
        animation: google.maps.Animation.DROP,
        icon: {
            path:         google.maps.SymbolPath.CIRCLE,
            scale:        10,
            fillColor:    '#e04040',
            fillOpacity:  1,
            strokeColor:  '#fff',
            strokeWeight: 2,
        },
    });

    // ── Shared update function ───────────────────────────────────────────────
    function moveTo(latLng) {
        var lat = latLng.lat().toFixed(6);
        var lng = latLng.lng().toFixed(6);

        marker.setPosition(latLng);
        circle.setCenter(latLng);

        document.getElementById('input-lat').value    = lat;
        document.getElementById('input-lng').value    = lng;
        document.getElementById('input-coords').value = lat + ', ' + lng;
        document.getElementById('lat-display').textContent = lat;
        document.getElementById('lng-display').textContent = lng;

        // Snap viewport so the full 200 m radius is visible
        map.fitBounds(circle.getBounds());
    }

    function setStatus(state) {
        var dot  = document.getElementById('vm-status-dot');
        var text = document.getElementById('vm-status-text');
        dot.className = 'vm-status-dot vm-status-dot--' + state;
        if (state === 'active') text.textContent = 'Coordinates captured';
        if (state === 'moving') text.textContent = 'Updating…';
        if (state === 'idle')   text.textContent = 'Drop the pin to capture coordinates';
    }

    // Restore saved position on load
    if (hasSaved) {
        moveTo(new google.maps.LatLng(savedLat, savedLng));
        setStatus('active');
    }

    // Drag events
    marker.addListener('dragstart', function () { setStatus('moving'); });
    marker.addListener('dragend',   function (e) { moveTo(e.latLng); setStatus('active'); });

    // Click-to-move
    map.addListener('click', function (e) {
        moveTo(e.latLng);
        setStatus('active');
    });

    // ── Places Autocomplete search bar ───────────────────────────────────────
    var searchInput = document.getElementById('vm-search-input');
    if (searchInput && google.maps.places) {
        var autocomplete = new google.maps.places.Autocomplete(searchInput, {
            fields: ['geometry', 'name'],
        });
        autocomplete.bindTo('bounds', map);

        autocomplete.addListener('place_changed', function () {
            var place = autocomplete.getPlace();
            if (!place.geometry || !place.geometry.location) return;
            moveTo(place.geometry.location);
            setStatus('active');
        });
    }
}
</script>
<script
    src="https://maps.googleapis.com/maps/api/js?key=<?= $mapsKey ?>&libraries=places&callback=initVicinityMap&loading=async"
    async defer></script>
<?php else: ?>
<script>
// Maps API key not configured — show a notice inside the map container
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('vicinity-map');
    if (el) {
        el.style.display = 'flex';
        el.style.alignItems = 'center';
        el.style.justifyContent = 'center';
        el.style.flexDirection = 'column';
        el.style.gap = '8px';
        el.innerHTML =
            '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#9aaabd" viewBox="0 0 16 16"><path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/></svg>' +
            '<p style="font-size:.82rem;color:#62748a;font-weight:600;margin:0;text-align:center;padding:0 20px;">' +
            'Map unavailable — add <code>maps_api_key</code> to <code>config/env.php</code> to enable the satellite pin drop.' +
            '</p>';
    }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
