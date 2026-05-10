<?php

declare(strict_types=1);

/**
 * Philippine Sangguniang Panlungsod Land Reclassification Resolution Generator
 *
 * Generates a formal legislative resolution document as a multi-page PDF
 * using pure PHP (no external library required).
 *
 * Usage:
 *   generate_resolution_pdf(array $data): void   — streams PDF to browser
 *   build_resolution_text(array $data): string   — returns the plain-text body
 *
 * $data keys (all optional — missing values render as bracketed placeholders):
 *   session_type, year, legislative_body, city, resolution_number, item_number,
 *   applicant_name, lot_area, property_reference, barangay, current_zone,
 *   proposed_zone, purpose, committee_findings, documents (array of strings),
 *   date_approved, sponsoring_official, official_title, committee_name,
 *   total_pages (int, default 2)
 */

// ── Helpers ───────────────────────────────────────────────────────────────────

function _res_placeholder(string $key, string $fallback = ''): string
{
    return $fallback !== '' ? $fallback : '[' . strtoupper(str_replace('_', ' ', $key)) . ']';
}

function _res_safe(string $text): string
{
    $search  = ["\xe2\x80\x94", "\xe2\x80\x93", "\xe2\x80\x9c", "\xe2\x80\x9d",
                "\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\xa2", "\xc2\xb7",
                "\xe2\x82\xb1", "\xe2\x80\xa6"];
    $replace = ['-', '-', '"', '"', "'", "'", '*', '-', 'PHP', '...'];
    $text    = str_replace($search, $replace, $text);
    $out     = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    return $out !== false ? $out : preg_replace('/[^\x20-\x7E\r\n\t]/', '?', $text);
}

function _res_escape(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('(', '\\(', $text);
    $text = str_replace(')', '\\)', $text);
    return $text;
}

function _res_wrap(string $text, int $maxChars = 88): array
{
    $result = [];
    foreach (preg_split("/\r\n|\r|\n/", _res_safe($text)) as $line) {
        $line = rtrim($line);
        if ($line === '') { $result[] = ''; continue; }
        while (mb_strlen($line) > $maxChars) {
            $cut = strrpos(substr($line, 0, $maxChars + 1), ' ');
            if ($cut === false || $cut < 20) { $cut = $maxChars; }
            $result[] = rtrim(substr($line, 0, $cut));
            $line     = ltrim(substr($line, $cut));
        }
        $result[] = $line;
    }
    return $result;
}

// ── Build plain-text resolution body ─────────────────────────────────────────

function build_resolution_text(array $d): string
{
    $p = function (string $key) use ($d): string {
        return !empty($d[$key]) ? (string)$d[$key] : '[' . strtoupper(str_replace('_', ' ', $key)) . ']';
    };

    $sessionType   = $p('session_type');
    $year          = $p('year');
    $legBody       = $p('legislative_body');
    $city          = $p('city');
    $resNo         = $p('resolution_number');
    $itemNo        = $p('item_number');
    $applicant     = $p('applicant_name');
    $lotArea       = $p('lot_area');
    $propRef       = $p('property_reference');
    $barangay      = $p('barangay');
    $currentZone   = $p('current_zone');
    $proposedZone  = $p('proposed_zone');
    $purpose       = $p('purpose');
    $findings      = $p('committee_findings');
    $dateApproved  = $p('date_approved');
    $sponsoring    = $p('sponsoring_official');
    $offTitle      = $p('official_title');
    $committee     = $p('committee_name');

    $documents = !empty($d['documents']) && is_array($d['documents'])
        ? $d['documents']
        : [
            'Zoning Certification',
            'Certified True Copy of Title and/or current tax receipts',
            'Proof of Ownership / Right over Land / Contract of Lease / Deed of Sale',
            'Site Development Plan indicating area and boundaries of lot/property line',
            'Vicinity Map showing major landmarks and nearby areas',
            'Barangay Resolution or Certificate of No Objection',
            'Barangay Development Council Resolution favorably endorsing the project',
            "City Engineer's Office Drainage Clearance",
            'City Environment and Natural Resources Office Solid Waste Management Plan clearance',
            "City Health Office Sanitation Clearance",
            "City Assessor's Office Tax Declaration",
            "City Treasurer's Office Realty Tax Clearance",
            'Water supply availability certification',
            'Power supply availability certification',
            'Geohazard / Mines and Geosciences Bureau certification, if applicable',
            'Fire Safety Inspection Certificate, if applicable',
            'Building Permit, if applicable',
            'Certificate of Occupancy, if applicable',
        ];

    $title = 'TO ENACT AN ORDINANCE GRANTING THE APPLICATION OF ' . strtoupper($applicant)
           . ' FOR RECLASSIFICATION OF A ' . strtoupper($lotArea)
           . ' SQUARE METERS MORE OR LESS PARCEL OF LAND COVERED BY ' . strtoupper($propRef)
           . ' SITUATED AT ' . strtoupper($barangay) . ', ' . strtoupper($city)
           . ' FROM ' . strtoupper($currentZone)
           . ' TO ' . strtoupper($proposedZone) . '.';

    $lines = [];

    // ── Header ────────────────────────────────────────────────────────────────
    $lines[] = strtoupper($sessionType) . ' SESSION';
    $lines[] = 'Series of ' . $year;
    $lines[] = '';
    $lines[] = 'REPUBLIKA NG PILIPINAS';
    $lines[] = strtoupper($city);
    $lines[] = strtoupper($legBody);
    $lines[] = '';
    $lines[] = str_repeat('-', 72);
    $lines[] = '';
    $lines[] = 'RESOLUTION NO. ' . $resNo;
    $lines[] = 'Series of ' . $year;
    $lines[] = '';
    $lines[] = str_repeat('-', 72);
    $lines[] = '';

    // ── Title ─────────────────────────────────────────────────────────────────
    foreach (_res_wrap($title, 80) as $tl) { $lines[] = $tl; }
    $lines[] = '';
    $lines[] = str_repeat('-', 72);
    $lines[] = '';

    // ── WHEREAS clauses ───────────────────────────────────────────────────────
    $whereas = [
        'WHEREAS, ' . $applicant . ' (hereinafter referred to as the "Applicant") has filed '
        . 'a formal application with the City Planning and Development Office (CPDO) of '
        . $city . ' for the reclassification of a parcel of land from '
        . $currentZone . ' to ' . $proposedZone . ';',

        'WHEREAS, the subject property is a parcel of land containing an area of '
        . $lotArea . ' square meters, more or less, covered by ' . $propRef
        . ', situated at ' . $barangay . ', ' . $city . ', Philippines;',

        'WHEREAS, the Applicant seeks the reclassification of the subject property '
        . 'for the following purpose: ' . $purpose . ';',

        'WHEREAS, the subject property has been found suitable for the proposed '
        . $proposedZone . ' use based on its location, accessibility, proximity to '
        . 'existing infrastructure, and compatibility with surrounding land uses in '
        . $barangay . ', ' . $city . ';',

        'WHEREAS, the Land Use Reclassification Committee (LZRC) Technical Working '
        . 'Group (TWG) of the City Planning and Development Office conducted a field '
        . 'inspection and site validation of the subject property and found the '
        . 'following: ' . $findings . ';',

        'WHEREAS, the LZRC TWG conducted a formal meeting and deliberation on the '
        . 'application, during which the merits of the reclassification were thoroughly '
        . 'reviewed, discussed, and evaluated in accordance with the Comprehensive Land '
        . 'Use Plan (CLUP) and the Zoning Ordinance of ' . $city . ';',

        'WHEREAS, the reclassification of the subject property from ' . $currentZone
        . ' to ' . $proposedZone . ' is consistent with the development goals, '
        . 'land use policies, and spatial planning framework of ' . $city
        . ', and will contribute to the orderly, rational, and sustainable development '
        . 'of the locality;',

        'WHEREAS, the application has been duly reviewed and endorsed by the City '
        . 'Planning and Development Office, and no valid objection has been raised by '
        . 'the concerned barangay officials, adjacent property owners, or any '
        . 'government agency with jurisdiction over the subject property;',

        'WHEREAS, to support the application, the Applicant submitted the following '
        . 'documents for compliance and perusal:',
    ];

    foreach ($whereas as $clause) {
        foreach (_res_wrap($clause, 88) as $wl) { $lines[] = $wl; }
        $lines[] = '';
    }

    // ── Document list ─────────────────────────────────────────────────────────
    foreach ($documents as $i => $doc) {
        $num = str_pad((string)($i + 1), 2, ' ', STR_PAD_LEFT) . '. ';
        foreach (_res_wrap($num . $doc, 86) as $j => $dl) {
            $lines[] = ($j === 0 ? '' : '     ') . $dl;
        }
    }
    $lines[] = '';

    // ── NOW THEREFORE ─────────────────────────────────────────────────────────
    $nowTherefore = 'NOW THEREFORE, BE IT RESOLVED AS IT IS HEREBY RESOLVED, TO ENACT AN ORDINANCE '
        . 'GRANTING THE APPLICATION OF ' . strtoupper($applicant)
        . ' FOR RECLASSIFICATION OF ' . strtoupper($lotArea)
        . ' SQUARE METERS MORE OR LESS PARCEL OF LAND COVERED BY ' . strtoupper($propRef)
        . ' FROM ' . strtoupper($currentZone)
        . ' TO ' . strtoupper($proposedZone)
        . ' SITUATED AT ' . strtoupper($barangay) . ', ' . strtoupper($city) . '.';

    foreach (_res_wrap($nowTherefore, 88) as $nl) { $lines[] = $nl; }
    $lines[] = '';

    // ── Distribution clause ───────────────────────────────────────────────────
    $distribution = 'RESOLVED FINALLY, that copies of this Resolution be furnished to the Office of '
        . 'the City Mayor through the City Administrator\'s Office, the Office of the Vice Mayor, '
        . 'all ' . $city . ' Councilors, the City Engineer\'s Office, the City Planning and '
        . 'Development Office, and all other offices/departments concerned, for their information '
        . 'and guidance;';

    foreach (_res_wrap($distribution, 88) as $dl) { $lines[] = $dl; }
    $lines[] = '';
    $lines[] = str_repeat('-', 72);
    $lines[] = '';

    // ── Date and location ─────────────────────────────────────────────────────
    $lines[] = 'Done this ' . $dateApproved . ', at ' . $city . ', Philippines.';
    $lines[] = '';
    $lines[] = '';

    // ── Signatories ───────────────────────────────────────────────────────────
    $lines[] = str_repeat(' ', 40) . strtoupper($sponsoring);
    $lines[] = str_repeat(' ', 40) . $offTitle;
    $lines[] = str_repeat(' ', 40) . 'Chairperson, ' . $committee;
    $lines[] = '';

    return implode("\n", $lines);
}

// ── PDF output ────────────────────────────────────────────────────────────────

function generate_resolution_pdf(array $data): void
{
    $p = function (string $key) use ($data): string {
        return !empty($data[$key]) ? (string)$data[$key] : '[' . strtoupper(str_replace('_', ' ', $key)) . ']';
    };

    $city      = $p('city');
    $resNo     = $p('resolution_number');
    $itemNo    = $p('item_number');
    $year      = $p('year');
    $applicant = $p('applicant_name');

    $bodyText  = build_resolution_text($data);
    $allLines  = _res_wrap($bodyText, 88);

    // ── PDF constants ─────────────────────────────────────────────────────────
    $pageWidth   = 612;   // Letter width  (pts)
    $pageHeight  = 792;   // Letter height (pts)
    $marginLeft  = 72;    // 1 inch
    $marginRight = 72;
    $marginTop   = 72;
    $marginBot   = 72;
    $fontSize    = 10;
    $lineHeight  = 14;
    $usableH     = $pageHeight - $marginTop - $marginBot - 30; // 30 for footer
    $linesPerPg  = (int)floor($usableH / $lineHeight);

    // Split lines into pages
    $pages = array_chunk($allLines, $linesPerPg);
    if (empty($pages)) { $pages = [['']];}
    $totalPages = count($pages);

    // ── Build PDF objects ─────────────────────────────────────────────────────
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',   // Pages dict — filled after
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>',
    ];

    $pageIds = [];

    foreach ($pages as $pgIdx => $pgLines) {
        $pgNum = $pgIdx + 1;

        // Content stream
        $content  = "BT\n";
        $content .= "/F1 {$fontSize} Tf\n";
        $content .= "{$marginLeft} " . ($pageHeight - $marginTop) . " Td\n";
        $content .= "{$lineHeight} TL\n";

        foreach ($pgLines as $line) {
            $content .= '(' . _res_escape($line) . ") Tj\nT*\n";
        }

        // Footer: Item No. / Page N of M
        $footerY = $marginBot - 18;
        $footerText = 'Item No. ' . (!empty($data['item_number']) ? $data['item_number'] : '[ITEM NO]')
                    . ', Resolution     Page ' . $pgNum . ' of ' . $totalPages;
        $content .= "/F1 8 Tf\n";
        $content .= $marginLeft . ' ' . $footerY . " Td\n";
        $content .= '(' . _res_escape($footerText) . ") Tj\n";

        $content .= "ET\n";

        $cId = count($objects) + 1;
        $objects[$cId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";

        $pId = count($objects) + 1;
        $objects[$pId] = "<< /Type /Page /Parent 2 0 R"
            . " /MediaBox [0 0 {$pageWidth} {$pageHeight}]"
            . " /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >>"
            . " /Contents {$cId} 0 R >>";

        $pageIds[] = $pId . ' 0 R';
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageIds) . '] /Count ' . $totalPages . ' >>';

    // ── Assemble PDF ──────────────────────────────────────────────────────────
    $pdf     = "%PDF-1.4\n";
    $offsets = [];
    $count   = count($objects);

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

    // ── Stream to browser ─────────────────────────────────────────────────────
    $safeCity = preg_replace('/[^A-Za-z0-9_-]+/', '-', $city);
    $safeRes  = preg_replace('/[^A-Za-z0-9_-]+/', '-', $resNo);
    $filename = 'Resolution-' . trim($safeRes, '-') . '-' . trim($safeCity, '-') . '-' . $year . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0');
    echo $pdf;
    exit;
}
