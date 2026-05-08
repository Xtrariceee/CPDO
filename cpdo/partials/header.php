<?php
$user    = current_user();
$cpdoUrl = rtrim($config['app']['cpdo_url'] ?? str_replace('/public', '/cpdo', $config['app']['base_url']), '/');
$pubUrl  = rtrim($config['app']['base_url'], '/');
$cpdoLogoUrl = cpdo_logo_url($config);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CPDO Staff Portal — <?= e($config['app']['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($pubUrl) ?>/assets/css/app.css" rel="stylesheet">
    <style>
        /* ── CPDO government portal — solid authoritative theme ── */

        /* Page background: subtle dot-grid on light slate */
        body {
            background-color: #eef2f7;
            background-image: radial-gradient(circle, #c8d4e3 1px, transparent 1px);
            background-size: 28px 28px;
            min-height: 100vh;
        }

        /* Top government header bar — matches login page */
        .topbar {
            background: #0b2a4a !important;
            border-bottom: 3px solid #1d6aad;
            box-shadow: 0 2px 12px rgba(11,42,74,.25);
            padding-top: 0;
            padding-bottom: 0;
        }

        /* Seal mark */
        .gov-nav-seal {
            width: 34px; height: 34px; border-radius: 50%;
            background: #fff;
            border: 2px solid rgba(255,255,255,.25);
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .gov-nav-seal-inner {
            width: 26px; height: 26px; border-radius: 50%;
            background: linear-gradient(135deg, #0b2a4a, #1d6aad);
            display: flex; align-items: center; justify-content: center;
            font-size: .55rem; font-weight: 900; color: #fff;
            letter-spacing: .02em; text-align: center; line-height: 1.1;
        }

        /* Staff badge pill */
        .cpdo-portal-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 9px; border-radius: 4px;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.18);
            color: rgba(255,255,255,.7);
            font-size: .65rem; font-weight: 800;
            letter-spacing: .08em; text-transform: uppercase;
        }
        .cpdo-portal-badge-dot {
            width: 5px; height: 5px; border-radius: 50%;
            background: #f59e0b; flex-shrink: 0;
        }

        .topbar .navbar-brand { font-weight: 800; font-size: .95rem; }
        .topbar .nav-link { font-size: .83rem; font-weight: 600; opacity: .8; }
        .topbar .nav-link:hover { opacity: 1; }

        /* Page content area — slightly inset feel */
        .page-shell {
            background: transparent;
        }

        /* Cards in the CPDO portal get a clean white look */
        .gov-card, .gov-card-inner {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(11,42,74,.05), 0 6px 20px rgba(11,42,74,.07);
        }

        /* Override the default app.css card for CPDO portal */
        .gov-card.p-4, .gov-card.p-3 {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(11,42,74,.05), 0 6px 20px rgba(11,42,74,.07);
        }

        /* Sidebar links — government style */
        .side-panel {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(11,42,74,.06);
        }
        .side-link { color: #3a5068; font-weight: 600; }
        .side-link:hover, .side-link.active {
            background: #eef2f7;
            color: #0b2a4a;
        }

        /* Table headers — government slate */
        .table thead th {
            background: #eef2f7;
            color: #0b2a4a;
            border-bottom: 2px solid #c5d3df;
        }

        /* Metric cards */
        .metric-card {
            background: #fff;
            border: 1px solid #d0dae6;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(11,42,74,.06);
        }
        .metric-card strong { color: #0b2a4a; }

        /* Buttons — government primary is navy */
        .btn-primary {
            background: #0b2a4a;
            border-color: #0b2a4a;
            box-shadow: 0 2px 8px rgba(11,42,74,.22);
        }
        .btn-primary:hover, .btn-primary:focus {
            background: #0e3560;
            border-color: #0e3560;
            box-shadow: 0 4px 14px rgba(11,42,74,.3);
        }

        /* Activity items */
        .activity-item {
            background: #f4f8fc;
            border: 1px solid #d0dae6;
            border-radius: 6px;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark topbar">
    <div class="container-fluid page-shell py-0" style="padding-top:10px!important;padding-bottom:10px!important;">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e($cpdoUrl) ?>/index.php">
            <div class="gov-nav-seal">
                <?php if ($cpdoLogoUrl): ?>
                    <img class="cpdo-logo-img cpdo-logo-img--nav" src="<?= e($cpdoLogoUrl) ?>" alt="CPDO logo">
                <?php else: ?>
                    <div class="gov-nav-seal-inner">CPDO</div>
                <?php endif; ?>
            </div>
            <div>
                <span style="display:block;font-size:.9rem;font-weight:800;line-height:1.2;">CPDO Land Portal</span>
                <span style="display:block;font-size:.62rem;color:rgba(255,255,255,.5);font-weight:500;line-height:1;">City Planning &amp; Development Office</span>
            </div>
            <span class="cpdo-portal-badge ms-1">
                <span class="cpdo-portal-badge-dot"></span>
                Staff
            </span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <?php if ($user): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e(dashboard_for_role($user['role'])) ?>">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link"><?= e(user_full_name($user)) ?> · <?= e(role_label($user['role'])) ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-outline-light btn-sm" href="<?= e($cpdoUrl) ?>/logout.php">Logout</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e($cpdoUrl) ?>/login.php">Staff Login</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main class="page-shell">
    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger"><?= e($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success"><?= e($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>
