<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

// Rates for utilities calculation
const WATER_RATE_PER_CUBIC = 30.00;
const ELEC_RATE_PER_KWH = 12.50;

// Action 1: Batch Utility Input
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batch_utilities') {
    verify_csrf();

    $billingPeriod = $_POST['billing_period'] ?? date('Y-m-01');
    $readings = $_POST['readings'] ?? []; // Array mapping lease_id -> [water => X, electricity => Y]

    if (empty($readings)) {
        $_SESSION['flash_error'] = 'No readings submitted.';
        redirect('landlord/billing.php');
    }

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $invoicesCreated = 0;
        $itemsAdded = 0;

        foreach ($readings as $leaseId => $metrics) {
            $leaseId = (int)$leaseId;
            $waterQty = (float)($metrics['water'] ?? 0);
            $elecQty = (float)($metrics['electricity'] ?? 0);

            // Skip if no utility consumption recorded
            if ($waterQty <= 0 && $elecQty <= 0) {
                continue;
            }

            // Fetch lease details to get tenant and rent info
            $lStmt = $pdo->prepare('SELECT tenant_id, monthly_rent FROM leases WHERE id = ? AND status = "active"');
            $lStmt->execute([$leaseId]);
            $lease = $lStmt->fetch();
            if (!$lease) {
                continue; // Skip inactive/invalid leases
            }

            $tenantId = (int)$lease['tenant_id'];
            $monthlyRent = (float)$lease['monthly_rent'];

            // Find or create the main invoice for this billing period
            $invStmt = $pdo->prepare('SELECT id FROM invoices WHERE lease_id = ? AND billing_date = ?');
            $invStmt->execute([$leaseId, $billingPeriod]);
            $invoice = $invStmt->fetch();

            if ($invoice) {
                $invoiceId = (int)$invoice['id'];
            } else {
                // Auto-generate the base monthly_rent invoice
                $insInv = $pdo->prepare('INSERT INTO invoices (lease_id, tenant_id, billing_date, due_date, status, created_at) VALUES (?, ?, ?, ?, "unpaid", NOW())');
                // Due date set to 10 days from billing period
                $dueDate = date('Y-m-10', strtotime($billingPeriod));
                $insInv->execute([$leaseId, $tenantId, $billingPeriod, $dueDate]);
                $invoiceId = (int)$pdo->lastInsertId();
                $invoicesCreated++;

                // Add monthly rent as the base invoice item
                $insRentItem = $pdo->prepare('INSERT INTO invoice_items (invoice_id, type, amount, description, created_at) VALUES (?, "monthly_rent", ?, "Base Monthly Rent", NOW())');
                $insRentItem->execute([$invoiceId, $monthlyRent]);
                $itemsAdded++;

                // Fetch any pending offenses for this tenant and attach them to this invoice
                $offStmt = $pdo->prepare('SELECT id, violation_details, fine_amount FROM offenses WHERE tenant_id = ? AND status = "pending_billing"');
                $offStmt->execute([$tenantId]);
                $offenses = $offStmt->fetchAll();

                foreach ($offenses as $off) {
                    $fineAmount = (float)$off['fine_amount'];
                    if ($fineAmount > 0) {
                        // Insert fine as an invoice item
                        $insFineItem = $pdo->prepare('INSERT INTO invoice_items (invoice_id, type, amount, description, created_at) VALUES (?, "offense_fines", ?, ?, NOW())');
                        $desc = "Compliance Fine: " . mb_substr($off['violation_details'], 0, 45) . "...";
                        $insFineItem->execute([$invoiceId, $fineAmount, $desc]);
                        $newBilledItemId = $pdo->lastInsertId();

                        // Mark offense as billed
                        $updOffense = $pdo->prepare('UPDATE offenses SET status = "billed", billed_item_id = ? WHERE id = ?');
                        $updOffense->execute([$newBilledItemId, (int)$off['id']]);
                        $itemsAdded++;
                    }
                }
            }

            // Remove existing utilities items for this invoice to prevent duplication on re-submission
            $delUtils = $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ? AND type = "utilities"');
            $delUtils->execute([$invoiceId]);

            // Add Water Utility Item
            if ($waterQty > 0) {
                $waterCost = $waterQty * WATER_RATE_PER_CUBIC;
                $insWater = $pdo->prepare('INSERT INTO invoice_items (invoice_id, type, amount, description, created_at) VALUES (?, "utilities", ?, ?, NOW())');
                $desc = "Water Utility ({$waterQty} m³ at ₱" . number_format(WATER_RATE_PER_CUBIC, 2) . "/m³)";
                $insWater->execute([$invoiceId, $waterCost, $desc]);
                $itemsAdded++;
            }

            // Add Electricity Utility Item
            if ($elecQty > 0) {
                $elecCost = $elecQty * ELEC_RATE_PER_KWH;
                $insElec = $pdo->prepare('INSERT INTO invoice_items (invoice_id, type, amount, description, created_at) VALUES (?, "utilities", ?, ?, NOW())');
                $desc = "Electricity Utility ({$elecQty} kWh at ₱" . number_format(ELEC_RATE_PER_KWH, 2) . "/kWh)";
                $insElec->execute([$invoiceId, $elecCost, $desc]);
                $itemsAdded++;
            }
        }

        $pdo->commit();
        $_SESSION['flash_success'] = "Utility billing completed! Generated {$invoicesCreated} base bills and itemized {$itemsAdded} line items.";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = 'Failed to record utilities: ' . $e->getMessage();
    }

    redirect('landlord/billing.php');
}

// Action 2: Trigger Recurring Automated Base Invoices (Simulated)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'trigger_recurring') {
    verify_csrf();
    $billingPeriod = date('Y-m-01');
    $dueDate = date('Y-m-10');

    try {
        $pdo = db();
        // Fetch all active leases
        $leasesStmt = $pdo->prepare('SELECT id, tenant_id, monthly_rent FROM leases WHERE status = "active"');
        $leasesStmt->execute();
        $activeLeasesList = $leasesStmt->fetchAll();

        $generated = 0;
        foreach ($activeLeasesList as $l) {
            $leaseId = (int)$l['id'];
            $tenantId = (int)$l['tenant_id'];
            $rent = (float)$l['monthly_rent'];

            // Check if already exists
            $chk = $pdo->prepare('SELECT id FROM invoices WHERE lease_id = ? AND billing_date = ?');
            $chk->execute([$leaseId, $billingPeriod]);
            if ($chk->fetch()) {
                continue; // Skip if already created for this month
            }

            $pdo->beginTransaction();

            $ins = $pdo->prepare('INSERT INTO invoices (lease_id, tenant_id, billing_date, due_date, status) VALUES (?, ?, ?, ?, "unpaid")');
            $ins->execute([$leaseId, $tenantId, $billingPeriod, $dueDate]);
            $invoiceId = $pdo->lastInsertId();

            // Insert base monthly rent item
            $insItem = $pdo->prepare('INSERT INTO invoice_items (invoice_id, type, amount, description) VALUES (?, "monthly_rent", ?, "Base Monthly Rent")');
            $insItem->execute([$invoiceId, $rent]);

            // Append pending fines if any
            $fStmt = $pdo->prepare('SELECT id, violation_details, fine_amount FROM offenses WHERE tenant_id = ? AND status = "pending_billing"');
            $fStmt->execute([$tenantId]);
            $fines = $fStmt->fetchAll();
            foreach ($fines as $f) {
                $fineAmount = (float)$f['fine_amount'];
                if ($fineAmount > 0) {
                    $insFine = $pdo->prepare('INSERT INTO invoice_items (invoice_id, type, amount, description) VALUES (?, "offense_fines", ?, ?)');
                    $desc = "Compliance Fine: " . mb_substr($f['violation_details'], 0, 45) . "...";
                    $insFine->execute([$invoiceId, $fineAmount, $desc]);
                    $billedId = $pdo->lastInsertId();

                    $upd = $pdo->prepare('UPDATE offenses SET status = "billed", billed_item_id = ? WHERE id = ?');
                    $upd->execute([$billedId, (int)$f['id']]);
                }
            }

            $pdo->commit();
            $generated++;
        }

        $_SESSION['flash_success'] = "recurring job successfully triggered! Generated {$generated} monthly invoices.";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = 'Recurring generation failed: ' . $e->getMessage();
    }
    redirect('landlord/billing.php');
}

// Fetch all active leases for batch utility inputs
$activeLeasesStmt = db()->prepare(
    'SELECT l.id AS lease_id, p.title AS property_title, CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name
     FROM leases l
     JOIN properties p ON p.id = l.property_id
     JOIN users u ON u.id = l.tenant_id
     WHERE p.landlord_id = ? AND l.status = "active"'
);
$activeLeasesStmt->execute([(int)$user['id']]);
$activeLeases = $activeLeasesStmt->fetchAll();

// Fetch invoices history ledger
$ledgerStmt = db()->prepare(
    'SELECT i.*, p.title AS property_title, CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name,
            (SELECT SUM(amount) FROM invoice_items WHERE invoice_id = i.id) AS total_amount
     FROM invoices i
     JOIN leases l ON l.id = i.lease_id
     JOIN properties p ON p.id = l.property_id
     JOIN users u ON u.id = i.tenant_id
     WHERE p.landlord_id = ?
     ORDER BY i.billing_date DESC, i.created_at DESC'
);
$ledgerStmt->execute([(int)$user['id']]);
$invoices = $ledgerStmt->fetchAll();

require __DIR__ . '/../partials/header.php';
?>

<div class="container-fluid py-4" style="max-width: 1200px; margin: 0 auto;">
    <!-- Page Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <p class="dash-header-eyebrow mb-1">Landlord Dashboard</p>
            <h1 class="dash-header-title mb-1">Billing & Financials</h1>
            <p class="dash-header-sub">Record utility readings, manage invoice ledgers, and check rent settlements.</p>
        </div>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="trigger_recurring">
            <button type="submit" class="btn btn-outline-warning text-dark fw-bold btn-sm">
                ⚡ Simulate Recurring Base Rent Generation
            </button>
        </form>
    </div>

    <!-- 1. Utility Input Form -->
    <div class="glass-panel p-4 mb-4">
        <h2 class="section-title mb-1">Batch Utility Consumption Input</h2>
        <p class="text-secondary small mb-3">Submit utility readings (water in m³, electricity in kWh) to append calculations directly onto this month's tenant invoices.</p>

        <?php if (empty($activeLeases)): ?>
            <div class="alert alert-secondary py-3 text-center mb-0 small">No active tenants registered. You must have active leases before entering utility metrics.</div>
        <?php else: ?>
            <form method="post" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="batch_utilities">

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Billing Cycle Month</label>
                        <select class="form-select" name="billing_period" required>
                            <option value="<?= date('Y-m-01') ?>">Current Month (<?= date('F Y') ?>)</option>
                            <option value="<?= date('Y-m-01', strtotime('-1 month')) ?>">Last Month (<?= date('F Y', strtotime('-1 month')) ?>)</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table align-middle table-sm small">
                        <thead class="table-light">
                            <tr>
                                <th>Property Unit</th>
                                <th>Resident Name</th>
                                <th>Water consumption (m³)</th>
                                <th>Electricity consumption (kWh)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeLeases as $l): ?>
                                <tr>
                                    <td><strong><?= e($l['property_title']) ?></strong></td>
                                    <td><?= e($l['tenant_name']) ?></td>
                                    <td>
                                        <div class="input-group input-group-sm" style="max-width: 150px;">
                                            <input type="number" step="0.1" class="form-control" name="readings[<?= (int)$l['lease_id'] ?>][water]" placeholder="0.0" min="0">
                                            <span class="input-group-text">m³</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm" style="max-width: 150px;">
                                            <input type="number" step="0.1" class="form-control" name="readings[<?= (int)$l['lease_id'] ?>][electricity]" placeholder="0.0" min="0">
                                            <span class="input-group-text">kWh</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="btn btn-primary btn-sm fw-bold">Calculate and Append Bills</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- 2. Financial Ledger Table -->
    <div class="glass-panel p-4">
        <h2 class="section-title mb-3">Monthly Invoice Ledger</h2>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Billing Date</th>
                        <th scope="col">Unit / Resident</th>
                        <th scope="col">Due Date</th>
                        <th scope="col">Total Bill</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Breakdown</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($invoices): ?>
                        <?php foreach ($invoices as $inv):
                            $badgeClass = ($inv['status'] === 'paid') ? 'bg-success' : 'bg-warning text-dark';
                            $statusLabel = ($inv['status'] === 'paid') ? 'Paid' : 'Unpaid';
                            
                            // Fetch items for this invoice
                            $itemsStmt = db()->prepare('SELECT type, amount, description FROM invoice_items WHERE invoice_id = ?');
                            $itemsStmt->execute([(int)$inv['id']]);
                            $items = $itemsStmt->fetchAll();
                        ?>
                            <tr>
                                <td><strong><?= date('M Y', strtotime($inv['billing_date'])) ?></strong></td>
                                <td>
                                    <strong><?= e($inv['property_title']) ?></strong>
                                    <div class="text-secondary small"><?= e($inv['tenant_name']) ?></div>
                                </td>
                                <td><?= date('M d, Y', strtotime($inv['due_date'])) ?></td>
                                <td><strong>₱<?= number_format((float)$inv['total_amount'], 2) ?></strong></td>
                                <td>
                                    <span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                                    <?php if ($inv['paid_at']): ?>
                                        <div class="text-secondary small" style="font-size: 0.65rem;"><?= date('M d, g:i A', strtotime($inv['paid_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#breakdown-<?= (int)$inv['id'] ?>" aria-expanded="false">
                                        View items
                                    </button>
                                </td>
                            </tr>
                            <tr class="collapse-row">
                                <td colspan="6" class="p-0 border-0">
                                    <div class="collapse bg-light p-3" id="breakdown-<?= (int)$inv['id'] ?>">
                                        <h6 class="fw-bold mb-2">Itemized Charges Breakdown:</h6>
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
                                                    <td colspan="2" class="text-end">Total Amount:</td>
                                                    <td class="text-end">₱<?= number_format((float)$inv['total_amount'], 2) ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">No billing statements found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
