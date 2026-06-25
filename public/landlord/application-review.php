<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

$appId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$appId) {
    http_response_code(400);
    exit('Invalid application ID.');
}

// Fetch the rental application details
$stmt = db()->prepare(
    'SELECT ra.*, p.id AS prop_id, p.title AS property_title, p.address AS property_address, p.monthly_rent AS property_rent, p.extended_details AS property_details,
            CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name, u.email AS tenant_email, u.phone_number AS tenant_phone, u.date_of_birth AS tenant_dob
     FROM rental_applications ra
     JOIN properties p ON p.id = ra.property_id
     JOIN users u ON u.id = ra.tenant_id
     WHERE ra.id = ? AND p.landlord_id = ?'
);
$stmt->execute([$appId, (int)$user['id']]);
$application = $stmt->fetch();

if (!$application) {
    http_response_code(404);
    exit('Application not found.');
}

$propDetails = json_decode($application['property_details'] ?? '{}', true) ?: [];
$vetting = $propDetails['vetting_criteria'] ?? [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'accept') {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Update application status to ACCEPTED
            $pdo->prepare('UPDATE rental_applications SET status = "ACCEPTED" WHERE id = ?')
                ->execute([$appId]);

            // Determine dates
            $startDate = $application['requested_move_in_date'] ?: date('Y-m-d', strtotime('+7 days'));
            $endDate = date('Y-m-d', strtotime($startDate . ' +1 year'));
            $monthlyRent = (float)$application['property_rent'];
            $securityDeposit = $monthlyRent * 2.0;
            $advancePayment = $monthlyRent * 1.0;
            $terms = "1. RENT & FEES: The Tenant agrees to pay the monthly rent on or before the due date.\n2. SECURITY DEPOSIT: The security deposit will be held by the Landlord and refunded within 30 days of move-out, minus any damages.\n3. USE OF PREMISES: The premises shall be used solely as a private residential dwelling.\n4. MAINTENANCE: The Tenant shall maintain the unit in a clean, sanitary, and undamaged condition.\n5. RULES & COMPLIANCE: The Tenant agrees to comply with all building regulations and community rules.";

            // Insert placeholder lease into leases table
            $insLease = $pdo->prepare(
                'INSERT INTO leases (tenant_id, property_id, rental_application_id, monthly_rent, security_deposit, advance_payment, start_date, end_date, terms, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending_signature", NOW())'
            );
            $insLease->execute([
                (int)$application['tenant_id'],
                (int)$application['property_id'],
                $appId,
                $monthlyRent,
                $securityDeposit,
                $advancePayment,
                $startDate,
                $endDate,
                $terms
            ]);
            $leaseId = $pdo->lastInsertId();

            // Notify tenant
            $notif = $pdo->prepare('INSERT INTO notifications (user_id, rental_application_id, title, message, created_at) VALUES (?, ?, ?, ?, NOW())');
            $notif->execute([
                (int)$application['tenant_id'],
                $appId,
                'Application Approved & Lease Ready',
                'Your application for "' . $application['property_title'] . '" was approved! A digital lease agreement is ready for your signature under Lease & Documents.'
            ]);

            audit_log((int)$user['id'], 'RENTAL_APPLICATION_APPROVED', 'rental_applications', $appId, ['lease_id' => $leaseId]);
            $pdo->commit();

            $_SESSION['flash_success'] = 'Application approved. Lease agreement initialized and sent to tenant.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = 'Error approving application: ' . $e->getMessage();
        }

        redirect('landlord/application-review.php?id=' . $appId);

    } elseif ($action === 'decline') {
        db()->prepare('UPDATE rental_applications SET status = "DECLINED" WHERE id = ?')
            ->execute([$appId]);

        // Notify tenant
        $notif = db()->prepare('INSERT INTO notifications (user_id, rental_application_id, title, message, created_at) VALUES (?, ?, ?, ?, NOW())');
        $notif->execute([
            (int)$application['tenant_id'],
            $appId,
            'Application Declined',
            'Your application for "' . $application['property_title'] . '" was declined by the landlord.'
        ]);

        audit_log((int)$user['id'], 'RENTAL_APPLICATION_DECLINED', 'rental_applications', $appId);
        $_SESSION['flash_success'] = 'Application declined.';
        redirect('landlord/application-review.php?id=' . $appId);

    } elseif ($action === 'send_message') {
        $msgText = trim($_POST['message_text'] ?? '');
        if ($msgText !== '') {
            db()->prepare('INSERT INTO rental_application_messages (application_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())')
                ->execute([$appId, (int)$user['id'], $msgText]);

            // Notify tenant of new message
            $notif = db()->prepare('INSERT INTO notifications (user_id, rental_application_id, title, message, created_at) VALUES (?, ?, ?, ?, NOW())');
            $notif->execute([
                (int)$application['tenant_id'],
                $appId,
                'New Message from Landlord',
                user_full_name($user) . ' sent you a message regarding "' . $application['property_title'] . '".'
            ]);

            audit_log((int)$user['id'], 'RENTAL_APPLICATION_MESSAGE_SENT', 'rental_applications', $appId);
        }
        redirect('landlord/application-review.php?id=' . $appId);

    } elseif ($action === 'draft_agreement') {
        $monthlyRent = (float)($_POST['monthly_rent'] ?? $application['property_rent']);
        $securityDeposit = (float)($_POST['security_deposit'] ?? 0);
        $advancePayment = (float)($_POST['advance_payment'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $terms = trim($_POST['terms'] ?? '');
        
        $scheduledAt = $_POST['signing_scheduled_at'] ?? '';
        $location = trim($_POST['signing_location'] ?? '');

        if (!$startDate || !$endDate) {
            $_SESSION['flash_error'] = 'Lease start and end dates are required.';
            redirect('landlord/application-review.php?id=' . $appId);
        }

        $pdo = db();
        // Check if agreement already exists in leases
        $chk = $pdo->prepare('SELECT id FROM leases WHERE rental_application_id = ?');
        $chk->execute([$appId]);
        $existingLease = $chk->fetch();

        if ($existingLease) {
            $upd = $pdo->prepare('UPDATE leases 
                                  SET monthly_rent = ?, security_deposit = ?, advance_payment = ?, start_date = ?, end_date = ?, terms = ?, signing_scheduled_at = ?, signing_location = ?, status = "pending_signature" 
                                  WHERE rental_application_id = ?');
            $upd->execute([$monthlyRent, $securityDeposit, $advancePayment, $startDate, $endDate, $terms ?: null, $scheduledAt ?: null, $location ?: null, $appId]);
            $leaseId = (int)$existingLease['id'];
        } else {
            $ins = $pdo->prepare('INSERT INTO leases (tenant_id, property_id, rental_application_id, monthly_rent, security_deposit, advance_payment, start_date, end_date, terms, status, signing_scheduled_at, signing_location, created_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending_signature", ?, ?, NOW())');
            $ins->execute([(int)$application['tenant_id'], (int)$application['property_id'], $appId, $monthlyRent, $securityDeposit, $advancePayment, $startDate, $endDate, $terms ?: null, $scheduledAt ?: null, $location ?: null]);
            $leaseId = (int)$pdo->lastInsertId();
        }

        // Notify tenant
        $notif = $pdo->prepare('INSERT INTO notifications (user_id, rental_application_id, title, message, created_at) VALUES (?, ?, ?, ?, NOW())');
        $notif->execute([
            (int)$application['tenant_id'],
            $appId,
            'Agreement Draft Updated',
            'Landlord ' . user_full_name($user) . ' has updated the lease agreement draft. Please review and sign.'
        ]);

        audit_log((int)$user['id'], 'LEASE_AGREEMENT_DRAFTED', 'leases', $leaseId, ['application_id' => $appId]);
        $_SESSION['flash_success'] = 'Agreement draft updated and sent to tenant successfully!';
        redirect('landlord/application-review.php?id=' . $appId);
    }
}

// Fetch messages
$msgStmt = db()->prepare(
    'SELECT ram.*, CONCAT_WS(" ", u.first_name, u.last_name) AS sender_name, u.role AS sender_role
     FROM rental_application_messages ram
     JOIN users u ON u.id = ram.sender_id
     WHERE ram.application_id = ?
     ORDER BY ram.created_at ASC'
);
$msgStmt->execute([$appId]);
$messages = $msgStmt->fetchAll();

// Fetch lease agreement from leases
$leaseStmt = db()->prepare('SELECT * FROM leases WHERE rental_application_id = ?');
$leaseStmt->execute([$appId]);
$lease = $leaseStmt->fetch();

require __DIR__ . '/../partials/header.php';
?>

<style>
.review-page {
    min-height: calc(100vh - 64px);
    background: #f8fafc;
    padding: 32px 0 64px;
}

.review-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.02);
    margin-bottom: 24px;
    transition: all 0.3s ease;
}

.review-card:hover {
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08);
}

.section-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1e293b;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 12px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.match-badge {
    font-size: 0.75rem;
    font-weight: 600;
    padding: 5px 10px;
    border-radius: 12px;
}

.chat-box {
    height: 320px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
    padding: 16px;
}

.chat-msg {
    margin-bottom: 12px;
    max-width: 80%;
    padding: 12px 16px;
    border-radius: 16px;
    font-size: 0.88rem;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}

.chat-msg-sent {
    background: #2563eb;
    color: #ffffff;
    margin-left: auto;
    border-bottom-right-radius: 2px;
}

.chat-msg-received {
    background: #ffffff;
    color: #1e293b;
    border: 1px solid #e2e8f0;
    border-bottom-left-radius: 2px;
}

.metric-row {
    padding: 12px 0;
    border-bottom: 1px dashed #e2e8f0;
}

.metric-row:last-child {
    border-bottom: none;
}

.doc-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    background: #f1f5f9;
    color: #334155;
    font-weight: 600;
    font-size: 0.82rem;
    text-decoration: none;
    transition: all 0.2s ease;
    border: 1px solid #cbd5e1;
}

.doc-link:hover {
    background: #e2e8f0;
    color: #0f172a;
}
</style>

<div class="review-page">
    <div class="container-fluid" style="max-width: 1280px; margin: 0 auto; padding: 0 24px;">
        <!-- Header -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div class="d-flex align-items-center gap-3">
                <a class="btn btn-back btn-sm d-inline-flex align-items-center gap-1" href="applications.php" style="border-radius: 8px;">
                    <span aria-hidden="true">&larr;</span> Back to Inquiries
                </a>
                <h1 class="h3 mb-0 fw-bold text-slate-800">Application Screening</h1>
            </div>
            <div>
                <?php
                $statusColor = match ($application['status']) {
                    'PENDING' => 'bg-warning text-dark',
                    'ACCEPTED', 'AGREED' => 'bg-info text-dark',
                    'DRAFT_SENT' => 'bg-primary text-white',
                    'SIGNED' => 'bg-success text-white',
                    'PAID', 'COMPLETED' => 'bg-success text-white',
                    'DECLINED' => 'bg-danger text-white',
                    default => 'bg-secondary text-white'
                };
                ?>
                <span class="badge <?= $statusColor ?> px-4 py-2 fs-6" style="border-radius: 20px;">
                    Status: <?= e($application['status']) ?>
                </span>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left Side: Comprehensive Vetting Review -->
            <div class="col-lg-8">
                <!-- 1. Applicant Profile Card -->
                <div class="review-card p-4">
                    <h2 class="section-title">
                        <span style="font-size: 1.3rem;">👤</span> Applicant Profile & Contact Info
                    </h2>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="text-secondary small fw-bold uppercase">Legal Name</label>
                            <div class="fw-semibold text-slate-800 p-2 bg-light rounded border"><?= e($application['tenant_name']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <label class="text-secondary small fw-bold uppercase">Email Address</label>
                            <div class="fw-semibold text-slate-800 p-2 bg-light rounded border"><?= e($application['tenant_email']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <label class="text-secondary small fw-bold uppercase">Phone Number</label>
                            <div class="fw-semibold text-slate-800 p-2 bg-light rounded border"><?= e($application['tenant_phone'] ?: 'N/A') ?></div>
                        </div>
                    </div>
                </div>

                <!-- 2. Core Financial Vetting -->
                <div class="review-card p-4">
                    <h2 class="section-title">
                        <span style="font-size: 1.3rem;">💵</span> Core Financial Criteria
                    </h2>
                    
                    <div class="metric-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold text-slate-800">Income-to-Rent Ratio (3x Rule)</div>
                            <div class="text-secondary small">Gross monthly income must be at least 3x the monthly rent (₱<?= number_format($application['property_rent'] * 3, 2) ?>)</div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-slate-800">₱<?= number_format($application['monthly_income'], 2) ?> / mo</div>
                            <div>
                                <?php
                                $rent3x = $application['property_rent'] * 3;
                                if ($application['monthly_income'] >= $rent3x) {
                                    echo '<span class="badge bg-success-subtle text-success match-badge border border-success-subtle">✓ Meets 3x Rule</span>';
                                } else {
                                    echo '<span class="badge bg-danger-subtle text-danger match-badge border border-danger-subtle">⚠ Below 3x Rule</span>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="metric-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold text-slate-800">Employment Stability</div>
                            <div class="text-secondary small">Requirement: 6 to 12 consecutive months with current employer</div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-slate-800"><?= (int)($application['employment_stability_months'] ?? 0) ?> Consecutive Months</div>
                            <div>
                                <?php
                                $stability = (int)($application['employment_stability_months'] ?? 0);
                                if ($stability >= 6) {
                                    echo '<span class="badge bg-success-subtle text-success match-badge border border-success-subtle">✓ Stable Employment</span>';
                                } else {
                                    echo '<span class="badge bg-warning-subtle text-warning-emphasis match-badge border border-warning-subtle">⚠ Less than 6 months</span>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="metric-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold text-slate-800">Employment Status & Type</div>
                            <div class="text-secondary small">Current professional engagement category</div>
                        </div>
                        <div class="text-end fw-bold text-slate-800">
                            <?= e($application['employment_status']) ?>
                        </div>
                    </div>

                    <!-- Proof of Income Documents -->
                    <div class="mt-4">
                        <label class="text-secondary small fw-bold uppercase mb-2 d-block">Submitted Proof of Income Documents (Min 2 Required)</label>
                        <div class="p-3 bg-light rounded border border-slate-200">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="fw-semibold text-slate-700 small">Document Category:</span>
                                <span class="badge bg-secondary text-white"><?= e($application['income_proof_type'] ?: 'Not Specified') ?></span>
                            </div>
                            <div class="d-flex flex-wrap gap-3">
                                <?php if (!empty($application['income_proof_path_1'])): ?>
                                    <a href="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $application['income_proof_path_1']) ?>" target="_blank" class="doc-link">
                                        📄 View Income Proof 1
                                    </a>
                                <?php else: ?>
                                    <span class="text-danger small">⚠ First Income Proof Missing</span>
                                <?php endif; ?>

                                <?php if (!empty($application['income_proof_path_2'])): ?>
                                    <a href="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $application['income_proof_path_2']) ?>" target="_blank" class="doc-link">
                                        📄 View Income Proof 2
                                    </a>
                                <?php else: ?>
                                    <span class="text-danger small">⚠ Second Income Proof Missing</span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2 text-secondary small style="font-size:0.75rem;">
                                💡 Note: Applicants were explicitly instructed to redact bank account numbers, credit card numbers, and government tax IDs before uploading.
                            </div>
                        </div>
                    </div>

                    <!-- Guarantor Section if applicable -->
                    <?php if ($application['has_guarantor']): ?>
                        <div class="mt-4 p-3 bg-warning-subtle rounded border border-warning-subtle">
                            <h4 class="h6 fw-bold text-warning-emphasis mb-3"><span style="font-size:1.1rem;">🛡️</span> Co-Signer / Guarantor Details</h4>
                            <div class="row g-3 small">
                                <div class="col-md-4">
                                    <span class="text-secondary">Guarantor Name:</span>
                                    <div class="fw-bold text-slate-800"><?= e($application['guarantor_name']) ?></div>
                                </div>
                                <div class="col-md-4">
                                    <span class="text-secondary">Guarantor Gross Income:</span>
                                    <div class="fw-bold text-slate-800">₱<?= number_format((float)$application['guarantor_income'], 2) ?>/mo</div>
                                </div>
                                <div class="col-md-4">
                                    <span class="text-secondary">Vetting Status:</span>
                                    <div>
                                        <?php
                                        $guarRent4x = $application['property_rent'] * 4;
                                        if ($application['guarantor_income'] >= $guarRent4x) {
                                            echo '<span class="badge bg-success text-white">✓ Meets 4x Rent Rule</span>';
                                        } else {
                                            echo '<span class="badge bg-danger text-white">⚠ Below 4x Rent Rule</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <span class="text-secondary">Employment & Stability:</span>
                                    <div class="fw-bold text-slate-800"><?= e($application['guarantor_employment_status']) ?> (<?= (int)$application['guarantor_stability_months'] ?> mos)</div>
                                </div>
                                <div class="col-md-8">
                                    <span class="text-secondary">Income Verification Document:</span>
                                    <div>
                                        <?php if (!empty($application['guarantor_income_proof_path'])): ?>
                                            <a href="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $application['guarantor_income_proof_path']) ?>" target="_blank" class="doc-link py-1 px-2 mt-1">
                                                📄 View Guarantor Income Proof
                                            </a>
                                        <?php else: ?>
                                            <span class="text-danger">Missing</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 3. Background, Identity, & Credit Vetting -->
                <div class="review-card p-4">
                    <h2 class="section-title">
                        <span style="font-size: 1.3rem;">🛡️</span> Identity, Credit, &amp; Public Records Vetting
                    </h2>

                    <div class="row g-3 mb-4">
                        <!-- Government ID Verification -->
                        <div class="col-md-12">
                            <label class="text-secondary small fw-bold uppercase mb-2 d-block">Identity Verification</label>
                            <div class="p-3 bg-light rounded border border-slate-200 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-slate-800">
                                        <?= $application['gov_id_type'] ? e(strtoupper(str_replace('_', ' ', $application['gov_id_type']))) : 'No ID Submitted' ?>
                                    </div>
                                    <?php if ($application['gov_id_number']): ?>
                                        <div class="text-secondary small">ID Number: <?= e($application['gov_id_number']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if (!empty($application['gov_id_path'])): ?>
                                        <a href="<?= e(rtrim($config['app']['base_url'], '/') . '/' . $application['gov_id_path']) ?>" target="_blank" class="doc-link">
                                            📄 View Government ID
                                        </a>
                                    <?php else: ?>
                                        <span class="text-danger small fw-bold">⚠ Valid ID Missing</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Credit Declarations -->
                        <div class="col-md-6">
                            <label class="text-secondary small fw-bold uppercase mb-2 d-block">Credit & Financial Reliability</label>
                            <ul class="list-group list-group-flush border rounded">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="small">No active/unresolved bankruptcies (3-5 yrs)</span>
                                    <?php if (!$application['credit_bankruptcies']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">No Records</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Has Bankruptcy</span>
                                    <?php endif; ?>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="small">No history of major collection accounts</span>
                                    <?php if (!$application['credit_collections']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">No Records</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Has Collections</span>
                                    <?php endif; ?>
                                </li>
                            </ul>
                        </div>

                        <!-- Public Records Declarations -->
                        <div class="col-md-6">
                            <label class="text-secondary small fw-bold uppercase mb-2 d-block">Public Records & Compliance Check</label>
                            <ul class="list-group list-group-flush border rounded">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="small">No eviction records (past 7 years)</span>
                                    <?php if (!$application['record_evictions']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Clear</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Eviction Record Found</span>
                                    <?php endif; ?>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="small">No code violations or illegal activity history</span>
                                    <?php if (!$application['record_illegal_activity']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Clear</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Violations Found</span>
                                    <?php endif; ?>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- 4. Rental History & Compliance Track Record -->
                <div class="review-card p-4">
                    <h2 class="section-title">
                        <span style="font-size: 1.3rem;">📋</span> Rental History &amp; Previous Landlords
                    </h2>

                    <div class="row g-3 mb-4">
                        <!-- Landlord Reference 1 -->
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded border border-slate-200">
                                <div class="fw-bold text-slate-800 mb-1">Landlord Reference 1</div>
                                <div class="small mb-1"><span class="text-secondary">Name:</span> <?= e($application['landlord_ref_name_1'] ?: 'None declared') ?></div>
                                <div class="small"><span class="text-secondary">Phone:</span> <?= e($application['landlord_ref_phone_1'] ?: 'N/A') ?></div>
                            </div>
                        </div>

                        <!-- Landlord Reference 2 -->
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded border border-slate-200">
                                <div class="fw-bold text-slate-800 mb-1">Landlord Reference 2</div>
                                <div class="small mb-1"><span class="text-secondary">Name:</span> <?= e($application['landlord_ref_name_2'] ?: 'None declared') ?></div>
                                <div class="small"><span class="text-secondary">Phone:</span> <?= e($application['landlord_ref_phone_2'] ?: 'N/A') ?></div>
                            </div>
                        </div>
                    </div>

                    <label class="text-secondary small fw-bold uppercase mb-2 d-block">Direct Landlord Verification Checklist</label>
                    <div class="p-3 bg-light rounded border border-slate-200">
                        <div class="row g-2 text-slate-700 small">
                            <div class="col-md-6 d-flex align-items-center gap-2">
                                <span class="<?= $application['ref_paid_on_time'] ? 'text-success' : 'text-danger' ?> fw-bold">
                                    <?= $application['ref_paid_on_time'] ? '✓' : '✗' ?>
                                </span>
                                Consistently paid rent and bills on time
                            </div>
                            <div class="col-md-6 d-flex align-items-center gap-2">
                                <span class="<?= $application['ref_clean_sanitary'] ? 'text-success' : 'text-danger' ?> fw-bold">
                                    <?= $application['ref_clean_sanitary'] ? '✓' : '✗' ?>
                                </span>
                                Maintained the property in a clean, sanitary, and undamaged condition
                            </div>
                            <div class="col-md-6 d-flex align-items-center gap-2">
                                <span class="<?= $application['ref_adhered_rules'] ? 'text-success' : 'text-danger' ?> fw-bold">
                                    <?= $application['ref_adhered_rules'] ? '✓' : '✗' ?>
                                </span>
                                Adhered to community guidelines (no noise complaints or offenses)
                            </div>
                            <div class="col-md-6 d-flex align-items-center gap-2">
                                <span class="<?= $application['ref_proper_notice'] ? 'text-success' : 'text-danger' ?> fw-bold">
                                    <?= $application['ref_proper_notice'] ? '✓' : '✗' ?>
                                </span>
                                Gave proper, legal notice before vacating
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 5. Decision Area / Approval / Chat -->
                <?php if ($application['status'] === 'PENDING'): ?>
                    <div class="review-card p-4 border border-warning-subtle" style="background: #fffdf5;">
                        <h2 class="section-title text-slate-800">
                            <span>⚡</span> Direct Action
                        </h2>
                        <p class="small text-secondary mb-4">
                            Reviewing this application indicates it meets the minimum threshold requirements. Clicking **Approve Application** automatically converts this application into a lease draft, pre-filling contact and unit details instantly without requiring the tenant to fill out a separate onboarding form.
                        </p>
                        <div class="d-flex gap-3 justify-content-end">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= $appId ?>">
                                <input type="hidden" name="action" value="decline">
                                <button type="submit" class="btn btn-outline-danger btn-lg px-4" style="border-radius:10px;">Decline Application</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= $appId ?>">
                                <input type="hidden" name="action" value="accept">
                                <button type="submit" class="btn btn-success btn-lg px-4 fw-bold" style="background-color:#10b981; border-color:#10b981; color:#fff; border-radius:10px;">
                                    ✓ Approve &amp; Pre-fill Lease
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Chat & Communication Block -->
                <?php if ($application['status'] !== 'PENDING' && $application['status'] !== 'DECLINED'): ?>
                    <div class="review-card p-4">
                        <h2 class="section-title">
                            <span>💬</span> Message Communication
                        </h2>
                        <div class="chat-box mb-3 d-flex flex-column" id="chatWindow">
                            <?php if (empty($messages)): ?>
                                <div class="text-center text-secondary my-auto small">No messages exchanged yet. Send a message below to start planning a visit!</div>
                            <?php else: ?>
                                <?php foreach ($messages as $msg): ?>
                                    <?php $isSelf = ($msg['sender_id'] == $user['id']); ?>
                                    <div class="chat-msg <?= $isSelf ? 'chat-msg-sent' : 'chat-msg-received' ?>">
                                        <div class="fw-bold" style="font-size:0.75rem; opacity:0.8;">
                                            <?= e($msg['sender_name']) ?> (<?= role_label($msg['sender_role']) ?>)
                                        </div>
                                        <div class="my-1"><?= nl2br(e($msg['message'])) ?></div>
                                        <div class="text-end" style="font-size:0.65rem; opacity:0.6;">
                                            <?= date('M d, g:i A', strtotime($msg['created_at'])) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="d-flex gap-2">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= $appId ?>">
                            <input type="hidden" name="action" value="send_message">
                            <input class="form-control" type="text" name="message_text" placeholder="Type a message to schedule a visit..." required style="border-radius:10px;">
                            <button type="submit" class="btn btn-primary px-4 fw-bold" style="border-radius:10px;">Send</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Side: Agreement Builder -->
            <div class="col-lg-4">
                <?php if ($application['status'] !== 'PENDING' && $application['status'] !== 'DECLINED'): ?>
                    <div class="review-card p-4 border border-primary-subtle" style="background:#f8fafc;">
                        <h2 class="section-title text-slate-800" style="border-color:#3b82f6;">
                            <span>✍️</span> Lease Agreement Builder
                        </h2>
                        <p class="small text-secondary mb-3">
                            Build the lease agreement using pre-defined clauses and live summary updates. Export a printable PDF version after review.
                        </p>

                        <form method="post" id="leaseBuilderForm">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= $appId ?>">
                            <input type="hidden" name="action" value="draft_agreement">

                            <div class="mb-3">
                                <label class="form-label fw-semibold text-slate-700">Template</label>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary lease-template-btn active" data-template="standard">Standard Residential</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary lease-template-btn" data-template="commercial">Commercial</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary lease-template-btn" data-template="custom">Custom</button>
                                </div>
                            </div>

                            <div class="mb-3 p-3 rounded-3" style="background:#ffffff; border:1px solid #e2e8f0;">
                                <h3 class="h6 fw-bold mb-2">Auto-filled Property Details</h3>
                                <div class="small text-secondary mb-2">This section is sourced from the applicant and unit record.</div>
                                <div class="mb-2"><strong>Landlord:</strong> <?= e(user_full_name($user)) ?></div>
                                <div class="mb-2"><strong>Tenant:</strong> <?= e($application['tenant_name']) ?></div>
                                <div class="mb-2"><strong>Unit:</strong> <?= e($application['property_title']) ?></div>
                                <div class="mb-2"><strong>Address:</strong> <?= e($application['property_address']) ?></div>
                                <div class="mb-2"><strong>Proposed Rent:</strong> ₱<?= number_format((float)($lease['monthly_rent'] ?? $application['property_rent']), 2) ?></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold text-slate-700">Add Common Clauses</label>
                                <div id="clauseToggleGroup" class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary clause-toggle-btn" data-clause="late_payment">Late Payment Penalty</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary clause-toggle-btn" data-clause="utilities">Utilities</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary clause-toggle-btn" data-clause="pets">Pets</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary clause-toggle-btn" data-clause="subletting">Subletting</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary clause-toggle-btn" data-clause="maintenance">Maintenance</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary clause-toggle-btn" data-clause="noise">Noise</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary clause-toggle-btn" data-clause="termination">Early Termination</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary clause-toggle-btn" data-clause="custom">Custom Section</button>
                                </div>
                            </div>

                            <div id="clauseCardsContainer" class="mb-3"></div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold text-slate-700">Contract Preview</label>
                                <textarea class="form-control" id="termsInput" name="terms" rows="8" readonly style="border-radius:8px; background:#f8fafc; font-size:0.9rem; line-height:1.45;"><?php
                                    echo e($lease['terms'] ?? "1. RENT & FEES: The Tenant agrees to pay the monthly rent on or before the due date.\n2. SECURITY DEPOSIT: The security deposit will be held by the Landlord and refunded within 30 days of move-out, minus any damages.\n3. USE OF PREMISES: The premises shall be used solely as a private residential dwelling.\n4. MAINTENANCE: The Tenant shall maintain the unit in a clean, sanitary, and undamaged condition.\n5. RULES & COMPLIANCE: The Tenant agrees to comply with all building regulations and community rules.")
                                ?></textarea>
                            </div>

                            <hr style="border-color:#cbd5e1;">
                            <h3 class="h6 fw-bold text-slate-800 mb-3">📅 Signing Schedule</h3>

                            <div class="mb-3">
                                <label class="form-label fw-semibold text-slate-700">Signing Date &amp; Time</label>
                                <input type="datetime-local" class="form-control" name="signing_scheduled_at" value="<?= $lease['signing_scheduled_at'] ? date('Y-m-d\TH:i', strtotime($lease['signing_scheduled_at'])) : '' ?>" style="border-radius:8px;">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold text-slate-700">Signing Location</label>
                                <input type="text" class="form-control" name="signing_location" placeholder="e.g. CPDO Office Lobby or Online Meet link" value="<?= e($lease['signing_location'] ?? '') ?>" style="border-radius:8px;">
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg fw-bold py-2">Update Agreement</button>
                                <button type="button" id="exportPdfBtn" class="btn btn-outline-secondary btn-lg py-2 no-print">Export PDF</button>
                            </div>
                        </form>

                        <div class="mt-4" id="contractSummaryPanel">
                            <div class="p-3 rounded-3" style="background:#ffffff; border:1px solid #e2e8f0;">
                                <h3 class="h6 fw-bold mb-3">Live Contract Summary</h3>
                                <div id="summaryAccountInfo" class="mb-3"></div>
                                <div id="summaryKeyTerms" class="mb-3"></div>
                                <div id="summaryTermination" class="mb-2"></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Auto scroll chat to bottom
var chatWindow = document.getElementById('chatWindow');
if (chatWindow) {
    chatWindow.scrollTop = chatWindow.scrollHeight;
}

(function(){
    const builder = {
        template: 'standard',
        leaseDetails: {
            landlord: '<?= e(user_full_name($user)) ?>',
            tenant: '<?= e($application['tenant_name']) ?>',
            property: '<?= e($application['property_title']) ?>',
            address: '<?= e($application['property_address']) ?>',
            startDate: '<?= e($lease['start_date'] ?? $application['requested_move_in_date'] ?? date('Y-m-d', strtotime('+7 days'))) ?>',
            endDate: '<?= e($lease['end_date'] ?? date('Y-m-d', strtotime(($lease['start_date'] ?? $application['requested_move_in_date'] ?? date('Y-m-d', strtotime('+7 days'))) . ' +1 year'))) ?>',
            rent: '<?= number_format((float)($lease['monthly_rent'] ?? $application['property_rent']), 2) ?>',
            deposit: '<?= number_format((float)($lease['security_deposit'] ?? ($application['property_rent'] * 2.0)), 2) ?>',
            advance: '<?= number_format((float)($lease['advance_payment'] ?? $application['property_rent']), 2) ?>'
        },
        clauseState: {},
        clauseTemplates: {
            late_payment: {
                title: 'Late Payment Penalty',
                fields: { gracePeriod: '5', penaltyRate: '5' },
                render: function(data){
                    return `Late payments shall incur a ${data.penaltyRate}% penalty after a ${data.gracePeriod}-day grace period. The Tenant must pay all delinquent rent and fees promptly.`;
                },
                summary: function(data){ return `Late payment: ${data.penaltyRate}% after ${data.gracePeriod} days`; }
            },
            utilities: {
                title: 'Utilities',
                fields: { paidBy: 'Tenant', waterRate: 'metered', electricityRate: 'metered' },
                render: function(data){
                    return `Utilities for water and electricity will be billed separately. The ${data.paidBy.toLowerCase()} is responsible for payment based on actual consumption and ${data.electricityRate} charge rates.`;
                },
                summary: function(data){ return `Utilities: ${data.paidBy} pays water/electricity`; }
            },
            pets: {
                title: 'Pets',
                fields: { allowed: 'No', petDeposit: '0' },
                render: function(data){
                    const base = data.allowed === 'Yes' ? 'Pets are permitted subject to landlord approval' : 'Pets are not allowed on the premises';
                    const deposit = data.allowed === 'Yes' ? ` and require a deposit of ₱${data.petDeposit}` : '';
                    return `${base}${deposit}.`; 
                },
                summary: function(data){ return `Pets: ${data.allowed}${data.allowed === 'Yes' ? `; deposit ₱${data.petDeposit}` : ''}`; }
            },
            subletting: {
                title: 'Subletting',
                fields: { allowed: 'No' },
                render: function(data){
                    return `Subletting or assigning the lease is ${data.allowed === 'Yes' ? 'allowed with prior written consent of the Landlord' : 'strictly prohibited without prior written permission.'}`;
                },
                summary: function(data){ return `Subletting: ${data.allowed === 'Yes' ? 'allowed with consent' : 'prohibited'}`; }
            },
            maintenance: {
                title: 'Maintenance',
                fields: { tenantResponsibility: 'Interior upkeep', landlordResponsibility: 'Structural repairs' },
                render: function(data){
                    return `Tenant: ${data.tenantResponsibility}. Landlord: ${data.landlordResponsibility}. Both parties agree to report issues promptly.`;
                },
                summary: function(data){ return `Maintenance: Tenant interior; Landlord structure`; }
            },
            noise: {
                title: 'Noise',
                fields: { quietHours: '10:00 PM - 7:00 AM' },
                render: function(data){
                    return `Tenant must observe quiet hours from ${data.quietHours}. Noise disturbances may be treated as lease violations.`;
                },
                summary: function(data){ return `Quiet hours: ${data.quietHours}`; }
            },
            termination: {
                title: 'Early Termination',
                fields: { noticeDays: '30', terminationFee: '0' },
                render: function(data){
                    return `Early termination requires ${data.noticeDays} days written notice. An early termination fee of ₱${data.terminationFee} may apply if the lease ends before the agreed term.`;
                },
                summary: function(data){ return `Early termination: ${data.noticeDays} days notice, fee ₱${data.terminationFee}`; }
            },
            custom: {
                title: 'Custom Section',
                fields: { heading: 'Custom Clause', body: 'Enter custom lease language here.' },
                render: function(data){
                    return `${data.heading}: ${data.body}`; },
                summary: function(data){ return `Custom: ${data.heading}`; }
            }
        }
    };

    const clauseContainer = document.getElementById('clauseCardsContainer');
    const summaryAccountInfo = document.getElementById('summaryAccountInfo');
    const summaryKeyTerms = document.getElementById('summaryKeyTerms');
    const summaryTermination = document.getElementById('summaryTermination');
    const termsInput = document.getElementById('termsInput');
    const exportPdfBtn = document.getElementById('exportPdfBtn');

    function createClauseCard(key) {
        const clause = builder.clauseTemplates[key];
        const existing = builder.clauseState[key] || JSON.parse(JSON.stringify(clause.fields));
        const card = document.createElement('div');
        card.className = 'mb-3 p-3 rounded-3 bg-white border';
        card.dataset.clauseKey = key;
        card.innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <strong>${clause.title}</strong>
                    <div class="small text-secondary">Edit the clause fields below to customize the agreement.</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger clause-remove-btn">Remove</button>
            </div>
            <div class="clause-fields"></div>
        `;

        const fieldsContainer = card.querySelector('.clause-fields');
        Object.keys(clause.fields).forEach(fieldKey => {
            const value = existing[fieldKey] ?? clause.fields[fieldKey];
            const label = fieldKey.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase());
            const inputType = fieldKey.endsWith('Rate') || fieldKey.endsWith('Days') || fieldKey.endsWith('Deposit') || fieldKey === 'terminationFee' ? 'number' : 'text';
            fieldsContainer.insertAdjacentHTML('beforeend', `
                <div class="mb-3">
                    <label class="form-label fw-semibold text-slate-700">${label}</label>
                    <input type="${inputType}" class="form-control clause-field-input" data-field="${fieldKey}" value="${e(value)}" style="border-radius:8px;" ${fieldKey === 'body' ? 'rows="4"' : ''}>
                </div>
            `);
        });

        if (clause.title === 'Custom Section') {
            fieldsContainer.innerHTML = `
                <div class="mb-3">
                    <label class="form-label fw-semibold text-slate-700">Section Title</label>
                    <input type="text" class="form-control clause-field-input" data-field="heading" value="${e(existing.heading)}" style="border-radius:8px;">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold text-slate-700">Section Body</label>
                    <textarea class="form-control clause-field-input" data-field="body" rows="4" style="border-radius:8px;">${e(existing.body)}</textarea>
                </div>
            `;
        }

        clauseContainer.appendChild(card);
        bindClauseCardEvents(card, key);
    }

    function bindClauseCardEvents(card, key) {
        card.querySelector('.clause-remove-btn').addEventListener('click', function(){
            delete builder.clauseState[key];
            card.remove();
            updateToggleButtons();
            rebuildContract();
        });

        card.querySelectorAll('.clause-field-input').forEach(input => {
            input.addEventListener('input', function(){
                const field = this.dataset.field;
                builder.clauseState[key] = builder.clauseState[key] || {};
                builder.clauseState[key][field] = this.value;
                rebuildContract();
            });
        });
    }

    function initializeClauseButtons() {
        document.querySelectorAll('.clause-toggle-btn').forEach(button => {
            button.addEventListener('click', function(){
                const key = this.dataset.clause;
                if (builder.clauseState[key]) {
                    delete builder.clauseState[key];
                } else {
                    builder.clauseState[key] = JSON.parse(JSON.stringify(builder.clauseTemplates[key].fields));
                }
                renderClauseCards();
                updateToggleButtons();
                rebuildContract();
            });
        });
    }

    function updateToggleButtons() {
        document.querySelectorAll('.clause-toggle-btn').forEach(button => {
            const key = button.dataset.clause;
            button.classList.toggle('btn-primary', !!builder.clauseState[key]);
            button.classList.toggle('btn-outline-primary', !builder.clauseState[key]);
        });
    }

    function renderClauseCards() {
        clauseContainer.innerHTML = '';
        Object.keys(builder.clauseState).forEach(key => createClauseCard(key));
    }

    function rebuildContract() {
        const sections = [];
        sections.push(`LEASE AGREEMENT\n\nLandlord: ${builder.leaseDetails.landlord}\nTenant: ${builder.leaseDetails.tenant}\nProperty: ${builder.leaseDetails.property}\nAddress: ${builder.leaseDetails.address}\nLease Term: ${builder.leaseDetails.startDate} to ${builder.leaseDetails.endDate}\nMonthly Rent: ₱${builder.leaseDetails.rent}\nSecurity Deposit: ₱${builder.leaseDetails.deposit}\nAdvance Payment: ₱${builder.leaseDetails.advance}\n\n`);

        Object.keys(builder.clauseState).forEach(key => {
            const clause = builder.clauseTemplates[key];
            const data = builder.clauseState[key];
            sections.push(`${clause.title}:\n${clause.render(data)}\n\n`);
        });

        const result = sections.join('');
        termsInput.value = result;
        updateSummaryPanel();
    }

    function updateSummaryPanel() {
        summaryAccountInfo.innerHTML = `
            <div class="mb-2"><strong>Landlord:</strong> ${builder.leaseDetails.landlord}</div>
            <div class="mb-2"><strong>Tenant:</strong> ${builder.leaseDetails.tenant}</div>
            <div class="mb-2"><strong>Property:</strong> ${builder.leaseDetails.property}</div>
            <div class="mb-2"><strong>Dates:</strong> ${builder.leaseDetails.startDate} → ${builder.leaseDetails.endDate}</div>
            <div class="mb-2"><strong>Rent:</strong> ₱${builder.leaseDetails.rent}/month</div>
        `;

        const summaryItems = Object.keys(builder.clauseState).map(key => {
            return `<div class="small mb-1">• ${builder.clauseTemplates[key].summary(builder.clauseState[key])}</div>`;
        }).join('');

        summaryKeyTerms.innerHTML = `
            <h4 class="h6 fw-semibold mb-2">Key Terms</h4>
            ${summaryItems || '<div class="small text-secondary">No clauses added yet.</div>'}
        `;

        const termination = builder.clauseState.termination ? builder.clauseTemplates.termination.summary(builder.clauseState.termination) : 'Use the Early Termination clause to define notice and fees.';
        summaryTermination.innerHTML = `<h4 class="h6 fw-semibold mb-2">Termination Policy</h4><div class="small">${termination}</div>`;
    }

    function setTemplate(templateKey) {
        document.querySelectorAll('.lease-template-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.template === templateKey));
        builder.template = templateKey;

        const presets = {
            standard: ['late_payment','utilities','pets','maintenance','noise','termination'],
            commercial: ['late_payment','utilities','subletting','maintenance','noise','termination'],
            custom: []
        };

        builder.clauseState = {};
        presets[templateKey].forEach(key => {
            builder.clauseState[key] = JSON.parse(JSON.stringify(builder.clauseTemplates[key].fields));
        });
        renderClauseCards();
        updateToggleButtons();
        rebuildContract();
    }

    document.querySelectorAll('.lease-template-btn').forEach(button => button.addEventListener('click', function(){
        setTemplate(this.dataset.template);
    }));

    exportPdfBtn.addEventListener('click', function(){
        window.print();
    });

    initializeClauseButtons();
    setTemplate('standard');
})();
</script>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    #leaseBuilderForm, #leaseBuilderForm * {
        visibility: visible !important;
    }
    #leaseBuilderForm {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 0;
        margin: 0;
    }
    .no-print,
    .sidebar,
    .navbar,
    .review-card .clause-toggle-btn,
    .review-card .clause-remove-btn,
    .review-card button,
    .review-card input,
    .review-card select,
    .review-card textarea {
        display: none !important;
    }
}
</style>

<?php require __DIR__ . '/../partials/footer.php'; ?>
