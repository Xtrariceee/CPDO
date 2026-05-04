<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = require_role([ROLE_ZONING, ROLE_TWG, ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
$stmt = db()->prepare('SELECT a.*, u.email AS landlord_email FROM applications a JOIN users u ON u.id = a.landlord_id WHERE a.id = ?');
$stmt->execute([$applicationId]);
$application = $stmt->fetch();
if (!$application) {
    http_response_code(404);
    exit('Application not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'evaluate' && $user['role'] === ROLE_ZONING) {
        $docs = db()->prepare('SELECT id FROM requirement_documents WHERE application_id = ?');
        $docs->execute([$applicationId]);
        $update = db()->prepare(
            'UPDATE requirement_documents SET evaluation_status = ?, officer_notes = ?, evaluated_by = ?, evaluated_at = NOW() WHERE id = ?'
        );
        foreach ($docs->fetchAll() as $doc) {
            $status = $_POST['doc_' . $doc['id']] ?? 'PENDING';
            $notes = $_POST['notes_' . $doc['id']] ?? null;
            if (in_array($status, ['PASSED', 'FAILED'], true)) {
                $update->execute([$status, $notes, (int)$user['id'], (int)$doc['id']]);
            }
        }
        $failed = db()->prepare('SELECT COUNT(*) FROM requirement_documents WHERE application_id = ? AND evaluation_status != "PASSED"');
        $failed->execute([$applicationId]);
        if ((int)$failed->fetchColumn() === 0) {
            $opNumber = 'OP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $pay = db()->prepare('INSERT INTO payment_orders (application_id, op_number, registry_number) VALUES (?, ?, ?)');
            $pay->execute([$applicationId, $opNumber, $application['registry_number']]);
            advance_application($applicationId, 'PAYMENT_PENDING', 4);
        } else {
            advance_application($applicationId, 'PRE_EVALUATION', 3);
        }
        audit_log((int)$user['id'], 'PRE_EVALUATION_UPDATED', 'applications', $applicationId);
        $_SESSION['flash_success'] = 'Pre-evaluation saved.';
    }

    if ($action === 'verify_payment' && in_array($user['role'], [ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN], true)) {
        $scheduledAt = trim($_POST['scheduled_at'] ?? '');
        $insert = db()->prepare('INSERT INTO inspections (application_id, scheduled_at, assigned_by) VALUES (?, ?, ?)');
        $insert->execute([$applicationId, $scheduledAt ?: null, (int)$user['id']]);
        advance_application($applicationId, 'INSPECTION_SCHEDULED', 7);
        notify_user((int)$application['landlord_id'], $applicationId, 'Inspection scheduled', 'Your CPDO inspection has been scheduled.');
        notify_role(ROLE_TWG, $applicationId, 'New inspection assignment', 'A CPDO inspection is ready for TWG field validation.');
        audit_log((int)$user['id'], 'INSPECTION_SCHEDULED', 'applications', $applicationId, ['scheduled_at' => $scheduledAt]);
        $_SESSION['flash_success'] = 'Payment verified and inspection scheduled. Notifications are queued for landlord and TWG members.';
    }

    if ($action === 'inspection' && $user['role'] === ROLE_TWG) {
        $inspectionId = (int)($_POST['inspection_id'] ?? 0);
        $update = db()->prepare('UPDATE inspections SET findings = ?, site_plan_valid = ?, coordinates_valid = ?, finalized_at = NOW() WHERE id = ? AND application_id = ?');
        $update->execute([
            $_POST['findings'] ?? '',
            !empty($_POST['site_plan_valid']) ? 1 : 0,
            !empty($_POST['coordinates_valid']) ? 1 : 0,
            $inspectionId,
            $applicationId,
        ]);
        advance_application($applicationId, 'FOR_MEETING', 10);
        audit_log((int)$user['id'], 'INSPECTION_FINALIZED', 'applications', $applicationId);
        $_SESSION['flash_success'] = 'Inspection compliance report finalized.';
    }

    if ($action === 'meeting' && in_array($user['role'], [ROLE_ZONING, ROLE_TWG], true)) {
        $meeting = db()->prepare('INSERT INTO meetings (application_id, scheduled_at, minutes, created_by) VALUES (?, ?, ?, ?)');
        $meeting->execute([$applicationId, $_POST['meeting_at'] ?: null, $_POST['minutes'] ?? '', (int)$user['id']]);
        advance_application($applicationId, 'DELIBERATION', 11);
        audit_log((int)$user['id'], 'MEETING_MINUTES_LOGGED', 'applications', $applicationId);
        $_SESSION['flash_success'] = 'Meeting minutes saved.';
    }

    if ($action === 'vote' && $user['role'] === ROLE_TWG) {
        $vote = $_POST['vote'] ?? '';
        if (in_array($vote, ['APPROVED', 'DISAPPROVED', 'DEFERRED'], true)) {
            $stmt = db()->prepare(
                'INSERT INTO votes (application_id, twg_member_id, vote, notes) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE vote = VALUES(vote), notes = VALUES(notes)'
            );
            $stmt->execute([$applicationId, (int)$user['id'], $vote, $_POST['vote_notes'] ?? null]);
            advance_application($applicationId, $vote === 'APPROVED' ? 'DELIBERATION' : $vote, $vote === 'APPROVED' ? 13 : 12);
            notify_user((int)$application['landlord_id'], $applicationId, 'CPDO decision recorded', 'The committee decision has been recorded: ' . $vote . '.');
            audit_log((int)$user['id'], 'TWG_VOTE_CAST', 'applications', $applicationId, ['vote' => $vote]);
            $_SESSION['flash_success'] = 'Decision recorded and landlord notification is queued. Approved applications still require P13-P14 final output upload.';
        }
    }

    if ($action === 'final_output' && in_array($user['role'], [ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN], true)) {
        $signature = secure_upload($_FILES['signature_file'] ?? [], 'final_outputs/' . $applicationId);
        $resolution = secure_upload($_FILES['resolution_file'] ?? [], 'final_outputs/' . $applicationId);
        $insert = db()->prepare(
            'INSERT INTO final_outputs (application_id, signature_file_path, resolution_file_path, endorsement_number, uploaded_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$applicationId, $signature, $resolution, $_POST['endorsement_number'] ?? null, (int)$user['id']]);
        advance_application($applicationId, 'APPROVED', 14);
        audit_log((int)$user['id'], 'FINAL_OUTPUT_UPLOADED', 'applications', $applicationId);
        $_SESSION['flash_success'] = 'Official endorsement/resolution uploaded. Application is approved.';
    }

    redirect('workflow_action.php?id=' . $applicationId);
}

$docs = db()->prepare('SELECT * FROM requirement_documents WHERE application_id = ? ORDER BY group_name, id');
$docs->execute([$applicationId]);
$documents = $docs->fetchAll();

$payment = db()->prepare('SELECT * FROM payment_orders WHERE application_id = ? ORDER BY id DESC LIMIT 1');
$payment->execute([$applicationId]);
$paymentOrder = $payment->fetch();

$inspection = db()->prepare('SELECT * FROM inspections WHERE application_id = ? ORDER BY id DESC LIMIT 1');
$inspection->execute([$applicationId]);
$inspectionRow = $inspection->fetch();

require __DIR__ . '/partials/header.php';
?>
<h1 class="h3 mb-1">Workflow Action</h1>
<p class="text-secondary">Registry <?= e($application['registry_number']) ?> · P<?= (int)$application['current_process'] ?> · <?= e($application['phase_status']) ?></p>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-4">
            <?php for ($i = 1; $i <= 14; $i++): ?>
                <div class="workflow-step <?= (int)$application['current_process'] >= $i ? 'active' : '' ?>">
                    <strong>P<?= $i ?></strong>
                    <div class="small text-secondary"><?= e([
                        1 => 'Requirements Display', 2 => 'Submission', 3 => 'Pre-Evaluation', 4 => 'Order of Payment',
                        5 => 'Payment Integration', 6 => 'Payment Verification', 7 => 'Inspection Scheduling',
                        8 => 'Field Inspection', 9 => 'Site Plan Validation', 10 => 'Consolidation',
                        11 => 'Meeting', 12 => 'Decision', 13 => 'Committee Signatures', 14 => 'Endorsement / Resolution',
                    ][$i]) ?></div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <div class="col-lg-8 d-grid gap-4">
        <?php if ($user['role'] === ROLE_ZONING): ?>
            <form class="gov-card p-4" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">
                <input type="hidden" name="action" value="evaluate">
                <h2 class="h5">P3: Pre-Evaluation</h2>
                <?php foreach ($documents as $doc): ?>
                    <div class="border-top py-3">
                        <div class="fw-semibold"><?= e($doc['title']) ?></div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <select class="form-select" name="doc_<?= (int)$doc['id'] ?>">
                                    <option value="PENDING" <?= $doc['evaluation_status'] === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                                    <option value="PASSED" <?= $doc['evaluation_status'] === 'PASSED' ? 'selected' : '' ?>>Pass</option>
                                    <option value="FAILED" <?= $doc['evaluation_status'] === 'FAILED' ? 'selected' : '' ?>>Fail</option>
                                </select>
                            </div>
                            <div class="col-md-8"><input class="form-control" name="notes_<?= (int)$doc['id'] ?>" placeholder="Officer notes" value="<?= e($doc['officer_notes']) ?>"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <button class="btn btn-primary mt-3">Save Evaluation</button>
            </form>
        <?php endif; ?>

        <?php if ($paymentOrder && $paymentOrder['status'] === 'PAID' && in_array($user['role'], [ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN], true)): ?>
            <form class="gov-card p-4" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">
                <input type="hidden" name="action" value="verify_payment">
                <h2 class="h5">P6-P7: Verify Payment and Schedule Inspection</h2>
                <input class="form-control mb-3" type="datetime-local" name="scheduled_at">
                <button class="btn btn-primary">Schedule Inspection</button>
            </form>
        <?php endif; ?>

        <?php if ($inspectionRow && $user['role'] === ROLE_TWG): ?>
            <form class="gov-card p-4" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">
                <input type="hidden" name="inspection_id" value="<?= (int)$inspectionRow['id'] ?>">
                <input type="hidden" name="action" value="inspection">
                <h2 class="h5">P8-P10: Inspection Compliance Report</h2>
                <textarea class="form-control mb-3" name="findings" rows="5" placeholder="Findings"><?= e($inspectionRow['findings']) ?></textarea>
                <label class="form-check"><input class="form-check-input" type="checkbox" name="site_plan_valid" <?= $inspectionRow['site_plan_valid'] ? 'checked' : '' ?>> Site plans validated</label>
                <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="coordinates_valid" <?= $inspectionRow['coordinates_valid'] ? 'checked' : '' ?>> Coordinates validated</label>
                <button class="btn btn-primary">Finalize Report</button>
            </form>
        <?php endif; ?>

        <form class="gov-card p-4" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">
            <input type="hidden" name="action" value="meeting">
            <h2 class="h5">P11: Formal Meeting and Minutes</h2>
            <input class="form-control mb-3" type="datetime-local" name="meeting_at">
            <textarea class="form-control mb-3" name="minutes" rows="4" placeholder="Meeting minutes and landlord presentation notes"></textarea>
            <button class="btn btn-outline-primary">Save Meeting Minutes</button>
        </form>

        <?php if ($user['role'] === ROLE_TWG): ?>
            <form class="gov-card p-4" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">
                <input type="hidden" name="action" value="vote">
                <h2 class="h5">P12: Decision</h2>
                <select class="form-select mb-3" name="vote">
                    <option value="APPROVED">Approved</option>
                    <option value="DISAPPROVED">Disapproved</option>
                    <option value="DEFERRED">Deferred</option>
                </select>
                <textarea class="form-control mb-3" name="vote_notes" rows="3" placeholder="Decision notes"></textarea>
                <button class="btn btn-primary">Record Vote</button>
            </form>
        <?php endif; ?>

        <?php if (in_array($user['role'], [ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN], true)): ?>
            <form class="gov-card p-4" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$applicationId ?>">
                <input type="hidden" name="action" value="final_output">
                <h2 class="h5">P13-P14: Signatures and Official Output</h2>
                <input class="form-control mb-3" name="endorsement_number" placeholder="Endorsement / Resolution Number">
                <label class="form-label">Committee Signatures</label>
                <input class="form-control mb-3" type="file" name="signature_file" accept=".pdf,.jpg,.jpeg,.png">
                <label class="form-label">Official Endorsement / Resolution</label>
                <input class="form-control mb-3" type="file" name="resolution_file" accept=".pdf,.jpg,.jpeg,.png">
                <button class="btn btn-success">Approve and Upload Final Output</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
