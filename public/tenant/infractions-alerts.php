<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TENANT]);

// Fetch offenses logged for this tenant
$offensesStmt = db()->prepare(
    'SELECT o.*, ii.invoice_id
     FROM offenses o
     LEFT JOIN invoice_items ii ON ii.id = o.billed_item_id
     WHERE o.tenant_id = ?
     ORDER BY o.offense_date DESC'
);
$offensesStmt->execute([(int)$user['id']]);
$offenses = $offensesStmt->fetchAll();

require __DIR__ . '/../partials/header.php';
?>

<div class="container-fluid py-4" style="max-width: 800px; margin: 0 auto;">
    <!-- Page Header -->
    <div class="mb-4">
        <p class="dash-header-eyebrow mb-1">Resident Portal</p>
        <h1 class="dash-header-title mb-1">Infractions & Alerts</h1>
        <p class="dash-header-sub">Compliance alerts, warning reports, and penalty statements logged by management.</p>
    </div>

    <!-- Warnings List -->
    <div class="glass-panel p-4">
        <h2 class="section-title mb-3">Infraction Logs History</h2>

        <?php if (empty($offenses)): ?>
            <div class="alert alert-success py-4 text-center mb-0 border border-success-subtle" style="background:#f6fbf8; color:#1e9e57;">
                <strong>🎉 All compliance guidelines met!</strong> You have no logged infractions or warning notices.
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($offenses as $o):
                    $fineVal = (float)$o['fine_amount'];
                    $hasFine = ($fineVal > 0);
                    $badgeClass = match($o['status']) {
                        'pending_billing' => 'bg-warning text-dark',
                        'billed' => 'bg-info text-dark',
                        'waived' => 'bg-secondary',
                        default => 'bg-secondary'
                    };
                    $statusLabel = match($o['status']) {
                        'pending_billing' => 'Queued for Next Bill',
                        'billed' => 'Added to Invoice #' . (int)$o['invoice_id'],
                        'waived' => 'Waived / Warning Only',
                        default => $o['status']
                    };
                ?>
                    <div class="card <?= $hasFine ? 'border-danger-subtle' : 'border-warning-subtle' ?>" style="background: <?= $hasFine ? '#fffafb' : '#fffdf8' ?>;">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <h5 class="h6 fw-bold mb-0 text-dark">Rule Infraction Warning</h5>
                                    <span class="text-secondary" style="font-size:0.75rem;">Recorded on: <?= date('M d, Y \a\t g:i A', strtotime($o['offense_date'])) ?></span>
                                </div>
                                <?php if ($hasFine): ?>
                                    <div class="text-end">
                                        <span class="badge bg-danger" style="font-size: 0.8rem; padding: 4px 10px;">Fine: ₱<?= number_format($fineVal, 2) ?></span>
                                        <div class="small text-secondary mt-1" style="font-size: 0.65rem;"><?= $statusLabel ?></div>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark" style="font-size:0.7rem; padding: 3px 8px;">Notice / Warning Only</span>
                                <?php endif; ?>
                            </div>
                            
                            <hr class="my-2">
                            
                            <p class="mb-0 small text-secondary" style="line-height: 1.5; font-size: 0.85rem;">
                                <strong>Management Details:</strong><br>
                                <?= nl2br(e($o['violation_details'])) ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
