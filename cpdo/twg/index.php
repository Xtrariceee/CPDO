<?php
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);

$inspection = officer_applications(['INSPECTION_SCHEDULED']);
$meeting    = officer_applications(['FOR_MEETING']);

require __DIR__ . '/../partials/header.php';
?>
<h1 class="h3 mb-3">LZRC TWG Member Dashboard</h1>

<style>
/* ── Summary cards ── */
.zo-card{display:block;height:100%;padding:18px 20px;border:1px solid #d0dae6;border-left:4px solid #1d6aad;border-radius:8px;background:#fff;color:#0b2a4a;text-decoration:none;box-shadow:0 2px 4px rgba(11,42,74,.05),0 8px 20px rgba(11,42,74,.07);}
.zo-card:hover{border-color:#b0c4d8;border-left-color:#0b2a4a;color:#0b2a4a;transform:translateY(-2px);box-shadow:0 4px 14px rgba(11,42,74,.10);}
.zo-card-title{font-size:.96rem;font-weight:800;margin-bottom:8px;}
.zo-card-count{font-size:2rem;line-height:1;font-weight:900;}
.zo-card-note{font-size:.78rem;color:#62748a;margin-top:8px;}

/* ── Date/time pill in table ── */
.dt-pill{display:inline-flex;flex-direction:column;gap:1px;}
.dt-date{font-size:.8rem;font-weight:800;color:#0b2a4a;}
.dt-time{font-size:.72rem;font-weight:600;color:#62748a;}
.dt-none{font-size:.8rem;color:#62748a;font-style:italic;}
</style>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <a class="zo-card" href="inspection.php">
            <div class="zo-card-title">Inspection</div>
            <div class="zo-card-count"><?= count($inspection) ?></div>
            <div class="zo-card-note">Applications scheduled for site inspection</div>
        </a>
    </div>
    <div class="col-md-6">
        <a class="zo-card" href="meeting.php">
            <div class="zo-card-title">Meeting</div>
            <div class="zo-card-count"><?= count($meeting) ?></div>
            <div class="zo-card-note">Applications pending TWG meeting</div>
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12">
        <section class="gov-card p-4">
            <h2 class="h5 mb-3">Assigned TWG Tasks</h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Registry</th>
                            <th>Property</th>
                            <th>Status</th>
                            <th>Scheduled</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (officer_applications(['INSPECTION_SCHEDULED','FOR_MEETING']) as $app):
                        // Fetch inspection schedule for this application
                        $inspRow = null;
                        if ($app['phase_status'] === 'INSPECTION_SCHEDULED') {
                            $inspStmt = db()->prepare(
                                'SELECT scheduled_at, scheduled_date, scheduled_time
                                 FROM inspections WHERE application_id = ? ORDER BY id DESC LIMIT 1'
                            );
                            $inspStmt->execute([(int)$app['id']]);
                            $inspRow = $inspStmt->fetch() ?: null;
                        }
                    ?>
                        <tr>
                            <td class="fw-bold small"><?= e($app['registry_number']) ?></td>
                            <td><?= e($app['property_title']) ?></td>
                            <td><?= e(workflow_status_label($app['phase_status'])) ?></td>
                            <td>
                                <?php if ($inspRow && $inspRow['scheduled_at']): ?>
                                    <?php
                                        $ts = strtotime($inspRow['scheduled_at']);
                                        $dispDate = !empty($inspRow['scheduled_date'])
                                            ? date('M j, Y', strtotime($inspRow['scheduled_date']))
                                            : date('M j, Y', $ts);
                                        $dispTime = !empty($inspRow['scheduled_time'])
                                            ? date('h:i A', strtotime($inspRow['scheduled_time']))
                                            : date('h:i A', $ts);
                                    ?>
                                    <div class="dt-pill">
                                        <span class="dt-date"><?= e($dispDate) ?></span>
                                        <span class="dt-time"><?= e($dispTime) ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="dt-none">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($app['phase_status'] === 'INSPECTION_SCHEDULED'): ?>
                                    <a class="btn btn-sm btn-primary" href="inspection.php?id=<?= (int)$app['id'] ?>">Inspect</a>
                                <?php else: ?>
                                    <a class="btn btn-sm btn-primary" href="meeting.php?id=<?= (int)$app['id'] ?>">Meeting</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
