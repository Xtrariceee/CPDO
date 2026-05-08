<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TENANT]);
verify_csrf();

/* â”€â”€ Upgrade request submission â”€â”€ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_upgrade') {
    $reason   = trim($_POST['reason'] ?? '');
    $existing = db()->prepare('SELECT id FROM role_upgrade_requests WHERE user_id = ? AND status = "PENDING"');
    $existing->execute([(int)$user['id']]);
    if ($existing->fetch()) {
        $_SESSION['flash_error'] = 'You already have a pending upgrade request. Please wait for admin review.';
    } else {
        $pdo  = db();
        $stmt = $pdo->prepare('INSERT INTO role_upgrade_requests (user_id, from_role, to_role, reason) VALUES (?, "tenant", "landlord", ?)');
        $stmt->execute([(int)$user['id'], $reason ?: null]);
        $requestId = (int)$pdo->lastInsertId();
        audit_log((int)$user['id'], 'ROLE_UPGRADE_REQUESTED', 'role_upgrade_requests', $requestId, ['from' => 'tenant', 'to' => 'landlord']);
        notify_role(ROLE_SYSTEM_ADMIN, null, 'Role Upgrade Request',
            user_full_name($user) . ' (' . $user['email'] . ') has requested to upgrade from Tenant to Landlord.');
        $_SESSION['flash_success'] = 'Your upgrade request has been submitted. An admin will review it shortly.';
    }
    redirect('tenant/dashboard.php');
}

/* â”€â”€ Data queries â”€â”€ */
$upgradeReq = db()->prepare('SELECT * FROM role_upgrade_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
$upgradeReq->execute([(int)$user['id']]);
$upgradeRequest = $upgradeReq->fetch();

// Search / filter
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $likeSearch = '%' . $search . '%';
    $stmt = db()->prepare(
        'SELECT p.*, CONCAT_WS(" ", u.first_name, u.last_name) AS landlord_name
         FROM properties p JOIN users u ON u.id = p.landlord_id
         WHERE p.status = "ACTIVE" AND (p.title LIKE ? OR p.address LIKE ? OR p.description LIKE ?)
         ORDER BY p.updated_at DESC'
    );
    $stmt->execute([$likeSearch, $likeSearch, $likeSearch]);
} else {
    $stmt = db()->prepare(
        'SELECT p.*, CONCAT_WS(" ", u.first_name, u.last_name) AS landlord_name
         FROM properties p JOIN users u ON u.id = p.landlord_id
         WHERE p.status = "ACTIVE" ORDER BY p.updated_at DESC'
    );
    $stmt->execute();
}
$properties    = $stmt->fetchAll();
$totalListings = count($properties);
$minRent       = $properties ? min(array_column($properties, 'monthly_rent')) : 0;
$maxRent       = $properties ? max(array_column($properties, 'monthly_rent')) : 0;

$baseUrl = rtrim($config['app']['base_url'], '/');

require __DIR__ . '/../partials/header.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   Tenant Dashboard — Glassmorphism overlay on the RentEase base
   ═══════════════════════════════════════════════════════════════ */

.td-page {
    min-height: calc(100vh - 64px);
    background: #fff;
    padding: 32px 0 64px;
}

/* Glass card base */
.td-glass {
    background: #fff;
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border: 1px solid #f0dfad;
    border-radius: 18px;
    box-shadow: 0 4px 24px rgba(36,27,11,.08), 0 1px 4px rgba(36,27,11,.04);
}

/* Page header */
.td-eyebrow { font-size:.7rem;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#8a6400;margin-bottom:4px; }
.td-welcome { font-size:1.75rem;font-weight:900;color:#241b0b;margin-bottom:4px;letter-spacing:-.02em; }
.td-sub     { font-size:.88rem;color:#76684b; }

/* Stat cards */
.td-stat-card { padding:20px 22px;border-radius:16px;position:relative;overflow:hidden;height:100%; }
.td-stat-card::before { content:'';position:absolute;inset:0;background:linear-gradient(135deg,#fff 0%,#fffdf5 100%);border-radius:inherit;z-index:0; }
.td-stat-card > * { position:relative;z-index:1; }
.td-stat-icon { width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:14px;flex-shrink:0; }
.td-stat-icon-blue,
.td-stat-icon-green,
.td-stat-icon-amber { background:#fff7d6; color:#8a6400; }
.td-stat-label { font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#76684b;margin-bottom:4px; }
.td-stat-value { font-size:1.5rem;font-weight:900;color:#241b0b;line-height:1.1;margin-bottom:2px; }
.td-stat-meta  { font-size:.75rem;color:#76684b; }

/* Section headings */
.td-section-title { font-size:1rem;font-weight:800;color:#241b0b;margin-bottom:4px; }
.td-section-sub   { font-size:.8rem;color:#76684b;margin-bottom:0; }

/* Search bar */
.td-search-wrap { position:relative; }
.td-search-wrap .td-search-icon { position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#a89562;pointer-events:none;font-size:.95rem; }
.td-search-input { width:100%;padding:10px 14px 10px 40px;border:1.5px solid #f0dfad;border-radius:10px;background:#fffdf5;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);font-size:.88rem;color:#241b0b;box-shadow:0 1px 4px rgba(36,27,11,.06);transition:border-color .15s,box-shadow .15s,background .15s; }
.td-search-input::placeholder { color:#a89562; }
.td-search-input:focus { outline:none;border-color:#e6b82f;background:#fff;box-shadow:0 0 0 3px rgba(246,207,74,.28); }

/* Property listing cards */
.td-prop-card { border-radius:16px;overflow:hidden;height:100%;display:flex;flex-direction:column;transition:transform .2s,box-shadow .2s; }
.td-prop-card:hover { transform:translateY(-4px);box-shadow:0 12px 36px rgba(36,27,11,.14),0 4px 8px rgba(36,27,11,.06) !important; }
.td-prop-img { height:140px;display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:900;letter-spacing:.08em;color:rgba(36,27,11,.45);background:linear-gradient(135deg,#fff1b8,#f6cf4a);flex-shrink:0;position:relative; }
.td-prop-img-alt  { background:linear-gradient(135deg,#fff7d6,#e6b82f); }
.td-prop-img-alt2 { background:linear-gradient(135deg,#f7e7a6,#c59000); }
.td-verified-badge { position:absolute;top:10px;right:10px;display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;background:rgba(36,27,11,.82);backdrop-filter:blur(6px);color:#fff7d6;font-size:.65rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase; }
.td-prop-body { padding:16px;flex:1;display:flex;flex-direction:column; }
.td-prop-title    { font-size:.92rem;font-weight:800;color:#241b0b;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.td-prop-location { font-size:.75rem;color:#76684b;margin-bottom:10px;display:flex;align-items:center;gap:4px; }
.td-prop-details  { display:flex;gap:12px;margin-bottom:12px; }
.td-prop-detail   { font-size:.75rem;color:#3a2d12;font-weight:600;display:flex;align-items:center;gap:4px; }
.td-prop-rent     { font-size:1.1rem;font-weight:900;color:#8a6400;margin-bottom:14px; }
.td-prop-rent span { font-size:.75rem;font-weight:400;color:#76684b; }
.td-prop-landlord { font-size:.73rem;color:#a89562;margin-bottom:0;margin-top:auto; }
.td-btn-view { display:block;width:100%;padding:9px;border-radius:8px;border:1.5px solid #c59000;background:transparent;color:#8a6400;font-weight:700;font-size:.82rem;text-align:center;text-decoration:none;cursor:pointer;transition:background .15s,color .15s;margin-top:12px; }
.td-btn-view:hover { background:#f6cf4a;color:#241b0b; }

/* Quick Actions */
.td-action-btn { display:flex;align-items:center;gap:12px;width:100%;padding:14px 16px;border-radius:12px;border:1.5px solid;background:transparent;text-align:left;cursor:pointer;font-weight:700;font-size:.85rem;transition:background .15s,transform .15s,box-shadow .15s;text-decoration:none; }
.td-action-btn:hover { transform:translateY(-1px); }
.td-action-btn-primary { border-color:#c59000;color:#8a6400;background:#fff7d6; }
.td-action-btn-primary:hover { background:#fff1b8;color:#241b0b;box-shadow:0 4px 14px rgba(197,144,0,.18); }
.td-action-btn-upgrade { border-color:#f0dfad;color:#3a2d12;background:#fffdf5; }
.td-action-btn-upgrade:hover { background:#fff7d6;color:#241b0b;box-shadow:0 4px 14px rgba(36,27,11,.1); }
.td-action-icon { width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0; }
.td-action-icon-blue  { background:#fffdf5; }
.td-action-icon-slate { background:#fff7d6; }
.td-action-label { font-size:.85rem;font-weight:700;line-height:1.2; }
.td-action-sub   { font-size:.72rem;font-weight:400;color:#a89562;margin-top:1px; }
.td-premium-tag  { display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;background:#f6cf4a;color:#241b0b;font-size:.62rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-left:auto;flex-shrink:0; }

/* Status banners */
.td-status-banner { border-radius:12px;padding:14px 18px;font-size:.83rem;font-weight:600;display:flex;align-items:flex-start;gap:10px;margin-bottom:20px; }
.td-status-pending  { background:rgba(217,119,6,.1); border:1px solid rgba(217,119,6,.3); color:#78350f; }
.td-status-rejected { background:rgba(192,57,43,.1); border:1px solid rgba(192,57,43,.3); color:#7a1a10; }

/* Empty state */
.td-empty { text-align:center;padding:48px 24px;color:#76684b; }
.td-empty-icon { font-size:2.8rem;margin-bottom:12px; }
.td-empty h3 { font-size:.95rem;color:#241b0b;margin-bottom:6px;font-weight:800; }
.td-empty p  { font-size:.83rem;max-width:320px;margin:0 auto; }

@media (max-width:991px) { .td-sidebar { margin-top:24px; } }
@media (max-width:575px) { .td-welcome { font-size:1.4rem; } .td-stat-value { font-size:1.25rem; } }
</style>

<div class="td-page">
<div class="container-fluid" style="max-width:1200px;margin:0 auto;padding:0 20px;">

    <!-- Page header -->
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="td-eyebrow">Tenant Portal</p>
            <h1 class="td-welcome">Welcome back, <?= e($user['first_name']) ?></h1>
            <p class="td-sub">Browse rentals and manage your tenancy in Davao City.</p>
        </div>
        <span class="badge text-bg-success px-3 py-2 mt-1 flex-shrink-0" style="border-radius:999px;font-size:.72rem;">
            ● Tenant Account
        </span>
    </div>

    <!-- Upgrade request status banners -->
    <?php if ($upgradeRequest && $upgradeRequest['status'] === 'PENDING'): ?>
        <div class="td-status-banner td-status-pending">
            <span style="font-size:1.1rem;">Pending</span>
            <div><strong>Upgrade request pending.</strong> Your request to become a Landlord is under admin review. You'll be notified once a decision is made.</div>
        </div>
    <?php elseif ($upgradeRequest && $upgradeRequest['status'] === 'REJECTED'): ?>
        <div class="td-status-banner td-status-rejected">
            <span style="font-size:1.1rem;">X</span>
            <div>
                <strong>Upgrade request rejected.</strong>
                <?php if ($upgradeRequest['admin_notes']): ?> Admin note: <?= e($upgradeRequest['admin_notes']) ?><?php endif; ?>
                You may submit a new request from the Quick Actions panel.
            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Stats row -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-4">
            <div class="td-glass td-stat-card">
                <div class="td-stat-icon td-stat-icon-amber">DUE</div>
                <p class="td-stat-label">Next Rent Due</p>
                <p class="td-stat-value">₱8,500</p>
                <p class="td-stat-meta">Due on July 1, 2026</p>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="td-glass td-stat-card">
                <div class="td-stat-icon td-stat-icon-blue">FIX</div>
                <p class="td-stat-label">Maintenance Requests</p>
                <p class="td-stat-value">2</p>
                <p class="td-stat-meta">Pending repair tickets</p>
            </div>
        </div>
        <div class="col-sm-12 col-lg-4">
            <div class="td-glass td-stat-card">
                <div class="td-stat-icon td-stat-icon-green">DOC</div>
                <p class="td-stat-label">Lease Status</p>
                <p class="td-stat-value" style="font-size:1.1rem;">Active</p>
                <p class="td-stat-meta">6 months remaining</p>
            </div>
        </div>
    </div>

    <!-- Main content + sidebar -->
    <div class="row g-4 align-items-start">

        <!-- Left: Property Discovery -->
        <div class="col-lg-8">
            <div class="td-glass p-4">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h2 class="td-section-title">Property Discovery</h2>
                        <p class="td-section-sub">All listings are from verified landlords</p>
                    </div>
                    <?php if ($totalListings > 0): ?>
                        <span class="badge text-bg-success flex-shrink-0" style="border-radius:999px;padding:5px 12px;"><?= $totalListings ?> active</span>
                    <?php endif; ?>
                </div>

                <!-- Search bar -->
                <form method="get" class="mb-4" role="search">
                    <div class="td-search-wrap">
                        <span class="td-search-icon" aria-hidden="true">S</span>
                        <input class="td-search-input" type="search" name="search"
                               value="<?= e($search) ?>"
                               placeholder="Search by property name or location…"
                               aria-label="Search properties">
                    </div>
                </form>

                <?php if ($search !== '' && $totalListings === 0): ?>
                    <div class="td-empty">
                        <div class="td-empty-icon">No Results</div>
                        <h3>No results for "<?= e($search) ?>"</h3>
                        <p>Try a different keyword or <a href="dashboard.php" class="text-primary fw-bold">clear the search</a>.</p>
                    </div>
                <?php elseif (!$properties): ?>
                    <div class="td-empty">
                        <div class="td-empty-icon">Listings</div>
                        <h3>No listings available yet</h3>
                        <p>Check back soon — new compliant properties are added regularly.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($properties as $i => $prop): ?>
                            <div class="col-sm-6 col-xl-4">
                                <?php $imgClass = match ($i % 3) { 1 => 'td-prop-img-alt', 2 => 'td-prop-img-alt2', default => '' }; ?>
                                <article class="td-glass td-prop-card">
                                    <div class="td-prop-img <?= $imgClass ?>">
                                        <span><?= e(mb_strtoupper(mb_substr($prop['title'], 0, 2))) ?></span>
                                        <span class="td-verified-badge">Verified</span>
                                    </div>
                                    <div class="td-prop-body">
                                        <h3 class="td-prop-title"><?= e($prop['title']) ?></h3>
                                        <p class="td-prop-location"><span>Location:</span> <?= e($prop['address']) ?></p>
                                        <div class="td-prop-details">
                                            <span class="td-prop-detail">2 Beds</span>
                                            <span class="td-prop-detail">1 Bath</span>
                                        </div>
                                        <p class="td-prop-rent">₱<?= number_format((float)$prop['monthly_rent'], 0) ?><span>/month</span></p>
                                        <a class="td-btn-view" href="../landlord/property-view.php?id=<?= (int)$prop['id'] ?>">View Details</a>
                                        <p class="td-prop-landlord mt-2">Listed by <?= e($prop['landlord_name']) ?></p>
                                    </div>
                                </article>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Quick Actions sidebar -->
        <div class="col-lg-4 td-sidebar">
            <div class="td-glass p-4">
                <h2 class="td-section-title mb-1">Quick Actions</h2>
                <p class="td-section-sub mb-4">Manage your tenancy</p>
                <div class="d-flex flex-column gap-3">

                    <button type="button" class="td-action-btn td-action-btn-primary"
                            onclick="alert('Maintenance request feature coming soon.')">
                        <span class="td-action-icon td-action-icon-blue">FIX</span>
                        <div>
                            <div class="td-action-label">Submit Maintenance Request</div>
                            <div class="td-action-sub">Report a repair or issue</div>
                        </div>
                    </button>

                    <button type="button" class="td-action-btn td-action-btn-primary"
                            onclick="alert('Lease viewer coming soon.')">
                        <span class="td-action-icon td-action-icon-blue">DOC</span>
                        <div>
                            <div class="td-action-label">View Lease Agreement</div>
                            <div class="td-action-sub">Download or review your lease</div>
                        </div>
                    </button>

                    <hr style="border-color:#f0dfad;margin:4px 0;">

                    <?php if (!$upgradeRequest || $upgradeRequest['status'] === 'REJECTED'): ?>
                        <button type="button" class="td-action-btn td-action-btn-upgrade"
                                data-modal-target="upgrade-modal">
                            <span class="td-action-icon td-action-icon-slate">UP</span>
                            <div>
                                <div class="td-action-label">Become a Landlord</div>
                                <div class="td-action-sub">List your own property</div>
                            </div>
                            <span class="td-premium-tag">Upgrade</span>
                        </button>
                    <?php elseif ($upgradeRequest['status'] === 'PENDING'): ?>
                        <div class="td-action-btn td-action-btn-upgrade" style="cursor:default;opacity:.7;">
                            <span class="td-action-icon td-action-icon-slate">WAIT</span>
                            <div>
                                <div class="td-action-label">Upgrade Pending</div>
                                <div class="td-action-sub">Admin review in progress</div>
                            </div>
                        </div>
                    <?php elseif ($upgradeRequest['status'] === 'APPROVED'): ?>
                        <div class="td-action-btn" style="border-color:#1e9e57;color:#1e9e57;background:rgba(30,158,87,.06);cursor:default;">
                            <span class="td-action-icon" style="background:rgba(30,158,87,.12);">OK</span>
                            <div>
                                <div class="td-action-label">Upgrade Approved</div>
                                <div class="td-action-sub">Log out and back in to access your Landlord dashboard</div>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <?php if ($totalListings > 0): ?>
            <div class="td-glass p-4 mt-3">
                <h2 class="td-section-title mb-3">Market Summary</h2>
                <div class="d-flex flex-column gap-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span style="font-size:.8rem;color:#76684b;font-weight:600;">Active Listings</span>
                        <span style="font-size:.88rem;font-weight:800;color:#241b0b;"><?= $totalListings ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span style="font-size:.8rem;color:#76684b;font-weight:600;">Lowest Rent</span>
                        <span style="font-size:.88rem;font-weight:800;color:#8a6400;">₱<?= number_format((float)$minRent, 0) ?>/mo</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span style="font-size:.8rem;color:#76684b;font-weight:600;">Highest Rent</span>
                        <span style="font-size:.88rem;font-weight:800;color:#241b0b;">₱<?= number_format((float)$maxRent, 0) ?>/mo</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>
</div>

<!-- Upgrade to Landlord Modal -->
<div class="modal-backdrop" id="upgrade-modal" role="dialog" aria-modal="true" aria-labelledby="upgrade-modal-title">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-header">
            <h2 class="h5 mb-0" id="upgrade-modal-title">Become a Landlord</h2>
            <button class="preview-modal-close" data-modal-close aria-label="Close">&times;</button>
        </div>
        <form method="post" class="modal-body">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="request_upgrade">
            <p class="text-secondary small mb-3">
                Submitting this request notifies the admin. They will review your account and change your role to <strong>Landlord</strong> if approved — no new account needed.
            </p>
            <div class="mb-3">
                <label class="form-label">Reason <span class="text-secondary fw-normal">(optional)</span></label>
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
