<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);

$inspection = officer_applications(['INSPECTION_SCHEDULED']);
$meeting    = officer_applications(['FOR_MEETING']);
$voting     = officer_applications(['DELIBERATION']);

$assignedTasks = officer_applications([
    'INSPECTION_SCHEDULED',
    'FOR_MEETING',
    'DELIBERATION'
]);

require __DIR__ . '/../partials/header.php';
?>

<style>
    :root {
        --twg-navy: #0d3154;
        --twg-navy-dark: #08233d;
        --twg-blue: #0d6efd;
        --twg-blue-soft: #eaf3ff;
        --twg-gold: #f6c343;
        --twg-gold-soft: #fff5d6;
        --twg-green: #198754;
        --twg-green-soft: #eafaf1;
        --twg-orange: #b56a00;
        --twg-orange-soft: #fff4d6;
        --twg-text: #172033;
        --twg-muted: #6b7280;
        --twg-border: #d8e2ef;
        --twg-card: #ffffff;
        --twg-bg: #f3f7fc;
    }

    body.twg-dashboard-page {
        background:
            radial-gradient(circle at 1px 1px, rgba(13, 110, 253, 0.18) 1px, transparent 0),
            linear-gradient(180deg, #f7fbff 0%, #edf3fa 100%);
        background-size: 28px 28px, 100% 100%;
        color: var(--twg-text);
    }

    .twg-dashboard-shell {
        max-width: 1180px;
        margin: 0 auto;
        padding: 32px 18px 56px;
    }

    .twg-hero {
        position: relative;
        overflow: hidden;
        border-radius: 22px;
        padding: 28px;
        margin-bottom: 24px;
        background:
            radial-gradient(circle at top right, rgba(246, 195, 67, 0.25), transparent 34%),
            linear-gradient(135deg, #ffffff 0%, #f5f9ff 100%);
        border: 1px solid var(--twg-border);
        box-shadow: 0 18px 40px rgba(13, 49, 84, 0.10);
    }

    .twg-hero::before {
        content: "";
        position: absolute;
        right: -90px;
        top: -90px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(13, 110, 253, 0.08);
        pointer-events: none;
    }

    .twg-hero-content {
        position: relative;
        z-index: 1;
    }

    .twg-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        padding: 6px 12px;
        border-radius: 999px;
        background: var(--twg-blue-soft);
        color: var(--twg-blue);
        font-size: 0.72rem;
        font-weight: 900;
        letter-spacing: 0.09em;
        text-transform: uppercase;
    }

    .twg-eyebrow-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--twg-gold);
        box-shadow: 0 0 0 4px rgba(246, 195, 67, 0.20);
    }

    .twg-hero-title {
        margin: 0;
        color: #071d35;
        font-size: clamp(1.75rem, 4vw, 2.6rem);
        font-weight: 900;
        letter-spacing: -0.04em;
        line-height: 1.05;
    }

    .twg-hero-sub {
        max-width: 690px;
        margin: 12px 0 0;
        color: var(--twg-muted);
        font-size: 0.98rem;
        line-height: 1.7;
        font-weight: 500;
    }

    .twg-hero-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 22px;
    }

    .twg-btn-primary,
    .twg-btn-outline,
    .twg-btn-dark {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 40px;
        padding: 10px 16px;
        border-radius: 11px;
        font-size: 0.84rem;
        font-weight: 800;
        text-decoration: none;
        border: 1px solid transparent;
        transition:
            transform 0.18s ease,
            box-shadow 0.18s ease,
            background 0.18s ease,
            border-color 0.18s ease;
    }

    .twg-btn-primary {
        background: var(--twg-blue);
        color: #fff;
        box-shadow: 0 10px 22px rgba(13, 110, 253, 0.22);
    }

    .twg-btn-primary:hover {
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(13, 110, 253, 0.28);
    }

    .twg-btn-outline {
        background: #fff;
        color: var(--twg-navy);
        border-color: var(--twg-border);
    }

    .twg-btn-outline:hover {
        color: var(--twg-navy);
        background: #f7fbff;
        border-color: #b9cce2;
        transform: translateY(-1px);
    }

    .twg-btn-dark {
        background: var(--twg-navy);
        color: #fff;
        box-shadow: 0 10px 20px rgba(13, 49, 84, 0.18);
    }

    .twg-btn-dark:hover {
        background: var(--twg-navy-dark);
        color: #fff;
        transform: translateY(-1px);
    }

    .twg-stats-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .twg-stat-card {
        position: relative;
        overflow: hidden;
        display: block;
        min-height: 166px;
        padding: 22px;
        border-radius: 18px;
        background: var(--twg-card);
        border: 1px solid var(--twg-border);
        box-shadow: 0 14px 32px rgba(13, 49, 84, 0.09);
        text-decoration: none;
        transition:
            transform 0.2s ease,
            box-shadow 0.2s ease,
            border-color 0.2s ease;
    }

    .twg-stat-card::after {
        content: "";
        position: absolute;
        right: -54px;
        top: -54px;
        width: 130px;
        height: 130px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(13, 110, 253, 0.13), transparent 70%);
        pointer-events: none;
    }

    .twg-stat-card:hover {
        transform: translateY(-4px);
        border-color: #b7cce5;
        box-shadow: 0 20px 42px rgba(13, 49, 84, 0.13);
    }

    .twg-stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 18px;
        color: var(--twg-blue);
        background: var(--twg-blue-soft);
    }

    .twg-stat-card--inspection .twg-stat-icon {
        color: var(--twg-orange);
        background: var(--twg-orange-soft);
    }

    .twg-stat-card--meeting .twg-stat-icon {
        color: var(--twg-blue);
        background: var(--twg-blue-soft);
    }

    .twg-stat-card--voting .twg-stat-icon {
        color: var(--twg-green);
        background: var(--twg-green-soft);
    }

    .twg-stat-icon svg {
        width: 24px;
        height: 24px;
        stroke: currentColor;
    }

    .twg-stat-label {
        margin: 0 0 8px;
        color: var(--twg-blue);
        font-size: 0.84rem;
        font-weight: 900;
        letter-spacing: 0.01em;
    }

    .twg-stat-card--inspection .twg-stat-label {
        color: var(--twg-orange);
    }

    .twg-stat-card--meeting .twg-stat-label {
        color: var(--twg-blue);
    }

    .twg-stat-card--voting .twg-stat-label {
        color: var(--twg-green);
    }

    .twg-stat-value {
        margin: 0;
        color: #071d35;
        font-size: 2.45rem;
        font-weight: 900;
        line-height: 1;
        letter-spacing: -0.06em;
    }

    .twg-stat-sub {
        margin: 8px 0 0;
        color: var(--twg-muted);
        font-size: 0.82rem;
        font-weight: 600;
    }

    .twg-feature-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .twg-feature-card {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 18px;
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.86);
        border: 1px solid var(--twg-border);
        box-shadow: 0 10px 26px rgba(13, 49, 84, 0.07);
        backdrop-filter: blur(14px);
    }

    .twg-feature-icon {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        border-radius: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fff7dc;
        color: #b07a00;
    }

    .twg-feature-icon svg {
        width: 21px;
        height: 21px;
        stroke: currentColor;
    }

    .twg-feature-title {
        margin: 0 0 4px;
        color: #071d35;
        font-size: 0.92rem;
        font-weight: 900;
    }

    .twg-feature-text {
        margin: 0;
        color: var(--twg-muted);
        font-size: 0.8rem;
        line-height: 1.55;
        font-weight: 500;
    }

    .twg-panel {
        overflow: hidden;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.94);
        border: 1px solid var(--twg-border);
        box-shadow: 0 18px 42px rgba(13, 49, 84, 0.10);
    }

    .twg-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 22px 24px;
        border-bottom: 1px solid var(--twg-border);
        background:
            radial-gradient(circle at top right, rgba(13, 110, 253, 0.08), transparent 28%),
            #fff;
    }

    .twg-panel-title {
        margin: 0;
        color: #071d35;
        font-size: 1.15rem;
        font-weight: 900;
        letter-spacing: -0.02em;
    }

    .twg-panel-sub {
        margin: 4px 0 0;
        color: var(--twg-muted);
        font-size: 0.84rem;
        font-weight: 500;
    }

    .twg-search-wrap {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 300px;
        padding: 9px 12px;
        border-radius: 12px;
        border: 1px solid var(--twg-border);
        background: #f8fbff;
    }

    .twg-search-wrap svg {
        width: 16px;
        height: 16px;
        color: var(--twg-muted);
        stroke: currentColor;
        flex: 0 0 auto;
    }

    .twg-search-wrap input {
        width: 100%;
        border: 0;
        outline: none;
        background: transparent;
        color: var(--twg-text);
        font-size: 0.84rem;
        font-weight: 600;
    }

    .twg-table-wrap {
        padding: 0 24px 24px;
    }

    .twg-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 12px;
        margin: 0;
    }

    .twg-table thead th {
        padding: 16px 14px 8px;
        color: #071d35;
        font-size: 0.72rem;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        border: 0;
    }

    .twg-table tbody tr {
        background: #fff;
        box-shadow: 0 8px 22px rgba(13, 49, 84, 0.07);
    }

    .twg-table tbody td {
        padding: 16px 14px;
        vertical-align: middle;
        border-top: 1px solid #edf2f8;
        border-bottom: 1px solid #edf2f8;
        color: var(--twg-text);
        font-size: 0.86rem;
        font-weight: 600;
    }

    .twg-table tbody td:first-child {
        border-left: 1px solid #edf2f8;
        border-radius: 14px 0 0 14px;
    }

    .twg-table tbody td:last-child {
        border-right: 1px solid #edf2f8;
        border-radius: 0 14px 14px 0;
    }

    .twg-registry {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #071d35;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        font-size: 0.8rem;
        font-weight: 800;
    }

    .twg-registry::before {
        content: "";
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--twg-blue);
        box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.13);
    }

    .twg-property {
        color: #111827;
        font-weight: 850;
    }

    .twg-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 10px;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 900;
        white-space: nowrap;
    }

    .twg-badge--inspection {
        background: var(--twg-orange-soft);
        color: var(--twg-orange);
    }

    .twg-badge--meeting {
        background: var(--twg-blue-soft);
        color: var(--twg-blue);
    }

    .twg-badge--voting {
        background: var(--twg-green-soft);
        color: var(--twg-green);
    }

    .twg-action-group {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
    }

    .twg-table-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 34px;
        padding: 8px 13px;
        border-radius: 10px;
        background: var(--twg-navy);
        color: #fff;
        font-size: 0.78rem;
        font-weight: 900;
        text-decoration: none;
        border: 1px solid var(--twg-navy);
        box-shadow: 0 8px 18px rgba(13, 49, 84, 0.17);
        transition:
            transform 0.18s ease,
            background 0.18s ease,
            box-shadow 0.18s ease;
    }

    .twg-table-btn:hover {
        color: #fff;
        background: var(--twg-navy-dark);
        transform: translateY(-1px);
        box-shadow: 0 12px 22px rgba(13, 49, 84, 0.22);
    }

    .twg-empty {
        padding: 46px 18px;
        text-align: center;
    }

    .twg-empty-icon {
        width: 64px;
        height: 64px;
        margin: 0 auto 16px;
        border-radius: 20px;
        background: var(--twg-blue-soft);
        color: var(--twg-blue);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .twg-empty-icon svg {
        width: 30px;
        height: 30px;
        stroke: currentColor;
    }

    .twg-empty h3 {
        margin: 0 0 6px;
        color: #071d35;
        font-size: 1.05rem;
        font-weight: 900;
    }

    .twg-empty p {
        max-width: 430px;
        margin: 0 auto;
        color: var(--twg-muted);
        font-size: 0.88rem;
        line-height: 1.6;
    }

    @media (max-width: 991.98px) {
        .twg-stats-grid,
        .twg-feature-grid {
            grid-template-columns: 1fr;
        }

        .twg-panel-header {
            align-items: stretch;
            flex-direction: column;
        }

        .twg-search-wrap {
            min-width: 0;
            width: 100%;
        }
    }

    @media (max-width: 767.98px) {
        .twg-dashboard-shell {
            padding: 24px 14px 44px;
        }

        .twg-hero {
            padding: 22px;
        }

        .twg-table-wrap {
            padding: 0 14px 18px;
            overflow-x: auto;
        }

        .twg-table {
            min-width: 760px;
        }
    }
</style>

<script>
    document.body.classList.add('twg-dashboard-page');
</script>

<div class="twg-dashboard-shell">

    <!-- Header -->
    <section class="twg-hero">
        <div class="twg-hero-content">
            <div class="twg-eyebrow">
                <span class="twg-eyebrow-dot"></span>
                LZRC TWG Member Portal
            </div>

            <h1 class="twg-hero-title">LZRC TWG Member Dashboard</h1>

            <p class="twg-hero-sub">
                Review assigned applications, monitor inspection items, track meeting schedules,
                and manage voting tasks from one organized workspace.
            </p>

            <div class="twg-hero-actions">
                <a href="#assigned-tasks" class="twg-btn-primary">
                    View Assigned Tasks
                </a>

                <a href="inspection.php" class="twg-btn-outline">
                    Inspection Queue
                </a>

                <a href="meeting.php" class="twg-btn-outline">
                    Meeting Queue
                </a>

                <a href="voting.php" class="twg-btn-outline">
                    Voting Queue
                </a>
            </div>
        </div>
    </section>

    <!-- Statistics -->
    <section class="twg-stats-grid">

        <a class="twg-stat-card twg-stat-card--inspection" href="inspection.php">
            <div class="twg-stat-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 11l3 3L22 4"></path>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                </svg>
            </div>

            <p class="twg-stat-label">Inspection</p>
            <p class="twg-stat-value"><?= number_format(count($inspection)) ?></p>
            <p class="twg-stat-sub">Applications ready for inspection review</p>
        </a>

        <a class="twg-stat-card twg-stat-card--meeting" href="meeting.php">
            <div class="twg-stat-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>

            <p class="twg-stat-label">Meeting</p>
            <p class="twg-stat-value"><?= number_format(count($meeting)) ?></p>
            <p class="twg-stat-sub">Items waiting for TWG meeting action</p>
        </a>

        <a class="twg-stat-card twg-stat-card--voting" href="voting.php">
            <div class="twg-stat-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 12l2 2 4-4"></path>
                    <path d="M21 12a9 9 0 1 1-9-9"></path>
                    <path d="M16 3h5v5"></path>
                    <path d="M21 3l-7 7"></path>
                </svg>
            </div>

            <p class="twg-stat-label">Voting</p>
            <p class="twg-stat-value"><?= number_format(count($voting)) ?></p>
            <p class="twg-stat-sub">Applications pending voting decision</p>
        </a>

    </section>

    <!-- Feature Shortcuts -->
    <section class="twg-feature-grid">

        <div class="twg-feature-card">
            <div class="twg-feature-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <path d="M14 2v6h6"></path>
                    <path d="M16 13H8"></path>
                    <path d="M16 17H8"></path>
                    <path d="M10 9H8"></path>
                </svg>
            </div>

            <div>
                <h3 class="twg-feature-title">Review Applications</h3>
                <p class="twg-feature-text">
                    Open assigned registry records and inspect required application details.
                </p>
            </div>
        </div>

        <div class="twg-feature-card">
            <div class="twg-feature-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 2v4"></path>
                    <path d="M16 2v4"></path>
                    <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                    <path d="M3 10h18"></path>
                </svg>
            </div>

            <div>
                <h3 class="twg-feature-title">Meeting Queue</h3>
                <p class="twg-feature-text">
                    Track applications that are ready for LZRC TWG meeting discussion.
                </p>
            </div>
        </div>

        <div class="twg-feature-card">
            <div class="twg-feature-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"></path>
                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                </svg>
            </div>

            <div>
                <h3 class="twg-feature-title">Decision Tracking</h3>
                <p class="twg-feature-text">
                    Monitor voting tasks and keep decision actions clear and organized.
                </p>
            </div>
        </div>

    </section>

    <!-- Assigned Tasks -->
    <section class="twg-panel" id="assigned-tasks">

        <div class="twg-panel-header">
            <div>
                <h2 class="twg-panel-title">Assigned TWG Tasks</h2>
                <p class="twg-panel-sub">
                    Applications currently assigned to your TWG member account.
                </p>
            </div>

            <div class="twg-search-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>

                <input
                    type="search"
                    id="twgTaskSearch"
                    placeholder="Search registry, property, or status..."
                    aria-label="Search assigned TWG tasks"
                >
            </div>
        </div>

        <div class="twg-table-wrap">
            <?php if (!empty($assignedTasks)): ?>
                <table class="twg-table" id="twgTasksTable">
                    <thead>
                        <tr>
                            <th>Registry</th>
                            <th>Property</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($assignedTasks as $application): ?>
                            <?php
                                $phaseStatus = $application['phase_status'];
                                $statusLabel = workflow_status_label($phaseStatus);

                                if ($phaseStatus === 'INSPECTION_SCHEDULED') {
                                    $badgeClass = 'twg-badge--inspection';
                                    $actionUrl  = 'inspection.php?id=' . (int)$application['id'];
                                    $actionText = 'Inspect';
                                } elseif ($phaseStatus === 'FOR_MEETING') {
                                    $badgeClass = 'twg-badge--meeting';
                                    $actionUrl  = 'meeting.php?id=' . (int)$application['id'];
                                    $actionText = 'Meeting';
                                } else {
                                    $badgeClass = 'twg-badge--voting';
                                    $actionUrl  = 'voting.php?id=' . (int)$application['id'];
                                    $actionText = 'Vote';
                                }
                            ?>

                            <tr>
                                <td>
                                    <span class="twg-registry">
                                        <?= e($application['registry_number']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="twg-property">
                                        <?= e($application['property_title']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="twg-badge <?= e($badgeClass) ?>">
                                        <?= e($statusLabel) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="twg-action-group">
                                        <a class="twg-table-btn" href="<?= e($actionUrl) ?>">
                                            <?= e($actionText) ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="twg-empty">
                    <div class="twg-empty-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 11l3 3L22 4"></path>
                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                        </svg>
                    </div>

                    <h3>No assigned TWG tasks</h3>

                    <p>
                        You currently have no assigned inspection, meeting, or voting tasks.
                        New assignments will appear here automatically.
                    </p>
                </div>
            <?php endif; ?>
        </div>

    </section>

</div>

<script>
    (function () {
        var searchInput = document.getElementById('twgTaskSearch');
        var table = document.getElementById('twgTasksTable');

        if (!searchInput || !table) {
            return;
        }

        searchInput.addEventListener('input', function () {
            var query = searchInput.value.toLowerCase().trim();
            var rows = table.querySelectorAll('tbody tr');

            rows.forEach(function (row) {
                var text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>