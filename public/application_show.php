<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = require_login();
$id = (int)($_GET['id'] ?? 0);
// Officers stay on the same page; landlords go to the new folder
if ($user['role'] === ROLE_LANDLORD) {
    redirect('landlord/application-show.php' . ($id ? '?id=' . $id : ''));
}
// For officers, include the full view inline
require_once __DIR__ . '/../app/bootstrap.php';

$applicationId = $id;
$sql = 'SELECT a.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name FROM applications a JOIN users u ON u.id = a.landlord_id WHERE a.id = ?';
$stmt = db()->prepare($sql);
$stmt->execute([$applicationId]);
$application = $stmt->fetch();
if (!$application) { http_response_code(404); exit('Application not found.'); }

$docs = db()->prepare('SELECT * FROM requirement_documents WHERE application_id = ? ORDER BY id');
$docs->execute([$applicationId]);
$documents = $docs->fetchAll();

$totalDocs    = count($documents);
$uploadedDocs = count(array_filter($documents, fn($d) => !empty($d['file_data']) || !empty($d['file_path'])));

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

require __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between gap-3 mb-4 flex-wrap">
    <div>
        <h1 class="h3 mb-1"><?= e($application['property_title']) ?></h1>
        <p class="text-secondary mb-0">
            Registry <?= e($application['registry_number']) ?>
            &middot; P<?= (int)$application['current_process'] ?>
            &middot; <span class="status-pill status-<?= e($application['status']) ?>"><?= e(workflow_status_label($application['phase_status'])) ?></span>
        </p>
    </div>
    <?php
    $actionPath = match ($user['role']) {
        ROLE_ZONING => in_array($application['phase_status'], ['PAID'], true)
            ? 'zoning-officer/payment-scheduling.php'
            : ($application['phase_status'] === 'FOR_MEETING' ? 'workflow_action.php' : 'zoning-officer/pre-evaluation.php'),
        ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN => in_array($application['phase_status'], ['DELIBERATION'], true)
            ? 'admin-officer/final-output.php'
            : 'admin-officer/order-payment.php',
        ROLE_TWG => $application['phase_status'] === 'INSPECTION_SCHEDULED'
            ? 'twg/inspection.php'
            : ($application['phase_status'] === 'FOR_MEETING' ? 'twg/meeting.php' : 'twg/voting.php'),
        default => 'workflow_action.php',
    };
    ?>
    <a class="btn btn-primary align-self-start" href="<?= e($actionPath) ?>?id=<?= (int)$applicationId ?>">Process Action</a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <section class="gov-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">Requirement Documents</h2>
                <span class="text-secondary small"><?= $uploadedDocs ?>/<?= $totalDocs ?> uploaded</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>#</th><th>Document</th><th>Office</th><th>File</th><th>Evaluation</th></tr></thead>
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
    </div>
    <div class="col-lg-4">
        <section class="gov-card p-4 mb-4" data-sensitive>
            <h2 class="h5 mb-3">Payment</h2>
            <?php if ($paymentOrder): ?>
                <dl class="row mb-0 small">
                    <dt class="col-5">Account Name</dt><dd class="col-7"><?= e($paymentOrder['account_name'] ?? $application['account_name']) ?></dd>
                    <dt class="col-5">Paying For</dt><dd class="col-7"><?= e($paymentOrder['payment_for'] ?? default_payment_for($application)) ?></dd>
                    <dt class="col-5">Date</dt><dd class="col-7"><?= e($paymentOrder['paid_at'] ?? $paymentOrder['created_at'] ?? 'Pending') ?></dd>
                    <dt class="col-5">OP Number</dt><dd class="col-7"><?= e($paymentOrder['op_number']) ?></dd>
                    <dt class="col-5">Fee / Amount</dt><dd class="col-7"><?= currency_php((float)$paymentOrder['service_fee']) ?></dd>
                    <dt class="col-5">Method</dt><dd class="col-7"><?= e($paymentOrder['payment_method'] ?? ($paymentOrder['status'] === 'PAID' ? 'PayMongo' : 'Pending')) ?></dd>
                    <dt class="col-5">Status</dt>
                    <dd class="col-7"><span class="badge <?= $paymentOrder['status'] === 'PAID' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= e($paymentOrder['status']) ?></span></dd>
                </dl>
            <?php else: ?>
                <p class="text-secondary small mb-0">Order of Payment generated after pre-evaluation passes.</p>
            <?php endif; ?>
        </section>
        <section class="gov-card p-4">
            <h2 class="h5 mb-3">Inspection &amp; Meeting</h2>
            <dl class="row mb-0 small">
                <dt class="col-5">Inspection</dt><dd class="col-7"><?= e($inspectionDisplay) ?></dd>
                <dt class="col-5">Meeting</dt><dd class="col-7"><?= e($meetingRow['scheduled_at'] ?? 'Not scheduled') ?></dd>
            </dl>
        </section>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
