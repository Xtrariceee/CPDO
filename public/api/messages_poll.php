<?php
/**
 * API: Poll for new messages in a thread (long-poll style).
 * GET /api/messages_poll.php?thread_id=X&after_id=Y
 *
 * Returns JSON array of messages newer than after_id.
 * Also marks all messages in this thread as read by the current user
 * and resets the appropriate unread counter.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

global $config;

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$threadId = (int)($_GET['thread_id'] ?? 0);
$afterId  = (int)($_GET['after_id']  ?? 0);

if (!$threadId) {
    http_response_code(422);
    echo json_encode(['error' => 'thread_id required']);
    exit;
}

$pdo = db();

// ── Verify membership ─────────────────────────────────────────────────────────
$thStmt = $pdo->prepare('SELECT * FROM message_threads WHERE id = ?');
$thStmt->execute([$threadId]);
$thread = $thStmt->fetch();

if (!$thread) {
    http_response_code(404);
    echo json_encode(['error' => 'Thread not found']);
    exit;
}

$userId = (int)$user['id'];
$isLandlord = $userId === (int)$thread['landlord_id'];
$isTenant   = $userId === (int)$thread['tenant_id'];

if (!$isLandlord && !$isTenant) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// ── Mark unread messages from the OTHER party as read ─────────────────────────
// "Recipient" = messages sent by the other person
$otherPartyId = $isLandlord ? (int)$thread['tenant_id'] : (int)$thread['landlord_id'];
$pdo->prepare(
    'UPDATE messages
        SET is_read_by_recipient = 1, read_at = NOW()
      WHERE thread_id = ? AND sender_id = ? AND is_read_by_recipient = 0'
)->execute([$threadId, $otherPartyId]);

// Reset my unread counter
$counterCol = $isLandlord ? 'unread_landlord' : 'unread_tenant';
$pdo->prepare("UPDATE message_threads SET {$counterCol} = 0 WHERE id = ?")->execute([$threadId]);

// ── Fetch new messages ────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT m.*, u.first_name, u.last_name, u.role
       FROM messages m
       JOIN users u ON u.id = m.sender_id
      WHERE m.thread_id = ? AND m.id > ?
      ORDER BY m.created_at ASC
      LIMIT 100'
);
$stmt->execute([$threadId, $afterId]);
$rows = $stmt->fetchAll();

$baseUrl = rtrim($config['app']['base_url'], '/');

$messages = array_map(function ($row) use ($baseUrl) {
    $attachmentUrl = null;
    if ($row['attachment_path']) {
        $attachmentUrl = $baseUrl . '/' . $row['attachment_path'];
    }
    return [
        'id'                   => (int)$row['id'],
        'sender_id'            => (int)$row['sender_id'],
        'sender_name'          => trim($row['first_name'] . ' ' . $row['last_name']),
        'sender_role'          => $row['role'],
        'body'                 => $row['body'],
        'message_type'         => $row['message_type'],
        'attachment_url'       => $attachmentUrl,
        'attachment_mime'      => $row['attachment_mime'],
        'attachment_name'      => $row['attachment_name'],
        'is_read_by_recipient' => (bool)$row['is_read_by_recipient'],
        'read_at'              => $row['read_at'],
        'created_at'           => $row['created_at'],
    ];
}, $rows);

// ── Global unread counts for badge update ────────────────────────────────────
$unreadCol = $isLandlord ? 'unread_landlord' : 'unread_tenant';
$totalUnread = (int)$pdo->prepare(
    "SELECT COALESCE(SUM({$unreadCol}), 0) FROM message_threads WHERE " .
    ($isLandlord ? 'landlord_id' : 'tenant_id') . " = ?"
)->execute([$userId]) ? $pdo->query(
    "SELECT COALESCE(SUM({$unreadCol}), 0) FROM message_threads WHERE " .
    ($isLandlord ? 'landlord_id' : 'tenant_id') . " = {$userId}"
)->fetchColumn() : 0;

echo json_encode([
    'messages'        => $messages,
    'total_unread'    => (int)$totalUnread,
    'thread_id'       => $threadId,
]);
