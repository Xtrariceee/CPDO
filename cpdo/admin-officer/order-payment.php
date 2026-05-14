<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application = officer_application($applicationId);
    if (!requirements_all_passed($applicationId)) {
        $_SESSION['flash_error'] = 'Order of Payment can only be generated after all P3 requirements pass evaluation.';
        redirect('admin-officer/order-payment.php?id=' . $applicationId);
    }

    // Check if an order already exists so we know whether this is a regeneration
    $existingOrder = payment_order_for_application($applicationId);
    $order         = create_payment_order_if_missing($application, (int)$user['id']);

    // If the order already existed, create_payment_order_if_missing returns the
    // existing one without logging. Log a regeneration event explicitly.
    if ($existingOrder) {
        audit_log(
            (int)$user['id'],
            'ORDER_OF_PAYMENT_GENERATED',
            'payment_orders',
            (int)$order['id'],
            ['op_number' => $order['op_number'], 'action' => 'regenerated']
        );
    }

    notify_user(
        (int)$application['landlord_id'],
        $applicationId,
        'Order of Payment Issued',
        'An Order of Payment (OP No. ' . $order['op_number'] . ') has been issued for your application. Please proceed to payment.'
    );
    $_SESSION['flash_success'] = 'Order of Payment generated: ' . $order['op_number'];
    redirect('admin-officer/order-payment.php?id=' . $applicationId);
}

$applications = officer_applications(['SUBMITTED', 'PRE_EVALUATION', 'PAYMENT_PENDING']);
$application  = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
// Only show the slip after it has been generated (blank until then)
$order        = ($application && $applicationId) ? payment_order_for_application((int)$application['id']) : null;

// Audit log of all OP generation events for this application
$opLogs = [];
if ($application) {
    $logStmt = db()->prepare(
        "SELECT al.created_at, al.details,
                CONCAT_WS(' ', u.first_name, u.last_name) AS generated_by,
                po.op_number AS order_op_number,
                po.account_name AS order_account_name
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN payment_orders po ON po.id = al.entity_id AND al.entity_type = 'payment_orders'
         WHERE al.action = 'ORDER_OF_PAYMENT_GENERATED'
           AND al.entity_type = 'payment_orders'
           AND al.entity_id IN (
               SELECT id FROM payment_orders WHERE application_id = ?
           )
         ORDER BY al.created_at DESC"
    );
    $logStmt->execute([(int)$application['id']]);
    $opLogs = $logStmt->fetchAll();
}

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">Order of Payment Generator</h1>
<p class="text-secondary mb-3">Generate the Order of Payment (OP) for applications where all pre-evaluation requirements have passed. This is a Zoning Officer function.</p>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">Applications</h2>
            <div class="list-group">
                <?php foreach ($applications as $row): ?>
                    <a class="list-group-item list-group-item-action <?= $application && (int)$application['id']===(int)$row['id']?'active':'' ?>"
                       href="order-payment.php?id=<?= (int)$row['id'] ?>">
                        <?= e($row['registry_number']) ?><br>
                        <small><?= e($row['property_title']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$applications): ?>
                    <div class="text-secondary small p-2">No applications available.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <?php if ($application): ?>
            <!-- Digital Payment Slip — blank until generated -->
            <section class="gov-card p-4 mb-4" data-sensitive>
                <h2 class="h5 mb-3">Digital Payment Slip</h2>

                <?php if ($order): ?>
                    <dl class="row mb-3">
                        <dt class="col-sm-4">OP Number</dt>
                        <dd class="col-sm-8 fw-bold text-primary"><?= e($order['op_number']) ?></dd>

                        <dt class="col-sm-4">Registry Number</dt>
                        <dd class="col-sm-8"><?= e($application['registry_number']) ?></dd>

                        <dt class="col-sm-4">Date Issued</dt>
                        <dd class="col-sm-8"><?= e($order['created_at'] ? date('F j, Y', strtotime($order['created_at'])) : date('F j, Y')) ?></dd>

                        <dt class="col-sm-4">Account Name</dt>
                        <dd class="col-sm-8"><?= e($application['account_name']) ?></dd>

                        <dt class="col-sm-4">Account Address</dt>
                        <dd class="col-sm-8"><?= e($application['account_address']) ?></dd>

                        <dt class="col-sm-4">Paying For</dt>
                        <dd class="col-sm-8"><?= e($order['payment_for'] ?? default_payment_for($application)) ?></dd>

                        <dt class="col-sm-4">Fee / Amount</dt>
                        <dd class="col-sm-8 fw-bold"><?= currency_php((float)($order['service_fee'] ?? 1500.00)) ?></dd>

                        <dt class="col-sm-4">Account Code</dt>
                        <dd class="col-sm-8">4-02-01-020-8-6</dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">
                            <span class="badge <?= $order['status'] === 'PAID' ? 'text-bg-success' : 'text-bg-warning' ?>">
                                <?= e($order['status']) ?>
                            </span>
                        </dd>
                    </dl>
                <?php else: ?>
                    <div class="text-center py-4 text-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" fill="currentColor"
                             class="mb-2 opacity-50" viewBox="0 0 16 16">
                            <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
                            <path d="M4 4h8v1H4V4zm0 3h8v1H4V7zm0 3h5v1H4v-1z"/>
                        </svg>
                        <p class="mb-0 small">
                            No Order of Payment generated yet.<br>
                            Click <strong>Generate Payment Slip</strong> below to create one.
                        </p>
                    </div>
                <?php endif; ?>

                <form method="post" class="mt-3">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                    <button class="btn btn-primary">
                        <?= $order ? 'Regenerate Payment Slip' : 'Generate Payment Slip' ?>
                    </button>
                </form>
            </section>

            <!-- OP Generation Log -->
            <section class="gov-card p-4">
                <h2 class="h6 mb-3">Generation Log</h2>
                <?php if ($opLogs): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>OP Number</th>
                                    <th>Account Name</th>
                                    <th>Registry No.</th>
                                    <th>Generated By</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($opLogs as $log): ?>
                                    <?php
                                    $details   = json_decode($log['details'] ?? '{}', true);
                                    $opNumber  = $log['order_op_number'] ?? ($details['op_number'] ?? '—');
                                    $genBy     = trim($log['generated_by'] ?? '') ?: 'System';
                                    $actionTag = ($details['action'] ?? '') === 'regenerated' ? 'Regenerated' : 'Generated';
                                    $logDate   = $log['created_at'] ? date('M d, Y', strtotime($log['created_at'])) : '—';
                                    $logTime   = $log['created_at'] ? date('h:i A', strtotime($log['created_at'])) : '—';
                                    ?>
                                    <tr>
                                        <td class="small text-secondary text-nowrap"><?= e($logDate) ?></td>
                                        <td class="small text-secondary text-nowrap"><?= e($logTime) ?></td>
                                        <td class="fw-bold small"><?= e($opNumber) ?></td>
                                        <td class="small"><?= e($log['order_account_name'] ?? $application['account_name'] ?? '—') ?></td>
                                        <td class="small"><?= e($application['registry_number'] ?? '—') ?></td>
                                        <td class="small"><?= e($genBy) ?></td>
                                        <td>
                                            <span class="badge <?= $actionTag === 'Regenerated' ? 'text-bg-warning' : 'text-bg-primary' ?>">
                                                <?= $actionTag ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-secondary small mb-0">No generation history yet. Click <strong>Generate Payment Slip</strong> to create the first entry.</p>
                <?php endif; ?>
            </section>

        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">No application selected.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
