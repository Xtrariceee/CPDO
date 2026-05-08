<?php

declare(strict_types=1);

/**
 * Vicinity Map PDF Generator
 *
 * Fetches a Google Static Maps satellite image for the given coordinates,
 * then builds a formal GIS-style "Vicinity Map" PDF using FPDF.
 *
 * Layout: Landscape A4 (297 × 210 mm)
 *   - Left  : Satellite map image  — X=10, Y=10, W=200, H=190
 *   - Right : Title block column   — X=215, Y=10, W=72, H=190
 *
 * Returns the relative path to the saved PDF (relative to project root),
 * or throws RuntimeException on failure.
 */

// FPDF 1.82 lives in the global namespace — autoloaded via Composer classmap.

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

    // ── 1. Fetch satellite image from Google Static Maps API ─────────────────
    // Use a high-resolution 640×640 square image at zoom 17 (~200 m radius).
    $zoom   = 17;
    $width  = 640;
    $height = 640;

    $staticUrl = sprintf(
        'https://maps.googleapis.com/maps/api/staticmap'
        . '?center=%s,%s&zoom=%d&size=%dx%d&maptype=satellite'
        . '&markers=color:red%%7C%s,%s'
        . '&style=feature:all|element:labels|visibility:on'
        . '&key=%s',
        $lat, $lng,
        $zoom,
        $width, $height,
        $lat, $lng,
        urlencode($mapsApiKey)
    );

    $ch = curl_init($staticUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $imageData = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($imageData === false || $curlError !== '') {
        throw new RuntimeException('Failed to fetch satellite image: ' . $curlError);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('Google Static Maps API returned HTTP ' . $httpCode . '. Check your API key and billing.');
    }

    // Detect MIME type
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($imageData);
    $ext      = match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        default      => throw new RuntimeException('Unexpected image type from Static Maps API: ' . $mimeType),
    };

    // Write to temp file
    $tmpDir = __DIR__ . '/../storage/tmp';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0775, true);
    }
    $tmpFile = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (file_put_contents($tmpFile, $imageData) === false) {
        throw new RuntimeException('Could not write temporary image file.');
    }

    // ── 2. Build the PDF ──────────────────────────────────────────────────────
    // Landscape A4: 297 mm wide × 210 mm high
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    // ── Map area: X=10, Y=10, W=200, H=190 ───────────────────────────────────
    $mapX = 10;
    $mapY = 10;
    $mapW = 200;
    $mapH = 190;

    $fpdfType = strtoupper($ext === 'jpg' ? 'JPEG' : 'PNG');
    $pdf->Image($tmpFile, $mapX, $mapY, $mapW, $mapH, $fpdfType);

    // Black border around the map
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($mapX, $mapY, $mapW, $mapH);

    // ── Title block: X=215, Y=10, W=72, H=190 ────────────────────────────────
    $tbX = 215;
    $tbY = 10;
    $tbW = 72;
    $tbH = 190;

    // Outer boundary rectangle
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($tbX, $tbY, $tbW, $tbH);

    // ── Helper: write a line inside the title block ───────────────────────────
    // Tracks the current Y position within the block.
    $curY = $tbY + 4;

    $writeCell = function (
        string $text,
        string $style = '',
        float  $size  = 8,
        string $align = 'C',
        float  $lineH = 5
    ) use ($pdf, $tbX, $tbW, &$curY): void {
        $pdf->SetFont('Arial', $style, $size);
        $pdf->SetXY($tbX + 2, $curY);
        $pdf->MultiCell($tbW - 4, $lineH, $text, 0, $align);
        $curY = $pdf->GetY();
    };

    $drawDivider = function () use ($pdf, $tbX, $tbW, &$curY): void {
        $pdf->SetDrawColor(180, 180, 180);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($tbX + 2, $curY + 1, $tbX + $tbW - 2, $curY + 1);
        $curY += 4;
    };

    // ── Header: government office ─────────────────────────────────────────────
    $pdf->SetTextColor(11, 42, 74);

    $writeCell('Republic of the Philippines', '', 6.5, 'C', 4);
    $writeCell('City of Davao', '', 6.5, 'C', 4);
    $writeCell("OFFICE OF THE CITY PLANNING\nAND DEVELOPMENT COORDINATOR", 'B', 6.5, 'C', 4);

    $drawDivider();

    // ── Main title ────────────────────────────────────────────────────────────
    $pdf->SetTextColor(11, 42, 74);
    $writeCell('VICINITY MAP', 'B', 16, 'C', 8);

    $pdf->SetTextColor(60, 80, 104);
    $writeCell('MAP SHOWING THE LOCATION OF:', '', 7, 'C', 4);

    $pdf->SetTextColor(11, 42, 74);
    // Truncate project name to avoid overflow
    $displayName = mb_strtoupper(mb_substr($projectName, 0, 60));
    $writeCell($displayName, 'B', 8, 'C', 5);

    $drawDivider();

    // ── Applicant & registry ──────────────────────────────────────────────────
    $pdf->SetTextColor(98, 116, 138);
    $writeCell('APPLICANT', '', 6, 'L', 3.5);
    $pdf->SetTextColor(11, 42, 74);
    $writeCell($applicantName, 'B', 7.5, 'L', 4.5);

    $curY += 2;
    $pdf->SetTextColor(98, 116, 138);
    $writeCell('REGISTRY NO.', '', 6, 'L', 3.5);
    $pdf->SetTextColor(11, 42, 74);
    $writeCell($registryNumber, 'B', 7.5, 'L', 4.5);

    $drawDivider();

    // ── Coordinates ───────────────────────────────────────────────────────────
    $pdf->SetTextColor(98, 116, 138);
    $writeCell('COORDINATES', '', 6, 'L', 3.5);

    $pdf->SetTextColor(11, 42, 74);
    $writeCell('Latitude:', 'B', 7, 'L', 4);
    $writeCell(number_format($lat, 6) . '°', '', 7.5, 'L', 4);

    $curY += 1;
    $writeCell('Longitude:', 'B', 7, 'L', 4);
    $writeCell(number_format($lng, 6) . '°', '', 7.5, 'L', 4);

    $drawDivider();

    // ── Scale note ────────────────────────────────────────────────────────────
    $pdf->SetTextColor(98, 116, 138);
    $writeCell('APPROXIMATE SCALE', '', 6, 'L', 3.5);
    $pdf->SetTextColor(11, 42, 74);
    $writeCell('200-metre radius shown', '', 7, 'L', 4);

    $drawDivider();

    // ── Disclaimer (pushed to bottom of block) ────────────────────────────────
    // Position near the bottom of the title block
    $disclaimerY = $tbY + $tbH - 28;
    if ($curY < $disclaimerY) {
        $curY = $disclaimerY;
    }

    $pdf->SetDrawColor(180, 180, 180);
    $pdf->SetLineWidth(0.2);
    $pdf->Line($tbX + 2, $curY, $tbX + $tbW - 2, $curY);
    $curY += 3;

    $pdf->SetTextColor(130, 140, 155);
    $pdf->SetFont('Arial', 'I', 5.5);
    $pdf->SetXY($tbX + 2, $curY);
    $pdf->MultiCell(
        $tbW - 4, 3.5,
        'This vicinity map was generated digitally from satellite imagery via the RentEase Portal for reference purposes in support of the Application for Locational Clearance.',
        0, 'L'
    );

    // ── Save PDF ──────────────────────────────────────────────────────────────
    $outDir = __DIR__ . '/../storage/vicinity_maps';
    if (!is_dir($outDir)) {
        mkdir($outDir, 0775, true);
    }

    $filename = 'vicinity_map_'
              . preg_replace('/[^A-Za-z0-9\-]/', '_', $registryNumber)
              . '_' . date('Ymd_His') . '.pdf';
    $outPath  = $outDir . '/' . $filename;

    $pdf->Output('F', $outPath);

    // ── Clean up temp image ───────────────────────────────────────────────────
    @unlink($tmpFile);

    return 'storage/vicinity_maps/' . $filename;
}
