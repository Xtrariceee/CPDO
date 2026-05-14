<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$applicationId) {
        $_SESSION['flash_error'] = 'No application selected.';
        redirect('zoning-officer/payment-verification.php');
    }
    $application = officer_application($applicationId);
    $order       = payment_order_for_application($applicationId);

    if (!$order || $order['status'] !== 'PAID') {
        $_SESSION['flash_error'] = 'A paid receipt is required before this action can continue.';
        redirect('zoning-officer/payment-verification.php?id=' . $applicationId);
    }

    verify_payment_order((int)$order['id'], (int)$user['id']);
    notify_user((int)$application['landlord_id'], $applicationId, 'Payment Verified', 'Your payment has been verified by the CPDO. You may now proceed to the next step.');
    audit_log((int)$user['id'], 'P6_PAYMENT_VERIFIED', 'payment_orders', (int)$order['id']);
    $_SESSION['flash_success'] = 'Payment verified.';
    redirect('zoning-officer/payment-verification.php?id=' . $applicationId);
}

$applications    = officer_applications(['PAID']);
$application     = $applicationId ? officer_application($applicationId) : null;
$order           = $application ? payment_order_for_application((int)$application['id']) : null;
$paymentVerified = $order ? payment_order_is_verified($order) : false;

// Activity log for this application
$paymentLogs = [];
if ($application) {
    $logStmt = db()->prepare(
        'SELECT al.created_at, al.action, al.details,
                CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS actor_name
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.entity_type IN ("payment_orders", "applications")
           AND al.entity_id IN (
               SELECT id FROM payment_orders WHERE application_id = ?
               UNION SELECT ?
           )
           AND al.action IN (
               "ORDER_OF_PAYMENT_GENERATED",
               "P6_PAYMENT_VERIFIED",
               "PAYMENT_MARKED_PAID"
           )
         ORDER BY al.created_at DESC
         LIMIT 50'
    );
    $logStmt->execute([(int)$application['id'], (int)$application['id']]);
    $paymentLogs = $logStmt->fetchAll();
}

require __DIR__ . '/../partials/header.php';
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="index.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
    <div>
        <h1 class="h3 mb-0">Payment Verification</h1>
    </div>
</div>

<div class="row g-4">
    <!-- Application list -->
    <div class="col-lg-3">
        <div class="gov-card p-3">
            <p class="eyebrow mb-2">Paid Applications</p>
            <?php if ($applications): ?>
                <div class="d-grid gap-1">
                    <?php foreach ($applications as $row): ?>
                        <?php $isActive = $application && (int)$application['id'] === (int)$row['id']; ?>
                        <a class="app-list-item <?= $isActive ? 'app-list-item--active' : '' ?>"
                           href="payment-verification.php?id=<?= (int)$row['id'] ?>">
                            <span class="app-list-registry"><?= e($row['registry_number']) ?></span>
                            <span class="app-list-title"><?= e($row['property_title']) ?></span>
                            <span class="app-list-landlord"><?= e($row['landlord_name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-secondary small mb-0">No paid applications awaiting verification.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main content -->
    <div class="col-lg-9">
        <?php if ($application && $order): ?>
            <section class="gov-card p-4 mb-4" id="payment-panel" data-sensitive>
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                        <h2 class="h5 mb-1">Payment Receipt</h2>
                        <p class="small text-secondary mb-0">
                            <?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?>
                        </p>
                    </div>
                    <span class="badge <?= $paymentVerified ? 'text-bg-success' : 'text-bg-warning' ?>">
                        <?= $paymentVerified ? 'Verified' : 'Needs Verification' ?>
                    </span>
                </div>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Account Name</dt>
                    <dd class="col-sm-8"><?= e($order['account_name'] ?? $application['account_name']) ?></dd>
                    <dt class="col-sm-4">Paying For</dt>
                    <dd class="col-sm-8"><?= e($order['payment_for'] ?? default_payment_for($application)) ?></dd>
                    <dt class="col-sm-4">Payment Date</dt>
                    <dd class="col-sm-8"><?= e($order['paid_at'] ?? 'Not paid') ?></dd>
                    <dt class="col-sm-4">OP Number</dt>
                    <dd class="col-sm-8"><?= e($order['op_number']) ?></dd>
                    <dt class="col-sm-4">Receipt Number</dt>
                    <dd class="col-sm-8"><?= e($order['receipt_number'] ?? 'Pending receipt') ?></dd>
                    <dt class="col-sm-4">Fee / Amount</dt>
                    <dd class="col-sm-8"><?= currency_php((float)$order['service_fee']) ?></dd>
                    <dt class="col-sm-4">Payment Method</dt>
                    <dd class="col-sm-8"><?= e($order['payment_method'] ?? 'PayMongo') ?></dd>
                </dl>
                <div class="d-flex align-items-center gap-3 mt-3 flex-wrap">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                        <button class="btn btn-primary" <?= $paymentVerified ? 'disabled' : '' ?>>
                            Verify Payment
                        </button>
                    </form>
                    <?php if ($paymentVerified): ?>
                        <a class="btn btn-outline-primary btn-sm"
                           href="inspection-scheduling.php?id=<?= (int)$application['id'] ?>">
                            Schedule Inspection &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Activity log -->
            <section class="gov-card p-4" id="payment-logs">
                <h2 class="h5 mb-3">Payment Activity Log</h2>
                <?php if ($paymentLogs): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:100px;">Date</th>
                                    <th style="width:80px;">Time</th>
                                    <th>Event</th>
                                    <th>By</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($paymentLogs as $log):
                                $eventLabel = match ($log['action']) {
                                    'ORDER_OF_PAYMENT_GENERATED' => 'Order of Payment Generated',
                                    'P6_PAYMENT_VERIFIED'        => 'Payment Verified',
                                    'PAYMENT_MARKED_PAID'        => 'Payment Marked as Paid',
                                    default                      => e($log['action']),
                                };
                                $badgeClass = match ($log['action']) {
                                    'ORDER_OF_PAYMENT_GENERATED' => 'text-bg-secondary',
                                    'P6_PAYMENT_VERIFIED'        => 'text-bg-success',
                                    'PAYMENT_MARKED_PAID'        => 'text-bg-info',
                                    default                      => 'text-bg-secondary',
                                };
                                // Build details: always include account name + registry, then any extra fields
                                $detailParts = [];
                                $detailParts[] = 'Account: ' . e($application['account_name'] ?? '—');
                                $detailParts[] = 'Registry: ' . e($application['registry_number'] ?? '—');
                                if (!empty($log['details'])) {
                                    $decoded = json_decode($log['details'], true);
                                    if (is_array($decoded)) {
                                        foreach ($decoded as $k => $v) {
                                            if (!in_array($k, ['account_name', 'registry_number'], true)) {
                                                $detailParts[] = ucwords(str_replace('_', ' ', $k)) . ': ' . e((string)$v);
                                            }
                                        }
                                    }
                                }
                                $logDate = $log['created_at'] ? date('M j, Y', strtotime($log['created_at'])) : '—';
                                $logTime = $log['created_at'] ? date('H:i', strtotime($log['created_at'])) : '—';
                            ?>
                                <tr>
                                    <td class="text-secondary small text-nowrap"><?= e($logDate) ?></td>
                                    <td class="text-secondary small text-nowrap"><?= e($logTime) ?></td>
                                    <td><span class="badge <?= $badgeClass ?>"><?= $eventLabel ?></span></td>
                                    <td class="small"><?= e($log['actor_name'] ?? '—') ?></td>
                                    <td class="small text-secondary"><?= implode(' · ', $detailParts) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-secondary small mb-0">No payment activity recorded for this application yet.</p>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">
                Select a paid application from the list to view its payment receipt.
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.app-list-item{display:block;padding:10px 12px;border-radius:var(--radius-sm);text-decoration:none;border:1px solid transparent;transition:background var(--transition-fast),border-color var(--transition-fast);}
.app-list-item:hover{background:var(--cpdo-light);border-color:rgba(47,128,199,.2);}
.app-list-item--active{background:var(--cpdo-light);border-color:var(--cpdo-blue);}
.app-list-registry{display:block;font-size:var(--text-xs);font-weight:900;color:var(--cpdo-blue);letter-spacing:.04em;}
.app-list-title{display:block;font-size:var(--text-sm);font-weight:700;color:var(--cpdo-deep);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.app-list-landlord{display:block;font-size:var(--text-xs);color:var(--cpdo-muted);margin-top:1px;}
</style>

<?php require __DIR__ . '/../partials/footer.php'; ?>
