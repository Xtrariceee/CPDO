<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
// This page has been split into payment-verification.php and inspection-scheduling.php
$id = (int)($_GET['id'] ?? 0);
$anchor = $_GET['anchor'] ?? '';
if ($anchor === 'schedule-panel') {
    redirect('zoning-officer/inspection-scheduling.php' . ($id ? '?id=' . $id : ''));
}
redirect('zoning-officer/payment-verification.php' . ($id ? '?id=' . $id : ''));

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $application = officer_application($applicationId);
    $order = payment_order_for_application($applicationId);

    if (!$order || $order['status'] !== 'PAID') {
        $_SESSION['flash_error'] = 'A paid receipt is required before this action can continue.';
        redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId);
    }

    if ($action === 'verify_payment') {
        verify_payment_order((int)$order['id'], (int)$user['id']);
        audit_log((int)$user['id'], 'P6_PAYMENT_VERIFIED', 'payment_orders', (int)$order['id']);
        $_SESSION['flash_success'] = 'Payment verified.';
        redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId);
    }

    if ($action === 'schedule_inspection') {
        if (!payment_order_is_verified($order)) {
            $_SESSION['flash_error'] = 'Verify the payment before scheduling inspection.';
            redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId);
        }

        try {
            $scheduledAt = schedule_inspection_for_application(
                $applicationId,
                trim($_POST['inspection_date'] ?? ''),
                trim($_POST['inspection_time'] ?? ''),
                (int)$user['id']
            );
        } catch (RuntimeException $exception) {
            $_SESSION['flash_error'] = $exception->getMessage();
            redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId);
        }
        advance_application($applicationId, 'INSPECTION_SCHEDULED', 7);
        notify_user((int)$application['landlord_id'], $applicationId, 'Inspection scheduled', 'Your CPDO inspection has been scheduled.');
        notify_role(ROLE_TWG, $applicationId, 'New inspection assignment', 'A CPDO inspection is ready for TWG field validation.');
        audit_log((int)$user['id'], 'P7_INSPECTION_SCHEDULED', 'applications', $applicationId, ['scheduled_at' => $scheduledAt]);
        $_SESSION['flash_success'] = 'Inspection scheduled.';
        redirect('zoning-officer/payment-scheduling.php');
    }

    $_SESSION['flash_error'] = 'Unknown payment action.';
    redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId);
}

$applications = officer_applications(['PAID']);
$application  = $applicationId ? officer_application($applicationId) : null;
$order        = $application ? payment_order_for_application((int)$application['id']) : null;
$paymentVerified = $order ? payment_order_is_verified($order) : false;

// Payment logs: all payment orders + audit events for this application
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
               "P7_INSPECTION_SCHEDULED",
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
<h1 class="h3 mb-3">Payment Verification and Inspection Scheduling</h1>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">Paid Applications</h2>
            <div class="list-group">
                <?php foreach ($applications as $row): ?>
                    <a class="list-group-item list-group-item-action <?= $application && (int)$application['id'] === (int)$row['id'] ? 'active' : '' ?>" href="payment-scheduling.php?id=<?= (int)$row['id'] ?>">
                        <?= e($row['registry_number']) ?><br><small><?= e($row['property_title']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$applications): ?><div class="text-secondary small">No paid applications ready for scheduling.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($application && $order): ?>
            <section class="gov-card p-4 mb-4" id="payment-panel" data-sensitive>
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                        <h2 class="h5 mb-1">Payment Receipt</h2>
                        <p class="small text-secondary mb-0"><?= e($application['registry_number']) ?> - <?= e($application['property_title']) ?></p>
                    </div>
                    <span class="badge <?= $paymentVerified ? 'text-bg-success' : 'text-bg-warning' ?>">
                        <?= $paymentVerified ? 'Verified' : 'Needs Verification' ?>
                    </span>
                </div>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Account Name</dt><dd class="col-sm-8"><?= e($order['account_name'] ?? $application['account_name']) ?></dd>
                    <dt class="col-sm-4">Paying For</dt><dd class="col-sm-8"><?= e($order['payment_for'] ?? default_payment_for($application)) ?></dd>
                    <dt class="col-sm-4">Payment Date</dt><dd class="col-sm-8"><?= e($order['paid_at'] ?? 'Not paid') ?></dd>
                    <dt class="col-sm-4">OP Number</dt><dd class="col-sm-8"><?= e($order['op_number']) ?></dd>
                    <dt class="col-sm-4">Receipt Number</dt><dd class="col-sm-8"><?= e($order['receipt_number'] ?? 'Pending receipt') ?></dd>
                    <dt class="col-sm-4">Fee / Amount</dt><dd class="col-sm-8"><?= currency_php((float)$order['service_fee']) ?></dd>
                    <dt class="col-sm-4">Payment Method</dt><dd class="col-sm-8"><?= e($order['payment_method'] ?? 'PayMongo') ?></dd>
                </dl>
                <form class="mt-3" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                    <input type="hidden" name="action" value="verify_payment">
                    <button class="btn btn-primary" <?= $paymentVerified ? 'disabled' : '' ?>>Verify Payment</button>
                </form>
            </section>

            <section class="gov-card p-4" id="schedule-panel">
                <h2 class="h5 mb-3">Inspection Schedule</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                    <input type="hidden" name="action" value="schedule_inspection">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Inspection Date</label>
                            <input class="form-control" type="date" name="inspection_date" required <?= $paymentVerified ? '' : 'disabled' ?>>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Inspection Time</label>
                            <input class="form-control" type="time" name="inspection_time" required <?= $paymentVerified ? '' : 'disabled' ?>>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" <?= $paymentVerified ? '' : 'disabled' ?>>Schedule Inspection</button>
                    <?php if (!$paymentVerified): ?>
                        <p class="small text-secondary mt-2 mb-0">Verify payment before scheduling inspection.</p>
                    <?php endif; ?>
                </form>
            </section>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">
                Select a paid application to view its payment receipt and schedule inspection.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($application): ?>
<section class="gov-card p-4 mt-4" id="payment-logs">
    <h2 class="h5 mb-3">Payment Activity Log</h2>
    <?php if ($paymentLogs): ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:160px;">Date &amp; Time</th>
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
                        'P7_INSPECTION_SCHEDULED'    => 'Inspection Scheduled',
                        'PAYMENT_MARKED_PAID'        => 'Payment Marked as Paid',
                        default                      => e($log['action']),
                    };
                    $badgeClass = match ($log['action']) {
                        'ORDER_OF_PAYMENT_GENERATED' => 'text-bg-secondary',
                        'P6_PAYMENT_VERIFIED'        => 'text-bg-success',
                        'P7_INSPECTION_SCHEDULED'    => 'text-bg-primary',
                        'PAYMENT_MARKED_PAID'        => 'text-bg-info',
                        default                      => 'text-bg-secondary',
                    };
                    $details = '';
                    if (!empty($log['details'])) {
                        $decoded = json_decode($log['details'], true);
                        if (is_array($decoded)) {
                            $parts = [];
                            foreach ($decoded as $k => $v) {
                                $parts[] = ucwords(str_replace('_', ' ', $k)) . ': ' . e((string)$v);
                            }
                            $details = implode(' · ', $parts);
                        }
                    }
                ?>
                    <tr>
                        <td class="text-secondary small text-nowrap">
                            <?= e(date('M j, Y H:i', strtotime($log['created_at']))) ?>
                        </td>
                        <td>
                            <span class="badge <?= $badgeClass ?>"><?= $eventLabel ?></span>
                        </td>
                        <td class="small"><?= e($log['actor_name'] ?? '—') ?></td>
                        <td class="small text-secondary"><?= $details ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="text-secondary small mb-0">No payment activity recorded for this application yet.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../partials/footer.php'; ?>
