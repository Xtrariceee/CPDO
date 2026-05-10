<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$user = require_role([ROLE_LANDLORD]);
verify_csrf();

$errors = [];
$post   = [];

/*
|--------------------------------------------------------------------------
| Helper: generate / update vicinity map PDF from drawn land boundary center
|--------------------------------------------------------------------------
*/
if (!function_exists('landlord_app_generate_vicinity_pdf')) {
    function landlord_app_generate_vicinity_pdf(int $applicationId, array $application, array $post, int $userId): void
    {
        // Primary: explicit latitude/longitude hidden inputs (written by the polygon JS).
        $lat = is_numeric($post['latitude'] ?? '') ? (float)$post['latitude'] : null;
        $lng = is_numeric($post['longitude'] ?? '') ? (float)$post['longitude'] : null;

        // Fallback 1: parse the "lat, lng" string stored in coordinates.
        if (($lat === null || $lng === null) && !empty($post['coordinates'])) {
            $parts = explode(',', (string)$post['coordinates']);
            if (count($parts) === 2) {
                $parsedLat = trim($parts[0]);
                $parsedLng = trim($parts[1]);
                if (is_numeric($parsedLat) && is_numeric($parsedLng)) {
                    $lat = (float)$parsedLat;
                    $lng = (float)$parsedLng;
                }
            }
        }

        // Fallback 2: derive centroid from the GeoJSON polygon itself.
        if (($lat === null || $lng === null) && !empty($post['land_polygon_geojson'])) {
            try {
                $gj     = json_decode((string)$post['land_polygon_geojson'], true);
                $coords = null;
                if (isset($gj['features'][0]['geometry']['coordinates'][0])) {
                    $coords = $gj['features'][0]['geometry']['coordinates'][0];
                } elseif (isset($gj['geometry']['coordinates'][0])) {
                    $coords = $gj['geometry']['coordinates'][0];
                } elseif (isset($gj['coordinates'][0])) {
                    $coords = $gj['coordinates'][0];
                }
                if (is_array($coords) && count($coords) > 0) {
                    $sumLat = 0.0; $sumLng = 0.0; $n = count($coords);
                    foreach ($coords as $pt) { $sumLng += (float)$pt[0]; $sumLat += (float)$pt[1]; }
                    $lat = $sumLat / $n;
                    $lng = $sumLng / $n;
                }
            } catch (Throwable $e) { /* ignore malformed GeoJSON */ }
        }

        if ($lat === null || $lng === null) {
            return;
        }

        try {
            $pdfPath = generate_vicinity_map_pdf(
                lat:            $lat,
                lng:            $lng,
                applicantName:  trim($post['account_name'] ?? $application['account_name'] ?? ''),
                projectName:    trim($post['property_title'] ?? $application['property_title'] ?? ''),
                registryNumber: $application['registry_number'] ?? ''
            );

            $pdo = db();

            $pdo->prepare(
                'UPDATE applications
                 SET vicinity_map_pdf_path = ?
                 WHERE id = ?'
            )->execute([
                $pdfPath,
                $applicationId,
            ]);

            $absPath  = __DIR__ . '/../../' . $pdfPath;
            $pdfBytes = is_file($absPath) ? file_get_contents($absPath) : false;

            if ($pdfBytes !== false) {
                $encName = encrypt_sensitive('vicinity_map_generated.pdf');

                $pdo->prepare(
                    'UPDATE requirement_documents
                     SET file_data = ?,
                         file_mime = "application/pdf",
                         file_path = NULL,
                         original_name_enc = ?,
                         original_name_nonce = ?,
                         uploaded_at = NOW()
                     WHERE application_id = ?
                       AND requirement_key = "vicinity_map"'
                )->execute([
                    $pdfBytes,
                    $encName['ciphertext'],
                    $encName['nonce'],
                    $applicationId,
                ]);
            }
        } catch (Throwable $exception) {
            audit_log(
                $userId,
                'VICINITY_MAP_PDF_FAILED',
                'applications',
                $applicationId,
                ['error' => $exception->getMessage()]
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Edit mode: load existing application
|--------------------------------------------------------------------------
*/
$editId     = (int)($_GET['edit'] ?? 0);
$editApp    = null;
$isEditMode = false;

if ($editId > 0) {
    $editStmt = db()->prepare(
        'SELECT *
         FROM applications
         WHERE id = ?
           AND landlord_id = ?'
    );

    $editStmt->execute([
        $editId,
        (int)$user['id'],
    ]);

    $editApp = $editStmt->fetch();

    if ($editApp) {
        $isEditMode = true;

        if (empty($_POST)) {
            $post = (array)$editApp;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Handle submit
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = $_POST;

    // Merge "Others — specify" for type_of_project
    if (($post['type_of_project'] ?? '') === 'Others' && trim($post['type_of_project_other'] ?? '') !== '') {
        $post['type_of_project'] = trim($post['type_of_project_other']);
    }

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

    if (!in_array($post['nature_of_application'] ?? '', ['new_development', 'improvement', 'others'], true)) {
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

    if (trim($post['land_polygon_geojson'] ?? '') === '') {
        $errors['land_polygon_geojson'] = 'Please draw the land boundary on the map.';
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

    if (isset($post['land_polygon_area_sqm']) && $post['land_polygon_area_sqm'] !== '' && (!is_numeric($post['land_polygon_area_sqm']) || (float)$post['land_polygon_area_sqm'] <= 0)) {
        $errors['land_polygon_area_sqm'] = 'Drawn land area must be valid.';
    }

    if (empty($errors)) {
        $natureOther = ($post['nature_of_application'] ?? '') === 'others'
            ? trim($post['nature_of_application_other'] ?? '')
            : null;

        $landUseOther = ($post['existing_land_use'] ?? '') === 'others'
            ? trim($post['existing_land_use_other'] ?? '')
            : null;

        if ($isEditMode && $editApp) {
            db()->prepare(
                'UPDATE applications
                 SET account_name = ?,
                     account_address = ?,
                     corporation_name = ?,
                     representative_name = ?,
                     property_title = ?,
                     property_address = ?,
                     coordinates = ?,
                     land_polygon_geojson = ?,
                     land_polygon_area_sqm = ?,
                     type_of_project = ?,
                     lot_area = ?,
                     building_area = ?,
                     project_cost = ?,
                     nature_of_application = ?,
                     nature_of_application_other = ?,
                     right_over_land = ?,
                     existing_land_use = ?,
                     existing_land_use_other = ?,
                     sworn_statement = ?
                 WHERE id = ?
                   AND landlord_id = ?'
            )->execute([
                trim($post['account_name']),
                trim($post['account_address']),
                trim($post['corporation_name'] ?? '') ?: null,
                trim($post['representative_name'] ?? '') ?: null,
                trim($post['property_title']),
                trim($post['property_address']),
                trim($post['coordinates'] ?? '') ?: null,
                trim($post['land_polygon_geojson'] ?? '') ?: null,
                is_numeric($post['land_polygon_area_sqm'] ?? '') ? (float)$post['land_polygon_area_sqm'] : null,
                trim($post['type_of_project'] ?? '') ?: null,
                is_numeric($post['lot_area'] ?? '') ? (float)$post['lot_area'] : null,
                is_numeric($post['building_area'] ?? '') ? (float)$post['building_area'] : null,
                is_numeric($post['project_cost'] ?? '') ? (float)$post['project_cost'] : null,
                in_array($post['nature_of_application'] ?? '', ['new_development', 'improvement', 'others'], true)
                    ? $post['nature_of_application']
                    : null,
                $natureOther,
                in_array($post['right_over_land'] ?? '', ['owner', 'lessee'], true)
                    ? $post['right_over_land']
                    : null,
                in_array($post['existing_land_use'] ?? '', ['residential', 'commercial', 'industrial', 'institutional', 'agricultural', 'others'], true)
                    ? $post['existing_land_use']
                    : null,
                $landUseOther,
                !empty($post['sworn_statement']) ? 1 : 0,
                $editId,
                (int)$user['id'],
            ]);

            $updatedStmt = db()->prepare(
                'SELECT *
                 FROM applications
                 WHERE id = ?
                   AND landlord_id = ?'
            );

            $updatedStmt->execute([
                $editId,
                (int)$user['id'],
            ]);

            $updatedApplication = $updatedStmt->fetch() ?: $editApp;

            landlord_app_generate_vicinity_pdf(
                $editId,
                $updatedApplication,
                $post,
                (int)$user['id']
            );

            audit_log((int)$user['id'], 'APPLICATION_UPDATED', 'applications', $editId);

            $_SESSION['flash_success'] = 'Application updated successfully.';
            redirect('landlord/requirements-upload.php?id=' . $editId);
        }

        $applicationId = create_application((int)$user['id'], $post);

        $newStmt = db()->prepare(
            'SELECT *
             FROM applications
             WHERE id = ?
               AND landlord_id = ?'
        );

        $newStmt->execute([
            $applicationId,
            (int)$user['id'],
        ]);

        $newApplication = $newStmt->fetch();

        if ($newApplication) {
            landlord_app_generate_vicinity_pdf(
                $applicationId,
                $newApplication,
                $post,
                (int)$user['id']
            );
        }

        redirect('landlord/requirements-upload.php?id=' . $applicationId);
    }
}

$v = fn(string $key, string $default = '') => e($post[$key] ?? $default);

$effectiveRequirements = effective_requirements();
$mandatoryCount = array_sum(array_map('count', $effectiveRequirements));

require __DIR__ . '/../partials/header.php';
?>

<style>
    :root {
        --app-ink: #241b0b;
        --app-muted: #6f6656;
        --app-muted-dark: #4f4738;
        --app-soft: #fff8dd;
        --app-soft-2: #fffdf6;
        --app-gold: #f6cf4a;
        --app-gold-dark: #c59000;
        --app-border: #f0dfad;
        --app-card: #ffffff;
        --app-success: #157347;
        --app-danger: #dc3545;
        --app-blue: #2f80c7;
        --app-blue-soft: #eaf4ff;
        --app-shadow: 0 18px 44px rgba(36, 27, 11, 0.08);
        --app-shadow-strong: 0 24px 60px rgba(36, 27, 11, 0.12);
    }

    body.application-form-page {
        background:
            radial-gradient(circle at 1px 1px, rgba(246, 207, 74, 0.14) 1px, transparent 0),
            linear-gradient(180deg, #ffffff 0%, #fffdf7 100%);
        background-size: 28px 28px, 100% 100%;
        color: var(--app-ink);
    }

    .app-shell {
        max-width: 1180px;
        margin: 0 auto;
        padding: 34px 18px 64px;
    }

    .app-topbar {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 24px;
    }

    .app-back {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 40px;
        padding: 9px 15px;
        border-radius: 11px;
        background: #ffffff;
        color: var(--app-ink);
        border: 1px solid #d6e0ec;
        text-decoration: none;
        font-size: 0.84rem;
        font-weight: 850;
        box-shadow: 0 10px 24px rgba(36, 27, 11, 0.06);
        transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .app-back:hover {
        color: var(--app-ink);
        background: var(--app-soft);
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(36, 27, 11, 0.09);
    }

    .app-eyebrow {
        margin: 0 0 6px;
        color: var(--app-muted-dark);
        font-size: 0.72rem;
        font-weight: 950;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .app-title {
        margin: 0;
        color: var(--app-ink);
        font-size: clamp(1.85rem, 4vw, 2.75rem);
        font-weight: 950;
        line-height: 1.05;
        letter-spacing: -0.045em;
    }

    .app-subtitle {
        max-width: 760px;
        margin: 12px 0 0;
        color: var(--app-muted);
        font-size: 0.96rem;
        line-height: 1.7;
        font-weight: 550;
    }

    .app-layout {
        display: grid;
        grid-template-columns: 360px minmax(0, 1fr);
        gap: 26px;
        align-items: start;
    }

    .app-sidebar,
    .app-form-card {
        position: relative;
        overflow: hidden;
        border-radius: 24px;
        background:
            radial-gradient(circle at top right, rgba(246, 207, 74, 0.15), transparent 36%),
            #ffffff;
        border: 1px solid var(--app-border);
        box-shadow: var(--app-shadow);
    }

    .app-sidebar {
        padding: 24px;
        position: sticky;
        top: 20px;
    }

    .app-side-header {
        display: flex;
        gap: 14px;
        align-items: flex-start;
        margin-bottom: 18px;
    }

    .app-side-badge {
        width: 56px;
        height: 56px;
        flex: 0 0 56px;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--app-soft);
        color: var(--app-gold-dark);
        border: 1px solid #efd185;
        font-size: 1rem;
        font-weight: 950;
        box-shadow: 0 12px 24px rgba(197, 144, 0, 0.10);
    }

    .app-side-title {
        margin: 0;
        color: var(--app-ink);
        font-size: 1.1rem;
        font-weight: 950;
        letter-spacing: -0.02em;
    }

    .app-side-sub {
        margin: 6px 0 0;
        color: var(--app-muted);
        font-size: 0.83rem;
        line-height: 1.55;
        font-weight: 550;
    }

    .app-steps {
        display: grid;
        gap: 10px;
        margin: 18px 0;
    }

    .app-step {
        display: grid;
        grid-template-columns: 30px minmax(0, 1fr);
        gap: 10px;
        padding: 12px;
        border-radius: 14px;
        background: var(--app-soft-2);
        border: 1px solid var(--app-border);
    }

    .app-step-num {
        width: 30px;
        height: 30px;
        border-radius: 10px;
        background: var(--app-soft);
        color: var(--app-gold-dark);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.78rem;
        font-weight: 950;
    }

    .app-step strong {
        display: block;
        margin-bottom: 3px;
        color: var(--app-ink);
        font-size: 0.8rem;
        font-weight: 950;
    }

    .app-step span {
        display: block;
        color: var(--app-muted);
        font-size: 0.74rem;
        line-height: 1.42;
        font-weight: 600;
    }

    .req-office-block {
        margin-top: 18px;
        padding-top: 14px;
        border-top: 1px solid #eee6d1;
    }

    .req-office-label {
        margin: 0 0 8px;
        color: var(--app-muted-dark);
        font-size: 0.7rem;
        font-weight: 950;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .req-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 8px;
    }

    .req-list li {
        display: grid;
        grid-template-columns: 24px minmax(0, 1fr);
        gap: 8px;
        color: #3d4b5c;
        font-size: 0.82rem;
        line-height: 1.45;
        font-weight: 650;
    }

    .req-num {
        width: 24px;
        height: 24px;
        border-radius: 999px;
        background: #241b0b;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.68rem;
        font-weight: 950;
    }

    .req-details-dropdown {
        margin-top: 4px;
    }

    .req-details-summary {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        color: var(--app-muted-dark);
        cursor: pointer;
        font-size: 0.7rem;
        font-weight: 850;
        list-style: none;
        padding: 2px 0;
    }

    .req-details-summary::-webkit-details-marker,
    .req-details-summary::marker {
        display: none;
    }

    .req-details-summary:hover {
        color: var(--app-gold-dark);
    }

    .req-details-body {
        margin-top: 6px;
        padding: 9px 11px;
        background: #f9f7ef;
        border-left: 3px solid var(--app-gold);
        border-radius: 0 8px 8px 0;
        color: var(--app-muted);
        font-size: 0.76rem;
        line-height: 1.55;
    }

    .app-form-card {
        padding: clamp(20px, 3vw, 28px);
    }

    .app-no {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 14px 16px;
        background: #f9f7ef;
        border: 1px solid var(--app-border);
        border-radius: 14px;
        margin-bottom: 24px;
    }

    .app-no-label {
        color: var(--app-muted-dark);
        font-size: 0.72rem;
        font-weight: 950;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        white-space: nowrap;
    }

    .app-no-value {
        color: var(--app-muted);
        font-size: 0.86rem;
        font-style: italic;
        font-weight: 750;
    }

    .app-section {
        margin-top: 28px;
        padding-top: 24px;
        border-top: 1px solid var(--app-border);
    }

    .app-section:first-of-type {
        margin-top: 0;
        padding-top: 0;
        border-top: 0;
    }

    .app-section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 18px;
        color: var(--app-muted-dark);
        font-size: 0.78rem;
        font-weight: 950;
        text-transform: uppercase;
        letter-spacing: 0.10em;
    }

    .app-section-title::before {
        content: "";
        width: 9px;
        height: 9px;
        border-radius: 999px;
        background: var(--app-gold);
        box-shadow: 0 0 0 4px rgba(246, 207, 74, 0.18);
    }

    .app-form-card .form-label {
        color: var(--app-ink);
        font-size: 0.84rem;
        font-weight: 900;
        margin-bottom: 7px;
    }

    .app-form-card .form-control,
    .app-form-card .form-select {
        min-height: 47px;
        padding: 11px 13px;
        border-radius: 13px;
        border: 1px solid var(--app-border);
        background: #ffffff;
        color: var(--app-ink);
        font-size: 0.9rem;
        font-weight: 600;
        box-shadow: none;
    }

    .app-form-card textarea.form-control {
        min-height: 90px;
        line-height: 1.55;
    }

    .app-form-card .form-control:focus,
    .app-form-card .form-select:focus {
        border-color: #e6b82f;
        box-shadow: 0 0 0 4px rgba(246, 207, 74, 0.18);
    }

    .app-field-error {
        color: var(--app-danger);
        font-size: 0.76rem;
        font-weight: 800;
        margin-top: 5px;
    }

    .is-invalid-field .form-control,
    .is-invalid-field .form-select {
        border-color: var(--app-danger);
    }

    .app-choice-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 9px;
        margin-top: 6px;
    }

    .app-choice {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 38px;
        padding: 9px 12px;
        border-radius: 999px;
        border: 1px solid var(--app-border);
        background: #ffffff;
        color: var(--app-muted-dark);
        font-size: 0.82rem;
        font-weight: 850;
        cursor: pointer;
        transition: background 0.16s ease, border-color 0.16s ease, color 0.16s ease;
    }

    .app-choice input {
        width: 15px;
        height: 15px;
        accent-color: var(--app-gold-dark);
    }

    .app-choice:has(input:checked) {
        background: var(--app-soft);
        border-color: #e6b82f;
        color: var(--app-ink);
    }

    .app-others-row {
        display: none;
        margin-top: 10px;
        align-items: center;
        gap: 10px;
    }

    .app-others-row.is-visible {
        display: flex;
    }

    .app-others-row label {
        color: var(--app-muted-dark);
        font-size: 0.78rem;
        font-weight: 900;
        white-space: nowrap;
    }

    .land-boundary-card {
        overflow: hidden;
        border-radius: 20px;
        background:
            radial-gradient(circle at top right, rgba(47, 128, 199, 0.12), transparent 36%),
            #ffffff;
        border: 1px solid var(--app-border);
        box-shadow: 0 14px 34px rgba(36, 27, 11, 0.08);
    }

    .land-boundary-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        padding: 18px 20px;
        background: #f9f7ef;
        border-bottom: 1px solid var(--app-border);
    }

    .land-boundary-eyebrow {
        margin: 0 0 5px;
        color: var(--app-muted-dark);
        font-size: 0.68rem;
        font-weight: 950;
        text-transform: uppercase;
        letter-spacing: 0.10em;
    }

    .land-boundary-title {
        margin: 0;
        color: var(--app-ink);
        font-size: 1.05rem;
        font-weight: 950;
        letter-spacing: -0.02em;
    }

    .land-boundary-sub {
        max-width: 640px;
        margin: 7px 0 0;
        color: var(--app-muted);
        font-size: 0.82rem;
        line-height: 1.6;
        font-weight: 550;
    }

    .land-boundary-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .land-boundary-btn {
        min-height: 36px;
        padding: 8px 12px;
        border-radius: 10px;
        border: 1px solid var(--app-border);
        background: #ffffff;
        color: var(--app-muted-dark);
        font-size: 0.76rem;
        font-weight: 900;
        cursor: pointer;
        transition: background 0.16s ease, transform 0.16s ease;
        white-space: nowrap;
    }

    .land-boundary-btn:hover {
        background: var(--app-soft);
        transform: translateY(-1px);
    }

    .land-boundary-map-wrap {
        position: relative;
        background: #edf2f7;
    }

    #landBoundaryMap {
        width: 100%;
        height: 430px;
        z-index: 1;
    }

    .land-boundary-info {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        border-top: 1px solid var(--app-border);
    }

    .land-boundary-info > div {
        padding: 14px 16px;
        border-right: 1px solid var(--app-border);
        background: var(--app-soft-2);
    }

    .land-boundary-info > div:last-child {
        border-right: 0;
    }

    .land-boundary-label {
        display: block;
        margin-bottom: 4px;
        color: var(--app-muted-dark);
        font-size: 0.66rem;
        font-weight: 950;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .land-boundary-info strong {
        display: block;
        color: var(--app-ink);
        font-size: 0.84rem;
        font-weight: 900;
        overflow-wrap: anywhere;
    }

    .land-boundary-note {
        margin: 0;
        padding: 12px 16px;
        border-top: 1px solid var(--app-border);
        background: #f9f7ef;
        color: var(--app-muted);
        font-size: 0.78rem;
        line-height: 1.55;
        font-weight: 600;
    }

    .land-boundary-error {
        display: <?= isset($errors['land_polygon_geojson']) ? 'block' : 'none' ?>;
        margin-top: 10px;
        padding: 12px 14px;
        border-radius: 12px;
        background: #fff0f0;
        border: 1px solid #ffc9c9;
        color: var(--app-danger);
        font-size: 0.82rem;
        font-weight: 800;
    }

    .sworn-block {
        padding: 18px;
        border-radius: 18px;
        background: #f9f7ef;
        border: 1px solid var(--app-border);
        border-left: 5px solid var(--app-gold);
    }

    .sworn-block.is-error {
        background: #fff4f4;
        border-color: #ffc9c9;
        border-left-color: var(--app-danger);
    }

    .sworn-text {
        margin: 0 0 12px;
        color: var(--app-muted);
        font-size: 0.86rem;
        line-height: 1.65;
        font-weight: 550;
    }

    .sworn-check {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        color: var(--app-ink);
        font-size: 0.88rem;
        font-weight: 850;
        cursor: pointer;
    }

    .sworn-check input {
        width: 18px;
        height: 18px;
        margin-top: 2px;
        accent-color: var(--app-gold-dark);
        flex-shrink: 0;
    }

    .app-submit-panel {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        margin-top: 28px;
        padding-top: 24px;
        border-top: 1px solid var(--app-border);
    }

    .app-submit-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        min-height: 50px;
        padding: 13px 22px;
        border-radius: 14px;
        border: 1px solid #e6b82f;
        background: var(--app-gold);
        color: var(--app-ink);
        font-size: 0.9rem;
        font-weight: 950;
        box-shadow: 0 14px 28px rgba(197, 144, 0, 0.22);
        transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
    }

    .app-submit-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 34px rgba(197, 144, 0, 0.28);
    }

    .app-submit-note {
        color: var(--app-muted);
        font-size: 0.82rem;
        line-height: 1.55;
        font-weight: 600;
        max-width: 360px;
    }

    .leaflet-container {
        font-family: inherit;
    }

    .leaflet-draw-toolbar a {
        background-color: #ffffff;
    }

    @media (max-width: 1100px) {
        .app-layout {
            grid-template-columns: 1fr;
        }

        .app-sidebar {
            position: static;
        }
    }

    @media (max-width: 767.98px) {
        .app-shell {
            padding: 24px 14px 44px;
        }

        .app-topbar {
            flex-direction: column;
            align-items: flex-start;
        }

        .app-back {
            width: 100%;
        }

        .land-boundary-header {
            flex-direction: column;
        }

        .land-boundary-actions {
            width: 100%;
            justify-content: stretch;
        }

        .land-boundary-btn {
            flex: 1;
        }

        #landBoundaryMap {
            height: 360px;
        }

        .land-boundary-info {
            grid-template-columns: 1fr;
        }

        .land-boundary-info > div {
            border-right: 0;
            border-bottom: 1px solid var(--app-border);
        }

        .land-boundary-info > div:last-child {
            border-bottom: 0;
        }

        .app-submit-btn {
            width: 100%;
        }
    }
</style>

<script>
    document.body.classList.add('application-form-page');
</script>

<div class="app-shell">

    <div class="app-topbar">
        <a
            class="app-back"
            href="<?= $isEditMode ? 'requirements-upload.php?id=' . (int)$editId : 'dashboard.php' ?>"
        >
            <span aria-hidden="true">&larr;</span>
            <?= $isEditMode ? 'Back to Upload' : 'Dashboard' ?>
        </a>

        <div>
            <p class="app-eyebrow"><?= $isEditMode ? 'Edit Application' : 'Application Form' ?></p>

            <h1 class="app-title">
                <?= $isEditMode ? 'Edit Locational Clearance Application' : 'Application for Locational Clearance' ?>
            </h1>

            <p class="app-subtitle">
                Complete the applicant, project, land boundary, and land-use details. After submission,
                you will continue to the document upload page for the mandatory requirements.
            </p>

            <?php if ($isEditMode && $editApp): ?>
                <p class="text-secondary small mt-2 mb-0">
                    Registry: <?= e($editApp['registry_number']) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-4" role="alert">
            <strong>Please correct the errors below.</strong>
            Some required information is missing or invalid.
        </div>
    <?php endif; ?>

    <div class="app-layout">

        <!-- Requirements Sidebar -->
        <aside class="app-sidebar">
            <div class="app-side-header">
                <div class="app-side-badge">
                    <?= number_format($mandatoryCount) ?>
                </div>

                <div>
                    <h2 class="app-side-title">Mandatory Requirements</h2>

                    <p class="app-side-sub">
                        After submitting this application, upload all required documents on the next page.
                    </p>
                </div>
            </div>

            <div class="app-steps">
                <div class="app-step">
                    <div class="app-step-num">01</div>
                    <div>
                        <strong>Fill out application</strong>
                        <span>Provide applicant, project, land-use, and boundary details.</span>
                    </div>
                </div>

                <div class="app-step">
                    <div class="app-step-num">02</div>
                    <div>
                        <strong>Upload documents</strong>
                        <span>Submit the required supporting files for review.</span>
                    </div>
                </div>

                <div class="app-step">
                    <div class="app-step-num">03</div>
                    <div>
                        <strong>Officer evaluation</strong>
                        <span>Your submission will move through the review workflow.</span>
                    </div>
                </div>
            </div>

            <?php $num = 0; ?>
            <?php foreach ($effectiveRequirements as $office => $documents): ?>
                <div class="req-office-block">
                    <p class="req-office-label"><?= e($office) ?></p>

                    <ul class="req-list">
                        <?php foreach ($documents as $key => $doc): ?>
                            <?php $num++; ?>

                            <li>
                                <span class="req-num"><?= $num ?></span>

                                <span>
                                    <?= e($doc['title']) ?>

                                    <?php if (!empty($doc['details'])): ?>
                                        <details class="req-details-dropdown">
                                            <summary class="req-details-summary">
                                                What to prepare
                                            </summary>

                                            <div class="req-details-body">
                                                <?= nl2br(e($doc['details'])) ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </aside>

        <!-- Application Form -->
        <main>
            <form class="app-form-card" method="post" novalidate id="lc-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <?php if ($isEditMode): ?>
                    <input type="hidden" name="edit_id" value="<?= (int)$editId ?>">
                <?php endif; ?>

                <div class="app-no">
                    <span class="app-no-label">Application No.</span>

                    <span class="app-no-value">
                        <?= $isEditMode && $editApp ? e($editApp['registry_number']) : 'Will be assigned upon submission' ?>
                    </span>
                </div>

                <!-- Applicant Details -->
                <section class="app-section">
                    <h2 class="app-section-title">Applicant Details</h2>

                    <div class="row g-3">
                        <div class="col-12 <?= isset($errors['account_name']) ? 'is-invalid-field' : '' ?>">
                            <label class="form-label" for="account_name">
                                Name of Applicant <span class="text-danger">*</span>
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="account_name"
                                name="account_name"
                                value="<?= $v('account_name', user_full_name($user)) ?>"
                                required
                                placeholder="Full legal name"
                            >

                            <?php if (isset($errors['account_name'])): ?>
                                <div class="app-field-error"><?= e($errors['account_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 <?= isset($errors['account_address']) ? 'is-invalid-field' : '' ?>">
                            <label class="form-label" for="account_address">
                                Address of Applicant <span class="text-danger">*</span>
                            </label>

                            <textarea
                                class="form-control"
                                id="account_address"
                                name="account_address"
                                rows="2"
                                required
                                placeholder="Complete address"
                            ><?= $v('account_address') ?></textarea>

                            <?php if (isset($errors['account_address'])): ?>
                                <div class="app-field-error"><?= e($errors['account_address']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="corporation_name">
                                Name of Corporation
                                <span class="text-secondary fw-normal small">(optional)</span>
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="corporation_name"
                                name="corporation_name"
                                value="<?= $v('corporation_name') ?>"
                                placeholder="If applicable"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="representative_name">
                                Representative of the Owner
                                <span class="text-secondary fw-normal small">(optional)</span>
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="representative_name"
                                name="representative_name"
                                value="<?= $v('representative_name') ?>"
                                placeholder="If applicable"
                            >
                        </div>
                    </div>
                </section>

                <!-- Project Details -->
                <section class="app-section">
                    <h2 class="app-section-title">Project Details</h2>

                    <div class="row g-3">
                        <div class="col-12 <?= isset($errors['property_title']) ? 'is-invalid-field' : '' ?>">
                            <label class="form-label" for="property_title">
                                Project / Property Title <span class="text-danger">*</span>
                            </label>

                            <input
                                class="form-control"
                                type="text"
                                id="property_title"
                                name="property_title"
                                value="<?= $v('property_title') ?>"
                                required
                                placeholder="Name of the project or property"
                            >

                            <?php if (isset($errors['property_title'])): ?>
                                <div class="app-field-error"><?= e($errors['property_title']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 <?= isset($errors['type_of_project']) ? 'is-invalid-field' : '' ?>">
                            <label class="form-label" for="type_of_project">
                                Type of Project <span class="text-danger">*</span>
                            </label>

                            <?php
                            $projectTypes = [
                                ''                                    => '— Select type of project —',
                                'Residential Subdivision'             => 'Residential Subdivision',
                                'Residential Condominium'             => 'Residential Condominium',
                                'Apartment / Boarding House'          => 'Apartment / Boarding House',
                                'Single Detached Residential'         => 'Single Detached Residential',
                                'Socialized Housing'                  => 'Socialized Housing',
                                'Commercial Building'                 => 'Commercial Building',
                                'Mixed-Use Development'               => 'Mixed-Use Development',
                                'Industrial Facility'                 => 'Industrial Facility',
                                'Institutional / Government Facility' => 'Institutional / Government Facility',
                                'Agricultural Development'            => 'Agricultural Development',
                                'Tourism / Recreational Facility'     => 'Tourism / Recreational Facility',
                                'Memorial Park / Cemetery'            => 'Memorial Park / Cemetery',
                                'Utilities / Infrastructure'          => 'Utilities / Infrastructure',
                                'Others'                              => 'Others (specify below)',
                            ];
                            $currentType = $v('type_of_project');
                            $isOtherType = $currentType !== '' && !array_key_exists($currentType, $projectTypes);
                            ?>

                            <select
                                class="form-control form-select"
                                id="type_of_project"
                                name="type_of_project"
                                required
                                onchange="document.getElementById('type_of_project_other_row').style.display=(this.value==='Others'?'block':'none')"
                            >
                                <?php foreach ($projectTypes as $val => $label): ?>
                                    <option value="<?= e($val) ?>" <?= ($currentType === $val || ($isOtherType && $val === 'Others')) ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div id="type_of_project_other_row" style="margin-top:8px;<?= ($currentType === 'Others' || $isOtherType) ? '' : 'display:none' ?>">
                                <input
                                    class="form-control"
                                    type="text"
                                    id="type_of_project_other"
                                    name="type_of_project_other"
                                    value="<?= $isOtherType ? $currentType : '' ?>"
                                    placeholder="Please specify the type of project"
                                >
                            </div>

                            <?php if (isset($errors['type_of_project'])): ?>
                                <div class="app-field-error"><?= e($errors['type_of_project']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 <?= isset($errors['property_address']) ? 'is-invalid-field' : '' ?>">
                            <label class="form-label" for="property_address">
                                Project Location <span class="text-danger">*</span>
                            </label>

                            <textarea
                                class="form-control"
                                id="property_address"
                                name="property_address"
                                rows="2"
                                required
                                placeholder="Complete address of the project site"
                            ><?= $v('property_address') ?></textarea>

                            <?php if (isset($errors['property_address'])): ?>
                                <div class="app-field-error"><?= e($errors['property_address']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4 <?= isset($errors['lot_area']) ? 'is-invalid-field' : '' ?>">
                            <label class="form-label" for="lot_area">
                                Lot Area (sqm) <span class="text-danger">*</span>
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                id="lot_area"
                                name="lot_area"
                                value="<?= $v('lot_area') ?>"
                                min="0"
                                step="0.01"
                                required
                                placeholder="0.00"
                            >

                            <?php if (isset($errors['lot_area'])): ?>
                                <div class="app-field-error"><?= e($errors['lot_area']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4 <?= isset($errors['building_area']) ? 'is-invalid-field' : '' ?>">
                            <label class="form-label" for="building_area">
                                Building Area (sqm)
                                <span class="text-secondary fw-normal small">(optional)</span>
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                id="building_area"
                                name="building_area"
                                value="<?= $v('building_area') ?>"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                            >

                            <?php if (isset($errors['building_area'])): ?>
                                <div class="app-field-error"><?= e($errors['building_area']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4 <?= isset($errors['project_cost']) ? 'is-invalid-field' : '' ?>">
                            <label class="form-label" for="project_cost">
                                Project Cost (PHP)
                                <span class="text-secondary fw-normal small">(optional)</span>
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                id="project_cost"
                                name="project_cost"
                                value="<?= $v('project_cost') ?>"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                            >

                            <?php if (isset($errors['project_cost'])): ?>
                                <div class="app-field-error"><?= e($errors['project_cost']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- Land Boundary Mapping -->
                <section class="app-section">
                    <h2 class="app-section-title">Land Boundary Mapping</h2>

                    <?php
                    $existingCoordinates = $post['coordinates'] ?? ($editApp['coordinates'] ?? '');
                    $existingLandPolygon = $post['land_polygon_geojson'] ?? ($editApp['land_polygon_geojson'] ?? '');
                    $existingLandArea    = $post['land_polygon_area_sqm'] ?? ($editApp['land_polygon_area_sqm'] ?? '');

                    $existingLat = '';
                    $existingLng = '';

                    if ($existingCoordinates) {
                        $coordParts = explode(',', $existingCoordinates);

                        if (count($coordParts) === 2) {
                            $coordLat = trim($coordParts[0]);
                            $coordLng = trim($coordParts[1]);

                            if (is_numeric($coordLat) && is_numeric($coordLng)) {
                                $existingLat = $coordLat;
                                $existingLng = $coordLng;
                            }
                        }
                    }
                    ?>

                    <div class="land-boundary-card">
                        <div class="land-boundary-header">
                            <div>
                                <p class="land-boundary-eyebrow">Drawn Land Area</p>

                                <h3 class="land-boundary-title">
                                    Draw the exact land area you own
                                </h3>

                                <p class="land-boundary-sub">
                                    Use the polygon tool to trace the boundary of your property. The system will save the boundary,
                                    center coordinates, and estimated area for inspection and review.
                                </p>
                            </div>

                            <div class="land-boundary-actions">
                                <button type="button" class="land-boundary-btn" id="locateLandBoundary">
                                    Use My Location
                                </button>

                                <button type="button" class="land-boundary-btn" id="centerDavaoBoundary">
                                    Center Davao
                                </button>

                                <button type="button" class="land-boundary-btn" id="clearLandBoundary">
                                    Clear Drawing
                                </button>
                            </div>
                        </div>

                        <div class="land-boundary-map-wrap">
                            <div id="landBoundaryMap"></div>
                        </div>

                        <div class="land-boundary-info">
                            <div>
                                <span class="land-boundary-label">Center Coordinates</span>

                                <strong id="landBoundaryCenter">
                                    <?= $existingCoordinates ? e($existingCoordinates) : 'Not drawn yet' ?>
                                </strong>
                            </div>

                            <div>
                                <span class="land-boundary-label">Estimated Area</span>

                                <strong id="landBoundaryArea">
                                    <?= $existingLandArea ? number_format((float)$existingLandArea, 2) . ' sqm' : '0 sqm' ?>
                                </strong>
                            </div>

                            <div>
                                <span class="land-boundary-label">Quick Action</span>

                                <button type="button" class="land-boundary-btn" id="useDrawnAreaBtn">
                                    Use as Lot Area
                                </button>
                            </div>
                        </div>

                        <p class="land-boundary-note">
                            The drawn boundary is applicant-declared and will still be verified against submitted legal documents,
                            site inspection findings, and official records.
                        </p>
                    </div>

                    <div class="land-boundary-error" id="landBoundaryError">
                        <?= isset($errors['land_polygon_geojson'])
                            ? e($errors['land_polygon_geojson'])
                            : 'Please draw the land boundary on the map before submitting.' ?>
                    </div>

                    <input
                        type="hidden"
                        id="coordinates"
                        name="coordinates"
                        value="<?= e($existingCoordinates) ?>"
                    >

                    <input
                        type="hidden"
                        id="latitude"
                        name="latitude"
                        value="<?= e($existingLat) ?>"
                    >

                    <input
                        type="hidden"
                        id="longitude"
                        name="longitude"
                        value="<?= e($existingLng) ?>"
                    >

                    <input
                        type="hidden"
                        id="land_polygon_geojson"
                        name="land_polygon_geojson"
                        value="<?= e($existingLandPolygon) ?>"
                    >

                    <input
                        type="hidden"
                        id="land_polygon_area_sqm"
                        name="land_polygon_area_sqm"
                        value="<?= e($existingLandArea) ?>"
                    >
                </section>

                <!-- Land Use & Classification -->
                <section class="app-section">
                    <h2 class="app-section-title">Land Use &amp; Classification</h2>

                    <div class="mb-3 <?= isset($errors['nature_of_application']) ? 'is-invalid-field' : '' ?>">
                        <label class="form-label d-block">
                            Nature of Application <span class="text-danger">*</span>
                        </label>

                        <div class="app-choice-grid">
                            <?php
                            $noa = $post['nature_of_application'] ?? '';

                            $natureOptions = [
                                'new_development' => 'New Development',
                                'improvement'     => 'Improvement',
                                'others'          => 'Others',
                            ];
                            ?>

                            <?php foreach ($natureOptions as $value => $label): ?>
                                <label class="app-choice">
                                    <input
                                        type="radio"
                                        name="nature_of_application"
                                        value="<?= e($value) ?>"
                                        <?= $noa === $value ? 'checked' : '' ?>
                                    >

                                    <?= e($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="app-others-row <?= $noa === 'others' ? 'is-visible' : '' ?>" id="noa-other">
                            <label for="nature_of_application_other">Specify:</label>

                            <input
                                class="form-control form-control-sm"
                                type="text"
                                id="nature_of_application_other"
                                name="nature_of_application_other"
                                value="<?= $v('nature_of_application_other') ?>"
                                placeholder="Describe the nature of application"
                            >
                        </div>

                        <?php if (isset($errors['nature_of_application'])): ?>
                            <div class="app-field-error"><?= e($errors['nature_of_application']) ?></div>
                        <?php endif; ?>

                        <?php if (isset($errors['nature_of_application_other'])): ?>
                            <div class="app-field-error"><?= e($errors['nature_of_application_other']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3 <?= isset($errors['right_over_land']) ? 'is-invalid-field' : '' ?>">
                        <label class="form-label d-block">
                            Right over Land <span class="text-danger">*</span>
                        </label>

                        <?php $rol = $post['right_over_land'] ?? ''; ?>

                        <div class="app-choice-grid">
                            <label class="app-choice">
                                <input
                                    type="radio"
                                    name="right_over_land"
                                    value="owner"
                                    <?= $rol === 'owner' ? 'checked' : '' ?>
                                >

                                Owner
                            </label>

                            <label class="app-choice">
                                <input
                                    type="radio"
                                    name="right_over_land"
                                    value="lessee"
                                    <?= $rol === 'lessee' ? 'checked' : '' ?>
                                >

                                Lessee
                            </label>
                        </div>

                        <?php if (isset($errors['right_over_land'])): ?>
                            <div class="app-field-error"><?= e($errors['right_over_land']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-4 <?= isset($errors['existing_land_use']) ? 'is-invalid-field' : '' ?>">
                        <label class="form-label d-block">
                            Existing Land Use of Project Site <span class="text-danger">*</span>
                        </label>

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
                        ?>

                        <div class="app-choice-grid">
                            <?php foreach ($landUseOptions as $value => $label): ?>
                                <label class="app-choice">
                                    <input
                                        type="radio"
                                        name="existing_land_use"
                                        value="<?= e($value) ?>"
                                        <?= $elu === $value ? 'checked' : '' ?>
                                    >

                                    <?= e($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="app-others-row <?= $elu === 'others' ? 'is-visible' : '' ?>" id="elu-other">
                            <label for="existing_land_use_other">Specify:</label>

                            <input
                                class="form-control form-control-sm"
                                type="text"
                                id="existing_land_use_other"
                                name="existing_land_use_other"
                                value="<?= $v('existing_land_use_other') ?>"
                                placeholder="Describe the existing land use"
                            >
                        </div>

                        <?php if (isset($errors['existing_land_use'])): ?>
                            <div class="app-field-error"><?= e($errors['existing_land_use']) ?></div>
                        <?php endif; ?>

                        <?php if (isset($errors['existing_land_use_other'])): ?>
                            <div class="app-field-error"><?= e($errors['existing_land_use_other']) ?></div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Digital Sworn Statement -->
                <section class="app-section">
                    <h2 class="app-section-title">Digital Sworn Statement</h2>

                    <div class="sworn-block <?= isset($errors['sworn_statement']) ? 'is-error' : '' ?>">
                        <p class="sworn-text">
                            By checking the box below, the applicant affirms under oath that all information
                            provided in this Application for Locational Clearance is true, correct, and complete
                            to the best of their knowledge. Any false declaration shall be subject to the penalties
                            prescribed by law.
                        </p>

                        <label class="sworn-check">
                            <input
                                type="checkbox"
                                name="sworn_statement"
                                value="1"
                                <?= !empty($post['sworn_statement']) ? 'checked' : '' ?>
                            >

                            <span>
                                I hereby swear that all details provided are true and correct to the best of my knowledge.
                            </span>
                        </label>

                        <?php if (isset($errors['sworn_statement'])): ?>
                            <div class="app-field-error mt-2"><?= e($errors['sworn_statement']) ?></div>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="app-submit-panel">
                    <button class="app-submit-btn" type="submit">
                        <?= $isEditMode ? 'Save Changes' : 'Submit Application & Continue' ?>
                        <span aria-hidden="true">→</span>
                    </button>

                    <div class="app-submit-note">
                        <?= $isEditMode
                            ? 'Changes will be saved and you will return to the document upload page.'
                            : 'An application number will be generated automatically upon submission.' ?>
                    </div>
                </div>
            </form>
        </main>
    </div>
</div>

<script>
(function () {
    function updateOtherField(radioName, targetId) {
        var radios = document.querySelectorAll('input[name="' + radioName + '"]');
        var target = document.getElementById(targetId);

        if (!target) {
            return;
        }

        function refresh() {
            var checked = document.querySelector('input[name="' + radioName + '"]:checked');
            var shouldShow = checked && checked.value === 'others';

            target.classList.toggle('is-visible', shouldShow);

            if (!shouldShow) {
                var input = target.querySelector('input');

                if (input) {
                    input.value = '';
                }
            }
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', refresh);
        });

        refresh();
    }

    updateOtherField('nature_of_application', 'noa-other');
    updateOtherField('existing_land_use', 'elu-other');
}());
</script>

}());
</script>

<!-- Leaflet map styles -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css">
<style>
#landBoundaryMap {
    width: 100%;
    height: 430px;
    min-height: 430px;
    display: block;
    background: #edf2f7;
    position: relative;
    z-index: 1;
}
.leaflet-container {
    width: 100%;
    height: 100%;
    min-height: 430px;
    font-family: inherit;
    z-index: 1;
}
.leaflet-control-container {
    z-index: 500;
}
.map-load-error {
    padding: 18px;
    border-radius: 14px;
    background: #fff0f0;
    border: 1px solid #ffc9c9;
    color: #dc3545;
    font-size: 0.88rem;
    font-weight: 800;
    line-height: 1.5;
}
</style>
<script>
(function () {
    function loadScript(src, callback, fallback) {
        var script = document.createElement('script');
        script.src = src;
        script.async = false;
        script.onload = callback;
        script.onerror = function () {
            if (fallback) {
                loadScript(fallback, callback);
            } else {
                showMapLoadError('Map library failed to load. Please check your internet connection or CDN access.');
            }
        };
        document.head.appendChild(script);
    }

    function showMapLoadError(message) {
        var mapElement = document.getElementById('landBoundaryMap');
        if (!mapElement) { return; }
        mapElement.innerHTML =
            '<div class="map-load-error">' +
            message +
            '<br><br>Tip: Open DevTools → Console and check if Leaflet CDN files are blocked.' +
            '</div>';
    }

    function initLandBoundaryMap() {
        var mapElement = document.getElementById('landBoundaryMap');
        if (!mapElement) { return; }
        if (typeof L === 'undefined') {
            showMapLoadError('Leaflet is not loaded. The map cannot be displayed.');
            return;
        }

        var defaultLat = 7.1907;
        var defaultLng = 125.4553;

        var coordinatesInput   = document.getElementById('coordinates');
        var latitudeInput      = document.getElementById('latitude');
        var longitudeInput     = document.getElementById('longitude');
        var polygonInput       = document.getElementById('land_polygon_geojson');
        var areaInput          = document.getElementById('land_polygon_area_sqm');
        var centerDisplay      = document.getElementById('landBoundaryCenter');
        var areaDisplay        = document.getElementById('landBoundaryArea');
        var errorBox           = document.getElementById('landBoundaryError');
        var lotAreaInput       = document.getElementById('lot_area');
        var clearButton        = document.getElementById('clearLandBoundary');
        var locateButton       = document.getElementById('locateLandBoundary');
        var centerDavaoButton  = document.getElementById('centerDavaoBoundary');
        var useAreaButton      = document.getElementById('useDrawnAreaBtn');

        var savedGeoJson      = polygonInput ? polygonInput.value : '';
        var savedCoordinates  = coordinatesInput ? coordinatesInput.value : '';

        var initialCenter = [defaultLat, defaultLng];
        var initialZoom   = 16;

        if (savedCoordinates) {
            var parts = savedCoordinates.split(',');
            if (parts.length === 2) {
                var savedLat = parseFloat(parts[0]);
                var savedLng = parseFloat(parts[1]);
                if (!isNaN(savedLat) && !isNaN(savedLng)) {
                    initialCenter = [savedLat, savedLng];
                    initialZoom   = 17;
                }
            }
        }

        var map = L.map('landBoundaryMap', {
            center: initialCenter,
            zoom: initialZoom,
            scrollWheelZoom: true
        });

        var streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 20,
            attribution: '&copy; OpenStreetMap contributors'
        });

        var satelliteLayer = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 20, attribution: 'Tiles &copy; Esri' }
        );

        satelliteLayer.addTo(map);
        L.control.layers({ 'Satellite': satelliteLayer, 'Street Map': streetLayer }).addTo(map);

        var drawnItems = new L.FeatureGroup();
        map.addLayer(drawnItems);

        var boundaryStyle = {
            color: '#c59000',
            weight: 3,
            opacity: 1,
            fillColor: '#f6cf4a',
            fillOpacity: 0.30
        };

        if (L.Control && L.Control.Draw) {
            var drawControl = new L.Control.Draw({
                edit: { featureGroup: drawnItems, edit: true, remove: true },
                draw: {
                    polygon: {
                        allowIntersection: false,
                        showArea: true,
                        shapeOptions: boundaryStyle,
                        drawError: { color: '#dc3545', message: 'Boundary lines cannot cross each other.' }
                    },
                    rectangle:    { shapeOptions: boundaryStyle, showArea: true },
                    polyline:     false,
                    circle:       false,
                    circlemarker: false,
                    marker:       false
                }
            });
            map.addControl(drawControl);
        } else {
            showMapLoadError('Leaflet.draw failed to load. The map loaded, but drawing tools are unavailable.');
        }

        function getFirstLayer() {
            var selected = null;
            drawnItems.eachLayer(function (layer) { if (!selected) { selected = layer; } });
            return selected;
        }

        function computeArea(layer) {
            try {
                var latLngs = layer.getLatLngs();
                if (Array.isArray(latLngs[0])) { latLngs = latLngs[0]; }
                var area = 0, points = latLngs, length = points.length;
                for (var i = 0; i < length; i++) {
                    var j  = (i + 1) % length;
                    var xi = points[i].lng * Math.cos(points[i].lat * Math.PI / 180) * 111320;
                    var yi = points[i].lat * 110540;
                    var xj = points[j].lng * Math.cos(points[j].lat * Math.PI / 180) * 111320;
                    var yj = points[j].lat * 110540;
                    area  += xi * yj - xj * yi;
                }
                return Math.abs(area / 2);
            } catch (error) { return 0; }
        }

        function updateHiddenFields() {
            var layer = getFirstLayer();
            if (!layer) {
                if (coordinatesInput) coordinatesInput.value = '';
                if (latitudeInput)    latitudeInput.value    = '';
                if (longitudeInput)   longitudeInput.value   = '';
                if (polygonInput)     polygonInput.value     = '';
                if (areaInput)        areaInput.value        = '';
                if (centerDisplay)    centerDisplay.textContent = 'Not drawn yet';
                if (areaDisplay)      areaDisplay.textContent   = '0 sqm';
                return;
            }
            var bounds     = layer.getBounds();
            var center     = bounds.getCenter();
            var area       = computeArea(layer);
            var areaValue  = Math.round(area * 100) / 100;
            var centerText = center.lat.toFixed(6) + ', ' + center.lng.toFixed(6);

            if (coordinatesInput) coordinatesInput.value = centerText;
            if (latitudeInput)    latitudeInput.value    = center.lat.toFixed(6);
            if (longitudeInput)   longitudeInput.value   = center.lng.toFixed(6);
            if (polygonInput)     polygonInput.value     = JSON.stringify(layer.toGeoJSON());
            if (areaInput)        areaInput.value        = areaValue;
            if (centerDisplay)    centerDisplay.textContent = centerText;
            if (areaDisplay)      areaDisplay.textContent   = areaValue.toLocaleString() + ' sqm';
            if (errorBox)         errorBox.style.display    = 'none';
        }

        function addBoundaryLayer(layer) {
            drawnItems.clearLayers();
            if (layer.setStyle) { layer.setStyle(boundaryStyle); }
            drawnItems.addLayer(layer);
            updateHiddenFields();
            try { map.fitBounds(layer.getBounds(), { padding: [30, 30] }); } catch (e) {}
        }

        if (L.Draw && L.Draw.Event) {
            map.on(L.Draw.Event.CREATED, function (event) { addBoundaryLayer(event.layer); });
            map.on(L.Draw.Event.EDITED,  updateHiddenFields);
            map.on(L.Draw.Event.DELETED, updateHiddenFields);
        }

        if (clearButton) {
            clearButton.addEventListener('click', function () {
                drawnItems.clearLayers();
                updateHiddenFields();
            });
        }

        if (centerDavaoButton) {
            centerDavaoButton.addEventListener('click', function () {
                map.setView([defaultLat, defaultLng], 16);
                setTimeout(function () { map.invalidateSize(); }, 100);
            });
        }

        if (locateButton) {
            locateButton.addEventListener('click', function () {
                if (!navigator.geolocation) { alert('Geolocation is not supported by this browser.'); return; }
                locateButton.disabled    = true;
                locateButton.textContent = 'Locating...';
                navigator.geolocation.getCurrentPosition(
                    function (position) {
                        map.setView([position.coords.latitude, position.coords.longitude], 18);
                        locateButton.disabled    = false;
                        locateButton.textContent = 'Use My Location';
                        setTimeout(function () { map.invalidateSize(); }, 100);
                    },
                    function () {
                        alert('Unable to get your location.');
                        locateButton.disabled    = false;
                        locateButton.textContent = 'Use My Location';
                    },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            });
        }

        if (useAreaButton) {
            useAreaButton.addEventListener('click', function () {
                if (!areaInput || !lotAreaInput || !areaInput.value) {
                    alert('Draw the land boundary first before using the estimated area.');
                    return;
                }
                lotAreaInput.value = areaInput.value;
                lotAreaInput.focus();
            });
        }

        if (savedGeoJson) {
            try {
                L.geoJSON(JSON.parse(savedGeoJson), {
                    style: boundaryStyle,
                    onEachFeature: function (feature, layer) { addBoundaryLayer(layer); }
                });
            } catch (error) { console.warn('Invalid saved land boundary GeoJSON.', error); }
        }

        var form = document.getElementById('lc-form');
        if (form) {
            form.addEventListener('submit', function (event) {
                if (!polygonInput || !polygonInput.value) {
                    event.preventDefault();
                    if (errorBox) { errorBox.style.display = 'block'; }
                    mapElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    alert('Please draw the land boundary on the map before submitting the application.');
                }
            });
        }

        setTimeout(function () { map.invalidateSize(); }, 300);
        setTimeout(function () { map.invalidateSize(); }, 1000);
    }

    loadScript(
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        function () {
            loadScript(
                'https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js',
                initLandBoundaryMap,
                'https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js'
            );
        },
        'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js'
    );
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>