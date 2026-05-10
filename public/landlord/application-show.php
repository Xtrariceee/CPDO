<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_login();
$applicationId = (int)($_GET['id'] ?? 0);

$sql = 'SELECT a.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name
        FROM applications a
        JOIN users u ON u.id = a.landlord_id
        WHERE a.id = ?';
$params = [$applicationId];
if ($user['role'] === ROLE_LANDLORD) {
    $sql .= ' AND a.landlord_id = ?';
    $params[] = (int)$user['id'];
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$application = $stmt->fetch();
if (!$application) {
    http_response_code(404);
    exit('Application not found.');
}

$docs = db()->prepare('SELECT * FROM requirement_documents WHERE application_id = ? ORDER BY id');
$docs->execute([$applicationId]);
$documents = $docs->fetchAll();

$totalDocs    = count($documents);
$uploadedDocs = count(array_filter($documents, fn($d) => !empty($d['file_data']) || !empty($d['file_path'])));
$missingDocs  = $totalDocs - $uploadedDocs;
$passedDocs   = count(array_filter($documents, fn($d) => $d['evaluation_status'] === 'PASSED'));
$failedDocs   = count(array_filter($documents, fn($d) => $d['evaluation_status'] === 'FAILED'));

$payment = db()->prepare('SELECT * FROM payment_orders WHERE application_id = ? ORDER BY id DESC LIMIT 1');
$payment->execute([$applicationId]);
$paymentOrder = $payment->fetch();

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

// Determine if landlord can still upload
$canUpload = $user['role'] === ROLE_LANDLORD
    && in_array($application['phase_status'], ['DRAFT', 'SUBMITTED', 'PRE_EVALUATION'], true);

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-start gap-3 mb-4 flex-wrap">
    <a class="btn btn-back btn-sm align-self-start" href="dashboard.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
    <div class="flex-grow-1">
        <h1 class="h3 mb-1"><?= e($application['property_title']) ?></h1>
        <p class="text-secondary mb-0">
            Registry <?= e($application['registry_number']) ?>
            &middot; Process P<?= (int)$application['current_process'] ?>
            &middot; <span class="status-pill status-<?= e($application['status']) ?>"><?= e(workflow_status_label($application['phase_status'])) ?></span>
        </p>
    </div>
    <?php if (in_array($user['role'], [ROLE_ZONING, ROLE_TWG, ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN], true)): ?>
        <?php
        $actionPath = match ($user['role']) {
            ROLE_ZONING => in_array($application['phase_status'], ['PAID'], true)
                ? '../zoning-officer/payment-scheduling.php'
                : ($application['phase_status'] === 'FOR_MEETING' ? '../workflow_action.php' : '../zoning-officer/pre-evaluation.php'),
            ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN => in_array($application['phase_status'], ['DELIBERATION'], true)
                ? '../admin-officer/final-output.php'
                : '../admin-officer/order-payment.php',
            ROLE_TWG => $application['phase_status'] === 'INSPECTION_SCHEDULED'
                ? '../twg/inspection.php'
                : ($application['phase_status'] === 'FOR_MEETING' ? '../twg/meeting.php' : '../twg/voting.php'),
            default => '../workflow_action.php',
        };
        ?>
        <a class="btn btn-primary align-self-start" href="<?= e($actionPath) ?>?id=<?= (int)$applicationId ?>">Process Action</a>
    <?php endif; ?>
</div>

<!-- Missing documents alert for landlord -->
<?php if ($canUpload && $missingDocs > 0): ?>
    <div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 mb-4">
        <div>
            <strong><?= $missingDocs ?> document<?= $missingDocs > 1 ? 's' : '' ?> still missing.</strong>
            Your application cannot be submitted until all <?= $totalDocs ?> documents are uploaded.
        </div>
        <a class="btn btn-warning btn-sm text-nowrap" href="requirements-upload.php?id=<?= (int)$applicationId ?>">
            Upload Documents
        </a>
    </div>
<?php endif; ?>

<!-- Failed documents alert -->
<?php if ($failedDocs > 0 && $canUpload): ?>
    <div class="alert alert-danger d-flex align-items-center justify-content-between gap-3 mb-4">
        <div>
            <strong><?= $failedDocs ?> document<?= $failedDocs > 1 ? 's' : '' ?> failed evaluation.</strong>
            Please re-upload the flagged documents.
        </div>
        <a class="btn btn-danger btn-sm text-nowrap" href="requirements-upload.php?id=<?= (int)$applicationId ?>">
            Re-upload
        </a>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Documents table -->
    <div class="col-lg-8">
        <section class="gov-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">Requirement Documents</h2>
                <?php if ($canUpload): ?>
                    <a class="btn btn-outline-primary btn-sm" href="requirements-upload.php?id=<?= (int)$applicationId ?>">
                        <?= $missingDocs > 0 ? 'Upload Missing' : 'Manage Files' ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Mini progress bar -->
            <div class="mb-3">
                <div class="d-flex justify-content-between mb-1 small text-secondary">
                    <span><?= $uploadedDocs ?>/<?= $totalDocs ?> uploaded</span>
                    <span><?= $passedDocs ?> passed · <?= $failedDocs ?> failed</span>
                </div>
                <div class="progress" style="height:6px;border-radius:99px;">
                    <div class="progress-bar bg-success" style="width:<?= $totalDocs > 0 ? round($uploadedDocs/$totalDocs*100) : 0 ?>%"></div>
                </div>
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
                                <?php
                                $evalClass = match ($doc['evaluation_status']) {
                                    'PASSED' => 'text-bg-success',
                                    'FAILED' => 'text-bg-danger',
                                    default  => 'text-bg-secondary',
                                };
                                ?>
                                <span class="badge <?= $evalClass ?>"><?= e($doc['evaluation_status']) ?></span>
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
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <!-- Payment -->
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
                    <dt class="col-5">Account Code</dt>
                    <dd class="col-7"><?= e($paymentOrder['account_code']) ?></dd>
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
                <?php if ($user['role'] === ROLE_LANDLORD && $paymentOrder['status'] === 'PENDING'): ?>
                    <a class="btn btn-primary btn-sm mt-3 w-100" href="../paymongo_checkout.php?order=<?= (int)$paymentOrder['id'] ?>">
                        Proceed with payment
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-secondary small mb-0">Order of Payment will be generated after pre-evaluation passes.</p>
            <?php endif; ?>
        </section>

        <!-- Inspection & Meeting -->
        <section class="gov-card p-4">
            <h2 class="h5 mb-3">Inspection &amp; Meeting</h2>
            <dl class="row mb-0 small">
                <dt class="col-5">Inspection</dt>
                <dd class="col-7"><?= e($inspectionDisplay) ?></dd>
                <dt class="col-5">Meeting</dt>
                <dd class="col-7"><?= e($meetingRow['scheduled_at'] ?? 'Not scheduled') ?></dd>
            </dl>
        </section>

        <!-- Resolution / Endorsement (shown when APPROVED) -->
        <?php if ($application['phase_status'] === 'APPROVED'): ?>
            <?php
            $finalOutput = db()->prepare('SELECT * FROM final_outputs WHERE application_id = ? ORDER BY id DESC LIMIT 1');
            $finalOutput->execute([$applicationId]);
            $finalRow = $finalOutput->fetch();
            ?>
            <section class="gov-card p-4 mt-4" style="border-left:4px solid #157347;">
                <h2 class="h5 mb-2" style="color:#157347;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="me-1" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z"/></svg>
                    Application Approved
                </h2>
                <?php if ($finalRow): ?>
                    <p class="small text-secondary mb-2">
                        Endorsement No.: <strong><?= e($finalRow['endorsement_number'] ?? 'N/A') ?></strong><br>
                        Issued: <?= e($finalRow['uploaded_at'] ? date('F j, Y', strtotime($finalRow['uploaded_at'])) : '—') ?>
                    </p>
                    <?php if (!empty($finalRow['resolution_file_path'])): ?>
                        <a class="btn btn-success btn-sm w-100 mb-2"
                           href="<?= e(rtrim($config['app']['base_url'], '/') . '/document_preview.php?type=final_output&id=' . (int)$finalRow['id']) ?>"
                           target="_blank" rel="noopener">
                            View Endorsement / Resolution
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="small text-secondary mb-0">The endorsement document will appear here once issued by the Administrative Officer.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
