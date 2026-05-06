<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TENANT]);
verify_csrf();

// Handle upgrade request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_upgrade') {
    $reason = trim($_POST['reason'] ?? '');

    // Check for existing pending request
    $existing = db()->prepare(
        'SELECT id FROM role_upgrade_requests WHERE user_id = ? AND status = "PENDING"'
    );
    $existing->execute([(int)$user['id']]);

    if ($existing->fetch()) {
        $_SESSION['flash_error'] = 'You already have a pending upgrade request. Please wait for admin review.';
    } else {
        $pdo  = db();
        $stmt = $pdo->prepare(
            'INSERT INTO role_upgrade_requests (user_id, from_role, to_role, reason) VALUES (?, "tenant", "landlord", ?)'
        );
        $stmt->execute([(int)$user['id'], $reason ?: null]);
        $requestId = (int)$pdo->lastInsertId();

        audit_log((int)$user['id'], 'ROLE_UPGRADE_REQUESTED', 'role_upgrade_requests', $requestId, [
            'from' => 'tenant',
            'to'   => 'landlord',
        ]);

        // Notify all admins
        notify_role(ROLE_SYSTEM_ADMIN, null,
            'Role Upgrade Request',
            user_full_name($user) . ' (' . $user['email'] . ') has requested to upgrade from Tenant to Landlord.'
        );

        $_SESSION['flash_success'] = 'Your upgrade request has been submitted. An admin will review it shortly.';
    }
    redirect('tenant/dashboard.php');
}

// Check for existing upgrade request
$upgradeRequest = db()->prepare(
    'SELECT * FROM role_upgrade_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
);
$upgradeRequest->execute([(int)$user['id']]);
$upgradeRequest = $upgradeRequest->fetch();

// All active listings
$stmt = db()->prepare(
    'SELECT p.*, CONCAT_WS(" ", u.first_name, u.last_name) AS landlord_name
     FROM properties p
     JOIN users u ON u.id = p.landlord_id
     WHERE p.status = "ACTIVE"
     ORDER BY p.updated_at DESC'
);
$stmt->execute();
$properties = $stmt->fetchAll();

// Stats
$totalListings = count($properties);
$minRent = $properties ? min(array_column($properties, 'monthly_rent')) : 0;
$maxRent = $properties ? max(array_column($properties, 'monthly_rent')) : 0;

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
    <div>
        <p class="eyebrow mb-1">Tenant Portal</p>
        <h1 class="h3 mb-1">Welcome, <?= e($user['first_name']) ?></h1>
        <p class="text-secondary mb-0">Browse CPDO-compliant rental properties available in Davao City.</p>
    </div>
    <div class="d-flex align-items-start">
        <button class="btn btn-outline-primary btn-sm" data-modal-target="upgrade-modal">
            Upgrade to Landlord
        </button>
    </div>
</div>

<!-- Upgrade Request Status Banner -->
<?php if ($upgradeRequest): ?>
    <?php if ($upgradeRequest['status'] === 'PENDING'): ?>
        <div class="alert alert-warning mb-4">
            <strong>Upgrade request pending.</strong> Your request to become a Landlord is under admin review. You'll be notified once a decision is made.
        </div>
    <?php elseif ($upgradeRequest['status'] === 'REJECTED'): ?>
        <div class="alert alert-danger mb-4">
            <strong>Upgrade request rejected.</strong>
            <?php if ($upgradeRequest['admin_notes']): ?>
                Admin note: <?= e($upgradeRequest['admin_notes']) ?>
            <?php endif; ?>
            You may submit a new request.
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Stats row -->
<?php if ($totalListings > 0): ?>
<div class="metric-grid mb-4" style="grid-template-columns:repeat(3,minmax(0,1fr));">
    <article class="metric-card">
        <span>Available Listings</span>
        <strong><?= $totalListings ?></strong>
    </article>
    <article class="metric-card">
        <span>Lowest Rent</span>
        <strong><?= currency_php((float)$minRent) ?></strong>
    </article>
    <article class="metric-card">
        <span>Highest Rent</span>
        <strong><?= currency_php((float)$maxRent) ?></strong>
    </article>
</div>
<?php endif; ?>

<!-- Marketplace Carousel -->
<section class="gov-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h5 mb-0">Available Rentals</h2>
            <p class="text-secondary small mb-0">All listings are from CPDO-verified landlords</p>
        </div>
        <span class="badge text-bg-success"><?= $totalListings ?> active</span>
    </div>

    <?php if (!$properties): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🏘️</div>
            <h3 class="h6">No listings available yet</h3>
            <p>Check back soon — new CPDO-compliant properties are added regularly.</p>
        </div>
    <?php else: ?>
        <div class="listing-carousel-wrap">
            <div class="listing-carousel" id="tenant-market-carousel">
                <?php foreach ($properties as $listing): ?>
                    <article class="listing-card">
                        <div class="listing-card-img listing-card-img--market">
                            <span><?= mb_strtoupper(mb_substr($listing['title'], 0, 2)) ?></span>
                        </div>
                        <div class="listing-card-body">
                            <h3 class="listing-card-title"><?= e($listing['title']) ?></h3>
                            <p class="listing-card-address"><?= e($listing['address']) ?></p>
                            <p class="listing-card-rent"><?= currency_php((float)$listing['monthly_rent']) ?><span>/mo</span></p>
                            <p class="listing-card-landlord">by <?= e($listing['landlord_name']) ?></p>
                        </div>
                        <div class="listing-card-actions">
                            <a class="btn btn-sm btn-primary" href="../landlord/property-view.php?id=<?= (int)$listing['id'] ?>">View Details</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if (count($properties) > 3): ?>
                <button class="carousel-nav carousel-nav--prev" data-carousel="tenant-market-carousel" aria-label="Previous">&#8249;</button>
                <button class="carousel-nav carousel-nav--next" data-carousel="tenant-market-carousel" aria-label="Next">&#8250;</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Grid view of all listings -->
<?php if ($properties): ?>
<section class="gov-card p-4">
    <h2 class="h5 mb-3">All Available Properties</h2>
    <div class="row g-3">
        <?php foreach ($properties as $property): ?>
            <div class="col-md-6 col-lg-4">
                <article class="gov-card p-3 h-100 d-flex flex-column">
                    <div class="listing-card-img listing-card-img--market mb-3" style="height:100px;border-radius:var(--radius-sm);">
                        <span><?= mb_strtoupper(mb_substr($property['title'], 0, 2)) ?></span>
                    </div>
                    <h3 class="h6 mb-1"><?= e($property['title']) ?></h3>
                    <p class="small text-secondary mb-1"><?= e($property['address']) ?></p>
                    <p class="fw-bold text-success mb-1"><?= currency_php((float)$property['monthly_rent']) ?>/mo</p>
                    <p class="small text-secondary mb-3">by <?= e($property['landlord_name']) ?></p>
                    <a class="btn btn-outline-primary btn-sm mt-auto" href="../landlord/property-view.php?id=<?= (int)$property['id'] ?>">View Details</a>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Upgrade to Landlord Modal ── -->
<div class="modal-backdrop" id="upgrade-modal" role="dialog" aria-modal="true" aria-labelledby="upgrade-modal-title">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <h2 class="h5 mb-0" id="upgrade-modal-title">Request Landlord Upgrade</h2>
            <button class="preview-modal-close" data-modal-close aria-label="Close">&times;</button>
        </div>
        <form method="post" class="modal-body">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="request_upgrade">
            <p class="text-secondary small mb-3">
                Submitting this request will notify the admin. They will review your account and change your role to <strong>Landlord</strong> if approved. You do not need to create a new account.
            </p>
            <div class="mb-3">
                <label class="form-label">Reason <span class="text-secondary">(optional)</span></label>
                <textarea class="form-control" name="reason" rows="3" placeholder="Briefly explain why you'd like to become a landlord…"></textarea>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
