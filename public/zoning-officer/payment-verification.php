<?php
require_once __DIR__ . '/../../app/bootstrap.php';
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
    $_SESSION['flash_success'] = 'Payment verified successfully.';
    redirect('zoning-officer/payment-verification.php?id=' . $applicationId);
}

if (!function_exists('zo_format_datetime')) {
    function zo_format_datetime(?string $value): string
    {
        if (!$value) return 'Not set';
        $ts = strtotime($value);
        return $ts ? date('M d, Y · h:i A', $ts) : $value;
    }
}

if (!function_exists('zo_payment_verified')) {
    function zo_payment_verified(array $order): bool
    {
        return payment_order_is_verified($order);
    }
}

if (!function_exists('zo_payment_logs')) {
    function zo_payment_logs(int $applicationId): array
    {
        try {
            $stmt = db()->query(
                "SELECT po.*, po.id AS payment_order_id, po.status AS payment_status,
                        a.id AS application_id, a.registry_number, a.property_title,
                        a.property_address, a.phase_status, a.account_name AS application_account_name
                 FROM payment_orders po
                 INNER JOIN applications a ON a.id = po.application_id
                 WHERE po.status IN ('PENDING','PAID')
                    OR a.phase_status IN ('PAYMENT_PENDING','PAID','INSPECTION_SCHEDULED',
                        'INSPECTION_DONE','FOR_MEETING','DELIBERATION','APPROVED','DISAPPROVED','DEFERRED')
                 ORDER BY po.id DESC"
            );
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}

$applications    = officer_applications(['PAID']);
$application     = $applicationId ? officer_application($applicationId) : null;
$order           = $application ? payment_order_for_application((int)$application['id']) : null;
$paymentVerified = $order ? payment_order_is_verified($order) : false;
$paymentLogs     = zo_payment_logs($applicationId);

$pendingCount  = count(array_filter($paymentLogs, fn($l) => strtoupper((string)($l['payment_status'] ?? $l['status'] ?? '')) !== 'PAID'));
$paidCount     = count(array_filter($paymentLogs, fn($l) => strtoupper((string)($l['payment_status'] ?? $l['status'] ?? '')) === 'PAID'));
$verifiedCount = count(array_filter($paymentLogs, fn($l) => strtoupper((string)($l['payment_status'] ?? $l['status'] ?? '')) === 'PAID' && zo_payment_verified($l)));

require __DIR__ . '/../partials/header.php';
?>

<style>
:root{--zo-navy:#0d3154;--zo-navy-dark:#08233d;--zo-blue:#0d6efd;--zo-blue-soft:#eaf3ff;--zo-gold:#f6c343;--zo-green:#198754;--zo-green-soft:#eafaf1;--zo-orange:#b56a00;--zo-orange-soft:#fff4d6;--zo-red:#dc3545;--zo-red-soft:#fff0f0;--zo-text:#172033;--zo-muted:#6b7280;--zo-border:#d8e2ef;}
body.zo-page{background:radial-gradient(circle at 1px 1px,rgba(13,110,253,.16) 1px,transparent 0),linear-gradient(180deg,#f7fbff 0%,#edf3fa 100%);background-size:28px 28px,100% 100%;}
.zo-shell{max-width:1180px;margin:0 auto;padding:32px 18px 56px;}
.zo-hero{position:relative;overflow:hidden;border-radius:24px;padding:30px;margin-bottom:22px;background:radial-gradient(circle at top right,rgba(246,195,67,.28),transparent 35%),linear-gradient(135deg,#fff 0%,#f5f9ff 100%);border:1px solid var(--zo-border);box-shadow:0 18px 42px rgba(13,49,84,.10);}
.zo-eyebrow{display:inline-flex;align-items:center;gap:8px;margin-bottom:10px;padding:6px 12px;border-radius:999px;background:var(--zo-blue-soft);color:var(--zo-blue);font-size:.72rem;font-weight:900;letter-spacing:.09em;text-transform:uppercase;}
.zo-eyebrow-dot{width:7px;height:7px;border-radius:50%;background:var(--zo-gold);box-shadow:0 0 0 4px rgba(246,195,67,.22);}
.zo-title{margin:0;color:#071d35;font-size:clamp(1.75rem,4vw,2.55rem);font-weight:900;line-height:1.05;letter-spacing:-.04em;}
.zo-subtitle{max-width:760px;margin:12px 0 0;color:var(--zo-muted);font-size:.98rem;font-weight:500;line-height:1.7;}
.zo-stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:24px;}
.zo-stat-card{position:relative;overflow:hidden;min-height:140px;padding:22px;border-radius:20px;background:radial-gradient(circle at top right,rgba(13,110,253,.10),transparent 36%),linear-gradient(145deg,#fff 0%,#f8fbff 100%);border:1px solid var(--zo-border);box-shadow:0 16px 34px rgba(13,49,84,.10),inset 0 1px 0 rgba(255,255,255,.85);}
.zo-stat-card::before{content:"";position:absolute;top:0;left:0;width:100%;height:4px;background:linear-gradient(90deg,var(--zo-blue),rgba(13,110,253,.15));}
.zo-stat-card--pending::before{background:linear-gradient(90deg,var(--zo-orange),rgba(181,106,0,.12));}
.zo-stat-card--paid::before{background:linear-gradient(90deg,var(--zo-green),rgba(25,135,84,.12));}
.zo-stat-label{margin:0 0 9px;color:#071d35;font-size:.78rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;}
.zo-stat-value{margin:0;color:#071d35;font-size:2.3rem;font-weight:950;line-height:1;letter-spacing:-.06em;}
.zo-stat-sub{margin:9px 0 0;color:var(--zo-muted);font-size:.82rem;line-height:1.45;}
.zo-layout{display:grid;grid-template-columns:360px minmax(0,1fr);gap:24px;align-items:start;}
.zo-panel{overflow:hidden;border-radius:20px;background:rgba(255,255,255,.96);border:1px solid var(--zo-border);box-shadow:0 18px 42px rgba(13,49,84,.10);}
.zo-panel-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:20px 22px;border-bottom:1px solid var(--zo-border);background:radial-gradient(circle at top right,rgba(13,110,253,.08),transparent 30%),#fff;}
.zo-panel-title{margin:0;color:#071d35;font-size:1rem;font-weight:900;letter-spacing:-.02em;}
.zo-panel-sub{margin:4px 0 0;color:var(--zo-muted);font-size:.8rem;line-height:1.5;font-weight:500;}
.zo-panel-body{padding:20px 22px;}
.zo-list{display:grid;gap:10px;}
.zo-app-link{display:block;padding:14px;border-radius:14px;border:1px solid #edf2f8;background:#fff;color:inherit;text-decoration:none;transition:transform .18s,border-color .18s,box-shadow .18s;}
.zo-app-link:hover,.zo-app-link.is-active{color:inherit;transform:translateY(-1px);border-color:#b7cce5;box-shadow:0 12px 24px rgba(13,49,84,.10);}
.zo-app-link.is-active{background:linear-gradient(135deg,#eaf3ff 0%,#fff 100%);}
.zo-app-registry{display:flex;align-items:center;gap:8px;color:#071d35;font-family:ui-monospace,monospace;font-size:.78rem;font-weight:900;margin-bottom:5px;}
.zo-app-registry::before{content:"";width:8px;height:8px;flex:0 0 8px;border-radius:50%;background:var(--zo-blue);box-shadow:0 0 0 4px rgba(13,110,253,.13);}
.zo-app-title{margin:0;color:var(--zo-text);font-size:.88rem;font-weight:800;line-height:1.35;}
.zo-app-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:9px;}
.zo-mini-badge,.zo-status-badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 9px;border-radius:999px;font-size:.7rem;font-weight:900;line-height:1;white-space:nowrap;}
.zo-mini-badge{background:var(--zo-blue-soft);color:var(--zo-blue);}
.zo-status-badge--pending{background:var(--zo-orange-soft);color:var(--zo-orange);}
.zo-status-badge--paid{background:var(--zo-green-soft);color:var(--zo-green);}
.zo-status-badge--verified{background:#e9f8ee;color:#116d3b;}
.zo-empty{padding:28px 16px;text-align:center;color:var(--zo-muted);font-size:.86rem;line-height:1.6;}
.zo-details-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
.zo-detail{padding:14px;border-radius:14px;background:#f8fbff;border:1px solid #edf2f8;}
.zo-detail-label{margin:0 0 5px;color:var(--zo-muted);font-size:.68rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;}
.zo-detail-value{margin:0;color:#071d35;font-size:.9rem;font-weight:850;line-height:1.45;overflow-wrap:anywhere;}
.zo-form-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:18px;}
.zo-btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:10px 16px;border-radius:12px;font-size:.84rem;font-weight:850;line-height:1;text-decoration:none;border:1px solid transparent;background:var(--zo-blue);color:#fff;box-shadow:0 10px 22px rgba(13,110,253,.24);transition:transform .18s,box-shadow .18s;}
.zo-btn-primary:hover{color:#fff;transform:translateY(-1px);box-shadow:0 14px 28px rgba(13,110,253,.30);}
.zo-btn-outline{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:10px 16px;border-radius:12px;font-size:.84rem;font-weight:850;line-height:1;text-decoration:none;border:1px solid var(--zo-border);background:#fff;color:var(--zo-navy);transition:transform .18s,border-color .18s;}
.zo-btn-outline:hover{color:var(--zo-navy);background:#f7fbff;border-color:#b9cce2;transform:translateY(-1px);}
.zo-note{margin:0;color:var(--zo-muted);font-size:.82rem;line-height:1.55;}
.zo-tabs{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
.zo-tab{border:1px solid var(--zo-border);background:#fff;color:var(--zo-muted);border-radius:999px;padding:8px 12px;font-size:.76rem;font-weight:900;cursor:pointer;transition:background .16s,color .16s,border-color .16s;}
.zo-tab.is-active{background:var(--zo-blue);color:#fff;border-color:var(--zo-blue);}
.zo-search-wrap{display:flex;align-items:center;gap:10px;padding:10px 12px;margin-bottom:14px;border-radius:12px;border:1px solid var(--zo-border);background:#f8fbff;}
.zo-search-wrap input{width:100%;border:0;outline:none;background:transparent;color:var(--zo-text);font-size:.84rem;font-weight:600;}
.zo-table-wrap{overflow-x:auto;}
.zo-table{width:100%;border-collapse:separate;border-spacing:0 10px;margin:0;min-width:700px;}
.zo-table thead th{padding:10px 12px 6px;color:#071d35;font-size:.7rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;border:0;}
.zo-table tbody tr{background:#fff;box-shadow:0 8px 22px rgba(13,49,84,.07);}
.zo-table tbody td{padding:14px 12px;vertical-align:middle;border-top:1px solid #edf2f8;border-bottom:1px solid #edf2f8;color:var(--zo-text);font-size:.82rem;font-weight:600;}
.zo-table tbody td:first-child{border-left:1px solid #edf2f8;border-radius:14px 0 0 14px;}
.zo-table tbody td:last-child{border-right:1px solid #edf2f8;border-radius:0 14px 14px 0;}
.zo-registry{color:#071d35;font-family:ui-monospace,monospace;font-size:.78rem;font-weight:900;}
@media(max-width:1100px){.zo-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.zo-layout{grid-template-columns:1fr;}}
@media(max-width:767.98px){.zo-shell{padding:24px 14px 44px;}.zo-details-grid{grid-template-columns:1fr;}}
</style>
<script>document.body.classList.add('zo-page');</script>

<div class="zo-shell">
    <section class="zo-hero">
        <div>
            <div class="zo-eyebrow"><span class="zo-eyebrow-dot"></span>Zoning Officer Workspace</div>
            <h1 class="zo-title">Payment Verification</h1>
            <p class="zo-subtitle">Review and verify official payment receipts from applicants before proceeding to inspection scheduling.</p>
        </div>
    </section>

    <section class="zo-stat-grid">
        <article class="zo-stat-card zo-stat-card--pending">
            <p class="zo-stat-label">Pending</p>
            <p class="zo-stat-value"><?= number_format($pendingCount) ?></p>
            <p class="zo-stat-sub">Awaiting payment completion</p>
        </article>
        <article class="zo-stat-card zo-stat-card--paid">
            <p class="zo-stat-label">Paid</p>
            <p class="zo-stat-value"><?= number_format($paidCount) ?></p>
            <p class="zo-stat-sub">Recorded paid receipts</p>
        </article>
        <article class="zo-stat-card">
            <p class="zo-stat-label">Verified</p>
            <p class="zo-stat-value"><?= number_format($verifiedCount) ?></p>
            <p class="zo-stat-sub">Receipts already verified</p>
        </article>
    </section>

    <div class="zo-layout">
        <aside>
            <section class="zo-panel">
                <div class="zo-panel-header">
                    <div>
                        <h2 class="zo-panel-title">Paid Applications</h2>
                        <p class="zo-panel-sub">Select one to review its payment receipt.</p>
                    </div>
                    <span class="zo-mini-badge"><?= count($applications) ?></span>
                </div>
                <div class="zo-panel-body">
                    <?php if ($applications): ?>
                        <div class="zo-list">
                            <?php foreach ($applications as $row):
                                $rowOrder    = payment_order_for_application((int)$row['id']) ?: [];
                                $rowVerified = $rowOrder && zo_payment_verified($rowOrder);
                            ?>
                                <a class="zo-app-link <?= $application && (int)$application['id'] === (int)$row['id'] ? 'is-active' : '' ?>"
                                   href="payment-verification.php?id=<?= (int)$row['id'] ?>">
                                    <div class="zo-app-registry"><?= e($row['registry_number']) ?></div>
                                    <p class="zo-app-title"><?= e($row['property_title']) ?></p>
                                    <div class="zo-app-meta">
                                        <?php if ($rowVerified): ?>
                                            <span class="zo-status-badge zo-status-badge--verified">Verified</span>
                                        <?php else: ?>
                                            <span class="zo-status-badge zo-status-badge--pending">Needs Verification</span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="zo-empty">No paid applications awaiting verification.</div>
                    <?php endif; ?>
                </div>
            </section>
        </aside>

        <main class="d-grid gap-4">
            <?php if ($application && $order): ?>
                <section class="zo-panel" id="payment-panel" data-sensitive>
                    <div style="padding:22px;">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:18px;flex-wrap:wrap;">
                            <div>
                                <h2 style="margin:0;color:#071d35;font-size:1.12rem;font-weight:900;">Payment Receipt</h2>
                                <p style="margin:5px 0 0;color:var(--zo-muted);font-size:.83rem;">
                                    <?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?>
                                </p>
                            </div>
                            <?php if ($paymentVerified): ?>
                                <span class="zo-status-badge zo-status-badge--verified">Verified</span>
                            <?php else: ?>
                                <span class="zo-status-badge zo-status-badge--pending">Needs Verification</span>
                            <?php endif; ?>
                        </div>
                        <div class="zo-details-grid">
                            <div class="zo-detail"><p class="zo-detail-label">Account Name</p><p class="zo-detail-value"><?= e($order['account_name'] ?? $application['account_name']) ?></p></div>
                            <div class="zo-detail"><p class="zo-detail-label">Paying For</p><p class="zo-detail-value"><?= e($order['payment_for'] ?? default_payment_for($application)) ?></p></div>
                            <div class="zo-detail"><p class="zo-detail-label">Payment Date</p><p class="zo-detail-value"><?= e(zo_format_datetime($order['paid_at'] ?? null)) ?></p></div>
                            <div class="zo-detail"><p class="zo-detail-label">OP Number</p><p class="zo-detail-value"><?= e($order['op_number'] ?? 'Not provided') ?></p></div>
                            <div class="zo-detail"><p class="zo-detail-label">Receipt Number</p><p class="zo-detail-value"><?= e($order['receipt_number'] ?? 'Pending receipt') ?></p></div>
                            <div class="zo-detail"><p class="zo-detail-label">Fee / Amount</p><p class="zo-detail-value"><?= currency_php((float)($order['service_fee'] ?? 0)) ?></p></div>
                            <div class="zo-detail"><p class="zo-detail-label">Payment Method</p><p class="zo-detail-value"><?= e($order['payment_method'] ?? 'PayMongo') ?></p></div>
                            <div class="zo-detail"><p class="zo-detail-label">Status</p><p class="zo-detail-value"><?= e(strtoupper((string)($order['status'] ?? 'PENDING'))) ?></p></div>
                        </div>
                        <div class="zo-form-actions">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                                <button class="zo-btn-primary" <?= $paymentVerified ? 'disabled' : '' ?>>
                                    <?= $paymentVerified ? 'Payment Verified' : 'Verify Payment' ?>
                                </button>
                            </form>
                            <?php if ($paymentVerified): ?>
                                <a class="zo-btn-outline" href="inspection-scheduling.php?id=<?= (int)$application['id'] ?>">
                                    Schedule Inspection &rarr;
                                </a>
                            <?php else: ?>
                                <span class="zo-note">Verify the payment to unlock inspection scheduling.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <section class="zo-panel">
                    <div class="zo-empty">
                        <strong>Select a paid application</strong><br>
                        Choose an item from the list to view its payment receipt.
                    </div>
                </section>
            <?php endif; ?>

            <!-- Payment logs table -->
            <section class="zo-panel" id="payment-logs">
                <div class="zo-panel-header">
                    <div>
                        <h2 class="zo-panel-title">Payment Logs</h2>
                        <p class="zo-panel-sub">All paid and pending payment records.</p>
                    </div>
                </div>
                <div class="zo-panel-body">
                    <div class="zo-tabs">
                        <button type="button" class="zo-tab is-active" data-payment-filter="all">All</button>
                        <button type="button" class="zo-tab" data-payment-filter="paid">Paid</button>
                        <button type="button" class="zo-tab" data-payment-filter="pending">Pending</button>
                        <button type="button" class="zo-tab" data-payment-filter="verified">Verified</button>
                    </div>
                    <div class="zo-search-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" width="16" height="16"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="search" id="paymentLogSearch" placeholder="Search registry, property, OP number...">
                    </div>
                    <?php if ($paymentLogs): ?>
                        <div class="zo-table-wrap">
                            <table class="zo-table">
                                <thead><tr><th>Registry</th><th>Property</th><th>Amount</th><th>Payment Date</th><th>OP / Receipt</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($paymentLogs as $log):
                                    $logStatus  = strtoupper((string)($log['payment_status'] ?? $log['status'] ?? 'PENDING'));
                                    $logPaid     = $logStatus === 'PAID';
                                    $logVerified = $logPaid && zo_payment_verified($log);
                                    $logAppId    = (int)($log['application_id'] ?? 0);
                                    if ($logVerified)     { $logFilter = 'verified paid'; $badgeClass = 'zo-status-badge--verified'; $badgeText = 'Verified'; }
                                    elseif ($logPaid)     { $logFilter = 'paid';          $badgeClass = 'zo-status-badge--paid';     $badgeText = 'Paid'; }
                                    else                  { $logFilter = 'pending';       $badgeClass = 'zo-status-badge--pending';  $badgeText = 'Pending'; }
                                ?>
                                    <tr data-payment-row data-payment-status="<?= e($logFilter) ?>">
                                        <td><span class="zo-registry"><?= e($log['registry_number'] ?? 'N/A') ?></span></td>
                                        <td><?= e($log['property_title'] ?? 'Untitled') ?></td>
                                        <td><?= currency_php((float)($log['service_fee'] ?? 0)) ?></td>
                                        <td><?= e(zo_format_datetime($log['paid_at'] ?? null)) ?></td>
                                        <td><strong>OP:</strong> <?= e($log['op_number'] ?? 'N/A') ?><br><strong>Receipt:</strong> <?= e($log['receipt_number'] ?? 'Pending') ?></td>
                                        <td><span class="zo-status-badge <?= e($badgeClass) ?>"><?= e($badgeText) ?></span></td>
                                        <td class="text-end">
                                            <?php if ($logPaid && $logAppId > 0): ?>
                                                <a class="zo-btn-outline" href="payment-verification.php?id=<?= $logAppId ?>">Open</a>
                                            <?php else: ?>
                                                <span class="zo-note">Waiting</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="zo-empty">No payment logs found yet.</div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<script>
(function(){
    var searchInput = document.getElementById('paymentLogSearch');
    var tabButtons  = document.querySelectorAll('[data-payment-filter]');
    var rows        = document.querySelectorAll('[data-payment-row]');
    var activeFilter = 'all';
    function applyFilters() {
        var q = searchInput ? searchInput.value.toLowerCase().trim() : '';
        rows.forEach(function(row) {
            var s = row.dataset.paymentStatus || '';
            var t = row.textContent.toLowerCase();
            row.style.display = (activeFilter === 'all' || s.indexOf(activeFilter) !== -1) && (!q || t.indexOf(q) !== -1) ? '' : 'none';
        });
    }
    tabButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            tabButtons.forEach(function(b){ b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            activeFilter = btn.dataset.paymentFilter || 'all';
            applyFilters();
        });
    });
    if (searchInput) searchInput.addEventListener('input', applyFilters);
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
