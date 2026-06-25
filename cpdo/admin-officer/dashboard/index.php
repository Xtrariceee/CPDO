<?php
require_once __DIR__ . '/../../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);

$forResolution = officer_applications(['DELIBERATION']);

/* clearance requests pending review */
$clrPending = 0;
try {
    $clrPending = (int)db()->query('SELECT COUNT(*) FROM clearance_requests WHERE status="PENDING"')->fetchColumn();
} catch (Throwable $_) {}

require __DIR__ . '/../../partials/header.php';
?>
<h1 class="h3 mb-3">Administrative Officer Dashboard</h1>
<style>
.zo-card {
    display: block;
    height: 100%;
    padding: 18px 20px;
    border: 1px solid #d0dae6;
    border-left: 4px solid #1d6aad;
    border-radius: 8px;
    background: #fff;
    color: #0b2a4a;
    text-decoration: none;
    box-shadow: 0 2px 4px rgba(11,42,74,.05), 0 8px 20px rgba(11,42,74,.07);
}
.zo-card:hover {
    border-color: #b0c4d8;
    border-left-color: #0b2a4a;
    color: #0b2a4a;
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(11,42,74,.10);
}
.zo-card-title {
    font-size: .96rem;
    font-weight: 800;
    margin-bottom: 8px;
}
.zo-card-count {
    font-size: 2rem;
    line-height: 1;
    font-weight: 900;
}
.zo-card-note {
    font-size: .78rem;
    color: #62748a;
    margin-top: 8px;
}
</style>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <a class="zo-card" href="../final-output.php">
            <div class="zo-card-title">Validate Resolution &amp; Endorsement</div>
            <div class="zo-card-count"><?= count($forResolution) ?></div>
            <div class="zo-card-note">Applications deliberated — generate endorsement &amp; notify landlord</div>
        </a>
    </div>
    <div class="col-md-6">
        <a class="zo-card" href="../clearance-review.php">
            <div class="zo-card-title">Clearance &amp; Certification Requests</div>
            <div class="zo-card-count"><?= $clrPending ?></div>
            <div class="zo-card-note">Business clearance requests awaiting review &amp; certificate issuance</div>
        </a>
    </div>
</div>
<section class="gov-card p-4">
    <h2 class="h5">Pending Administrative Actions</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Type</th><th>Record</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($forResolution as $app): ?>
                <tr>
                    <td>CPDO Application</td>
                    <td><?= e($app['registry_number']) ?> · <?= e($app['property_title']) ?></td>
                    <td><?= e(workflow_status_label($app['phase_status'])) ?></td>
                    <td><a class="btn btn-sm btn-primary" href="../final-output.php?id=<?= (int)$app['id'] ?>">Validate &amp; Endorse</a></td>
                </tr>
            <?php endforeach; ?>
            <?php
            /* clearance requests */
            try {
                $clrRows = db()->query(
                    'SELECT cr.id, cr.title, cr.status,
                            CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name
                     FROM clearance_requests cr
                     JOIN users u ON u.id = cr.landlord_id
                     WHERE cr.status = "PENDING"
                     ORDER BY cr.created_at ASC LIMIT 20'
                )->fetchAll();
                foreach ($clrRows as $cr): ?>
                    <tr>
                        <td>Clearance Request</td>
                        <td><?= e($cr['title']) ?> &middot; <?= e($cr['landlord_name']) ?></td>
                        <td><span class="badge text-bg-warning"><?= e($cr['status']) ?></span></td>
                        <td><a class="btn btn-sm btn-primary" href="../clearance-review.php?filter=PENDING">Review</a></td>
                    </tr>
                <?php endforeach;
            } catch (Throwable $_) {}
            ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../partials/footer.php'; ?>
