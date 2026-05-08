<?php
$user    = current_user();
$baseUrl = rtrim($config['app']['base_url'], '/');
$cpdoUrl = rtrim($config['app']['cpdo_url'] ?? str_replace('/public', '/cpdo', $baseUrl), '/');
$renteaseLogoUrl = rentease_logo_url($config);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RentEase — <?= e($config['app']['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($baseUrl) ?>/assets/css/app.css" rel="stylesheet">
    <?php if ($renteaseLogoUrl): ?>
        <link rel="icon" type="image/png" href="<?= e($renteaseLogoUrl) ?>">
    <?php endif; ?>
    <style>
        /* ── RentEase public portal — dark gradient navbar ── */
        body {
            background: #fff;
            min-height: 100vh;
        }

        /* Navbar — matches the login page dark gradient */
        .topbar {
            background: #fff;
            border-bottom: 2px solid #f0dfad;
            box-shadow: 0 2px 16px rgba(36,27,11,.07);
        }

        /* Brand mark */
        .re-nav-mark {
            width: 38px; height: 38px; border-radius: 50%;
            background: #241b0b;
            border: 1px solid #f0dfad;
            overflow: hidden;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 900; font-size: .8rem; color: #f6cf4a; flex-shrink: 0;
        }

        .topbar .navbar-brand { font-weight: 800; font-size: 1rem; color: #241b0b !important; }
        .topbar .nav-link { font-size: .85rem; font-weight: 600; opacity: .8; }
        .topbar .nav-link:hover { opacity: 1; }
    </style>
</head>
<body class="rental-interface">
<nav class="navbar navbar-expand-lg navbar-light topbar">
    <div class="container-fluid page-shell py-0">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e($baseUrl) ?>/index.php">
            <span class="re-nav-mark">
                <?php if ($renteaseLogoUrl): ?>
                    <img class="rentease-logo-img" src="<?= e($renteaseLogoUrl) ?>" alt="RentEase logo">
                <?php else: ?>
                    RE
                <?php endif; ?>
            </span>
            <span>RentEase</span>
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
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e($baseUrl) ?>/logout.php">Logout</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= e($baseUrl) ?>/login.php">Sign In</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-primary btn-sm fw-bold" href="<?= e($baseUrl) ?>/register.php">Get Started</a>
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
