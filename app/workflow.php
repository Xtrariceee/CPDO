<?php

declare(strict_types=1);

function required_documents(): array
{
    return [
        'City Planning and Development Office' => [
            'application_form'       => 'Application Form (1 original)',
            'zoning_certification'   => 'Zoning Certification (1 photocopy)',
        ],
        'Register of Deeds' => [
            'proof_of_ownership'     => 'Certified true copy of title(s) / Contract of Lease / Deed of Sale / any proof of ownership (1 original for validation, 1 photocopy)',
        ],
        'Department of Public Works & Highways / City Engineer\'s Office' => [
            'right_of_way'           => 'Right-of-way of access road (right to use or deed of sale) (1 photocopy)',
        ],
        'Applicant' => [
            'site_development_plan'  => 'Site development plan reflecting land distribution (green area, parking, building footprint, etc.), property line & access road if property is an interior lot (1 original)',
            'vicinity_map'           => 'Vicinity map showing major landmarks/structures within a radius of 200 meters (1 original)',
            'affidavit_neighbor'     => 'Affidavit of Neighbor\'s Consent',
        ],
        'Barangay Council' => [
            'barangay_resolution'    => 'Barangay Council Resolution Interposing No Objection (1 photocopy)',
        ],
        'Barangay Development Council' => [
            'bdc_resolution'         => 'Barangay Development Council Resolution favorably endorsing the project (1 photocopy)',
        ],
        'City Engineer\'s Office' => [
            'drainage_clearance'     => 'Drainage clearance (1 photocopy)',
        ],
        'City Environment & Natural Resources Office' => [
            'solid_waste_certificate' => 'Solid Waste Management Plan Certificate (1 photocopy)',
        ],
        'City Health Office' => [
            'sanitation_clearance'   => 'Sanitation clearance (1 photocopy)',
        ],
        'City Assessor\'s Office' => [
            'tax_declaration'        => 'New tax declaration (1 photocopy)',
        ],
        'City Treasurer\'s Office' => [
            'realty_tax_clearance'   => 'Realty tax clearance (1 photocopy)',
        ],
        'Davao City Water District' => [
            'water_supply_cert'      => 'Water supply certification (1 photocopy)',
        ],
        'Davao Light & Power Company' => [
            'power_supply_cert'      => 'Power supply certification (1 photocopy)',
        ],
        'DENR – Mines & Geosciences Bureau' => [
            'geohazard_cert'         => 'Certification for possible geohazard and recommended mitigating measures (1 photocopy)',
        ],
        'DENR – Environmental Management Bureau' => [
            'denr_emb_permit'        => 'DENR-EMB for waste treatment facilities and permit to discharge effluents (for industrial and commercial reclassification)',
        ],
        'Department of Agriculture' => [
            'safdz_cert'             => 'Certification that property is not within the Strategic Agriculture and Fisheries Development Zone (SAFDZ)',
        ],
    ];
}

function create_application(int $landlordId, array $data): int
{
    $registry = 'REG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $encryptedTitle = encrypt_sensitive($data['land_title_reference'] ?? '');

    $stmt = db()->prepare(
        'INSERT INTO applications (landlord_id, registry_number, account_name, account_address, property_title, property_address, coordinates, sensitive_land_title_enc, sensitive_land_title_nonce)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $landlordId,
        $registry,
        $data['account_name'],
        $data['account_address'],
        $data['property_title'],
        $data['property_address'],
        $data['coordinates'] ?? null,
        $encryptedTitle['ciphertext'],
        $encryptedTitle['nonce'],
    ]);

    $applicationId = (int)db()->lastInsertId();
    seed_requirement_rows($applicationId);
    audit_log($landlordId, 'APPLICATION_CREATED', 'applications', $applicationId, ['registry_number' => $registry]);
    return $applicationId;
}

function seed_requirement_rows(int $applicationId): void
{
    $stmt = db()->prepare(
        'INSERT INTO requirement_documents (application_id, requirement_key, group_name, title) VALUES (?, ?, ?, ?)'
    );
    foreach (required_documents() as $group => $documents) {
        foreach ($documents as $key => $title) {
            $stmt->execute([$applicationId, $key, $group, $title]);
        }
    }
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
    return '₱' . number_format($amount, 2);
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
