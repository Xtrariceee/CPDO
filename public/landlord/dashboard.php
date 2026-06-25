<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

// ── Own properties ──────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $likeSearch = '%' . $search . '%';
    $propertiesStmt = db()->prepare('SELECT * FROM properties WHERE landlord_id = ? AND (title LIKE ? OR address LIKE ? OR description LIKE ?) ORDER BY updated_at DESC');
    $propertiesStmt->execute([(int)$user['id'], $likeSearch, $likeSearch, $likeSearch]);
} else {
    $propertiesStmt = db()->prepare('SELECT * FROM properties WHERE landlord_id = ? ORDER BY updated_at DESC');
    $propertiesStmt->execute([(int)$user['id']]);
}
$properties = $propertiesStmt->fetchAll();

// ── Recent CPDO applications ─────────────────────────────────────────────────
$appsStmt = db()->prepare('SELECT * FROM applications WHERE landlord_id = ? ORDER BY updated_at DESC LIMIT 10');
$appsStmt->execute([(int)$user['id']]);
$applications = $appsStmt->fetchAll();

// ── Skip Path Compliance Uploads ─────────────────────────────────────────────
$skipStmt = db()->prepare('SELECT * FROM compliance_uploads WHERE landlord_id = ? ORDER BY created_at DESC');
$skipStmt->execute([(int)$user['id']]);
$skipUploads = $skipStmt->fetchAll();

// ── Pending and Recent tenant inquiries ─────────────────────────────────────
$pendingInquiriesStmt = db()->prepare(
    'SELECT COUNT(*) FROM rental_applications ra
     JOIN properties p ON p.id = ra.property_id
     WHERE p.landlord_id = ? AND ra.status = "PENDING"'
);
$pendingInquiriesStmt->execute([(int)$user['id']]);
$pendingInquiries = (int)$pendingInquiriesStmt->fetchColumn();

$recentInquiriesStmt = db()->prepare(
    'SELECT ra.*, p.title AS property_title, CONCAT_WS(" ", u.first_name, u.last_name) AS tenant_name
     FROM rental_applications ra
     JOIN properties p ON p.id = ra.property_id
     JOIN users u ON u.id = ra.tenant_id
     WHERE p.landlord_id = ?
     ORDER BY ra.created_at DESC
     LIMIT 5'
);
$recentInquiriesStmt->execute([(int)$user['id']]);
$recentInquiries = $recentInquiriesStmt->fetchAll();

// ── Quick stats ──────────────────────────────────────────────────────────────
$totalProperties  = count($properties);
$activeProperties = count(array_filter($properties, fn($p) => $p['status'] === 'ACTIVE'));

// Count tenants: properties that are ACTIVE (occupied) — approximation until a tenants table is joined
$activeTenants = $activeProperties;

// Pending CPDO applications
$pendingApps = count(array_filter($applications, fn($a) => !in_array($a['phase_status'], ['APPROVED', 'DISAPPROVED'], true)));

// Monthly revenue: sum of monthly_rent for ACTIVE properties
$monthlyRevenue = array_sum(array_map(
    fn($p) => $p['status'] === 'ACTIVE' ? (float)$p['monthly_rent'] : 0.0,
    $properties
));

// ── Helper: map phase_status to a Bootstrap badge class ─────────────────────
function app_badge_class(string $phase): string
{
    return match ($phase) {
        'APPROVED'                          => 'bg-success',
        'DISAPPROVED'                       => 'bg-danger',
        'PAYMENT_PENDING', 'FOR_MEETING',
        'DELIBERATION', 'DEFERRED'          => 'bg-warning',
        'DRAFT', 'SUBMITTED'                => 'bg-secondary',
        'PRE_EVALUATION', 'PAID',
        'INSPECTION_SCHEDULED',
        'INSPECTION_DONE'                   => 'bg-info',
        default                             => 'bg-secondary',
    };
}

// ── Helper: human-readable phase label ──────────────────────────────────────
function app_phase_label(string $phase): string
{
    return match ($phase) {
        'DRAFT'                => 'Draft',
        'SUBMITTED'            => 'Submitted',
        'PRE_EVALUATION'       => 'Pre-Evaluation',
        'PAYMENT_PENDING'      => 'Payment Pending',
        'PAID'                 => 'Paid',
        'INSPECTION_SCHEDULED' => 'Inspection Scheduled',
        'INSPECTION_DONE'      => 'Inspection Done',
        'FOR_MEETING'          => 'For Meeting',
        'DELIBERATION'         => 'Deliberation',
        'APPROVED'             => 'Approved',
        'DISAPPROVED'          => 'Disapproved',
        'DEFERRED'             => 'Deferred',
        default                => $phase,
    };
}

$displayApps = $applications;

require __DIR__ . '/../partials/header.php';
?>

<!-- ── Dashboard-specific stylesheet ──────────────────────────────────────── -->
<link href="<?= e(rtrim($config['app']['base_url'], '/')) ?>/assets/css/landlord-dashboard.css" rel="stylesheet">

<style>
    /* ─────────────────────────────────────────────
       Improved RentEase Dashboard Stat Cards
       Removes letter badges like PR, TN, CP, PHP
       Replaces them with clean SVG icons
    ───────────────────────────────────────────── */

    .landlord-dash .stats-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 1.5rem;
    }

    .landlord-dash .stats-grid .stat-card--inquiries::before {
        background: radial-gradient(circle, rgba(246, 207, 74, 0.25), transparent 70%) !important;
    }


    .landlord-dash .stats-grid .stat-card {
        position: relative;
        overflow: hidden;
        min-height: 196px;
        padding: 26px 24px;
        border-radius: 20px;
        background:
            radial-gradient(circle at top right, rgba(246, 207, 74, 0.18), transparent 34%),
            linear-gradient(145deg, #ffffff 0%, #fffdf7 100%);
        border: 1px solid #f0d99f;
        box-shadow:
            0 14px 32px rgba(36, 27, 11, 0.07),
            inset 0 1px 0 rgba(255, 255, 255, 0.85);
        transition:
            transform 0.22s ease,
            box-shadow 0.22s ease,
            border-color 0.22s ease,
            background 0.22s ease;
    }

    .landlord-dash .stats-grid .stat-card::before {
        content: "";
        position: absolute;
        top: -72px;
        right: -72px;
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(246, 207, 74, 0.20), transparent 70%);
        pointer-events: none;
    }

    .landlord-dash .stats-grid .stat-card::after {
        content: "";
        position: absolute;
        left: 24px;
        right: 24px;
        bottom: 0;
        height: 3px;
        border-radius: 999px 999px 0 0;
        background: linear-gradient(90deg, transparent, rgba(197, 144, 0, 0.45), transparent);
        opacity: 0;
        transition: opacity 0.22s ease;
    }

    .landlord-dash .stats-grid .stat-card:hover {
        transform: translateY(-5px);
        border-color: #e6b82f;
        box-shadow:
            0 20px 44px rgba(36, 27, 11, 0.11),
            inset 0 1px 0 rgba(255, 255, 255, 0.9);
    }

    .landlord-dash .stats-grid .stat-card:hover::after {
        opacity: 1;
    }

    .landlord-dash .stats-grid .stat-card-icon {
        width: 56px;
        height: 56px;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 22px;
        color: #c59000;
        background:
            radial-gradient(circle at 30% 20%, rgba(255, 255, 255, 0.95), transparent 42%),
            #fff4cc;
        box-shadow:
            inset 0 0 0 1px rgba(230, 184, 47, 0.18),
            0 10px 24px rgba(197, 144, 0, 0.10);
    }

    .landlord-dash .stats-grid .stat-card-icon svg {
        width: 27px;
        height: 27px;
        stroke: currentColor;
    }

    .landlord-dash .stats-grid .stat-card-label {
        margin: 0 0 12px;
        font-size: 0.74rem;
        font-weight: 900;
        line-height: 1.2;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #6d5c3e;
    }

    .landlord-dash .stats-grid .stat-card-value {
        margin: 0 0 10px;
        color: #201707;
        font-size: clamp(2rem, 3vw, 2.55rem);
        font-weight: 950;
        line-height: 1;
        letter-spacing: -0.055em;
    }

    .landlord-dash .stats-grid .stat-card-value--currency {
        font-size: clamp(1.8rem, 2.4vw, 2.2rem);
        letter-spacing: -0.045em;
    }

    .landlord-dash .stats-grid .stat-card-sub {
        margin: 0;
        color: #7a6a4d;
        font-size: 0.9rem;
        font-weight: 650;
        line-height: 1.35;
    }

    .landlord-dash .compliance-banner-icon {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .landlord-dash .compliance-banner-icon svg {
        width: 22px;
        height: 22px;
        stroke: currentColor;
    }

    .landlord-dash .glass-empty-state-icon {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .landlord-dash .glass-empty-state-icon svg {
        width: 28px;
        height: 28px;
        stroke: currentColor;
    }

    @media (max-width: 991.98px) {
        .landlord-dash .stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 575.98px) {
        .landlord-dash .stats-grid {
            grid-template-columns: 1fr;
            gap: 14px;
        }

        .landlord-dash .stats-grid .stat-card {
            min-height: auto;
            padding: 22px 20px;
        }

        .landlord-dash .stats-grid .stat-card-icon {
            width: 50px;
            height: 50px;
            margin-bottom: 18px;
        }

        .landlord-dash .stats-grid .stat-card-icon svg {
            width: 24px;
            height: 24px;
        }
        .occupancy-badge--pending-rules {
            background: #fff2f0 !important;
            color: #b42318 !important;
            border: 1px solid #ffc9c2 !important;
        }
        .occupancy-badge--pending-rules::before {
            background: #b42318 !important;
        }
    }
</style>

<script>document.body.classList.add('landlord-dash');</script>

    <!-- ── Page header ──────────────────────────────────────────────────── -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <p class="dash-header-eyebrow mb-1">Landlord Portal</p>
            <h1 class="dash-header-title mb-1">Welcome back, <?= e($user['first_name']) ?></h1>
            <p class="dash-header-sub">Manage CPDO compliance and your rental property listings.</p>
        </div>
        <a class="btn-glass-primary" href="property-form.php">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                <path d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2z"/>
            </svg>
            Add Listing
        </a>
    </div>

    <!-- ── Quick stats row ──────────────────────────────────────────────── -->
    <div class="stats-grid">

        <!-- Total Properties -->
        <div class="glass-card stat-card stat-card--properties h-100">
            <div class="stat-card-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 10.5 12 3l9 7.5"></path>
                    <path d="M5 10v10h14V10"></path>
                    <path d="M9 20v-6h6v6"></path>
                </svg>
            </div>

            <p class="stat-card-label">Total Properties</p>

            <p class="stat-card-value">
                <?= number_format((int)$totalProperties) ?>
            </p>

            <p class="stat-card-sub">
                <?= number_format((int)$activeProperties) ?> Active
            </p>
        </div>

        <!-- Active Tenants -->
        <div class="glass-card stat-card stat-card--tenants h-100">
            <div class="stat-card-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>

            <p class="stat-card-label">Active Tenants</p>

            <p class="stat-card-value">
                <?= number_format((int)$activeTenants) ?>
            </p>

            <p class="stat-card-sub">Occupants</p>
        </div>

        <!-- Pending Approvals -->
        <div class="glass-card stat-card stat-card--pending h-100">
            <div class="stat-card-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <path d="M14 2v6h6"></path>
                    <path d="m9 15 2 2 4-4"></path>
                </svg>
            </div>

            <p class="stat-card-label">Pending Approvals</p>

            <p class="stat-card-value">
                <?= number_format((int)$pendingApps) ?>
            </p>

            <p class="stat-card-sub">
                Application<?= ($pendingApps !== 1) ? 's' : '' ?> Pending
            </p>
        </div>

        <!-- Tenant Inquiries -->
        <a href="applications.php" class="glass-card stat-card stat-card--inquiries h-100 text-decoration-none" style="display:block;">
            <div class="stat-card-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
            </div>

            <p class="stat-card-label">Tenant Inquiries</p>

            <p class="stat-card-value" style="color: #8a6400;">
                <?= number_format($pendingInquiries) ?>
            </p>

            <p class="stat-card-sub">
                <?= $pendingInquiries === 1 ? 'Inquiry' : 'Inquiries' ?> Pending
            </p>
        </a>

        <!-- Monthly Revenue -->
        <div class="glass-card stat-card stat-card--revenue h-100">
            <div class="stat-card-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 12V8H4a2 2 0 0 1 0-4h14v4"></path>
                    <path d="M4 8v12a2 2 0 0 0 2 2h14v-6"></path>
                    <path d="M18 12h4v6h-4a3 3 0 0 1 0-6z"></path>
                    <path d="M18 15h.01"></path>
                </svg>
            </div>

            <p class="stat-card-label">Monthly Revenue</p>

            <p class="stat-card-value stat-card-value--currency">
                ₱<?= number_format((float)$monthlyRevenue, 0) ?>
            </p>

            <p class="stat-card-sub">Estimated / mo</p>
        </div>

    </div><!-- /stats-grid -->

    <!-- ── My Property Listings ─────────────────────────────────────────── -->
    <div class="glass-panel p-4 mb-4">

        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-3">
            <div>
                <h2 class="section-title mb-0">My Property Listings</h2>
                <p class="section-sub mt-1">
                    <?= $properties ? count($properties) . ' listing' . (count($properties) !== 1 ? 's' : '') . ' on record' : 'No listings yet' ?>
                </p>
            </div>
            <div class="d-flex flex-column flex-sm-row gap-3 align-items-sm-center">
                <form method="get" class="d-flex align-items-center position-relative">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="position-absolute ms-3 text-secondary" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="search" name="search" class="form-control form-control-sm ps-5" placeholder="Search listings..." value="<?= e($search) ?>" style="border-radius: 20px; border-color: #f0dfad; min-width: 200px;">
                </form>
                <a class="btn-glass-outline flex-shrink-0" href="property-form.php">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                        <path d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2z"/>
                    </svg>
                    Add Listing
                </a>
            </div>
        </div>

        <hr class="glass-divider mb-4">

        <?php if ($properties): ?>
        <div class="row g-3">
            <?php foreach ($properties as $i => $property):
                $thumbIdx = ($i % 3) + 1;
                $initials = mb_strtoupper(mb_substr($property['title'], 0, 2));
                
                $isPending = ($property['status'] === 'PENDING');
                $isPendingRules = $isPending && empty($property['rules_accepted']);
                $isPendingDetails = $isPending && !$isPendingRules; // Could be expanded to check if details are missing, but for now we route to details or publish
                
                $occupancy = $isPending ? 'pending' : match ($property['status']) {
                    'ACTIVE'  => 'occupied',
                    default   => 'inactive',
                };
                $occupancyLabel = $isPending ? 'Draft' : match ($property['status']) {
                    'ACTIVE'  => 'Occupied',
                    default   => 'Inactive',
                };
                $nextStepUrl = $isPendingRules ? 'house-rules.php?id=' . (int)$property['id'] : 'property-details.php?id=' . (int)$property['id'];
                
                $propDetails = json_decode($property['extended_details'] ?? '{}', true) ?: [];
                $images = $propDetails['image_gallery'] ?? [];
                $videos = $propDetails['video_gallery'] ?? [];
                
                $bedrooms = (int)($propDetails['bedrooms'] ?? 0);
                $bathrooms = $propDetails['bathrooms'] ?? '0';
                $bedsLabel = $bedrooms === 0 ? 'Studio' : $bedrooms . ' Bed' . ($bedrooms > 1 ? 's' : '');
                $bathsLabel = $bathrooms . ' Bath' . (is_numeric($bathrooms) && (float)$bathrooms > 1 ? 's' : '');
            ?>
            <div class="col-12 col-sm-6 col-xl-4">
                <article class="property-card h-100">
                    <?php if (!empty($images)): 
                        $imgUrl = rtrim($config['app']['base_url'], '/') . '/' . $images[0];
                    ?>
                        <div class="property-thumb p-0" style="overflow:hidden;" aria-hidden="true">
                            <img src="<?= e($imgUrl) ?>" alt="<?= e($property['title']) ?>" style="width:100%; height:100%; object-fit:cover;">
                        </div>
                    <?php elseif (!empty($videos)): 
                        $vidUrl = rtrim($config['app']['base_url'], '/') . '/' . $videos[0];
                    ?>
                        <div class="property-thumb p-0" style="overflow:hidden; background:#000;" aria-hidden="true">
                            <video src="<?= e($vidUrl) ?>" style="width:100%; height:100%; object-fit:cover;" muted playsinline></video>
                        </div>
                    <?php else: ?>
                        <div class="property-thumb property-thumb--<?= $thumbIdx ?>" aria-hidden="true">
                            <?= e($initials) ?>
                        </div>
                    <?php endif; ?>
                    <div class="property-card-body">
                        <h3 class="property-card-title"><?= e($property['title']) ?></h3>
                        <p class="property-card-address">
                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" style="opacity:.5;margin-right:3px;">
                                <path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/>
                            </svg>
                            <?= e($property['address']) ?>
                        </p>
                        <div class="d-flex gap-2 mb-2 text-secondary" style="font-size:0.75rem; font-weight:600;">
                            <span><?= e($bedsLabel) ?></span>
                            <span>&middot;</span>
                            <span><?= e($bathsLabel) ?></span>
                            <?php if (!empty($propDetails['floor_area'])): ?>
                                <span>&middot;</span>
                                <span><?= e($propDetails['floor_area']) ?> <?= e($propDetails['floor_area_unit'] ?? 'sqm') ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="property-card-rent">
                            ₱<?= number_format((float)$property['monthly_rent'], 0) ?><span>/mo</span>
                        </p>
                        <span class="occupancy-badge occupancy-badge--<?= e($occupancy) ?>">
                            <?= e($occupancyLabel) ?>
                        </span>
                    </div>
                    <div class="property-card-actions">
                        <?php if ($isPending): ?>
                            <div class="d-flex flex-column gap-2 w-100">
                                <a class="btn btn-warning btn-sm fw-bold text-dark w-100 d-block text-center" href="<?= e($nextStepUrl) ?>">Continue Draft</a>
                                <div class="d-flex gap-2">
                                    <a class="btn btn-outline-primary btn-sm flex-fill" href="property-form.php?id=<?= (int)$property['id'] ?>">Edit</a>
                                    <a class="btn btn-outline-secondary btn-sm flex-fill" href="property-view.php?id=<?= (int)$property['id'] ?>">View</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <a class="btn btn-outline-primary btn-sm" href="property-form.php?id=<?= (int)$property['id'] ?>">Edit</a>
                            <a class="btn btn-outline-secondary btn-sm" href="property-view.php?id=<?= (int)$property['id'] ?>">View Tenants</a>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
            <?php endforeach; ?>
        </div><!-- /property grid -->
        <?php else: ?>
        <div class="glass-empty-state">
            <div class="glass-empty-state-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 10.5 12 3l9 7.5"></path>
                    <path d="M5 10v10h14V10"></path>
                    <path d="M9 20v-6h6v6"></path>
                </svg>
            </div>
            <h3>No listings yet</h3>
            <p>Complete the CPDO workflow or upload compliance documents for your property address to start listing.</p>
            <a class="btn-glass-primary" href="property-form.php">Add Listing</a>
        </div>
        <?php endif; ?>

    </div><!-- /property panel -->

    <!-- ── Recent Tenant Inquiries ──────────────────────────────────────── -->
    <div class="glass-panel p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="section-title mb-0">Tenant Inquiries</h2>
                <p class="section-sub mt-1">Screen applicants and manage rental agreement drafts</p>
            </div>
            <a class="btn-glass-primary" href="applications.php">View All Inquiries</a>
        </div>

        <hr class="glass-divider mb-0">

        <div class="glass-table-wrap table-responsive">
            <table class="glass-table table align-middle" aria-label="Tenant Inquiries">
                <thead>
                    <tr>
                        <th scope="col">Property</th>
                        <th scope="col">Applicant</th>
                        <th scope="col">Monthly Income</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($recentInquiries): ?>
                    <?php foreach ($recentInquiries as $inq):
                        $badgeClass = match ($inq['status']) {
                            'PENDING' => 'bg-warning text-dark',
                            'ACCEPTED', 'AGREED' => 'bg-info text-dark',
                            'REGISTRY_FILLED' => 'bg-primary',
                            'DRAFT_SENT' => 'bg-info text-dark',
                            'SIGNED', 'PAID', 'COMPLETED' => 'bg-success',
                            'DECLINED' => 'bg-danger',
                            default => 'bg-secondary'
                        };
                    ?>
                    <tr>
                        <td><strong><?= e($inq['property_title']) ?></strong></td>
                        <td><?= e($inq['tenant_name']) ?></td>
                        <td>₱<?= number_format((float)$inq['monthly_income'], 2) ?></td>
                        <td><span class="badge <?= e($badgeClass) ?>"><?= e($inq['status']) ?></span></td>
                        <td class="text-end">
                            <a class="btn btn-sm" href="application-review.php?id=<?= (int)$inq['id'] ?>">Review</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="5">No tenant inquiries received yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php require __DIR__ . '/../partials/footer.php'; ?>