<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TENANT]);
verify_csrf();

$appId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$appId) {
    http_response_code(400);
    exit('Invalid application ID.');
}

// Fetch the rental application details
$stmt = db()->prepare(
    'SELECT ra.*, p.title AS property_title, p.address AS property_address, p.monthly_rent AS property_rent, p.extended_details AS property_details,
            CONCAT_WS(" ", u.first_name, u.last_name) AS landlord_name, u.email AS landlord_email, u.phone_number AS landlord_phone, u.id AS landlord_id
     FROM rental_applications ra
     JOIN properties p ON p.id = ra.property_id
     JOIN users u ON u.id = p.landlord_id
     WHERE ra.id = ? AND ra.tenant_id = ?'
);
$stmt->execute([$appId, (int)$user['id']]);
$application = $stmt->fetch();

if (!$application) {
    http_response_code(404);
    exit('Application not found.');
}

$propDetails = json_decode($application['property_details'] ?? '{}', true) ?: [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_message') {
        $msgText = trim($_POST['message_text'] ?? '');
        if ($msgText !== '') {
            db()->prepare('INSERT INTO rental_application_messages (application_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())')
                ->execute([$appId, (int)$user['id'], $msgText]);

            // Notify landlord of new message
            $notif = db()->prepare('INSERT INTO notifications (user_id, rental_application_id, title, message, created_at) VALUES (?, ?, ?, ?, NOW())');
            $notif->execute([
                (int)$application['landlord_id'],
                $appId,
                'New Message from Tenant',
                user_full_name($user) . ' sent you a message regarding "' . $application['property_title'] . '".'
            ]);

            audit_log((int)$user['id'], 'RENTAL_APPLICATION_MESSAGE_SENT', 'rental_applications', $appId);
        }
        redirect('tenant/application-status.php?id=' . $appId);
    }
}

// Fetch messages
$msgStmt = db()->prepare(
    'SELECT ram.*, CONCAT_WS(" ", u.first_name, u.last_name) AS sender_name, u.role AS sender_role
     FROM rental_application_messages ram
     JOIN users u ON u.id = ram.sender_id
     WHERE ram.application_id = ?
     ORDER BY ram.created_at ASC'
);
$msgStmt->execute([$appId]);
$messages = $msgStmt->fetchAll();

// Fetch lease agreement from leases
$leaseStmt = db()->prepare('SELECT * FROM leases WHERE rental_application_id = ?');
$leaseStmt->execute([$appId]);
$lease = $leaseStmt->fetch();

require __DIR__ . '/../partials/header.php';
?>

<style>
.status-page {
    min-height: calc(100vh - 64px);
    background: #f8fafc;
    padding: 32px 0 64px;
}

.status-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}

.section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 8px;
    margin-bottom: 18px;
}

.chat-box {
    height: 300px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    padding: 16px;
}

.chat-msg {
    margin-bottom: 12px;
    max-width: 80%;
    padding: 10px 14px;
    border-radius: 12px;
    font-size: 0.88rem;
}

.chat-msg-sent {
    background: #2563eb;
    color: #fff;
    margin-left: auto;
    border-bottom-right-radius: 2px;
}

.chat-msg-received {
    background: #fff;
    color: #1e293b;
    border: 1px solid #e2e8f0;
    border-bottom-left-radius: 2px;
}

/* Timeline stepper */
.stepper-wrap {
    display: flex;
    justify-content: space-between;
    margin-bottom: 32px;
    position: relative;
}

.stepper-wrap::before {
    content: '';
    position: absolute;
    top: 15px;
    left: 0;
    right: 0;
    height: 3px;
    background: #cbd5e1;
    z-index: 1;
}

.stepper-step {
    position: relative;
    z-index: 2;
    background: #f8fafc;
    padding: 0 10px;
    text-align: center;
}

.stepper-dot {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #cbd5e1;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin: 0 auto 8px;
    border: 3px solid #f8fafc;
}

.stepper-step.active .stepper-dot {
    background: #3b82f6;
    color: #fff;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
}

.stepper-step.completed .stepper-dot {
    background: #10b981;
    color: #fff;
}

.stepper-label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
}

.stepper-step.active .stepper-label {
    color: #1e293b;
}
</style>

<div class="status-page">
    <div class="container-fluid" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
        
        <div class="d-flex align-items-center gap-3 mb-4">
            <a class="btn btn-back btn-sm" href="dashboard.php" style="border-radius:8px;"><span aria-hidden="true">&larr;</span> Dashboard</a>
            <h1 class="h3 mb-0 fw-bold">Application Tracker</h1>
            <span class="badge text-bg-warning px-3 py-2 ms-auto" style="border-radius:999px;">
                Status: <?= e($application['status']) ?>
            </span>
        </div>

        <!-- Progress Stepper -->
        <div class="stepper-wrap">
            <?php
            // Stepper represents the new simplified SaaS lease cycle
            $statuses = ['PENDING', 'ACCEPTED', 'SIGNED', 'PAID'];
            $currentIdx = array_search($application['status'], $statuses);
            if ($currentIdx === false) { $currentIdx = 0; }
            if ($application['status'] === 'DECLINED') { $currentIdx = -1; }
            
            $steps = [
                ['label' => '1. Inquiry Submitted', 'key' => 'PENDING'],
                ['label' => '2. Background Approved', 'key' => 'ACCEPTED'],
                ['label' => '3. Signed Lease', 'key' => 'SIGNED'],
                ['label' => '4. Move-in Complete', 'key' => 'PAID']
            ];

            foreach ($steps as $idx => $step):
                $isCompleted = ($currentIdx > $idx) || ($application['status'] === 'COMPLETED') || ($application['status'] === 'PAID' && $idx <= 3);
                $isActive = ($currentIdx === $idx);
                $class = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
            ?>
                <div class="stepper-step <?= $class ?>">
                    <div class="stepper-dot"><?= $isCompleted ? '✓' : ($idx + 1) ?></div>
                    <div class="stepper-label"><?= $step['label'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($application['status'] === 'DECLINED'): ?>
            <div class="alert alert-danger py-3 px-4 mb-4" style="border-radius:12px;">
                <strong>Application Declined.</strong> Unfortunately, the landlord has declined this application inquiry. Feel free to browse other listing opportunities on your dashboard.
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Left Side: Messaging -->
            <div class="col-lg-8">
                <!-- Chat Window (Plan property visit) -->
                <?php if ($application['status'] !== 'DECLINED'): ?>
                    <div class="status-card p-4 mb-4">
                        <h2 class="section-title">Message Communication (Coordinate Property Visit)</h2>
                        
                        <?php if ($application['status'] === 'PENDING'): ?>
                            <div class="alert alert-warning py-3 px-4 mb-0 small" style="border-radius:12px;">
                                <strong>Waiting for Landlord review.</strong> Chat messaging will unlock once the landlord accepts your initial inquiry screening.
                            </div>
                        <?php else: ?>
                            <div class="chat-box mb-3 d-flex flex-column" id="chatWindow">
                                <?php if (empty($messages)): ?>
                                    <div class="text-center text-secondary my-auto small">No messages exchanged yet. Send a message to the landlord to arrange a visit!</div>
                                <?php else: ?>
                                    <?php foreach ($messages as $msg): ?>
                                        <?php $isSelf = ($msg['sender_id'] == $user['id']); ?>
                                        <div class="chat-msg <?= $isSelf ? 'chat-msg-sent' : 'chat-msg-received' ?>">
                                            <div class="fw-bold" style="font-size:0.75rem; opacity:0.8;">
                                                <?= e($msg['sender_name']) ?> (<?= role_label($msg['sender_role']) ?>)
                                            </div>
                                            <div class="my-1"><?= nl2br(e($msg['message'])) ?></div>
                                            <div class="text-end" style="font-size:0.65rem; opacity:0.6;">
                                                <?= date('M d, g:i A', strtotime($msg['created_at'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <form method="post" class="d-flex gap-2">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= $appId ?>">
                                <input type="hidden" name="action" value="send_message">
                                <input class="form-control" type="text" name="message_text" placeholder="Type a message to schedule a visit..." required style="border-radius:10px;">
                                <button type="submit" class="btn btn-primary px-4" style="border-radius:10px;">Send</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($application['status'] === 'ACCEPTED' && $lease && $lease['status'] === 'pending_signature'): ?>
                    <div class="status-card p-4">
                        <h2 class="section-title">✓ Vetting Completed Successfully</h2>
                        <p class="small text-secondary mb-4">
                            Your application screening was successful and the landlord has approved your registration. A digital lease agreement is ready for your signature.
                        </p>
                        <a href="lease-documents.php" class="btn btn-success btn-lg px-4 fw-bold" style="background:#10b981; border-color:#10b981; color:#fff; border-radius:10px;">
                            📄 Proceed to Lease &amp; Documents to Sign
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Side: Property Listing & Lease Status -->
            <div class="col-md-4">
                <!-- Property Details Card -->
                <div class="status-card p-4 mb-4">
                    <h2 class="section-title">Property Info</h2>
                    <h3 class="h6 text-primary fw-bold mb-1"><?= e($application['property_title']) ?></h3>
                    <p class="text-secondary small mb-3"><?= e($application['property_address']) ?></p>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-secondary small">Monthly Rent</span>
                        <strong class="text-success">₱<?= number_format((float)$application['property_rent'], 2) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-secondary small">Landlord</span>
                        <span class="fw-semibold text-dark"><?= e($application['landlord_name']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-secondary small">Contact</span>
                        <span class="small text-secondary"><?= e($application['landlord_phone']) ?></span>
                    </div>
                </div>

                <!-- Lease Draft Review -->
                <?php if ($lease): ?>
                    <div class="status-card p-4">
                        <h2 class="section-title">Lease Agreement</h2>
                        
                        <!-- Scheduling block -->
                        <?php if ($lease['signing_scheduled_at']): ?>
                            <div class="alert alert-warning py-3 px-3 mb-3 small border border-warning-subtle" style="background:#fffdf5; color:#8a6400; border-radius:10px;">
                                <strong style="display:block;margin-bottom:4px;">📅 Scheduled Signing:</strong>
                                <strong>Date &amp; Time:</strong> <?= date('M d, Y · g:i A', strtotime($lease['signing_scheduled_at'])) ?><br>
                                <strong>Location:</strong> <?= e($lease['signing_location'] ?: 'CPDO Portal') ?>
                            </div>
                        <?php endif; ?>

                        <div class="small mb-3">
                            <span class="text-secondary d-block">Monthly Rent:</span>
                            <strong class="text-dark">₱<?= number_format((float)$lease['monthly_rent'], 2) ?></strong>
                        </div>
                        <div class="small mb-3">
                            <span class="text-secondary d-block">Security Deposit:</span>
                            <strong class="text-dark">₱<?= number_format((float)$lease['security_deposit'], 2) ?></strong>
                        </div>
                        <div class="small mb-3">
                            <span class="text-secondary d-block">Advance Rent:</span>
                            <strong class="text-dark">₱<?= number_format((float)$lease['advance_payment'], 2) ?></strong>
                        </div>
                        <div class="small mb-3">
                            <span class="text-secondary d-block">Lease Duration:</span>
                            <strong class="text-dark"><?= date('M d, Y', strtotime($lease['start_date'])) ?> to <?= date('M d, Y', strtotime($lease['end_date'])) ?></strong>
                        </div>
                        
                        <div class="pt-3 border-top">
                            <?php if ($lease['status'] === 'pending_signature'): ?>
                                <div class="alert alert-info py-2 px-3 small border border-info-subtle mb-3" style="background:#f0f9ff; color:#0369a1; border-radius:8px;">
                                    <strong>✍️ Awaiting Signature</strong><br>
                                    Lease contract is ready. Please proceed to Lease & Documents to sign.
                                </div>
                                <a href="lease-documents.php" class="btn btn-success w-100 py-2 fw-bold" style="background:#10b981; border-color:#10b981; color:#fff; border-radius:8px;">
                                    Review &amp; Sign Lease Contract
                                </a>
                            <?php elseif ($lease['status'] === 'awaiting_initial_payment'): ?>
                                <div class="alert alert-warning py-2 px-3 small border border-warning-subtle mb-3" style="background:#fffdf5; color:#8a6400; border-radius:8px;">
                                    <strong>✓ Signed Contract Locked</strong><br>
                                    Please visit the Payment Hub to settle the move-in fees to activate the lease.
                                </div>
                                <a href="payment-hub.php" class="btn btn-primary w-100 py-2 fw-bold" style="background:#2563eb; border-color:#2563eb; color:#fff; border-radius:8px;">
                                    Go to Payment Hub
                                </a>
                            <?php elseif ($lease['status'] === 'active'): ?>
                                <div class="alert alert-success py-2 px-3 small border border-success-subtle mb-0" style="background:#f6fbf8; color:#1e9e57; font-weight:700; border-radius:8px;">
                                    ✓ Agreement Executed &amp; Tenancy Active.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Auto scroll chat to bottom
var chatWindow = document.getElementById('chatWindow');
if (chatWindow) {
    chatWindow.scrollTop = chatWindow.scrollHeight;
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
