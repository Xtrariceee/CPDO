<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);
verify_csrf();

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application = officer_application($applicationId);
    $decision    = $_POST['vote'] ?? '';
    if (!in_array($decision, ['APPROVED','DISAPPROVED','DEFERRED'], true)) {
        $_SESSION['flash_error'] = 'Invalid decision.';
        redirect('twg/voting.php?id=' . $applicationId);
    }
    $stmt = db()->prepare('INSERT INTO votes (application_id, twg_member_id, vote, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE vote=VALUES(vote), notes=VALUES(notes)');
    $stmt->execute([$applicationId, (int)$user['id'], $decision, $_POST['vote_notes'] ?? null]);
    advance_application($applicationId, $decision === 'APPROVED' ? 'DELIBERATION' : $decision, $decision === 'APPROVED' ? 13 : 12);
    notify_user((int)$application['landlord_id'], $applicationId, 'CPDO decision recorded', 'The committee decision has been recorded: ' . $decision . '.');
    audit_log((int)$user['id'], 'P12_DECISION_SUBMITTED', 'applications', $applicationId, ['decision' => $decision]);
    $_SESSION['flash_success'] = 'Decision submitted.';
    redirect('twg/voting.php?id=' . $applicationId);
}

$applications = officer_applications(['DELIBERATION']);
$application  = $applicationId ? officer_application($applicationId) : ($applications[0] ?? null);
$votes        = [];
if ($application) {
    $stmt = db()->prepare('SELECT v.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS name FROM votes v JOIN users u ON u.id=v.twg_member_id WHERE v.application_id=? ORDER BY v.created_at DESC');
    $stmt->execute([(int)$application['id']]);
    $votes = $stmt->fetchAll();
}

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">Voting System</h1>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="gov-card p-3">
            <h2 class="h6">Under Deliberation</h2>
            <div class="list-group">
                <?php foreach ($applications as $row): ?>
                    <a class="list-group-item list-group-item-action <?= $application && (int)$application['id']===(int)$row['id']?'active':'' ?>" href="voting.php?id=<?= (int)$row['id'] ?>">
                        <?= e($row['registry_number']) ?><br><small><?= e($row['property_title']) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$applications): ?><div class="text-secondary small">No applications under deliberation.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($application): ?>
            <form class="gov-card p-4 mb-4" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                <h2 class="h5"><?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?></h2>
                <label class="form-label">Decision</label>
                <select class="form-select mb-3" name="vote">
                    <option value="APPROVED">Approved</option>
                    <option value="DISAPPROVED">Disapproved</option>
                    <option value="DEFERRED">Deferred</option>
                </select>
                <label class="form-label">Decision Notes</label>
                <textarea class="form-control mb-3" name="vote_notes" rows="4"></textarea>
                <button class="btn btn-primary">Submit Decision</button>
            </form>
            <section class="gov-card p-4">
                <h2 class="h5">Recorded Decisions</h2>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>TWG Member</th><th>Vote</th><th>Notes</th></tr></thead>
                        <tbody>
                        <?php foreach ($votes as $vote): ?>
                            <tr><td><?= e($vote['name']) ?></td><td><?= e($vote['vote']) ?></td><td><?= e($vote['notes']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$votes): ?><tr><td colspan="3" class="text-secondary">No decisions recorded yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php else: ?>
            <div class="gov-card p-5 text-center text-secondary">No application selected for voting.</div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
