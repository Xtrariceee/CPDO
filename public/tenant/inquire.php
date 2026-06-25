<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TENANT]);
verify_csrf();

$propertyId = (int)($_GET['property_id'] ?? $_POST['property_id'] ?? 0);

if (!$propertyId) {
    $_SESSION['flash_error'] = 'Invalid property.';
    redirect('tenant/dashboard.php');
}

// Fetch property details
$stmt = db()->prepare('SELECT p.*, CONCAT_WS(" ", u.first_name, u.last_name) AS landlord_name 
                       FROM properties p 
                       JOIN users u ON u.id = p.landlord_id 
                       WHERE p.id = ? AND p.status = "ACTIVE"');
$stmt->execute([$propertyId]);
$property = $stmt->fetch();

if (!$property) {
    $_SESSION['flash_error'] = 'Property listing not found or is inactive.';
    redirect('tenant/dashboard.php');
}

$details = json_decode($property['extended_details'] ?? '{}', true) ?: [];

// Check if tenant already has an active application for this property
$existingStmt = db()->prepare('SELECT id FROM rental_applications WHERE tenant_id = ? AND property_id = ? AND status NOT IN ("DECLINED")');
$existingStmt->execute([(int)$user['id'], $propertyId]);
if ($existingStmt->fetch()) {
    $_SESSION['flash_error'] = 'You already have an active application/inquiry for this property.';
    redirect('tenant/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $income = (float)($_POST['monthly_income'] ?? 0);
    $occupants = (int)($_POST['occupants_count'] ?? 1);
    $employment = trim($_POST['employment_status'] ?? '');
    $stability = (int)($_POST['employment_stability_months'] ?? 0);
    $proofType = trim($_POST['income_proof_type'] ?? '');
    
    $hasGuarantor = (isset($_POST['has_guarantor']) && $_POST['has_guarantor'] === '1') ? 1 : 0;
    $guarantorName = trim($_POST['guarantor_name'] ?? '');
    $guarantorEmp = trim($_POST['guarantor_employment_status'] ?? '');
    $guarantorIncome = (float)($_POST['guarantor_income'] ?? 0);
    $guarantorStability = (int)($_POST['guarantor_stability_months'] ?? 0);

    $govIdType = trim($_POST['gov_id_type'] ?? '');
    $govIdNumber = trim($_POST['gov_id_number'] ?? '');

    $landlordRefName1 = trim($_POST['landlord_ref_name_1'] ?? '');
    $landlordRefPhone1 = trim($_POST['landlord_ref_phone_1'] ?? '');
    $landlordRefName2 = trim($_POST['landlord_ref_name_2'] ?? '');
    $landlordRefPhone2 = trim($_POST['landlord_ref_phone_2'] ?? '');

    $refPaidOnTime = (isset($_POST['ref_paid_on_time']) && $_POST['ref_paid_on_time'] === '1') ? 1 : 0;
    $refCleanSanitary = (isset($_POST['ref_clean_sanitary']) && $_POST['ref_clean_sanitary'] === '1') ? 1 : 0;
    $refAdheredRules = (isset($_POST['ref_adhered_rules']) && $_POST['ref_adhered_rules'] === '1') ? 1 : 0;
    $refProperNotice = (isset($_POST['ref_proper_notice']) && $_POST['ref_proper_notice'] === '1') ? 1 : 0;

    $creditBankruptcies = (isset($_POST['credit_bankruptcies']) && $_POST['credit_bankruptcies'] === '1') ? 1 : 0;
    $creditCollections = (isset($_POST['credit_collections']) && $_POST['credit_collections'] === '1') ? 1 : 0;
    $recordEvictions = (isset($_POST['record_evictions']) && $_POST['record_evictions'] === '1') ? 1 : 0;
    $recordIllegalActivity = (isset($_POST['record_illegal_activity']) && $_POST['record_illegal_activity'] === '1') ? 1 : 0;

    $requestedMoveInDate = trim($_POST['requested_move_in_date'] ?? '');
    $pets = ($_POST['pets'] ?? 'No') === 'Yes' ? 1 : 0;
    $smoking = ($_POST['smoking'] ?? 'No') === 'Yes' ? 1 : 0;
    $message = trim($_POST['message'] ?? '');

    $monthlyRent = (float)$property['monthly_rent'];

    // ── Backend Vetting Checks ──
    $errors = [];

    // 1. Income-to-Rent Ratio
    if ($income < (3 * $monthlyRent) && !$hasGuarantor) {
        $errors[] = 'Gross monthly income must be at least 3x the monthly rent (₱' . number_format($monthlyRent * 3, 2) . '). If you are a student or lack sufficient income, please link a co-signer/guarantor.';
    }

    // 2. Guarantor Vetting (If applicable)
    if ($hasGuarantor) {
        if (empty($guarantorName)) {
            $errors[] = 'Guarantor name is required.';
        }
        if ($guarantorIncome < (4 * $monthlyRent)) {
            $errors[] = 'Guarantor gross monthly income must be at least 4x the monthly rent (₱' . number_format($monthlyRent * 4, 2) . ').';
        }
        if (empty($_FILES['guarantor_income_proof']['name'])) {
            $errors[] = 'Guarantor income proof file is required.';
        }
    }

    // 3. Declarations (Must agree to past vetting benchmarks)
    if ($creditBankruptcies) {
        $errors[] = 'Applicants with active bankruptcies within the past 3-5 years are ineligible.';
    }
    if ($creditCollections) {
        $errors[] = 'Applicants with severe unresolved collections or utility write-offs are ineligible.';
    }
    if ($recordEvictions) {
        $errors[] = 'Applicants with active eviction records within the past 7 years are ineligible.';
    }
    if ($recordIllegalActivity) {
        $errors[] = 'Applicants with a history of illegal activity or severe building code violations are ineligible.';
    }

    // 4. File uploads validation
    if (empty($_FILES['income_proof_1']['name']) || empty($_FILES['income_proof_2']['name'])) {
        $errors[] = 'Please upload at least two digital copies of income proof (e.g. recent pay slips, bank statements, COE).';
    }
    if (empty($_FILES['gov_id_file']['name'])) {
        $errors[] = 'A digital copy of your government-issued ID is required.';
    }
    if (empty($requestedMoveInDate)) {
        $errors[] = 'Requested move-in date is required.';
    }

    if (!empty($errors)) {
        $_SESSION['flash_error'] = implode('<br>', $errors);
    } else {
        try {
            $pdo = db();
            // Process file uploads
            $incomePath1 = secure_upload($_FILES['income_proof_1'], 'applications/' . $user['id']);
            $incomePath2 = secure_upload($_FILES['income_proof_2'], 'applications/' . $user['id']);
            $govIdPath = secure_upload($_FILES['gov_id_file'], 'applications/' . $user['id']);
            
            $guarantorPath = null;
            if ($hasGuarantor && !empty($_FILES['guarantor_income_proof']['name'])) {
                $guarantorPath = secure_upload($_FILES['guarantor_income_proof'], 'applications/' . $user['id']);
            }

            // Insert into DB
            $ins = $pdo->prepare(
                'INSERT INTO rental_applications (
                    tenant_id, property_id, status, monthly_income, occupants_count, employment_status, 
                    pets, smoking, message, income_proof_type, income_proof_path_1, income_proof_path_2,
                    employment_stability_months, has_guarantor, guarantor_name, guarantor_income, 
                    guarantor_income_proof_path, guarantor_employment_status, guarantor_stability_months,
                    credit_bankruptcies, credit_collections, record_evictions, record_illegal_activity,
                    landlord_ref_name_1, landlord_ref_phone_1, landlord_ref_name_2, landlord_ref_phone_2,
                    ref_paid_on_time, ref_clean_sanitary, ref_adhered_rules, ref_proper_notice,
                    requested_move_in_date, gov_id_type, gov_id_number, gov_id_path, created_at
                 ) VALUES (?, ?, "PENDING", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $ins->execute([
                (int)$user['id'],
                $propertyId,
                $income,
                $occupants,
                $employment,
                $pets,
                $smoking,
                $message ?: null,
                $proofType,
                $incomePath1,
                $incomePath2,
                $stability,
                $hasGuarantor,
                $hasGuarantor ? $guarantorName : null,
                $hasGuarantor ? $guarantorIncome : null,
                $guarantorPath,
                $hasGuarantor ? $guarantorEmp : null,
                $hasGuarantor ? $guarantorStability : null,
                $creditBankruptcies,
                $creditCollections,
                $recordEvictions,
                $recordIllegalActivity,
                $landlordRefName1 ?: null,
                $landlordRefPhone1 ?: null,
                $landlordRefName2 ?: null,
                $landlordRefPhone2 ?: null,
                $refPaidOnTime,
                $refCleanSanitary,
                $refAdheredRules,
                $refProperNotice,
                $requestedMoveInDate,
                $govIdType,
                $govIdNumber,
                $govIdPath
            ]);
            $applicationId = (int)$pdo->lastInsertId();

            // Create notification for the landlord
            $landlordNotif = $pdo->prepare('INSERT INTO notifications (user_id, rental_application_id, title, message, created_at) VALUES (?, ?, ?, ?, NOW())');
            $landlordNotif->execute([
                (int)$property['landlord_id'],
                $applicationId,
                'New Tenant Vetting Application Received',
                user_full_name($user) . ' has submitted a privacy-focused vetting application for your property: ' . $property['title']
            ]);

            audit_log((int)$user['id'], 'RENTAL_APPLICATION_SUBMITTED', 'rental_applications', $applicationId, ['property_id' => $propertyId]);

            $_SESSION['flash_success'] = 'Your application has been submitted successfully! The landlord will review your vetting details shortly.';
            redirect('tenant/application-status.php?id=' . $applicationId);
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to submit application: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/../partials/header.php';
?>

<style>
.inquire-page {
    min-height: calc(100vh - 64px);
    background: #f9fafb;
    padding: 32px 0 64px;
}

.step-indicator {
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    max-width: 600px;
    margin: 0 auto 32px;
}

.step-indicator::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    height: 3px;
    background: #e5e7eb;
    z-index: 1;
    transform: translateY(-50%);
}

.step-item {
    position: relative;
    z-index: 2;
    background: #fff;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 3px solid #e5e7eb;
    font-weight: 700;
    color: #9ca3af;
    transition: all 0.3s ease;
}

.step-item.active {
    border-color: #c59000;
    color: #c59000;
    box-shadow: 0 0 0 4px rgba(197, 144, 0, 0.15);
}

.step-item.completed {
    background-color: #c59000;
    border-color: #c59000;
    color: #fff;
}

.step-label {
    position: absolute;
    top: 48px;
    font-size: 0.72rem;
    font-weight: 800;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #9ca3af;
}

.step-item.active .step-label {
    color: #241b0b;
}

.step-item.completed .step-label {
    color: #c59000;
}

.inquire-card {
    background: #fff;
    border: 1px solid #f0dfad;
    border-radius: 20px;
    box-shadow: 0 8px 30px rgba(36, 27, 11, 0.05);
    overflow: hidden;
}

.privacy-banner {
    background-color: #fffbeb;
    border: 1px solid #fef3c7;
    border-radius: 12px;
    padding: 16px;
    color: #78350f;
}

.form-section {
    display: none;
}

.form-section.active {
    display: block;
    animation: fadeIn 0.4s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

.form-label {
    font-weight: 700;
    color: #241b0b;
    font-size: 0.88rem;
}

.locked-badge {
    background-color: #f3f4f6;
    color: #4b5563;
    font-size: 0.75rem;
    padding: 4px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.form-control[readonly] {
    background-color: #f9fafb;
    color: #6b7280;
    border-color: #e5e7eb;
}

.btn-primary {
    background-color: #f6cf4a;
    border-color: #e6b82f;
    color: #241b0b;
    font-weight: 700;
    transition: all 0.2s ease;
}

.btn-primary:hover {
    background-color: #e6b82f;
    border-color: #c59000;
}

.btn-outline-secondary {
    border-color: #d1d5db;
    color: #4b5563;
    font-weight: 700;
}

.btn-outline-secondary:hover {
    background-color: #f9fafb;
    border-color: #9ca3af;
}

.form-check-input:checked {
    background-color: #c59000;
    border-color: #c59000;
}

.card-summary {
    background: linear-gradient(135deg, #fffdf5 0%, #fffbf0 100%);
    border: 1px solid #f0dfad;
    border-radius: 16px;
}
</style>

<div class="inquire-page">
    <div class="container-fluid" style="max-width: 900px; margin: 0 auto; padding: 0 20px;">
        
        <!-- Header -->
        <div class="d-flex align-items-center gap-3 mb-4">
            <a class="btn btn-back btn-sm" href="../landlord/property-view.php?id=<?= $propertyId ?>"><span aria-hidden="true">&larr;</span> Back to Listing</a>
            <h1 class="h3 mb-0">Secure Tenant Screening Application</h1>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step-item completed" id="indicator-1">
                1
                <span class="step-label">Financials</span>
            </div>
            <div class="step-item active" id="indicator-2">
                2
                <span class="step-label">Identity & Vetting</span>
            </div>
            <div class="step-item" id="indicator-3">
                3
                <span class="step-label">References & Unit</span>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left Side: Form Details -->
            <div class="col-lg-8">
                <form class="inquire-card p-4" method="post" enctype="multipart/form-data" id="vettingForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="property_id" value="<?= $propertyId ?>">

                    <!-- STEP 1: FINANCIALS & EMPLOYMENT -->
                    <div class="form-section active" id="section-1">
                        <h2 class="h5 mb-3 text-dark fw-bold border-bottom pb-2">Step 1: Financial & Employment Credentials</h2>
                        
                        <!-- Locked Profile Data Warning -->
                        <div class="mb-4">
                            <span class="locked-badge mb-2">🔒 Secure Profile Linkage Active</span>
                            <div class="row g-2">
                                <div class="col-sm-4">
                                    <label class="form-label text-secondary small">Legal Name</label>
                                    <input type="text" class="form-control form-control-sm" value="<?= e(user_full_name($user)) ?>" readonly>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label text-secondary small">Registered Email</label>
                                    <input type="email" class="form-control form-control-sm" value="<?= e($user['email']) ?>" readonly>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label text-secondary small">Phone Number</label>
                                    <input type="text" class="form-control form-control-sm" value="<?= e($user['phone_number']) ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <!-- Core Income Fields -->
                        <div class="mb-3">
                            <label class="form-label">Your Gross Monthly Income (₱) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" step="0.01" class="form-control" name="monthly_income" id="monthly_income" required placeholder="0.00" min="0">
                            </div>
                            <div class="form-text small text-secondary">Must be at least 3x the monthly rent (₱<?= number_format($property['monthly_rent'] * 3, 2) ?>).</div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label">Employment Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="employment_status" id="employment_status" required>
                                    <option value="">-- Select Status --</option>
                                    <option value="Employed">Employed (Full-Time / Part-Time)</option>
                                    <option value="Self-Employed">Self-Employed / Business Owner</option>
                                    <option value="Student">Student</option>
                                    <option value="Unemployed">Unemployed</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Months in Current Job <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="employment_stability_months" required min="0" placeholder="e.g. 12">
                            </div>
                        </div>

                        <!-- Privacy Redact Information -->
                        <div class="privacy-banner mb-3 small">
                            <h4 class="h6 fw-bold mb-2">⚠️ Important Document Redaction Instructions</h4>
                            <p class="mb-2"><strong>Never upload raw, unedited financial records.</strong> Please cross out or black out sensitive, non-relevant details on files before uploading:</p>
                            <ul class="mb-0">
                                <li><strong>Leave visible:</strong> Full name, document date, gross/net pay, and employer's name.</li>
                                <li><strong>Black out:</strong> Bank account/credit card numbers, purchase details, and tax IDs (TIN).</li>
                            </ul>
                        </div>

                        <!-- Proof of Income Uploads -->
                        <div class="mb-3">
                            <label class="form-label">Primary Income Proof Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="income_proof_type" required>
                                <option value="">-- Select Document Type --</option>
                                <option value="Pay Slips">Pay Slips (3 Consecutive Months)</option>
                                <option value="Bank Statements">Bank Statements (3 Months Redacted)</option>
                                <option value="Certificate of Employment">Certificate of Employment (COE)</option>
                                <option value="Tax Return">Previous Year's Tax Return (ITR)</option>
                            </select>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Document copy 1 <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" name="income_proof_1" accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Document copy 2 <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" name="income_proof_2" accept=".pdf,.jpg,.jpeg,.png" required>
                            </div>
                        </div>

                        <!-- Co-Signer Toggle and Fields -->
                        <div class="card p-3 border-secondary-subtle bg-light mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="has_guarantor" name="has_guarantor" value="1">
                                <label class="form-check-label fw-bold small text-dark" for="has_guarantor">Link a Co-signer / Guarantor (Highly recommended for students or low income)</label>
                            </div>

                            <div id="guarantor-fields" class="mt-3" style="display: none;">
                                <h3 class="h6 fw-bold text-dark border-bottom pb-1">Guarantor Information & Vetting</h3>
                                <div class="mb-2">
                                    <label class="form-label">Guarantor Full Legal Name</label>
                                    <input type="text" class="form-control" name="guarantor_name" placeholder="John Doe">
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="form-label">Employment Status</label>
                                        <input type="text" class="form-control" name="guarantor_employment_status" placeholder="e.g. Full-time Executive">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label">Stability (Months)</label>
                                        <input type="number" class="form-control" name="guarantor_stability_months" placeholder="Months in job">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Guarantor Monthly Income (₱)</label>
                                    <input type="number" step="0.01" class="form-control" name="guarantor_income" placeholder="0.00">
                                    <div class="form-text small text-secondary">Must be at least 4x the rent (₱<?= number_format($property['monthly_rent'] * 4, 2) ?>).</div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Guarantor Redacted Income Proof File</label>
                                    <input type="file" class="form-control" name="guarantor_income_proof" accept=".pdf,.jpg,.jpeg,.png">
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="button" class="btn btn-primary px-4" onclick="goToStep(2)">Continue to Step 2</button>
                        </div>
                    </div>

                    <!-- STEP 2: IDENTITY & BACKGROUND DECLARATIONS -->
                    <div class="form-section" id="section-2">
                        <h2 class="h5 mb-3 text-dark fw-bold border-bottom pb-2">Step 2: Identity & Vetting Disclosures</h2>

                        <!-- ID Verification -->
                        <div class="row g-2 mb-4">
                            <div class="col-sm-6">
                                <label class="form-label">Government ID Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="gov_id_type" required>
                                    <option value="">-- Select ID --</option>
                                    <option value="Philippine ID (National ID)">Philippine ID (National ID)</option>
                                    <option value="Passport">Passport</option>
                                    <option value="Driver's License">Driver's License</option>
                                    <option value="UMID">UMID</option>
                                    <option value="PRC ID">PRC ID</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">ID Document Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="gov_id_number" required placeholder="e.g. XXXX-XXXX-XXXX">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Upload Government ID File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" name="gov_id_file" accept=".pdf,.jpg,.jpeg,.png" required>
                            <div class="form-text small text-secondary">Ensure ID is valid, unexpired, and clearly readable.</div>
                        </div>

                        <!-- Vetting Disclosures (Declarations are stored as negative indicators: user checks to declare NO past offenses. Checked means FALSE for bankruptcies, i.e. NO bankruptcies.) -->
                        <h3 class="h6 fw-bold text-dark border-bottom pb-1">Vetting Disclosures & Credit Checks</h3>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="credit_bankruptcies" name="credit_bankruptcies" value="1">
                            <label class="form-check-label small text-dark" for="credit_bankruptcies">
                                <strong>Bankruptcy Check:</strong> I have active/unresolved bankruptcies within the past 3 to 5 years.
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="credit_collections" name="credit_collections" value="1">
                            <label class="form-check-label small text-dark" for="credit_collections">
                                <strong>Collections & Financial Health:</strong> I have active collection accounts or major utility write-offs.
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="record_evictions" name="record_evictions" value="1">
                            <label class="form-check-label small text-dark" for="record_evictions">
                                <strong>Eviction History:</strong> I have records of evictions due to non-payment or property damage within past 7 years.
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="record_illegal_activity" name="record_illegal_activity" value="1">
                            <label class="form-check-label small text-dark" for="record_illegal_activity">
                                <strong>Public Record:</strong> I have a history of illegal activity or severe building/safety code violations.
                            </label>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-outline-secondary px-4" onclick="goToStep(1)">Back</button>
                            <button type="button" class="btn btn-primary px-4" onclick="goToStep(3)">Continue to Step 3</button>
                        </div>
                    </div>

                    <!-- STEP 3: REFERENCES & PREFERENCES -->
                    <div class="form-section" id="section-3">
                        <h2 class="h5 mb-3 text-dark fw-bold border-bottom pb-2">Step 3: Rental References & Details</h2>

                        <!-- References -->
                        <h3 class="h6 fw-bold text-dark mb-2">Previous Landlord References (Spanning last 2+ years)</h3>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label">Reference 1: Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="landlord_ref_name_1" required placeholder="Manager / Landlord Name">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Reference 1: Phone <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="landlord_ref_phone_1" required placeholder="Active Contact Number">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label">Reference 2: Name <span class="text-secondary small">(optional)</span></label>
                                <input type="text" class="form-control" name="landlord_ref_name_2" placeholder="Second Reference Name">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Reference 2: Phone <span class="text-secondary small">(optional)</span></label>
                                <input type="text" class="form-control" name="landlord_ref_phone_2" placeholder="Second Reference Phone">
                            </div>
                        </div>

                        <!-- References Declarations -->
                        <div class="card p-3 border-secondary-subtle bg-light mb-3">
                            <h4 class="h6 fw-bold text-dark mb-2">Direct Tenant Reference Declaration</h4>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="ref_paid_on_time" name="ref_paid_on_time" value="1" checked required>
                                <label class="form-check-label small text-dark" for="ref_paid_on_time">
                                    I consistently paid rent and bills on time with my past landlords.
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="ref_clean_sanitary" name="ref_clean_sanitary" value="1" checked required>
                                <label class="form-check-label small text-dark" for="ref_clean_sanitary">
                                    I maintained past rental properties in a clean, sanitary, and undamaged condition.
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="ref_adhered_rules" name="ref_adhered_rules" value="1" checked required>
                                <label class="form-check-label small text-dark" for="ref_adhered_rules">
                                    I adhered to community guidelines (no noise complaints or offenses logged against me).
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="ref_proper_notice" name="ref_proper_notice" value="1" checked required>
                                <label class="form-check-label small text-dark" for="ref_proper_notice">
                                    I gave proper legal notice to past landlords before vacating premises.
                                </label>
                            </div>
                        </div>

                        <!-- Unit Preferences -->
                        <h3 class="h6 fw-bold text-dark border-bottom pb-1 mt-4">Move-in Details</h3>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label">Requested Move-in Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="requested_move_in_date" required min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Total Occupants <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="occupants_count" required min="1" value="1">
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label">Pets? <span class="text-danger">*</span></label>
                                <select class="form-select" name="pets" required>
                                    <option value="No">No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Smoke? <span class="text-danger">*</span></label>
                                <select class="form-select" name="smoking" required>
                                    <option value="No">No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Message to Landlord <span class="text-secondary small">(optional)</span></label>
                            <textarea class="form-control" name="message" rows="3" placeholder="Introduce yourself or mention details..."></textarea>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-outline-secondary px-4" onclick="goToStep(2)">Back</button>
                            <button type="submit" class="btn btn-primary px-5 py-2 fs-6">Submit Application</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Right Side: Property Details -->
            <div class="col-lg-4">
                <div class="card-summary p-4 border border-warning shadow-sm">
                    <h2 class="h5 mb-3 text-dark fw-bold">Listing Summary</h2>
                    <h3 class="h6 text-primary fw-bold mb-1"><?= e($property['title']) ?></h3>
                    <p class="text-secondary small mb-3"><?= e($property['address']) ?></p>
                    <hr>
                    <div class="mb-3">
                        <span class="text-secondary small d-block">Monthly Rent</span>
                        <strong class="text-success fs-5">₱<?= number_format($monthlyRent, 2) ?></strong>
                    </div>
                    <?php if (!empty($details['security_deposit'])): ?>
                        <div class="mb-3">
                            <span class="text-secondary small d-block">Security Deposit</span>
                            <strong>₱<?= number_format((float)$details['security_deposit'], 2) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($details['advance_rent'])): ?>
                        <div class="mb-3">
                            <span class="text-secondary small d-block">Advance Rent</span>
                            <strong>₱<?= number_format((float)$details['advance_rent'], 2) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="mt-2 pt-2 border-top">
                        <span class="text-secondary small d-block">Landlord</span>
                        <span class="fw-semibold text-dark"><?= e($property['landlord_name']) ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
function goToStep(step) {
    // Basic field validation before proceeding to next step
    if (step === 2) {
        var income = document.getElementById('monthly_income').value;
        var status = document.getElementById('employment_status').value;
        if (!income || income <= 0 || !status) {
            alert('Please specify your Monthly Income and Employment Status.');
            return;
        }
    }
    
    // Deactivate all steps & indicators
    document.querySelectorAll('.form-section').forEach(function(section) {
        section.classList.remove('active');
    });
    document.querySelectorAll('.step-item').forEach(function(item) {
        item.classList.remove('active', 'completed');
    });

    // Activate selected section
    document.getElementById('section-' + step).classList.add('active');

    // Update Indicators
    for (var i = 1; i <= 3; i++) {
        var el = document.getElementById('indicator-' + i);
        if (i < step) {
            el.classList.add('completed');
        } else if (i === step) {
            el.classList.add('active');
        }
    }
}

// Toggle guarantor fields on switch
document.addEventListener('DOMContentLoaded', function() {
    var switchEl = document.getElementById('has_guarantor');
    if (switchEl) {
        switchEl.addEventListener('change', function() {
            var fields = document.getElementById('guarantor-fields');
            if (switchEl.checked) {
                fields.style.display = 'block';
                document.getElementsByName('guarantor_name')[0].required = true;
                document.getElementsByName('guarantor_employment_status')[0].required = true;
                document.getElementsByName('guarantor_income')[0].required = true;
                document.getElementsByName('guarantor_stability_months')[0].required = true;
                document.getElementsByName('guarantor_income_proof')[0].required = true;
            } else {
                fields.style.display = 'none';
                document.getElementsByName('guarantor_name')[0].required = false;
                document.getElementsByName('guarantor_employment_status')[0].required = false;
                document.getElementsByName('guarantor_income')[0].required = false;
                document.getElementsByName('guarantor_stability_months')[0].required = false;
                document.getElementsByName('guarantor_income_proof')[0].required = false;
            }
        });
    }
    goToStep(1); // Initialize stepper at Step 1
});
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
