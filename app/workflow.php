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

/**
 * Normalise an address string for comparison.
 * Lowercases, collapses whitespace, strips punctuation so minor formatting
 * differences ("Brgy. 1" vs "brgy 1") still match.
 */
function normalise_address(string $address): string
{
    $a = mb_strtolower(trim($address));
    $a = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $a); // strip punctuation
    $a = preg_replace('/\s+/', ' ', $a);               // collapse spaces
    return trim($a);
}

/**
 * Property-specific compliance check.
 *
 * ELIGIBLE when:
 *   - An APPROVED application exists for this landlord whose property_address
 *     normalises to the same value as $propertyAddress, OR
 *   - A VERIFIED compliance_uploads row exists for this landlord AND the same
 *     normalised property_address.
 *
 * UNDER_REVIEW when either record exists but is not yet approved/verified.
 *
 * REQUIRED otherwise — including when another address is approved but this one
 * is not yet in the system.
 *
 * @param int    $landlordId
 * @param string $propertyAddress  The address of the specific listing being created.
 */
function property_compliance_status(int $landlordId, string $propertyAddress): array
{
    $norm = normalise_address($propertyAddress);

    // ── Approved CPDO application for this address ────────────────────────
    $appStmt = db()->prepare(
        'SELECT * FROM applications
         WHERE landlord_id = ? AND phase_status = "APPROVED"
         ORDER BY updated_at DESC'
    );
    $appStmt->execute([$landlordId]);
    foreach ($appStmt->fetchAll() as $row) {
        if (normalise_address((string)($row['property_address'] ?? '')) === $norm) {
            return ['state' => 'ELIGIBLE', 'label' => 'Eligible to List Property',
                    'source' => 'application', 'record' => $row];
        }
    }

    // ── Verified skip-path upload for this address ────────────────────────
    $skipStmt = db()->prepare(
        'SELECT * FROM compliance_uploads
         WHERE landlord_id = ? AND status = "VERIFIED"
         ORDER BY reviewed_at DESC'
    );
    $skipStmt->execute([$landlordId]);
    foreach ($skipStmt->fetchAll() as $row) {
        $rowAddr = (string)($row['property_address'] ?? $row['property_title'] ?? '');
        if (normalise_address($rowAddr) === $norm) {
            return ['state' => 'ELIGIBLE', 'label' => 'Eligible to List Property',
                    'source' => 'skip', 'record' => $row];
        }
    }

    // ── In-progress CPDO application for this address ─────────────────────
    $reviewStmt = db()->prepare(
        'SELECT * FROM applications
         WHERE landlord_id = ? AND phase_status NOT IN ("APPROVED","DISAPPROVED")
         ORDER BY updated_at DESC'
    );
    $reviewStmt->execute([$landlordId]);
    foreach ($reviewStmt->fetchAll() as $row) {
        if (normalise_address((string)($row['property_address'] ?? '')) === $norm) {
            return ['state' => 'UNDER_REVIEW', 'label' => 'Under Review',
                    'source' => 'application', 'record' => $row];
        }
    }

    // ── Pending skip-path upload for this address ─────────────────────────
    $pendStmt = db()->prepare(
        'SELECT * FROM compliance_uploads
         WHERE landlord_id = ? AND status = "PENDING_VERIFICATION"
         ORDER BY created_at DESC'
    );
    $pendStmt->execute([$landlordId]);
    foreach ($pendStmt->fetchAll() as $row) {
        $rowAddr = (string)($row['property_address'] ?? $row['property_title'] ?? '');
        if (normalise_address($rowAddr) === $norm) {
            return ['state' => 'UNDER_REVIEW', 'label' => 'Under Review',
                    'source' => 'skip', 'record' => $row];
        }
    }

    return ['state' => 'REQUIRED', 'label' => 'Compliance Required', 'source' => null, 'record' => null];
}

/**
 * Determine a landlord's eligibility based on required landlord-level documents.
 * Returns array: [
 *   'passed' => bool,
 *   'status' => 'VERIFIED'|'PENDING_VERIFICATION'|'REJECTED'|'NONE',
 *   'missing' => array of requirement keys still missing,
 *   'record' => compliance_uploads row or null
 * ]
 */
function landlord_eligibility_status(int $landlordId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM compliance_uploads WHERE landlord_id = ? ORDER BY reviewed_at DESC, created_at DESC');
    $stmt->execute([$landlordId]);
    $rows = $stmt->fetchAll();

    $required = [
        'mayors_business_permit_path' => 'mayors_permit',
        'barangay_business_clearance_path' => 'barangay_business_clearance',
        'bir_registration_path' => 'bir_registration',
        'government_id_path' => 'government_id',
    ];

    foreach ($rows as $row) {
        $status = strtoupper((string)($row['status'] ?? ''));
        $missing = [];
        foreach ($required as $col => $key) {
            if (empty($row[$col])) {
                $missing[] = $key;
            }
        }
        // Ensure government ID metadata is present if the file is uploaded
        if (!empty($row['government_id_path']) && (empty($row['government_id_type']) || empty($row['government_id_number']))) {
            if (!in_array('government_id', $missing, true)) {
                $missing[] = 'government_id';
            }
        }

        if ($status === 'VERIFIED' && empty($missing)) {
            return ['passed' => true, 'status' => 'VERIFIED', 'missing' => [], 'record' => $row];
        }

        // If a record exists but not verified, return its current state and missing items
        if (in_array($status, ['PENDING_VERIFICATION', 'REJECTED'], true)) {
            return ['passed' => false, 'status' => $status, 'missing' => $missing, 'record' => $row];
        }
    }

    // No compliance_uploads record found for this landlord
    return ['passed' => false, 'status' => 'NONE', 'missing' => array_values(['mayors_permit','barangay_business_clearance','bir_registration','government_id']), 'record' => null];
}

/**
 * Backwards-compatible shim for older callers.
 * Maps legacy return shape to the newer landlord_eligibility_status.
 */
function landlord_compliance_status(int $landlordId): array
{
    $res = landlord_eligibility_status($landlordId);
    $label = $res['passed'] ? 'Eligible' : 'Compliance Required';
    $source = $res['record'] ? 'skip' : null;
    return ['state' => $res['passed'] ? 'ELIGIBLE' : 'REQUIRED', 'label' => $label, 'source' => $source, 'record' => $res['record']];
}

/**
 * Check whether a property is allowed to be listed/published.
 * Returns ['allowed' => bool, 'reasons' => array, 'info' => mixed]
 */
function property_listing_allowed(int $propertyId): array
{
    $pdo = db();
    $pStmt = $pdo->prepare('SELECT * FROM properties WHERE id = ?');
    $pStmt->execute([$propertyId]);
    $property = $pStmt->fetch();
    if (!$property) {
        return ['allowed' => false, 'reasons' => ['property_not_found'], 'info' => null];
    }

    $reasons = [];

    // Prefer compliance_uploads (skip-path)
    if (!empty($property['compliance_upload_id'])) {
        $cStmt = $pdo->prepare('SELECT * FROM compliance_uploads WHERE id = ?');
        $cStmt->execute([(int)$property['compliance_upload_id']]);
        $c = $cStmt->fetch();
        if (!$c) {
            $reasons[] = 'compliance_record_missing';
        } else {
            if (strtoupper((string)$c['status']) !== 'VERIFIED') {
                $reasons[] = 'compliance_not_verified';
            }
            if (empty($c['certificate_of_occupancy_path'])) {
                $reasons[] = 'missing_certificate_of_occupancy';
            }
            if (empty($c['fire_safety_inspection_certificate_path'])) {
                $reasons[] = 'missing_fsic';
            }
        }
    }

    // If linked to a CPDO application, also allow per-application evaluation records
    if (!empty($property['application_id'])) {
        $reqs = ['certificate_of_occupancy', 'fire_safety_inspection_certificate'];
        $placeholders = implode(',', array_fill(0, count($reqs), '?'));
        $params = array_merge([(int)$property['application_id']], $reqs);
        $dStmt = $pdo->prepare("SELECT requirement_key, evaluation_status FROM requirement_documents WHERE application_id = ? AND requirement_key IN ($placeholders)");
        $dStmt->execute($params);
        $found = [];
        foreach ($dStmt->fetchAll() as $row) {
            $found[$row['requirement_key']] = $row['evaluation_status'];
        }
        foreach ($reqs as $r) {
            if (!isset($found[$r]) || strtoupper($found[$r]) !== 'PASSED') {
                $reasons[] = 'application_missing_' . $r;
            }
        }
    }

    $allowed = empty($reasons);
    return ['allowed' => $allowed, 'reasons' => array_values(array_unique($reasons)), 'info' => $property];
}

/**
 * Admin helper to mark a compliance_uploads record as VERIFIED or REJECTED.
 * Only a user with role = 'admin' is permitted to perform this action.
 */
function admin_verify_compliance_upload(int $complianceId, int $adminUserId, string $newStatus, ?string $notes = null): bool
{
    $pdo = db();
    $userStmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $userStmt->execute([$adminUserId]);
    $user = $userStmt->fetch();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        throw new RuntimeException('Only System Admin can perform eligibility verification.');
    }

    $valid = ['VERIFIED', 'REJECTED'];
    $newStatus = strtoupper($newStatus);
    if (!in_array($newStatus, $valid, true)) {
        throw new InvalidArgumentException('Invalid status. Use VERIFIED or REJECTED.');
    }

    $cStmt = $pdo->prepare('SELECT * FROM compliance_uploads WHERE id = ?');
    $cStmt->execute([$complianceId]);
    $c = $cStmt->fetch();
    if (!$c) {
        throw new RuntimeException('Compliance upload record not found.');
    }

    $update = $pdo->prepare('UPDATE compliance_uploads SET status = ?, officer_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
    $update->execute([$newStatus, $notes, $adminUserId, $complianceId]);

    $action = $newStatus === 'VERIFIED' ? 'ELIGIBILITY_VERIFIED' : 'ELIGIBILITY_REJECTED';
    audit_log($adminUserId, $action, 'compliance_uploads', $complianceId, ['notes' => $notes]);

    // Notify landlord
    $landlordId = (int)($c['landlord_id'] ?? 0);
    if ($landlordId > 0) {
        $title = $newStatus === 'VERIFIED' ? 'Eligibility Documents Verified' : 'Eligibility Documents Rejected';
        $message = $newStatus === 'VERIFIED'
            ? 'Your eligibility documents have been verified by System Admin.'
            : 'Your eligibility documents were rejected by System Admin. Please review the officer notes and re-upload.';
        $pdo->prepare('INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$landlordId, $title, $message]);
    }

    return true;
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
