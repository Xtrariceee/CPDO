<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

$status = landlord_compliance_status((int)$user['id']);

// Own properties
$propertiesStmt = db()->prepare('SELECT * FROM properties WHERE landlord_id = ? ORDER BY updated_at DESC');
$propertiesStmt->execute([(int)$user['id']]);
$properties = $propertiesStmt->fetchAll();

// Recent applications
$appsStmt = db()->prepare('SELECT * FROM applications WHERE landlord_id = ? ORDER BY updated_at DESC LIMIT 10');
$appsStmt->execute([(int)$user['id']]);
$applications = $appsStmt->fetchAll();

// Public marketplace listings (other landlords, ACTIVE only)
$marketStmt = db()->prepare(
    'SELECT p.*, CONCAT_WS(" ", u.first_name, u.last_name) AS landlord_name
     FROM properties p
     JOIN users u ON u.id = p.landlord_id
     WHERE p.status = "ACTIVE" AND p.landlord_id != ?
     ORDER BY p.updated_at DESC
     LIMIT 12'
);
$marketStmt->execute([(int)$user['id']]);
$marketListings = $marketStmt->fetchAll();

$bannerClass = match ($status['state']) {
    'ELIGIBLE'     => 'status-eligible',
    'UNDER_REVIEW' => 'status-review',
    default        => 'status-required',
};

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
    <div>
        <p class="eyebrow mb-1">Landlord Portal</p>
        <h1 class="h3 mb-1">Welcome, <?= e($user['first_name']) ?></h1>
        <p class="text-secondary mb-0">Manage CPDO compliance and your rental property listings.</p>
    </div>
    <a class="btn btn-primary align-self-md-start" href="compliance-gateway.php">+ Add Listing</a>
</div>

<!-- Compliance status banner -->
<section class="status-banner <?= e($bannerClass) ?> mb-4">
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
        <div>
            <h2 class="h5 mb-1"><?= e($status['label']) ?></h2>
            <p class="mb-0">
                <?php if ($status['state'] === 'ELIGIBLE'): ?>
                    Your compliance record is approved or verified. Property listing is enabled.
                <?php elseif ($status['state'] === 'UNDER_REVIEW'): ?>
                    Your submission is being reviewed. Listing remains locked until approval or verification.
                <?php else: ?>
                    You must complete land reclassification/rezoning before listing a property.
                <?php endif; ?>
            </p>
        </div>
        <span class="badge text-bg-light align-self-start classification-badge">DLP: Confidential</span>
    </div>
</section>

<!-- My Property Listings -->
<section class="gov-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">My Property Listings</h2>
        <a class="btn btn-outline-primary btn-sm" href="compliance-gateway.php">+ Add Listing</a>
    </div>
    <?php if (!$properties): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🏠</div>
            <h3 class="h6">No listings yet</h3>
            <p>Approved CPDO workflow or verified legal documents are required before listing.</p>
            <a class="btn btn-primary btn-sm" href="compliance-gateway.php">Get Started</a>
        </div>
    <?php else: ?>
        <div class="listing-carousel-wrap">
            <div class="listing-carousel" id="my-listings-carousel">
                <?php foreach ($properties as $property): ?>
                    <article class="listing-card">
                        <div class="listing-card-img listing-card-img--own">
                            <span><?= mb_strtoupper(mb_substr($property['title'], 0, 2)) ?></span>
                        </div>
                        <div class="listing-card-body">
                            <h3 class="listing-card-title"><?= e($property['title']) ?></h3>
                            <p class="listing-card-address"><?= e($property['address']) ?></p>
                            <p class="listing-card-rent"><?= currency_php((float)$property['monthly_rent']) ?><span>/mo</span></p>
                            <span class="listing-badge listing-badge--<?= strtolower($property['status']) ?>">
                                <?= e($property['status']) ?>
                            </span>
                        </div>
                        <div class="listing-card-actions">
                            <a class="btn btn-sm btn-outline-primary" href="property-view.php?id=<?= (int)$property['id'] ?>">View</a>
                            <a class="btn btn-sm btn-outline-secondary" href="property-form.php?id=<?= (int)$property['id'] ?>">Edit</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if (count($properties) > 3): ?>
                <button class="carousel-nav carousel-nav--prev" data-carousel="my-listings-carousel" aria-label="Previous">&#8249;</button>
                <button class="carousel-nav carousel-nav--next" data-carousel="my-listings-carousel" aria-label="Next">&#8250;</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Recent CPDO Applications -->
<section class="gov-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">CPDO Applications</h2>
        <a class="btn btn-outline-primary btn-sm" href="application-form.php">New Application</a>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Registry</th>
                    <th>Property</th>
                    <th>Process</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($applications as $app): ?>
                <tr>
                    <td class="text-nowrap"><?= e($app['registry_number']) ?></td>
                    <td><?= e($app['property_title']) ?></td>
                    <td>P<?= (int)$app['current_process'] ?></td>
                    <td>
                        <span class="status-pill status-<?= e($app['status']) ?>">
                            <?= e(workflow_status_label($app['phase_status'])) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <?php
                        // Route "Open" to the right page based on phase
                        $openUrl = match (true) {
                            in_array($app['phase_status'], ['DRAFT', 'SUBMITTED'], true)
                                && $app['current_process'] <= 2
                                => 'requirements-upload.php?id=' . (int)$app['id'],
                            default => 'application-show.php?id=' . (int)$app['id'],
                        };
                        ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e($openUrl) ?>">
                            <?= in_array($app['phase_status'], ['DRAFT'], true) ? 'Continue' : 'Open' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$applications): ?>
                <tr><td colspan="5" class="text-secondary text-center py-3">No applications started yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Public Marketplace Carousel -->
<?php if ($marketListings): ?>
<section class="gov-card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h5 mb-0">Available Rentals Marketplace</h2>
            <p class="text-secondary small mb-0">Active listings from other landlords</p>
        </div>
    </div>
    <div class="listing-carousel-wrap">
        <div class="listing-carousel" id="market-carousel">
            <?php foreach ($marketListings as $listing): ?>
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
                        <a class="btn btn-sm btn-outline-primary" href="property-view.php?id=<?= (int)$listing['id'] ?>">View</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (count($marketListings) > 3): ?>
            <button class="carousel-nav carousel-nav--prev" data-carousel="market-carousel" aria-label="Previous">&#8249;</button>
            <button class="carousel-nav carousel-nav--next" data-carousel="market-carousel" aria-label="Next">&#8250;</button>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
