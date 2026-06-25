<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TENANT]);

// Handle invoice payment POST simulation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_invoice') {
    verify_csrf();
    
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    if (!$invoiceId) {
        $_SESSION['flash_error'] = 'Invalid invoice reference.';
        redirect('tenant/payment-hub.php');
    }

    try {
        $pdo = db();
        $pdo->beginTransaction();

        // 1. Fetch invoice details
        $invStmt = $pdo->prepare('SELECT lease_id, status FROM invoices WHERE id = ? AND tenant_id = ? AND status = "unpaid"');
        $invStmt->execute([$invoiceId, (int)$user['id']]);
        $invoice = $invStmt->fetch();

        if (!$invoice) {
            throw new Exception('Invoice not found or already settled.');
        }

        $leaseId = (int)$invoice['lease_id'];

        // 2. Set invoice status to paid
        $updInvoice = $pdo->prepare('UPDATE invoices SET status = "paid", paid_at = NOW() WHERE id = ?');
        $updInvoice->execute([$invoiceId]);

        // 3. Check if this invoice contained move_in_fees
        $chkMoveInStmt = $pdo->prepare('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ? AND type = "move_in_fees"');
        $chkMoveInStmt->execute([$invoiceId]);
        $hasMoveIn = (int)$chkMoveInStmt->fetchColumn() > 0;

        if ($hasMoveIn) {
            // Update lease status to active
            $updLease = $pdo->prepare('UPDATE leases SET status = "active" WHERE id = ?');
            $updLease->execute([$leaseId]);

            // Notify landlord that tenancy is now fully active
            $propStmt = $pdo->prepare('SELECT p.landlord_id, p.title FROM leases l JOIN properties p ON p.id = l.property_id WHERE l.id = ?');
            $propStmt->execute([$leaseId]);
            $prop = $propStmt->fetch();
            $landlordId = (int)$prop['landlord_id'];
            $propTitle = $prop['title'];

            $notify = $pdo->prepare('INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, "Move-In Fees Settled & Tenancy Active", ?, NOW())');
            $msg = user_full_name($user) . " paid the move-in fees for \"{$propTitle}\". The lease is now active, and they are listed in your active tenant registry.";
            $notify->execute([$landlordId, $msg]);

            audit_log((int)$user['id'], 'LEASE_ACTIVATED_BY_PAYMENT', 'leases', $leaseId, ['invoice_id' => $invoiceId]);
        } else {
            // Standard monthly rent payment notification to landlord
            $propStmt = $pdo->prepare('SELECT p.landlord_id, p.title FROM leases l JOIN properties p ON p.id = l.property_id WHERE l.id = ?');
            $propStmt->execute([$leaseId]);
            $prop = $propStmt->fetch();
            $landlordId = (int)$prop['landlord_id'];
            $propTitle = $prop['title'];

            $notify = $pdo->prepare('INSERT INTO notifications (user_id, title, message, created_at) VALUES (?, "Monthly Rent Settled", ?, NOW())');
            $msg = user_full_name($user) . " settled the monthly statement for \"{$propTitle}\" (Invoice #{$invoiceId}).";
            $notify->execute([$landlordId, $msg]);

            audit_log((int)$user['id'], 'MONTHLY_RENT_PAID_NEXT_PHASE', 'invoices', $invoiceId);
        }

        $pdo->commit();
        $_SESSION['flash_success'] = 'Payment processed successfully! Your statement has been marked as PAID.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = 'Payment failed: ' . $e->getMessage();
    }

    redirect('tenant/payment-hub.php');
}

// Fetch all unpaid invoices
$unpaidStmt = db()->prepare(
    'SELECT i.*, p.title AS property_title, 
            (SELECT SUM(amount) FROM invoice_items WHERE invoice_id = i.id) AS total_amount
     FROM invoices i
     JOIN leases l ON l.id = i.lease_id
     JOIN properties p ON p.id = l.property_id
     WHERE i.tenant_id = ? AND i.status = "unpaid"
     ORDER BY i.due_date ASC'
);
$unpaidStmt->execute([(int)$user['id']]);
$unpaidInvoices = $unpaidStmt->fetchAll();

// Fetch payment history receipts
$paidStmt = db()->prepare(
    'SELECT i.*, p.title AS property_title,
            (SELECT SUM(amount) FROM invoice_items WHERE invoice_id = i.id) AS total_amount
     FROM invoices i
     JOIN leases l ON l.id = i.lease_id
     JOIN properties p ON p.id = l.property_id
     WHERE i.tenant_id = ? AND i.status = "paid"
     ORDER BY i.paid_at DESC'
);
$paidStmt->execute([(int)$user['id']]);
$receipts = $paidStmt->fetchAll();

require __DIR__ . '/../partials/header.php';
?>

<div class="container-fluid py-4" style="max-width: 1000px; margin: 0 auto;">
    <!-- Page Header -->
    <div class="mb-4">
        <p class="dash-header-eyebrow mb-1">Resident Portal</p>
        <h1 class="dash-header-title mb-1">Payment Hub</h1>
        <p class="dash-header-sub">Settle monthly rentals, utility invoices, move-in fees, and fines.</p>
    </div>

    <!-- 1. Unpaid Invoices / Action required -->
    <div class="glass-panel p-4 mb-4">
        <h2 class="section-title mb-3">Pending Billing Statements</h2>

        <?php if (empty($unpaidInvoices)): ?>
            <div class="alert alert-success py-4 text-center mb-0 border border-success-subtle" style="background:#f6fbf8; color:#1e9e57;">
                <strong>🎉 All caught up!</strong> You have no pending invoices at this time.
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($unpaidInvoices as $inv):
                    $itemsStmt = db()->prepare('SELECT type, amount, description FROM invoice_items WHERE invoice_id = ?');
                    $itemsStmt->execute([(int)$inv['id']]);
                    $items = $itemsStmt->fetchAll();
                ?>
                    <div class="col-12">
                        <div class="card border border-warning-subtle" style="background: #fffdf9;">
                            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                                <span class="fw-bold">Invoice Cycle: <?= date('M Y', strtotime($inv['billing_date'])) ?> &middot; Reference #<?= (int)$inv['id'] ?></span>
                                <span class="badge bg-warning text-dark">Due Date: <?= date('M d, Y', strtotime($inv['due_date'])) ?></span>
                            </div>
                            <div class="card-body p-4">
                                <div class="row">
                                    <div class="col-md-7">
                                        <h6 class="fw-bold text-dark mb-2">Itemized Invoice Breakdown:</h6>
                                        <table class="table table-sm table-bordered bg-white small mb-0">
                                            <thead>
                                                <tr class="table-light">
                                                    <th>Type</th>
                                                    <th>Description</th>
                                                    <th class="text-end">Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($items as $item): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge bg-secondary"><?= e(str_replace('_', ' ', $item['type'])) ?></span>
                                                        </td>
                                                        <td><?= e($item['description']) ?></td>
                                                        <td class="text-end">₱<?= number_format($item['amount'], 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr class="table-info fw-bold">
                                                    <td colspan="2" class="text-end">Total Unpaid Balance:</td>
                                                    <td class="text-end text-danger" style="font-size:1rem;">₱<?= number_format((float)$inv['total_amount'], 2) ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="col-md-5 d-flex flex-column justify-content-center align-items-center border-start ps-4">
                                        <div class="text-center mb-3">
                                            <p class="text-secondary small mb-1">Payable Amount</p>
                                            <h3 class="fw-bold text-success">₱<?= number_format((float)$inv['total_amount'], 2) ?></h3>
                                        </div>
                                        
                                        <!-- simulated payment button -->
                                        <form method="post" class="w-100">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="pay_invoice">
                                            <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                                            <button type="submit" class="btn btn-warning w-100 py-2 fw-bold text-dark shadow-sm">
                                                💳 Settle Invoice via simulated PayMongo
                                            </button>
                                        </form>
                                        <p class="text-secondary" style="font-size: 0.65rem; margin-top: 6px;">Test gateway executes transaction immediately without bank prompts.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 2. Payment History / receipts -->
    <div class="glass-panel p-4">
        <h2 class="section-title mb-3">Invoice Settlement Receipts</h2>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Payment Date</th>
                        <th scope="col">Reference ID</th>
                        <th scope="col">Unit / Billing Month</th>
                        <th scope="col">Amount Settled</th>
                        <th scope="col">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($receipts): ?>
                        <?php foreach ($receipts as $r): ?>
                            <tr>
                                <td><strong><?= date('M d, Y', strtotime($r['paid_at'])) ?></strong><br><span class="text-secondary" style="font-size: 0.7rem;"><?= date('g:i A', strtotime($r['paid_at'])) ?></span></td>
                                <td><span class="text-monospace">INV-<?= (int)$r['id'] ?></span></td>
                                <td>
                                    <strong><?= e($r['property_title']) ?></strong>
                                    <div class="text-secondary small">Cycle: <?= date('M Y', strtotime($r['billing_date'])) ?></div>
                                </td>
                                <td><strong class="text-success">₱<?= number_format((float)$r['total_amount'], 2) ?></strong></td>
                                <td>
                                    <span class="badge bg-success">Paid / Cleared</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">No cleared invoice records.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
