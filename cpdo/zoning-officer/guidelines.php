<?php
/**
 * CPDO — Zoning Officer: Manage Requirement Guidelines
 * Allows the Zoning Officer (or System Admin) to edit the title and detailed
 * guidance text for each of the 18 mandatory requirements. Changes are stored
 * in requirement_guidelines and override the code defaults for all landlords.
 */
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
verify_csrf();

// ── Handle save ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $upsert = db()->prepare(
        'INSERT INTO requirement_guidelines
            (requirement_key, group_name, title, details, is_active, updated_by)
         VALUES (?, ?, ?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE
            group_name  = VALUES(group_name),
            title       = VALUES(title),
            details     = VALUES(details),
            is_active   = 1,
            updated_by  = VALUES(updated_by)'
    );

    $allDocs = required_documents();
    $saved   = 0;

    foreach ($allDocs as $group => $docs) {
        foreach ($docs as $key => $default) {
            $newTitle   = trim($_POST['title_'   . $key] ?? '');
            $newDetails = trim($_POST['details_' . $key] ?? '');

            // Only save if the officer actually changed something
            if ($newTitle === '' && $newDetails === '') {
                continue;
            }
            $finalTitle   = $newTitle   !== '' ? $newTitle   : $default['title'];
            $finalDetails = $newDetails !== '' ? $newDetails : $default['details'];

            $upsert->execute([$key, $group, $finalTitle, $finalDetails, (int)$user['id']]);
            $saved++;
        }
    }

    audit_log((int)$user['id'], 'GUIDELINES_UPDATED', 'requirement_guidelines', null, ['rows_saved' => $saved]);
    $_SESSION['flash_success'] = 'Guidelines saved. Changes will be visible to landlords immediately.';
    redirect('zoning-officer/guidelines.php');
}

// ── Load current overrides ────────────────────────────────────────────────────
$overrideRows = db()->query(
    'SELECT requirement_key, title, details FROM requirement_guidelines WHERE is_active = 1'
)->fetchAll(PDO::FETCH_KEY_PAIR); // key => title only — need both cols

// Re-fetch as assoc keyed by requirement_key
$overrides = [];
foreach (db()->query('SELECT requirement_key, title, details FROM requirement_guidelines WHERE is_active = 1')->fetchAll() as $row) {
    $overrides[$row['requirement_key']] = $row;
}

$allDocs = required_documents();

// Flatten to a numbered list for display
$flatList = [];
$num      = 0;
foreach ($allDocs as $group => $docs) {
    foreach ($docs as $key => $default) {
        $num++;
        $flatList[] = [
            'num'            => $num,
            'key'            => $key,
            'group'          => $group,
            'default_title'  => $default['title'],
            'default_details'=> $default['details'],
            'saved_title'    => $overrides[$key]['title']   ?? null,
            'saved_details'  => $overrides[$key]['details'] ?? null,
        ];
    }
}

require __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Guidelines page — government solid theme ── */
.gl-section-header {
    background: #eef2f7;
    border: 1px solid #d0dae6;
    border-left: 4px solid #1d6aad;
    border-radius: 6px;
    padding: 10px 16px;
    margin: 28px 0 0;
    display: flex;
    align-items: baseline;
    gap: 10px;
}
.gl-section-label {
    font-size: .68rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #1d6aad;
    white-space: nowrap;
}
.gl-section-name {
    font-size: .85rem;
    font-weight: 700;
    color: #0b2a4a;
}

.gl-req-row {
    border: 1px solid #e8eef5;
    border-radius: 8px;
    padding: 16px 18px;
    margin-top: 10px;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
}
.gl-req-row:hover {
    border-color: #b0c4d8;
    box-shadow: 0 2px 8px rgba(11,42,74,.06);
}
.gl-req-row.is-overridden {
    border-color: #1d6aad;
    background: #f8fbff;
}

.gl-req-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: #0b2a4a;
    color: #fff;
    font-size: .7rem;
    font-weight: 900;
    flex-shrink: 0;
    margin-right: 10px;
    margin-top: 2px;
}

.gl-req-title-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 10px;
}

.gl-default-label {
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #9aaabd;
    margin-bottom: 3px;
}
.gl-default-text {
    font-size: .8rem;
    color: #62748a;
    line-height: 1.5;
    font-style: italic;
}

.gl-field-label {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #3a5068;
    margin-bottom: 5px;
    display: block;
}
.gl-input {
    border: 1.5px solid #c5d3df;
    border-radius: 6px;
    background: #f8fbff;
    color: #121212;
    font-size: .85rem;
    padding: 8px 11px;
    width: 100%;
    transition: border-color .15s, box-shadow .15s;
}
.gl-input:focus {
    border-color: #1d6aad;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(29,106,173,.12);
    outline: none;
}
textarea.gl-input {
    min-height: 80px;
    resize: vertical;
    line-height: 1.55;
}

.gl-override-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: .65rem;
    font-weight: 800;
    letter-spacing: .04em;
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #bfdbfe;
    white-space: nowrap;
    flex-shrink: 0;
}

.gl-save-bar {
    position: sticky;
    bottom: 0;
    background: #fff;
    border-top: 1px solid #d0dae6;
    padding: 14px 0;
    margin-top: 32px;
    z-index: 10;
    box-shadow: 0 -4px 16px rgba(11,42,74,.08);
}
.gl-save-btn {
    padding: 10px 28px;
    border-radius: 6px;
    border: none;
    background: #0b2a4a;
    color: #fff;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    transition: background .15s, box-shadow .15s;
    box-shadow: 0 2px 8px rgba(11,42,74,.22);
}
.gl-save-btn:hover { background: #0e3560; box-shadow: 0 4px 14px rgba(11,42,74,.30); }

.gl-reset-btn {
    padding: 9px 18px;
    border-radius: 6px;
    border: 1.5px solid #c5d3df;
    background: transparent;
    color: #62748a;
    font-weight: 700;
    font-size: .85rem;
    cursor: pointer;
    transition: background .15s, border-color .15s;
}
.gl-reset-btn:hover { background: #f4f8fc; border-color: #9aaabd; color: #3a5068; }
</style>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="index.php"><span aria-hidden="true">&larr;</span> Dashboard</a>
    <div>
        <p class="eyebrow mb-0">Zoning Officer</p>
        <h1 class="h3 mb-0">Requirement Guidelines</h1>
    </div>
    <div class="ms-auto">
        <span class="badge text-bg-primary"><?= count($flatList) ?> Requirements</span>
        <?php $overrideCount = count($overrides); ?>
        <?php if ($overrideCount > 0): ?>
            <span class="badge text-bg-info ms-1"><?= $overrideCount ?> Customized</span>
        <?php endif; ?>
    </div>
</div>

<div class="gov-card p-4 mb-4">
    <div class="d-flex align-items-start gap-3">
        <div style="font-size:.78rem;font-weight:900;color:#1d6aad;flex-shrink:0;margin-top:3px;">INFO</div>
        <div>
            <p class="fw-bold mb-1" style="color:#0b2a4a;">About This Page</p>
            <p class="small text-secondary mb-0">
                Edit the title and guidance details for each of the 18 mandatory requirements.
                Changes saved here override the system defaults and are immediately visible to landlords
                on their document upload page. Leave a field blank to keep the current default.
            </p>
        </div>
    </div>
</div>

<form method="post" id="guidelines-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <?php
    $currentGroup = '';
    foreach ($flatList as $req):
        if ($currentGroup !== $req['group']):
            $currentGroup = $req['group'];
    ?>
        <div class="gl-section-header">
            <span class="gl-section-label">Issuing Office</span>
            <span class="gl-section-name"><?= e($currentGroup) ?></span>
        </div>
    <?php endif; ?>

    <?php
        $hasOverride = isset($overrides[$req['key']]);
        $currentTitle   = $hasOverride ? $overrides[$req['key']]['title']   : '';
        $currentDetails = $hasOverride ? $overrides[$req['key']]['details'] : '';
    ?>
    <div class="gl-req-row <?= $hasOverride ? 'is-overridden' : '' ?>" id="row-<?= e($req['key']) ?>">
        <div class="gl-req-title-row">
            <span class="gl-req-num"><?= $req['num'] ?></span>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <strong style="font-size:.88rem;color:#0b2a4a;"><?= e($req['default_title']) ?></strong>
                    <?php if ($hasOverride): ?>
                        <span class="gl-override-badge">Customized</span>
                    <?php endif; ?>
                </div>
                <div class="gl-default-label">Issuing office</div>
                <div class="gl-default-text"><?= e($req['group']) ?></div>
            </div>
        </div>

        <div class="row g-3">
            <!-- Title override -->
            <div class="col-12">
                <label class="gl-field-label" for="title-<?= e($req['key']) ?>">
                    Requirement Title
                    <span style="font-weight:400;color:#9aaabd;text-transform:none;letter-spacing:0;">
                        — leave blank to use default
                    </span>
                </label>
                <input
                    class="gl-input"
                    type="text"
                    id="title-<?= e($req['key']) ?>"
                    name="title_<?= e($req['key']) ?>"
                    value="<?= e($currentTitle) ?>"
                    placeholder="<?= e($req['default_title']) ?>"
                    maxlength="255"
                >
            </div>

            <!-- Details override -->
            <div class="col-12">
                <label class="gl-field-label" for="details-<?= e($req['key']) ?>">
                    Guidance Details
                    <span style="font-weight:400;color:#9aaabd;text-transform:none;letter-spacing:0;">
                        — shown to landlords as a dropdown on the upload page
                    </span>
                </label>
                <textarea
                    class="gl-input"
                    id="details-<?= e($req['key']) ?>"
                    name="details_<?= e($req['key']) ?>"
                    placeholder="<?= e($req['default_details']) ?>"
                    rows="3"
                ><?= e($currentDetails) ?></textarea>
            </div>

            <!-- Show current default for reference -->
            <div class="col-12">
                <details style="margin-top:2px;">
                    <summary style="font-size:.72rem;font-weight:700;color:#9aaabd;cursor:pointer;user-select:none;letter-spacing:.04em;text-transform:uppercase;">
                        View system default
                    </summary>
                    <div style="margin-top:8px;padding:10px 14px;background:#f4f8fc;border-radius:6px;border-left:3px solid #c5d3df;">
                        <p class="gl-default-label mb-1">Default Title</p>
                        <p class="gl-default-text mb-2"><?= e($req['default_title']) ?></p>
                        <p class="gl-default-label mb-1">Default Details</p>
                        <p class="gl-default-text mb-0"><?= e($req['default_details']) ?></p>
                    </div>
                </details>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Sticky save bar -->
    <div class="gl-save-bar">
        <div class="d-flex align-items-center gap-3">
            <button type="submit" class="gl-save-btn">Save All Guidelines</button>
            <button type="reset" class="gl-reset-btn">Reset Unsaved Changes</button>
            <span class="text-secondary small ms-2">
                Changes apply immediately to all landlord upload pages.
            </span>
        </div>
    </div>
</form>

<script>
// Mark rows as modified when the officer types in them
(function () {
    document.querySelectorAll('.gl-input').forEach(function (input) {
        input.addEventListener('input', function () {
            var row = input.closest('.gl-req-row');
            if (row) row.style.borderColor = '#f59e0b';
        });
    });
}());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
