<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);

$applicationId = (int)($_GET['id'] ?? 0);
if (!$applicationId) {
    redirect('zoning-officer/index.php');
}

$application = officer_application($applicationId);

$stmt = db()->prepare('SELECT * FROM requirement_documents WHERE application_id = ? ORDER BY id');
$stmt->execute([$applicationId]);
$documents = $stmt->fetchAll();

$totalDocs    = count($documents);
$uploadedDocs = count(array_filter($documents, fn($d) => !empty($d['file_data']) || !empty($d['file_path'])));

$paymentOrder = payment_order_for_application($applicationId);

$inspection = db()->prepare('SELECT * FROM inspections WHERE application_id = ? ORDER BY id DESC LIMIT 1');
$inspection->execute([$applicationId]);
$inspectionRow = $inspection->fetch();
$inspectionDisplay = 'Not scheduled';
if ($inspectionRow) {
    $inspectionDisplay = !empty($inspectionRow['scheduled_date']) && !empty($inspectionRow['scheduled_time'])
        ? $inspectionRow['scheduled_date'] . ' ' . substr((string)$inspectionRow['scheduled_time'], 0, 5)
        : ($inspectionRow['scheduled_at'] ?? 'Not scheduled');
}

$meeting = db()->prepare('SELECT * FROM meetings WHERE application_id = ? ORDER BY id DESC LIMIT 1');
$meeting->execute([$applicationId]);
$meetingRow = $meeting->fetch();

// Determine the appropriate action page for this application's current status
$actionPath = match (true) {
    in_array($application['phase_status'], ['SUBMITTED', 'PRE_EVALUATION'], true) => 'pre-evaluation.php',
    $application['phase_status'] === 'PAID'                                        => 'payment-scheduling.php',
    default                                                                         => null,
};

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <a class="btn btn-back btn-sm" href="index.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
    <div>
        <p class="eyebrow mb-0">Application Details</p>
        <h1 class="h3 mb-0"><?= e($application['property_title']) ?></h1>
    </div>
    <?php if ($actionPath): ?>
        <a class="btn btn-primary btn-sm ms-auto" href="<?= e($actionPath) ?>?id=<?= (int)$applicationId ?>">
            Process Action
        </a>
    <?php endif; ?>
</div>

<!-- Status bar -->
<div class="gov-card p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div>
            <span class="eyebrow d-block mb-0" style="font-size:.65rem;">Registry Number</span>
            <strong><?= e($application['registry_number']) ?></strong>
        </div>
        <div>
            <span class="eyebrow d-block mb-0" style="font-size:.65rem;">Landlord</span>
            <strong><?= e($application['landlord_name']) ?></strong>
        </div>
        <div>
            <span class="eyebrow d-block mb-0" style="font-size:.65rem;">Process</span>
            <strong>P<?= (int)$application['current_process'] ?></strong>
        </div>
    </div>
    <span class="status-pill status-<?= e($application['status']) ?>">
        <?= e(workflow_status_label($application['phase_status'])) ?>
    </span>
</div>

<div class="row g-4">
    <!-- Left: documents -->
    <div class="col-lg-8">
        <section class="gov-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">Requirement Documents</h2>
                <span class="text-secondary small"><?= $uploadedDocs ?>/<?= $totalDocs ?> uploaded</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Document</th>
                            <th>Office</th>
                            <th>File</th>
                            <th>Evaluation</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $n = 0; foreach ($documents as $doc): $n++; ?>
                        <tr class="<?= $doc['evaluation_status'] === 'FAILED' ? 'table-danger' : ($doc['evaluation_status'] === 'PASSED' ? 'table-success' : '') ?>">
                            <td class="text-secondary"><?= $n ?></td>
                            <td><?= e($doc['title']) ?></td>
                            <td class="small text-secondary"><?= e($doc['group_name']) ?></td>
                            <td>
                                <?php $hasFile = !empty($doc['file_data']) || !empty($doc['file_path']); ?>
                                <?php if ($hasFile): ?>
                                    <span class="badge text-bg-success">Uploaded</span>
                                    <button
                                        type="button"
                                        class="preview-link ms-1"
                                        data-preview-btn
                                        data-preview-url="<?= e(rtrim($config['app']['base_url'], '/') . '/document_preview.php?id=' . (int)$doc['id']) ?>"
                                        data-preview-type="<?= e($doc['file_mime'] ?? 'application/pdf') ?>"
                                        data-preview-title="<?= e($doc['title']) ?>"
                                        data-preview-meta="<?= e($doc['group_name']) ?>"
                                    >Preview</button>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">Missing</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $doc['evaluation_status'] === 'PASSED' ? 'text-bg-success' : ($doc['evaluation_status'] === 'FAILED' ? 'text-bg-danger' : 'text-bg-secondary') ?>">
                                    <?= e($doc['evaluation_status']) ?>
                                </span>
                                <?php if ($doc['officer_notes']): ?>
                                    <div class="small text-secondary mt-1"><?= e($doc['officer_notes']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Application details -->
        <section class="gov-card p-4">
            <h2 class="h5 mb-3">Application Details</h2>
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
            <div class="row g-3">
                <div class="col-sm-6">
                    <p class="av-label">Account Name</p>
                    <p class="av-value"><?= e($application['account_name']) ?></p>
                </div>
                <div class="col-sm-6">
                    <p class="av-label">Address</p>
                    <p class="av-value"><?= nl2br(e($application['account_address'])) ?></p>
                </div>
                <?php if ($application['corporation_name']): ?>
                    <div class="col-sm-6">
                        <p class="av-label">Corporation</p>
                        <p class="av-value"><?= e($application['corporation_name']) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($application['representative_name']): ?>
                    <div class="col-sm-6">
                        <p class="av-label">Representative</p>
                        <p class="av-value"><?= e($application['representative_name']) ?></p>
                    </div>
                <?php endif; ?>
                <div class="col-sm-6">
                    <p class="av-label">Type of Project</p>
                    <p class="av-value"><?= e($application['type_of_project'] ?? 'N/A') ?></p>
                </div>
                <div class="col-sm-6">
                    <p class="av-label">Property Address</p>
                    <p class="av-value"><?= nl2br(e($application['property_address'])) ?></p>
                </div>
                <?php if ($application['coordinates']): ?>
                    <div class="col-sm-6">
                        <p class="av-label">Coordinates</p>
                        <p class="av-value"><?= e($application['coordinates']) ?></p>
                    </div>
                <?php endif; ?>
                <div class="col-sm-4">
                    <p class="av-label">Lot Area (sqm)</p>
                    <p class="av-value"><?= $application['lot_area'] ? number_format((float)$application['lot_area'], 2) : 'N/A' ?></p>
                </div>
                <div class="col-sm-4">
                    <p class="av-label">Building Area (sqm)</p>
                    <p class="av-value"><?= $application['building_area'] ? number_format((float)$application['building_area'], 2) : 'N/A' ?></p>
                </div>
                <div class="col-sm-4">
                    <p class="av-label">Project Cost (PHP)</p>
                    <p class="av-value"><?= $application['project_cost'] ? number_format((float)$application['project_cost'], 2) : 'N/A' ?></p>
                </div>
                <div class="col-sm-4">
                    <p class="av-label">Nature of Application</p>
                    <p class="av-value"><?= e($noaLabel) ?></p>
                </div>
                <div class="col-sm-4">
                    <p class="av-label">Right over Land</p>
                    <p class="av-value"><?= e($rolLabel) ?></p>
                </div>
                <div class="col-sm-4">
                    <p class="av-label">Existing Land Use</p>
                    <p class="av-value"><?= e($eluLabel) ?></p>
                </div>
                <?php if ($application['vicinity_map_pdf_path']): ?>
                    <div class="col-12">
                        <p class="av-label">Vicinity Map</p>
                        <p class="av-value">
                            <a
                                href="<?= e(rtrim($config['app']['base_url'], '/') . '/vicinity_map_preview.php?id=' . (int)$applicationId) ?>"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-sm btn-outline-primary"
                            >
                                View Generated PDF
                            </a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Right: payment + inspection/meeting -->
    <div class="col-lg-4">
        <section class="gov-card p-4 mb-4" data-sensitive>
            <h2 class="h5 mb-3">Payment</h2>
            <?php if ($paymentOrder): ?>
                <dl class="row mb-0 small">
                    <dt class="col-5">Account Name</dt>
                    <dd class="col-7"><?= e($paymentOrder['account_name'] ?? $application['account_name']) ?></dd>
                    <dt class="col-5">Paying For</dt>
                    <dd class="col-7"><?= e($paymentOrder['payment_for'] ?? default_payment_for($application)) ?></dd>
                    <dt class="col-5">Date</dt>
                    <dd class="col-7"><?= e($paymentOrder['paid_at'] ?? $paymentOrder['created_at'] ?? 'Pending') ?></dd>
                    <dt class="col-5">OP Number</dt>
                    <dd class="col-7"><?= e($paymentOrder['op_number']) ?></dd>
                    <dt class="col-5">Fee / Amount</dt>
                    <dd class="col-7"><?= currency_php((float)$paymentOrder['service_fee']) ?></dd>
                    <dt class="col-5">Method</dt>
                    <dd class="col-7"><?= e($paymentOrder['payment_method'] ?? ($paymentOrder['status'] === 'PAID' ? 'PayMongo' : 'Pending')) ?></dd>
                    <dt class="col-5">Status</dt>
                    <dd class="col-7">
                        <span class="badge <?= $paymentOrder['status'] === 'PAID' ? 'text-bg-success' : 'text-bg-warning' ?>">
                            <?= e($paymentOrder['status']) ?>
                        </span>
                    </dd>
                </dl>
            <?php else: ?>
                <p class="text-secondary small mb-0">Order of Payment generated after pre-evaluation passes.</p>
            <?php endif; ?>
        </section>

        <section class="gov-card p-4">
            <h2 class="h5 mb-3">Inspection &amp; Meeting</h2>
            <dl class="row mb-0 small">
                <dt class="col-5">Inspection</dt>
                <dd class="col-7"><?= e($inspectionDisplay) ?></dd>
                <dt class="col-5">Meeting</dt>
                <dd class="col-7"><?= e($meetingRow['scheduled_at'] ?? 'Not scheduled') ?></dd>
            </dl>

            <?php if ($inspectionRow && !empty($inspectionRow['findings'])): ?>
                <hr class="my-3">
                <h3 class="h6 mb-2">Inspection Report</h3>
                <details>
                    <summary class="preview-link" style="cursor:pointer;display:inline-flex;">View Report</summary>
                    <pre style="margin-top:10px;padding:12px;background:#f4f8fc;border:1px solid #d0dae6;border-radius:8px;font-size:.75rem;white-space:pre-wrap;word-break:break-word;color:#0b2a4a;max-height:320px;overflow-y:auto;"><?= e($inspectionRow['findings']) ?></pre>
                </details>

                <?php
                // Show inspection photos if any
                $photoStmt = db()->prepare(
                    'SELECT id, caption, category FROM inspection_photos WHERE inspection_id = ? ORDER BY uploaded_at ASC'
                );
                $photoStmt->execute([(int)$inspectionRow['id']]);
                $inspPhotos = $photoStmt->fetchAll();
                if ($inspPhotos):
                    $cpdoUrl = rtrim($config['app']['cpdo_url'] ?? '', '/');
                ?>
                    <h3 class="h6 mb-2 mt-3">Site Photos (<?= count($inspPhotos) ?>)</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px;">
                        <?php foreach ($inspPhotos as $ph): ?>
                            <a href="<?= e($cpdoUrl) ?>/twg/inspection_photo.php?id=<?= (int)$ph['id'] ?>" target="_blank" rel="noopener"
                               style="display:block;border-radius:8px;overflow:hidden;border:1px solid #d0dae6;aspect-ratio:4/3;background:#eef2f7;">
                                <img src="<?= e($cpdoUrl) ?>/twg/inspection_photo.php?id=<?= (int)$ph['id'] ?>"
                                     alt="<?= e($ph['caption'] ?: ucwords(str_replace('_', ' ', $ph['category']))) ?>"
                                     loading="lazy"
                                     style="width:100%;height:100%;object-fit:cover;display:block;">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($meetingRow && !empty($meetingRow['minutes'])): ?>
                <hr class="my-3">
                <h3 class="h6 mb-2">Meeting Minutes</h3>
                <details>
                    <summary class="preview-link" style="cursor:pointer;display:inline-flex;">View Minutes</summary>
                    <pre style="margin-top:10px;padding:12px;background:#f4f8fc;border:1px solid #d0dae6;border-radius:8px;font-size:.75rem;white-space:pre-wrap;word-break:break-word;color:#0b2a4a;max-height:320px;overflow-y:auto;"><?= e($meetingRow['minutes']) ?></pre>
                </details>
                <a href="<?= e(rtrim($config['app']['cpdo_url'] ?? '', '/')) ?>/twg/meeting.php?id=<?= (int)$applicationId ?>&download_minutes_pdf=1"
                   target="_blank" rel="noopener"
                   class="preview-link mt-2" style="display:inline-flex;">
                    Download Minutes PDF
                </a>
            <?php endif; ?>

            <?php if (in_array($application['phase_status'], ['DELIBERATION', 'APPROVED'], true)): ?>
                <hr class="my-3">
                <h3 class="h6 mb-2">Legislative Resolution</h3>
                <a href="resolution.php?id=<?= (int)$applicationId ?>"
                   class="preview-link" style="display:inline-flex;">
                    Generate / Download Resolution PDF
                </a>
            <?php endif; ?>
        </section>
    </div>
</div>

<style>
.av-label {
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--cpdo-muted, #62748a);
    margin-bottom: 2px;
}
.av-value {
    font-size: .88rem;
    color: var(--cpdo-deep, #0b2a4a);
    font-weight: 600;
    margin-bottom: 0;
}
.preview-link {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 6px;
    border: 1px solid #1d6aad;
    background: #f0f6ff;
    color: #1d6aad;
    font-size: .75rem;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    appearance: none;
    transition: background .15s, color .15s;
}
.preview-link:hover {
    background: #1d6aad;
    color: #fff;
}
</style>

<?php require __DIR__ . '/../partials/footer.php'; ?>
