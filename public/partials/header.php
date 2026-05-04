<?php
$user = current_user();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($config['app']['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(rtrim($config['app']['base_url'], '/')) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark topbar">
    <div class="container-fluid page-shell py-0">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(rtrim($config['app']['base_url'], '/')) ?>/index.php">
            <span class="brand-mark">CP</span>
            <span>CPDO Land Portal</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <?php if ($user): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e(rtrim($config['app']['base_url'], '/') . '/' . dashboard_for_role($user['role'])) ?>">Dashboard</a></li>
                    <li class="nav-item"><span class="nav-link"><?= e(user_full_name($user)) ?> · <?= e(role_label($user['role'])) ?></span></li>
                    <li class="nav-item"><a class="btn btn-outline-light btn-sm" href="<?= e(rtrim($config['app']['base_url'], '/')) ?>/logout.php">Logout</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e(rtrim($config['app']['base_url'], '/')) ?>/login.php">Login</a></li>
                    <li class="nav-item"><a class="btn btn-light btn-sm" href="<?= e(rtrim($config['app']['base_url'], '/')) ?>/register.php">Register</a></li>
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
