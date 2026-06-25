<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

// Handle Infraction Submission POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_infraction') {
    verify_csrf();

    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $details = trim($_POST['violation_details'] ?? '');
    $fine = (float)($_POST['fine_amount'] ?? 0.00);
    $date = $_POST['offense_date'] ?? date('Y-m-d H:i:s');

    if (!$tenantId || empty($details)) {
        $_SESSION['flash_error'] = 'Tenant name and infraction details description are required.';
        redirect('landlord/compliance-log.php');
    }

    try {
        $pdo = db();
        $pdo->beginTransaction();

        // 1. Insert into offenses table
        $status = ($fine > 0) ? 'pending_billing' : 'billed'; // If no fine, mark as billed (historical only)
        $ins = $pdo->prepare(
            'INSERT INTO offenses (tenant_id, violation_details, fine_amount, offense_date, status, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute([$tenantId, $details, $fine > 0 ? $fine : null, $date, $status]);
        $offenseId = $pdo->lastInsertId();

        // 2. Notify the Tenant with warning alert and push notification
        $insNotif = $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, created_at)
             VALUES (?, "Compliance Alert: Infraction Logged", ?, NOW())'
        );
        $fineMsg = ($fine > 0) ? " A fine of ₱" . number_format($fine, 2) . " has been issued and will be added to your next monthly bill." : "";
        $msgText = "A compliance rule violation was logged on your account. Details: \"{$details}\".{$fineMsg}";
        $insNotif->execute([$tenantId, $msgText]);

        // Audit Log
        audit_log((int)$user['id'], 'COMPLIANCE_INFRACTION_LOGGED', 'offenses', $offenseId, ['tenant_id' => $tenantId, 'fine_amount' => $fine]);

        $pdo->commit();
        $_SESSION['flash_success'] = 'Infraction successfully logged. Tenant has been notified.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = 'Failed to log infraction: ' . $e->getMessage();
    }

    redirect('landlord/compliance-log.php');
}

// Fetch active tenants for the dropdown selection
$tenantsStmt = db()->prepare(
    'SELECT DISTINCT u.id AS tenant_id, CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name, p.title AS property_title
     FROM leases l
     JOIN properties p ON p.id = l.property_id
     JOIN users u ON u.id = l.tenant_id
     WHERE p.landlord_id = ? AND l.status = "active"'
);
$tenantsStmt->execute([(int)$user['id']]);
$tenants = $tenantsStmt->fetchAll();

// Fetch infraction history log
$offensesStmt = db()->prepare(
    'SELECT o.*, CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name, p.title AS property_title
     FROM offenses o
     JOIN users u ON u.id = o.tenant_id
     LEFT JOIN leases l ON l.tenant_id = o.tenant_id AND l.status = "active"
     LEFT JOIN properties p ON p.id = l.property_id
     WHERE p.landlord_id = ? OR p.landlord_id IS NULL -- show all logged offenses matching landlord properties
     ORDER BY o.offense_date DESC'
);
// Filter by landlord
$offensesStmt->execute([(int)$user['id']]);
$offenses = $offensesStmt->fetchAll();

require __DIR__ . '/../partials/header.php';
?>

<div class="container-fluid py-4" style="max-width: 1200px; margin: 0 auto;">
    <!-- Page Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <p class="dash-header-eyebrow mb-1">Landlord Dashboard</p>
            <h1 class="dash-header-title mb-1">Compliance Log</h1>
            <p class="dash-header-sub">Report household/incidents rule violations and manage penalty fines.</p>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left: Incident Logging Form -->
        <div class="col-lg-5">
            <div class="glass-panel p-4 h-100">
                <h2 class="section-title mb-3">Record Rule Violation</h2>
                <form method="post" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="log_infraction">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Resident Tenant <span class="text-danger">*</span></label>
                        <select class="form-select" name="tenant_id" required>
                            <option value="">Choose a resident...</option>
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= (int)$t['tenant_id'] ?>"><?= e($t['tenant_name']) ?> (<?= e($t['property_title']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select a tenant.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Date & Time of Incident <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" name="offense_date" required value="<?= date('Y-m-d\TH:i') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Levy Penalty Fine (₱) <span class="text-secondary">(optional)</span></label>
                        <input type="number" step="0.01" class="form-control" name="fine_amount" placeholder="0.00" min="0">
                        <div class="form-text text-muted">Fines will automatically merge into the tenant's next recurring monthly bill.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Violation Details & Description <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="violation_details" rows="5" required placeholder="Describe the compliance incident, listing warning notice dates or property damage descriptions..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Submit Infraction & Notify Resident</button>
                </form>
            </div>
        </div>

        <!-- Right: Incident Logs History -->
        <div class="col-lg-7">
            <div class="glass-panel p-4 h-100">
                <h2 class="section-title mb-3">Infraction Logs History</h2>
                <div class="table-responsive">
                    <table class="table align-middle small">
                        <thead>
                            <tr class="table-light">
                                <th>Incident Date</th>
                                <th>Tenant</th>
                                <th>Description</th>
                                <th>Fine Issued</th>
                                <th>Billing Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($offenses): ?>
                                <?php foreach ($offenses as $o):
                                    $fineVal = (float)$o['fine_amount'];
                                    $badgeClass = match($o['status']) {
                                        'pending_billing' => 'bg-warning text-dark',
                                        'billed' => 'bg-info text-dark',
                                        'waived' => 'bg-secondary',
                                        default => 'bg-secondary'
                                    };
                                    $statusLabel = match($o['status']) {
                                        'pending_billing' => 'Queued for Billing',
                                        'billed' => 'Billed / Resolved',
                                        'waived' => 'Waived',
                                        default => $o['status']
                                    };
                                ?>
                                    <tr>
                                        <td><strong><?= date('M d, Y', strtotime($o['offense_date'])) ?></strong><br><span class="text-secondary" style="font-size:0.7rem;"><?= date('g:i A', strtotime($o['offense_date'])) ?></span></td>
                                        <td>
                                            <strong><?= e($o['tenant_name']) ?></strong>
                                            <div class="text-secondary" style="font-size:0.7rem;"><?= e($o['property_title'] ?: 'Inactive Lease') ?></div>
                                        </td>
                                        <td>
                                            <div style="max-width: 200px; max-height: 80px; overflow-y: auto;" class="text-wrap">
                                                <?= nl2br(e($o['violation_details'])) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($fineVal > 0): ?>
                                                <strong class="text-danger">₱<?= number_format($fineVal, 2) ?></strong>
                                            <?php else: ?>
                                                <span class="text-muted italic">No Fine</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($fineVal > 0): ?>
                                                <span class="badge <?= $badgeClass ?>" style="font-size:0.65rem;"><?= $statusLabel ?></span>
                                            <?php else: ?>
                                                <span class="text-secondary small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-secondary py-4">No compliance offenses logged.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
