<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);
verify_csrf();

if (!function_exists('twg_inspection_checklist')) {
    function twg_inspection_checklist(): array
    {
        return [
            'Pre-Inspection Verification' => [
                'registry_project_verified' => [
                    'label' => 'Registry number, property title, and project location were verified on site.',
                    'help'  => 'Confirm that the field inspection is for the correct application and property.',
                ],
                'applicant_or_rep_present' => [
                    'label' => 'Applicant, owner, landlord, or authorized representative was available or reachable.',
                    'help'  => 'Record if the applicant/representative was present, absent, or represented by another person.',
                ],
                'submitted_documents_reviewed' => [
                    'label' => 'Submitted plans, lot documents, and application details were reviewed before or during inspection.',
                    'help'  => 'Use this to confirm the TWG member checked the application package before recording findings.',
                ],
            ],
            'Location, Lot, and Boundary Validation' => [
                'location_matches_vicinity_map' => [
                    'label' => 'Actual site location matches the submitted vicinity map or location plan.',
                    'help'  => 'Check whether the inspected location is the same as the mapped and declared project site.',
                ],
                'coordinates_validated' => [
                    'label' => 'Coordinates were validated on site.',
                    'help'  => 'Compare observed GPS/location data with the submitted coordinates or pinned map.',
                ],
                'lot_boundaries_identifiable' => [
                    'label' => 'Lot boundaries, access points, frontage, or visible markers were identifiable.',
                    'help'  => 'Note if boundaries are unclear, blocked, disputed, or require geodetic confirmation.',
                ],
                'road_right_of_way_observed' => [
                    'label' => 'Road-right-of-way, alley, frontage, or legal access was observed.',
                    'help'  => 'Important for site accessibility, emergency access, and development feasibility.',
                ],
            ],
            'Site Plan and Zoning Conformance' => [
                'site_plan_matches_uploaded_documents' => [
                    'label' => 'Site development plan appears consistent with actual site conditions.',
                    'help'  => 'Compare submitted site plan with visible structures, open areas, access, and layout.',
                ],
                'existing_land_use_observed' => [
                    'label' => 'Existing land use and surrounding use were observed and documented.',
                    'help'  => 'Confirm whether the actual land use aligns with the declared use and zoning context.',
                ],
                'setbacks_open_space_checked' => [
                    'label' => 'Setbacks, open spaces, building line, or spacing from property line were checked where visible.',
                    'help'  => 'Use visual inspection only; exact measurements may require technical survey.',
                ],
                'parking_loading_access_checked' => [
                    'label' => 'Parking, loading, driveway, access circulation, or entry/exit condition was checked where applicable.',
                    'help'  => 'Especially useful for commercial, rental, multi-unit, or traffic-generating properties.',
                ],
            ],
            'Site Conditions and Constraints' => [
                'drainage_flooding_observed' => [
                    'label' => 'Drainage, flooding risk, slope, erosion, or waterway concerns were observed.',
                    'help'  => 'Record visible ponding, drainage obstruction, steep slope, erosion, canal, creek, or flood-prone condition.',
                ],
                'utilities_easements_observed' => [
                    'label' => 'Utilities, easements, overhead lines, septic/well areas, or restricted zones were noted if visible.',
                    'help'  => 'Mark not applicable if no utility/easement issue was visible during inspection.',
                ],
                'environmental_safety_risks' => [
                    'label' => 'Environmental, safety, obstruction, or access risks were checked.',
                    'help'  => 'Include blocked access, unsafe structures, hazards, encroachments, or site conditions affecting inspection.',
                ],
                'neighboring_uses_compatibility' => [
                    'label' => 'Adjacent or neighboring land uses were checked for compatibility concerns.',
                    'help'  => 'Note sensitive adjacent uses such as schools, residential areas, industrial uses, waterways, or protected areas.',
                ],
            ],
            'Evidence and Documentation' => [
                'photos_taken' => [
                    'label' => 'Site photos were taken and documented.',
                    'help'  => 'Recommended photos: frontage, road access/RROW, lot view, adjacent uses, existing structures, and issue areas.',
                ],
                'field_notes_completed' => [
                    'label' => 'Field notes, observations, and compliance issues were recorded clearly.',
                    'help'  => 'Findings should be understandable by the LZRC TWG during meeting or deliberation.',
                ],
                'issues_recommendations_recorded' => [
                    'label' => 'Issues, corrections, or recommendations were recorded if any.',
                    'help'  => 'Use remarks to specify required correction, clarification, or follow-up validation.',
                ],
            ],
        ];
    }
}

if (!function_exists('twg_status_label')) {
    function twg_status_label(string $status): string
    {
        return match ($status) {
            'compliant'      => 'Compliant',
            'minor_issue'    => 'Minor Issue',
            'non_compliant'  => 'Non-Compliant',
            'not_applicable' => 'N/A',
            default          => 'Not Answered',
        };
    }
}

if (!function_exists('twg_format_datetime')) {
    function twg_format_datetime(?string $value): string
    {
        if (!$value) return 'No schedule found';
        $ts = strtotime($value);
        return $ts ? date('M d, Y · h:i A', $ts) : $value;
    }
}

if (!function_exists('twg_build_inspection_report')) {
    function twg_build_inspection_report(array $post, array $checklist): string
    {
        $generalFindings  = trim($post['findings'] ?? '');
        $checklistValues  = is_array($post['checklist'] ?? null) ? $post['checklist'] : [];
        $remarksValues    = is_array($post['checklist_remarks'] ?? null) ? $post['checklist_remarks'] : [];
        $recommendation   = $post['recommendation'] ?? 'for_meeting';
        $recommendationLabel = match ($recommendation) {
            'for_meeting'      => 'For LZRC TWG Meeting / Consolidation',
            'for_correction'   => 'For Applicant Correction / Clarification',
            'for_reinspection' => 'For Re-Inspection',
            'not_recommended'  => 'Not Recommended Based on Field Findings',
            default            => 'For LZRC TWG Meeting / Consolidation',
        };
        $fieldInspector   = trim($post['field_inspector'] ?? '');
        $inspectionStart  = trim($post['inspection_start_time'] ?? '');
        $inspectionEnd    = trim($post['inspection_end_time'] ?? '');
        $weatherCondition = trim($post['weather_condition'] ?? '');
        $actualAddress    = trim($post['actual_site_address'] ?? '');
        $observedCoords   = trim($post['observed_coordinates'] ?? '');
        $personsPresent   = trim($post['persons_present'] ?? '');
        $photoCount       = trim($post['photo_count'] ?? '');
        $siteAccessNotes  = trim($post['site_access_notes'] ?? '');
        $safetyNotes      = trim($post['safety_notes'] ?? '');

        $lines = [];
        $lines[] = 'LZRC TWG FIELD INSPECTION REPORT';
        $lines[] = 'Generated: ' . date('Y-m-d H:i:s');
        $lines[] = '';
        $lines[] = 'FIELD INSPECTION DETAILS';
        $lines[] = '- Field Inspector / TWG Member: ' . ($fieldInspector !== '' ? $fieldInspector : 'Not specified');
        $lines[] = '- Inspection Start Time: '        . ($inspectionStart  !== '' ? $inspectionStart  : 'Not specified');
        $lines[] = '- Inspection End Time: '          . ($inspectionEnd    !== '' ? $inspectionEnd    : 'Not specified');
        $lines[] = '- Weather / Site Condition: '     . ($weatherCondition !== '' ? $weatherCondition : 'Not specified');
        $lines[] = '- Actual Site Address Observed: ' . ($actualAddress    !== '' ? $actualAddress    : 'Not specified');
        $lines[] = '- Observed Coordinates: '         . ($observedCoords   !== '' ? $observedCoords   : 'Not specified');
        $lines[] = '- Persons Present: '              . ($personsPresent   !== '' ? $personsPresent   : 'Not specified');
        $lines[] = '- Number of Site Photos Taken: '  . ($photoCount       !== '' ? $photoCount       : 'Not specified');
        $lines[] = '';
        $lines[] = 'INSPECTION CHECKLIST';
        foreach ($checklist as $groupName => $items) {
            $lines[] = '';
            $lines[] = strtoupper($groupName);
            foreach ($items as $key => $item) {
                $status = $checklistValues[$key] ?? '';
                $remark = trim($remarksValues[$key] ?? '');
                $lines[] = '- ' . $item['label'];
                $lines[] = '  Status: ' . twg_status_label($status);
                if ($remark !== '') {
                    $lines[] = '  Remarks: ' . $remark;
                }
            }
        }
        $lines[] = '';
        $lines[] = 'SITE ACCESS NOTES';
        $lines[] = $siteAccessNotes !== '' ? $siteAccessNotes : 'No specific site access notes recorded.';
        $lines[] = '';
        $lines[] = 'SAFETY / ENVIRONMENTAL / OBSTRUCTION NOTES';
        $lines[] = $safetyNotes !== '' ? $safetyNotes : 'No specific safety or environmental notes recorded.';
        $lines[] = '';
        $lines[] = 'GENERAL INSPECTION FINDINGS';
        $lines[] = $generalFindings !== '' ? $generalFindings : 'No additional general findings recorded.';
        $lines[] = '';
        $lines[] = 'TWG RECOMMENDATION';
        $lines[] = $recommendationLabel;

        return trim(implode("\n", $lines));
    }
}

$checklist     = twg_inspection_checklist();
$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$applicationId) {
        $_SESSION['flash_error'] = 'No application selected.';
        redirect('twg/inspection.php');
    }
    $application = officer_application($applicationId);
    $inspection  = latest_inspection_for_application($applicationId);

    if (!$inspection) {
        $_SESSION['flash_error'] = 'Inspection must be scheduled before findings can be entered.';
        redirect('twg/inspection.php?id=' . $applicationId);
    }

    $compiledFindings = twg_build_inspection_report($_POST, $checklist);
    $sitePlanValid    = !empty($_POST['site_plan_valid'])
        || (($_POST['checklist']['site_plan_matches_uploaded_documents'] ?? '') === 'compliant');
    $coordinatesValid = !empty($_POST['coordinates_valid'])
        || (($_POST['checklist']['coordinates_validated'] ?? '') === 'compliant');

    $stmt = db()->prepare(
        'UPDATE inspections SET findings = ?, site_plan_valid = ?, coordinates_valid = ?, finalized_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$compiledFindings, $sitePlanValid ? 1 : 0, $coordinatesValid ? 1 : 0, (int)$inspection['id']]);

    advance_application($applicationId, 'FOR_MEETING', 10);
    notify_user((int)$application['landlord_id'], $applicationId, 'Site Inspection Completed', 'The site inspection for your application has been completed. Your application is now being scheduled for a TWG meeting.');
    audit_log((int)$user['id'], 'INSPECTION_REPORT_ENTERED', 'applications', $applicationId);
    $_SESSION['flash_success'] = 'Inspection checklist and findings saved. Application moved to consolidation/meeting.';
    redirect('twg/inspection.php?id=' . $applicationId);
}

$applications = officer_applications(['INSPECTION_SCHEDULED']);
$application  = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$inspection   = $application ? latest_inspection_for_application((int)$application['id']) : null;

require __DIR__ . '/../partials/header.php';
?>

<style>
:root{--twg-navy:#0d3154;--twg-navy-dark:#08233d;--twg-blue:#0d6efd;--twg-blue-soft:#eaf3ff;--twg-gold:#f6c343;--twg-green:#198754;--twg-green-soft:#eafaf1;--twg-orange:#b56a00;--twg-orange-soft:#fff4d6;--twg-red:#dc3545;--twg-red-soft:#fff0f0;--twg-text:#172033;--twg-muted:#6b7280;--twg-border:#d8e2ef;}
body.twg-inspection-page{background:radial-gradient(circle at 1px 1px,rgba(13,110,253,.16) 1px,transparent 0),linear-gradient(180deg,#f7fbff 0%,#edf3fa 100%);background-size:28px 28px,100% 100%;color:var(--twg-text);}
.twg-shell{max-width:1180px;margin:0 auto;padding:32px 18px 56px;}
.twg-hero{position:relative;overflow:hidden;border-radius:24px;padding:30px;margin-bottom:24px;background:radial-gradient(circle at top right,rgba(246,195,67,.28),transparent 35%),linear-gradient(135deg,#fff 0%,#f5f9ff 100%);border:1px solid var(--twg-border);box-shadow:0 18px 42px rgba(13,49,84,.10);}
.twg-hero::before{content:"";position:absolute;top:-90px;right:-90px;width:230px;height:230px;border-radius:50%;background:rgba(13,110,253,.08);pointer-events:none;}
.twg-hero-content{position:relative;z-index:1;}
.twg-eyebrow{display:inline-flex;align-items:center;gap:8px;margin-bottom:10px;padding:6px 12px;border-radius:999px;background:var(--twg-blue-soft);color:var(--twg-blue);font-size:.72rem;font-weight:900;letter-spacing:.09em;text-transform:uppercase;}
.twg-eyebrow-dot{width:7px;height:7px;border-radius:50%;background:var(--twg-gold);box-shadow:0 0 0 4px rgba(246,195,67,.22);}
.twg-title{margin:0;color:#071d35;font-size:clamp(1.75rem,4vw,2.55rem);font-weight:900;line-height:1.05;letter-spacing:-.04em;}
.twg-subtitle{max-width:780px;margin:12px 0 0;color:var(--twg-muted);font-size:.98rem;font-weight:500;line-height:1.7;}
.twg-layout{display:grid;grid-template-columns:360px minmax(0,1fr);gap:24px;align-items:start;}
.twg-panel{overflow:hidden;border-radius:20px;background:rgba(255,255,255,.96);border:1px solid var(--twg-border);box-shadow:0 18px 42px rgba(13,49,84,.10);}
.twg-panel-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:20px 22px;border-bottom:1px solid var(--twg-border);background:radial-gradient(circle at top right,rgba(13,110,253,.08),transparent 30%),#fff;}
.twg-panel-title{margin:0;color:#071d35;font-size:1rem;font-weight:900;letter-spacing:-.02em;}
.twg-panel-sub{margin:4px 0 0;color:var(--twg-muted);font-size:.8rem;line-height:1.5;font-weight:500;}
.twg-panel-body{padding:20px 22px;}
.twg-list{display:grid;gap:10px;}
.twg-app-link{display:block;padding:14px;border-radius:14px;border:1px solid #edf2f8;background:#fff;color:inherit;text-decoration:none;transition:transform .18s,border-color .18s,box-shadow .18s;}
.twg-app-link:hover,.twg-app-link.is-active{color:inherit;transform:translateY(-1px);border-color:#b7cce5;box-shadow:0 12px 24px rgba(13,49,84,.10);}
.twg-app-link.is-active{background:linear-gradient(135deg,#eaf3ff 0%,#fff 100%);}
.twg-registry{display:flex;align-items:center;gap:8px;color:#071d35;font-family:ui-monospace,monospace;font-size:.78rem;font-weight:900;margin-bottom:5px;}
.twg-registry::before{content:"";width:8px;height:8px;flex:0 0 8px;border-radius:50%;background:var(--twg-blue);box-shadow:0 0 0 4px rgba(13,110,253,.13);}
.twg-app-title{margin:0;color:var(--twg-text);font-size:.88rem;font-weight:800;line-height:1.35;}
.twg-mini-badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 9px;border-radius:999px;font-size:.7rem;font-weight:900;line-height:1;white-space:nowrap;background:var(--twg-blue-soft);color:var(--twg-blue);}
.twg-status-badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 9px;border-radius:999px;font-size:.7rem;font-weight:900;line-height:1;white-space:nowrap;margin-top:9px;background:var(--twg-orange-soft);color:var(--twg-orange);}
.twg-empty{padding:34px 16px;text-align:center;color:var(--twg-muted);font-size:.86rem;line-height:1.6;}
.twg-report-card{padding:24px;}
.twg-report-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:22px;}
.twg-report-title{margin:0;color:#071d35;font-size:1.25rem;font-weight:900;letter-spacing:-.03em;line-height:1.25;}
.twg-report-sub{margin:6px 0 0;color:var(--twg-muted);font-size:.86rem;line-height:1.55;font-weight:550;}
.twg-section{margin-top:24px;padding-top:22px;border-top:1px solid var(--twg-border);}
.twg-section:first-of-type{margin-top:0;padding-top:0;border-top:0;}
.twg-section-title{display:flex;align-items:center;gap:9px;margin:0 0 14px;color:#071d35;font-size:.98rem;font-weight:900;letter-spacing:-.01em;}
.twg-section-title::before{content:"";width:9px;height:9px;border-radius:50%;background:var(--twg-gold);box-shadow:0 0 0 4px rgba(246,195,67,.18);}
.twg-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;}
.twg-form-grid--three{grid-template-columns:repeat(3,minmax(0,1fr));}
.twg-field-label{display:block;margin-bottom:7px;color:#071d35;font-size:.78rem;font-weight:900;}
.twg-control{width:100%;min-height:42px;padding:10px 12px;border-radius:12px;border:1px solid var(--twg-border);background:#fff;color:var(--twg-text);font-size:.88rem;font-weight:650;outline:none;transition:border-color .16s,box-shadow .16s;}
.twg-control:focus{border-color:var(--twg-blue);box-shadow:0 0 0 4px rgba(13,110,253,.12);}
textarea.twg-control{min-height:130px;resize:vertical;line-height:1.6;}
.twg-checklist-group{margin-top:18px;border-radius:18px;border:1px solid #edf2f8;background:#fff;overflow:hidden;}
.twg-checklist-group-header{padding:15px 18px;background:radial-gradient(circle at top right,rgba(246,195,67,.16),transparent 30%),#f8fbff;border-bottom:1px solid #edf2f8;}
.twg-checklist-group-title{margin:0;color:#071d35;font-size:.9rem;font-weight:900;}
.twg-checklist-item{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(360px,1fr);gap:16px;padding:16px 18px;border-bottom:1px solid #edf2f8;}
.twg-checklist-item:last-child{border-bottom:0;}
.twg-check-label{margin:0 0 5px;color:var(--twg-text);font-size:.88rem;font-weight:850;line-height:1.45;}
.twg-check-help{margin:0;color:var(--twg-muted);font-size:.78rem;line-height:1.55;font-weight:500;}
.twg-radio-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:10px;}
.twg-radio-pill{position:relative;display:flex;align-items:center;gap:7px;min-height:36px;padding:8px 10px;border-radius:999px;border:1px solid var(--twg-border);background:#fff;color:var(--twg-muted);font-size:.74rem;font-weight:900;cursor:pointer;user-select:none;transition:background .16s,color .16s,border-color .16s;}
.twg-radio-pill input{width:14px;height:14px;margin:0;}
.twg-radio-pill:has(input:checked){border-color:var(--twg-blue);background:var(--twg-blue-soft);color:var(--twg-blue);}
.twg-radio-pill--ok:has(input:checked){border-color:#9bd6b4;background:var(--twg-green-soft);color:var(--twg-green);}
.twg-radio-pill--warning:has(input:checked){border-color:#ffd27a;background:var(--twg-orange-soft);color:var(--twg-orange);}
.twg-radio-pill--danger:has(input:checked){border-color:#f5b5b5;background:var(--twg-red-soft);color:var(--twg-red);}
.twg-remark{width:100%;min-height:38px;padding:9px 11px;border-radius:11px;border:1px solid var(--twg-border);background:#f8fbff;color:var(--twg-text);font-size:.8rem;font-weight:600;outline:none;}
.twg-core-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
.twg-core-check{display:flex;align-items:flex-start;gap:10px;padding:14px;border-radius:14px;border:1px solid #edf2f8;background:#f8fbff;}
.twg-core-check input{margin-top:3px;}
.twg-core-check strong{display:block;color:#071d35;font-size:.84rem;font-weight:900;margin-bottom:3px;}
.twg-core-check span{display:block;color:var(--twg-muted);font-size:.76rem;line-height:1.45;font-weight:500;}
.twg-progress-card{display:grid;gap:8px;padding:15px;border-radius:16px;background:#f8fbff;border:1px solid #edf2f8;margin-bottom:18px;}
.twg-progress-row{display:flex;align-items:center;justify-content:space-between;gap:12px;color:#071d35;font-size:.82rem;font-weight:900;}
.twg-progress-track{height:8px;border-radius:999px;background:#e6eef8;overflow:hidden;}
.twg-progress-fill{width:0;height:100%;background:linear-gradient(90deg,var(--twg-blue),var(--twg-green));border-radius:inherit;transition:width .2s ease;}
.twg-submit-row{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-top:24px;padding-top:20px;border-top:1px solid var(--twg-border);}
.twg-btn-primary{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:11px 18px;border-radius:12px;background:var(--twg-navy);color:#fff;border:1px solid var(--twg-navy);font-size:.84rem;font-weight:900;text-decoration:none;box-shadow:0 10px 22px rgba(13,49,84,.18);transition:transform .18s,background .18s,box-shadow .18s;}
.twg-btn-primary:hover{background:var(--twg-navy-dark);color:#fff;transform:translateY(-1px);box-shadow:0 14px 26px rgba(13,49,84,.24);}
.twg-note{color:var(--twg-muted);font-size:.82rem;line-height:1.55;font-weight:550;}
.twg-print-panel{margin-top:16px;padding:14px;border-radius:14px;background:#fffdf3;border:1px solid #f1dc9a;color:#7a5a00;font-size:.8rem;line-height:1.55;font-weight:650;}
@media(max-width:1100px){.twg-layout{grid-template-columns:1fr;}}
@media(max-width:900px){.twg-checklist-item{grid-template-columns:1fr;}.twg-form-grid,.twg-form-grid--three,.twg-core-checks{grid-template-columns:1fr;}}
@media(max-width:575.98px){.twg-shell{padding:24px 14px 44px;}.twg-hero{padding:22px;}.twg-report-card{padding:18px;}.twg-radio-grid{grid-template-columns:1fr;}}
@media print{header,nav,.twg-hero,.twg-panel:first-child,.twg-submit-row,.twg-print-panel{display:none !important;}body{background:#fff !important;}.twg-shell{max-width:none;padding:0;}.twg-layout{display:block;}.twg-panel{box-shadow:none;border:0;}.twg-checklist-item{break-inside:avoid;}}
.photo-dropzone{border:2px dashed var(--twg-border);border-radius:18px;background:#f8fbff;padding:28px 20px;text-align:center;cursor:pointer;transition:border-color .18s,background .18s;margin-bottom:18px;}
.photo-dropzone.is-dragover{border-color:var(--twg-blue);background:var(--twg-blue-soft);}
.photo-dropzone-inner{display:flex;flex-direction:column;align-items:center;gap:4px;}
.twg-btn-upload{display:inline-flex;align-items:center;gap:7px;margin-top:12px;padding:9px 18px;border-radius:999px;border:1px solid var(--twg-blue);background:var(--twg-blue-soft);color:var(--twg-blue);font-size:.8rem;font-weight:900;cursor:pointer;transition:background .16s,color .16s;}
.twg-btn-upload:hover{background:var(--twg-blue);color:#fff;}
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-top:4px;}
.photo-card{border-radius:14px;overflow:hidden;border:1px solid var(--twg-border);background:#fff;box-shadow:0 4px 12px rgba(13,49,84,.08);transition:transform .18s,box-shadow .18s;}
.photo-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(13,49,84,.12);}
.photo-card-img-wrap{position:relative;aspect-ratio:4/3;background:#edf2f7;overflow:hidden;}
.photo-card-img-wrap img{width:100%;height:100%;object-fit:cover;display:block;}
.photo-card-delete{position:absolute;top:6px;right:6px;width:24px;height:24px;border-radius:50%;background:rgba(220,53,69,.85);border:none;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:0;transition:opacity .16s;backdrop-filter:blur(4px);}
.photo-card:hover .photo-card-delete{opacity:1;}
.photo-card-meta{padding:8px 10px;}
.photo-card-category{display:block;font-size:.68rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:var(--twg-blue);margin-bottom:2px;}
.photo-card-caption{display:block;font-size:.74rem;color:var(--twg-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.photo-card-uploading{opacity:.6;pointer-events:none;}
.photo-card-uploading .photo-card-img-wrap::after{content:"Uploading…";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.7);font-size:.78rem;font-weight:800;color:var(--twg-blue);}
@media(max-width:575.98px){.photo-grid{grid-template-columns:repeat(auto-fill,minmax(120px,1fr));}}
@media print{#photo-section{break-before:page;}.photo-card-delete{display:none !important;}}
</style>
<script>document.body.classList.add('twg-inspection-page');</script>

<div class="twg-shell">
    <section class="twg-hero">
        <div class="twg-hero-content">
            <div class="twg-eyebrow"><span class="twg-eyebrow-dot"></span>LZRC TWG Field Validation</div>
            <h1 class="twg-title">Inspection Report Entry</h1>
            <p class="twg-subtitle">Complete the on-site land inspection checklist, record field observations, validate uploaded plans against actual site conditions, and submit the report for LZRC TWG consolidation or meeting.</p>
        </div>
    </section>

    <div class="twg-layout">
        <aside class="twg-panel">
            <div class="twg-panel-header">
                <div>
                    <h2 class="twg-panel-title">Scheduled Inspections</h2>
                    <p class="twg-panel-sub">Select an application assigned for field validation.</p>
                </div>
                <span class="twg-mini-badge"><?= number_format(count($applications)) ?></span>
            </div>
            <div class="twg-panel-body">
                <?php if ($applications): ?>
                    <div class="twg-list">
                        <?php foreach ($applications as $row): ?>
                            <a class="twg-app-link <?= $application && (int)$application['id'] === (int)$row['id'] ? 'is-active' : '' ?>"
                               href="inspection.php?id=<?= (int)$row['id'] ?>">
                                <div class="twg-registry"><?= e($row['registry_number']) ?></div>
                                <p class="twg-app-title"><?= e($row['property_title']) ?></p>
                                <span class="twg-status-badge">Scheduled</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="twg-empty">No inspections are currently scheduled.</div>
                <?php endif; ?>
                <div class="twg-print-panel">
                    Tip: The completed checklist is compiled into the inspection findings field — no database migration required.
                </div>
            </div>
        </aside>

        <main>
            <?php if ($application): ?>
                <form class="twg-panel twg-report-card" method="post" id="inspectionForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">

                    <div class="twg-report-head">
                        <div>
                            <h2 class="twg-report-title"><?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?></h2>
                            <p class="twg-report-sub">Scheduled: <?= e(twg_format_datetime($inspection['scheduled_at'] ?? null)) ?></p>
                        </div>
                        <span class="twg-mini-badge">Field Checklist</span>
                    </div>

                    <!-- Field Inspection Details -->
                    <section class="twg-section">
                        <h3 class="twg-section-title">Field Inspection Details</h3>
                        <div class="twg-form-grid twg-form-grid--three">
                            <div>
                                <label class="twg-field-label" for="field_inspector">Field Inspector / TWG Member</label>
                                <input class="twg-control" id="field_inspector" name="field_inspector"
                                       value="<?= e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?>"
                                       placeholder="Inspector name">
                            </div>
                            <div>
                                <label class="twg-field-label" for="inspection_start_time">Start Time</label>
                                <input class="twg-control" id="inspection_start_time" type="time" name="inspection_start_time">
                            </div>
                            <div>
                                <label class="twg-field-label" for="inspection_end_time">End Time</label>
                                <input class="twg-control" id="inspection_end_time" type="time" name="inspection_end_time">
                            </div>
                            <div>
                                <label class="twg-field-label" for="weather_condition">Weather / Site Condition</label>
                                <select class="twg-control" id="weather_condition" name="weather_condition">
                                    <option value="">Select condition</option>
                                    <option value="Clear">Clear</option>
                                    <option value="Cloudy">Cloudy</option>
                                    <option value="Rainy">Rainy</option>
                                    <option value="Muddy / wet ground">Muddy / wet ground</option>
                                    <option value="Limited visibility">Limited visibility</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="twg-field-label" for="observed_coordinates">Observed Coordinates</label>
                                <input class="twg-control" id="observed_coordinates" name="observed_coordinates"
                                       placeholder="e.g. 7.0731, 125.6128">
                            </div>
                            <div>
                                <label class="twg-field-label" for="photo_count">Number of Site Photos Taken</label>
                                <input class="twg-control" id="photo_count" type="number" min="0" name="photo_count" placeholder="0">
                            </div>
                        </div>
                        <div class="twg-form-grid mt-3">
                            <div>
                                <label class="twg-field-label" for="actual_site_address">Actual Site Address Observed</label>
                                <textarea class="twg-control" id="actual_site_address" name="actual_site_address" rows="3"
                                          placeholder="Write the actual address, landmark, or field description observed."><?= e($application['property_address'] ?? '') ?></textarea>
                            </div>
                            <div>
                                <label class="twg-field-label" for="persons_present">Persons Present During Inspection</label>
                                <textarea class="twg-control" id="persons_present" name="persons_present" rows="3"
                                          placeholder="e.g. owner, tenant, barangay representative, engineer, caretaker"></textarea>
                            </div>
                        </div>
                    </section>

                    <!-- Core Validation -->
                    <section class="twg-section">
                        <h3 class="twg-section-title">Core Validation</h3>
                        <div class="twg-core-checks">
                            <label class="twg-core-check">
                                <input type="checkbox" name="site_plan_valid" value="1" <?= !empty($inspection['site_plan_valid']) ? 'checked' : '' ?>>
                                <span>
                                    <strong>Site plan validated</strong>
                                    <span>Submitted site plan appears consistent with actual field condition.</span>
                                </span>
                            </label>
                            <label class="twg-core-check">
                                <input type="checkbox" name="coordinates_valid" value="1" <?= !empty($inspection['coordinates_valid']) ? 'checked' : '' ?>>
                                <span>
                                    <strong>Coordinates validated</strong>
                                    <span>Site coordinates or pinned map location were checked during inspection.</span>
                                </span>
                            </label>
                        </div>
                    </section>

                    <!-- Checklist -->
                    <section class="twg-section">
                        <h3 class="twg-section-title">On-Site Land Inspection Checklist</h3>
                        <div class="twg-progress-card">
                            <div class="twg-progress-row">
                                <span>Checklist Completion</span>
                                <span id="checklistProgressText">0%</span>
                            </div>
                            <div class="twg-progress-track">
                                <div class="twg-progress-fill" id="checklistProgressFill"></div>
                            </div>
                        </div>
                        <?php foreach ($checklist as $groupName => $items): ?>
                            <div class="twg-checklist-group">
                                <div class="twg-checklist-group-header">
                                    <h4 class="twg-checklist-group-title"><?= e($groupName) ?></h4>
                                </div>
                                <?php foreach ($items as $key => $item): ?>
                                    <div class="twg-checklist-item">
                                        <div>
                                            <p class="twg-check-label"><?= e($item['label']) ?></p>
                                            <p class="twg-check-help"><?= e($item['help']) ?></p>
                                        </div>
                                        <div>
                                            <div class="twg-radio-grid">
                                                <label class="twg-radio-pill twg-radio-pill--ok">
                                                    <input type="radio" name="checklist[<?= e($key) ?>]" value="compliant" required>
                                                    Compliant
                                                </label>
                                                <label class="twg-radio-pill twg-radio-pill--warning">
                                                    <input type="radio" name="checklist[<?= e($key) ?>]" value="minor_issue" required>
                                                    Minor Issue
                                                </label>
                                                <label class="twg-radio-pill twg-radio-pill--danger">
                                                    <input type="radio" name="checklist[<?= e($key) ?>]" value="non_compliant" required>
                                                    Non-Compliant
                                                </label>
                                                <label class="twg-radio-pill">
                                                    <input type="radio" name="checklist[<?= e($key) ?>]" value="not_applicable" required>
                                                    N/A
                                                </label>
                                            </div>
                                            <input class="twg-remark" type="text"
                                                   name="checklist_remarks[<?= e($key) ?>]"
                                                   placeholder="Optional remarks, evidence, or issue noted">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>

                    <!-- Narrative Findings -->
                    <section class="twg-section">
                        <h3 class="twg-section-title">Site Access, Safety, and Narrative Findings</h3>
                        <div class="twg-form-grid">
                            <div>
                                <label class="twg-field-label" for="site_access_notes">Site Access Notes</label>
                                <textarea class="twg-control" id="site_access_notes" name="site_access_notes"
                                          placeholder="Describe site access, frontage, RROW/alley, driveway, road condition, or accessibility concerns."></textarea>
                            </div>
                            <div>
                                <label class="twg-field-label" for="safety_notes">Safety / Environmental / Obstruction Notes</label>
                                <textarea class="twg-control" id="safety_notes" name="safety_notes"
                                          placeholder="Describe hazards, obstruction, drainage/flooding issues, slope, encroachment, or site constraints."></textarea>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="twg-field-label" for="findings">General Inspection Findings</label>
                            <textarea class="twg-control" id="findings" name="findings" rows="7" required
                                      placeholder="Write the overall field findings, observations, and TWG notes."><?= e($inspection['findings'] ?? '') ?></textarea>
                        </div>
                        <div class="mt-3">
                            <label class="twg-field-label" for="recommendation">TWG Recommendation</label>
                            <select class="twg-control" id="recommendation" name="recommendation" required>
                                <option value="for_meeting">For LZRC TWG Meeting / Consolidation</option>
                                <option value="for_correction">For Applicant Correction / Clarification</option>
                                <option value="for_reinspection">For Re-Inspection</option>
                                <option value="not_recommended">Not Recommended Based on Field Findings</option>
                            </select>
                        </div>
                    </section>

                    <!-- ── Site Photo Documentation ──────────────────────── -->
                    <section class="twg-section" id="photo-section">
                        <h3 class="twg-section-title">Site Photo Documentation</h3>
                        <p class="twg-note mb-3">Upload site photos as evidence. Recommended: frontage, road access, lot view, adjacent uses, existing structures, and any issue areas. Max 10 MB per photo (JPEG, PNG, WebP).</p>

                        <?php
                        $existingPhotos = [];
                        if ($inspection) {
                            $pStmt = db()->prepare(
                                'SELECT id, caption, category, file_mime, file_size, uploaded_at
                                 FROM inspection_photos
                                 WHERE inspection_id = ?
                                 ORDER BY uploaded_at ASC'
                            );
                            $pStmt->execute([(int)$inspection['id']]);
                            $existingPhotos = $pStmt->fetchAll();
                        }
                        $photoBaseUrl = rtrim($config['app']['base_url'], '/');
                        ?>

                        <div class="photo-dropzone" id="photoDropzone" role="region" aria-label="Photo upload area">
                            <div class="photo-dropzone-inner">
                                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" style="color:var(--twg-blue);margin-bottom:10px;"><path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/><path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2h-12zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1h12z"/></svg>
                                <p style="margin:0 0 6px;font-size:.9rem;font-weight:800;color:#071d35;">Drop photos here or click to browse</p>
                                <p style="margin:0;font-size:.78rem;color:var(--twg-muted);">JPEG, PNG, WebP · Max 10 MB each · Multiple files allowed</p>
                                <input type="file" id="photoFileInput" accept="image/jpeg,image/png,image/webp,image/*" multiple capture="environment" style="display:none;" aria-label="Choose site photos">
                                <button type="button" class="twg-btn-upload" id="photoBrowseBtn">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M15 12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h1.172a3 3 0 0 0 2.12-.879l.83-.828A1 1 0 0 1 6.827 3h2.344a1 1 0 0 1 .707.293l.828.828A3 3 0 0 0 12.828 5H14a1 1 0 0 1 1 1v6zM2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 1 3.172 4H2z"/><path d="M8 11a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5zm0 1a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM3 6.5a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0z"/></svg>
                                    Choose / Take Photo
                                </button>
                            </div>
                        </div>

                        <div class="photo-grid" id="photoGrid">
                            <?php foreach ($existingPhotos as $photo): ?>
                                <div class="photo-card" data-photo-id="<?= (int)$photo['id'] ?>">
                                    <div class="photo-card-img-wrap">
                                        <img src="<?= e($photoBaseUrl) ?>/twg/inspection_photo.php?id=<?= (int)$photo['id'] ?>"
                                             alt="<?= e($photo['caption'] ?: 'Site photo') ?>" loading="lazy">
                                        <button type="button" class="photo-card-delete" data-photo-id="<?= (int)$photo['id'] ?>" aria-label="Delete photo">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16"><path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8 2.146 2.854z"/></svg>
                                        </button>
                                    </div>
                                    <div class="photo-card-meta">
                                        <span class="photo-card-category"><?= e(ucwords(str_replace('_', ' ', $photo['category']))) ?></span>
                                        <?php if ($photo['caption']): ?><span class="photo-card-caption"><?= e($photo['caption']) ?></span><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="photoUploadStatus" style="margin-top:10px;font-size:.82rem;color:var(--twg-muted);"></div>
                    </section>

                    <div class="twg-submit-row">
                        <span class="twg-note">Submitting saves the checklist into the inspection findings and moves the application to the meeting/consolidation stage.</span>
                        <button class="twg-btn-primary" type="submit">Submit Inspection Compliance Report</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="twg-panel">
                    <div class="twg-empty">No inspection selected. Choose an application from the list.</div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('inspectionForm');
    var progressText = document.getElementById('checklistProgressText');
    var progressFill = document.getElementById('checklistProgressFill');
    if (!form || !progressText || !progressFill) return;

    var groups = {};
    form.querySelectorAll('input[type="radio"][name^="checklist["]').forEach(function (radio) {
        groups[radio.name] = true;
    });
    var total = Object.keys(groups).length;

    function updateProgress() {
        var answered = 0;
        Object.keys(groups).forEach(function (name) {
            if (form.querySelector('input[name="' + CSS.escape(name) + '"]:checked')) answered++;
        });
        var pct = total > 0 ? Math.round((answered / total) * 100) : 0;
        progressText.textContent = pct + '%';
        progressFill.style.width = pct + '%';
    }

    form.querySelectorAll('input[type="radio"][name^="checklist["]').forEach(function (radio) {
        radio.addEventListener('change', updateProgress);
    });
    updateProgress();
}());
</script>

<script>
(function () {
    var appId       = <?= (int)($application['id'] ?? 0) ?>;
    var csrfToken   = <?= json_encode(csrf_token()) ?>;
    var uploadUrl   = '../upload_inspection_photo.php';
    var deleteUrl   = '../delete_inspection_photo.php';
    var grid        = document.getElementById('photoGrid');
    var dropzone    = document.getElementById('photoDropzone');
    var fileInput   = document.getElementById('photoFileInput');
    var browseBtn   = document.getElementById('photoBrowseBtn');
    var statusEl    = document.getElementById('photoUploadStatus');
    if (!dropzone || !appId) { return; }
    var categoryLabels = {frontage:'Frontage',road_access:'Road Access',lot_view:'Lot View',adjacent_uses:'Adjacent Uses',existing_structures:'Existing Structures',issue_area:'Issue Area',other:'Other'};
    browseBtn.addEventListener('click', function () { fileInput.click(); });
    dropzone.addEventListener('click', function (e) { if (e.target===browseBtn||browseBtn.contains(e.target)) return; fileInput.click(); });
    dropzone.addEventListener('dragover', function (e) { e.preventDefault(); dropzone.classList.add('is-dragover'); });
    dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('is-dragover'); });
    dropzone.addEventListener('drop', function (e) { e.preventDefault(); dropzone.classList.remove('is-dragover'); uploadFiles(Array.from(e.dataTransfer.files)); });
    fileInput.addEventListener('change', function () { uploadFiles(Array.from(fileInput.files)); fileInput.value=''; });
    function uploadFiles(files) {
        var images = files.filter(function(f){return f.type.startsWith('image/');});
        if (!images.length) { setStatus('No image files selected.','error'); return; }
        setStatus('Uploading '+images.length+' photo(s)…','info');
        var done=0;
        images.forEach(function(file){ uploadOne(file,function(ok){ done++; if(done===images.length) setStatus(ok?'All photos uploaded.':'Some photos failed.',ok?'ok':'error'); }); });
    }
    function uploadOne(file, cb) {
        var ph = buildPlaceholder(file); grid.appendChild(ph);
        var cat = guessCategory(file.name);
        var fd = new FormData();
        fd.append('csrf_token',csrfToken); fd.append('application_id',appId);
        fd.append('photo',file); fd.append('category',cat); fd.append('caption','');
        fetch(uploadUrl,{method:'POST',body:fd,credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(d){ ph.remove(); if(!d.ok) throw new Error(d.message||'Upload failed.'); grid.appendChild(buildCard(d.photo_id,d.thumb_url,cat,'')); cb(true); })
            .catch(function(err){ ph.remove(); setStatus('Failed: '+err.message,'error'); cb(false); });
    }
    function guessCategory(n){var l=n.toLowerCase();if(l.includes('front'))return'frontage';if(l.includes('road')||l.includes('access'))return'road_access';if(l.includes('lot'))return'lot_view';if(l.includes('adj')||l.includes('neighbor'))return'adjacent_uses';if(l.includes('struct')||l.includes('build'))return'existing_structures';if(l.includes('issue')||l.includes('problem'))return'issue_area';return'other';}
    function buildPlaceholder(file){var c=document.createElement('div');c.className='photo-card photo-card-uploading';var w=document.createElement('div');w.className='photo-card-img-wrap';var i=document.createElement('img');i.src=URL.createObjectURL(file);i.alt=file.name;w.appendChild(i);c.appendChild(w);return c;}
    function buildCard(id,url,cat,cap){var c=document.createElement('div');c.className='photo-card';c.dataset.photoId=id;c.innerHTML='<div class="photo-card-img-wrap"><img src="'+esc(url)+'" alt="Site photo" loading="lazy"><button type="button" class="photo-card-delete" data-photo-id="'+id+'" aria-label="Delete photo"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16"><path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8 2.146 2.854z"/></svg></button></div><div class="photo-card-meta"><span class="photo-card-category">'+esc(categoryLabels[cat]||'Other')+'</span>'+(cap?'<span class="photo-card-caption">'+esc(cap)+'</span>':'')+'</div>';return c;}
    grid.addEventListener('click',function(e){var btn=e.target.closest('.photo-card-delete');if(!btn)return;var id=btn.dataset.photoId;if(!confirm('Delete this photo?'))return;var fd=new FormData();fd.append('csrf_token',csrfToken);fd.append('photo_id',id);fetch(deleteUrl,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){if(!d.ok)throw new Error(d.message);var card=grid.querySelector('[data-photo-id="'+id+'"]');if(card)card.remove();setStatus('Photo deleted.','ok');}).catch(function(err){setStatus('Delete failed: '+err.message,'error');});});
    function setStatus(msg,type){if(!statusEl)return;statusEl.textContent=msg;statusEl.style.color=type==='error'?'#dc3545':type==='ok'?'#198754':'#6b7280';if(type!=='error')setTimeout(function(){statusEl.textContent='';},4000);}
    function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
