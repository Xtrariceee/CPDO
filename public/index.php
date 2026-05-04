<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = current_user();
if ($user) {
    redirect(dashboard_for_role($user['role']));
}

require __DIR__ . '/partials/header.php';
?>
<section class="landing-hero">
    <div class="hero-glass">
        <p class="eyebrow">CPDO + Rental Compliance Platform</p>
        <h1>Land reclassification workflows and rental readiness in one calm, secure portal.</h1>
        <p class="hero-copy">Digitize Davao City CPDO Processes 1-14, verify compliance before property listing, and preserve auditable decisions from submission to final endorsement.</p>
        <div class="hero-actions">
            <a class="btn btn-primary btn-lg" href="register.php">Register</a>
            <a class="btn btn-outline-primary btn-lg" href="login.php">Login</a>
        </div>
    </div>
    <div class="hero-orbit-card">
        <span>Workflow Progress</span>
        <strong>P1-P14</strong>
        <small>Documents, payment, inspection, deliberation, endorsement</small>
    </div>
</section>

<section class="feature-grid">
    <article class="feature-card">
        <span class="feature-icon">01</span>
        <h2>Regulatory Workflow</h2>
        <p>Role-specific pages guide officers through pre-evaluation, payment, inspection, meetings, voting, and final resolution.</p>
    </article>
    <article class="feature-card">
        <span class="feature-icon">02</span>
        <h2>Verified Listings</h2>
        <p>Landlords can only create rentals after approval or verified legal documents, keeping listings compliant by design.</p>
    </article>
    <article class="feature-card">
        <span class="feature-icon">03</span>
        <h2>Secure Records</h2>
        <p>Bcrypt authentication, OTP verification, encrypted sensitive fields, audit logs, and session timeout protect the process.</p>
    </article>
</section>

<section class="testimonial-band">
    <div>
        <p class="quote">"A single, traceable place for applicants, officers, and committee members to move from requirements to resolution."</p>
        <span>Designed for professional G2C service delivery</span>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
