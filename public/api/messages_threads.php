<?php
/**
 * API: Get paginated thread list for the inbox sidebar.
 * GET /api/messages_threads.php?filter=all|inquiry|lease|maintenance|broadcast&page=1
 *
 * Returns JSON: { threads: [...], total_unread: N }
 */

declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$isLandlord = $user['role'] === ROLE_LANDLORD;
$userId     = (int)$user['id'];
$filter     = $_GET['filter'] ?? 'all';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 30;
$offset     = ($page - 1) * $perPage;

$validFilters = ['all','inquiry','lease','maintenance','broadcast','general'];
if (!in_array($filter, $validFilters, true)) $filter = 'all';

$pdo = db();

$userCol    = $isLandlord ? 'mt.landlord_id' : 'mt.tenant_id';
$otherCol   = $isLandlord ? 'mt.tenant_id'   : 'mt.landlord_id';
$unreadCol  = $isLandlord ? 'mt.unread_landlord' : 'mt.unread_tenant';

$whereParts = ["{$userCol} = :uid"];
$params = [':uid' => $userId];

if ($filter !== 'all') {
    $whereParts[] = 'mt.context_type = :ctx';
    $params[':ctx'] = $filter;
}

$whereSQL = implode(' AND ', $whereParts);

$sql = "
    SELECT
        mt.*,
        {$unreadCol} AS my_unread,
        u.first_name AS other_first, u.last_name AS other_last, u.role AS other_role,
        p.title AS property_title,
        (SELECT m2.body FROM messages m2 WHERE m2.thread_id = mt.id ORDER BY m2.created_at DESC LIMIT 1) AS last_body,
        (SELECT m2.message_type FROM messages m2 WHERE m2.thread_id = mt.id ORDER BY m2.created_at DESC LIMIT 1) AS last_type,
        (SELECT m2.created_at FROM messages m2 WHERE m2.thread_id = mt.id ORDER BY m2.created_at DESC LIMIT 1) AS last_msg_at
    FROM message_threads mt
    JOIN users u ON u.id = {$otherCol}
    LEFT JOIN properties p ON p.id = mt.property_id
    WHERE {$whereSQL}
    ORDER BY mt.last_message_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// ── Total unread across all threads ──────────────────────────────────────────
$totalStmt = $pdo->prepare("SELECT COALESCE(SUM({$unreadCol}), 0) FROM message_threads WHERE {$userCol} = ?");
$totalStmt->execute([$userId]);
$totalUnread = (int)$totalStmt->fetchColumn();

$threads = array_map(function ($row) {
    $lastPreview = $row['last_body']
        ? (mb_strlen($row['last_body']) > 60 ? mb_substr($row['last_body'], 0, 57) . '…' : $row['last_body'])
        : match ($row['last_type']) {
            'image' => '📷 Image',
            'pdf'   => '📄 PDF',
            default => '',
        };

    return [
        'id'             => (int)$row['id'],
        'context_type'   => $row['context_type'],
        'subject'        => $row['subject'],
        'status'         => $row['status'],
        'other_name'     => trim($row['other_first'] . ' ' . $row['other_last']),
        'other_role'     => $row['other_role'],
        'property_title' => $row['property_title'] ?? null,
        'last_preview'   => $lastPreview,
        'last_msg_at'    => $row['last_msg_at'] ?? $row['created_at'],
        'my_unread'      => (int)$row['my_unread'],
        'unread_landlord'=> (int)$row['unread_landlord'],
        'unread_tenant'  => (int)$row['unread_tenant'],
    ];
}, $rows);

echo json_encode([
    'threads'      => $threads,
    'total_unread' => $totalUnread,
    'page'         => $page,
    'per_page'     => $perPage,
]);
