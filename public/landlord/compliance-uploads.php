<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

// Fetch Skip Path Compliance Uploads
$skipStmt = db()->prepare('SELECT * FROM compliance_uploads WHERE landlord_id = ? ORDER BY created_at DESC');
$skipStmt->execute([(int)$user['id']]);
$skipUploads = $skipStmt->fetchAll();

// Fetch properties to check if upload is already added to listings
$propertiesStmt = db()->prepare('SELECT * FROM properties WHERE landlord_id = ?');
$propertiesStmt->execute([(int)$user['id']]);
$properties = $propertiesStmt->fetchAll();

$baseUrl = rtrim($config['app']['base_url'], '/');

require __DIR__ . '/../partials/header.php';
?>

<div class="glass-panel p-4 mb-4">
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-3">
        <div>
            <h2 class="section-title mb-0">Skip Path Compliance Uploads</h2>
            <p class="section-sub mt-1">Track verification of your existing compliance documents</p>
        </div>
        <a class="btn-glass-primary flex-shrink-0" href="compliance-gateway.php">New Upload</a>
    </div>

    <hr class="glass-divider mb-0">

    <div class="glass-table-wrap table-responsive">
        <table class="glass-table table align-middle" aria-label="Skip Path Compliance Uploads">
            <thead>
                <tr>
                    <th scope="col">Property Name</th>
                    <th scope="col">Property Address</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($skipUploads): ?>
                <?php foreach ($skipUploads as $upload):
                    $badgeClass = match ($upload['status']) {
                        'VERIFIED' => 'bg-success',
                        'REJECTED' => 'bg-danger',
                        default    => 'bg-warning text-dark'
                    };
                    $statusLabel = match ($upload['status']) {
                        'PENDING_VERIFICATION' => 'Pending Verification',
                        'VERIFIED' => 'Verified',
                        'REJECTED' => 'Rejected',
                        default => $upload['status']
                    };
                ?>
                <tr>
                    <td><strong><?= e($upload['property_title']) ?></strong></td>
                    <td><?= e($upload['property_address']) ?></td>
                    <td><span class="badge <?= e($badgeClass) ?>"><?= e($statusLabel) ?></span></td>
                    <td class="text-end">
                        <?php if ($upload['status'] === 'VERIFIED'): ?>
                            <?php
                            // Check if a property with this address already exists
                            $existingProp = array_filter($properties, fn($p) => strcasecmp($p['address'], $upload['property_address']) === 0);
                            if ($existingProp): ?>
                                <span class="text-success small fw-bold">Added to Listings</span>
                            <?php else: ?>
                                <a class="btn btn-sm btn-outline-primary" href="property-form.php?address=<?= urlencode($upload['property_address']) ?>&title=<?= urlencode($upload['property_title']) ?>">Add to Listings</a>
                            <?php endif; ?>
                        <?php elseif ($upload['status'] === 'REJECTED'): ?>
                            <span class="text-danger small">Rejected</span>
                        <?php else: ?>
                            <span class="text-secondary small">Waiting Review</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr class="empty-row">
                    <td colspan="4">No skip path documents submitted yet.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
