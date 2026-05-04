<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TENANT]);

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
</div>

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

<script>
document.querySelectorAll('[data-carousel]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id  = btn.dataset.carousel;
        var el  = document.getElementById(id);
        var dir = btn.classList.contains('carousel-nav--next') ? 1 : -1;
        if (el) el.scrollBy({ left: dir * 320, behavior: 'smooth' });
    });
});
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
