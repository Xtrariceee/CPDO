<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application = officer_application($applicationId);
    $existing = latest_meeting_for_application($applicationId);
    if ($existing) {
        $stmt = db()->prepare('UPDATE meetings SET minutes = ?, created_by = ? WHERE id = ?');
        $stmt->execute([$_POST['minutes'] ?? '', (int)$user['id'], (int)$existing['id']]);
    } else {
        $stmt = db()->prepare('INSERT INTO meetings (application_id, scheduled_at, minutes, created_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$applicationId, $_POST['meeting_at'] ?: null, $_POST['minutes'] ?? '', (int)$user['id']]);
    }
    advance_application($applicationId, 'DELIBERATION', 11);
    audit_log((int)$user['id'], 'P11_MEETING_DISCUSSIONS_LOGGED', 'applications', $applicationId);
    $_SESSION['flash_success'] = 'Meeting discussions and clarifications logged.';
    redirect('twg/meeting.php?id=' . $applicationId);
}

$applications = officer_applications(['FOR_MEETING']);
$application = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$meeting = $application ? latest_meeting_for_application((int)$application['id']) : null;

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">P11 Meeting Module</h1>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">For Meeting</h2>
            <div class="list-group">
                <?php foreach ($applications as $row): ?>
                    <a class="list-group-item list-group-item-action <?= $application && (int)$application['id'] === (int)$row['id'] ? 'active' : '' ?>" href="meeting.php?id=<?= (int)$row['id'] ?>">
                        <?= e($row['registry_number']) ?><br><small><?= e($row['property_title']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$applications): ?><div class="text-secondary small">No applications are ready for meeting minutes.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($application): ?>
            <form class="gov-card p-4" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                <h2 class="h5"><?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?></h2>
                <label class="form-label">Meeting Schedule</label>
                <input class="form-control mb-3" type="datetime-local" name="meeting_at" value="<?= e($meeting && $meeting['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($meeting['scheduled_at'])) : '') ?>">
                <label class="form-label">Discussions, Clarifications, and Minutes</label>
                <textarea class="form-control mb-3" name="minutes" rows="7" required><?= e($meeting['minutes'] ?? '') ?></textarea>
                <button class="btn btn-primary">Save Meeting Record</button>
            </form>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">No meeting selected.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
