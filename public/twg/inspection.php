<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application = officer_application($applicationId);
    $inspection = latest_inspection_for_application($applicationId);
    if (!$inspection) {
        $_SESSION['flash_error'] = 'Inspection must be scheduled before findings can be entered.';
        redirect('twg/inspection.php?id=' . $applicationId);
    }
    $stmt = db()->prepare('UPDATE inspections SET findings = ?, site_plan_valid = ?, coordinates_valid = ?, finalized_at = NOW() WHERE id = ?');
    $stmt->execute([
        $_POST['findings'] ?? '',
        !empty($_POST['site_plan_valid']) ? 1 : 0,
        !empty($_POST['coordinates_valid']) ? 1 : 0,
        (int)$inspection['id'],
    ]);
    advance_application($applicationId, 'FOR_MEETING', 10);
    audit_log((int)$user['id'], 'P8_P9_INSPECTION_REPORT_ENTERED', 'applications', $applicationId);
    $_SESSION['flash_success'] = 'Inspection findings saved and application moved to consolidation/meeting.';
    redirect('twg/inspection.php?id=' . $applicationId);
}

$applications = officer_applications(['INSPECTION_SCHEDULED']);
$application = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$inspection = $application ? latest_inspection_for_application((int)$application['id']) : null;

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">P8-P9 Inspection Report Entry</h1>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">Scheduled Inspections</h2>
            <div class="list-group">
                <?php foreach ($applications as $row): ?>
                    <a class="list-group-item list-group-item-action <?= $application && (int)$application['id'] === (int)$row['id'] ? 'active' : '' ?>" href="inspection.php?id=<?= (int)$row['id'] ?>">
                        <?= e($row['registry_number']) ?><br><small><?= e($row['property_title']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$applications): ?><div class="text-secondary small">No inspections are currently scheduled.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($application): ?>
            <form class="gov-card p-4" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                <h2 class="h5"><?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?></h2>
                <p class="text-secondary">Scheduled: <?= e($inspection['scheduled_at'] ?? 'No schedule found') ?></p>
                <label class="form-label">Inspection Findings</label>
                <textarea class="form-control mb-3" name="findings" rows="6" required><?= e($inspection['findings'] ?? '') ?></textarea>
                <label class="form-check"><input class="form-check-input" type="checkbox" name="site_plan_valid" <?= !empty($inspection['site_plan_valid']) ? 'checked' : '' ?>> Site plans validated</label>
                <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="coordinates_valid" <?= !empty($inspection['coordinates_valid']) ? 'checked' : '' ?>> Coordinates validated</label>
                <button class="btn btn-primary">Submit Inspection Compliance Report</button>
            </form>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">No inspection selected.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
