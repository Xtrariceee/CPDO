<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

// Handle Lease Creation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_lease') {
    verify_csrf();
    
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $propertyId = (int)($_POST['property_id'] ?? 0);
    $appId = (int)($_POST['application_id'] ?? 0);
    $rent = (float)($_POST['monthly_rent'] ?? 0.00);
    $deposit = (float)($_POST['security_deposit'] ?? 0.00);
    $advance = (float)($_POST['advance_payment'] ?? 0.00);
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $terms = trim($_POST['terms'] ?? '');

    if (!$tenantId || !$propertyId || !$startDate || !$endDate || $rent <= 0) {
        $_SESSION['flash_error'] = 'All fields are required. Monthly Rent must be greater than zero.';
        redirect('landlord/tenant-registry.php');
    }

    try {
        $pdo = db();
        $pdo->beginTransaction();

        // 1. Insert lease agreement into the new leases table
        $stmt = $pdo->prepare(
            'INSERT INTO leases (tenant_id, property_id, rental_application_id, monthly_rent, security_deposit, advance_payment, start_date, end_date, terms, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending_signature", NOW())'
        );
        $stmt->execute([$tenantId, $propertyId, $appId ?: null, $rent, $deposit, $advance, $startDate, $endDate, $terms ?: null]);
        $leaseId = $pdo->lastInsertId();

        // 2. Also update status of rental application to indicate lease has been drafted/sent
        if ($appId) {
            $updApp = $pdo->prepare('UPDATE rental_applications SET status = "DRAFT_SENT" WHERE id = ?');
            $updApp->execute([$appId]);
        }

        // 3. Notify the Tenant
        $notify = $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, created_at)
             VALUES (?, "New Lease Agreement Pushed", ?, NOW())'
        );
        $propTitle = '';
        $propStmt = $pdo->prepare('SELECT title FROM properties WHERE id = ?');
        $propStmt->execute([$propertyId]);
        $propTitle = $propStmt->fetchColumn() ?: 'Rental Unit';

        $notify->execute([
            $tenantId,
            "Your landlord has sent the lease contract for \"{$propTitle}\". Please navigate to Lease & Documents to sign digitally."
        ]);

        // Audit Log
        audit_log((int)$user['id'], 'LEASE_CREATED_NEXT_PHASE', 'leases', $leaseId, ['tenant_id' => $tenantId, 'property_id' => $propertyId]);

        $pdo->commit();
        $_SESSION['flash_success'] = 'Lease agreement generated successfully and pushed to the tenant.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = 'Failed to generate lease: ' . $e->getMessage();
    }
    
    redirect('landlord/tenant-registry.php');
}

// Fetch active leases (Active residents)
$activeStmt = db()->prepare(
    'SELECT l.*, p.title AS property_title, p.address AS property_address,
            CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name, u.email AS tenant_email, u.phone_number AS tenant_phone
     FROM leases l
     JOIN properties p ON p.id = l.property_id
     JOIN users u ON u.id = l.tenant_id
     WHERE p.landlord_id = ? AND l.status = "active"
     ORDER BY l.start_date DESC'
);
$activeStmt->execute([(int)$user['id']]);
$activeLeases = $activeStmt->fetchAll();

// Fetch pending leases
$pendingStmt = db()->prepare(
    'SELECT l.*, p.title AS property_title, p.address AS property_address,
            CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name, u.email AS tenant_email, u.phone_number AS tenant_phone
     FROM leases l
     JOIN properties p ON p.id = l.property_id
     JOIN users u ON u.id = l.tenant_id
     WHERE p.landlord_id = ? AND l.status != "active"
     ORDER BY l.created_at DESC'
);
$pendingStmt->execute([(int)$user['id']]);
$pendingLeases = $pendingStmt->fetchAll();

// Fetch approved applicants without any lease draft yet
$needLeaseStmt = db()->prepare(
    'SELECT ra.id AS application_id, ra.tenant_id, ra.property_id, p.title AS property_title, p.monthly_rent AS property_rent,
            CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name, u.email AS tenant_email, u.phone_number AS tenant_phone
     FROM rental_applications ra
     JOIN properties p ON p.id = ra.property_id
     JOIN users u ON u.id = ra.tenant_id
     LEFT JOIN leases l ON l.rental_application_id = ra.id
     WHERE p.landlord_id = ? AND (ra.status = "ACCEPTED" OR ra.status = "AGREED" OR ra.status = "REGISTRY_FILLED") AND l.id IS NULL
     ORDER BY ra.updated_at DESC'
);
$needLeaseStmt->execute([(int)$user['id']]);
$needsLease = $needLeaseStmt->fetchAll();

require __DIR__ . '/../partials/header.php';
?>

<div class="container-fluid py-4" style="max-width: 1200px; margin: 0 auto;">
    <!-- Page Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <p class="dash-header-eyebrow mb-1">Landlord Dashboard</p>
            <h1 class="dash-header-title mb-1">Tenant Registry & Leases</h1>
            <p class="dash-header-sub">Manage your resident directory and active lease agreements.</p>
        </div>
    </div>

    <!-- 1. Approved Applicants Awaiting Lease -->
    <?php if (!empty($needsLease)): ?>
        <div class="glass-panel p-4 mb-4 border border-warning-subtle" style="background: #fffdf5;">
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge bg-warning text-dark" style="font-size: 0.8rem; padding: 5px 10px;">Action Required</span>
                <h2 class="section-title mb-0" style="border: none; padding: 0;">Approved Applicants Awaiting Lease Contracts</h2>
            </div>
            <div class="table-responsive">
                <table class="table align-middle small mb-0">
                    <thead>
                        <tr class="table-light">
                            <th>Applicant</th>
                            <th>Property Unit</th>
                            <th>Contact Details</th>
                            <th class="text-end">Lease Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($needsLease as $app): ?>
                            <tr>
                                <td><strong><?= e($app['tenant_name']) ?></strong></td>
                                <td><?= e($app['property_title']) ?></td>
                                <td><?= e($app['tenant_email']) ?> &middot; <?= e($app['tenant_phone']) ?></td>
                                class="text-end"
                                <td class="text-end">
                                    <button type="button" class="btn btn-warning btn-sm fw-bold text-dark" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#leaseModal"
                                            data-tenant-id="<?= (int)$app['tenant_id'] ?>"
                                            data-tenant-name="<?= e($app['tenant_name']) ?>"
                                            data-property-id="<?= (int)$app['property_id'] ?>"
                                            data-property-title="<?= e($app['property_title']) ?>"
                                            data-property-rent="<?= (float)$app['property_rent'] ?>"
                                            data-app-id="<?= (int)$app['application_id'] ?>">
                                        Create Lease
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- 2. Active Tenant Registry -->
    <div class="glass-panel p-4 mb-4">
        <h2 class="section-title mb-3">Active Residents Directory</h2>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Resident Name</th>
                        <th scope="col">Property Unit</th>
                        <th scope="col">Rent & Terms</th>
                        <th scope="col">Duration</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($activeLeases): ?>
                        <?php foreach ($activeLeases as $l): ?>
                            <tr>
                                <td>
                                    <strong><?= e($l['tenant_name']) ?></strong>
                                    <div class="text-secondary small"><?= e($l['tenant_email']) ?> &middot; <?= e($l['tenant_phone']) ?></div>
                                </td>
                                <td>
                                    <strong><?= e($l['property_title']) ?></strong>
                                    <div class="text-secondary small"><?= e($l['property_address']) ?></div>
                                </td>
                                <td>
                                    <strong>₱<?= number_format($l['monthly_rent'], 2) ?>/mo</strong>
                                    <div class="text-secondary small">Dep: ₱<?= number_format($l['security_deposit'], 0) ?> &middot; Adv: ₱<?= number_format($l['advance_payment'], 0) ?></div>
                                </td>
                                <td>
                                    <span class="small"><?= date('M d, Y', strtotime($l['start_date'])) ?></span> to
                                    <span class="small"><?= date('M d, Y', strtotime($l['end_date'])) ?></span>
                                </td>
                                <td><span class="badge bg-success">Active Resident</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">No active tenants registered yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 3. Pending Lease Agreements -->
    <div class="glass-panel p-4">
        <h2 class="section-title mb-3">Lease Agreements Pipeline (Drafts & Pending Signatures)</h2>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Tenant Name</th>
                        <th scope="col">Property Unit</th>
                        <th scope="col">Monthly Rent</th>
                        <th scope="col">Duration</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pendingLeases): ?>
                        <?php foreach ($pendingLeases as $l):
                            $badgeClass = match($l['status']) {
                                'draft' => 'bg-secondary',
                                'pending_signature' => 'bg-info text-dark',
                                'awaiting_initial_payment' => 'bg-warning text-dark',
                                default => 'bg-secondary'
                            };
                            $statusLabel = match($l['status']) {
                                'draft' => 'Draft',
                                'pending_signature' => 'Awaiting Signature',
                                'awaiting_initial_payment' => 'Awaiting Initial Payment',
                                default => $l['status']
                            };
                        ?>
                            <tr>
                                <td>
                                    <strong><?= e($l['tenant_name']) ?></strong>
                                    <div class="text-secondary small"><?= e($l['tenant_email']) ?> &middot; <?= e($l['tenant_phone']) ?></div>
                                </td>
                                <td><strong><?= e($l['property_title']) ?></strong></td>
                                <td><strong>₱<?= number_format($l['monthly_rent'], 2) ?>/mo</strong></td>
                                <td>
                                    <span class="small"><?= date('M d, Y', strtotime($l['start_date'])) ?></span> to
                                    <span class="small"><?= date('M d, Y', strtotime($l['end_date'])) ?></span>
                                </td>
                                <td><span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">No pending lease agreements.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Lease Creation Modal -->
<div class="modal fade" id="leaseModal" tabindex="-1" aria-labelledby="leaseModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_lease">
            <input type="hidden" name="tenant_id" id="modalTenantId">
            <input type="hidden" name="property_id" id="modalPropertyId">
            <input type="hidden" name="application_id" id="modalAppId">

            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="leaseModalTitle">Create Lease Agreement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Tenant Name</label>
                    <input type="text" class="form-control-plaintext bg-light px-2" id="modalTenantName" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Property Unit</label>
                    <input type="text" class="form-control-plaintext bg-light px-2" id="modalPropertyTitle" readonly>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">Monthly Rent (₱) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="monthly_rent" id="modalPropertyRent" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">Security Deposit (₱)</label>
                        <input type="number" step="0.01" class="form-control" name="security_deposit" value="0.00">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Advance Rent Payment (₱)</label>
                    <input type="number" step="0.01" class="form-control" name="advance_payment" value="0.00">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold">Lease Start Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="start_date" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">Lease End Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="end_date" required value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Additional Terms & Conditions</label>
                    <textarea class="form-control" name="terms" rows="3" placeholder="Specify utilities arrangement, noise rules, pet limits, etc..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm fw-bold">Push to Tenant</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var leaseModal = document.getElementById('leaseModal');
    if (leaseModal) {
        leaseModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var tenantId = button.getAttribute('data-tenant-id');
            var tenantName = button.getAttribute('data-tenant-name');
            var propertyId = button.getAttribute('data-property-id');
            var propertyTitle = button.getAttribute('data-property-title');
            var propertyRent = button.getAttribute('data-property-rent');
            var appId = button.getAttribute('data-app-id');

            document.getElementById('modalTenantId').value = tenantId;
            document.getElementById('modalTenantName').value = tenantName;
            document.getElementById('modalPropertyId').value = propertyId;
            document.getElementById('modalPropertyTitle').value = propertyTitle;
            document.getElementById('modalPropertyRent').value = propertyRent;
            document.getElementById('modalAppId').value = appId;
        });
    }
});
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
