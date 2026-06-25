<?php
/**
 * API: Send a message to a thread (or create a new thread).
 * POST /api/messages_send.php
 *
 * Accepts multipart/form-data:
 *   csrf_token, thread_id (or landlord_id+tenant_id+property_id+context_type+context_id+subject for new),
 *   body, attachment (optional file)
 *
 * Returns JSON: { success, message_id, thread_id, created_at }
 */

declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth ─────────────────────────────────────────────────────────────────────
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthenticated']);
    exit;
}
if (!in_array($user['role'], [ROLE_LANDLORD, ROLE_TENANT], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// ── Inputs ───────────────────────────────────────────────────────────────────
$threadId    = (int)($_POST['thread_id'] ?? 0);
$body        = trim($_POST['body'] ?? '');
$messageType = 'text';

// ── Attachment handling ───────────────────────────────────────────────────────
$attachmentPath = null;
$attachmentMime = null;
$attachmentName = null;

if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $file      = $_FILES['attachment'];
    $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed   = ['jpg','jpeg','png','webp','gif','pdf'];

    if (!in_array($ext, $allowed, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Unsupported file type. Allowed: JPG, PNG, WebP, GIF, PDF.']);
        exit;
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'File too large (max 10 MB).']);
        exit;
    }

    try {
        $attachmentPath = secure_upload($file, 'messages', $allowed);
        $attachmentName = $file['name'];
        $mimeMap = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',  'webp' => 'image/webp',
            'gif' => 'image/gif',  'pdf'  => 'application/pdf',
        ];
        $attachmentMime = $mimeMap[$ext] ?? 'application/octet-stream';
        $messageType    = in_array($ext, ['jpg','jpeg','png','webp','gif'], true) ? 'image' : 'pdf';
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'File upload failed.']);
        exit;
    }
}

if ($body === '' && $attachmentPath === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Message body or attachment required.']);
    exit;
}

$pdo = db();

// ── Resolve / create thread ───────────────────────────────────────────────────
if (!$threadId) {
    // New thread — landlord must supply tenant_id, or tenant must supply (auto-derives landlord from property)
    $newLandlordId  = (int)($_POST['landlord_id']  ?? 0);
    $newTenantId    = (int)($_POST['tenant_id']    ?? 0);
    $newPropertyId  = ($_POST['property_id']  !== '' && $_POST['property_id'] !== null)
                      ? (int)$_POST['property_id'] : null;
    $contextType    = $_POST['context_type']  ?? 'general';
    $contextId      = ($_POST['context_id']   !== '' && isset($_POST['context_id']))
                      ? (int)$_POST['context_id'] : null;
    $subject        = trim($_POST['subject']  ?? 'New Message');

    $allowed_ctx = ['inquiry','lease','maintenance','broadcast','general'];
    if (!in_array($contextType, $allowed_ctx, true)) $contextType = 'general';

    // Security: landlord can only create threads for their own tenants
    if ($user['role'] === ROLE_LANDLORD) {
        $newLandlordId = (int)$user['id'];
        if (!$newTenantId) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'tenant_id required.']);
            exit;
        }
    } else {
        // Tenant: derive landlord from active lease or property
        $newTenantId = (int)$user['id'];
        if (!$newLandlordId && $newPropertyId) {
            $llStmt = $pdo->prepare('SELECT landlord_id FROM properties WHERE id = ?');
            $llStmt->execute([$newPropertyId]);
            $newLandlordId = (int)($llStmt->fetchColumn() ?: 0);
        }
        if (!$newLandlordId) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Cannot determine landlord.']);
            exit;
        }
    }

    $stmtNew = $pdo->prepare(
        'INSERT INTO message_threads
            (landlord_id, tenant_id, property_id, context_type, context_id, subject, status)
         VALUES (?, ?, ?, ?, ?, ?, \'open\')'
    );
    $stmtNew->execute([$newLandlordId, $newTenantId, $newPropertyId, $contextType, $contextId, $subject]);
    $threadId = (int)$pdo->lastInsertId();
}

// ── Security: verify sender belongs to this thread ────────────────────────────
$thStmt = $pdo->prepare('SELECT * FROM message_threads WHERE id = ?');
$thStmt->execute([$threadId]);
$thread = $thStmt->fetch();

if (!$thread) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Thread not found.']);
    exit;
}

$senderId = (int)$user['id'];
if ($senderId !== (int)$thread['landlord_id'] && $senderId !== (int)$thread['tenant_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

// ── Insert message ────────────────────────────────────────────────────────────
$stmtMsg = $pdo->prepare(
    'INSERT INTO messages
        (thread_id, sender_id, body, message_type, attachment_path, attachment_mime, attachment_name)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmtMsg->execute([
    $threadId, $senderId, $body ?: null,
    $messageType, $attachmentPath, $attachmentMime, $attachmentName,
]);
$messageId = (int)$pdo->lastInsertId();

// ── Update thread unread counters and last_message_at ────────────────────────
$isLandlord = $senderId === (int)$thread['landlord_id'];
$counterCol = $isLandlord ? 'unread_tenant' : 'unread_landlord';
$pdo->prepare("UPDATE message_threads SET {$counterCol} = {$counterCol} + 1, last_message_at = NOW() WHERE id = ?")
    ->execute([$threadId]);

// ── In-system notification to recipient ──────────────────────────────────────
$recipientId = $isLandlord ? (int)$thread['tenant_id'] : (int)$thread['landlord_id'];
$senderName  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$notifTitle  = 'New message from ' . $senderName;
$notifBody   = $body !== '' ? (mb_strlen($body) > 80 ? mb_substr($body, 0, 77) . '…' : $body) : '📎 Attachment';

$pdo->prepare(
    'INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)'
)->execute([$recipientId, $notifTitle, $notifBody]);

// ── Fetch the created message back for response ───────────────────────────────
$stmtFetch = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
$stmtFetch->execute([$messageId]);
$msg = $stmtFetch->fetch();

echo json_encode([
    'success'    => true,
    'message_id' => $messageId,
    'thread_id'  => $threadId,
    'created_at' => $msg['created_at'],
]);
