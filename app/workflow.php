<?php

declare(strict_types=1);

function required_documents(): array
{
    return [
        'City Planning and Development Office' => [
            'zoning_certification' => [
                'title'   => 'Zoning Certification',
                'details' => 'Obtain a Zoning Certification issued by the CPDO confirming the current zoning classification of the subject property. Upload a clear scanned copy.',
            ],
        ],
        'Register of Deeds' => [
            'proof_of_ownership'   => [
                'title'   => 'Proof of Ownership',
                'details' => 'Upload a certified true copy of the Transfer Certificate of Title (TCT), Contract of Lease, Deed of Sale, or any other document that legally establishes ownership or right over the property. The original will be required for physical validation during inspection.',
            ],
        ],
        'Department of Public Works & Highways / City Engineer\'s Office' => [
            'right_of_way'         => [
                'title'   => 'Right-of-Way of Access Road',
                'details' => 'Upload documentation proving the right to use or ownership of the access road leading to the property (e.g., right-of-way agreement or deed of sale for the access road).',
            ],
        ],
        'Applicant' => [
            'site_development_plan' => [
                'title'   => 'Site Development Plan',
                'details' => 'Prepare and upload an original site development plan drawn to scale. It must reflect the full land distribution including: green/open areas, parking layout, building footprint, property boundary lines, and the access road. If the property is an interior lot, the access road must be clearly indicated.',
            ],
            'vicinity_map'          => [
                'title'   => 'Vicinity Map',
                'details' => 'This PDF is generated automatically from the drawn land boundary polygon in the application form. Review the generated map before submitting.',
            ],
            'affidavit_neighbor'    => [
                'title'   => 'Affidavit of Neighbor\'s Consent',
                'details' => 'Upload a notarized Affidavit of Consent signed by the adjoining property owners confirming they have no objection to the proposed reclassification.',
            ],
        ],
        'Barangay Council' => [
            'barangay_resolution'  => [
                'title'   => 'Barangay Council Resolution (No Objection)',
                'details' => 'Upload a copy of the Barangay Council Resolution formally interposing no objection to the proposed land use reclassification of the subject property.',
            ],
        ],
        'Barangay Development Council' => [
            'bdc_resolution'       => [
                'title'   => 'Barangay Development Council Resolution',
                'details' => 'Upload a copy of the Barangay Development Council (BDC) Resolution favorably endorsing the proposed project or reclassification.',
            ],
        ],
        'City Engineer\'s Office' => [
            'drainage_clearance'   => [
                'title'   => 'Drainage Clearance',
                'details' => 'Upload the Drainage Clearance issued by the City Engineer\'s Office confirming that the property\'s drainage system meets city standards.',
            ],
        ],
        'City Environment & Natural Resources Office' => [
            'solid_waste_certificate' => [
                'title'   => 'Solid Waste Management Plan Certificate',
                'details' => 'Upload the certificate issued by the City Environment & Natural Resources Office (CENRO) confirming that a compliant Solid Waste Management Plan is in place for the property.',
            ],
        ],
        'City Health Office' => [
            'sanitation_clearance' => [
                'title'   => 'Sanitation Clearance',
                'details' => 'Upload the Sanitation Clearance issued by the City Health Office certifying that the property meets public health and sanitation requirements.',
            ],
        ],
        'City Assessor\'s Office' => [
            'tax_declaration'      => [
                'title'   => 'New Tax Declaration',
                'details' => 'Upload the latest Tax Declaration for the property issued by the City Assessor\'s Office. This must reflect the current assessed value and land classification.',
            ],
        ],
        'City Treasurer\'s Office' => [
            'realty_tax_clearance' => [
                'title'   => 'Realty Tax Clearance',
                'details' => 'Upload the Realty Tax Clearance issued by the City Treasurer\'s Office confirming that all real property taxes on the subject property are fully paid and up to date.',
            ],
        ],
        'Davao City Water District' => [
            'water_supply_cert'    => [
                'title'   => 'Water Supply Certification',
                'details' => 'Upload the Water Supply Certification issued by the Davao City Water District confirming that the property has access to or can be connected to the city water supply system.',
            ],
        ],
        'Davao Light & Power Company' => [
            'power_supply_cert'    => [
                'title'   => 'Power Supply Certification',
                'details' => 'Upload the Power Supply Certification issued by Davao Light & Power Company (DLPC) confirming that the property has access to or can be connected to the power grid.',
            ],
        ],
        'DENR – Mines & Geosciences Bureau' => [
            'geohazard_cert'       => [
                'title'   => 'Geohazard Certification',
                'details' => 'Upload the Certification issued by the DENR – Mines and Geosciences Bureau (MGB) assessing the property for possible geohazards (e.g., landslide, flooding, liquefaction) and recommending appropriate mitigating measures.',
            ],
        ],
        'DENR – Environmental Management Bureau' => [
            'denr_emb_permit'      => [
                'title'   => 'DENR-EMB Permit',
                'details' => 'Required for industrial and commercial reclassification only. Upload the permit issued by the DENR – Environmental Management Bureau (EMB) covering waste treatment facilities and the permit to discharge effluents.',
            ],
        ],
        'Department of Agriculture' => [
            'safdz_cert'           => [
                'title'   => 'SAFDZ Certification',
                'details' => 'Upload the Certification issued by the Department of Agriculture confirming that the subject property is NOT located within the Strategic Agriculture and Fisheries Development Zone (SAFDZ).',
            ],
        ],
    ];
}

function create_application(int $landlordId, array $data): int
{
    $registry       = 'REG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $encryptedTitle = encrypt_sensitive($data['land_title_reference'] ?? '');

    $pdo  = db();
    $stmt = $pdo->prepare(
        'INSERT INTO applications (
            landlord_id, registry_number,
            account_name, account_address,
            corporation_name, representative_name,
            property_title, property_address, coordinates,
            land_polygon_geojson, land_polygon_area_sqm,
            type_of_project, lot_area, building_area, project_cost,
            nature_of_application, nature_of_application_other,
            right_over_land,
            existing_land_use, existing_land_use_other,
            sworn_statement,
            sensitive_land_title_enc, sensitive_land_title_nonce
        ) VALUES (
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?,
            ?, ?,
            ?,
            ?, ?
        )'
    );

    $natureOther   = ($data['nature_of_application'] ?? '') === 'others'
                     ? trim($data['nature_of_application_other'] ?? '') : null;
    $landUseOther  = ($data['existing_land_use'] ?? '') === 'others'
                     ? trim($data['existing_land_use_other'] ?? '') : null;

    $stmt->execute([
        $landlordId,
        $registry,
        trim($data['account_name']),
        trim($data['account_address']),
        trim($data['corporation_name']   ?? '') ?: null,
        trim($data['representative_name'] ?? '') ?: null,
        trim($data['property_title']),
        trim($data['property_address']),
        trim($data['coordinates'] ?? '') ?: null,
        trim($data['land_polygon_geojson']  ?? '') ?: null,
        is_numeric($data['land_polygon_area_sqm'] ?? '') ? (float)$data['land_polygon_area_sqm'] : null,
        trim($data['type_of_project']    ?? '') ?: null,
        is_numeric($data['lot_area']      ?? '') ? (float)$data['lot_area']      : null,
        is_numeric($data['building_area'] ?? '') ? (float)$data['building_area'] : null,
        is_numeric($data['project_cost']  ?? '') ? (float)$data['project_cost']  : null,
        in_array($data['nature_of_application'] ?? '', ['new_development','improvement','others'], true)
            ? $data['nature_of_application'] : null,
        $natureOther,
        in_array($data['right_over_land'] ?? '', ['owner','lessee'], true)
            ? $data['right_over_land'] : null,
        in_array($data['existing_land_use'] ?? '', ['residential','commercial','industrial','institutional','agricultural','others'], true)
            ? $data['existing_land_use'] : null,
        $landUseOther,
        !empty($data['sworn_statement']) ? 1 : 0,
        $encryptedTitle['ciphertext'],
        $encryptedTitle['nonce'],
    ]);

    $applicationId = (int)$pdo->lastInsertId();
    if ($applicationId === 0) {
        throw new RuntimeException('INSERT into applications returned lastInsertId() = 0. Row count: ' . $stmt->rowCount());
    }

    seed_requirement_rows($applicationId);

    // ── Generate vicinity map PDF from drawn land boundary centroid ─────────
    // Primary: explicit latitude/longitude hidden inputs (written by the polygon JS).
    // Fallback: parse the "lat, lng" string stored in coordinates.
    $lat = is_numeric($data['latitude']  ?? '') ? (float)$data['latitude']  : null;
    $lng = is_numeric($data['longitude'] ?? '') ? (float)$data['longitude'] : null;

    if (($lat === null || $lng === null) && !empty($data['coordinates'])) {
        $parts = explode(',', (string)$data['coordinates']);
        if (count($parts) === 2) {
            $parsedLat = trim($parts[0]);
            $parsedLng = trim($parts[1]);
            if (is_numeric($parsedLat) && is_numeric($parsedLng)) {
                $lat = (float)$parsedLat;
                $lng = (float)$parsedLng;
            }
        }
    }

    // Last resort: derive centroid from the GeoJSON polygon itself
    if (($lat === null || $lng === null) && !empty($data['land_polygon_geojson'])) {
        try {
            $gj = json_decode((string)$data['land_polygon_geojson'], true);
            $coords = null;
            // FeatureCollection → first Feature → Polygon coordinates[0]
            if (isset($gj['features'][0]['geometry']['coordinates'][0])) {
                $coords = $gj['features'][0]['geometry']['coordinates'][0];
            } elseif (isset($gj['geometry']['coordinates'][0])) {
                $coords = $gj['geometry']['coordinates'][0];
            } elseif (isset($gj['coordinates'][0])) {
                $coords = $gj['coordinates'][0];
            }
            if (is_array($coords) && count($coords) > 0) {
                $sumLat = 0.0; $sumLng = 0.0; $n = count($coords);
                foreach ($coords as $pt) {
                    $sumLng += (float)$pt[0];
                    $sumLat += (float)$pt[1];
                }
                $lat = $sumLat / $n;
                $lng = $sumLng / $n;
            }
        } catch (Throwable $e) { /* ignore malformed GeoJSON */ }
    }

    if ($lat !== null && $lng !== null) {
        try {
            $pdfPath = generate_vicinity_map_pdf(
                lat:           $lat,
                lng:           $lng,
                applicantName: trim($data['account_name']),
                projectName:   trim($data['property_title']),
                registryNumber: $registry
            );
            // Store the path on the application record
            $pdo->prepare('UPDATE applications SET vicinity_map_pdf_path = ? WHERE id = ?')
                ->execute([$pdfPath, $applicationId]);

            // Auto-attach the generated PDF to the vicinity_map requirement document row
            $absPath  = __DIR__ . '/../' . $pdfPath;
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
                    $applicationId,
                ]);
            }
        } catch (Throwable $e) {
            // PDF generation failure is non-fatal — log it and continue
            audit_log($landlordId, 'VICINITY_MAP_PDF_FAILED', 'applications', $applicationId,
                ['error' => $e->getMessage()]);
        }
    }

    audit_log($landlordId, 'APPLICATION_CREATED', 'applications', $applicationId, ['registry_number' => $registry]);
    return $applicationId;
}

function seed_requirement_rows(int $applicationId): void
{
    $stmt = db()->prepare(
        'INSERT INTO requirement_documents (application_id, requirement_key, group_name, title) VALUES (?, ?, ?, ?)'
    );
    foreach (required_documents() as $group => $documents) {
        foreach ($documents as $key => $doc) {
            $stmt->execute([$applicationId, $key, $group, $doc['title']]);
        }
    }
}

/**
 * Return the effective requirement list, merging any Zoning Officer overrides
 * from the requirement_guidelines table on top of the code defaults.
 * Structure: [ group_name => [ requirement_key => ['title'=>…,'details'=>…] ] ]
 */
function effective_requirements(): array
{
    $base = required_documents();

    // Load all active overrides in one query
    $rows = db()->query(
        'SELECT requirement_key, title, details FROM requirement_guidelines WHERE is_active = 1'
    )->fetchAll();

    $overrides = [];
    foreach ($rows as $row) {
        $overrides[$row['requirement_key']] = [
            'title'   => $row['title'],
            'details' => $row['details'],
        ];
    }

    if (empty($overrides)) {
        return $base;
    }

    // Merge overrides into base structure
    $merged = [];
    foreach ($base as $group => $docs) {
        $merged[$group] = [];
        foreach ($docs as $key => $doc) {
            $merged[$group][$key] = isset($overrides[$key])
                ? array_merge($doc, $overrides[$key])
                : $doc;
        }
    }
    return $merged;
}

function landlord_compliance_status(int $landlordId): array
{
    $approved = db()->prepare('SELECT * FROM applications WHERE landlord_id = ? AND phase_status = "APPROVED" ORDER BY updated_at DESC LIMIT 1');
    $approved->execute([$landlordId]);
    if ($row = $approved->fetch()) {
        return ['state' => 'ELIGIBLE', 'label' => 'Eligible to List Property', 'source' => 'application', 'record' => $row];
    }

    $verified = db()->prepare('SELECT * FROM compliance_uploads WHERE landlord_id = ? AND status = "VERIFIED" ORDER BY reviewed_at DESC LIMIT 1');
    $verified->execute([$landlordId]);
    if ($row = $verified->fetch()) {
        return ['state' => 'ELIGIBLE', 'label' => 'Eligible to List Property', 'source' => 'skip', 'record' => $row];
    }

    $underReview = db()->prepare(
        'SELECT phase_status FROM applications WHERE landlord_id = ? AND phase_status NOT IN ("APPROVED","DISAPPROVED") ORDER BY updated_at DESC LIMIT 1'
    );
    $underReview->execute([$landlordId]);
    if ($row = $underReview->fetch()) {
        return ['state' => 'UNDER_REVIEW', 'label' => 'Under Review', 'source' => 'application', 'record' => $row];
    }

    $pendingSkip = db()->prepare('SELECT * FROM compliance_uploads WHERE landlord_id = ? AND status = "PENDING_VERIFICATION" ORDER BY created_at DESC LIMIT 1');
    $pendingSkip->execute([$landlordId]);
    if ($row = $pendingSkip->fetch()) {
        return ['state' => 'UNDER_REVIEW', 'label' => 'Under Review', 'source' => 'skip', 'record' => $row];
    }

    return ['state' => 'REQUIRED', 'label' => 'Compliance Required', 'source' => null, 'record' => null];
}

function currency_php(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function advance_application(int $applicationId, string $status, int $process): void
{
    $businessStatus = match ($status) {
        'DRAFT' => 'pending_documents',
        'SUBMITTED', 'PRE_EVALUATION' => 'under_evaluation',
        'PAYMENT_PENDING' => 'for_payment',
        'PAID', 'INSPECTION_SCHEDULED', 'INSPECTION_DONE' => 'for_inspection',
        'FOR_MEETING', 'DELIBERATION', 'DEFERRED' => 'under_deliberation',
        'APPROVED' => 'approved',
        'DISAPPROVED' => 'rejected',
        default => 'pending_documents',
    };
    $stmt = db()->prepare('UPDATE applications SET phase_status = ?, status = ?, current_process = ? WHERE id = ?');
    $stmt->execute([$status, $businessStatus, $process, $applicationId]);
}
