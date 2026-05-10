<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

$status = landlord_compliance_status((int)$user['id']);

// ── Own properties ──────────────────────────────────────────────────────────
$propertiesStmt = db()->prepare('SELECT * FROM properties WHERE landlord_id = ? ORDER BY updated_at DESC');
$propertiesStmt->execute([(int)$user['id']]);
$properties = $propertiesStmt->fetchAll();

// ── Recent CPDO applications ─────────────────────────────────────────────────
$appsStmt = db()->prepare('SELECT * FROM applications WHERE landlord_id = ? ORDER BY updated_at DESC LIMIT 10');
$appsStmt->execute([(int)$user['id']]);
$applications = $appsStmt->fetchAll();

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

// ── Compliance banner CSS class ──────────────────────────────────────────────
$bannerClass = match ($status['state']) {
    'ELIGIBLE'     => 'compliance-banner--eligible',
    'UNDER_REVIEW' => 'compliance-banner--review',
    default        => 'compliance-banner--required',
};

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

// ── Sample CPDO applications for demo when none exist ───────────────────────
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
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 18px;
        margin-bottom: 1.5rem;
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
    }
</style>

<script>document.body.classList.add('landlord-dash');</script>

<div class="page-shell">

    <!-- ── Page header ──────────────────────────────────────────────────── -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <p class="dash-header-eyebrow mb-1">Landlord Portal</p>
            <h1 class="dash-header-title mb-1">Welcome back, <?= e($user['first_name']) ?></h1>
            <p class="dash-header-sub">Manage CPDO compliance and your rental property listings.</p>
        </div>
        <a class="btn-glass-primary" href="<?= $status['state'] === 'ELIGIBLE' ? 'property-form.php' : 'compliance-gateway.php' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                <path d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2z"/>
            </svg>
            <?= $status['state'] === 'ELIGIBLE' ? 'Add Listing' : 'Unlock Listing' ?>
        </a>
    </div>

    <!-- ── Compliance alert banner ──────────────────────────────────────── -->
    <div class="compliance-banner <?= e($bannerClass) ?> mb-4" role="alert">
        <div class="compliance-banner-icon" aria-hidden="true">
            <?php if ($status['state'] === 'ELIGIBLE'): ?>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <path d="m9 11 3 3L22 4"></path>
                </svg>
            <?php elseif ($status['state'] === 'UNDER_REVIEW'): ?>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M12 6v6l4 2"></path>
                </svg>
            <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <path d="M12 9v4"></path>
                    <path d="M12 17h.01"></path>
                </svg>
            <?php endif; ?>
        </div>

        <div class="flex-grow-1">
            <p class="compliance-banner-title mb-1"><?= e($status['label']) ?></p>
            <p class="compliance-banner-body">
                <?php if ($status['state'] === 'ELIGIBLE'): ?>
                    Compliance record is approved or verified. Property listing is enabled — add new listings anytime.
                <?php elseif ($status['state'] === 'UNDER_REVIEW'): ?>
                    Submission is under review. Listing remains locked until the CPDO approves or verifies the application.
                <?php else: ?>
                    Land reclassification or rezoning must be completed before listing a property.
                    <a href="compliance-gateway.php" class="fw-bold" style="color:inherit;text-decoration:underline;">Start</a>
                <?php endif; ?>
            </p>
        </div>

        <span class="dlp-badge align-self-start">DLP: Confidential</span>
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

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="section-title mb-0">My Property Listings</h2>
                <p class="section-sub mt-1">
                    <?= $properties ? count($properties) . ' listing' . (count($properties) !== 1 ? 's' : '') . ' on record' : 'No listings yet' ?>
                </p>
            </div>
            <a class="btn-glass-outline" href="<?= $status['state'] === 'ELIGIBLE' ? 'property-form.php' : 'compliance-gateway.php' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                    <path d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2z"/>
                </svg>
                <?= $status['state'] === 'ELIGIBLE' ? 'Add Listing' : 'Unlock Listing' ?>
            </a>
        </div>

        <hr class="glass-divider mb-4">

        <?php if ($properties): ?>
        <div class="row g-3">
            <?php foreach ($properties as $i => $property):
                $thumbIdx = ($i % 3) + 1;
                $initials = mb_strtoupper(mb_substr($property['title'], 0, 2));
                $occupancy = match ($property['status']) {
                    'ACTIVE'  => 'occupied',
                    'PENDING' => 'pending',
                    default   => 'inactive',
                };
                $occupancyLabel = match ($occupancy) {
                    'occupied' => 'Occupied',
                    'pending'  => 'Pending',
                    default    => 'Inactive',
                };
            ?>
            <div class="col-12 col-sm-6 col-xl-4">
                <article class="property-card h-100">
                    <div class="property-thumb property-thumb--<?= $thumbIdx ?>" aria-hidden="true">
                        <?= e($initials) ?>
                    </div>
                    <div class="property-card-body">
                        <h3 class="property-card-title"><?= e($property['title']) ?></h3>
                        <p class="property-card-address">
                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" style="opacity:.5;margin-right:3px;">
                                <path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10zm0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/>
                            </svg>
                            <?= e($property['address']) ?>
                        </p>
                        <p class="property-card-rent">
                            ₱<?= number_format((float)$property['monthly_rent'], 0) ?><span>/mo</span>
                        </p>
                        <span class="occupancy-badge occupancy-badge--<?= e($occupancy) ?>">
                            <?= e($occupancyLabel) ?>
                        </span>
                    </div>
                    <div class="property-card-actions">
                        <a class="btn btn-outline-primary btn-sm" href="property-form.php?id=<?= (int)$property['id'] ?>">Edit</a>
                        <a class="btn btn-outline-secondary btn-sm" href="property-view.php?id=<?= (int)$property['id'] ?>">View Tenants</a>
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
            <p>Approved CPDO workflow or verified legal documents are required before listing a property.</p>
            <a class="btn-glass-primary" href="compliance-gateway.php">Get Started</a>
        </div>
        <?php endif; ?>

    </div><!-- /property panel -->

    <!-- ── CPDO Applications Tracker ────────────────────────────────────── -->
    <div class="glass-panel cpdo-app-panel p-4 mb-4">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="section-title mb-0">CPDO Applications</h2>
                <p class="section-sub mt-1">Track land reclassification and rezoning submissions</p>
            </div>
            <a class="btn-glass-primary" href="application-form.php">New Application</a>
        </div>

        <hr class="glass-divider mb-0">

        <div class="glass-table-wrap table-responsive">
            <table class="glass-table table align-middle" aria-label="CPDO Applications">
                <thead>
                    <tr>
                        <th scope="col">Registry / ID</th>
                        <th scope="col">Property Address</th>
                        <th scope="col">Process Type</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($displayApps): ?>
                    <?php foreach ($displayApps as $app):
                        $badgeClass = app_badge_class($app['phase_status']);
                        $phaseLabel = app_phase_label($app['phase_status']);
                        $openUrl    = match (true) {
                            in_array($app['phase_status'], ['DRAFT', 'SUBMITTED'], true)
                                && $app['current_process'] <= 2
                                => 'requirements-upload.php?id=' . (int)$app['id'],
                            default => 'application-show.php?id=' . (int)$app['id'],
                        };
                        $openLabel = $app['phase_status'] === 'DRAFT' ? 'Continue' : 'Open';
                    ?>
                    <tr>
                        <td><span class="registry-id"><?= e($app['registry_number']) ?></span></td>
                        <td><?= e($app['property_title']) ?></td>
                        <td>
                            <span class="process-type">
                                <?= $app['current_process'] <= 1 ? 'Land Reclassification' : 'Rezoning (P' . (int)$app['current_process'] . ')' ?>
                            </span>
                        </td>
                        <td><span class="badge <?= e($badgeClass) ?>"><?= e($phaseLabel) ?></span></td>
                        <td class="text-end">
                            <a class="btn btn-sm" href="<?= e($openUrl) ?>"><?= e($openLabel) ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="5">No applications started yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div><!-- /table-responsive -->

    </div><!-- /applications panel -->

</div><!-- /page-shell -->

<?php require __DIR__ . '/../partials/footer.php'; ?>