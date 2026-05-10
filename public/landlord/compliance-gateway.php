<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$user = require_role([ROLE_LANDLORD]);
$status = landlord_compliance_status((int)$user['id']);

if ($status['state'] === 'ELIGIBLE') {
    // ELIGIBLE means fully verified — redirect to the landlord dashboard
    // where they can manage and add property listings.
    redirect('landlord/dashboard.php');
}

$isUnderReview = $status['state'] === 'UNDER_REVIEW';

require __DIR__ . '/../partials/header.php';
?>

<style>
    :root {
        --re-ink: #241b0b;
        --re-muted: #76684b;
        --re-soft: #fff7d6;
        --re-soft-2: #fffdf5;
        --re-gold: #f6cf4a;
        --re-gold-dark: #c59000;
        --re-border: #f0dfad;
        --re-danger: #b42318;
        --re-danger-soft: #fff2f0;
        --re-success: #157347;
        --re-success-soft: #ecfdf3;
        --re-info: #0d6efd;
        --re-info-soft: #eaf3ff;
        --re-shadow: 0 18px 44px rgba(36, 27, 11, 0.08);
        --re-shadow-strong: 0 24px 60px rgba(36, 27, 11, 0.12);
    }

    body.rentease-compliance-page {
        background:
            radial-gradient(circle at 1px 1px, rgba(246, 207, 74, 0.18) 1px, transparent 0),
            linear-gradient(180deg, #ffffff 0%, #fffdf7 100%);
        background-size: 28px 28px, 100% 100%;
        color: var(--re-ink);
    }

    .gateway-shell {
        max-width: 1180px;
        margin: 0 auto;
        padding: 34px 18px 64px;
    }

    .gateway-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 22px;
    }

    .gateway-back {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 40px;
        padding: 9px 15px;
        border-radius: 11px;
        background: #ffffff;
        color: var(--re-ink);
        border: 1px solid var(--re-border);
        text-decoration: none;
        font-size: 0.84rem;
        font-weight: 850;
        box-shadow: 0 10px 24px rgba(36, 27, 11, 0.06);
        transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .gateway-back:hover {
        color: var(--re-ink);
        background: var(--re-soft);
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(36, 27, 11, 0.09);
    }

    .gateway-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: <?= $isUnderReview ? 'var(--re-danger-soft)' : 'var(--re-info-soft)' ?>;
        color: <?= $isUnderReview ? 'var(--re-danger)' : 'var(--re-info)' ?>;
        border: 1px solid <?= $isUnderReview ? '#ffc9c2' : '#cfe2ff' ?>;
        font-size: 0.78rem;
        font-weight: 900;
    }

    .gateway-status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: currentColor;
        box-shadow: 0 0 0 4px currentColor;
        opacity: 0.18;
    }

    .gateway-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: clamp(26px, 4vw, 44px);
        margin-bottom: 26px;
        background:
            radial-gradient(circle at top right, rgba(246, 207, 74, 0.28), transparent 36%),
            linear-gradient(135deg, #ffffff 0%, #fffdf5 100%);
        border: 1px solid var(--re-border);
        box-shadow: var(--re-shadow);
    }

    .gateway-hero::before {
        content: "";
        position: absolute;
        right: -100px;
        top: -100px;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: rgba(246, 207, 74, 0.16);
        pointer-events: none;
    }

    .gateway-hero-content {
        position: relative;
        z-index: 2;
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
        gap: 28px;
        align-items: center;
    }

    .gateway-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
        padding: 7px 13px;
        border-radius: 999px;
        background: var(--re-soft);
        color: var(--re-gold-dark);
        font-size: 0.72rem;
        font-weight: 950;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .gateway-eyebrow span {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--re-gold-dark);
        box-shadow: 0 0 0 4px rgba(197, 144, 0, 0.16);
    }

    .gateway-title {
        margin: 0;
        color: var(--re-ink);
        font-size: clamp(2rem, 5vw, 3.4rem);
        font-weight: 950;
        line-height: 1.02;
        letter-spacing: -0.055em;
    }

    .gateway-title mark {
        padding: 0;
        background: linear-gradient(90deg, var(--re-gold-dark), #7a5700);
        color: transparent;
        -webkit-background-clip: text;
        background-clip: text;
    }

    .gateway-subtitle {
        max-width: 720px;
        margin: 16px 0 0;
        color: var(--re-muted);
        font-size: 1rem;
        line-height: 1.75;
        font-weight: 550;
    }

    .gateway-hero-card {
        position: relative;
        padding: 24px;
        border-radius: 22px;
        background: rgba(255, 255, 255, 0.86);
        border: 1px solid var(--re-border);
        box-shadow: 0 16px 34px rgba(36, 27, 11, 0.07);
        backdrop-filter: blur(12px);
    }

    .gateway-hero-card-title {
        margin: 0 0 12px;
        color: var(--re-ink);
        font-size: 0.95rem;
        font-weight: 950;
    }

    .gateway-mini-list {
        display: grid;
        gap: 12px;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .gateway-mini-list li {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr);
        gap: 10px;
        align-items: start;
        color: var(--re-muted);
        font-size: 0.84rem;
        line-height: 1.45;
        font-weight: 600;
    }

    .gateway-mini-icon {
        width: 34px;
        height: 34px;
        border-radius: 11px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--re-soft);
        color: var(--re-gold-dark);
    }

    .gateway-mini-icon svg {
        width: 18px;
        height: 18px;
        stroke: currentColor;
    }

    .gateway-alert {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        margin-bottom: 24px;
        padding: 18px;
        border-radius: 18px;
        border: 1px solid <?= $isUnderReview ? '#ffc9c2' : 'var(--re-border)' ?>;
        background: <?= $isUnderReview ? 'var(--re-danger-soft)' : '#ffffff' ?>;
        box-shadow: 0 14px 34px rgba(36, 27, 11, 0.06);
    }

    .gateway-alert-icon {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: <?= $isUnderReview ? '#ffe0dc' : 'var(--re-soft)' ?>;
        color: <?= $isUnderReview ? 'var(--re-danger)' : 'var(--re-gold-dark)' ?>;
    }

    .gateway-alert-icon svg {
        width: 22px;
        height: 22px;
        stroke: currentColor;
    }

    .gateway-alert-title {
        margin: 0 0 4px;
        color: var(--re-ink);
        font-size: 0.96rem;
        font-weight: 950;
    }

    .gateway-alert-text {
        margin: 0;
        color: var(--re-muted);
        font-size: 0.88rem;
        line-height: 1.65;
        font-weight: 550;
    }

    .gateway-rule-box {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        margin-bottom: 26px;
        padding: 20px;
        border-radius: 20px;
        background: #fff8dd;
        border: 1px solid #e6b82f;
        box-shadow: 0 14px 34px rgba(197, 144, 0, 0.10);
    }

    .gateway-rule-icon {
        width: 44px;
        height: 44px;
        flex: 0 0 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        background: #241b0b;
        color: #f6cf4a;
    }

    .gateway-rule-icon svg {
        width: 23px;
        height: 23px;
        stroke: currentColor;
    }

    .gateway-rule-title {
        margin: 0 0 5px;
        color: var(--re-ink);
        font-size: 1rem;
        font-weight: 950;
    }

    .gateway-rule-text {
        margin: 0;
        color: #5e4b1b;
        font-size: 0.9rem;
        line-height: 1.65;
        font-weight: 650;
    }

    .gateway-path-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 22px;
        margin-bottom: 26px;
    }

    .gateway-path-card {
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        min-height: 420px;
        padding: 26px;
        border-radius: 24px;
        background:
            radial-gradient(circle at top right, rgba(246, 207, 74, 0.14), transparent 36%),
            #ffffff;
        border: 1px solid var(--re-border);
        box-shadow: var(--re-shadow);
        transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
    }

    .gateway-path-card:hover {
        transform: translateY(-5px);
        border-color: #e6b82f;
        box-shadow: var(--re-shadow-strong);
    }

    .gateway-path-card.is-muted {
        opacity: 0.72;
    }

    .gateway-path-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 22px;
    }

    .gateway-path-icon {
        width: 64px;
        height: 64px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--re-ink);
        color: var(--re-gold);
        box-shadow: 0 14px 28px rgba(36, 27, 11, 0.16);
    }

    .gateway-path-card--upload .gateway-path-icon {
        background: var(--re-soft);
        color: var(--re-gold-dark);
        border: 1px solid #e6b82f;
        box-shadow: 0 14px 28px rgba(197, 144, 0, 0.12);
    }

    .gateway-path-icon svg {
        width: 30px;
        height: 30px;
        stroke: currentColor;
    }

    .gateway-tag {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 28px;
        padding: 6px 10px;
        border-radius: 999px;
        background: var(--re-soft);
        color: var(--re-gold-dark);
        font-size: 0.7rem;
        font-weight: 950;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .gateway-tag--required {
        background: var(--re-danger-soft);
        color: var(--re-danger);
    }

    .gateway-path-title {
        margin: 0 0 12px;
        color: var(--re-ink);
        font-size: clamp(1.25rem, 2vw, 1.55rem);
        font-weight: 950;
        letter-spacing: -0.035em;
        line-height: 1.18;
    }

    .gateway-path-text {
        margin: 0 0 20px;
        color: var(--re-muted);
        font-size: 0.95rem;
        line-height: 1.7;
        font-weight: 550;
    }

    .gateway-checklist {
        display: grid;
        gap: 10px;
        margin: 0 0 24px;
        padding: 0;
        list-style: none;
    }

    .gateway-checklist li {
        display: grid;
        grid-template-columns: 22px minmax(0, 1fr);
        gap: 10px;
        align-items: start;
        color: #4f442d;
        font-size: 0.84rem;
        line-height: 1.5;
        font-weight: 650;
    }

    .gateway-checklist svg {
        width: 20px;
        height: 20px;
        margin-top: 1px;
        color: var(--re-success);
        stroke: currentColor;
    }

    .gateway-checklist .warn svg {
        color: var(--re-danger);
    }

    .gateway-action-wrap {
        margin-top: auto;
    }

    .gateway-btn-primary,
    .gateway-btn-outline,
    .gateway-btn-disabled {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        width: 100%;
        min-height: 48px;
        padding: 12px 18px;
        border-radius: 13px;
        font-size: 0.9rem;
        font-weight: 950;
        text-decoration: none;
        border: 1px solid transparent;
        transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease, background 0.18s ease;
    }

    .gateway-btn-primary {
        background: var(--re-gold);
        color: var(--re-ink);
        border-color: #e6b82f;
        box-shadow: 0 14px 26px rgba(197, 144, 0, 0.22);
    }

    .gateway-btn-primary:hover {
        color: var(--re-ink);
        transform: translateY(-1px);
        box-shadow: 0 18px 34px rgba(197, 144, 0, 0.28);
    }

    .gateway-btn-outline {
        background: #ffffff;
        color: var(--re-ink);
        border-color: #e6b82f;
    }

    .gateway-btn-outline:hover {
        color: var(--re-ink);
        background: var(--re-soft);
        transform: translateY(-1px);
    }

    .gateway-btn-disabled {
        background: #f3f1ea;
        color: #9a8c6a;
        border-color: #e5ddc6;
        pointer-events: none;
        cursor: not-allowed;
    }

    .gateway-btn-primary svg,
    .gateway-btn-outline svg,
    .gateway-btn-disabled svg {
        width: 17px;
        height: 17px;
        stroke: currentColor;
    }

    .gateway-process-panel {
        display: grid;
        grid-template-columns: 0.9fr 1.1fr;
        gap: 22px;
        margin-bottom: 26px;
        padding: 24px;
        border-radius: 24px;
        background: #ffffff;
        border: 1px solid var(--re-border);
        box-shadow: var(--re-shadow);
    }

    .gateway-process-title {
        margin: 0 0 10px;
        color: var(--re-ink);
        font-size: 1.25rem;
        font-weight: 950;
        letter-spacing: -0.03em;
    }

    .gateway-process-text {
        margin: 0;
        color: var(--re-muted);
        font-size: 0.9rem;
        line-height: 1.7;
        font-weight: 550;
    }

    .gateway-steps {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .gateway-step {
        padding: 15px;
        border-radius: 16px;
        background: var(--re-soft-2);
        border: 1px solid var(--re-border);
    }

    .gateway-step-num {
        width: 30px;
        height: 30px;
        margin-bottom: 10px;
        border-radius: 10px;
        background: var(--re-soft);
        color: var(--re-gold-dark);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.78rem;
        font-weight: 950;
    }

    .gateway-step strong {
        display: block;
        margin-bottom: 5px;
        color: var(--re-ink);
        font-size: 0.84rem;
        font-weight: 950;
    }

    .gateway-step span {
        display: block;
        color: var(--re-muted);
        font-size: 0.76rem;
        line-height: 1.45;
        font-weight: 600;
    }

    .gateway-help-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
    }

    .gateway-help-card {
        padding: 18px;
        border-radius: 18px;
        background: #ffffff;
        border: 1px solid var(--re-border);
        box-shadow: 0 12px 28px rgba(36, 27, 11, 0.06);
    }

    .gateway-help-card h3 {
        margin: 0 0 7px;
        color: var(--re-ink);
        font-size: 0.95rem;
        font-weight: 950;
    }

    .gateway-help-card p {
        margin: 0;
        color: var(--re-muted);
        font-size: 0.82rem;
        line-height: 1.6;
        font-weight: 550;
    }

    @media (max-width: 991.98px) {
        .gateway-hero-content,
        .gateway-path-grid,
        .gateway-process-panel {
            grid-template-columns: 1fr;
        }

        .gateway-steps {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .gateway-help-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 575.98px) {
        .gateway-shell {
            padding: 24px 14px 44px;
        }

        .gateway-top {
            align-items: stretch;
            flex-direction: column;
        }

        .gateway-status-pill,
        .gateway-back {
            width: 100%;
            justify-content: center;
        }

        .gateway-path-card {
            min-height: auto;
            padding: 22px;
        }

        .gateway-path-top {
            flex-direction: column;
        }

        .gateway-steps {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
    document.body.classList.add('rentease-compliance-page');
</script>

<div class="gateway-shell">

    <div class="gateway-top">
        <a class="gateway-back" href="dashboard.php">
            <span aria-hidden="true">&larr;</span>
            Dashboard
        </a>

        <div class="gateway-status-pill">
            <span class="gateway-status-dot"></span>
            <?= e($status['label'] ?? ($isUnderReview ? 'Under Review' : 'Compliance Required')) ?>
        </div>
    </div>

    <section class="gateway-hero">
        <div class="gateway-hero-content">
            <div>
                <div class="gateway-eyebrow">
                    <span></span>
                    Compliance Gateway
                </div>

                <h1 class="gateway-title">
                    Publish only properties with valid <mark>zoning approval.</mark>
                </h1>

                <p class="gateway-subtitle">
                    A CPDO zoning clearance or reclassification / rezoning resolution is required before a landlord can proceed with business-related legal documents and property listing verification.
                </p>
            </div>

            <aside class="gateway-hero-card">
                <h2 class="gateway-hero-card-title">Important rule</h2>

                <ul class="gateway-mini-list">
                    <li>
                        <span class="gateway-mini-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 12l2 2 4-4"></path>
                                <path d="M21 12a9 9 0 1 1-9-9"></path>
                            </svg>
                        </span>
                        <span>Option B is only for landlords who already have CPDO zoning clearance or an approved resolution.</span>
                    </li>

                    <li>
                        <span class="gateway-mini-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <path d="M14 2v6h6"></path>
                                <path d="M16 13H8"></path>
                                <path d="M16 17H8"></path>
                            </svg>
                        </span>
                        <span>If the property has no zoning clearance, the landlord must use the CPDO workflow first.</span>
                    </li>

                    <li>
                        <span class="gateway-mini-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 10.5 12 3l9 7.5"></path>
                                <path d="M5 10v10h14V10"></path>
                                <path d="M9 20v-6h6v6"></path>
                            </svg>
                        </span>
                        <span>Business permits and other legal documents must be supported by proper zoning compliance.</span>
                    </li>
                </ul>
            </aside>
        </div>
    </section>

    <div class="gateway-alert" role="alert">
        <div class="gateway-alert-icon" aria-hidden="true">
            <?php if ($isUnderReview): ?>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M12 6v6l4 2"></path>
                </svg>
            <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <path d="M12 9v4"></path>
                    <path d="M12 17h.01"></path>
                </svg>
            <?php endif; ?>
        </div>

        <div>
            <p class="gateway-alert-title">
                <?= $isUnderReview ? 'Compliance review already in progress' : 'Zoning approval is the prerequisite' ?>
            </p>

            <p class="gateway-alert-text">
                <?php if ($isUnderReview): ?>
                    You already have a compliance record under review. Listing creation remains locked until the submission is approved or verified.
                <?php else: ?>
                    If your property has no CPDO zoning clearance or approved rezoning / reclassification resolution,
                    you cannot proceed through Option B. Start the CPDO workflow first to obtain the required zoning basis.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="gateway-rule-box">
        <div class="gateway-rule-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 12l2 2 4-4"></path>
                <path d="M21 12a9 9 0 1 1-9-9"></path>
            </svg>
        </div>

        <div>
            <p class="gateway-rule-title">Option B requires existing CPDO approval.</p>
            <p class="gateway-rule-text">
                A landlord may only upload business/legal documents under Option B if the property already has a valid
                zoning clearance, approved reclassification, approved rezoning, or equivalent CPDO-issued resolution.
                Without this, the landlord must complete Option A first.
            </p>
        </div>
    </div>

    <section class="gateway-path-grid">

        <article class="gateway-path-card <?= $isUnderReview ? 'is-muted' : '' ?>">
            <div class="gateway-path-top">
                <div class="gateway-path-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 10.5 12 3l9 7.5"></path>
                        <path d="M5 10v10h14V10"></path>
                        <path d="M9 20v-6h6v6"></path>
                    </svg>
                </div>

                <span class="gateway-tag">Required if no clearance</span>
            </div>

            <h2 class="gateway-path-title">
                Start CPDO Reclassification / Rezoning
            </h2>

            <p class="gateway-path-text">
                Use this path if the property has no proper zoning clearance or reclassification / rezoning resolution.
                This begins the CPDO process needed before business registration and legal document verification.
            </p>

            <ul class="gateway-checklist">
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"></path>
                    </svg>
                    <span>For properties that are not yet cleared for their intended rental or business use.</span>
                </li>

                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"></path>
                    </svg>
                    <span>Includes application details, document upload, payment, inspection, TWG meeting, deliberation, and resolution.</span>
                </li>

                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"></path>
                    </svg>
                    <span>After approval, the resulting CPDO resolution or clearance can support business/legal document verification.</span>
                </li>
            </ul>

            <div class="gateway-action-wrap">
                <?php if ($isUnderReview): ?>
                    <a class="gateway-btn-disabled" href="#" aria-disabled="true">
                        Under Review
                    </a>
                <?php else: ?>
                    <a class="gateway-btn-primary" href="application-form.php">
                        Start CPDO Workflow
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14"></path>
                            <path d="m12 5 7 7-7 7"></path>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>
        </article>

        <article class="gateway-path-card gateway-path-card--upload <?= $isUnderReview ? 'is-muted' : '' ?>">
            <div class="gateway-path-top">
                <div class="gateway-path-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <path d="M14 2v6h6"></path>
                        <path d="m9 15 2 2 4-4"></path>
                    </svg>
                </div>

                <span class="gateway-tag gateway-tag--required">Requires CPDO approval</span>
            </div>

            <h2 class="gateway-path-title">
                Already Cleared — Upload Business Legal Documents
            </h2>

            <p class="gateway-path-text">
                Use this path only if your property already has valid CPDO zoning clearance or an approved
                reclassification / rezoning resolution. These are prerequisites before business registration documents can be accepted.
                <strong>Uploading documents here does not automatically unlock listing.</strong>
                An Administrative Officer must review and verify all submitted documents before your listing is enabled.
            </p>

            <ul class="gateway-checklist">
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"></path>
                    </svg>
                    <span>Upload CPDO resolution, zoning clearance, or equivalent proof of zoning compliance.</span>
                </li>

                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"></path>
                    </svg>
                    <span>Then upload business/legal documents such as permits, FSIC, sanitary permit, and BIR registration.</span>
                </li>

                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"></path>
                    </svg>
                    <span>An Administrative Officer will review your documents. Listing is unlocked only after verification is complete — not upon upload.</span>
                </li>

                <li class="warn">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 9v4"></path>
                        <path d="M12 17h.01"></path>
                        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    </svg>
                    <span>If you do not have zoning clearance or CPDO resolution, do not use Option B. Start the CPDO workflow first.</span>
                </li>
            </ul>

            <div class="gateway-action-wrap">
                <?php if ($isUnderReview): ?>
                    <a class="gateway-btn-disabled" href="#" aria-disabled="true">
                        Under Review
                    </a>
                <?php else: ?>
                    <a class="gateway-btn-outline" href="skip-compliance.php">
                        I Already Have CPDO Approval
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14"></path>
                            <path d="m12 5 7 7-7 7"></path>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>
        </article>

    </section>

    <section class="gateway-process-panel">
        <div>
            <h2 class="gateway-process-title">Correct compliance sequence</h2>
            <p class="gateway-process-text">
                Business registration documents should be supported by zoning compliance. If zoning compliance does not yet exist,
                the landlord must secure it first through the CPDO process.
            </p>
        </div>

        <div class="gateway-steps">
            <div class="gateway-step">
                <div class="gateway-step-num">01</div>
                <strong>Check zoning status</strong>
                <span>Confirm whether the property already has CPDO clearance or resolution.</span>
            </div>

            <div class="gateway-step">
                <div class="gateway-step-num">02</div>
                <strong>Secure CPDO basis</strong>
                <span>If missing, complete reclassification or rezoning workflow first.</span>
            </div>

            <div class="gateway-step">
                <div class="gateway-step-num">03</div>
                <strong>Upload legal docs</strong>
                <span>After CPDO approval, submit business and compliance documents.</span>
            </div>

            <div class="gateway-step">
                <div class="gateway-step-num">04</div>
                <strong>List property</strong>
                <span>Once verified, the landlord can publish the rental listing.</span>
            </div>
        </div>
    </section>

    <section class="gateway-help-grid">
        <article class="gateway-help-card">
            <h3>When should I choose Option A?</h3>
            <p>
                Choose Option A if the property has no zoning clearance, no approved reclassification, or no rezoning resolution from CPDO.
            </p>
        </article>

        <article class="gateway-help-card">
            <h3>When should I choose Option B?</h3>
            <p>
                Choose Option B only if you already have CPDO-issued zoning clearance or an approved resolution, plus the required business/legal documents.
            </p>
        </article>

        <article class="gateway-help-card">
            <h3>Why is this required?</h3>
            <p>
                Zoning compliance is the basis for whether the property can legally support the intended rental or business use.
            </p>
        </article>
    </section>

</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>