<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

$orderId = (int)($_GET['order'] ?? 0);
$stmt = db()->prepare(
    'SELECT po.*, a.landlord_id FROM payment_orders po
     JOIN applications a ON a.id = po.application_id
     WHERE po.id = ? AND a.landlord_id = ?'
);
$stmt->execute([$orderId, (int)$user['id']]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    exit('Payment order not found.');
}

$receipt = 'RCPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
$update = db()->prepare('UPDATE payment_orders SET status = "PAID", receipt_number = ?, paid_at = NOW() WHERE id = ?');
$update->execute([$receipt, $orderId]);
advance_application((int)$order['application_id'], 'PAID', 6);
audit_log((int)$user['id'], 'PAYMENT_SUCCESS', 'payment_orders', $orderId, ['receipt_number' => $receipt]);

$_SESSION['flash_success'] = 'Payment successful. Digital receipt generated: ' . $receipt;
redirect('landlord/application-show.php?id=' . (int)$order['application_id']);
