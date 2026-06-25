<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

// Fetch all inquiries submitted for properties belonging to this landlord
$stmt = db()->prepare(
    'SELECT ra.*, p.title AS property_title, p.address AS property_address,
            CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name, u.email AS tenant_email
     FROM rental_applications ra
     JOIN properties p ON p.id = ra.property_id
     JOIN users u ON u.id = ra.tenant_id
     WHERE p.landlord_id = ?
     ORDER BY ra.created_at DESC'
);
$stmt->execute([(int)$user['id']]);
$applications = $stmt->fetchAll();

require __DIR__ . '/../partials/header.php';
?>

<style>
.landlord-apps-page {
    min-height: calc(100vh - 64px);
    background: #fff;
    padding: 32px 0 64px;
}

.apps-card {
    background: #fff;
    border: 1px solid #f0dfad;
    border-radius: 18px;
    box-shadow: 0 4px 24px rgba(36,27,11,.08);
}

.table-title {
    font-size: 1.2rem;
    font-weight: 800;
    color: #241b0b;
}

.badge-status {
    font-size: 0.75rem;
    font-weight: 700;
    padding: 5px 10px;
    border-radius: 20px;
}
</style>

<div class="landlord-apps-page">
    <div class="container-fluid" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <a class="btn btn-back btn-sm" href="dashboard.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
            <h1 class="h3 mb-0">Tenant Inquiries</h1>
        </div>

        <div class="apps-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="table-title">Incoming Applications</span>
                <span class="badge text-bg-warning px-3 py-2" style="border-radius:999px;"><?= count($applications) ?> Total</span>
            </div>

            <hr class="mb-4" style="border-color:#f0dfad;">

            <?php if (empty($applications)): ?>
                <div class="text-center py-5 text-secondary">
                    <span style="font-size: 3rem; display: block; margin-bottom: 12px;">✉</span>
                    <h3 class="h6 fw-bold text-dark">No inquiries yet</h3>
                    <p class="small mb-0">Prospective tenants will show up here once they send inquiries for your active properties.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr class="table-light text-secondary small">
                                <th>Property</th>
                                <th>Applicant</th>
                                <th>Monthly Income</th>
                                <th>Occupants</th>
                                <th>Submitted On</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($applications as $app): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= e($app['property_title']) ?></div>
                                        <div class="text-secondary small"><?= e($app['property_address']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($app['tenant_name']) ?></div>
                                        <div class="text-secondary small" style="font-size:0.75rem;"><?= e($app['tenant_email']) ?></div>
                                    </td>
                                    <td class="fw-semibold text-dark">₱<?= number_format((float)$app['monthly_income'], 2) ?></td>
                                    <td><?= (int)$app['occupants_count'] ?> Persons</td>
                                    <td class="text-secondary small"><?= date('M d, Y', strtotime($app['created_at'])) ?></td>
                                    <td>
                                        <?php
                                        $badgeClass = match ($app['status']) {
                                            'PENDING' => 'text-bg-warning',
                                            'ACCEPTED', 'AGREED' => 'text-bg-info text-dark',
                                            'REGISTRY_FILLED' => 'text-bg-primary',
                                            'DRAFT_SENT' => 'text-bg-info text-dark',
                                            'SIGNED' => 'text-bg-success',
                                            'PAID', 'COMPLETED' => 'text-bg-success',
                                            'DECLINED' => 'text-bg-danger',
                                            default => 'text-bg-secondary'
                                        };
                                        ?>
                                        <span class="badge badge-status <?= $badgeClass ?>"><?= e($app['status']) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <a href="application-review.php?id=<?= (int)$app['id'] ?>" class="btn btn-sm btn-outline-warning text-dark fw-bold" style="border-radius: 8px;">Review &amp; Screen</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
