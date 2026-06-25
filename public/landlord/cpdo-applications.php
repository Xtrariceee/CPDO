<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

// Fetch CPDO Applications
$appsStmt = db()->prepare('SELECT * FROM applications WHERE landlord_id = ? ORDER BY updated_at DESC');
$appsStmt->execute([(int)$user['id']]);
$applications = $appsStmt->fetchAll();

// Map status to a Bootstrap badge class
function app_badge_class(string $phase): string
{
    return match ($phase) {
        'APPROVED'                          => 'bg-success',
        'DISAPPROVED'                       => 'bg-danger',
        'PAYMENT_PENDING', 'FOR_MEETING',
        'DELIBERATION', 'DEFERRED'          => 'bg-warning',
        'DRAFT', 'SUBMITTED'                => 'bg-secondary',
        'PRE_EVALUATION', 'PAID',
        'INSPECTION_SCHEDULED',
        'INSPECTION_DONE'                   => 'bg-info',
        default                             => 'bg-secondary',
    };
}

// Human-readable phase label
function app_phase_label(string $phase): string
{
    return match ($phase) {
        'DRAFT'                => 'Draft',
        'SUBMITTED'            => 'Submitted',
        'PRE_EVALUATION'       => 'Pre-Evaluation',
        'PAYMENT_PENDING'      => 'Payment Pending',
        'PAID'                 => 'Paid',
        'INSPECTION_SCHEDULED' => 'Inspection Scheduled',
        'INSPECTION_DONE'      => 'Inspection Done',
        'FOR_MEETING'          => 'For Meeting',
        'DELIBERATION'         => 'Deliberation',
        'APPROVED'             => 'Approved',
        'DISAPPROVED'          => 'Disapproved',
        'DEFERRED'             => 'Deferred',
        default                => $phase,
    };
}

require __DIR__ . '/../partials/header.php';
?>

<div class="glass-panel cpdo-app-panel p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="section-title mb-0">CPDO Applications</h2>
            <p class="section-sub mt-1">Track land reclassification and rezoning submissions</p>
        </div>
        <a class="btn-glass-primary" href="application-form.php">New Application</a>
    </div>

    <hr class="glass-divider mb-0">

    <div class="glass-table-wrap table-responsive">
        <table class="glass-table table align-middle" aria-label="CPDO Applications">
            <thead>
                <tr>
                    <th scope="col">Registry / ID</th>
                    <th scope="col">Property Address</th>
                    <th scope="col">Process Type</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($applications): ?>
                <?php foreach ($applications as $app):
                    $badgeClass = app_badge_class($app['phase_status']);
                    $phaseLabel = app_phase_label($app['phase_status']);
                    $openUrl    = match (true) {
                        in_array($app['phase_status'], ['DRAFT', 'SUBMITTED'], true)
                            && $app['current_process'] <= 2
                            => 'requirements-upload.php?id=' . (int)$app['id'],
                        default => 'application-show.php?id=' . (int)$app['id'],
                    };
                    $openLabel = $app['phase_status'] === 'DRAFT' ? 'Continue' : 'Open';
                ?>
                <tr>
                    <td><span class="registry-id"><?= e($app['registry_number']) ?></span></td>
                    <td><?= e($app['property_title']) ?></td>
                    <td>
                        <span class="process-type">
                            <?= $app['current_process'] <= 1 ? 'Land Reclassification' : 'Rezoning (P' . (int)$app['current_process'] . ')' ?>
                        </span>
                    </td>
                    <td><span class="badge <?= e($badgeClass) ?>"><?= e($phaseLabel) ?></span></td>
                    <td class="text-end">
                        <a class="btn btn-sm" href="<?= e($openUrl) ?>"><?= e($openLabel) ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr class="empty-row">
                    <td colspan="5">No applications started yet.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
