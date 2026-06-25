<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

$propertyId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$propertyId) {
    http_response_code(400);
    exit('Invalid property ID.');
}

// Fetch property
$stmt = db()->prepare('SELECT * FROM properties WHERE id = ? AND landlord_id = ?');
$stmt->execute([$propertyId, (int)$user['id']]);
$property = $stmt->fetch();

if (!$property) {
    http_response_code(404);
    exit('Property not found.');
}

// Double check compliance is still valid for this address before letting them proceed
$compStatus = property_compliance_status((int)$user['id'], $property['address']);
if ($compStatus['state'] !== 'ELIGIBLE') {
    $_SESSION['flash_error'] = 'Address compliance check is required for "' . e($property['address']) . '" before you can proceed.';
    redirect('landlord/compliance-gateway.php?address=' . urlencode($property['address']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $houseRules = trim($_POST['house_rules'] ?? '');
    $accepted = !empty($_POST['accept_rules']);
    $isDraft = !empty($_POST['save_draft']);

    if ($isDraft) {
        $stmt = db()->prepare(
            'UPDATE properties
             SET house_rules = ?
             WHERE id = ? AND landlord_id = ?'
        );
        $stmt->execute([$houseRules ?: null, $propertyId, (int)$user['id']]);
        $_SESSION['flash_success'] = 'Draft saved successfully.';
        redirect('landlord/dashboard.php');
    }

    if (empty($houseRules)) {
        $_SESSION['flash_error'] = 'You must list at least one listing-specific house rule.';
        redirect('landlord/house-rules.php?id=' . $propertyId);
    }

    if (!$accepted) {
        $_SESSION['flash_error'] = 'You must accept the mandatory house rules and guidelines to activate this listing.';
        redirect('landlord/house-rules.php?id=' . $propertyId);
    }

    $stmt = db()->prepare(
        'UPDATE properties
         SET house_rules = ?, rules_accepted = 1, rules_accepted_at = NOW()
         WHERE id = ? AND landlord_id = ?'
    );
    $stmt->execute([$houseRules ?: null, $propertyId, (int)$user['id']]);
    audit_log((int)$user['id'], 'PROPERTY_RULES_ACCEPTED', 'properties', $propertyId);

    $_SESSION['flash_success'] = 'Guidelines accepted. Proceed to add property details.';
    redirect('landlord/property-details.php?id=' . $propertyId);
}

require __DIR__ . '/../partials/header.php';

$categories = [
    'shared_spaces' => [
        'title' => 'Shared Spaces (bathrooms, kitchen, common area)',
        'rules' => [
            'Clean up kitchen counters and stove immediately after cooking.',
            'Do not leave personal toiletries in the shared bathroom.',
            'Wipe down the bathroom sink and mirror after use.',
            'No loud music or noise in common areas after 10:00 PM.',
            'Shared refrigerator space must be kept clean; label your items.',
            'Wash and dry your dishes right after eating; do not leave them in the sink.'
        ]
    ],
    'individual_premises' => [
        'title' => 'Individual / Within Premises',
        'rules' => [
            'No alterations, painting, or drilling into bedroom walls without prior permission.',
            'Guests are not allowed in individual rooms past 11:00 PM.',
            'No overnight visitors in individual rooms unless approved by the landlord.',
            'No subletting of individual rooms or beds.',
            'Maintain cleanliness and proper ventilation in the room.'
        ]
    ],
    'monthly_payments' => [
        'title' => 'Monthly Rental Payments',
        'rules' => [
            'Monthly rent must be paid on or before the 5th of each month.',
            'A late fee of 5% will be charged for payments made after the 10th.',
            'Payments must be made via bank transfer or GCash; cash is not preferred.',
            'Bounced checks will incur a fee of PHP 1,000 plus bank charges.',
            'Provide a copy of the payment receipt/transaction details to the landlord immediately.'
        ]
    ],
    'general_conduct' => [
        'title' => 'General Conduct',
        'rules' => [
            'Treat all other tenants and neighbors with respect and courtesy.',
            'No illegal drugs or activities within the property premises.',
            'No smoking or vaping anywhere inside the building.',
            'No loud parties or social gatherings allowed without prior notification to the landlord.',
            'Keep noise levels to a minimum at all times, especially between 10:00 PM and 7:00 AM.',
            'No pets allowed within the premises without written consent.'
        ]
    ],
    'room_etiquette' => [
        'title' => 'Room Etiquette',
        'rules' => [
            'Turn off all lights, fans, and air conditioning units when leaving the room.',
            'Do not consume food inside the bedroom to avoid attracting pests.',
            'Keep clothes and personal items inside closets/drawers; do not hang them out of windows.',
            'Do not store hazardous or highly flammable materials in the room.'
        ]
    ],
    'garbage_disposal' => [
        'title' => 'Garbage Disposal',
        'rules' => [
            'Segregate garbage into biodegradable, non-biodegradable, and recyclable bins.',
            'Dispose of garbage daily in the designated central collection area.',
            'Do not leave trash bags outside the room door or in common hallways.',
            'Wash and dry recyclable containers before placing them in recycling bins.',
            'Keep the trash bins covered at all times to prevent odor and pests.'
        ]
    ],
    'safety_security' => [
        'title' => 'Safety and Security',
        'rules' => [
            'Ensure the main gate and entrance doors are locked at all times.',
            'Do not share gate keys, access cards, or door codes with non-residents.',
            'Report any broken locks, windows, or security issues immediately.',
            'Do not leave electrical appliances plugged in unattended (e.g., irons, hair straighteners).',
            'Know the location of the fire extinguisher and emergency exits.'
        ]
    ]
];
?>

<style>
    .rules-card {
        background: #ffffff;
        border: 1px solid #f0d99f;
        border-radius: 24px;
        box-shadow: 0 16px 36px rgba(36, 27, 11, 0.06);
        overflow: hidden;
    }

    .rules-header {
        background: linear-gradient(135deg, #241b0b 0%, #3a2d12 100%);
        color: #fff7d6;
        padding: 32px 28px;
        border-bottom: 3px solid #f6cf4a;
    }

    .rules-body {
        padding: 32px 28px;
    }

    .rule-item {
        display: grid;
        grid-template-columns: 42px minmax(0, 1fr);
        gap: 16px;
        margin-bottom: 24px;
        align-items: start;
    }

    .rule-icon {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        background: #fff7d6;
        color: #c59000;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 1.1rem;
    }

    .rule-title {
        font-weight: 850;
        color: #241b0b;
        margin-bottom: 4px;
        font-size: 1.05rem;
    }

    .rule-desc {
        color: #76684b;
        font-size: 0.92rem;
        line-height: 1.6;
        margin-bottom: 0;
    }

    .compliance-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 999px;
        background: #ecfdf3;
        color: #157347;
        border: 1px solid #a3cfbb;
        font-size: 0.82rem;
        font-weight: 800;
    }

    .custom-rules-box {
        background: #fffdf5;
        border: 1px solid #f0dfad;
        border-radius: 16px;
        padding: 24px;
        margin-top: 32px;
        margin-bottom: 32px;
    }

    /* Drag & Drop Builder Specifics */
    .rules-builder-box {
        background: #fffdf5;
        border: 1.5px solid #f0dfad;
        border-radius: 20px;
        padding: 28px;
        margin-top: 32px;
        margin-bottom: 32px;
        box-shadow: 0 8px 24px rgba(36,27,11,0.02);
    }
    
    .rules-accordion .accordion-item {
        border: 1px solid #f0dfad;
        background: #fff;
        margin-bottom: 8px;
        border-radius: 8px !important;
        overflow: hidden;
    }
    
    .rules-accordion .accordion-button {
        background: #fffdf5;
        color: #241b0b;
        font-weight: 700;
        font-size: 0.9rem;
        padding: 12px 16px;
        box-shadow: none;
    }
    
    .rules-accordion .accordion-button:not(.collapsed) {
        background: #fff9e6;
        color: #241b0b;
        border-bottom: 1px solid #f0dfad;
    }
    
    .rules-accordion .accordion-button::after {
        filter: sepia(100%) hue-rotate(5deg) saturate(200%);
    }
    
    .template-rule-item {
        background: #fff;
        border: 1px solid #f0e6cc;
        border-radius: 8px;
        padding: 10px 12px;
        margin-bottom: 8px;
        cursor: grab;
        font-size: 0.85rem;
        color: #5b4d30;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        user-select: none;
    }
    
    .template-rule-item:hover {
        background: #fffdf0;
        border-color: #f6cf4a;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(246, 207, 74, 0.15);
    }
    
    .template-rule-item:active {
        cursor: grabbing;
    }
    
    .active-rules-dropzone {
        border: 2px dashed #f0dfad;
        background: #ffffff;
        border-radius: 12px;
        padding: 16px;
        transition: all 0.2s ease;
        position: relative;
    }
    
    .active-rules-dropzone.drag-over {
        border-color: #f6cf4a;
        background: #fffcef;
        box-shadow: inset 0 0 10px rgba(246, 207, 74, 0.1);
    }
    
    .active-rule-card {
        background: #fffdf5;
        border: 1px solid #e2d2aa;
        border-left: 4px solid #f6cf4a;
        border-radius: 8px;
        padding: 12px;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        cursor: grab;
        transition: all 0.2s ease;
        animation: slideIn 0.2s ease;
    }
    
    .active-rule-card:hover {
        box-shadow: 0 4px 12px rgba(36, 27, 11, 0.04);
        border-color: #d8c392;
    }
    
    .active-rule-card:active {
        cursor: grabbing;
    }
    
    .active-rule-card.dragging {
        opacity: 0.4;
        border-style: dashed;
    }
    
    .rule-drag-handle {
        color: #c4b595;
        cursor: grab;
        margin-top: 2px;
        display: flex;
        align-items: center;
    }
    
    .rule-content-wrapper {
        flex-grow: 1;
        font-size: 0.88rem;
        color: #241b0b;
        font-weight: 550;
        line-height: 1.4;
    }
    
    .rule-inline-input {
        width: 100%;
        border: 1px solid #f6cf4a;
        background: #fff;
        border-radius: 4px;
        padding: 2px 6px;
        font-size: 0.88rem;
        color: #241b0b;
        font-weight: 550;
    }
    
    .rule-inline-input:focus {
        outline: none;
        box-shadow: 0 0 0 2px rgba(246, 207, 74, 0.25);
    }
    
    .rule-actions {
        display: flex;
        gap: 6px;
        flex-shrink: 0;
    }
    
    .rule-action-btn {
        background: none;
        border: none;
        padding: 2px;
        color: #a89562;
        cursor: pointer;
        border-radius: 4px;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
    }
    
    .rule-action-btn:hover {
        background: #f0dfad;
        color: #241b0b;
    }
    
    .rule-action-btn.delete:hover {
        background: #fee2e2;
        color: #ef4444;
    }
    
    .empty-rules-msg {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: #a89562;
        text-align: center;
        padding: 40px 20px;
    }
    
    .empty-rules-msg svg {
        margin-bottom: 12px;
        color: #d4c5a1;
    }
    
    @keyframes slideIn {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        15%, 45%, 75% { transform: translateX(-6px); }
        30%, 60% { transform: translateX(6px); }
    }
    
    .shake-error {
        animation: shake 0.4s ease-in-out;
        border-color: #ef4444 !important;
        background-color: #fef2f2 !important;
    }
    
    /* Template Add Button */
    .template-add-btn {
        background: #fff9e6;
        border: 1px solid #f0dfad;
        color: #c59000;
        font-weight: bold;
        font-size: 0.75rem;
        padding: 2px 6px;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    
    .template-add-btn:hover {
        background: #f6cf4a;
        color: #241b0b;
        border-color: #f6cf4a;
    }
</style>

<div class="d-flex align-items-center gap-3 mb-4">
    <a class="btn btn-back btn-sm" href="dashboard.php"><span aria-hidden="true">&larr;</span> Cancel</a>
    <h1 class="h3 mb-0">Pre-Listing Verification</h1>
</div>

<div class="row g-4 justify-content-center">
    <div class="col-lg-8">
        <div class="rules-card">
            <div class="rules-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="text-uppercase small fw-bold tracking-wider" style="color: #f6cf4a; letter-spacing: 0.12em;">Step 2 of 4</span>
                        <h2 class="h3 mb-1 mt-1 text-white">House Rules &amp; Guidelines</h2>
                        <p class="mb-0 text-white-50 small">Accept mandatory compliance terms for "<?= e($property['title']) ?>"</p>
                    </div>
                    <div class="compliance-badge">
                        <span style="font-size: 1.1rem; line-height: 1;">✔</span> Address Compliant
                    </div>
                </div>
            </div>

            <form class="rules-body" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$propertyId ?>">

                <h3 class="h5 mb-4 text-dark font-weight-bold">Mandatory Landlord Guidelines</h3>

                <div class="rule-item">
                    <div class="rule-icon">1</div>
                    <div>
                        <h4 class="rule-title">Local Zoning &amp; Classification Compliance</h4>
                        <p class="rule-desc">
                            The landlord warrants that the property will be used strictly in accordance with its verified zoning classification (e.g. residential, commercial) as evaluated and approved under CPDO guidelines.
                        </p>
                    </div>
                </div>

                <div class="rule-item">
                    <div class="rule-icon">2</div>
                    <div>
                        <h4 class="rule-title">Building Integrity &amp; Safety Standards</h4>
                        <p class="rule-desc">
                            The property must comply with the National Building Code of the Philippines, the Fire Code, and sanitary ordinances. Necessary permits (such as Building Permit and Certificate of Occupancy) must remain valid.
                        </p>
                    </div>
                </div>

                <div class="rule-item">
                    <div class="rule-icon">3</div>
                    <div>
                        <h4 class="rule-title">Rent Control Act Compliance</h4>
                        <p class="rule-desc">
                            The landlord agrees to respect standard rent boundaries, tenant rights, and deposit limits set forth under the Rent Control Act of the Philippines (Republic Act No. 9653).
                        </p>
                    </div>
                </div>

                <div class="rule-item">
                    <div class="rule-icon">4</div>
                    <div>
                        <h4 class="rule-title">Verified Tenancy Registry</h4>
                        <p class="rule-desc">
                            All tenant agreements and occupants listed for this property must be registered transparently on the portal to support standard city occupancy regulations and documentation rules.
                        </p>
                    </div>
                </div>

                <div class="rules-builder-box">
                    <h3 class="h6 mb-2 text-dark font-weight-bold">Listing-Specific House Rules <span class="text-danger">*</span></h3>
                    <p class="text-secondary small mb-4">You are required to list at least one listing-specific house rule. Drag rules from the categories on the left into the active rules list on the right, or create custom rules.</p>
                    
                    <div class="row g-4">
                        <!-- Left Column: Available Template Rules -->
                        <div class="col-lg-5 col-md-6">
                            <h4 class="h6 fw-bold text-dark mb-3"><span class="badge bg-secondary me-1" style="background-color: #241b0b !important;">1</span> Available Templates</h4>
                            <div class="accordion rules-accordion" id="categoriesAccordion">
                                <?php $idx = 0; foreach ($categories as $key => $cat): ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="heading-<?= $key ?>">
                                            <button class="accordion-button <?= $idx === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?= $key ?>" aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>" aria-controls="collapse-<?= $key ?>">
                                                <?= e($cat['title']) ?>
                                            </button>
                                        </h2>
                                        <div id="collapse-<?= $key ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>" aria-labelledby="heading-<?= $key ?>" data-bs-parent="#categoriesAccordion">
                                            <div class="accordion-body p-2 bg-light" style="max-height: 250px; overflow-y: auto;">
                                                <?php foreach ($cat['rules'] as $rule): ?>
                                                    <div class="template-rule-item" draggable="true" data-rule="<?= e($rule) ?>" title="Drag or double click to add">
                                                        <span><?= e($rule) ?></span>
                                                        <button type="button" class="template-add-btn" title="Add to active list">+</button>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php $idx++; endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Right Column: Active Selected Rules -->
                        <div class="col-lg-7 col-md-6">
                            <h4 class="h6 fw-bold text-dark mb-3"><span class="badge bg-primary me-1" style="background-color: #c59000 !important;">2</span> Your Active Rules</h4>
                            <div class="card shadow-none border" style="border-radius: 12px; overflow: hidden; border-color: #f0dfad !important;">
                                <div class="card-body p-3">
                                    <div id="active-rules-list" class="active-rules-dropzone d-flex flex-column gap-2 mb-3" style="min-height: 270px; max-height: 400px; overflow-y: auto;">
                                        <!-- Selected rules will render here -->
                                    </div>
                                    
                                    <!-- Add Custom Rule -->
                                    <div class="input-group">
                                        <input type="text" id="custom-rule-input" class="form-control form-control-sm" placeholder="Type a custom house rule..." style="border-color: #f0dfad;">
                                        <button type="button" id="add-custom-rule-btn" class="btn btn-warning text-dark fw-bold btn-sm" style="background-color: #f6cf4a; border-color: #f6cf4a;">Add Custom</button>
                                    </div>
                                    <div id="validation-error-msg" class="text-danger small mt-2 d-none">
                                        ⚠ Please add at least one listing-specific house rule before continuing.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Hidden textarea to carry the text block values -->
                    <textarea id="hidden-house-rules" name="house_rules" class="d-none"></textarea>
                </div>

                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const rawRulesText = <?= json_encode($property['house_rules'] ?? '') ?>;
                    
                    // Parse existing rules: split by newline, trim, remove numeric prefix like "1. ", "2. ", "10) ", etc.
                    let activeRules = rawRulesText.split('\n')
                        .map(line => line.trim())
                        .filter(line => line.length > 0)
                        .map(line => line.replace(/^\d+[\.\)\-\s]+\s*/, ''));
                        
                    const activeContainer = document.getElementById('active-rules-list');
                    const hiddenInput = document.getElementById('hidden-house-rules');
                    const customRuleInput = document.getElementById('custom-rule-input');
                    const addCustomBtn = document.getElementById('add-custom-rule-btn');
                    const validationErrorMsg = document.getElementById('validation-error-msg');
                    const rulesForm = activeContainer.closest('form');
                    
                    // Icon snippets
                    const dragHandleIcon = `<span class="rule-drag-handle me-2" title="Drag to reorder"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M7 2a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM7 5a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM7 8a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-3 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm-3 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0zm3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/></svg></span>`;
                    const editIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.707-6.707zM1.354 11.5a.5.5 0 0 0-.13-.166L.75 11.793l.166-.13c.189-.148.4-.246.636-.289l.526-.1l-.1-.526a.822.822 0 0 0-.289-.636L1.793 10.5l-1.3-.136a.5.5 0 0 0-.61.61l.136 1.3L.146 12.854a.5.5 0 0 0 .708.708l1.646-1.647 1.793-1.793z"/></svg>`;
                    const deleteIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/></svg>`;
                    const emptyStateIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="bi bi-card-checklist text-warning" viewBox="0 0 16 16"><path d="M14.5 3a.5.5 0 0 1 .5.5v9a.5.5 0 0 1-.5.5h-13a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h13zm-13-1A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h13a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 14.5 2h-13z"/><path d="M7 5.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm-1.496-.854a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708l.146.147 1.146-1.147a.5.5 0 0 1 .708 0zM7 9.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm-1.496-.854a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 0 1 .708-.708l.146.147 1.146-1.147a.5.5 0 0 1 .708 0z"/></svg>`;

                    function updateHiddenInput() {
                        if (activeRules.length === 0) {
                            hiddenInput.value = '';
                        } else {
                            hiddenInput.value = activeRules.map((rule, idx) => `${idx + 1}. ${rule}`).join('\n');
                        }
                    }
                    
                    function renderActiveRules() {
                        activeContainer.classList.remove('shake-error');
                        if (activeRules.length === 0) {
                            activeContainer.innerHTML = `
                                <div class="empty-rules-msg">
                                    ${emptyStateIcon}
                                    <p class="mb-1 fw-bold text-dark mt-2">No Active Rules Selected</p>
                                    <p class="small text-secondary mb-0">Drag templates here, click "+" buttons, or create custom rules below.</p>
                                </div>
                            `;
                            updateHiddenInput();
                            return;
                        }
                        
                        activeContainer.innerHTML = '';
                        activeRules.forEach((rule, idx) => {
                            const card = document.createElement('div');
                            card.className = 'active-rule-card';
                            card.draggable = true;
                            card.dataset.index = idx;
                            
                            card.innerHTML = `
                                ${dragHandleIcon}
                                <div class="rule-content-wrapper">
                                    <span class="rule-index-prefix text-secondary me-1">${idx + 1}.</span>
                                    <span class="rule-text-span">${escapeHTML(rule)}</span>
                                </div>
                                <div class="rule-actions">
                                    <button type="button" class="rule-action-btn edit" title="Edit rule">${editIcon}</button>
                                    <button type="button" class="rule-action-btn delete text-danger" title="Remove rule">${deleteIcon}</button>
                                </div>
                            `;
                            
                            // Drag listeners for reordering
                            card.addEventListener('dragstart', function(e) {
                                card.classList.add('dragging');
                                e.dataTransfer.setData('text/type', 'active');
                                e.dataTransfer.setData('text/source-index', idx);
                            });
                            
                            card.addEventListener('dragend', function() {
                                card.classList.remove('dragging');
                            });
                            
                            // Edit trigger
                            const textSpan = card.querySelector('.rule-text-span');
                            const editBtn = card.querySelector('.rule-action-btn.edit');
                            
                            function startEdit() {
                                if (card.classList.contains('editing')) return;
                                card.classList.add('editing');
                                
                                const currentText = activeRules[idx];
                                const input = document.createElement('input');
                                input.type = 'text';
                                input.className = 'rule-inline-input';
                                input.value = currentText;
                                
                                textSpan.replaceWith(input);
                                input.focus();
                                
                                function saveEdit() {
                                    const newText = input.value.trim();
                                    if (newText) {
                                        activeRules[idx] = newText;
                                    }
                                    card.classList.remove('editing');
                                    renderActiveRules();
                                }
                                
                                input.addEventListener('keydown', function(e) {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        saveEdit();
                                    } else if (e.key === 'Escape') {
                                        card.classList.remove('editing');
                                        renderActiveRules();
                                    }
                                });
                                
                                input.addEventListener('blur', saveEdit);
                            }
                            
                            editBtn.addEventListener('click', startEdit);
                            textSpan.addEventListener('dblclick', startEdit);
                            
                            // Delete trigger
                            const deleteBtn = card.querySelector('.rule-action-btn.delete');
                            deleteBtn.addEventListener('click', function() {
                                activeRules.splice(idx, 1);
                                renderActiveRules();
                            });
                            
                            activeContainer.appendChild(card);
                        });
                        
                        updateHiddenInput();
                    }
                    
                    function escapeHTML(str) {
                        return str.replace(/&/g, '&amp;')
                                  .replace(/</g, '&lt;')
                                  .replace(/>/g, '&gt;')
                                  .replace(/"/g, '&quot;')
                                  .replace(/'/g, '&#039;');
                    }
                    
                    // Add rule helper
                    function addRule(ruleText) {
                        ruleText = ruleText.trim();
                        if (!ruleText) return false;
                        
                        // Avoid duplicates
                        if (activeRules.some(r => r.toLowerCase() === ruleText.toLowerCase())) {
                            alert('This house rule has already been added.');
                            return false;
                        }
                        
                        activeRules.push(ruleText);
                        renderActiveRules();
                        return true;
                    }
                    
                    // Setup Template Rule Draggables
                    document.querySelectorAll('.template-rule-item').forEach(item => {
                        const ruleText = item.dataset.rule;
                        
                        item.addEventListener('dragstart', function(e) {
                            e.dataTransfer.setData('text/type', 'template');
                            e.dataTransfer.setData('text/plain', ruleText);
                        });
                        
                        // Click '+' to add
                        const addBtn = item.querySelector('.template-add-btn');
                        if (addBtn) {
                            addBtn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                addRule(ruleText);
                            });
                        }
                        
                        // Double-click template to add
                        item.addEventListener('dblclick', function() {
                            addRule(ruleText);
                        });
                    });
                    
                    // Dropzone Listeners
                    activeContainer.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        activeContainer.classList.add('drag-over');
                    });
                    
                    activeContainer.addEventListener('dragleave', function() {
                        activeContainer.classList.remove('drag-over');
                    });
                    
                    activeContainer.addEventListener('drop', function(e) {
                        e.preventDefault();
                        activeContainer.classList.remove('drag-over');
                        
                        const type = e.dataTransfer.getData('text/type');
                        const text = e.dataTransfer.getData('text/plain');
                        
                        // Reordering calculations
                        const afterElement = getDragAfterElement(activeContainer, e.clientY);
                        let targetIndex = activeRules.length;
                        if (afterElement) {
                            targetIndex = parseInt(afterElement.dataset.index);
                        }
                        
                        if (type === 'template') {
                            if (!activeRules.some(r => r.toLowerCase() === text.toLowerCase())) {
                                activeRules.splice(targetIndex, 0, text);
                                renderActiveRules();
                            } else {
                                alert('This house rule has already been added.');
                            }
                        } else if (type === 'active') {
                            const sourceIndex = parseInt(e.dataTransfer.getData('text/source-index'));
                            if (!isNaN(sourceIndex) && sourceIndex !== targetIndex) {
                                const [moved] = activeRules.splice(sourceIndex, 1);
                                let adjustedTarget = targetIndex;
                                if (sourceIndex < targetIndex) {
                                    adjustedTarget--;
                                }
                                activeRules.splice(adjustedTarget, 0, moved);
                                renderActiveRules();
                            }
                        }
                    });
                    
                    function getDragAfterElement(container, y) {
                        const draggableElements = [...container.querySelectorAll('.active-rule-card:not(.dragging)')];
                        return draggableElements.reduce((closest, child) => {
                            const box = child.getBoundingClientRect();
                            const offset = y - box.top - box.height / 2;
                            if (offset < 0 && offset > closest.offset) {
                                return { offset: offset, element: child };
                            } else {
                                return closest;
                            }
                        }, { offset: Number.NEGATIVE_INFINITY }).element;
                    }
                    
                    // Add Custom Rule
                    addCustomBtn.addEventListener('click', function() {
                        const customText = customRuleInput.value.trim();
                        if (customText) {
                            if (addRule(customText)) {
                                customRuleInput.value = '';
                            }
                        }
                    });
                    
                    customRuleInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            addCustomBtn.click();
                        }
                    });
                    
                    // Form Submit Validation
                    rulesForm.addEventListener('submit', function(e) {
                        // Skip validation if saving as draft
                        if (e.submitter && e.submitter.name === 'save_draft') {
                            return;
                        }
                        
                        if (activeRules.length === 0) {
                            e.preventDefault();
                            validationErrorMsg.classList.remove('d-none');
                            activeContainer.classList.add('shake-error');
                            
                            // Scroll to active container
                            activeContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            
                            setTimeout(() => {
                                activeContainer.classList.remove('shake-error');
                            }, 500);
                        } else {
                            validationErrorMsg.classList.add('d-none');
                        }
                    });
                    
                    // Render initially
                    renderActiveRules();
                });
                </script>

                <div class="form-check p-3 rounded mb-4" style="background: #fff9e6; border: 1px dashed #f0dfad;">
                    <input class="form-check-input ms-0 me-2" type="checkbox" name="accept_rules" id="accept-rules-check" required value="1" <?= $property['rules_accepted'] ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold text-dark" for="accept-rules-check">
                        I hereby read, understand, and agree to follow the mandatory CPDO Landlord Guidelines and house rules for this property.
                    </label>
                </div>

                <div class="d-flex gap-3">
                    <button class="btn btn-primary px-4 py-2">Save &amp; Continue</button>
                    <button type="submit" name="save_draft" value="1" formnovalidate class="btn btn-outline-secondary px-4 py-2">Save as Draft</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
