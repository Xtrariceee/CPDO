<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $required = ['account_name', 'account_address', 'property_title', 'property_address'];
    foreach ($required as $field) {
        if (trim($_POST[$field] ?? '') === '') {
            $_SESSION['flash_error'] = 'Please complete all required application fields.';
            redirect('landlord/application-form.php');
        }
    }
    $applicationId = create_application((int)$user['id'], $_POST);
    redirect('landlord/requirements-upload.php?id=' . $applicationId);
}

require __DIR__ . '/../partials/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-outline-secondary btn-sm" href="dashboard.php">← Dashboard</a>
    <div>
        <p class="eyebrow mb-0">Process 1</p>
        <h1 class="h3 mb-0">Requirements &amp; Application Setup</h1>
    </div>
</div>

<div class="row g-4">
    <!-- Requirements checklist -->
    <div class="col-lg-5">
        <div class="gov-card p-4 h-100">
            <h2 class="h5 mb-3">
                <span class="process-badge">19</span>
                Mandatory Requirements
            </h2>
            <?php $num = 0; foreach (required_documents() as $office => $documents): ?>
                <div class="req-office-block">
                    <p class="req-office-label"><?= e($office) ?></p>
                    <ul class="req-list">
                        <?php foreach ($documents as $title): $num++; ?>
                            <li><span class="req-num"><?= $num ?></span><?= e($title) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Application form -->
    <div class="col-lg-7">
        <form class="gov-card p-4" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <h2 class="h5 mb-4">Application Details</h2>

            <div class="mb-3">
                <label class="form-label">Account Name <span class="text-danger">*</span></label>
                <input class="form-control" name="account_name" value="<?= e(user_full_name($user)) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Account Address <span class="text-danger">*</span></label>
                <textarea class="form-control" name="account_address" rows="2" required></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Property Name / Title <span class="text-danger">*</span></label>
                <input class="form-control" name="property_title" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Property Address <span class="text-danger">*</span></label>
                <textarea class="form-control" name="property_address" rows="2" required></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Coordinates <span class="text-secondary small">(optional)</span></label>
                <input class="form-control" name="coordinates" placeholder="e.g. 7.0731, 125.6128">
            </div>
            <div class="mb-4">
                <label class="form-label">Land Title Reference <span class="text-secondary small">(optional)</span></label>
                <input class="form-control" name="land_title_reference" data-sensitive>
                <div class="form-text">Stored encrypted in the database.</div>
            </div>

            <button class="btn btn-primary w-100">Continue to Document Upload →</button>
        </form>
    </div>
</div>

<style>
.process-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--cpdo-blue);
    color: #fff;
    font-size: 13px;
    font-weight: 900;
    margin-right: 8px;
}
.req-office-block {
    margin-bottom: 16px;
}
.req-office-label {
    font-size: .72rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--cpdo-blue);
    margin-bottom: 4px;
}
.req-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.req-list li {
    display: flex;
    gap: 8px;
    font-size: var(--text-sm);
    color: #33485f;
    padding: 3px 0;
    border-bottom: 1px solid #f0f4f8;
}
.req-num {
    flex-shrink: 0;
    width: 18px;
    font-weight: 700;
    color: var(--cpdo-blue);
}
</style>

<?php require __DIR__ . '/../partials/footer.php'; ?>
