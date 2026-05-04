<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
$appStmt = db()->prepare('SELECT * FROM applications WHERE id = ? AND landlord_id = ?');
$appStmt->execute([$applicationId, (int)$user['id']]);
$application = $appStmt->fetch();
if (!$application) {
    http_response_code(404);
    exit('Application not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $docStmt = db()->prepare('SELECT * FROM requirement_documents WHERE application_id = ?');
    $docStmt->execute([$applicationId]);
    $docs = $docStmt->fetchAll();
    $update = db()->prepare(
        'UPDATE requirement_documents SET file_path = ?, original_name_enc = ?, original_name_nonce = ?, uploaded_at = NOW() WHERE id = ?'
    );

    foreach ($docs as $doc) {
        $key = $doc['requirement_key'];
        if (!empty($_FILES[$key]['name'])) {
            $path = secure_upload($_FILES[$key], 'applications/' . $applicationId);
            $encryptedName = encrypt_sensitive($_FILES[$key]['name']);
            $update->execute([$path, $encryptedName['ciphertext'], $encryptedName['nonce'], (int)$doc['id']]);
        }
    }

    $missing = db()->prepare('SELECT COUNT(*) FROM requirement_documents WHERE application_id = ? AND file_path IS NULL');
    $missing->execute([$applicationId]);
    if ((int)$missing->fetchColumn() === 0) {
        advance_application($applicationId, 'SUBMITTED', 3);
        audit_log((int)$user['id'], 'APPLICATION_SUBMITTED', 'applications', $applicationId);
        $_SESSION['flash_success'] = 'All documents uploaded. Application submitted for pre-evaluation.';
        redirect('landlord/application-show.php?id=' . $applicationId);
    }

    $_SESSION['flash_error'] = 'Progress saved. All 19 mandatory documents are required before submission.';
}

$docsStmt = db()->prepare('SELECT * FROM requirement_documents WHERE application_id = ? ORDER BY id');
$docsStmt->execute([$applicationId]);
$documents = $docsStmt->fetchAll();

$totalDocs    = count($documents);
$uploadedDocs = count(array_filter($documents, fn($d) => !empty($d['file_path'])));

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-outline-secondary btn-sm" href="dashboard.php">← Dashboard</a>
    <div>
        <p class="eyebrow mb-0">Process 1 &rarr; 2</p>
        <h1 class="h3 mb-0">Document Upload</h1>
    </div>
    <div class="ms-auto text-end">
        <div class="small text-secondary"><?= e($application['registry_number']) ?></div>
        <div class="fw-bold"><?= e($application['property_title']) ?></div>
    </div>
</div>

<!-- Progress -->
<div class="gov-card p-3 mb-4">
    <div class="d-flex justify-content-between mb-2">
        <span class="fw-bold">Upload Progress</span>
        <span id="progress-label" class="text-secondary small"><?= $uploadedDocs ?> / <?= $totalDocs ?> uploaded</span>
    </div>
    <div class="progress" style="height:8px;border-radius:99px;">
        <div
            class="progress-bar bg-success"
            id="progress-bar"
            role="progressbar"
            style="width:<?= $totalDocs > 0 ? round($uploadedDocs / $totalDocs * 100) : 0 ?>%"
            aria-valuenow="<?= $uploadedDocs ?>"
            aria-valuemin="0"
            aria-valuemax="<?= $totalDocs ?>"
        ></div>
    </div>
</div>

<form class="document-upload-panel" method="post" enctype="multipart/form-data" id="upload-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">

    <?php $currentGroup = ''; $itemNumber = 0; ?>
    <?php foreach ($documents as $doc): ?>
        <?php $itemNumber++; ?>
        <?php if ($currentGroup !== $doc['group_name']): $currentGroup = $doc['group_name']; ?>
            <div class="upload-office-header">
                <span class="upload-office-label">Where to secure:</span>
                <strong><?= e($currentGroup) ?></strong>
            </div>
        <?php endif; ?>

        <div
            class="upload-row <?= $doc['file_path'] ? 'is-uploaded' : '' ?>"
            data-upload-row
            data-requirement-key="<?= e($doc['requirement_key']) ?>"
            data-doc-id="<?= (int)$doc['id'] ?>"
        >
            <div class="upload-copy">
                <div class="upload-number"><?= $itemNumber ?></div>
                <div>
                    <strong><?= e($doc['title']) ?></strong>
                    <span data-upload-status class="upload-status-text <?= $doc['file_path'] ? 'text-success' : 'text-danger' ?>">
                        <?= $doc['file_path'] ? '✓ Uploaded' : 'Required' ?>
                    </span>
                </div>
            </div>
            <div class="upload-control">
                <label class="file-picker">
                    <input
                        type="file"
                        name="<?= e($doc['requirement_key']) ?>"
                        data-autosave-file
                        data-requirement-key="<?= e($doc['requirement_key']) ?>"
                        accept=".pdf,.jpg,.jpeg,.png"
                        aria-label="Upload <?= e($doc['title']) ?>"
                    >
                    <span>Choose file</span>
                </label>
                <span class="file-name" data-file-name>
                    <?= $doc['file_path'] ? 'File saved' : 'No file chosen' ?>
                </span>
                <button
                    type="button"
                    class="preview-link <?= $doc['file_path'] ? '' : 'is-hidden' ?>"
                    data-preview-btn
                    data-preview-url="<?= $doc['file_path'] ? e('../document_preview.php?id=' . (int)$doc['id']) : '' ?>"
                    data-preview-type="<?= $doc['file_path'] ? e(strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION))) : '' ?>"
                    aria-label="Preview <?= e($doc['title']) ?>"
                >Preview</button>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="d-flex gap-3 mt-4 align-items-center flex-wrap">
        <button class="btn btn-primary" id="submit-btn">
            Save &amp; Submit All Documents
        </button>
        <span class="text-secondary small" id="submit-hint">
            <?php if ($uploadedDocs < $totalDocs): ?>
                <?= $totalDocs - $uploadedDocs ?> document(s) still required.
            <?php else: ?>
                All documents uploaded — ready to submit.
            <?php endif; ?>
        </span>
    </div>
</form>


<?php require __DIR__ . '/../partials/footer.php'; ?>
(function () {
    var STORAGE_KEY = 'cpdo_upload_drafts_<?= (int)$applicationId ?>';
    var csrfToken   = <?= json_encode(csrf_token()) ?>;
    var appId       = <?= (int)$applicationId ?>;
    var totalDocs   = <?= (int)$totalDocs ?>;
    var uploadedCount = <?= (int)$uploadedDocs ?>;

    function loadDrafts() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { return {}; }
    }
    function saveDraft(key, data) {
        var drafts = loadDrafts(); drafts[key] = data;
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(drafts)); } catch (e) {}
    }
    function clearDraft(key) {
        var drafts = loadDrafts(); delete drafts[key];
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(drafts)); } catch (e) {}
    }

    function updateProgress(delta) {
        uploadedCount = Math.max(0, uploadedCount + delta);
        var pct   = totalDocs > 0 ? Math.round(uploadedCount / totalDocs * 100) : 0;
        var bar   = document.getElementById('progress-bar');
        var label = document.getElementById('progress-label');
        var hint  = document.getElementById('submit-hint');
        if (bar)   { bar.style.width = pct + '%'; bar.setAttribute('aria-valuenow', uploadedCount); }
        if (label) { label.textContent = uploadedCount + ' / ' + totalDocs + ' uploaded'; }
        if (hint) {
            var rem = totalDocs - uploadedCount;
            hint.textContent = rem > 0 ? rem + ' document(s) still required.' : 'All documents uploaded — ready to submit.';
        }
    }

    // Restore draft labels on load
    (function () {
        var drafts = loadDrafts();
        Object.keys(drafts).forEach(function (key) {
            var row = document.querySelector('[data-upload-row][data-requirement-key="' + key + '"]');
            if (!row) return;
            var d = drafts[key];
            if (d && d.fileName) {
                var fn = row.querySelector('[data-file-name]');
                var st = row.querySelector('[data-upload-status]');
                if (fn) fn.textContent = d.fileName + ' (draft)';
                if (st) { st.textContent = 'Draft'; st.className = 'upload-status-text text-warning'; }
            }
        });
    }());

    // Auto-save on file select
    document.querySelectorAll('[data-autosave-file]').forEach(function (input) {
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            var key        = input.dataset.requirementKey;
            var row        = input.closest('[data-upload-row]');
            var statusEl   = row.querySelector('[data-upload-status]');
            var fileNameEl = row.querySelector('[data-file-name]');
            var previewBtn = row.querySelector('[data-preview-btn]');
            var wasUploaded = row.classList.contains('is-uploaded');

            saveDraft(key, { fileName: file.name });
            fileNameEl.textContent = file.name;
            statusEl.textContent   = 'Uploading…';
            statusEl.className     = 'upload-status-text text-secondary';

            if (previewBtn) {
                previewBtn.dataset.previewUrl  = URL.createObjectURL(file);
                previewBtn.dataset.previewType = file.name.split('.').pop().toLowerCase();
                previewBtn.classList.remove('is-hidden');
            }

            var fd = new FormData();
            fd.append('csrf_token',      csrfToken);
            fd.append('application_id',  appId);
            fd.append('requirement_key', key);
            fd.append('document_file',   file);

            fetch('../upload_requirement.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (p) {
                    if (!p.ok) throw new Error(p.message || 'Upload failed.');
                    if (previewBtn) {
                        previewBtn.dataset.previewUrl  = p.preview_url;
                        previewBtn.dataset.previewType = file.name.split('.').pop().toLowerCase();
                    }
                    statusEl.textContent = '✓ Uploaded';
                    statusEl.className   = 'upload-status-text text-success';
                    row.classList.add('is-uploaded');
                    clearDraft(key);
                    if (!wasUploaded) updateProgress(1);
                })
                .catch(function (err) {
                    statusEl.textContent = '✗ Not saved';
                    statusEl.className   = 'upload-status-text text-danger';
                    alert('Upload error: ' + err.message);
                });
        });
    });

    // Preview is handled globally by dlp.js (window.CPDO.preview)
    // [data-preview-btn] clicks are wired up in dlp.js automatically

    // Warn on leave if drafts exist
    window.addEventListener('beforeunload', function (e) {
        if (Object.keys(loadDrafts()).length > 0) {
            e.preventDefault();
            e.returnValue = 'You have unsaved draft selections. Leave anyway?';
        }
    });

    // Clear drafts on submit
    var form = document.getElementById('upload-form');
    if (form) form.addEventListener('submit', function () {
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
    });

    // Carousel
    document.querySelectorAll('[data-carousel]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id  = btn.dataset.carousel;
            var el  = document.getElementById(id);
            var dir = btn.classList.contains('carousel-nav--next') ? 1 : -1;
            if (el) el.scrollBy({ left: dir * 320, behavior: 'smooth' });
        });
    });
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
