<?php
require_once __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json');
try {
    $user = require_login();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { throw new RuntimeException('Invalid request.'); }
    $token = $body['csrf_token'] ?? ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { throw new RuntimeException('Invalid CSRF token.'); }
    $ids = array_filter(array_map('intval', $body['ids'] ?? []), fn($id) => $id > 0);
    if (empty($ids)) { echo json_encode(['ok' => true, 'marked' => 0]); exit; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params       = array_merge($ids, [(int)$user['id']]);
    $stmt = db()->prepare(
        "UPDATE notifications SET read_at = NOW()
         WHERE id IN ({$placeholders}) AND user_id = ? AND read_at IS NULL"
    );
    $stmt->execute($params);
    echo json_encode(['ok' => true, 'marked' => $stmt->rowCount()]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
