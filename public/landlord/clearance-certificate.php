<?php
/**
 * Streams the official clearance certificate PDF for an approved request.
 * If a stored certificate blob exists it is served directly.
 * Otherwise a fresh certificate is generated on the fly.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD, ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Missing id.'); }

$stmt = db()->prepare(
    'SELECT cr.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name,
            u.email AS landlord_email
     FROM clearance_requests cr
     JOIN users u ON u.id = cr.landlord_id
     WHERE cr.id = ?'
);
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row)                        { http_response_code(404); exit('Not found.'); }
if ($row['status'] !== 'APPROVED') { http_response_code(403); exit('Certificate not available — request not yet approved.'); }
if ($user['role'] === ROLE_LANDLORD && (int)$row['landlord_id'] !== (int)$user['id']) {
    http_response_code(403); exit('Forbidden.');
}

/* ── use stored cert if available ────────────────────────────────────────── */
if (!empty($row['certificate_data'])) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Certificate-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $row['title']) . '.pdf"');
    header('Content-Length: ' . strlen($row['certificate_data']));
    header('Cache-Control: private, max-age=86400');
    echo $row['certificate_data'];
    exit;
}

/* ── generate certificate PDF on the fly ─────────────────────────────────── */
function build_cert_pdf(array $row): string
{
    $pageW = 842; $pageH = 595; /* A4 landscape */
    $now   = date('F j, Y');
    $cert  = 'CERT-' . strtoupper(bin2hex(random_bytes(4)));

    /* ---- text lines ---- */
    $lines = [
        /* [x, y, font (1=regular, 2=bold), size, text] */
        [0,   $pageH - 80,  2, 22, 'CITY PLANNING AND DEVELOPMENT OFFICE'],
        [0,   $pageH - 104, 1, 13, 'Official Clearance & Certification Portal'],
        [0,   $pageH - 160, 2, 28, 'CERTIFICATE OF COMPLIANCE'],
        [0,   $pageH - 200, 1, 12, 'This is to certify that'],
        [0,   $pageH - 228, 2, 18, strtoupper($row['landlord_name'])],
        [0,   $pageH - 255, 1, 12, 'has been duly verified and approved for the following clearance / certification:'],
        [0,   $pageH - 290, 2, 16, $row['title']],
        [0,   $pageH - 316, 1, 11, 'Issuing Office: ' . $row['office']],
        [0,   $pageH - 352, 1, 11, 'Certificate No.: ' . $cert],
        [0,   $pageH - 370, 1, 11, 'Date of Issue:   ' . $now],
        [0,   $pageH - 388, 1, 11, 'Valid for one (1) year from date of issue unless otherwise revoked.'],
        [0,   $pageH - 440, 1, 10, 'This certificate is generated electronically by the CPDO Land Reclassification Portal.'],
        [0,   $pageH - 456, 1, 10, 'Authenticity may be verified through the portal reference number above.'],
    ];

    /* ── minimal raw PDF builder ── */
    $f1 = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $f2 = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',     /* pages dict — filled later */
        3 => $f1,
        4 => $f2,
    ];

    /* decorative border stream */
    $borderContent =
        "0.96 0.85 0.35 RG\n" .          /* gold stroke */
        "3 w\n" .
        "20 20 {$pageW}-40 {$pageH}-40 re S\n" .
        "1.5 w\n" .
        "30 30 {$pageW}-60 {$pageH}-60 re S\n" .
        "0.09 0.17 0.29 rg\n" .           /* navy fill for header band */
        "0 {$pageH}-110 {$pageW} 110 re f\n";

    $b = count($objects) + 1;
    $objects[$b] = '<< /Length ' . strlen($borderContent) . " >>\nstream\n" . $borderContent . "endstream";

    /* text content stream */
    $tc = "BT\n";
    foreach ($lines as $l) {
        [$x, $y, $fn, $sz, $txt] = $l;
        /* centre the text horizontally */
        $approxWidth = strlen($txt) * $sz * 0.55;
        $cx = max(40, ($pageW - $approxWidth) / 2);
        /* header lines in white, rest in navy */
        if ($y > $pageH - 130) {
            $tc .= "1 1 1 rg\n"; /* white */
        } else {
            $tc .= "0.09 0.17 0.29 rg\n"; /* navy */
        }
        $tc .= "/F{$fn} {$sz} Tf\n{$cx} {$y} Td\n(" . _cert_escape($txt) . ") Tj\n0 0 Td\n";
    }
    $tc .= "ET\n";

    $t = count($objects) + 1;
    $objects[$t] = '<< /Length ' . strlen($tc) . " >>\nstream\n" . $tc . "endstream";

    /* page */
    $p = count($objects) + 1;
    $objects[$p] =
        "<< /Type /Page /Parent 2 0 R " .
        "/MediaBox [0 0 {$pageW} {$pageH}] " .
        "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> " .
        "/Contents [{$b} 0 R {$t} 0 R] >>";

    $objects[2] = "<< /Type /Pages /Kids [{$p} 0 R] /Count 1 >>";

    /* assemble */
    $pdf = "%PDF-1.4\n"; $offsets = []; $n = count($objects);
    for ($i = 1; $i <= $n; $i++) {
        $offsets[$i] = strlen($pdf);
        $pdf .= "{$i} 0 obj\n{$objects[$i]}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . ($n + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $n; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . ($n + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    return $pdf;
}

function _cert_escape(string $s): string
{
    return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s);
}

$pdfBytes = build_cert_pdf($row);

/* persist so future downloads skip re-generation */
try {
    db()->prepare('UPDATE clearance_requests SET certificate_data=? WHERE id=?')
       ->execute([$pdfBytes, $id]);
} catch (Throwable $e) { /* non-fatal */ }

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Certificate-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $row['title']) . '.pdf"');
header('Content-Length: ' . strlen($pdfBytes));
header('Cache-Control: private, max-age=86400');
echo $pdfBytes;
exit;
