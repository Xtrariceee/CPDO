<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = current_user();
if ($user) {
    redirect(dashboard_for_role($user['role']));
}

$baseUrl = rtrim($config['app']['base_url'], '/');
$renteaseLogoUrl = rentease_logo_url($config);

$carouselBaseUrl    = $baseUrl;
$carouselRegisterUrl = $carouselBaseUrl . '/register.php';
$featuredProperties = [
    [
        'badge'   => 'New',
        'title'   => 'Modern Studio',
        'location'=> 'Matina, Davao City',
        'price'   => '₱9,500',
        'details' => ['Studio', '1 Bath', '22 m²'],
        'image'   => 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=900&q=80',
    ],
    [
        'badge'   => 'Popular',
        'title'   => 'Cozy 1-Bedroom',
        'location'=> 'Toril, Davao City',
        'price'   => '₱12,000',
        'details' => ['1 Bed', '1 Bath', '30 m²'],
        'image'   => 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=900&q=80',
    ],
    [
        'badge'   => 'Hot',
        'title'   => 'Townhouse Unit',
        'location'=> 'Buhangin, Davao City',
        'price'   => '₱18,500',
        'details' => ['2 Bed', '2 Bath', '65 m²'],
        'image'   => 'https://images.unsplash.com/photo-1605276374104-dee2a0ed3cd6?auto=format&fit=crop&w=900&q=80',
    ],
    [
        'badge'   => 'Featured',
        'title'   => 'Family Apartment',
        'location'=> 'Ecoland, Davao City',
        'price'   => '₱15,000',
        'details' => ['2 Bed', '1 Bath', '48 m²'],
        'image'   => 'https://images.unsplash.com/photo-1494526585095-c41746248156?auto=format&fit=crop&w=900&q=80',
    ],
    [
        'badge'   => 'Budget',
        'title'   => 'Student Room',
        'location'=> 'Obrero, Davao City',
        'price'   => '₱6,500',
        'details' => ['Room', 'Shared Bath', '18 m²'],
        'image'   => 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=900&q=80',
    ],
    [
        'badge'   => 'Premium',
        'title'   => 'City Condo',
        'location'=> 'Poblacion, Davao City',
        'price'   => '₱22,000',
        'details' => ['1 Bed', '1 Bath', '42 m²'],
        'image'   => 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?auto=format&fit=crop&w=900&q=80',
    ],
];
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
        .re-featured-carousel {
        position: relative;
        margin: 34px 0;
        padding: clamp(24px, 4vw, 42px);
        border-radius: 26px;
        background:
            radial-gradient(circle at top right, rgba(246, 207, 74, 0.18), transparent 32%),
            linear-gradient(135deg, #ffffff 0%, #fffdf6 100%);
        border: 1px solid #f0dfad;
        box-shadow: 0 18px 46px rgba(36, 27, 11, 0.08);
        overflow: hidden;
    }

    .re-featured-carousel::before {
        content: "";
        position: absolute;
        top: -120px;
        left: -120px;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: rgba(246, 207, 74, 0.12);
        filter: blur(12px);
        pointer-events: none;
    }

    .re-fp-header {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: end;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 24px;
    }

    .re-fp-eyebrow {
        margin: 0 0 8px;
        color: #c59000;
        font-size: 0.74rem;
        font-weight: 900;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .re-fp-title {
        margin: 0;
        color: #241b0b;
        font-size: clamp(1.7rem, 3vw, 2.35rem);
        font-weight: 950;
        letter-spacing: -0.04em;
        line-height: 1.08;
    }

    .re-fp-subtitle {
        margin: 10px 0 0;
        max-width: 620px;
        color: #76684b;
        font-size: 0.98rem;
        line-height: 1.65;
        font-weight: 500;
    }

    .re-fp-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 0 0 auto;
    }

    .re-fp-arrow {
        width: 42px;
        height: 42px;
        border-radius: 999px;
        border: 1px solid #f0dfad;
        background: #fff;
        color: #241b0b;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        font-weight: 900;
        cursor: pointer;
        box-shadow: 0 10px 24px rgba(36, 27, 11, 0.08);
        transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease;
    }

    .re-fp-arrow:hover {
        transform: translateY(-2px);
        background: #fff7d6;
        border-color: #e6b82f;
    }

    .re-fp-track-wrap {
        position: relative;
        z-index: 2;
        overflow: hidden;
    }

    .re-fp-track {
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: minmax(270px, 32%);
        gap: 20px;
        overflow-x: auto;
        scroll-snap-type: x mandatory;
        scroll-behavior: smooth;
        scrollbar-width: none;
        padding: 4px 2px 18px;
    }

    .re-fp-track::-webkit-scrollbar {
        display: none;
    }

    .re-fp-card {
        scroll-snap-align: start;
        overflow: hidden;
        border-radius: 22px;
        background: #fff;
        border: 1px solid #f0dfad;
        box-shadow: 0 16px 34px rgba(36, 27, 11, 0.10);
        color: inherit;
        text-decoration: none;
        transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
    }

    .re-fp-card:hover {
        transform: translateY(-6px);
        border-color: #e6b82f;
        box-shadow: 0 22px 44px rgba(36, 27, 11, 0.14);
        color: inherit;
    }

    .re-fp-media {
        position: relative;
        height: 210px;
        background:
            linear-gradient(135deg, rgba(246, 207, 74, 0.24), rgba(255, 247, 214, 0.86)),
            linear-gradient(135deg, #f8f2dc, #ffffff);
        overflow: hidden;
    }

    .re-fp-media img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.35s ease;
    }

    .re-fp-card:hover .re-fp-media img {
        transform: scale(1.06);
    }

    .re-fp-media.is-fallback::after {
        content: "RentEase";
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #c59000;
        font-size: 1.4rem;
        font-weight: 950;
        letter-spacing: -0.03em;
    }

    .re-fp-badge {
        position: absolute;
        top: 16px;
        left: 16px;
        z-index: 2;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 30px;
        padding: 7px 12px;
        border-radius: 999px;
        background: rgba(36, 27, 11, 0.78);
        color: #fff;
        font-size: 0.72rem;
        font-weight: 800;
        backdrop-filter: blur(12px);
    }

    .re-fp-body {
        padding: 20px 20px 22px;
    }

    .re-fp-name {
        margin: 0 0 10px;
        color: #241b0b;
        font-size: 1.1rem;
        font-weight: 900;
        letter-spacing: -0.02em;
    }

    .re-fp-location {
        display: flex;
        align-items: center;
        gap: 7px;
        margin: 0 0 16px;
        color: #76684b;
        font-size: 0.9rem;
        font-weight: 600;
    }

    .re-fp-location span {
        color: #c59000;
    }

    .re-fp-price {
        margin: 0 0 18px;
        color: #241b0b;
        font-size: 1.18rem;
        font-weight: 950;
        letter-spacing: -0.03em;
    }

    .re-fp-price small {
        color: #76684b;
        font-size: 0.84rem;
        font-weight: 700;
    }

    .re-fp-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .re-fp-tag {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 30px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #fff7d6;
        color: #6f540c;
        font-size: 0.78rem;
        font-weight: 750;
    }

    .re-fp-footer {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        margin-top: 18px;
    }

    .re-fp-dots {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        flex: 1;
    }

    .re-fp-dot {
        width: 9px;
        height: 9px;
        border-radius: 999px;
        background: #dfd6bd;
        border: 0;
        padding: 0;
        cursor: pointer;
        transition: width 0.18s ease, background 0.18s ease;
    }

    .re-fp-dot.is-active {
        width: 24px;
        background: #f6cf4a;
    }

    .re-fp-see-more {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 44px;
        padding: 12px 22px;
        border-radius: 999px;
        background: #f6cf4a;
        color: #241b0b;
        font-size: 0.9rem;
        font-weight: 900;
        text-decoration: none;
        border: 1px solid #e6b82f;
        box-shadow: 0 12px 28px rgba(197, 144, 0, 0.20);
        transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
        white-space: nowrap;
    }

    .re-fp-see-more:hover {
        color: #241b0b;
        transform: translateY(-2px);
        box-shadow: 0 16px 34px rgba(197, 144, 0, 0.26);
        opacity: 0.95;
    }

    @media (max-width: 991.98px) {
        .re-fp-track {
            grid-auto-columns: minmax(270px, 48%);
        }
    }

    @media (max-width: 767.98px) {
        .re-featured-carousel {
            padding: 24px 16px;
        }

        .re-fp-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .re-fp-actions {
            width: 100%;
            justify-content: flex-end;
        }

        .re-fp-track {
            grid-auto-columns: minmax(260px, 86%);
        }

        .re-fp-footer {
            align-items: stretch;
            flex-direction: column;
        }

        .re-fp-see-more {
            width: 100%;
        }
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

    <!-- Featured Properties Carousel -->
    <div style="max-width:1200px;margin:0 auto;padding:0 24px;">
    <section class="re-featured-carousel" data-featured-carousel>
    <div class="re-fp-header">
        <div>
            <p class="re-fp-eyebrow">Featured Rentals</p>
            <h2 class="re-fp-title">Featured Properties</h2>
            <p class="re-fp-subtitle">
                Handpicked rentals around Davao City — great locations, fair prices, and easy access through RentEase.
            </p>
        </div>

        <div class="re-fp-actions">
            <button class="re-fp-arrow" type="button" data-carousel-prev aria-label="Previous property">
                ‹
            </button>

            <button class="re-fp-arrow" type="button" data-carousel-next aria-label="Next property">
                ›
            </button>
        </div>
    </div>

    <div class="re-fp-track-wrap">
        <div class="re-fp-track" data-carousel-track>
            <?php foreach ($featuredProperties as $property): ?>
                <a class="re-fp-card" href="<?= e($carouselRegisterUrl) ?>">
                    <div class="re-fp-media">
                        <span class="re-fp-badge"><?= e($property['badge']) ?></span>

                        <img
                            src="<?= e($property['image']) ?>"
                            alt="<?= e($property['title']) ?> rental property"
                            loading="lazy"
                            onerror="this.style.display='none'; this.closest('.re-fp-media').classList.add('is-fallback');"
                        >
                    </div>

                    <div class="re-fp-body">
                        <h3 class="re-fp-name"><?= e($property['title']) ?></h3>

                        <p class="re-fp-location">
                            <span>●</span>
                            <?= e($property['location']) ?>
                        </p>

                        <p class="re-fp-price">
                            <?= e($property['price']) ?> <small>/ mo</small>
                        </p>

                        <div class="re-fp-tags">
                            <?php foreach ($property['details'] as $detail): ?>
                                <span class="re-fp-tag"><?= e($detail) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="re-fp-footer">
        <div class="re-fp-dots" data-carousel-dots aria-label="Carousel pagination"></div>

        <a class="re-fp-see-more" href="<?= e($carouselRegisterUrl) ?>">
            See more →
        </a>
    </div>
</section>
    </div>

<script>
    (function () {
        document.querySelectorAll('[data-featured-carousel]').forEach(function (carousel) {
            var track = carousel.querySelector('[data-carousel-track]');
            var prev = carousel.querySelector('[data-carousel-prev]');
            var next = carousel.querySelector('[data-carousel-next]');
            var dotsWrap = carousel.querySelector('[data-carousel-dots]');
            var cards = Array.prototype.slice.call(carousel.querySelectorAll('.re-fp-card'));

            if (!track || !cards.length) { return; }

            var currentIndex = 0;
            var autoTimer = null;

            function getStep() {
                var firstCard = cards[0];
                var styles = window.getComputedStyle(track);
                var gap = parseFloat(styles.columnGap || styles.gap || 20);
                return firstCard.offsetWidth + gap;
            }
            function getVisibleCount() { return Math.max(1, Math.round(track.offsetWidth / getStep())); }
            function getMaxIndex() { return Math.max(0, cards.length - getVisibleCount()); }

            function scrollToIndex(index) {
                currentIndex = Math.max(0, Math.min(index, getMaxIndex()));
                track.scrollTo({ left: currentIndex * getStep(), behavior: 'smooth' });
                updateDots();
            }

            function updateDots() {
                if (!dotsWrap) { return; }
                var max = getMaxIndex();
                dotsWrap.querySelectorAll('.re-fp-dot').forEach(function (dot, index) {
                    dot.classList.toggle('is-active', index === Math.min(currentIndex, max));
                });
            }

            function buildDots() {
                if (!dotsWrap) { return; }
                dotsWrap.innerHTML = '';
                var totalDots = getMaxIndex() + 1;
                for (var i = 0; i < totalDots; i++) {
                    var dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 're-fp-dot' + (i === 0 ? ' is-active' : '');
                    dot.setAttribute('aria-label', 'Go to property slide ' + (i + 1));
                    (function (index) {
                        dot.addEventListener('click', function () { stopAuto(); scrollToIndex(index); startAuto(); });
                    })(i);
                    dotsWrap.appendChild(dot);
                }
            }

            function nextSlide() {
                var max = getMaxIndex();
                scrollToIndex(currentIndex >= max ? 0 : currentIndex + 1);
            }
            function prevSlide() {
                var max = getMaxIndex();
                scrollToIndex(currentIndex <= 0 ? max : currentIndex - 1);
            }
            function startAuto() { stopAuto(); autoTimer = window.setInterval(nextSlide, 4500); }
            function stopAuto() { if (autoTimer) { window.clearInterval(autoTimer); autoTimer = null; } }

            if (next) { next.addEventListener('click', function () { stopAuto(); nextSlide(); startAuto(); }); }
            if (prev) { prev.addEventListener('click', function () { stopAuto(); prevSlide(); startAuto(); }); }

            track.addEventListener('scroll', function () {
                window.requestAnimationFrame(function () {
                    currentIndex = Math.round(track.scrollLeft / getStep());
                    updateDots();
                });
            });

            carousel.addEventListener('mouseenter', stopAuto);
            carousel.addEventListener('mouseleave', startAuto);
            window.addEventListener('resize', function () {
                buildDots();
                scrollToIndex(Math.min(currentIndex, getMaxIndex()));
            });

            buildDots();
            startAuto();
        });
    }());
</script>

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