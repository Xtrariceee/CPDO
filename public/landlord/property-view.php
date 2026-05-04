<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_login();
$propertyId = (int)($_GET['id'] ?? 0);

$sql = 'SELECT p.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name
        FROM properties p
        JOIN users u ON u.id = p.landlord_id
        WHERE p.id = ?';
$params = [$propertyId];
if ($user['role'] === ROLE_LANDLORD) {
    $sql .= ' AND p.landlord_id = ?';
    $params[] = (int)$user['id'];
}
$stmt = db()->prepare($sql);
$stmt->execute($params);
$property = $stmt->fetch();
if (!$property) {
    http_response_code(404);
    exit('Property not found.');
}

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-outline-secondary btn-sm" href="javascript:history.back()">← Back</a>
    <h1 class="h3 mb-0"><?= e($property['title']) ?></h1>
    <span class="badge ms-auto align-self-start <?= $property['status'] === 'ACTIVE' ? 'text-bg-success' : ($property['status'] === 'INACTIVE' ? 'text-bg-secondary' : 'text-bg-warning') ?>">
        <?= e($property['status']) ?>
    </span>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <article class="gov-card p-4 mb-4">
            <!-- Placeholder image block -->
            <div class="property-hero-img mb-4">
                <span><?= mb_strtoupper(mb_substr($property['title'], 0, 2)) ?></span>
            </div>

            <h2 class="h5">About this Property</h2>
            <?php if (!empty($property['description'])): ?>
                <p class="text-secondary"><?= nl2br(e($property['description'])) ?></p>
            <?php else: ?>
                <p class="text-secondary">No description provided.</p>
            <?php endif; ?>

            <hr>
            <dl class="row mb-0">
                <dt class="col-sm-4">Address</dt>
                <dd class="col-sm-8"><?= e($property['address']) ?></dd>
                <dt class="col-sm-4">Monthly Rent</dt>
                <dd class="col-sm-8 fw-bold text-success"><?= currency_php((float)$property['monthly_rent']) ?></dd>
                <dt class="col-sm-4">Listed by</dt>
                <dd class="col-sm-8"><?= e($property['landlord_name']) ?></dd>
            </dl>
        </article>
    </div>
    <div class="col-lg-4">
        <div class="gov-card p-4">
            <h2 class="h5 mb-3">Interested?</h2>
            <p class="text-secondary small">Contact the landlord to inquire about this property.</p>
            <?php if ($user['role'] === ROLE_LANDLORD && (int)$property['landlord_id'] === (int)$user['id']): ?>
                <a class="btn btn-outline-primary w-100 mb-2" href="property-form.php?id=<?= (int)$property['id'] ?>">Edit Listing</a>
                <a class="btn btn-outline-secondary w-100" href="dashboard.php">Back to Dashboard</a>
            <?php else: ?>
                <a class="btn btn-primary w-100" href="mailto:?subject=Inquiry: <?= rawurlencode($property['title']) ?>">Send Inquiry</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.property-hero-img {
    width: 100%;
    height: 200px;
    border-radius: var(--radius-md);
    background: linear-gradient(135deg, var(--cpdo-navy), var(--cpdo-blue));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    font-weight: 900;
    color: rgba(255,255,255,.4);
    letter-spacing: .1em;
}
</style>

<?php require __DIR__ . '/../partials/footer.php'; ?>
