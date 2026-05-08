<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $propertyTitle = trim($_POST['property_title'] ?? '');
    if (!$propertyTitle) {
        $_SESSION['flash_error'] = 'Property title is required.';
        redirect('landlord/skip-compliance.php');
    }

    $resolution = secure_upload($_FILES['approved_resolution'], 'compliance/' . $user['id']);
    $zoning     = secure_upload($_FILES['zoning_clearance'],    'compliance/' . $user['id']);
    $ownership  = secure_upload($_FILES['proof_of_ownership'],  'compliance/' . $user['id']);

    if (!$resolution || !$zoning || !$ownership) {
        $_SESSION['flash_error'] = 'All three legal documents are required.';
        redirect('landlord/skip-compliance.php');
    }

    $pdo  = db();
    $stmt = $pdo->prepare(
        'INSERT INTO compliance_uploads (landlord_id, property_title, approved_resolution_path, zoning_clearance_path, proof_of_ownership_path)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([(int)$user['id'], $propertyTitle, $resolution, $zoning, $ownership]);
    audit_log((int)$user['id'], 'SKIP_COMPLIANCE_SUBMITTED', 'compliance_uploads', (int)$pdo->lastInsertId());
    $_SESSION['flash_success'] = 'Documents submitted. An Administrative Officer will verify them shortly.';
    redirect('landlord/dashboard.php');
}

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="compliance-gateway.php"><span aria-hidden="true">&larr;</span> Back</a>
    <div>
        <p class="eyebrow mb-0">Option B</p>
        <h1 class="h3 mb-0">Upload Compliance Documents</h1>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="gov-card p-4 h-100">
            <h2 class="h5 mb-3">
                <span class="process-badge">3</span>
                Required Documents
            </h2>

            <div class="req-office-block">
                <p class="req-office-label">Administrative Officer Verification</p>
                <ul class="req-list">
                    <li><span class="req-num">1</span>Approved Resolution or Endorsement from CPDO</li>
                    <li><span class="req-num">2</span>Zoning Clearance</li>
                    <li><span class="req-num">3</span>Proof of Ownership (Title / Contract of Lease / Deed of Sale)</li>
                </ul>
            </div>

            <div class="alert alert-info mt-4 mb-0 small">
                <strong>Note:</strong> All uploaded documents will be reviewed by an Administrative Officer. You will be notified once verification is complete.
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <form class="document-upload-panel" method="post" enctype="multipart/form-data" id="skip-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="mb-4">
                <label class="form-label fw-bold">Property Title / Name <span class="text-danger">*</span></label>
                <input class="form-control" name="property_title" required placeholder="e.g. Lot 12 Block 5, Buhangin">
            </div>

            <!-- Document 1 -->
            <div class="upload-office-header">
                <span class="upload-office-label">Document 1</span>
                <strong>Approved Resolution / Endorsement</strong>
            </div>
            <div class="upload-row" data-upload-row data-requirement-key="approved_resolution">
                <div class="upload-copy">
                    <div class="upload-number">1</div>
                    <div>
                        <strong>Approved Resolution or Endorsement</strong>
                        <span data-upload-status class="upload-status-text text-danger">Required</span>
                    </div>
                </div>
                <div class="upload-control">
                    <label class="file-picker">
                        <input
                            type="file"
                            name="approved_resolution"
                            data-preview-input
                            data-requirement-key="approved_resolution"
                            accept=".pdf,.jpg,.jpeg,.png"
                            required
                        >
                        <span>Choose file</span>
                    </label>
                    <span class="file-name" data-file-name>No file chosen</span>
                    <button type="button" class="preview-link is-hidden" data-preview-btn data-preview-url="" data-preview-type="">Preview</button>
                </div>
            </div>

            <!-- Document 2 -->
            <div class="upload-office-header">
                <span class="upload-office-label">Document 2</span>
                <strong>Zoning Clearance</strong>
            </div>
            <div class="upload-row" data-upload-row data-requirement-key="zoning_clearance">
                <div class="upload-copy">
                    <div class="upload-number">2</div>
                    <div>
                        <strong>Zoning Clearance</strong>
                        <span data-upload-status class="upload-status-text text-danger">Required</span>
                    </div>
                </div>
                <div class="upload-control">
                    <label class="file-picker">
                        <input
                            type="file"
                            name="zoning_clearance"
                            data-preview-input
                            data-requirement-key="zoning_clearance"
                            accept=".pdf,.jpg,.jpeg,.png"
                            required
                        >
                        <span>Choose file</span>
                    </label>
                    <span class="file-name" data-file-name>No file chosen</span>
                    <button type="button" class="preview-link is-hidden" data-preview-btn data-preview-url="" data-preview-type="">Preview</button>
                </div>
            </div>

            <!-- Document 3 -->
            <div class="upload-office-header">
                <span class="upload-office-label">Document 3</span>
                <strong>Proof of Ownership</strong>
            </div>
            <div class="upload-row" data-upload-row data-requirement-key="proof_of_ownership">
                <div class="upload-copy">
                    <div class="upload-number">3</div>
                    <div>
                        <strong>Proof of Ownership</strong>
                        <span class="small text-secondary d-block">Title / Contract of Lease / Deed of Sale</span>
                        <span data-upload-status class="upload-status-text text-danger">Required</span>
                    </div>
                </div>
                <div class="upload-control">
                    <label class="file-picker">
                        <input
                            type="file"
                            name="proof_of_ownership"
                            data-preview-input
                            data-requirement-key="proof_of_ownership"
                            accept=".pdf,.jpg,.jpeg,.png"
                            required
                        >
                        <span>Choose file</span>
                    </label>
                    <span class="file-name" data-file-name>No file chosen</span>
                    <button type="button" class="preview-link is-hidden" data-preview-btn data-preview-url="" data-preview-type="">Preview</button>
                </div>
            </div>

            <div class="d-flex gap-3 mt-4 align-items-center flex-wrap">
                <button class="btn btn-primary">Submit for Verification</button>
                <span class="text-secondary small">All 3 documents are required.</span>
            </div>
        </form>
    </div>
</div>

<!-- Preview Modal injected by dlp.js -->

<script>
(function () {
    // Local preview on file select — shows blob URL immediately, no server round-trip
    document.querySelectorAll('[data-preview-input]').forEach(function (input) {
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            var row        = input.closest('[data-upload-row]');
            var statusEl   = row.querySelector('[data-upload-status]');
            var fileNameEl = row.querySelector('[data-file-name]');
            var previewBtn = row.querySelector('[data-preview-btn]');

            fileNameEl.textContent = file.name;
            statusEl.textContent   = 'Selected';
            statusEl.className     = 'upload-status-text text-success';

            if (previewBtn) {
                previewBtn.dataset.previewUrl  = URL.createObjectURL(file);
                previewBtn.dataset.previewType = file.name.split('.').pop().toLowerCase();
                previewBtn.dataset.previewTitle = row.querySelector('strong') ? row.querySelector('strong').textContent : 'Preview';
                previewBtn.classList.remove('is-hidden');
            }
        });
    });
    // Preview modal handled globally by dlp.js
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
