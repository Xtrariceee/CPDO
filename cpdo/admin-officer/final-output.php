<?php
/**
 * Admin Officer — Generate & Issue Endorsement (Resolution PDF)
 *
 * Generates the official legislative resolution PDF server-side from the
 * application data, saves it to storage, records it in final_outputs, and
 * notifies the applicant so they can download it from their dashboard.
 */
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);

// ── Helper: save resolution PDF to storage and return relative path ───────────
function ao_save_resolution_pdf(array $data, int $applicationId): string
{
    $outDir = __DIR__ . '/../../storage/final_outputs/' . $applicationId;
    if (!is_dir($outDir)) {
        mkdir($outDir, 0775, true);
    }

    $city    = preg_replace('/[^A-Za-z0-9_-]+/', '-', $data['city']    ?? 'city');
    $resNo   = preg_replace('/[^A-Za-z0-9_-]+/', '-', $data['resolution_number'] ?? 'res');
    $year    = preg_replace('/[^0-9]+/', '', $data['year'] ?? date('Y'));
    $filename = 'Resolution-' . trim($resNo, '-') . '-' . trim($city, '-') . '-' . $year . '.pdf';
    $outPath  = $outDir . '/' . $filename;

    // Build the PDF bytes using the same generator but capture instead of stream
    ob_start();
    generate_resolution_pdf($data);   // calls exit internally — use output buffer trick
    // generate_resolution_pdf() calls exit, so we need to capture differently.
    // We rebuild the PDF bytes here using the same logic without streaming.
    ob_end_clean();

    // Re-use build_resolution_text + raw PDF assembly (mirrors resolution_pdf.php)
    $bodyText   = build_resolution_text($data);
    $allLines   = _res_wrap($bodyText, 88);

    $pageWidth  = 612; $pageHeight = 792;
    $marginLeft = 72;  $marginTop  = 72; $marginBot = 72;
    $fontSize   = 10;  $lineHeight = 14;
    $usableH    = $pageHeight - $marginTop - $marginBot - 30;
    $linesPerPg = (int)floor($usableH / $lineHeight);

    $pages      = array_chunk($allLines, $linesPerPg) ?: [['']];
    $totalPages = count($pages);

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>',
    ];
    $pageIds = [];

    foreach ($pages as $pgIdx => $pgLines) {
        $pgNum   = $pgIdx + 1;
        $content = "BT\n/F1 {$fontSize} Tf\n{$marginLeft} " . ($pageHeight - $marginTop) . " Td\n{$lineHeight} TL\n";
        foreach ($pgLines as $line) {
            $content .= '(' . _res_escape($line) . ") Tj\nT*\n";
        }
        $footerY    = $marginBot - 18;
        $footerText = 'Item No. ' . (!empty($data['item_number']) ? $data['item_number'] : '[ITEM NO]')
                    . ', Resolution     Page ' . $pgNum . ' of ' . $totalPages;
        $content .= "/F1 8 Tf\n{$marginLeft} {$footerY} Td\n(" . _res_escape($footerText) . ") Tj\nET\n";

        $cId = count($objects) + 1;
        $objects[$cId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $pId = count($objects) + 1;
        $objects[$pId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$cId} 0 R >>";
        $pageIds[] = $pId . ' 0 R';
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageIds) . '] /Count ' . $totalPages . ' >>';

    $pdf = "%PDF-1.4\n"; $offsets = []; $count = count($objects);
    for ($i = 1; $i <= $count; $i++) {
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . ($count + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $count; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . ($count + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    file_put_contents($outPath, $pdf);

    return 'storage/final_outputs/' . $applicationId . '/' . $filename;
}

// ── POST: generate PDF, save, record, notify ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$applicationId) {
        $_SESSION['flash_error'] = 'No application selected.';
        redirect('admin-officer/final-output.php');
    }

    $application = officer_application($applicationId);

    $endorsementNumber = trim($_POST['endorsement_number'] ?? '');
    $adminNotes        = trim($_POST['admin_notes']        ?? '');

    if ($endorsementNumber === '') {
        $_SESSION['flash_error'] = 'Endorsement / Resolution Number is required.';
        redirect('admin-officer/final-output.php?id=' . $applicationId);
    }

    // Build resolution data from POST (all fields editable by AO)
    $existingLandUseMap = [
        'residential'   => 'Residential Zone (R)',
        'commercial'    => 'Commercial Zone (C)',
        'industrial'    => 'Industrial Zone (I)',
        'institutional' => 'Institutional Zone (INS)',
        'agricultural'  => 'Agricultural Zone (A)',
        'others'        => $application['existing_land_use_other'] ?? 'Other Zone',
    ];
    $defaultCurrentZone = $existingLandUseMap[$application['existing_land_use'] ?? ''] ?? ($application['existing_land_use'] ?? '');

    $data = [
        'session_type'        => trim($_POST['session_type']        ?? 'Regular Session'),
        'year'                => trim($_POST['year']                ?? date('Y')),
        'legislative_body'    => trim($_POST['legislative_body']    ?? 'Sangguniang Panlungsod'),
        'city'                => trim($_POST['city']                ?? 'Davao City'),
        'resolution_number'   => $endorsementNumber,
        'item_number'         => trim($_POST['item_number']         ?? ''),
        'applicant_name'      => trim($_POST['applicant_name']      ?? $application['account_name']),
        'lot_area'            => trim($_POST['lot_area']            ?? ($application['lot_area'] ? number_format((float)$application['lot_area'], 2) : '')),
        'property_reference'  => trim($_POST['property_reference']  ?? $application['property_title']),
        'barangay'            => trim($_POST['barangay']            ?? $application['property_address']),
        'current_zone'        => trim($_POST['current_zone']        ?? $defaultCurrentZone),
        'proposed_zone'       => trim($_POST['proposed_zone']       ?? ($application['type_of_project'] ?? '')),
        'purpose'             => trim($_POST['purpose']             ?? ($application['type_of_project'] ?? '')),
        'committee_findings'  => trim($_POST['committee_findings']  ?? ''),
        'date_approved'       => trim($_POST['date_approved']       ?? date('jS \d\a\y \o\f F Y')),
        'sponsoring_official' => trim($_POST['sponsoring_official'] ?? ''),
        'official_title'      => trim($_POST['official_title']      ?? 'City Councilor'),
        'committee_name'      => trim($_POST['committee_name']      ?? 'Committee on Land Use and Zoning'),
    ];

    try {
        $relPath = ao_save_resolution_pdf($data, $applicationId);
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Could not generate the resolution PDF: ' . $e->getMessage();
        redirect('admin-officer/final-output.php?id=' . $applicationId);
    }

    // Check if a final_output row already exists for this application
    $existingStmt = db()->prepare('SELECT id FROM final_outputs WHERE application_id = ? ORDER BY id DESC LIMIT 1');
    $existingStmt->execute([$applicationId]);
    $existingRow = $existingStmt->fetch();

    if ($existingRow) {
        db()->prepare('UPDATE final_outputs SET resolution_file_path = ?, endorsement_number = ?, uploaded_by = ?, uploaded_at = NOW() WHERE id = ?')
           ->execute([$relPath, $endorsementNumber, (int)$user['id'], (int)$existingRow['id']]);
        $finalOutputId = (int)$existingRow['id'];
    } else {
        $insertStmt = db()->prepare('INSERT INTO final_outputs (application_id, resolution_file_path, endorsement_number, uploaded_by) VALUES (?, ?, ?, ?)');
        $insertStmt->execute([$applicationId, $relPath, $endorsementNumber, (int)$user['id']]);
        $finalOutputId = (int)db()->lastInsertId();
    }

    advance_application($applicationId, 'APPROVED', 14);

    notify_user(
        (int)$application['landlord_id'],
        $applicationId,
        'Application Approved — Endorsement Issued',
        'Congratulations! Your application (' . $application['registry_number'] . ') has been officially approved. '
        . 'Endorsement No. ' . $endorsementNumber . ' has been issued. '
        . ($adminNotes ? 'Note from the Administrative Officer: ' . $adminNotes . ' ' : '')
        . 'Please log in to view and download the official resolution document.'
    );

    $finalOutputUrl = rtrim($config['app']['base_url'] ?? '', '/') . '/document_preview.php?type=final_output&id=' . $finalOutputId;
    $finalOutputDownloadUrl = $finalOutputUrl . '&download=1';

    // Fetch inspection photos for the email (load here in POST context)
    $inspectionPhotoUrls = [];
    $insStmt = db()->prepare('SELECT id FROM inspections WHERE application_id = ? ORDER BY id DESC LIMIT 1');
    $insStmt->execute([$applicationId]);
    $insRow = $insStmt->fetch();
    if ($insRow) {
        $photoStmt = db()->prepare('SELECT id FROM inspection_photos WHERE inspection_id = ? ORDER BY uploaded_at ASC');
        $photoStmt->execute([(int)$insRow['id']]);
        while ($photo = $photoStmt->fetch()) {
            $inspectionPhotoUrls[] = rtrim($config['app']['base_url'] ?? '', '/') . '/twg/inspection_photo.php?id=' . (int)$photo['id'];
        }
    }

    $htmlBody = '<p>Dear ' . e($application['account_name']) . ',</p>'
        . '<p>Your application <strong>' . e($application['registry_number']) . '</strong> has been approved and the official endorsement document is now available.</p>'
        . '<p><strong>Endorsement No.:</strong> ' . e($endorsementNumber) . '</p>'
        . '<p><strong>Project:</strong> ' . e($application['property_title']) . '</p>'
        . '<p>You may view, download, and print the endorsement by clicking the button below.</p>'
        . '<p><a href="' . e($finalOutputUrl) . '" style="display:inline-block;padding:12px 18px;background:#0d6efd;color:#ffffff;text-decoration:none;border-radius:6px;">View Endorsement</a></p>'
        . '<p><a href="' . e($finalOutputDownloadUrl) . '" style="display:inline-block;padding:12px 18px;background:#198754;color:#ffffff;text-decoration:none;border-radius:6px;margin-top:8px;">Download Endorsement</a></p>';

    if (!empty($inspectionPhotoUrls)) {
        $htmlBody .= '<p>The following site inspection photos are available for reference:</p><ul>';
        foreach ($inspectionPhotoUrls as $photoUrl) {
            $htmlBody .= '<li><a href="' . e($photoUrl) . '">' . e($photoUrl) . '</a></li>';
        }
        $htmlBody .= '</ul>';
    }

    if ($adminNotes) {
        $htmlBody .= '<p><strong>Note from the Administrative Officer:</strong> ' . e($adminNotes) . '</p>';
    }

    $htmlBody .= '<p>Thank you,<br>City Planning and Development Office</p>';

    $altBody = 'Your application ' . $application['registry_number'] . ' has been approved. Endorsement No. ' . $endorsementNumber . '. ';
    if ($adminNotes) {
        $altBody .= 'Note from the Administrative Officer: ' . $adminNotes . ' ';
    }
    $altBody .= 'View the endorsement here: ' . $finalOutputUrl;

    send_email_to_user(
        (int)$application['landlord_id'],
        'Your Endorsement is Ready — ' . $application['registry_number'],
        $htmlBody,
        $altBody
    );

    // Mark final_output row with emailed timestamp (non-fatal if column missing)
    try {
        db()->prepare('UPDATE final_outputs SET emailed_at = NOW() WHERE id = ?')
            ->execute([$finalOutputId]);
    } catch (Throwable $e) {
        // Column may not exist in older schema — non-fatal, skip silently
    }

    audit_log((int)$user['id'], 'AO_RESOLUTION_GENERATED_ENDORSEMENT_ISSUED', 'applications', $applicationId, [
        'endorsement_number' => $endorsementNumber,
        'resolution_path'    => $relPath,
        'inspection_photos'  => $inspectionPhotoUrls,
    ]);

    $_SESSION['flash_success'] = 'Resolution PDF generated. Endorsement No. ' . $endorsementNumber . ' issued. Applicant has been notified.';
    redirect('admin-officer/final-output.php?id=' . $applicationId);
}

// ── Page data ─────────────────────────────────────────────────────────────────
$applications = officer_applications(['DELIBERATION', 'APPROVED']);
$application  = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);

$existingOutput = null;
if ($application) {
    $s = db()->prepare('SELECT * FROM final_outputs WHERE application_id = ? ORDER BY id DESC LIMIT 1');
    $s->execute([(int)$application['id']]);
    $existingOutput = $s->fetch() ?: null;
}

// Pre-fill resolution fields from application + meeting + inspection data
$meetingRow    = $application ? latest_meeting_for_application((int)$application['id']) : null;
$inspectionRow = null;
if ($application) {
    $ins = db()->prepare('SELECT * FROM inspections WHERE application_id = ? ORDER BY id DESC LIMIT 1');
    $ins->execute([(int)$application['id']]);
    $inspectionRow = $ins->fetch() ?: null;
}

$existingLandUseMap = [
    'residential'   => 'Residential Zone (R)',
    'commercial'    => 'Commercial Zone (C)',
    'industrial'    => 'Industrial Zone (I)',
    'institutional' => 'Institutional Zone (INS)',
    'agricultural'  => 'Agricultural Zone (A)',
    'others'        => $application['existing_land_use_other'] ?? 'Other Zone',
];
$defaultCurrentZone = $application
    ? ($existingLandUseMap[$application['existing_land_use'] ?? ''] ?? ($application['existing_land_use'] ?? ''))
    : '';

$defaultFindings = '';
if ($inspectionRow && !empty($inspectionRow['findings'])) {
    $defaultFindings = mb_substr(trim($inspectionRow['findings']), 0, 400);
    if (mb_strlen($inspectionRow['findings']) > 400) { $defaultFindings .= '...'; }
}
if ($defaultFindings === '' && $meetingRow && !empty($meetingRow['minutes'])) {
    if (preg_match('/a\. Reports:\s*(.*?)(?=b\. Open Issues:|$)/si', $meetingRow['minutes'], $m)) {
        $defaultFindings = trim($m[1]);
    }
    if ($defaultFindings === '') {
        $defaultFindings = mb_substr(trim($meetingRow['minutes']), 0, 400);
    }
}


// If the Zoning Officer already generated the resolution, use that data to pre-fill
$zoResolutionData = [];
if ($existingOutput && !empty($existingOutput['resolution_data'])) {
    $zoResolutionData = json_decode($existingOutput['resolution_data'], true) ?: [];
}

// Override defaults with ZO-saved values where available
if (!empty($zoResolutionData['current_zone']))  { $defaultCurrentZone = $zoResolutionData['current_zone']; }
$zoProposedZone       = $zoResolutionData['proposed_zone']       ?? ($application['type_of_project'] ?? '');
$zoSessionType        = $zoResolutionData['session_type']        ?? 'Regular Session';
$zoYear               = $zoResolutionData['year']                ?? date('Y');
$zoLegBody            = $zoResolutionData['legislative_body']    ?? 'Sangguniang Panlungsod';
$zoCity               = $zoResolutionData['city']                ?? 'Davao City';
$zoResolutionNumber   = $existingOutput['endorsement_number']    ?? ($zoResolutionData['resolution_number'] ?? '');
$zoItemNumber         = $zoResolutionData['item_number']         ?? '';
$zoLotArea            = $zoResolutionData['lot_area']            ?? ($application['lot_area'] ? number_format((float)$application['lot_area'], 2) : '');
$zoPropertyRef        = $zoResolutionData['property_reference']  ?? $application['property_title'];
$zoBarangay           = $zoResolutionData['barangay']            ?? $application['property_address'];
$zoPurpose            = $zoResolutionData['purpose']             ?? ($application['type_of_project'] ?? '');
$zoDateApproved       = $zoResolutionData['date_approved']       ?? date('jS day of F Y');
$zoSponsoringOfficial = $zoResolutionData['sponsoring_official'] ?? '';
$zoOfficialTitle      = $zoResolutionData['official_title']      ?? 'City Councilor';
$zoCommitteeName      = $zoResolutionData['committee_name']      ?? 'Committee on Land Use and Zoning';
$zoApplicantName      = $zoResolutionData['applicant_name']      ?? $application['account_name'];
require __DIR__ . '/../partials/header.php';
?>﻿<?php
// view portion — appended to final-output.php
$cpdoBase = rtrim($config['app']['cpdo_url'] ?? '', '/');
$pubBase  = rtrim($config['app']['base_url']  ?? '', '/');
?>
<style>
.fo-shell{max-width:1200px;margin:0 auto;padding:30px 18px 56px}
.fo-topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}
.fo-title h1{margin:0;color:#0b2a4a;font-size:clamp(1.4rem,3vw,2rem);font-weight:900;letter-spacing:-.03em}
.fo-title p{margin:6px 0 0;color:#62748a;font-size:.88rem;line-height:1.6}
.fo-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:20px;align-items:start}
.fo-sidebar{position:sticky;top:20px}
.fo-app-list{background:#fff;border:1px solid #d0dae6;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(11,42,74,.07)}
.fo-list-hd{padding:13px 16px;background:#f4f8fc;border-bottom:1px solid #d0dae6;font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.07em;color:#0b2a4a;margin:0}
.fo-app-link{display:block;padding:11px 16px;border-bottom:1px solid #eef2f7;color:#3a5068;text-decoration:none;font-size:.82rem;font-weight:700;transition:background .12s}
.fo-app-link:last-child{border-bottom:0}
.fo-app-link:hover{background:#f4f8fc;color:#0b2a4a}
.fo-app-link.active{background:#eef2f7;color:#0b2a4a;border-left:3px solid #1d6aad}
.fo-app-link small{display:block;color:#8a9ab0;font-size:.73rem;font-weight:600;margin-top:2px}
.fo-empty-list{padding:20px 16px;color:#8a9ab0;font-size:.8rem;text-align:center}
.fo-card{background:#fff;border:1px solid #d0dae6;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(11,42,74,.07);margin-bottom:18px}
.fo-card-hd{padding:15px 22px;background:#f4f8fc;border-bottom:1px solid #d0dae6}
.fo-card-title{margin:0;color:#0b2a4a;font-size:.92rem;font-weight:900}
.fo-card-sub{margin:3px 0 0;color:#62748a;font-size:.77rem;line-height:1.5}
.fo-card-body{padding:22px}
.fo-strip{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 22px;background:#f4f8fc;border-bottom:1px solid #d0dae6;flex-wrap:wrap}
.fo-strip-info{display:flex;gap:22px;flex-wrap:wrap}
.fo-strip-item span{display:block;color:#8a9ab0;font-size:.6rem;font-weight:900;text-transform:uppercase;letter-spacing:.07em}
.fo-strip-item strong{display:block;color:#0b2a4a;font-size:.84rem;font-weight:900}
.fo-sec{font-size:.68rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:#1d6aad;margin:20px 0 11px;padding-bottom:5px;border-bottom:2px solid #eef2f7}
.fo-sec:first-child{margin-top:0}
.fo-row2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
.fo-lbl{display:block;margin-bottom:5px;font-size:.73rem;font-weight:800;color:#0b2a4a}
.fo-ctrl{width:100%;min-height:40px;padding:8px 11px;border-radius:8px;border:1px solid #c5d3df;background:#f8fbff;color:#0b2a4a;font-size:.87rem;font-weight:600;outline:none;transition:border-color .15s,box-shadow .15s}
.fo-ctrl:focus{border-color:#1d6aad;box-shadow:0 0 0 3px rgba(29,106,173,.12);background:#fff}
textarea.fo-ctrl{min-height:88px;resize:vertical;line-height:1.6}
.fo-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;padding-top:16px;border-top:1px solid #eef2f7}
.fo-btn{display:inline-flex;align-items:center;gap:7px;min-height:40px;padding:9px 20px;border-radius:8px;border:1px solid #0b2a4a;background:#0b2a4a;color:#fff;font-size:.85rem;font-weight:800;cursor:pointer;text-decoration:none;transition:background .15s,transform .15s}
.fo-btn:hover{background:#0e3560;color:#fff;transform:translateY(-1px)}
.fo-btn-success{background:#15803d;border-color:#15803d}
.fo-btn-success:hover{background:#166534;border-color:#166534;color:#fff}
.fo-btn-outline{background:transparent;color:#0b2a4a;border-color:#c5d3df}
.fo-btn-outline:hover{background:#eef2f7;color:#0b2a4a}
.fo-issued{display:flex;align-items:flex-start;gap:13px;padding:15px 18px;background:#f0fdf4;border:1px solid #86efac;border-left:4px solid #22c55e;border-radius:10px;margin-bottom:16px}
.fo-issued-icon{flex-shrink:0;color:#16a34a;margin-top:1px}
.fo-issued strong{display:block;color:#14532d;font-size:.88rem;margin-bottom:5px}
.fo-issued-dl{display:grid;grid-template-columns:auto 1fr;gap:3px 14px;font-size:.8rem}
.fo-issued-dl dt{color:#62748a;font-weight:700;white-space:nowrap}
.fo-issued-dl dd{margin:0;color:#0b2a4a;font-weight:800}
.fo-meeting-note{padding:11px 15px;background:#f4f8fc;border-left:4px solid #1d6aad;border-radius:0 8px 8px 0;margin-bottom:16px;font-size:.79rem;color:#3a5068;line-height:1.55}
.fo-meeting-note strong{color:#0b2a4a}
.fo-no-app{padding:48px 24px;text-align:center;color:#62748a;font-size:.9rem;line-height:1.7}
@media(max-width:900px){.fo-layout{grid-template-columns:1fr}.fo-sidebar{position:static}}
@media(max-width:600px){.fo-row2{grid-template-columns:1fr}}
</style>

<div class="fo-shell">
  <div class="fo-topbar">
    <div class="fo-title">
      <h1>Generate &amp; Issue Endorsement</h1>
      <p>Fill in the resolution details below, then generate and issue the official endorsement document to the applicant.</p>
    </div>
    <a class="btn btn-back btn-sm" href="dashboard/index.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
  </div>

  <div class="fo-layout">
    <!-- Sidebar -->
    <aside class="fo-sidebar">
      <div class="fo-app-list">
        <h2 class="fo-list-hd">Pending Endorsement</h2>
        <?php if ($applications): ?>
          <?php foreach ($applications as $row): ?>
            <a class="fo-app-link <?= $application && (int)$application['id'] === (int)$row['id'] ? 'active' : '' ?>"
               href="final-output.php?id=<?= (int)$row['id'] ?>">
              <?= e($row['registry_number']) ?>
              <small><?= e($row['property_title']) ?></small>
            </a>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="fo-empty-list">No applications awaiting endorsement.</div>
        <?php endif; ?>
      </div>
    </aside>

    <!-- Main -->
    <div>
      <?php if (!$application): ?>
        <div class="fo-card"><div class="fo-no-app">Select an application from the list to generate its endorsement.</div></div>
      <?php else: ?>

        <?php if ($existingOutput && !empty($existingOutput['resolution_file_path'])): ?>
        <div class="fo-issued">
          <div class="fo-issued-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/></svg>
          </div>
          <div>
            <strong>Endorsement Already Issued</strong>
            <dl class="fo-issued-dl">
              <dt>Endorsement No.</dt><dd><?= e($existingOutput['endorsement_number'] ?? '—') ?></dd>
              <dt>Issued at</dt><dd><?= e($existingOutput['uploaded_at'] ? date('F j, Y · g:i A', strtotime($existingOutput['uploaded_at'])) : '—') ?></dd>
            </dl>
            <?php
              $dlAbs = realpath(__DIR__ . '/../../' . $existingOutput['resolution_file_path']);
              $dlUrl = $dlAbs ? $pubBase . '/document_preview.php?type=final_output&id=' . (int)$existingOutput['id'] : '';
            ?>
            <?php if ($dlUrl): ?>
            <div style="margin-top:10px">
              <a class="fo-btn fo-btn-outline" href="<?= e($dlUrl) ?>" target="_blank" rel="noopener">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>
                View Resolution PDF
              </a>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php elseif ($existingOutput && !empty($existingOutput['resolution_data'])): ?>
        <div style="padding:12px 16px;background:#fefce8;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:10px;margin-bottom:16px;font-size:.82rem;color:#78350f;">
          <strong style="display:block;margin-bottom:3px;">Resolution drafted by Zoning Officer</strong>
          All fields have been pre-filled from the Zoning Officer's resolution. Review, adjust if needed, then click Generate &amp; Issue Endorsement.
        </div>
        <?php endif; ?>

        <div class="fo-card">
          <div class="fo-strip">
            <div class="fo-strip-info">
              <div class="fo-strip-item"><span>Registry</span><strong><?= e($application['registry_number']) ?></strong></div>
              <div class="fo-strip-item"><span>Property</span><strong><?= e($application['property_title']) ?></strong></div>
              <div class="fo-strip-item"><span>Applicant</span><strong><?= e($application['account_name']) ?></strong></div>
            </div>
            <span class="status-pill status-<?= e(strtolower($application['phase_status'])) ?>">
              <?= e(workflow_status_label($application['phase_status'])) ?>
            </span>
          </div>

          <div class="fo-card-body">
            <?php if ($meetingRow && trim($meetingRow['minutes'] ?? '') !== ''): ?>
            <div class="fo-meeting-note">
              <strong>TWG Minutes available</strong> — Meeting
              <?php if (!empty($meetingRow['scheduled_at'])): ?>
                scheduled on <strong><?= e(date('F j, Y · h:i A', strtotime($meetingRow['scheduled_at']))) ?></strong>.
              <?php endif; ?>
              Committee findings have been pre-filled from the minutes.
            </div>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="csrf_token"     value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">

              <!-- Endorsement number (maps to resolution_number) -->
              <p class="fo-sec">Endorsement Reference</p>
              <div class="fo-row2" style="margin-bottom:14px">
                <div>
                  <label class="fo-lbl" for="endorsement_number">Endorsement / Resolution Number <span style="color:#dc3545">*</span></label>
                  <input class="fo-ctrl" id="endorsement_number" name="endorsement_number" required
                         placeholder="e.g. CPDO-END-2026-001"
                         value="<?= e($existingOutput['endorsement_number'] ?? '') ?>">
                </div>
                <div>
                  <label class="fo-lbl" for="item_number">Item Number</label>
                  <input class="fo-ctrl" id="item_number" name="item_number" placeholder="e.g. 42" value="<?= e($zoItemNumber) ?>">
                </div>
              </div>

              <!-- Session & legislative body -->
              <p class="fo-sec">Session &amp; Legislative Body</p>
              <div class="fo-row2" style="margin-bottom:14px">
                <div>
                  <label class="fo-lbl" for="session_type">Session Type</label>
                  <select class="fo-ctrl" id="session_type" name="session_type">
                    <option value="Regular Session" <?= $zoSessionType === "Regular Session" ? "selected" : "" ?>>Regular Session</option>
                    <option value="Special Session" <?= $zoSessionType === "Special Session" ? "selected" : "" ?>>Special Session</option>
                  </select>
                </div>
                <div>
                  <label class="fo-lbl" for="year">Year</label>
                  <input class="fo-ctrl" id="year" name="year" type="number" value="<?= date('Y') ?>" min="2000" max="2099">
                </div>
                <div>
                  <label class="fo-lbl" for="legislative_body">Legislative Body</label>
                  <input class="fo-ctrl" id="legislative_body" name="legislative_body" value="<?= e($zoLegBody) ?>">
                </div>
                <div>
                  <label class="fo-lbl" for="city">City / Municipality</label>
                  <input class="fo-ctrl" id="city" name="city" value="<?= e($zoCity) ?>">
                </div>
              </div>

              <!-- Property & applicant -->
              <p class="fo-sec">Property &amp; Applicant</p>
              <div style="margin-bottom:13px">
                <label class="fo-lbl" for="applicant_name">Applicant Name</label>
                <input class="fo-ctrl" id="applicant_name" name="applicant_name"
                       value="<?= e($application['account_name']) ?>">
              </div>
              <div class="fo-row2" style="margin-bottom:13px">
                <div>
                  <label class="fo-lbl" for="lot_area">Lot Area (sqm)</label>
                  <input class="fo-ctrl" id="lot_area" name="lot_area"
                         value="<?= $application['lot_area'] ? e(number_format((float)$application['lot_area'], 2)) : '' ?>"
                         placeholder="e.g. 500.00">
                </div>
                <div>
                  <label class="fo-lbl" for="property_reference">Property Reference</label>
                  <input class="fo-ctrl" id="property_reference" name="property_reference"
                         value="<?= e($application['property_title']) ?>"
                         placeholder="e.g. TCT No. T-123456">
                </div>
              </div>
              <div style="margin-bottom:13px">
                <label class="fo-lbl" for="barangay">Barangay &amp; Location</label>
                <input class="fo-ctrl" id="barangay" name="barangay"
                       value="<?= e($application['property_address']) ?>">
              </div>
              <div class="fo-row2" style="margin-bottom:13px">
                <div>
                  <label class="fo-lbl" for="current_zone">Current Zoning</label>
                  <input class="fo-ctrl" id="current_zone" name="current_zone"
                         value="<?= e($defaultCurrentZone) ?>"
                         placeholder="e.g. Agricultural Zone (A)">
                </div>
                <div>
                  <label class="fo-lbl" for="proposed_zone">Proposed Zoning</label>
                  <input class="fo-ctrl" id="proposed_zone" name="proposed_zone"
                         value="<?= e($application['type_of_project'] ?? '') ?>"
                         placeholder="e.g. Residential Zone (R-2)">
                </div>
              </div>

              <!-- Purpose & findings -->
              <p class="fo-sec">Purpose &amp; Committee Findings</p>
              <div style="margin-bottom:13px">
                <label class="fo-lbl" for="purpose">Purpose / Justification</label>
                <textarea class="fo-ctrl" id="purpose" name="purpose" rows="3"><?= e($application['type_of_project'] ?? '') ?></textarea>
              </div>
              <div style="margin-bottom:13px">
                <label class="fo-lbl" for="committee_findings">Committee Findings</label>
                <textarea class="fo-ctrl" id="committee_findings" name="committee_findings" rows="4"><?= e($defaultFindings) ?></textarea>
              </div>

              <!-- Date & signatories -->
              <p class="fo-sec">Date &amp; Signatories</p>
              <div style="margin-bottom:13px">
                <label class="fo-lbl" for="date_approved">Date of Approval</label>
                <input class="fo-ctrl" id="date_approved" name="date_approved"
                       value="<?= e(date('jS day of F Y')) ?>">
              </div>
              <div class="fo-row2" style="margin-bottom:13px">
                <div>
                  <label class="fo-lbl" for="sponsoring_official">Sponsoring Official</label>
                  <input class="fo-ctrl" id="sponsoring_official" name="sponsoring_official" placeholder="Full name of sponsoring councilor" value="<?= e($zoSponsoringOfficial) ?>">
                </div>
                <div>
                  <label class="fo-lbl" for="official_title">Official Title</label>
                  <input class="fo-ctrl" id="official_title" name="official_title" value="<?= e($zoOfficialTitle) ?>">
                </div>
              </div>
              <div style="margin-bottom:13px">
                <label class="fo-lbl" for="committee_name">Committee Name</label>
                <input class="fo-ctrl" id="committee_name" name="committee_name" value="<?= e($zoCommitteeName) ?>">
              </div>

              <!-- Notes to landlord -->
              <p class="fo-sec">Notes to Applicant <span style="font-weight:600;text-transform:none;letter-spacing:0;color:#8a9ab0">(optional)</span></p>
              <div style="margin-bottom:4px">
                <textarea class="fo-ctrl" name="admin_notes" rows="3"
                          placeholder="Any additional instructions or remarks to include in the notification…"></textarea>
              </div>

              <div class="fo-actions">
                <button type="submit" class="fo-btn fo-btn-success">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>
                  <?= $existingOutput ? 'Re-generate &amp; Re-issue' : 'Generate &amp; Issue Endorsement' ?>
                </button>
              </div>
            </form>
          </div>
        </div>

      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>