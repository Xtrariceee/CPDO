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
    $action = $_POST['action'] ?? 'save';

    $docStmt = db()->prepare('SELECT * FROM requirement_documents WHERE application_id = ?');
    $docStmt->execute([$applicationId]);
    $docs = $docStmt->fetchAll();
    $totalDocs = count($docs);

    $update = db()->prepare(
        'UPDATE requirement_documents
         SET file_data = ?, file_mime = ?, file_path = NULL,
             original_name_enc = ?, original_name_nonce = ?, uploaded_at = NOW()
         WHERE id = ?'
    );

    foreach ($docs as $doc) {
        $key = $doc['requirement_key'];

        if ($key === 'vicinity_map') {
            continue;
        }

        if (!empty($_FILES[$key]['name']) && $_FILES[$key]['error'] === UPLOAD_ERR_OK) {
            $upload        = read_upload_for_db($_FILES[$key]);
            $encryptedName = encrypt_sensitive($_FILES[$key]['name']);

            $update->execute([
                $upload['file_data'],
                $upload['file_mime'],
                $encryptedName['ciphertext'],
                $encryptedName['nonce'],
                (int)$doc['id'],
            ]);
        }
    }

    if ($action === 'submit') {
        $missing = db()->prepare(
            'SELECT COUNT(*) FROM requirement_documents
             WHERE application_id = ? AND file_data IS NULL AND file_path IS NULL'
        );
        $missing->execute([$applicationId]);
        $missingCount = (int)$missing->fetchColumn();

        if ($missingCount === 0) {
            advance_application($applicationId, 'SUBMITTED', 3);
            audit_log((int)$user['id'], 'APPLICATION_SUBMITTED', 'applications', $applicationId);

            $_SESSION['flash_success'] = 'All documents uploaded. Application submitted for pre-evaluation.';
            redirect('landlord/application-show.php?id=' . $applicationId);
        }

        $_SESSION['flash_error'] = 'Progress saved. ' . $missingCount . ' of ' . $totalDocs . ' mandatory documents are still required before submission.';
        redirect('landlord/requirements-upload.php?id=' . $applicationId);
    }

    $_SESSION['flash_success'] = 'Document progress saved.';
    redirect('landlord/requirements-upload.php?id=' . $applicationId);
}

$docsStmt = db()->prepare('SELECT * FROM requirement_documents WHERE application_id = ? ORDER BY id');
$docsStmt->execute([$applicationId]);
$documents = $docsStmt->fetchAll();

$totalDocs    = count($documents);
$uploadedDocs = count(array_filter($documents, fn($d) => !empty($d['file_data']) || !empty($d['file_path'])));

// Build a lookup of effective details
$effectiveReqs = effective_requirements();
$detailsLookup = [];

foreach ($effectiveReqs as $group => $docs) {
    foreach ($docs as $key => $doc) {
        $detailsLookup[$key] = $doc;
    }
}

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a class="btn btn-back btn-sm" href="dashboard.php">
        <span aria-hidden="true">&larr;</span> Dashboard
    </a>

    <div>
        <p class="eyebrow mb-0">Document Upload</p>
        <h1 class="h3 mb-0">Document Upload</h1>
    </div>

    <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
        <div class="text-end me-2">
            <div class="small text-secondary"><?= e($application['registry_number']) ?></div>
            <div class="fw-bold"><?= e($application['property_title']) ?></div>
        </div>

        <button
            type="button"
            class="btn btn-outline-primary btn-sm"
            data-modal-target="app-preview-modal"
        >
            Preview Application
        </button>

        <a
            class="btn btn-outline-secondary btn-sm"
            href="application-form.php?edit=<?= (int)$applicationId ?>"
        >
            Edit Application
        </a>
    </div>
</div>

<!-- Progress -->
<div class="gov-card p-3 mb-4">
    <div class="d-flex justify-content-between mb-2">
        <span class="fw-bold">Upload Progress</span>
        <span id="progress-label" class="text-secondary small">
            <?= $uploadedDocs ?> / <?= $totalDocs ?> uploaded
        </span>
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

        <?php if ($currentGroup !== $doc['group_name']): ?>
            <?php $currentGroup = $doc['group_name']; ?>

            <div class="upload-office-header">
                <span class="upload-office-label">Where to secure:</span>
                <strong><?= e($currentGroup) ?></strong>
            </div>
        <?php endif; ?>

        <?php
            $hasFile    = !empty($doc['file_data']) || !empty($doc['file_path']);
            $isVicinity = $doc['requirement_key'] === 'vicinity_map';
            $reqDetails = $detailsLookup[$doc['requirement_key']] ?? null;
            $detailText = $reqDetails['details'] ?? null;
        ?>

        <div
            class="upload-row <?= $hasFile ? 'is-uploaded' : '' ?>"
            data-upload-row
            data-requirement-key="<?= e($doc['requirement_key']) ?>"
            data-doc-id="<?= (int)$doc['id'] ?>"
        >
            <div class="upload-copy">
                <div class="upload-number"><?= $itemNumber ?></div>

                <div class="upload-text">
                    <strong><?= e($doc['title']) ?></strong>

                    <span
                        data-upload-status
                        class="upload-status-text <?= $hasFile ? 'text-success' : 'text-danger' ?>"
                    >
                        <?= $hasFile ? 'Uploaded' : 'Required' ?>
                    </span>

                    <?php if ($detailText): ?>
                        <details class="req-details-dropdown">
                            <summary class="req-details-summary">
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    width="11"
                                    height="11"
                                    fill="currentColor"
                                    viewBox="0 0 16 16"
                                    aria-hidden="true"
                                >
                                    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                                    <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533L8.93 6.588zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
                                </svg>
                                What to prepare
                            </summary>

                            <div class="req-details-body">
                                <?= nl2br(e($detailText)) ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            </div>

            <div class="upload-control">
                <?php if ($isVicinity): ?>
                    <div class="auto-upload-note">
                        <strong>Auto upload</strong>
                        <span>
                            <?= $hasFile ? 'Generated from the pinned map location.' : 'Pin the location in the application form to generate this PDF.' ?>
                        </span>
                    </div>
                <?php else: ?>
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
                        <?= $hasFile ? 'File saved' : 'No file chosen' ?>
                    </span>
                <?php endif; ?>

                <button
                    type="button"
                    class="preview-link <?= $hasFile ? '' : 'is-hidden' ?>"
                    data-preview-btn
                    data-preview-url="<?= $hasFile ? e(rtrim($config['app']['base_url'], '/') . '/document_preview.php?id=' . (int)$doc['id']) : '' ?>"
                    data-preview-type="<?= $hasFile ? e($doc['file_mime'] ?? 'application/pdf') : '' ?>"
                    aria-label="Preview <?= e($doc['title']) ?>"
                >
                    Preview
                </button>

                <?php if ($isVicinity && !$hasFile): ?>
                    <a class="preview-link" href="application-form.php?edit=<?= (int)$applicationId ?>">
                        Edit Map
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="d-flex gap-3 mt-4 align-items-center flex-wrap">
        <button class="btn btn-outline-primary" type="submit" name="action" value="save">
            Save Documents
        </button>

        <button class="btn btn-primary" id="submit-btn" type="submit" name="action" value="submit">
            Submit Documents
        </button>

        <span class="text-secondary small" id="submit-hint">
            <?php if ($uploadedDocs < $totalDocs): ?>
                <?= $totalDocs - $uploadedDocs ?> document(s) still required.
            <?php else: ?>
                All documents uploaded - ready to submit.
            <?php endif; ?>
        </span>
    </div>
</form>

<style>
    /* ─────────────────────────────────────────────
       Upload Page Layout Improvements
       Moves Choose File + Preview to the right
    ───────────────────────────────────────────── */

    .document-upload-panel {
        background: #fff;
        border: 1px solid #f0d99f;
        border-radius: 18px;
        padding: clamp(18px, 3vw, 32px);
        box-shadow: 0 14px 36px rgba(36, 27, 11, 0.06);
    }

    .upload-office-header {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        margin: 28px 0 0;
        padding: 14px 18px;
        background: linear-gradient(90deg, #fff4c7 0%, #fff8da 100%);
        border-left: 4px solid #f6cf4a;
        border-radius: 10px 10px 0 0;
        color: #211707;
        font-size: 0.88rem;
    }

    .upload-office-label {
        color: #c59000;
        font-size: 0.72rem;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .upload-row {
        display: flex !important;
        align-items: center;
        justify-content: space-between;
        gap: 24px;
        width: 100%;
        min-height: 104px;
        padding: 22px 8px;
        border-bottom: 1px solid rgba(240, 217, 159, 0.42);
        background: transparent;
    }

    .upload-row.is-uploaded {
        background: #f5fcf8;
        border-radius: 0 0 10px 10px;
        padding-left: 8px;
        padding-right: 8px;
    }

    .upload-copy {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        flex: 1 1 auto;
        min-width: 0;
    }

    .upload-number {
        width: 24px;
        height: 24px;
        flex: 0 0 24px;
        border-radius: 50%;
        background: #241b0b;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 800;
        line-height: 1;
        margin-top: 1px;
    }

    .upload-text {
        flex: 1;
        min-width: 0;
    }

    .upload-text strong {
        display: block;
        color: #161008;
        font-size: 0.9rem;
        font-weight: 800;
        margin-bottom: 5px;
    }

    .upload-status-text {
        display: block;
        font-size: 0.74rem;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .upload-control {
        margin-left: auto;
        display: flex !important;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        flex: 0 0 auto;
        min-width: 430px;
        max-width: 520px;
        text-align: right;
    }

    .file-picker {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        margin: 0;
        cursor: pointer;
    }

    .file-picker input[type="file"] {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }

    .file-picker span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 38px;
        padding: 10px 17px;
        border-radius: 8px;
        background: #241b0b;
        color: #fff;
        font-size: 0.78rem;
        font-weight: 800;
        border: 1px solid #241b0b;
        box-shadow: 0 8px 18px rgba(36, 27, 11, 0.12);
        transition: transform 0.16s ease, box-shadow 0.16s ease, opacity 0.16s ease;
        white-space: nowrap;
    }

    .file-picker:hover span {
        transform: translateY(-1px);
        box-shadow: 0 10px 22px rgba(36, 27, 11, 0.16);
        opacity: 0.92;
    }

    .file-name {
        display: inline-block;
        max-width: 160px;
        color: #7a6a4d;
        font-size: 0.8rem;
        font-weight: 600;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        text-align: left;
    }

    .preview-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 34px;
        padding: 8px 14px;
        border-radius: 8px;
        border: 1px solid #f4bf31;
        background: #fff9e6;
        color: #c59000;
        font-size: 0.76rem;
        font-weight: 800;
        line-height: 1;
        text-decoration: none;
        transition: background 0.16s ease, color 0.16s ease, transform 0.16s ease;
        white-space: nowrap;
    }

    button.preview-link {
        appearance: none;
        cursor: pointer;
    }

    .preview-link:hover {
        background: #f6cf4a;
        color: #241b0b;
        transform: translateY(-1px);
    }

    .preview-link.is-hidden {
        display: none !important;
    }

    /* ── Requirement details dropdown ── */

    .req-details-dropdown {
        margin-top: 5px;
    }

    .req-details-summary {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #c59000;
        cursor: pointer;
        user-select: none;
        list-style: none;
        padding: 3px 0;
        letter-spacing: 0.02em;
    }

    .req-details-summary::-webkit-details-marker {
        display: none;
    }

    .req-details-summary::marker {
        display: none;
    }

    .req-details-summary:hover {
        color: #8a6400;
    }

    .req-details-body {
        margin-top: 8px;
        padding: 10px 14px;
        background: #fffaf0;
        border-left: 3px solid #f6cf4a;
        border-radius: 0 8px 8px 0;
        font-size: 0.8rem;
        color: #6b5b3d;
        line-height: 1.6;
        max-width: 640px;
    }

    .auto-upload-note {
        min-height: 42px;
        padding: 9px 13px;
        border-radius: 9px;
        border: 1px solid #f0d99f;
        background: #fffdf7;
        color: #6b5b3d;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 2px;
        min-width: 260px;
        max-width: 340px;
        text-align: left;
    }

    .auto-upload-note strong {
        font-size: 0.72rem;
        color: #241b0b;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .auto-upload-note span {
        font-size: 0.76rem;
        line-height: 1.35;
    }

    @media (max-width: 991.98px) {
        .upload-row {
            align-items: flex-start;
            gap: 18px;
        }

        .upload-control {
            min-width: 330px;
            max-width: 420px;
            flex-wrap: wrap;
        }

        .file-name {
            max-width: 130px;
        }
    }

    @media (max-width: 767.98px) {
        .document-upload-panel {
            padding: 18px;
        }

        .upload-office-header {
            align-items: flex-start;
            flex-direction: column;
            gap: 4px;
        }

        .upload-row {
            flex-direction: column;
            align-items: stretch;
            min-height: auto;
            padding: 18px 0;
        }

        .upload-row.is-uploaded {
            padding-left: 12px;
            padding-right: 12px;
        }

        .upload-control {
            width: 100%;
            min-width: 0;
            max-width: none;
            margin-left: 0;
            justify-content: flex-start;
            text-align: left;
        }

        .file-name {
            max-width: calc(100vw - 230px);
        }

        .auto-upload-note {
            width: 100%;
            min-width: 0;
            max-width: none;
        }
    }

    @media (max-width: 420px) {
        .upload-control {
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
        }

        .file-picker,
        .file-picker span,
        .preview-link {
            width: 100%;
        }

        .file-name {
            max-width: 100%;
            width: 100%;
            text-align: center;
        }
    }
</style>

<script>
(function () {
    var STORAGE_KEY   = 'cpdo_upload_drafts_<?= (int)$applicationId ?>';
    var csrfToken     = <?= json_encode(csrf_token()) ?>;
    var appId         = <?= (int)$applicationId ?>;
    var totalDocs     = <?= (int)$totalDocs ?>;
    var uploadedCount = <?= (int)$uploadedDocs ?>;

    function loadDrafts() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        } catch (e) {
            return {};
        }
    }

    function saveDraft(key, data) {
        var drafts = loadDrafts();
        drafts[key] = data;

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(drafts));
        } catch (e) {}
    }

    function clearDraft(key) {
        var drafts = loadDrafts();
        delete drafts[key];

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(drafts));
        } catch (e) {}
    }

    function updateProgress(delta) {
        uploadedCount = Math.max(0, uploadedCount + delta);

        var pct   = totalDocs > 0 ? Math.round(uploadedCount / totalDocs * 100) : 0;
        var bar   = document.getElementById('progress-bar');
        var label = document.getElementById('progress-label');
        var hint  = document.getElementById('submit-hint');

        if (bar) {
            bar.style.width = pct + '%';
            bar.setAttribute('aria-valuenow', uploadedCount);
        }

        if (label) {
            label.textContent = uploadedCount + ' / ' + totalDocs + ' uploaded';
        }

        if (hint) {
            var rem = totalDocs - uploadedCount;
            hint.textContent = rem > 0
                ? rem + ' document(s) still required.'
                : 'All documents uploaded - ready to submit.';
        }
    }

    document.querySelectorAll('[data-autosave-file]').forEach(function (input) {
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];

            if (!file) {
                return;
            }

            var key         = input.dataset.requirementKey;
            var row         = input.closest('[data-upload-row]');
            var statusEl    = row.querySelector('[data-upload-status]');
            var fileNameEl  = row.querySelector('[data-file-name]');
            var previewBtn  = row.querySelector('[data-preview-btn]');
            var wasUploaded = row.classList.contains('is-uploaded');

            saveDraft(key, { fileName: file.name });

            if (fileNameEl) {
                fileNameEl.textContent = file.name;
            }

            if (statusEl) {
                statusEl.textContent = 'Uploading...';
                statusEl.className   = 'upload-status-text text-secondary';
            }

            if (previewBtn) {
                previewBtn.dataset.previewUrl  = URL.createObjectURL(file);
                previewBtn.dataset.previewType = file.name.split('.').pop().toLowerCase();
                previewBtn.classList.remove('is-hidden');
            }

            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('application_id', appId);
            fd.append('requirement_key', key);
            fd.append('document_file', file);

            fetch('../upload_requirement.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
            .then(function (r) {
                return r.json();
            })
            .then(function (p) {
                if (!p.ok) {
                    throw new Error(p.message || 'Upload failed.');
                }

                if (previewBtn) {
                    previewBtn.dataset.previewUrl  = p.preview_url;
                    previewBtn.dataset.previewType = p.file_mime || file.name.split('.').pop().toLowerCase();
                }

                if (statusEl) {
                    statusEl.textContent = 'Uploaded';
                    statusEl.className   = 'upload-status-text text-success';
                }

                row.classList.add('is-uploaded');
                clearDraft(key);

                if (!wasUploaded) {
                    updateProgress(1);
                }
            })
            .catch(function (err) {
                if (statusEl) {
                    statusEl.textContent = 'Not saved';
                    statusEl.className   = 'upload-status-text text-danger';
                }

                alert('Upload error: ' + err.message);
            });
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (Object.keys(loadDrafts()).length > 0) {
            e.preventDefault();
            e.returnValue = 'You have unsaved draft selections. Leave anyway?';
        }
    });

    var form = document.getElementById('upload-form');

    if (form) {
        form.addEventListener('submit', function () {
            try {
                localStorage.removeItem(STORAGE_KEY);
            } catch (e) {}
        });
    }
}());
</script>

<?php
$noa = $application['nature_of_application'] ?? '';

$noaLabel = match ($noa) {
    'new_development' => 'New Development',
    'improvement'     => 'Improvement',
    'others'          => 'Others' . ($application['nature_of_application_other'] ? ': ' . $application['nature_of_application_other'] : ''),
    default           => 'N/A',
};

$rol = $application['right_over_land'] ?? '';

$rolLabel = match ($rol) {
    'owner'  => 'Owner',
    'lessee' => 'Lessee',
    default  => 'N/A',
};

$elu = $application['existing_land_use'] ?? '';

$eluLabel = match ($elu) {
    'residential'   => 'Residential',
    'commercial'    => 'Commercial',
    'industrial'    => 'Industrial',
    'institutional' => 'Institutional',
    'agricultural'  => 'Agricultural',
    'others'        => 'Others' . ($application['existing_land_use_other'] ? ': ' . $application['existing_land_use_other'] : ''),
    default         => 'N/A',
};
?>

<!-- Application Preview Modal -->
<div class="modal-backdrop" id="app-preview-modal" role="dialog" aria-modal="true" aria-labelledby="apm-title">
    <div class="modal-box" style="max-width:680px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header d-flex justify-content-between align-items-center">
            <div>
                <h2 class="h6 mb-0" id="apm-title">Application for Locational Clearance</h2>
                <p class="small text-secondary mb-0"><?= e($application['registry_number']) ?></p>
            </div>

            <button class="preview-modal-close" data-modal-close type="button" aria-label="Close">
                &times;
            </button>
        </div>

        <div class="modal-body p-4">
            <p class="apm-section-title">Applicant Details</p>

            <div class="row g-2 mb-3">
                <div class="col-sm-6">
                    <p class="apm-label">Name of Applicant</p>
                    <p class="apm-value"><?= e($application['account_name']) ?></p>
                </div>

                <div class="col-sm-6">
                    <p class="apm-label">Address</p>
                    <p class="apm-value"><?= nl2br(e($application['account_address'])) ?></p>
                </div>

                <?php if ($application['corporation_name']): ?>
                    <div class="col-sm-6">
                        <p class="apm-label">Corporation</p>
                        <p class="apm-value"><?= e($application['corporation_name']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($application['representative_name']): ?>
                    <div class="col-sm-6">
                        <p class="apm-label">Representative</p>
                        <p class="apm-value"><?= e($application['representative_name']) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <p class="apm-section-title">Project Details</p>

            <div class="row g-2 mb-3">
                <div class="col-sm-6">
                    <p class="apm-label">Project / Property Title</p>
                    <p class="apm-value"><?= e($application['property_title']) ?></p>
                </div>

                <div class="col-sm-6">
                    <p class="apm-label">Type of Project</p>
                    <p class="apm-value"><?= e($application['type_of_project'] ?? 'N/A') ?></p>
                </div>

                <div class="col-12">
                    <p class="apm-label">Project Location</p>
                    <p class="apm-value"><?= nl2br(e($application['property_address'])) ?></p>
                </div>

                <div class="col-sm-4">
                    <p class="apm-label">Lot Area (sqm)</p>
                    <p class="apm-value">
                        <?= $application['lot_area'] ? number_format((float)$application['lot_area'], 2) : 'N/A' ?>
                    </p>
                </div>

                <div class="col-sm-4">
                    <p class="apm-label">Building Area (sqm)</p>
                    <p class="apm-value">
                        <?= $application['building_area'] ? number_format((float)$application['building_area'], 2) : 'N/A' ?>
                    </p>
                </div>

                <div class="col-sm-4">
                    <p class="apm-label">Project Cost (PHP)</p>
                    <p class="apm-value">
                        <?= $application['project_cost'] ? number_format((float)$application['project_cost'], 2) : 'N/A' ?>
                    </p>
                </div>

                <?php if ($application['coordinates']): ?>
                    <div class="col-sm-6">
                        <p class="apm-label">Coordinates</p>
                        <p class="apm-value"><?= e($application['coordinates']) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <p class="apm-section-title">Land Use &amp; Classification</p>

            <div class="row g-2 mb-3">
                <div class="col-sm-4">
                    <p class="apm-label">Nature of Application</p>
                    <p class="apm-value"><?= e($noaLabel) ?></p>
                </div>

                <div class="col-sm-4">
                    <p class="apm-label">Right over Land</p>
                    <p class="apm-value"><?= e($rolLabel) ?></p>
                </div>

                <div class="col-sm-4">
                    <p class="apm-label">Existing Land Use</p>
                    <p class="apm-value"><?= e($eluLabel) ?></p>
                </div>
            </div>

            <p class="apm-section-title">Sworn Statement</p>

            <p class="apm-value">
                <?php if ($application['sworn_statement']): ?>
                    <span class="badge text-bg-success">Confirmed</span>
                    Applicant affirmed all details are true and correct.
                <?php else: ?>
                    <span class="badge text-bg-warning">Not confirmed</span>
                <?php endif; ?>
            </p>

            <?php if ($application['vicinity_map_pdf_path']): ?>
                <p class="apm-section-title">Vicinity Map</p>

                <p class="apm-value">
                    <a
                        href="<?= e(rtrim($config['app']['base_url'], '/') . '/vicinity_map_preview.php?id=' . (int)$applicationId) ?>"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-sm btn-outline-primary"
                    >
                        View Generated PDF
                    </a>
                </p>
            <?php endif; ?>
        </div>

        <div class="modal-footer d-flex justify-content-end gap-2 p-3" style="border-top:1px solid var(--cpdo-border);">
            <a
                class="btn btn-outline-secondary btn-sm"
                href="application-form.php?edit=<?= (int)$applicationId ?>"
            >
                Edit Application
            </a>

            <button class="btn btn-sm btn-primary" data-modal-close type="button">
                Close
            </button>
        </div>
    </div>
</div>

<style>
    .apm-section-title {
        font-size: 0.68rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.09em;
        color: var(--cpdo-blue);
        margin: 16px 0 8px;
        padding-bottom: 5px;
        border-bottom: 1px solid var(--cpdo-border);
    }

    .apm-label {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--cpdo-muted);
        margin-bottom: 2px;
    }

    .apm-value {
        font-size: 0.88rem;
        color: var(--cpdo-deep);
        font-weight: 600;
        margin-bottom: 0;
    }
</style>

<?php require __DIR__ . '/../partials/footer.php'; ?>