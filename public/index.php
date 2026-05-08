<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = current_user();
if ($user) {
    redirect(dashboard_for_role($user['role']));
}

$baseUrl = rtrim($config['app']['base_url'], '/');
$renteaseLogoUrl = rentease_logo_url($config);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RentEase — Rental Management System</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e($baseUrl) ?>/assets/css/app.css" rel="stylesheet">

    <?php if ($renteaseLogoUrl): ?>
        <link rel="icon" type="image/png" href="<?= e($renteaseLogoUrl) ?>">
    <?php endif; ?>

    <style>
        html, body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        .re-page {
            min-height: 100vh;
            background: #fff;
            position: relative;
            overflow-x: hidden;
            color: #241b0b;
        }

        .re-nav {
            position: relative;
            z-index: 10;
            padding: 20px 0;
            border-bottom: 2px solid #f0dfad;
            background: #fff;
        }

        .re-nav-brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .re-nav-mark {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #241b0b;
            border: 1px solid #f0dfad;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: .85rem;
            color: #f6cf4a;
        }

        .re-nav-name {
            font-size: 1.1rem;
            font-weight: 800;
            color: #241b0b;
        }

        .re-nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .re-nav-link {
            color: #76684b;
            font-size: .88rem;
            font-weight: 600;
            text-decoration: none;
            padding: 7px 14px;
            border-radius: 8px;
            transition: .2s;
        }

        .re-nav-link:hover {
            background: #fff7d6;
            color: #241b0b;
        }

        .re-nav-btn {
            padding: 8px 20px;
            border-radius: 8px;
            font-size: .88rem;
            font-weight: 700;
            background: #f6cf4a;
            color: #241b0b;
            text-decoration: none;
            border: 1px solid #e6b82f;
            box-shadow: 0 4px 14px rgba(197,144,0,.20);
            transition: .2s;
        }

        .re-nav-btn:hover {
            opacity: .9;
            transform: translateY(-1px);
            color: #241b0b;
        }

        .re-hero {
            padding: clamp(60px, 10vh, 120px) 0 clamp(40px, 6vh, 80px);
        }

        .re-hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 999px;
            background: #fff7d6;
            border: 1px solid #f0dfad;
            color: #8a6400;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 24px;
        }

        .re-hero-eyebrow-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #c59000;
        }

        .re-hero-title {
            font-size: clamp(2.6rem, 6vw, 5rem);
            font-weight: 900;
            line-height: 1.04;
            letter-spacing: -.03em;
            color: #241b0b;
            margin-bottom: 24px;
        }

        .grad {
            background: linear-gradient(90deg, #c59000 0%, #7a5700 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .re-hero-sub {
            font-size: 1.1rem;
            color: #76684b;
            line-height: 1.7;
            max-width: 520px;
            margin-bottom: 36px;
        }

        .re-hero-cta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .re-cta-primary {
            padding: 13px 28px;
            border-radius: 10px;
            font-size: .95rem;
            font-weight: 700;
            background: #f6cf4a;
            color: #241b0b;
            text-decoration: none;
            box-shadow: 0 4px 18px rgba(197,144,0,.22);
        }

        .re-cta-secondary {
            padding: 13px 28px;
            border-radius: 10px;
            font-size: .95rem;
            font-weight: 700;
            background: #fff;
            border: 1px solid #f0dfad;
            color: #3a2d12;
            text-decoration: none;
        }

        .re-stat-card {
            background: #fff;
            border: 1px solid #f0dfad;
            border-radius: 20px;
            padding: 28px 24px;
            box-shadow: 0 8px 32px rgba(36,27,11,.08);
        }

        .stat-num {
            font-size: 2.8rem;
            font-weight: 900;
            line-height: 1;
            background: linear-gradient(90deg, #c59000, #7a5700);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-label {
            font-size: .78rem;
            font-weight: 700;
            color: #8a6400;
            text-transform: uppercase;
            margin-top: 6px;
        }

        .stat-desc {
            font-size: .82rem;
            color: #76684b;
            margin-top: 4px;
            line-height: 1.5;
        }

        .re-features {
            padding: clamp(40px,6vh,80px) 0;
        }

        .re-section-label {
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #8a6400;
            margin-bottom: 14px;
        }

        .re-section-title {
            font-size: clamp(1.6rem, 3vw, 2.4rem);
            font-weight: 900;
            color: #241b0b;
            margin-bottom: 48px;
        }

        .re-feature-card {
            background: #fff;
            border: 1px solid #f0dfad;
            border-radius: 18px;
            padding: 28px 24px;
            height: 100%;
            transition: .2s;
        }

        .re-feature-card:hover {
            transform: translateY(-4px);
            border-color: #e6b82f;
        }

        .re-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #fff7d6;
            color: #8a6400;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 18px;
            font-weight: 700;
        }

        .re-feature-title {
            font-size: 1rem;
            font-weight: 800;
            color: #241b0b;
            margin-bottom: 8px;
        }

        .re-feature-desc {
            font-size: .85rem;
            color: #76684b;
            line-height: 1.65;
        }

        .re-footer {
            border-top: 1px solid #f0dfad;
            padding: 24px 0;
        }

        .re-footer-text {
            font-size: .78rem;
            color: #76684b;
        }

        .re-footer-link {
            color: #8a6400;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>

<body class="rental-interface">

<div class="re-page">

    <!-- Navbar -->
    <nav class="re-nav">
        <div class="container-fluid" style="max-width:1200px;margin:0 auto;padding:0 24px;">
            <div class="d-flex align-items-center justify-content-between">

                <a class="re-nav-brand" href="<?= e($baseUrl) ?>/index.php">
                    <span class="re-nav-mark">
                        <?php if ($renteaseLogoUrl): ?>
                            <img class="rentease-logo-img" src="<?= e($renteaseLogoUrl) ?>" alt="RentEase logo">
                        <?php else: ?>
                            RE
                        <?php endif; ?>
                    </span>

                    <span class="re-nav-name">RentEase</span>
                </a>

                <div class="re-nav-actions">
                    <a class="re-nav-link d-none d-sm-inline" href="<?= e($baseUrl) ?>/login.php">
                        Sign In
                    </a>

                    <a class="re-nav-btn" href="<?= e($baseUrl) ?>/register.php">
                        Get Started
                    </a>
                </div>

            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="re-hero">
        <div class="container-fluid" style="max-width:1200px;margin:0 auto;padding:0 24px;">

            <div class="row align-items-center g-5">

                <div class="col-lg-7">

                    <div class="re-hero-eyebrow">
                        <span class="re-hero-eyebrow-dot"></span>
                        Rental Management System
                    </div>

                    <h1 class="re-hero-title">
                        Manage rentals smarter<br>
                        with <span class="grad">RentEase.</span>
                    </h1>

                    <p class="re-hero-sub">
                        RentEase has never been this easy. Simplify property management, track rent payments, manage tenants, handle maintenance requests, and organize lease records — all in one powerful platform.
                    </p>

                    <div class="re-hero-cta">
                        <a class="re-cta-primary" href="<?= e($baseUrl) ?>/register.php">
                            Create Free Account
                        </a>

                        <a class="re-cta-secondary" href="<?= e($baseUrl) ?>/login.php">
                            Sign In
                        </a>
                    </div>

                </div>

                <div class="col-lg-5 d-none d-lg-block">

                    <div class="re-stat-card mb-3">
                        <div class="stat-num">ALL</div>
                        <div class="stat-label">In One Platform</div>
                        <div class="stat-desc">
                            Manage properties, tenants, payments, leases, and maintenance from one dashboard.
                        </div>
                    </div>

                    <div class="row g-3">

                        <div class="col-6">
                            <div class="re-stat-card">
                                <div class="stat-num">24/7</div>
                                <div class="stat-label">Access</div>
                                <div class="stat-desc">
                                    Landlords and tenants can access rental information anytime.
                                </div>
                            </div>
                        </div>

                        <div class="col-6">
                            <div class="re-stat-card">
                                <div class="stat-num">EASY</div>
                                <div class="stat-label">Management</div>
                                <div class="stat-desc">
                                    Simple tools for tracking rent, leases, and tenant communication.
                                </div>
                            </div>
                        </div>

                    </div>

                </div>

            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="re-features">

        <div class="container-fluid" style="max-width:1200px;margin:0 auto;padding:0 24px;">

            <p class="re-section-label">Platform Features</p>

            <h2 class="re-section-title">
                Built for landlords and tenants.
            </h2>

            <div class="row g-4">

                <div class="col-md-4">
                    <div class="re-feature-card">

                        <div class="re-feature-icon">HOME</div>

                        <h3 class="re-feature-title">
                            Property Management
                        </h3>

                        <p class="re-feature-desc">
                            Add, update, and organize rental properties with complete details, pricing, availability, and tenant information.
                        </p>

                    </div>
                </div>

                <div class="col-md-4">
                    <div class="re-feature-card">

                        <div class="re-feature-icon">USER</div>

                        <h3 class="re-feature-title">
                            Tenant Management
                        </h3>

                        <p class="re-feature-desc">
                            Keep tenant profiles, lease agreements, rental history, and communication records organized in one secure place.
                        </p>

                    </div>
                </div>

                <div class="col-md-4">
                    <div class="re-feature-card">

                        <div class="re-feature-icon">PAY</div>

                        <h3 class="re-feature-title">
                            Rent Tracking
                        </h3>

                        <p class="re-feature-desc">
                            Monitor payments, due dates, balances, and transaction history to simplify rent collection and reporting.
                        </p>

                    </div>
                </div>

            </div>

        </div>

    </section>

    <!-- Footer -->
    <footer class="re-footer">

        <div class="container-fluid" style="max-width:1200px;margin:0 auto;padding:0 24px;">

            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">

                <p class="re-footer-text mb-0">
                    © <?= date('Y') ?> RentEase · Rental Management System
                </p>

                <a class="re-footer-link" href="<?= e($baseUrl) ?>/register.php">
                    RentEase has never been this easy →
                </a>

            </div>

        </div>

    </footer>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>