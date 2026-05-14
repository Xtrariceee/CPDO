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
 * │   Main Satellite Map         │  (top ~58 mm)            │
 * │   with "THIS SITE" marker    ├──────────────────────────┤
 * │                              │  Legend — Land Use Types │
 * │                              │  (middle ~100 mm)        │
 * │                              ├──────────────────────────┤
 * │                              │  Inset map + Signature   │
 * │                              │  block (bottom ~40 mm)   │
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

    // Resolve the API key — prefer the global $config already loaded by bootstrap,
    // but fall back to reading env.php directly in case $config is not in scope
    // (e.g. when called from a context where the global was not propagated).
    $mapsApiKey = $config['google']['maps_api_key'] ?? '';

    if ($mapsApiKey === '') {
        $envFile = __DIR__ . '/../config/env.php';
        if (file_exists($envFile)) {
            $envCfg     = require $envFile;
            $mapsApiKey = $envCfg['google']['maps_api_key'] ?? '';
            // Also repair the global so subsequent calls in this request work
            if ($mapsApiKey !== '' && isset($config)) {
                $config['google']['maps_api_key'] = $mapsApiKey;
            }
        }
    }

    if ($mapsApiKey === '') {
        throw new RuntimeException('Google Maps API key is not configured. Add maps_api_key to config/env.php.');
    }

    // ── Resolve CA bundle (WampServer / XAMPP SSL fix) ────────────────────────
    $caBundle = (function (): string {
        $phpIni = ini_get('curl.cainfo');
        if ($phpIni && is_file($phpIni)) {
            return $phpIni;
        }
        $candidates = [
            'C:/wamp64/bin/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION . '/extras/ssl/cacert.pem',
            'C:/wamp64/bin/php/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/extras/ssl/cacert.pem',
            'C:/wamp/bin/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION . '/extras/ssl/cacert.pem',
            'C:/xampp/php/extras/ssl/cacert.pem',
            'C:/xampp/php/cacert.pem',
            __DIR__ . '/../storage/cacert.pem',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return '';
    })();

    // ── Helper: cURL fetch with SSL fix ───────────────────────────────────────
    $curlFetch = function (string $url) use ($caBundle): string {
        $ch   = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
        ];

        if ($caBundle !== '') {
            // Use the discovered CA bundle with full verification
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
            $opts[CURLOPT_CAINFO]         = $caBundle;
        } else {
            // No CA bundle found — disable peer verification (XAMPP/dev only)
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $opts);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($data === false || $err !== '') {
            throw new RuntimeException('Failed to fetch map image: ' . $err);
        }
        if ($code !== 200) {
            throw new RuntimeException(
                'Google Static Maps API returned HTTP ' . $code
                . '. Check your API key and billing.'
            );
        }
        return (string)$data;
    };

    // Always format coordinates with a dot decimal separator regardless of locale
    $latStr = number_format($lat, 7, '.', '');
    $lngStr = number_format($lng, 7, '.', '');

    // ── 1. Main satellite map (640×640, zoom 17) ──────────────────────────────
    $mainUrl = sprintf(
        'https://maps.googleapis.com/maps/api/staticmap'
        . '?center=%s,%s&zoom=17&size=640x640&maptype=satellite'
        . '&markers=color:red|label:S|%s,%s'
        . '&key=%s',
        $latStr, $lngStr,
        $latStr, $lngStr,
        urlencode($mapsApiKey)
    );
    $mainImageData = $curlFetch($mainUrl);

    // ── 2. Inset overview map (320×320, zoom 11) ──────────────────────────────
    $insetUrl = sprintf(
        'https://maps.googleapis.com/maps/api/staticmap'
        . '?center=%s,%s&zoom=11&size=320x320&maptype=roadmap'
        . '&markers=color:red|%s,%s'
        . '&key=%s',
        $latStr, $lngStr,
        $latStr, $lngStr,
        urlencode($mapsApiKey)
    );
    $insetImageData = $curlFetch($insetUrl);

    // ── Write temp image files ────────────────────────────────────────────────
    $tmpDir = __DIR__ . '/../storage/tmp';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0775, true);
    }

    $detectExt = function (string $data): string {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($data);
        if ($mime === 'image/jpeg') {
            return 'jpg';
        }
        if ($mime === 'image/png') {
            return 'png';
        }
        throw new RuntimeException('Unexpected image MIME type from Google Maps API: ' . $mime);
    };

    $mainExt  = $detectExt($mainImageData);
    $insetExt = $detectExt($insetImageData);

    $mainTmp  = $tmpDir . '/' . bin2hex(random_bytes(6)) . '_main.'  . $mainExt;
    $insetTmp = $tmpDir . '/' . bin2hex(random_bytes(6)) . '_inset.' . $insetExt;

    if (file_put_contents($mainTmp, $mainImageData) === false) {
        throw new RuntimeException('Could not write main image temp file.');
    }
    if (file_put_contents($insetTmp, $insetImageData) === false) {
        @unlink($mainTmp);
        throw new RuntimeException('Could not write inset image temp file.');
    }

    // FPDF type strings: 'JPEG' or 'PNG'
    $fpdfMain  = ($mainExt  === 'jpg') ? 'JPEG' : 'PNG';
    $fpdfInset = ($insetExt === 'jpg') ? 'JPEG' : 'PNG';

    // ── Build PDF ─────────────────────────────────────────────────────────────
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
    // Page: 297 × 210 mm  |  usable: 287 × 200 mm (5 mm margins)
    // Left map area: x=6, y=6, w=195, h=198
    // Right panel:   x=203, y=6, w=88, h=198
    $mapX = 6;   $mapY = 6;   $mapW = 195; $mapH = 198;
    $panX = 203; $panY = 6;   $panW = 88;  $panH = 198;

    // Fixed section heights (must sum to <= $panH)
    $titleH  = 58;   // Section 1 — title / description
    $legendH = 100;  // Section 2 — legend
    $sigH    = $panH - $titleH - $legendH;  // Section 3 — inset + signatures (40 mm)

    // ── Main satellite map ────────────────────────────────────────────────────
    $pdf->Image($mainTmp, $mapX, $mapY, $mapW, $mapH, $fpdfMain);

    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($mapX, $mapY, $mapW, $mapH);

    // "THIS SITE" label — centred horizontally, just below map centre
    $labelW = 28; $labelH = 6;
    $labelX = $mapX + ($mapW / 2) - ($labelW / 2);
    $labelY = $mapY + ($mapH / 2) + 6;
    $pdf->SetFillColor(220, 50, 50);
    $pdf->SetDrawColor(255, 255, 255);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect($labelX, $labelY, $labelW, $labelH, 'FD');
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($labelX, $labelY + 0.8);
    $pdf->Cell($labelW, $labelH - 1.5, 'THIS SITE', 0, 0, 'C');

    // ── Right panel outer border ──────────────────────────────────────────────
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($panX, $panY, $panW, $panH);

    // ═════════════════════════════════════════════════════════════════════════
    // SECTION 1 — Title / Description  (fixed height: $titleH mm)
    // ═════════════════════════════════════════════════════════════════════════
    $sec1Y = $panY;

    // Government header
    $pdf->SetTextColor(11, 42, 74);
    $pdf->SetFont('Arial', '', 5.5);
    $pdf->SetXY($panX + 2, $sec1Y + 2);
    $pdf->MultiCell($panW - 4, 3.5, 'Republic of the Philippines', 0, 'C');

    $pdf->SetFont('Arial', 'B', 5.5);
    $pdf->SetXY($panX + 2, $pdf->GetY());
    $pdf->MultiCell($panW - 4, 3.5, 'City of Davao', 0, 'C');

    $pdf->SetFont('Arial', 'B', 5.5);
    $pdf->SetXY($panX + 2, $pdf->GetY());
    $pdf->MultiCell($panW - 4, 3.5, "OFFICE OF THE CITY PLANNING\nAND DEVELOPMENT COORDINATOR", 0, 'C');

    $divY = $pdf->GetY() + 1;
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->SetLineWidth(0.2);
    $pdf->Line($panX + 3, $divY, $panX + $panW - 3, $divY);

    // "VICINITY MAP" title
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->SetTextColor(11, 42, 74);
    $pdf->SetXY($panX + 2, $divY + 2);
    $pdf->MultiCell($panW - 4, 7, 'VICINITY MAP', 0, 'C');

    // Description lines — truncate project name to avoid overflow
    $pdf->SetFont('Arial', '', 5.5);
    $pdf->SetTextColor(50, 60, 80);
    $pdf->SetXY($panX + 2, $pdf->GetY() + 1);
    $pdf->MultiCell(
        $panW - 4, 3.5,
        'MAP SHOWING THE LOCATION OF LOT ' . mb_strtoupper(mb_substr($projectName, 0, 35)),
        0, 'C'
    );
    $pdf->SetXY($panX + 2, $pdf->GetY());
    $pdf->MultiCell($panW - 4, 3.5, 'OVERLAYED WITH APPROVED LAND USE MAP', 0, 'C');

    $divY2 = $pdf->GetY() + 1;
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->SetLineWidth(0.2);
    $pdf->Line($panX + 3, $divY2, $panX + $panW - 3, $divY2);

    // Applicant — clamp to one line to stay within section
    $pdf->SetFont('Arial', '', 5);
    $pdf->SetTextColor(98, 116, 138);
    $pdf->SetXY($panX + 2, $divY2 + 1.5);
    $pdf->Cell($panW - 4, 3.5, 'APPLICANT', 0, 2, 'L');

    $pdf->SetFont('Arial', 'B', 6);
    $pdf->SetTextColor(11, 42, 74);
    $pdf->SetXY($panX + 2, $pdf->GetY());
    // Truncate applicant name to prevent overflow into section 2
    $shortName = mb_strlen($applicantName) > 28 ? mb_substr($applicantName, 0, 26) . '…' : $applicantName;
    $pdf->Cell($panW - 4, 3.5, $shortName, 0, 2, 'L');

    $pdf->SetFont('Arial', '', 5);
    $pdf->SetTextColor(98, 116, 138);
    $pdf->SetXY($panX + 2, $pdf->GetY() + 0.5);
    $pdf->Cell($panW - 4, 3.5, 'REGISTRY NO.', 0, 2, 'L');

    $pdf->SetFont('Arial', 'B', 6);
    $pdf->SetTextColor(11, 42, 74);
    $pdf->SetXY($panX + 2, $pdf->GetY());
    $pdf->Cell($panW - 4, 3.5, $registryNumber, 0, 2, 'L');

    // Section 1 bottom border (fixed position)
    $sec1BottomY = $panY + $titleH;
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.4);
    $pdf->Line($panX, $sec1BottomY, $panX + $panW, $sec1BottomY);

    // ═════════════════════════════════════════════════════════════════════════
    // SECTION 2 — Legend  (fixed height: $legendH mm)
    // ═════════════════════════════════════════════════════════════════════════
    $sec2Y = $sec1BottomY;

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(11, 42, 74);
    $pdf->SetXY($panX + 2, $sec2Y + 1.5);
    $pdf->Cell($panW - 4, 5, 'Legend', 0, 0, 'L');

    $legendItemsY = $sec2Y + 7.5;  // fixed Y where legend items start

    // Land use categories: [R, G, B, label]
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

    $total    = count($landUseCategories);
    $half     = (int)ceil($total / 2);
    $swatchW  = 5;
    $swatchH  = 3.5;
    $rowH     = 4.5;

    // Column widths — each column is half the panel minus padding
    $colW    = ($panW / 2) - 4;   // ~40 mm per column
    $col1X   = $panX + 2;
    $col2X   = $panX + ($panW / 2) + 2;

    $pdf->SetDrawColor(80, 80, 80);
    $pdf->SetLineWidth(0.15);
    $pdf->SetFont('Arial', '', 4.8);

    for ($i = 0; $i < $total; $i++) {
        [$r, $g, $b, $label] = $landUseCategories[$i];

        if ($i < $half) {
            $cx = $col1X;
            $cy = $legendItemsY + ($i * $rowH);
        } else {
            $cx = $col2X;
            $cy = $legendItemsY + (($i - $half) * $rowH);
        }

        // Colour swatch
        $pdf->SetFillColor($r, $g, $b);
        $pdf->Rect($cx, $cy + 0.4, $swatchW, $swatchH, 'FD');

        // Label — constrained to column width so it never overflows the panel
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetXY($cx + $swatchW + 1.5, $cy + 0.2);
        $pdf->Cell($colW - $swatchW - 2, $rowH, $label, 0, 0, 'L');
    }

    // Section 2 bottom border (fixed position)
    $sec2BottomY = $sec2Y + $legendH;
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.4);
    $pdf->Line($panX, $sec2BottomY, $panX + $panW, $sec2BottomY);

    // ═════════════════════════════════════════════════════════════════════════
    // SECTION 3 — Inset map + Signature block  (remaining height: $sigH mm)
    // ═════════════════════════════════════════════════════════════════════════
    $sec3Y = $sec2BottomY;
    $sec3H = $panY + $panH - $sec3Y;   // remaining height (should be ~40 mm)

    // Guard: ensure there is enough room to draw
    if ($sec3H >= 10) {
        $insetPad = 1.5;
        $insetX   = $panX + $insetPad;
        $insetY   = $sec3Y + $insetPad;
        $insetW   = ($panW / 2) - ($insetPad * 2);
        $insetH   = $sec3H - ($insetPad * 2);

        $pdf->Image($insetTmp, $insetX, $insetY, $insetW, $insetH, $fpdfInset);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect($insetX, $insetY, $insetW, $insetH);

        // Inset caption
        $pdf->SetFont('Arial', 'I', 4.5);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->SetXY($insetX, $insetY + $insetH - 4.5);
        $pdf->Cell($insetW, 4, 'Location Overview', 0, 0, 'C');

        // Signature / technical block — right half of section 3
        $sigBlockX = $panX + ($panW / 2) + $insetPad;
        $sigBlockY = $sec3Y + $insetPad;
        $sigBlockW = ($panW / 2) - ($insetPad * 2);
        $sigBlockH = $sec3H - ($insetPad * 2);

        $fields  = [
            ['Date',     date('F d, Y')],
            ['Prepared', ''],
            ['Checked',  ''],
            ['Approved', ''],
        ];
        $fieldH  = $sigBlockH / count($fields);

        $pdf->SetDrawColor(160, 160, 160);
        $pdf->SetLineWidth(0.2);

        foreach ($fields as $idx => [$fieldLabel, $fieldValue]) {
            $fy = $sigBlockY + ($idx * $fieldH);

            $pdf->Rect($sigBlockX, $fy, $sigBlockW, $fieldH);

            $pdf->SetFont('Arial', '', 4.5);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY($sigBlockX + 1, $fy + 1);
            $pdf->Cell($sigBlockW - 2, 3, $fieldLabel . ':', 0, 0, 'L');

            if ($fieldValue !== '') {
                $pdf->SetFont('Arial', 'B', 5);
                $pdf->SetTextColor(11, 42, 74);
                $pdf->SetXY($sigBlockX + 1, $fy + 4.5);
                $pdf->Cell($sigBlockW - 2, 3, $fieldValue, 0, 0, 'L');
            } else {
                // Blank signature line
                $pdf->SetDrawColor(160, 160, 160);
                $pdf->SetLineWidth(0.2);
                $pdf->Line(
                    $sigBlockX + 2,
                    $fy + $fieldH - 3,
                    $sigBlockX + $sigBlockW - 2,
                    $fy + $fieldH - 3
                );
            }
        }
    }

    // Coordinates note at very bottom of panel
    $pdf->SetFont('Arial', 'I', 4.5);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetXY($panX + 1, $panY + $panH - 5);
    $pdf->Cell(
        $panW - 2, 4,
        sprintf('Lat: %s  Lng: %s', number_format($lat, 6), number_format($lng, 6)),
        0, 0, 'C'
    );

    // ── Save PDF ──────────────────────────────────────────────────────────────
    $outDir = __DIR__ . '/../storage/vicinity_maps';
    if (!is_dir($outDir)) {
        mkdir($outDir, 0775, true);
    }

    $filename = 'vicinity_map_'
        . preg_replace('/[^A-Za-z0-9\-]/', '_', $registryNumber)
        . '_' . date('Ymd_His') . '.pdf';
    $outPath = $outDir . '/' . $filename;

    $pdf->Output('F', $outPath);

    @unlink($mainTmp);
    @unlink($insetTmp);

    return 'storage/vicinity_maps/' . $filename;
}
