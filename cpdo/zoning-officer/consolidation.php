<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application = officer_application($applicationId);
    $meetingAt   = trim($_POST['meeting_at'] ?? '');
    $minutes     = trim($_POST['consolidation_notes'] ?? '');
    $stmt = db()->prepare('INSERT INTO meetings (application_id, scheduled_at, minutes, created_by) VALUES (?, ?, ?, ?)');
    $stmt->execute([$applicationId, $meetingAt ?: null, $minutes, (int)$user['id']]);
    advance_application($applicationId, 'FOR_MEETING', 10);
    audit_log((int)$user['id'], 'P10_INSPECTION_CONSOLIDATED_MEETING_SCHEDULED', 'applications', $applicationId, ['meeting_at' => $meetingAt]);
    $_SESSION['flash_success'] = 'Inspection report consolidated and formal meeting scheduled.';
    redirect('zoning-officer/consolidation.php?id=' . $applicationId);
}

$applications = officer_applications(['FOR_MEETING']);
$application  = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$inspection   = $application ? latest_inspection_for_application((int)$application['id']) : null;

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">Inspection Consolidation</h1>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">Applications for Consolidation</h2>
            <div class="list-group">
                <?php foreach ($applications as $row): ?>
                    <a class="list-group-item list-group-item-action <?= $application && (int)$application['id']===(int)$row['id']?'active':'' ?>" href="consolidation.php?id=<?= (int)$row['id'] ?>">
                        <?= e($row['registry_number']) ?><br><small><?= e($row['property_title']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$applications): ?><div class="text-secondary small">No inspection reports awaiting consolidation.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($application): ?>
            <form class="gov-card p-4" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                <h2 class="h5"><?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?></h2>
                <div class="alert alert-light border">
                    <strong>TWG Findings:</strong>
                    <p class="mb-1"><?= nl2br(e($inspection['findings'] ?? 'No inspection report submitted yet.')) ?></p>
                    <span class="badge <?= !empty($inspection['site_plan_valid'])?'text-bg-success':'text-bg-warning' ?>">Site Plan <?= !empty($inspection['site_plan_valid'])?'Valid':'Pending' ?></span>
                    <span class="badge <?= !empty($inspection['coordinates_valid'])?'text-bg-success':'text-bg-warning' ?>">Coordinates <?= !empty($inspection['coordinates_valid'])?'Valid':'Pending' ?></span>
                </div>
                <label class="form-label">Consolidation Notes</label>
                <textarea class="form-control mb-3" name="consolidation_notes" rows="5" required></textarea>
                <label class="form-label">Formal Meeting Schedule</label>
                <input class="form-control mb-3" type="datetime-local" name="meeting_at" required>
                <button class="btn btn-primary">Finalize Compliance Report and Schedule Meeting</button>
            </form>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">No applications ready for consolidation.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
