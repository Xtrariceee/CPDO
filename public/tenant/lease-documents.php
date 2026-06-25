<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TENANT]);

// Fetch lease agreement
$leaseStmt = db()->prepare(
    'SELECT l.*, p.title AS property_title, p.address AS property_address,
            CONCAT_WS(" ", ul.first_name, ul.last_name) AS landlord_name, ul.email AS landlord_email, ul.phone_number AS landlord_phone,
            CONCAT_WS(" ", ut.first_name, ut.last_name) AS tenant_name, ut.email AS tenant_email, ut.phone_number AS tenant_phone, ut.date_of_birth AS tenant_dob
     FROM leases l
     JOIN properties p ON p.id = l.property_id
     JOIN users ul ON ul.id = p.landlord_id
     JOIN users ut ON ut.id = l.tenant_id
     WHERE l.tenant_id = ?
     ORDER BY l.created_at DESC LIMIT 1'
);
$leaseStmt->execute([(int)$user['id']]);
$lease = $leaseStmt->fetch();

// Handle signing POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sign_lease') {
    verify_csrf();

    if (!$lease || $lease['status'] !== 'pending_signature') {
        $_SESSION['flash_error'] = 'No lease draft available to sign.';
        redirect('tenant/lease-documents.php');
    }

    $consent = isset($_POST['consent_checkbox']) ? true : false;
    if (!$consent) {
        $_SESSION['flash_error'] = 'You must consent and authorize the signature to execute the lease.';
        redirect('tenant/lease-documents.php');
    }

    try {
        $pdo = db();
        $pdo->beginTransaction();

        // 1. Update lease state to awaiting_initial_payment and set signed_at timestamp
        $updLease = $pdo->prepare(
            'UPDATE leases SET status = "awaiting_initial_payment", signed_at = NOW() WHERE id = ?'
        );
        $updLease->execute([(int)$lease['id']]);

        // 2. System Automation: Generate a move_in_fees invoice
        $insInv = $pdo->prepare(
            'INSERT INTO invoices (lease_id, tenant_id, billing_date, due_date, status, created_at)
             VALUES (?, ?, ?, ?, "unpaid", NOW())'
        );
        $startDate = $lease['start_date'];
        $insInv->execute([(int)$lease['id'], (int)$user['id'], $startDate, $startDate]);
        $invoiceId = $pdo->lastInsertId();

        // Add Security Deposit item
        $insDep = $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, type, amount, description, created_at)
             VALUES (?, "move_in_fees", ?, "Security Deposit Charge", NOW())'
        );
        $insDep->execute([$invoiceId, (float)$lease['security_deposit']]);

        // Add Advance Rent item
        $insAdv = $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, type, amount, description, created_at)
             VALUES (?, "move_in_fees", ?, "Advance Rent Payment", NOW())'
        );
        $insAdv->execute([$invoiceId, (float)$lease['advance_payment']]);

        // 3. Notify the Landlord that lease is signed and invoices are pending payment
        $insNotif = $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, created_at)
             VALUES (?, "Lease Signed by Resident", ?, NOW())'
        );
        $msgText = user_full_name($user) . " has digitally signed the lease contract for unit \"{$lease['property_title']}\". Invoice #{$invoiceId} for initial move-in fees has been generated and is awaiting payment.";
        
        // Find landlord id
        $propStmt = $pdo->prepare('SELECT landlord_id FROM properties WHERE id = ?');
        $propStmt->execute([(int)$lease['property_id']]);
        $landlordId = (int)$propStmt->fetchColumn();

        $insNotif->execute([$landlordId, $msgText]);

        // 4. Update the application status to AGREED/SIGNED
        if ($lease['rental_application_id']) {
            $pdo->prepare('UPDATE rental_applications SET status = "SIGNED" WHERE id = ?')
                ->execute([(int)$lease['rental_application_id']]);
        }

        // Audit Log
        audit_log((int)$user['id'], 'LEASE_SIGNED_NEXT_PHASE', 'leases', (int)$lease['id'], ['invoice_id' => $invoiceId]);

        $pdo->commit();
        $_SESSION['flash_success'] = 'Lease successfully signed! Initial move-in fees invoice generated. Please settle payment in the Payment Hub.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = 'Failed to sign lease: ' . $e->getMessage();
    }

    redirect('tenant/lease-documents.php');
}

require __DIR__ . '/../partials/header.php';
?>

<style>
.lease-page {
    background: #f8fafc;
    min-height: calc(100vh - 64px);
    padding: 32px 0 64px;
}

.contract-container {
    background: #fdfdfb;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    box-shadow: 0 10px 30px -5px rgba(0,0,0,0.06);
    padding: 48px;
    font-family: 'Georgia', 'Times New Roman', serif;
    color: #1e293b;
    line-height: 1.7;
    position: relative;
    max-width: 800px;
    margin: 0 auto 32px;
}

.contract-header {
    text-align: center;
    margin-bottom: 40px;
    border-bottom: 2px solid #0f172a;
    padding-bottom: 20px;
}

.contract-title {
    font-size: 1.6rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #0f172a;
    margin-bottom: 4px;
}

.contract-subtitle {
    font-size: 0.9rem;
    font-style: italic;
    color: #64748b;
}

.contract-section {
    margin-bottom: 28px;
}

.contract-section h3 {
    font-size: 1.05rem;
    font-weight: bold;
    color: #0f172a;
    text-transform: uppercase;
    margin-bottom: 12px;
}

.field-highlight {
    font-family: 'Outfit', 'Inter', sans-serif;
    font-weight: 700;
    border-bottom: 1px solid #64748b;
    padding: 0 4px;
    color: #0f172a;
    display: inline-block;
}

.signature-block {
    display: flex;
    justify-content: space-between;
    margin-top: 48px;
    padding-top: 24px;
    border-top: 1px solid #e2e8f0;
}

.signature-line {
    width: 45%;
    text-align: center;
}

.sig-font {
    font-family: 'Dancing Script', 'Brush Script MT', cursive, serif;
    font-size: 1.5rem;
    color: #2563eb;
    border-bottom: 1px solid #000;
    padding-bottom: 4px;
    display: block;
    margin-bottom: 6px;
    height: 40px;
}

.stamp-executed {
    position: absolute;
    top: 40px;
    right: 40px;
    border: 4px double #10b981;
    color: #10b981;
    font-family: 'Outfit', sans-serif;
    font-weight: 900;
    text-transform: uppercase;
    font-size: 1.3rem;
    padding: 8px 16px;
    transform: rotate(12deg);
    border-radius: 8px;
    background: rgba(16, 185, 129, 0.05);
    user-select: none;
}

.stamp-pending {
    position: absolute;
    top: 40px;
    right: 40px;
    border: 4px double #f59e0b;
    color: #f59e0b;
    font-family: 'Outfit', sans-serif;
    font-weight: 900;
    text-transform: uppercase;
    font-size: 1.3rem;
    padding: 8px 16px;
    transform: rotate(12deg);
    border-radius: 8px;
    background: rgba(245, 158, 11, 0.05);
    user-select: none;
}
</style>

<div class="lease-page">
    <div class="container-fluid" style="max-width: 900px; margin: 0 auto; padding: 0 20px;">
        
        <!-- Page Header -->
        <div class="mb-4">
            <p class="dash-header-eyebrow mb-1">Tenant Portal</p>
            <h1 class="dash-header-title mb-1 fw-bold">Lease &amp; Contract Agreement</h1>
            <p class="dash-header-sub text-secondary">Review and electronically execute your residential lease contract.</p>
        </div>

        <?php if (!$lease): ?>
            <div class="glass-panel p-5 text-center bg-white border rounded">
                <div style="font-size: 3rem; color: #cbd5e1;" class="mb-3">📄</div>
                <h3 class="fw-bold">No active lease draft</h3>
                <p class="text-secondary small">Your landlord has not drafted a lease agreement for your application yet.</p>
                <a href="dashboard.php" class="btn btn-primary btn-sm mt-3" style="border-radius:8px;">Back to Dashboard</a>
            </div>
        <?php else:
            $isPendingSign = ($lease['status'] === 'pending_signature');
            $isAwaitingPay = ($lease['status'] === 'awaiting_initial_payment');
            $isActive = ($lease['status'] === 'active');
        ?>

            <!-- Status Alert banner -->
            <?php if ($isPendingSign): ?>
                <div class="alert alert-warning border border-warning-subtle d-flex align-items-center justify-content-between p-3 mb-4 rounded" style="background:#fffdf5;">
                    <div class="small">
                        <strong class="text-warning-emphasis d-block mb-1">✍️ Action Required: Review &amp; Sign Lease</strong>
                        Please read the terms below carefully. If you agree, authorize the electronic signature at the bottom to sign the contract instantly.
                    </div>
                    <span class="badge bg-warning text-dark px-3 py-2">Awaiting Signature</span>
                </div>
            <?php elseif ($isAwaitingPay): ?>
                <div class="alert alert-info border border-info-subtle d-flex align-items-center justify-content-between p-3 mb-4 rounded" style="background:#f0f9ff;">
                    <div class="small">
                        <strong class="text-blue-800 d-block mb-1">💳 Next Step: Settle Initial Payments</strong>
                        You signed this contract on <?= date('M d, Y at g:i A', strtotime($lease['signed_at'])) ?>. Settle deposit and advance rent to activate.
                    </div>
                    <a href="payment-hub.php" class="btn btn-primary btn-sm fw-bold px-3" style="border-radius:8px; background:#2563eb; border-color:#2563eb;">
                        Go to Payment Hub
                    </a>
                </div>
            <?php elseif ($isActive): ?>
                <div class="alert alert-success border border-success-subtle d-flex align-items-center justify-content-between p-3 mb-4 rounded" style="background:#f6fbf8;">
                    <div class="small text-success-emphasis">
                        <strong>✓ Lease Fully Executed &amp; Active</strong>
                        Your tenancy is officially active! You can view or print this executed document anytime.
                    </div>
                    <span class="badge bg-success text-white px-3 py-2">Active Tenancy</span>
                </div>
            <?php endif; ?>

            <!-- Interactive Digital Contract Paper -->
            <div class="contract-container">
                <!-- Stamping Watermarks -->
                <?php if ($isActive): ?>
                    <div class="stamp-executed">EXECUTED</div>
                <?php elseif ($isAwaitingPay || $isPendingSign): ?>
                    <div class="stamp-pending">DRAFT</div>
                <?php endif; ?>

                <div class="contract-header">
                    <h2 class="contract-title">Residential Lease Agreement</h2>
                    <p class="contract-subtitle">Davao City Land &amp; Housing Tenancy Regulatory Standard Form</p>
                </div>

                <!-- Intro Block -->
                <div class="contract-section">
                    <p>
                        This Residential Lease Agreement (hereinafter referred to as the "Agreement") is entered into this 
                        <span class="field-highlight"><?= date('jS') ?></span> day of <span class="field-highlight"><?= date('F, Y') ?></span>, by and between the parties enumerated below:
                    </p>
                </div>

                <!-- Parties Info -->
                <div class="contract-section">
                    <h3>I. The Parties</h3>
                    <p>
                        <strong>LANDLORD:</strong> <span class="field-highlight"><?= e($lease['landlord_name']) ?></span> 
                        (Email: <span class="field-highlight"><?= e($lease['landlord_email']) ?></span>, 
                        Phone: <span class="field-highlight"><?= e($lease['landlord_phone'] ?: 'N/A') ?></span>) 
                        hereinafter referred to as the "Landlord", and
                    </p>
                    <p>
                        <strong>TENANT:</strong> <span class="field-highlight"><?= e($lease['tenant_name']) ?></span> 
                        (Email: <span class="field-highlight"><?= e($lease['tenant_email']) ?></span>, 
                        Phone: <span class="field-highlight"><?= e($lease['tenant_phone'] ?: 'N/A') ?></span>, 
                        DOB: <span class="field-highlight"><?= $lease['tenant_dob'] ? date('M d, Y', strtotime($lease['tenant_dob'])) : 'N/A' ?></span>) 
                        hereinafter referred to as the "Tenant".
                    </p>
                </div>

                <!-- Premises -->
                <div class="contract-section">
                    <h3>II. The Premises</h3>
                    <p>
                        The Landlord hereby leases to the Tenant, and the Tenant hereby rents from the Landlord, the real property located at 
                        <span class="field-highlight"><?= e($lease['property_address']) ?></span>, 
                        popularly recognized as <span class="field-highlight"><?= e($lease['property_title']) ?></span>.
                    </p>
                </div>

                <!-- Term -->
                <div class="contract-section">
                    <h3>III. Lease Term</h3>
                    <p>
                        The term of this Agreement shall begin on <span class="field-highlight"><?= date('F d, Y', strtotime($lease['start_date'])) ?></span> 
                        and terminate on <span class="field-highlight"><?= date('F d, Y', strtotime($lease['end_date'])) ?></span>. 
                        Upon termination, Tenant must vacate the Premises unless a renewal is explicitly negotiated.
                    </p>
                </div>

                <!-- Rent & Financials -->
                <div class="contract-section">
                    <h3>IV. Rent &amp; Initial Financial Consideration</h3>
                    <p>
                        <strong>Monthly Rent:</strong> The Tenant agrees to pay the Landlord a gross monthly rental of 
                        <span class="field-highlight">₱<?= number_format($lease['monthly_rent'], 2) ?></span> 
                        settled in advance.
                    </p>
                    <p>
                        <strong>Move-in Fees:</strong> Prior to obtaining occupancy rights, the Tenant shall deliver:
                    </p>
                    <ul>
                        <li>Security Deposit of <span class="field-highlight">₱<?= number_format($lease['security_deposit'], 2) ?></span> (retained to cover damages).</li>
                        <li>Advance Rent payment of <span class="field-highlight">₱<?= number_format($lease['advance_payment'], 2) ?></span>.</li>
                    </ul>
                </div>

                <!-- Special Terms and Conditions -->
                <div class="contract-section">
                    <h3>V. Covenants &amp; Special Conditions</h3>
                    <div style="background:#f8fafc; border-left: 3px solid #cbd5e1; padding: 16px; font-size: 0.9rem; white-space: pre-wrap; font-family: monospace;">
                        <?= e($lease['terms'] ?: 'Standard household rules, maintenance guidelines, and local tenancy laws apply.') ?>
                    </div>
                </div>

                <!-- digital signature representation -->
                <div class="signature-block">
                    <div class="signature-line">
                        <span class="sig-font"><?= e($lease['landlord_name']) ?></span>
                        <small class="text-secondary uppercase small">Landlord Authorized Signature</small>
                    </div>
                    <div class="signature-line">
                        <?php if ($isPendingSign): ?>
                            <div style="height:40px; border-bottom: 1px dashed #64748b;" class="text-secondary small italic d-flex align-items-center justify-content-center">
                                Electronic signature pending
                            </div>
                        <?php else: ?>
                            <span class="sig-font"><?= e($lease['tenant_name']) ?></span>
                        <?php endif; ?>
                        <small class="text-secondary uppercase small">Tenant Digital Signature</small>
                    </div>
                </div>
            </div>

            <!-- Sign Form (Single Button Click) -->
            <?php if ($isPendingSign): ?>
                <div class="card bg-white border border-slate-200 p-4 max-width-800 mx-auto" style="border-radius:12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); max-width:800px;">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="sign_lease">
                        
                        <h4 class="h5 fw-bold text-slate-800 mb-2">Sign and Authorize Contract</h4>
                        <p class="text-secondary small mb-4">
                            By checking the consent box and clicking "Review &amp; Electronically Sign", you certify that your profile information matches your legal identity, and you bind yourself to the terms of this lease agreement.
                        </p>

                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" name="consent_checkbox" id="consentCheckbox" required>
                            <label class="form-check-label text-slate-700 small" for="consentCheckbox">
                                I declare that I am <strong><?= e(user_full_name($user)) ?></strong> and I authorize this digital transaction to constitute a legally binding electronic signature.
                            </label>
                        </div>

                        <button type="submit" class="btn btn-success btn-lg w-100 py-3 fw-bold shadow-sm" style="background-color: #10b981; border-color: #10b981; border-radius:10px;">
                            ✍️ Review &amp; Electronically Sign Agreement
                        </button>
                    </form>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
