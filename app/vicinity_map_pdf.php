<?php

declare(strict_types=1);

/**
 * Vicinity Map PDF Generator — CPDO Official Format
 *
 * Replicates the official CPDO Land Reclassification Vicinity Map layout:
 *
 * Landscape A4 (297 × 210 mm)
 * ┌──────────────────────────────┬──────────────────────────┐
 * │                              │  Title / Description     │
 * │   Main Satellite Map         │  (top ~55 mm)            │
 * │   with "THIS SITE" marker    ├──────────────────────────┤
 * │                              │  Legend — Land Use Types │
 * │                              │  (middle ~90 mm)         │
 * │                              ├──────────────────────────┤
 * │                              │  Technical / Signature   │
 * │                              │  block (bottom ~45 mm)   │
 * └──────────────────────────────┴──────────────────────────┘
 *
 * Returns the relative path to the saved PDF (relative to project root).
 */

function generate_vicinity_map_pdf(
    float  $lat,
    float  $lng,
    string $applicantName,
    string $projectName,
    string $registryNumber
): string {
    global $config;

    $mapsApiKey = $config['google']['maps_api_key'] ?? '';
    if ($mapsApiKey === '') {
        throw new RuntimeException('Google Maps API key is not configured. Add maps_api_key to config/env.php.');
    }

    // ── Resolve CA bundle (WampServer / XAMPP SSL fix) ────────────────────────
    $caBundle = (function (): string {
        $phpIni = ini_get('curl.cainfo');
        if ($phpIni && is_file($phpIni)) { return $phpIni; }
        $candidates = [
            'C:/wamp64/bin/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION . '/extras/ssl/cacert.pem',
            'C:/wamp64/bin/php/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/extras/ssl/cacert.pem',
            'C:/wamp/bin/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION . '/extras/ssl/cacert.pem',
            'C:/xampp/php/extras/ssl/cacert.pem',
            'C:/xampp/php/cacert.pem',
            __DIR__ . '/../storage/cacert.pem',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) { return $path; }
        }
        return '';
    })();

    // ── Helper: cURL fetch with SSL fix ───────────────────────────────────────
    $curlFetch = function (string $url) use ($caBundle): string {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($caBundle !== '') { $opts[CURLOPT_CAINFO] = $caBundle; }
        curl_setopt_array($ch, $opts);
        $data  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err   = curl_error($ch);
        curl_close($ch);
        if ($data === false || $err !== '') {
            throw new RuntimeException('Failed to fetch satellite image: ' . $err);
        }
        if ($code !== 200) {
            throw new RuntimeException('Google Static Maps API returned HTTP ' . $code . '. Check your API key and billing.');
        }
        return $data;
    };

    // ── 1. Main satellite map (640×640, zoom 17) ──────────────────────────────
    $mainUrl = sprintf(
        'https://maps.googleapis.com/maps/api/staticmap'
        . '?center=%s,%s&zoom=%d&size=640x640&maptype=satellite'
        . '&markers=color:red%%7Clabel:%%E2%%97%%8F%%7C%s,%s'
        . '&key=%s',
        $lat, $lng, 17, $lat, $lng,
        urlencode($mapsApiKey)
    );
    $mainImageData = $curlFetch($mainUrl);

    // ── 2. Inset overview map (320×320, zoom 11) ──────────────────────────────
    $insetUrl = sprintf(
        'https://maps.googleapis.com/maps/api/staticmap'
        . '?center=%s,%s&zoom=%d&size=320x320&maptype=roadmap'
        . '&markers=color:red%%7C%s,%s'
        . '&key=%s',
        $lat, $lng, 11, $lat, $lng,
        urlencode($mapsApiKey)
    );
    $insetImageData = $curlFetch($insetUrl);

    // ── Write temp files ──────────────────────────────────────────────────────
    $tmpDir = __DIR__ . '/../storage/tmp';
    if (!is_dir($tmpDir)) { mkdir($tmpDir, 0775, true); }

    $detectExt = function (string $data): string {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            default      => throw new RuntimeException('Unexpected image MIME: ' . $mime),
        };
    };

    $mainExt  = $detectExt($mainImageData);
    $insetExt = $detectExt($insetImageData);

    $mainTmp  = $tmpDir . '/' . bin2hex(random_bytes(6)) . '_main.'  . $mainExt;
    $insetTmp = $tmpDir . '/' . bin2hex(random_bytes(6)) . '_inset.' . $insetExt;

    file_put_contents($mainTmp,  $mainImageData)  !== false || throw new RuntimeException('Could not write main image temp file.');
    file_put_contents($insetTmp, $insetImageData) !== false || throw new RuntimeException('Could not write inset image temp file.');

    $fpdfMain  = strtoupper($mainExt  === 'jpg' ? 'JPEG' : 'PNG');
    $fpdfInset = strtoupper($insetExt === 'jpg' ? 'JPEG' : 'PNG');

    // ── 2. Build PDF ──────────────────────────────────────────────────────────
    // Landscape A4: 297 × 210 mm
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    // ── Page border ───────────────────────────────────────────────────────────
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.8);
    $pdf->Rect(5, 5, 287, 200);

    // ── Layout constants ──────────────────────────────────────────────────────
    $mapX = 6;   $mapY = 6;   $mapW = 195; $mapH = 198;   // main map area
    $panX = 203; $panY = 6;   $panW = 88;  $panH = 198;   // right panel

    // ── Main satellite map ────────────────────────────────────────────────────
    $pdf->Image($mainTmp, $mapX, $mapY, $mapW, $mapH, $fpdfMain);

    // Map border
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($mapX, $mapY, $mapW, $mapH);

    // "THIS SITE" label overlay — centred on the map
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(255, 255, 255);
    $labelW = 28; $labelH = 6;
    $labelX = $mapX + ($mapW / 2) - ($labelW / 2);
    $labelY = $mapY + ($mapH / 2) + 6;
    $pdf->SetFillColor(220, 50, 50);
    $pdf->SetDrawColor(255, 255, 255);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect($labelX, $labelY, $labelW, $labelH, 'FD');
    $pdf->SetXY($labelX, $labelY + 0.8);
    $pdf->Cell($labelW, $labelH - 1.5, 'THIS SITE', 0, 0, 'C');

    // ── Right panel outer border ──────────────────────────────────────────────
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($panX, $panY, $panW, $panH);

    $py = $panY; // running Y cursor inside the panel

    // ═════════════════════════════════════════════════════════════════════════
    // SECTION 1 — Title / Description  (top ~58 mm)
    // ═════════════════════════════════════════════════════════════════════════
    $titleH = 58;

    // Government header
    $pdf->SetTextColor(11, 42, 74);
    $pdf->SetFont('Arial', '', 5.5);
    $pdf->SetXY($panX + 2, $py + 2);
    $pdf->MultiCell($panW - 4, 3.5, 'Republic of the Philippines', 0, 'C');
    $py = $pdf->GetY();

    $pdf->SetFont('Arial', 'B', 5.5);
    $pdf->SetXY($panX + 2, $py);
    $pdf->MultiCell($panW - 4, 3.5, 'City of Davao', 0, 'C');
    $py = $pdf->GetY();

    $pdf->SetFont('Arial', 'B', 5.5);
    $pdf->SetXY($panX + 2, $py);
    $pdf->MultiCell($panW - 4, 3.5, "OFFICE OF THE CITY PLANNING\nAND DEVELOPMENT COORDINATOR", 0, 'C');
    $py = $pdf->GetY() + 1;

    // Thin divider
    $pdf->SetDrawColor(180, 180, 180); $pdf->SetLineWidth(0.2);
    $pdf->Line($panX + 3, $py, $panX + $panW - 3, $py);
    $py += 2;

    // "VICINITY MAP" main title
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->SetTextColor(11, 42, 74);
    $pdf->SetXY($panX + 2, $py);
    $pdf->MultiCell($panW - 4, 7, 'VICINITY MAP', 0, 'C');
    $py = $pdf->GetY() + 1;

    // Description line 1
    $pdf->SetFont('Arial', '', 5.5);
    $pdf->SetTextColor(50, 60, 80);
    $pdf->SetXY($panX + 2, $py);
    $pdf->MultiCell($panW - 4, 3.5,
        'MAP SHOWING THE LOCATION OF LOT ' . mb_strtoupper(mb_substr($projectName, 0, 40)),
        0, 'C');
    $py = $pdf->GetY();

    $pdf->SetXY($panX + 2, $py);
    $pdf->MultiCell($panW - 4, 3.5,
        'OVERLAYED WITH APPROVED LAND USE MAP',
        0, 'C');
    $py = $pdf->GetY() + 1;

    // Thin divider
    $pdf->SetDrawColor(180, 180, 180); $pdf->SetLineWidth(0.2);
    $pdf->Line($panX + 3, $py, $panX + $panW - 3, $py);
    $py += 2;

    // Applicant / Registry
    $pdf->SetFont('Arial', '', 5);
    $pdf->SetTextColor(98, 116, 138);
    $pdf->SetXY($panX + 2, $py);
    $pdf->Cell($panW - 4, 3.5, 'APPLICANT', 0, 2, 'L');
    $py = $pdf->GetY();

    $pdf->SetFont('Arial', 'B', 6);
    $pdf->SetTextColor(11, 42, 74);
    $pdf->SetXY($panX + 2, $py);
    $pdf->MultiCell($panW - 4, 3.5, $applicantName, 0, 'L');
    $py = $pdf->GetY() + 1;

    $pdf->SetFont('Arial', '', 5);
    $pdf->SetTextColor(98, 116, 138);
    $pdf->SetXY($panX + 2, $py);
    $pdf->Cell($panW - 4, 3.5, 'REGISTRY NO.', 0, 2, 'L');
    $py = $pdf->GetY();

    $pdf->SetFont('Arial', 'B', 6);
    $pdf->SetTextColor(11, 42, 74);
    $pdf->SetXY($panX + 2, $py);
    $pdf->Cell($panW - 4, 3.5, $registryNumber, 0, 2, 'L');
    $py = $pdf->GetY() + 1;

    // Section 1 bottom border
    $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.4);
    $pdf->Line($panX, $panY + $titleH, $panX + $panW, $panY + $titleH);
    $py = $panY + $titleH + 1;

    // ═════════════════════════════════════════════════════════════════════════
    // SECTION 2 — Legend  (middle ~90 mm)
    // ═════════════════════════════════════════════════════════════════════════
    $legendStartY = $panY + $titleH;
    $legendH      = 100;

    // "Legend" header
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(11, 42, 74);
    $pdf->SetXY($panX + 2, $py);
    $pdf->Cell($panW - 4, 5, 'Legend', 0, 2, 'L');
    $py = $pdf->GetY() + 1;

    // Land use categories with colour swatches
    // Each entry: [R, G, B, label]
    $landUseCategories = [
        [0,   100, 180, 'Coastal Land Area'],
        [0,   140,  70, 'Ecosystem Forest Land'],
        [80,  160,  80, 'River Land Area'],
        [0,   120, 160, 'Coastal / Watershed Areas'],
        [180, 100,  40, 'Utility Corridor'],
        [60,  140, 200, 'Water Land / River Land Areas'],
        [160, 120,  60, 'Heritage Site'],
        [100, 180, 200, 'Seashore Areas'],
        [60,  160,  80, 'Buffer / Greenbelt Areas'],
        [200, 160,  40, 'Production Areas'],
        [180, 100, 160, 'General Municipal Areas'],
        [120,  80,  60, 'Peninsula / Mountain'],
        [220, 140,  60, 'Residential / Commercial Areas'],
        [80,  180, 120, 'Water / Greenbelt / Open Space'],
        [140, 100,  80, 'Buffer Land / Mountain'],
        [100,  60, 140, 'Special Management Areas'],
        [40,  120, 180, 'Water / Land / River Land Areas'],
    ];

    $swatchW = 5; $swatchH = 3.8; $rowH = 4.2;
    $col1X = $panX + 2;
    $col2X = $panX + 2 + ($panW / 2);
    $colW  = ($panW / 2) - 3;

    $pdf->SetDrawColor(80, 80, 80);
    $pdf->SetLineWidth(0.15);
    $pdf->SetFont('Arial', '', 5);

    $half = (int)ceil(count($landUseCategories) / 2);
    for ($i = 0; $i < count($landUseCategories); $i++) {
        [$r, $g, $b, $label] = $landUseCategories[$i];

        if ($i < $half) {
            $cx = $col1X;
            $cy = $py + ($i * $rowH);
        } else {
            $cx = $col2X;
            $cy = $py + (($i - $half) * $rowH);
        }

        // Colour swatch
        $pdf->SetFillColor($r, $g, $b);
        $pdf->Rect($cx, $cy + 0.3, $swatchW, $swatchH, 'FD');

        // Label
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetXY($cx + $swatchW + 1.5, $cy + 0.2);
        $pdf->Cell($colW - $swatchW - 2, $rowH, $label, 0, 0, 'L');
    }

    $py = $legendStartY + $legendH;

    // Section 2 bottom border
    $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.4);
    $pdf->Line($panX, $py, $panX + $panW, $py);
    $py += 1;

    // ═════════════════════════════════════════════════════════════════════════
    // SECTION 3 — Inset map + Technical / Signature block  (bottom ~50 mm)
    // ═════════════════════════════════════════════════════════════════════════
    $sigStartY = $py;
    $sigH      = $panY + $panH - $sigStartY;

    // Inset map — left half of the bottom section
    $insetX = $panX + 1;
    $insetY = $sigStartY + 1;
    $insetW = ($panW / 2) - 2;
    $insetH = $sigH - 2;

    $pdf->Image($insetTmp, $insetX, $insetY, $insetW, $insetH, $fpdfInset);
    $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.3);
    $pdf->Rect($insetX, $insetY, $insetW, $insetH);

    // Inset label
    $pdf->SetFont('Arial', 'I', 4.5);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->SetXY($insetX, $insetY + $insetH - 4);
    $pdf->Cell($insetW, 4, 'Municipality, City', 0, 0, 'C');

    // Technical / Signature block — right half of the bottom section
    $sigX = $panX + ($panW / 2) + 1;
    $sigY = $sigStartY + 1;
    $sigW = ($panW / 2) - 3;

    $pdf->SetDrawColor(160, 160, 160); $pdf->SetLineWidth(0.2);

    $fields = [
        ['Date',     date('F d, Y')],
        ['Prepared', ''],
        ['Checked',  ''],
        ['Approved', ''],
    ];

    $fieldH = ($sigH - 4) / count($fields);
    foreach ($fields as $idx => [$label, $value]) {
        $fy = $sigY + ($idx * $fieldH);

        // Row border
        $pdf->Rect($sigX, $fy, $sigW, $fieldH);

        // Label
        $pdf->SetFont('Arial', '', 4.5);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY($sigX + 1, $fy + 1);
        $pdf->Cell($sigW - 2, 3, $label . ':', 0, 2, 'L');

        // Value / signature line
        if ($value !== '') {
            $pdf->SetFont('Arial', 'B', 5);
            $pdf->SetTextColor(11, 42, 74);
            $pdf->SetXY($sigX + 1, $fy + 4.5);
            $pdf->Cell($sigW - 2, 3, $value, 0, 0, 'L');
        } else {
            // Blank signature line
            $pdf->SetDrawColor(160, 160, 160); $pdf->SetLineWidth(0.2);
            $pdf->Line($sigX + 2, $fy + $fieldH - 3, $sigX + $sigW - 2, $fy + $fieldH - 3);
        }
    }

    // Coordinates note at very bottom of panel
    $pdf->SetFont('Arial', 'I', 4.5);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetXY($panX + 1, $panY + $panH - 5);
    $pdf->Cell($panW - 2, 4,
        sprintf('Lat: %s  Lng: %s', number_format($lat, 6), number_format($lng, 6)),
        0, 0, 'C');

    // ── Save PDF ──────────────────────────────────────────────────────────────
    $outDir = __DIR__ . '/../storage/vicinity_maps';
    if (!is_dir($outDir)) { mkdir($outDir, 0775, true); }

    $filename = 'vicinity_map_'
              . preg_replace('/[^A-Za-z0-9\-]/', '_', $registryNumber)
              . '_' . date('Ymd_His') . '.pdf';
    $outPath  = $outDir . '/' . $filename;

    $pdf->Output('F', $outPath);

    @unlink($mainTmp);
    @unlink($insetTmp);

    return 'storage/vicinity_maps/' . $filename;
}
