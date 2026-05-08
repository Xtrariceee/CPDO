<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application = officer_application($applicationId);
    $docs = db()->prepare('SELECT id FROM requirement_documents WHERE application_id = ?');
    $docs->execute([$applicationId]);
    $update = db()->prepare(
        'UPDATE requirement_documents
         SET evaluation_status = ?, officer_notes = ?, evaluated_by = ?, evaluated_at = NOW()
         WHERE id = ?'
    );
    foreach ($docs->fetchAll() as $doc) {
        $status = $_POST['doc_' . $doc['id']] ?? 'PENDING';
        if (in_array($status, ['PENDING', 'PASSED', 'FAILED'], true)) {
            $update->execute([
                $status,
                trim($_POST['notes_' . $doc['id']] ?? ''),
                (int)$user['id'],
                (int)$doc['id'],
            ]);
        }
    }
    $pending = db()->prepare(
        'SELECT COUNT(*) FROM requirement_documents WHERE application_id = ? AND evaluation_status != "PASSED"'
    );
    $pending->execute([$applicationId]);
    if ((int)$pending->fetchColumn() === 0) {
        create_payment_order_if_missing($application, (int)$user['id']);
        $_SESSION['flash_success'] = 'All requirements passed. Application moved to For Payment.';
    } else {
        advance_application($applicationId, 'PRE_EVALUATION', 3);
        $_SESSION['flash_success'] = 'Evaluation saved.';
    }
    audit_log((int)$user['id'], 'P3_PRE_EVALUATION_SAVED', 'applications', $applicationId);
    redirect('zoning-officer/pre-evaluation.php?id=' . $applicationId);
}

$applications = officer_applications(['SUBMITTED', 'PRE_EVALUATION']);
$application  = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$documents    = [];
if ($application) {
    $stmt = db()->prepare(
        'SELECT * FROM requirement_documents WHERE application_id = ? ORDER BY id'
    );
    $stmt->execute([(int)$application['id']]);
    $documents = $stmt->fetchAll();
}

$totalDocs  = count($documents);
$passedDocs = count(array_filter($documents, fn($d) => $d['evaluation_status'] === 'PASSED'));
$failedDocs = count(array_filter($documents, fn($d) => $d['evaluation_status'] === 'FAILED'));
$uploadedDocs = count(array_filter($documents, fn($d) => !empty($d['file_data']) || !empty($d['file_path'])));

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="index.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
    <div>
        <h1 class="h3 mb-0">Pre-Evaluation</h1>
    </div>
</div>

<div class="row g-4">
    <!-- Application list sidebar -->
    <div class="col-lg-3">
        <div class="gov-card p-3">
            <p class="eyebrow mb-2">Submitted Applications</p>
            <?php if ($applications): ?>
                <div class="d-grid gap-1">
                    <?php foreach ($applications as $row): ?>
                        <?php $isActive = $application && (int)$application['id'] === (int)$row['id']; ?>
                        <a
                            class="app-list-item <?= $isActive ? 'app-list-item--active' : '' ?>"
                            href="pre-evaluation.php?id=<?= (int)$row['id'] ?>"
                        >
                            <span class="app-list-registry"><?= e($row['registry_number']) ?></span>
                            <span class="app-list-title"><?= e($row['property_title']) ?></span>
                            <span class="app-list-landlord"><?= e($row['landlord_name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-secondary small mb-0">No applications awaiting pre-evaluation.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Evaluation panel -->
    <div class="col-lg-9">
        <?php if ($application): ?>

            <!-- Application header card -->
            <div class="gov-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <h2 class="h5 mb-1"><?= e($application['property_title']) ?></h2>
                        <p class="text-secondary small mb-0">
                            <?= e($application['registry_number']) ?>
                            &middot; <?= e($application['landlord_name']) ?>
                            &middot; <?= e($application['landlord_email']) ?>
                        </p>
                    </div>
                    <span class="status-pill status-<?= e($application['status']) ?>">
                        <?= e(workflow_status_label($application['phase_status'])) ?>
                    </span>
                </div>

                <!-- Progress summary -->
                <div class="row g-3 mt-3">
                    <div class="col-4">
                        <div class="eval-stat-card eval-stat-card--neutral">
                            <span><?= $uploadedDocs ?>/<?= $totalDocs ?></span>
                            <small>Uploaded</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="eval-stat-card eval-stat-card--pass">
                            <span><?= $passedDocs ?></span>
                            <small>Passed</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="eval-stat-card eval-stat-card--fail">
                            <span><?= $failedDocs ?></span>
                            <small>Failed</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Evaluation form -->
            <form class="gov-card p-4" method="post" id="eval-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="h6 mb-0">Document Evaluation</h3>
                    <div class="eval-actions">
                        <button type="button" class="eval-bulk-btn eval-bulk-btn--pass" id="mark-all-pass">
                            Pass All
                        </button>
                        <button type="button" class="eval-bulk-btn eval-bulk-btn--reset" id="mark-all-pending">
                            Reset All
                        </button>
                    </div>
                </div>

                <?php $currentGroup = ''; ?>
                <?php foreach ($documents as $doc): ?>
                    <?php if ($currentGroup !== $doc['group_name']): $currentGroup = $doc['group_name']; ?>
                        <div class="eval-group-header">
                            <span class="upload-office-label">Office:</span>
                            <strong><?= e($currentGroup) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="eval-doc-row" data-eval-row>
                        <!-- Left: document info + preview -->
                        <div>
                            <div class="eval-doc-title"><?= e($doc['title']) ?></div>
                            <div class="eval-doc-meta">
                                <?php $hasFile = !empty($doc['file_data']) || !empty($doc['file_path']); ?>
                                <?php if ($hasFile): ?>
                                    <span class="badge text-bg-success me-1">Uploaded</span>
                                    <button
                                        type="button"
                                        class="preview-link"
                                        data-preview-btn
                                        data-preview-url="<?= e(rtrim($config['app']['base_url'], '/') . '/document_preview.php?id=' . (int)$doc['id']) ?>"
                                        data-preview-type="<?= e($doc['file_mime'] ?? 'application/pdf') ?>"
                                        data-preview-title="<?= e($doc['title']) ?>"
                                        data-preview-meta="<?= e($doc['group_name']) ?>"
                                    >Preview Document</button>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">No file uploaded</span>
                                <?php endif; ?>
                                <?php if ($doc['evaluated_at']): ?>
                                    <span class="text-secondary ms-2" style="font-size:var(--text-xs);">
                                        Last evaluated <?= e(date('M j, Y', strtotime($doc['evaluated_at']))) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="eval-doc-notes">
                                <input
                                    class="form-control form-control-sm"
                                    name="notes_<?= (int)$doc['id'] ?>"
                                    value="<?= e($doc['officer_notes'] ?? '') ?>"
                                    placeholder="Officer notes (optional)"
                                >
                            </div>
                        </div>

                        <!-- Right: status select -->
                        <div class="d-flex align-items-start pt-1">
                            <select
                                class="eval-select <?= $doc['evaluation_status'] === 'PASSED' ? 'status-passed' : ($doc['evaluation_status'] === 'FAILED' ? 'status-failed' : 'status-pending') ?>"
                                name="doc_<?= (int)$doc['id'] ?>"
                                data-eval-select
                            >
                                <option value="PENDING" <?= $doc['evaluation_status'] === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                                <option value="PASSED"  <?= $doc['evaluation_status'] === 'PASSED'  ? 'selected' : '' ?>>Pass</option>
                                <option value="FAILED"  <?= $doc['evaluation_status'] === 'FAILED'  ? 'selected' : '' ?>>Fail</option>
                            </select>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="d-flex gap-3 mt-4 align-items-center flex-wrap">
                    <button class="btn btn-primary" type="submit">Save Evaluation Results</button>
                    <span class="text-secondary small">
                        <?php if ($passedDocs === $totalDocs && $totalDocs > 0): ?>
                            All documents passed - saving will generate an Order of Payment.
                        <?php else: ?>
                            <?= $totalDocs - $passedDocs ?> document(s) not yet passed.
                        <?php endif; ?>
                    </span>
                </div>
            </form>

        <?php else: ?>
            <div class="gov-card p-5">
                <div class="empty-state">
                    <div class="empty-state-icon">DOC</div>
                    <h3>No applications to evaluate</h3>
                    <p>Submitted applications will appear here once landlords complete their document uploads.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Application list items */
.app-list-item {
    display: block;
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    border: 1px solid transparent;
    transition: background var(--transition-fast), border-color var(--transition-fast);
}
.app-list-item:hover {
    background: var(--cpdo-light);
    border-color: rgba(47,128,199,.2);
}
.app-list-item--active {
    background: var(--cpdo-light);
    border-color: var(--cpdo-blue);
}
.app-list-registry {
    display: block;
    font-size: var(--text-xs);
    font-weight: 900;
    color: var(--cpdo-blue);
    letter-spacing: .04em;
}
.app-list-title {
    display: block;
    font-size: var(--text-sm);
    font-weight: 700;
    color: var(--cpdo-deep);
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.app-list-landlord {
    display: block;
    font-size: var(--text-xs);
    color: var(--cpdo-muted);
    margin-top: 1px;
}

/* Eval stat cards */
.eval-stat-card {
    border-radius: var(--radius-md);
    padding: 12px 14px;
    text-align: center;
    border: 1px solid var(--cpdo-border);
}
.eval-stat-card span {
    display: block;
    font-size: 1.6rem;
    font-weight: 900;
    line-height: 1;
}
.eval-stat-card small {
    display: block;
    font-size: var(--text-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-top: 4px;
}
.eval-stat-card--neutral { background: #f4f8fc; color: var(--cpdo-navy); }
.eval-stat-card--pass    { background: #f0fdf4; color: #14532d; border-color: #bbf7d0; }
.eval-stat-card--fail    { background: #fff5f5; color: #7f1d1d; border-color: #fecaca; }

/* Group header */
.eval-group-header {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin: 24px 0 0;
    padding: 8px 12px;
    background: var(--cpdo-light);
    border-radius: var(--radius-sm);
    border-left: 4px solid var(--cpdo-blue);
}
</style>

<script>
(function () {
    // Pass All / Reset All buttons
    var passBtn    = document.getElementById('mark-all-pass');
    var resetBtn   = document.getElementById('mark-all-pending');
    var selects    = document.querySelectorAll('[data-eval-select]');

    function setAll(value) {
        selects.forEach(function (sel) {
            sel.value = value;
            // Trigger change so dlp.js color-syncs
            sel.dispatchEvent(new Event('change'));
        });
    }

    if (passBtn)  passBtn.addEventListener('click',  function () { setAll('PASSED'); });
    if (resetBtn) resetBtn.addEventListener('click', function () { setAll('PENDING'); });
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
