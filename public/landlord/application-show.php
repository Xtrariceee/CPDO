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
$uploadedDocs = count(array_filter($documents, fn($d) => !empty($d['file_path'])));
$missingDocs  = $totalDocs - $uploadedDocs;
$passedDocs   = count(array_filter($documents, fn($d) => $d['evaluation_status'] === 'PASSED'));
$failedDocs   = count(array_filter($documents, fn($d) => $d['evaluation_status'] === 'FAILED'));

$payment = db()->prepare('SELECT * FROM payment_orders WHERE application_id = ? ORDER BY id DESC LIMIT 1');
$payment->execute([$applicationId]);
$paymentOrder = $payment->fetch();

$inspection = db()->prepare('SELECT * FROM inspections WHERE application_id = ? ORDER BY id DESC LIMIT 1');
$inspection->execute([$applicationId]);
$inspectionRow = $inspection->fetch();

$meeting = db()->prepare('SELECT * FROM meetings WHERE application_id = ? ORDER BY id DESC LIMIT 1');
$meeting->execute([$applicationId]);
$meetingRow = $meeting->fetch();

// Determine if landlord can still upload
$canUpload = $user['role'] === ROLE_LANDLORD
    && in_array($application['phase_status'], ['DRAFT', 'SUBMITTED', 'PRE_EVALUATION'], true);

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-start gap-3 mb-4 flex-wrap">
    <a class="btn btn-outline-secondary btn-sm align-self-start" href="dashboard.php">← Dashboard</a>
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
                : ($application['phase_status'] === 'FOR_MEETING' ? '../zoning-officer/consolidation.php' : '../zoning-officer/pre-evaluation.php'),
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
            Your application cannot be submitted until all 19 documents are uploaded.
        </div>
        <a class="btn btn-warning btn-sm text-nowrap" href="requirements-upload.php?id=<?= (int)$applicationId ?>">
            Upload Documents →
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
            Re-upload →
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
                    <dt class="col-5">OP Number</dt>
                    <dd class="col-7"><?= e($paymentOrder['op_number']) ?></dd>
                    <dt class="col-5">Account Code</dt>
                    <dd class="col-7"><?= e($paymentOrder['account_code']) ?></dd>
                    <dt class="col-5">Service Fee</dt>
                    <dd class="col-7"><?= currency_php((float)$paymentOrder['service_fee']) ?></dd>
                    <dt class="col-5">Status</dt>
                    <dd class="col-7">
                        <span class="badge <?= $paymentOrder['status'] === 'PAID' ? 'text-bg-success' : 'text-bg-warning' ?>">
                            <?= e($paymentOrder['status']) ?>
                        </span>
                    </dd>
                </dl>
                <?php if ($user['role'] === ROLE_LANDLORD && $paymentOrder['status'] === 'PENDING'): ?>
                    <a class="btn btn-primary btn-sm mt-3 w-100" href="../paymongo_checkout.php?order=<?= (int)$paymentOrder['id'] ?>">
                        Pay with PayMongo
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
                <dd class="col-7"><?= e($inspectionRow['scheduled_at'] ?? 'Not scheduled') ?></dd>
                <dt class="col-5">Meeting</dt>
                <dd class="col-7"><?= e($meetingRow['scheduled_at'] ?? 'Not scheduled') ?></dd>
            </dl>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
