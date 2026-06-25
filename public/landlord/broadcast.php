<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);
verify_csrf();

global $config;
$baseUrl = rtrim($config['app']['base_url'], '/');

// Fetch landlord's active properties
$propStmt = db()->prepare('SELECT id, title FROM properties WHERE landlord_id = ? AND status = "ACTIVE" ORDER BY title');
$propStmt->execute([(int)$user['id']]);
$myProperties = $propStmt->fetchAll();

$flash = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetType  = $_POST['target_type']  ?? 'all_tenants';
    $targetProp  = (int)($_POST['target_property_id'] ?? 0) ?: null;
    $subject     = trim($_POST['subject'] ?? '');
    $body        = trim($_POST['body']    ?? '');

    if (!$subject || !$body) {
        $flash = 'Subject and message body are required.';
        $flashType = 'danger';
    } else {
        // Handle optional attachment
        $attachPath = $attachMime = $attachName = null;
        if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['attachment'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','pdf'];
            if (in_array($ext, $allowed, true) && $file['size'] <= 10*1024*1024) {
                try {
                    $attachPath = secure_upload($file, 'messages', $allowed);
                    $attachName = $file['name'];
                    $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];
                    $attachMime = $mimeMap[$ext] ?? 'application/octet-stream';
                } catch (Throwable $e) {
                    $flash = 'Attachment upload failed: ' . $e->getMessage();
                    $flashType = 'danger';
                }
            }
        }

        if (!$flash) {
            $pdo = db();

            // Determine recipients
            if ($targetType === 'all_tenants') {
                // All tenants with an active/signed lease under this landlord
                $recStmt = $pdo->prepare(
                    'SELECT DISTINCT l.tenant_id
                     FROM leases l
                     JOIN properties p ON p.id = l.property_id
                     WHERE p.landlord_id = ? AND l.status = "active"'
                );
                $recStmt->execute([(int)$user['id']]);
            } elseif ($targetType === 'property' && $targetProp) {
                $recStmt = $pdo->prepare(
                    'SELECT DISTINCT l.tenant_id
                     FROM leases l
                     WHERE l.property_id = ? AND l.status = "active"'
                );
                $recStmt->execute([$targetProp]);
            } else {
                $recStmt = null;
            }

            $tenantIds = $recStmt ? array_column($recStmt->fetchAll(), 'tenant_id') : [];
            $recipientCount = count($tenantIds);

            // Insert broadcast record
            $pdo->prepare(
                'INSERT INTO message_broadcasts
                    (landlord_id, target_type, target_property_id, subject, body, attachment_path, attachment_mime, attachment_name, recipient_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (int)$user['id'], $targetType, $targetProp,
                $subject, $body, $attachPath, $attachMime, $attachName, $recipientCount,
            ]);
            $broadcastId = (int)$pdo->lastInsertId();

            // Fan-out: create or find thread per tenant and insert system message
            foreach ($tenantIds as $tenantId) {
                // Check if a broadcast thread already exists for this landlord+tenant
                $thCheck = $pdo->prepare(
                    'SELECT id FROM message_threads
                      WHERE landlord_id = ? AND tenant_id = ? AND context_type = "broadcast"
                      LIMIT 1'
                );
                $thCheck->execute([(int)$user['id'], (int)$tenantId]);
                $existingThread = $thCheck->fetchColumn();

                if ($existingThread) {
                    $threadId = (int)$existingThread;
                    // Update thread subject to latest broadcast
                    $pdo->prepare('UPDATE message_threads SET subject = ?, last_message_at = NOW() WHERE id = ?')
                        ->execute(['📢 ' . $subject, $threadId]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO message_threads (landlord_id, tenant_id, context_type, subject, status)
                         VALUES (?, ?, "broadcast", ?, "open")'
                    )->execute([(int)$user['id'], (int)$tenantId, '📢 ' . $subject]);
                    $threadId = (int)$pdo->lastInsertId();
                }

                // Insert the broadcast message
                $pdo->prepare(
                    'INSERT INTO messages (thread_id, sender_id, body, message_type, attachment_path, attachment_mime, attachment_name)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $threadId, (int)$user['id'],
                    $body,
                    $attachMime && str_starts_with($attachMime, 'image/') ? 'image' : ($attachMime === 'application/pdf' ? 'pdf' : 'text'),
                    $attachPath, $attachMime, $attachName,
                ]);

                // Increment tenant unread
                $pdo->prepare('UPDATE message_threads SET unread_tenant = unread_tenant + 1 WHERE id = ?')
                    ->execute([$threadId]);

                // In-system notification
                $senderName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)')
                    ->execute([$tenantId, '📢 Broadcast: ' . $subject, mb_substr($body, 0, 120)]);
            }

            audit_log((int)$user['id'], 'BROADCAST_SENT', 'message_broadcasts', $broadcastId, [
                'recipient_count' => $recipientCount,
                'target_type'     => $targetType,
            ]);

            $flash = "Broadcast sent to {$recipientCount} tenant(s) successfully.";
            $flashType = 'success';
        }
    }
}

// Fetch past broadcasts
$histStmt = db()->prepare(
    'SELECT mb.*, p.title AS prop_title
       FROM message_broadcasts mb
       LEFT JOIN properties p ON p.id = mb.target_property_id
      WHERE mb.landlord_id = ?
      ORDER BY mb.sent_at DESC
      LIMIT 30'
);
$histStmt->execute([(int)$user['id']]);
$pastBroadcasts = $histStmt->fetchAll();

// Count eligible recipients for preview
$previewCounts = [];
$allStmt = db()->prepare(
    'SELECT COUNT(DISTINCT l.tenant_id) FROM leases l JOIN properties p ON p.id=l.property_id WHERE p.landlord_id=? AND l.status="active"'
);
$allStmt->execute([(int)$user['id']]);
$previewCounts['all_tenants'] = (int)$allStmt->fetchColumn();

foreach ($myProperties as $p) {
    $pStmt = db()->prepare('SELECT COUNT(DISTINCT tenant_id) FROM leases WHERE property_id=? AND status="active"');
    $pStmt->execute([$p['id']]);
    $previewCounts['prop_' . $p['id']] = (int)$pStmt->fetchColumn();
}

require __DIR__ . '/../partials/header.php';
?>
<style>
.broadcast-card { background:#fff; border:1px solid #f0dfad; border-radius:16px; padding:28px; box-shadow:0 4px 16px rgba(36,27,11,.05); }
.form-label-styled { font-size:.85rem; font-weight:700; color:#241b0b; margin-bottom:6px; display:block; }
.form-control-styled, .form-select-styled { border:1px solid #d4c89a; border-radius:8px; padding:10px 13px; font-size:.9rem; width:100%; background:#fff; }
.form-control-styled:focus, .form-select-styled:focus { outline:none; border-color:#f6cf4a; box-shadow:0 0 0 3px rgba(246,207,74,.2); }
.recipient-preview { background:linear-gradient(135deg,#fff9e6,#fffdf5); border:1px solid #f6cf4a; border-radius:10px; padding:14px 18px; display:flex; align-items:center; gap:12px; }
.recipient-count { font-size:2rem; font-weight:900; color:#241b0b; line-height:1; }
.recipient-label { font-size:.82rem; color:#76684b; }
.urgency-toggle { display:flex; gap:8px; }
.urgency-btn { padding:7px 16px; border-radius:20px; border:1px solid #d4c89a; font-size:.82rem; font-weight:700; cursor:pointer; transition:all .15s; }
.urgency-btn.normal { background:#f0f4ff; color:#1e40af; }
.urgency-btn.normal.active { background:#1e40af; color:#fff; border-color:#1e40af; }
.urgency-btn.urgent { background:#fef2f2; color:#991b1b; }
.urgency-btn.urgent.active { background:#ef4444; color:#fff; border-color:#ef4444; }
.history-row { display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid #f0dfad; }
.history-row:last-child { border-bottom:none; }
.history-subject { font-size:.88rem; font-weight:800; color:#241b0b; }
.history-meta { font-size:.75rem; color:#a89562; margin-top:2px; }
.history-count { background:#241b0b; color:#f6cf4a; font-size:.7rem; font-weight:800; padding:3px 10px; border-radius:999px; white-space:nowrap; }
.char-counter { font-size:.75rem; color:#a89562; text-align:right; margin-top:4px; }
.char-counter.warn { color:#ef4444; }
</style>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <a href="<?= e($baseUrl) ?>/landlord/messages.php" class="btn btn-sm btn-outline-secondary me-2" style="border-radius:8px;">← Back to Inbox</a>
        <span class="h3 mb-0">📢 Mass Broadcast</span>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flashType) ?> mb-4"><?= e($flash) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="broadcast-card">
            <h2 style="font-size:1.1rem;font-weight:800;color:#241b0b;margin-bottom:20px;">Compose Broadcast</h2>

            <form method="post" enctype="multipart/form-data" id="broadcastForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="mb-3">
                    <label class="form-label-styled">Send To</label>
                    <select class="form-select-styled" name="target_type" id="targetType" onchange="updatePreview()">
                        <option value="all_tenants">All Active Tenants (<?= $previewCounts['all_tenants'] ?> tenants)</option>
                        <?php foreach ($myProperties as $p): ?>
                            <option value="property" data-prop-id="<?= (int)$p['id'] ?>">
                                🏠 <?= e($p['title']) ?> (<?= $previewCounts['prop_'.$p['id']] ?? 0 ?> tenants)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="target_property_id" id="targetPropId">
                </div>

                <div class="recipient-preview mb-3" id="recipientPreview">
                    <span class="recipient-count" id="recCount"><?= $previewCounts['all_tenants'] ?></span>
                    <div>
                        <div style="font-size:.82rem;font-weight:800;color:#241b0b;">tenants will receive this broadcast</div>
                        <div class="recipient-label" id="recLabel">All active tenants in your portfolio</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label-styled">Urgency Level</label>
                    <div class="urgency-toggle" id="urgencyToggle">
                        <button type="button" class="urgency-btn normal active" onclick="setUrgency('normal', this)">📋 Normal Notice</button>
                        <button type="button" class="urgency-btn urgent" onclick="setUrgency('urgent', this)">🚨 Urgent Notice</button>
                    </div>
                    <input type="hidden" name="urgency" id="urgencyInput" value="normal">
                </div>

                <div class="mb-3">
                    <label class="form-label-styled">Subject <span class="text-danger">*</span></label>
                    <input type="text" class="form-control-styled" name="subject" id="broadcastSubject"
                           placeholder="e.g. Water service interruption — June 26" maxlength="120" required
                           value="<?= e($_POST['subject'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label-styled">Message <span class="text-danger">*</span></label>
                    <textarea class="form-control-styled" name="body" id="broadcastBody" rows="7"
                              placeholder="Write your notice here…" maxlength="2000" required
                              oninput="updateCharCount(this)"><?= e($_POST['body'] ?? '') ?></textarea>
                    <div class="char-counter" id="charCounter">0 / 2000</div>
                </div>

                <div class="mb-4">
                    <label class="form-label-styled">Attachment <span class="text-secondary small">(optional, max 10 MB, JPG/PNG/PDF)</span></label>
                    <input type="file" class="form-control-styled" name="attachment" accept="image/*,.pdf"
                           style="padding:8px;" onchange="previewAttach(this)">
                    <div id="attachPreviewArea" style="display:none;margin-top:8px;"></div>
                </div>

                <button type="submit" class="btn fw-bold w-100 py-2" id="submitBtn"
                        style="background:#241b0b;color:#f6cf4a;border-radius:10px;font-size:.95rem;">
                    📢 Send Broadcast to <span id="btnCount"><?= $previewCounts['all_tenants'] ?></span> Tenant(s)
                </button>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <!-- Quick Templates sidebar -->
        <div class="broadcast-card mb-4">
            <h3 style="font-size:.95rem;font-weight:800;color:#241b0b;margin-bottom:14px;">⚡ Quick Templates</h3>
            <?php
            $bcastTemplates = [
                ['icon'=>'🚰','label'=>'Water Shut-off','subject'=>'Water Service Interruption Notice','body'=>"Dear Residents,\n\nPlease be advised that water service will be temporarily interrupted on [DATE] from [START TIME] to [END TIME] due to scheduled maintenance.\n\nWe recommend storing sufficient water in advance. We apologize for any inconvenience.\n\n— Property Management"],
                ['icon'=>'💵','label'=>'Rent Reminder','subject'=>'Monthly Rent Due Reminder','body'=>"Dear Residents,\n\nThis is a friendly reminder that monthly rent is due on the [DATE] of each month.\n\nPlease ensure timely payment to avoid late fees. Payments can be made through the tenant portal.\n\nThank you for your cooperation.\n\n— Property Management"],
                ['icon'=>'🔍','label'=>'Unit Inspection','subject'=>'Scheduled Unit Inspections — [MONTH]','body'=>"Dear Residents,\n\nWe will be conducting routine unit inspections on [DATE RANGE]. Our team will inspect general unit condition, fixtures, and appliances.\n\nPlease ensure access is available or coordinate with us for scheduling.\n\nThank you,\n— Property Management"],
                ['icon'=>'🎉','label'=>'Holiday Greetings','subject'=>'Season\'s Greetings from Your Property Team','body'=>"Dear Residents,\n\nWishing you and your family a wonderful and joyful holiday season! We are grateful for your continued trust in us.\n\nWarm regards,\n— Property Management"],
                ['icon'=>'⚠️','label'=>'Emergency Notice','subject'=>'⚠️ URGENT: Important Safety Notice','body'=>"URGENT NOTICE TO ALL RESIDENTS:\n\nThis is an important safety notice. [DESCRIBE THE EMERGENCY OR SITUATION].\n\nPlease [REQUIRED ACTION] immediately. If you have questions, contact management at [CONTACT].\n\n— Property Management"],
            ];
            foreach ($bcastTemplates as $bt): ?>
                <div onclick="fillTemplate(<?= json_encode($bt['subject']) ?>, <?= json_encode($bt['body']) ?>)"
                     style="padding:10px 12px;border:1px solid #f0dfad;border-radius:8px;margin-bottom:8px;cursor:pointer;transition:background .12s;background:#fffdf5;">
                    <div style="font-size:.85rem;font-weight:800;color:#241b0b;"><?= e($bt['icon']) ?> <?= e($bt['label']) ?></div>
                    <div style="font-size:.75rem;color:#a89562;margin-top:2px;"><?= e(mb_substr($bt['body'], 0, 60)) ?>…</div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Broadcast History -->
        <div class="broadcast-card">
            <h3 style="font-size:.95rem;font-weight:800;color:#241b0b;margin-bottom:14px;">📋 Recent Broadcasts</h3>
            <?php if (empty($pastBroadcasts)): ?>
                <p style="color:#a89562;font-size:.82rem;">No broadcasts sent yet.</p>
            <?php else: ?>
                <?php foreach ($pastBroadcasts as $bc): ?>
                    <div class="history-row">
                        <div style="flex:1;min-width:0;">
                            <div class="history-subject"><?= e($bc['subject']) ?></div>
                            <div class="history-meta">
                                <?= date('M d, Y · g:i A', strtotime($bc['sent_at'])) ?>
                                · <?= $bc['target_type'] === 'all_tenants' ? 'All tenants' : e($bc['prop_title'] ?? 'Specific property') ?>
                                <?php if ($bc['attachment_path']): ?>· 📎<?php endif; ?>
                            </div>
                        </div>
                        <span class="history-count"><?= (int)$bc['recipient_count'] ?> sent</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const propCounts = <?= json_encode($previewCounts) ?>;
const propTitles = {
    <?php foreach ($myProperties as $p): ?>
    <?= (int)$p['id'] ?>: <?= json_encode(e($p['title'])) ?>,
    <?php endforeach; ?>
};

function updatePreview() {
    const sel   = document.getElementById('targetType');
    const opt   = sel.options[sel.selectedIndex];
    const type  = sel.value;
    const propId = opt.dataset.propId ? parseInt(opt.dataset.propId) : null;

    document.getElementById('targetPropId').value = propId || '';

    let count = 0, label = '';
    if (type === 'all_tenants') {
        count = propCounts['all_tenants'] || 0;
        label = 'All active tenants in your portfolio';
    } else if (type === 'property' && propId) {
        count = propCounts['prop_' + propId] || 0;
        label = propTitles[propId] || 'Selected property';
    }

    document.getElementById('recCount').textContent = count;
    document.getElementById('recLabel').textContent  = label;
    document.getElementById('btnCount').textContent  = count;
}

function setUrgency(level, btn) {
    document.querySelectorAll('.urgency-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('urgencyInput').value = level;
    if (level === 'urgent') {
        const subj = document.getElementById('broadcastSubject');
        if (subj.value && !subj.value.startsWith('⚠️')) subj.value = '⚠️ ' + subj.value;
    }
}

function updateCharCount(ta) {
    const counter = document.getElementById('charCounter');
    const len = ta.value.length;
    counter.textContent = len + ' / 2000';
    counter.classList.toggle('warn', len > 1800);
}

function fillTemplate(subject, body) {
    document.getElementById('broadcastSubject').value = subject;
    document.getElementById('broadcastBody').value    = body;
    updateCharCount(document.getElementById('broadcastBody'));
}

function previewAttach(input) {
    const area = document.getElementById('attachPreviewArea');
    if (!input.files[0]) { area.style.display='none'; return; }
    const file = input.files[0];
    const isImg = file.type.startsWith('image/');
    area.style.display = 'block';
    area.innerHTML = isImg
        ? `<img src="${URL.createObjectURL(file)}" style="max-height:100px;border-radius:8px;border:1px solid #f0dfad;">`
        : `<div style="display:inline-flex;align-items:center;gap:8px;background:#f3f4f6;border-radius:8px;padding:8px 12px;font-size:.82rem;font-weight:700;">📄 ${file.name}</div>`;
}

// Init
document.getElementById('broadcastBody') && updateCharCount(document.getElementById('broadcastBody'));
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
