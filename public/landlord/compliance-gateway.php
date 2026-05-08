<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
$status = landlord_compliance_status((int)$user['id']);

if ($status['state'] === 'ELIGIBLE') {
    redirect('landlord/property-form.php');
}

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="dashboard.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
    <div>
        <p class="eyebrow mb-0">Compliance Gateway</p>
        <h1 class="h3 mb-0">Add a Property Listing</h1>
    </div>
</div>

<p class="text-secondary mb-4">Choose how this property will satisfy CPDO listing compliance before it can be listed.</p>

<?php if ($status['state'] === 'UNDER_REVIEW'): ?>
    <div class="alert alert-warning mb-4">
        You already have an ongoing compliance record under review. Listing creation remains locked until it is approved or verified.
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-6">
        <article class="gov-card p-4 h-100 d-flex flex-column">
            <div class="compliance-option-icon compliance-option-icon--primary">A</div>
            <h2 class="h5 mt-3">Start Reclassification / Rezoning</h2>
            <p class="text-secondary flex-grow-1">
                Use this path when the property is not yet approved. This begins the 14-step CPDO workflow (Processes 1–14) and locks listing until final approval.
            </p>
            <div class="mt-3">
                <a class="btn btn-primary w-100" href="application-form.php">Start CPDO Workflow →</a>
            </div>
        </article>
    </div>
    <div class="col-md-6">
        <article class="gov-card p-4 h-100 d-flex flex-column">
            <div class="compliance-option-icon compliance-option-icon--secondary">B</div>
            <h2 class="h5 mt-3">Already Compliant — Upload Documents</h2>
            <p class="text-secondary flex-grow-1">
                Upload your approved resolution or endorsement, zoning clearance, and proof of ownership for Administrative Officer verification.
            </p>
            <div class="mt-3">
                <a class="btn btn-outline-primary w-100" href="skip-compliance.php">Upload Legal Documents →</a>
            </div>
        </article>
    </div>
</div>

<style>
.compliance-option-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 14px;
    font-size: 1.4rem;
    font-weight: 900;
}
.compliance-option-icon--primary {
    background: var(--cpdo-navy);
    color: #fff;
}
.compliance-option-icon--secondary {
    background: var(--cpdo-light);
    color: var(--cpdo-navy);
    border: 2px solid var(--cpdo-blue);
}
</style>

<?php require __DIR__ . '/../partials/footer.php'; ?>
