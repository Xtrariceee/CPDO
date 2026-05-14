<?php
/**
 * Zoning Officer — Generate Formal Legislative Resolution
 *
 * Produces a Philippine Sangguniang Panlungsod-style land reclassification
 * resolution PDF from the application data on record.
 *
 * Accessible to: Zoning Officer, System Admin
 */
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
if (!$applicationId) {
    $_SESSION['flash_error'] = 'No application selected.';
    redirect('zoning-officer/index.php');
}

$application = officer_application($applicationId);

// ── Gate: resolution can only be generated after TWG minutes are saved ────────
$meetingRow = latest_meeting_for_application($applicationId);
$minutesSaved = $meetingRow && trim($meetingRow['minutes'] ?? '') !== '';

if (!in_array($application['phase_status'], ['DELIBERATION', 'APPROVED', 'DISAPPROVED'], true)) {
    $_SESSION['flash_error'] = 'The resolution can only be generated after the TWG has saved the Minutes of Meeting and the application is under deliberation.';
    redirect('zoning-officer/index.php');
}

$inspection = db()->prepare('SELECT * FROM inspections WHERE application_id = ? ORDER BY id DESC LIMIT 1');
$inspection->execute([$applicationId]);
$inspectionRow = $inspection->fetch() ?: null;

// ── PDF download ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_resolution') {
    verify_csrf();

    // Determine current and proposed zone from application
    $existingLandUseMap = [
        'residential'   => 'Residential Zone (R)',
        'commercial'    => 'Commercial Zone (C)',
        'industrial'    => 'Industrial Zone (I)',
        'institutional' => 'Institutional Zone (INS)',
        'agricultural'  => 'Agricultural Zone (A)',
        'others'        => $application['existing_land_use_other'] ?? 'Other Zone',
    ];
    $currentZone = $existingLandUseMap[$application['existing_land_use'] ?? ''] ?? ($application['existing_land_use'] ?? '[CURRENT ZONE]');

    $data = [
        'session_type'        => trim($_POST['session_type']       ?? 'Regular Session'),
        'year'                => trim($_POST['year']               ?? date('Y')),
        'legislative_body'    => trim($_POST['legislative_body']   ?? 'Sangguniang Panlungsod'),
        'city'                => trim($_POST['city']               ?? 'Davao City'),
        'resolution_number'   => trim($_POST['resolution_number']  ?? ''),
        'item_number'         => trim($_POST['item_number']        ?? ''),
        'applicant_name'      => trim($_POST['applicant_name']     ?? $application['account_name']),
        'lot_area'            => trim($_POST['lot_area']           ?? ($application['lot_area'] ? number_format((float)$application['lot_area'], 2) : '')),
        'property_reference'  => trim($_POST['property_reference'] ?? $application['property_title']),
        'barangay'            => trim($_POST['barangay']           ?? $application['property_address']),
        'current_zone'        => trim($_POST['current_zone']       ?? $currentZone),
        'proposed_zone'       => trim($_POST['proposed_zone']      ?? ''),
        'purpose'             => trim($_POST['purpose']            ?? ($application['type_of_project'] ?? '')),
        'committee_findings'  => trim($_POST['committee_findings'] ?? ($inspectionRow['findings'] ?? '')),
        'date_approved'       => trim($_POST['date_approved']      ?? date('jS \d\a\y \o\f F Y')),
        'sponsoring_official' => trim($_POST['sponsoring_official'] ?? ''),
        'official_title'      => trim($_POST['official_title']     ?? 'City Councilor'),
        'committee_name'      => trim($_POST['committee_name']     ?? 'Committee on Land Use and Zoning'),
    ];

    audit_log((int)$user['id'], 'RESOLUTION_PDF_GENERATED', 'applications', $applicationId, [
        'resolution_number' => $data['resolution_number'],
    ]);

    // Save resolution data as a draft in final_outputs so the Admin Officer
    // can see it immediately and validate/issue the endorsement.
    $existingFO = db()->prepare('SELECT id FROM final_outputs WHERE application_id = ? ORDER BY id DESC LIMIT 1');
    $existingFO->execute([$applicationId]);
    $existingFORow = $existingFO->fetch();

    $resDataJson = json_encode($data, JSON_UNESCAPED_UNICODE);

    if ($existingFORow) {
        db()->prepare(
            'UPDATE final_outputs SET resolution_data = ?, endorsement_number = ?, uploaded_by = ? WHERE id = ?'
        )->execute([$resDataJson, $data['resolution_number'] ?: null, (int)$user['id'], (int)$existingFORow['id']]);
    } else {
        db()->prepare(
            'INSERT INTO final_outputs (application_id, resolution_data, endorsement_number, uploaded_by) VALUES (?, ?, ?, ?)'
        )->execute([$applicationId, $resDataJson, $data['resolution_number'] ?: null, (int)$user['id']]);
    }

    // Notify Admin Officer that the resolution is ready for validation
    notify_role(
        ROLE_ADMIN_OFFICER,
        $applicationId,
        'Resolution Ready for Validation',
        'The Zoning Officer has generated the legislative resolution for application '
        . $application['registry_number'] . '. Please review and issue the official endorsement.'
    );

    generate_resolution_pdf($data);
    // generate_resolution_pdf() calls exit — nothing runs after this
}

// ── Pre-fill form defaults ────────────────────────────────────────────────────
$existingLandUseMap = [
    'residential'   => 'Residential Zone (R)',
    'commercial'    => 'Commercial Zone (C)',
    'industrial'    => 'Industrial Zone (I)',
    'institutional' => 'Institutional Zone (INS)',
    'agricultural'  => 'Agricultural Zone (A)',
    'others'        => $application['existing_land_use_other'] ?? 'Other Zone',
];
$defaultCurrentZone = $existingLandUseMap[$application['existing_land_use'] ?? ''] ?? '';

$defaultFindings = '';
if ($inspectionRow && !empty($inspectionRow['findings'])) {
    $defaultFindings = mb_substr(trim($inspectionRow['findings']), 0, 400);
    if (mb_strlen($inspectionRow['findings']) > 400) { $defaultFindings .= '...'; }
}

// Also pull key points from the meeting minutes if available
$minutesSummary = '';
if ($meetingRow && !empty($meetingRow['minutes'])) {
    // Extract the "a. Reports" section as the primary findings source
    $minutesText = $meetingRow['minutes'];
    if (preg_match('/a\. Reports:\s*(.*?)(?=b\. Open Issues:|$)/si', $minutesText, $m)) {
        $minutesSummary = trim($m[1]);
    }
    if ($minutesSummary === '' && $defaultFindings === '') {
        $minutesSummary = mb_substr(trim($minutesText), 0, 400);
    }
}
if ($defaultFindings === '' && $minutesSummary !== '') {
    $defaultFindings = $minutesSummary;
}

require __DIR__ . '/../partials/header.php';
?>

<style>
.res-shell { max-width: 860px; margin: 0 auto; padding: 32px 18px 56px; }
.res-card  { background: #fff; border: 1px solid #d0dae6; border-radius: 14px; padding: clamp(22px,3vw,36px); box-shadow: 0 8px 28px rgba(11,42,74,.08); }
.res-section-title { font-size: .7rem; font-weight: 900; text-transform: uppercase; letter-spacing: .1em; color: #1d6aad; margin: 24px 0 12px; padding-bottom: 6px; border-bottom: 2px solid #eef2f7; }
.res-section-title:first-child { margin-top: 0; }
.res-form-row { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 14px; }
.res-field-label { display: block; margin-bottom: 6px; font-size: .76rem; font-weight: 800; color: #0b2a4a; }
.res-control { width: 100%; min-height: 40px; padding: 9px 12px; border-radius: 9px; border: 1px solid #c5d3df; background: #f8fbff; color: #0b2a4a; font-size: .88rem; font-weight: 600; outline: none; transition: border-color .15s, box-shadow .15s; }
.res-control:focus { border-color: #1d6aad; box-shadow: 0 0 0 3px rgba(29,106,173,.12); background: #fff; }
textarea.res-control { min-height: 100px; resize: vertical; line-height: 1.6; }
.res-preview-box { background: #f4f8fc; border: 1px solid #d0dae6; border-radius: 10px; padding: 18px 20px; font-family: 'Courier New', Courier, monospace; font-size: .78rem; line-height: 1.65; color: #0b2a4a; white-space: pre-wrap; word-break: break-word; max-height: 480px; overflow-y: auto; }
.res-btn { display: inline-flex; align-items: center; gap: 8px; min-height: 42px; padding: 11px 22px; border-radius: 10px; border: 1px solid #0b2a4a; background: #0b2a4a; color: #fff; font-size: .88rem; font-weight: 800; cursor: pointer; text-decoration: none; transition: background .15s, transform .15s; }
.res-btn:hover { background: #0e3560; color: #fff; transform: translateY(-1px); }
.res-btn-outline { background: transparent; color: #0b2a4a; border-color: #c5d3df; }
.res-btn-outline:hover { background: #eef2f7; color: #0b2a4a; }
@media (max-width: 640px) { .res-form-row { grid-template-columns: 1fr; } }
</style>

<div class="res-shell">
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <a class="btn btn-back btn-sm" href="index.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
        <div>
            <p class="eyebrow mb-0">Zoning Officer</p>
            <h1 class="h3 mb-0">Generate Legislative Resolution</h1>
        </div>
    </div>

    <!-- Application summary strip -->
    <div class="gov-card p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div>
                <span class="eyebrow d-block mb-0" style="font-size:.62rem;">Registry</span>
                <strong><?= e($application['registry_number']) ?></strong>
            </div>
            <div>
                <span class="eyebrow d-block mb-0" style="font-size:.62rem;">Property</span>
                <strong><?= e($application['property_title']) ?></strong>
            </div>
            <div>
                <span class="eyebrow d-block mb-0" style="font-size:.62rem;">Applicant</span>
                <strong><?= e($application['account_name']) ?></strong>
            </div>
        </div>
        <span class="status-pill status-<?= e($application['status']) ?>">
            <?= e(workflow_status_label($application['phase_status'])) ?>
        </span>
    </div>

    <?php if ($meetingRow && $minutesSaved): ?>
    <div class="gov-card p-3 mb-4" style="border-left:4px solid #1d6aad;background:#f4f8fc;">
        <p class="eyebrow mb-1" style="font-size:.62rem;">TWG Minutes of Meeting</p>
        <p class="mb-0 small text-secondary">
            Meeting minutes have been saved
            <?php if (!empty($meetingRow['scheduled_at'])): ?>
                for the meeting scheduled on <strong><?= e(date('F j, Y · h:i A', strtotime($meetingRow['scheduled_at']))) ?></strong>.
            <?php else: ?>
                for this application.
            <?php endif; ?>
            The committee findings field below has been pre-filled from the minutes.
        </p>
    </div>
    <?php endif; ?>

    <form class="res-card" method="post" id="resolutionForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">
        <input type="hidden" name="action" value="generate_resolution">

        <!-- Section 1: Session & Legislative Body -->
        <p class="res-section-title">Session &amp; Legislative Body</p>
        <div class="res-form-row mb-3">
            <div>
                <label class="res-field-label" for="session_type">Session Type</label>
                <select class="res-control" id="session_type" name="session_type">
                    <option value="Regular Session">Regular Session</option>
                    <option value="Special Session">Special Session</option>
                </select>
            </div>
            <div>
                <label class="res-field-label" for="year">Year</label>
                <input class="res-control" id="year" name="year" type="number" value="<?= date('Y') ?>" min="2000" max="2099">
            </div>
            <div>
                <label class="res-field-label" for="legislative_body">Legislative Body</label>
                <input class="res-control" id="legislative_body" name="legislative_body" value="Sangguniang Panlungsod" placeholder="e.g. Sangguniang Panlungsod">
            </div>
            <div>
                <label class="res-field-label" for="city">City / Municipality</label>
                <input class="res-control" id="city" name="city" value="Davao City" placeholder="e.g. Davao City">
            </div>
        </div>

        <!-- Section 2: Resolution Reference -->
        <p class="res-section-title">Resolution Reference</p>
        <div class="res-form-row mb-3">
            <div>
                <label class="res-field-label" for="resolution_number">Resolution Number</label>
                <input class="res-control" id="resolution_number" name="resolution_number" placeholder="e.g. 0123-S-2025">
            </div>
            <div>
                <label class="res-field-label" for="item_number">Item Number</label>
                <input class="res-control" id="item_number" name="item_number" placeholder="e.g. 42">
            </div>
        </div>

        <!-- Section 3: Property & Applicant -->
        <p class="res-section-title">Property &amp; Applicant Details</p>
        <div class="mb-3">
            <label class="res-field-label" for="applicant_name">Applicant / Association / Owner Name</label>
            <input class="res-control" id="applicant_name" name="applicant_name" value="<?= e($application['account_name']) ?>">
        </div>
        <div class="res-form-row mb-3">
            <div>
                <label class="res-field-label" for="lot_area">Lot Area (sqm)</label>
                <input class="res-control" id="lot_area" name="lot_area"
                       value="<?= $application['lot_area'] ? e(number_format((float)$application['lot_area'], 2)) : '' ?>"
                       placeholder="e.g. 500.00">
            </div>
            <div>
                <label class="res-field-label" for="property_reference">Property Reference (Title / Tax Dec / Lot No.)</label>
                <input class="res-control" id="property_reference" name="property_reference"
                       value="<?= e($application['property_title']) ?>"
                       placeholder="e.g. TCT No. T-123456">
            </div>
        </div>
        <div class="mb-3">
            <label class="res-field-label" for="barangay">Barangay &amp; City Location</label>
            <input class="res-control" id="barangay" name="barangay"
                   value="<?= e($application['property_address']) ?>"
                   placeholder="e.g. Barangay Matina Aplaya, Davao City">
        </div>
        <div class="res-form-row mb-3">
            <div>
                <label class="res-field-label" for="current_zone">Current Zoning Classification</label>
                <input class="res-control" id="current_zone" name="current_zone"
                       value="<?= e($defaultCurrentZone) ?>"
                       placeholder="e.g. Agricultural Zone (A)">
            </div>
            <div>
                <label class="res-field-label" for="proposed_zone">Proposed Zoning Classification</label>
                <input class="res-control" id="proposed_zone" name="proposed_zone"
                       value="<?= e($application['type_of_project'] ?? '') ?>"
                       placeholder="e.g. Residential Zone (R-2)">
            </div>
        </div>

        <!-- Section 4: Purpose & Findings -->
        <p class="res-section-title">Purpose &amp; Committee Findings</p>
        <div class="mb-3">
            <label class="res-field-label" for="purpose">Purpose / Justification for Reclassification</label>
            <textarea class="res-control" id="purpose" name="purpose" rows="3"
                      placeholder="Describe the intended use and reason for reclassification."><?= e($application['type_of_project'] ?? '') ?></textarea>
        </div>
        <div class="mb-3">
            <label class="res-field-label" for="committee_findings">Meeting / Committee Findings</label>
            <textarea class="res-control" id="committee_findings" name="committee_findings" rows="5"
                      placeholder="Summarize the TWG inspection findings, meeting deliberation results, and committee recommendation."><?= e($defaultFindings) ?></textarea>
        </div>

        <!-- Section 5: Date & Signatories -->
        <p class="res-section-title">Date &amp; Signatories</p>
        <div class="mb-3">
            <label class="res-field-label" for="date_approved">Date of Approval / Execution</label>
            <input class="res-control" id="date_approved" name="date_approved"
                   value="<?= e(date('jS day of F Y')) ?>"
                   placeholder="e.g. 15th day of May 2025">
        </div>
        <div class="res-form-row mb-3">
            <div>
                <label class="res-field-label" for="sponsoring_official">Sponsoring / Presenting Official</label>
                <input class="res-control" id="sponsoring_official" name="sponsoring_official"
                       placeholder="Full name of the sponsoring councilor">
            </div>
            <div>
                <label class="res-field-label" for="official_title">Official Title</label>
                <input class="res-control" id="official_title" name="official_title"
                       value="City Councilor" placeholder="e.g. City Councilor">
            </div>
        </div>
        <div class="mb-4">
            <label class="res-field-label" for="committee_name">Committee Name</label>
            <input class="res-control" id="committee_name" name="committee_name"
                   value="Committee on Land Use and Zoning"
                   placeholder="e.g. Committee on Land Use and Zoning">
        </div>

        <!-- Preview -->
        <p class="res-section-title">Document Preview</p>
        <div class="res-preview-box mb-4" id="resPreview">Fill in the fields above and click "Preview" to see the resolution text.</div>

        <div class="d-flex gap-3 flex-wrap">
            <button type="button" class="res-btn res-btn-outline" id="previewBtn">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"/><path d="M0 8s3-5.5 8-5.5S16 8 16 8s-3 5.5-8 5.5S0 8 0 8zm8 3.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/></svg>
                Preview Resolution
            </button>
            <button type="submit" class="res-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>
                Download Resolution PDF
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    var previewBtn = document.getElementById('previewBtn');
    var previewBox = document.getElementById('resPreview');
    var form       = document.getElementById('resolutionForm');
    if (!previewBtn || !previewBox || !form) return;

    function getVal(name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? el.value.trim() : '';
    }

    function ph(val, label) {
        return val !== '' ? val : '[' + label.toUpperCase() + ']';
    }

    previewBtn.addEventListener('click', function () {
        var sessionType  = getVal('session_type')  || 'Regular Session';
        var year         = getVal('year')           || '<?= date('Y') ?>';
        var legBody      = getVal('legislative_body') || 'Sangguniang Panlungsod';
        var city         = getVal('city')           || 'Davao City';
        var resNo        = ph(getVal('resolution_number'), 'Resolution Number');
        var itemNo       = ph(getVal('item_number'), 'Item Number');
        var applicant    = ph(getVal('applicant_name'), 'Applicant Name');
        var lotArea      = ph(getVal('lot_area'), 'Lot Area');
        var propRef      = ph(getVal('property_reference'), 'Property Reference');
        var barangay     = ph(getVal('barangay'), 'Barangay');
        var curZone      = ph(getVal('current_zone'), 'Current Zone');
        var propZone     = ph(getVal('proposed_zone'), 'Proposed Zone');
        var purpose      = ph(getVal('purpose'), 'Purpose');
        var findings     = ph(getVal('committee_findings'), 'Committee Findings');
        var dateApproved = ph(getVal('date_approved'), 'Date Approved');
        var sponsoring   = ph(getVal('sponsoring_official'), 'Sponsoring Official');
        var offTitle     = ph(getVal('official_title'), 'Official Title');
        var committee    = ph(getVal('committee_name'), 'Committee Name');

        var title = 'TO ENACT AN ORDINANCE GRANTING THE APPLICATION OF '
            + applicant.toUpperCase()
            + ' FOR RECLASSIFICATION OF A ' + lotArea.toUpperCase()
            + ' SQUARE METERS MORE OR LESS PARCEL OF LAND COVERED BY ' + propRef.toUpperCase()
            + ' SITUATED AT ' + barangay.toUpperCase() + ', ' + city.toUpperCase()
            + ' FROM ' + curZone.toUpperCase()
            + ' TO ' + propZone.toUpperCase() + '.';

        var lines = [
            sessionType.toUpperCase() + ' SESSION',
            'Series of ' + year,
            '',
            'REPUBLIKA NG PILIPINAS',
            city.toUpperCase(),
            legBody.toUpperCase(),
            '',
            '------------------------------------------------------------------------',
            '',
            'RESOLUTION NO. ' + resNo,
            'Series of ' + year,
            '',
            '------------------------------------------------------------------------',
            '',
            title,
            '',
            '------------------------------------------------------------------------',
            '',
            'WHEREAS, ' + applicant + ' has filed a formal application with the City Planning',
            '  and Development Office (CPDO) of ' + city + ' for the reclassification of a',
            '  parcel of land from ' + curZone + ' to ' + propZone + ';',
            '',
            'WHEREAS, the subject property contains an area of ' + lotArea + ' square meters,',
            '  more or less, covered by ' + propRef + ', situated at ' + barangay + ', ' + city + ';',
            '',
            'WHEREAS, the Applicant seeks reclassification for the following purpose: ' + purpose + ';',
            '',
            'WHEREAS, the LZRC TWG conducted a field inspection and found: ' + findings + ';',
            '',
            'WHEREAS, the application has been duly reviewed and endorsed by the CPDO;',
            '',
            '------------------------------------------------------------------------',
            '',
            'NOW THEREFORE, BE IT RESOLVED AS IT IS HEREBY RESOLVED, TO ENACT AN ORDINANCE',
            'GRANTING THE APPLICATION OF ' + applicant.toUpperCase(),
            'FOR RECLASSIFICATION OF ' + lotArea.toUpperCase() + ' SQUARE METERS MORE OR LESS',
            'PARCEL OF LAND COVERED BY ' + propRef.toUpperCase(),
            'FROM ' + curZone.toUpperCase() + ' TO ' + propZone.toUpperCase(),
            'SITUATED AT ' + barangay.toUpperCase() + ', ' + city.toUpperCase() + '.',
            '',
            '------------------------------------------------------------------------',
            '',
            'Done this ' + dateApproved + ', at ' + city + ', Philippines.',
            '',
            '',
            '                                        ' + sponsoring.toUpperCase(),
            '                                        ' + offTitle,
            '                                        Chairperson, ' + committee,
            '',
            '------------------------------------------------------------------------',
            'Item No. ' + itemNo + ', Resolution     Page 1 of [TOTAL PAGES]',
        ];

        previewBox.textContent = lines.join('\n');
    });
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
