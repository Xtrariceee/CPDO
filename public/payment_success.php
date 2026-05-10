<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

$orderId = (int)($_GET['order'] ?? 0);
$stmt = db()->prepare(
    'SELECT po.*, a.landlord_id, a.id AS application_id
     FROM payment_orders po
     JOIN applications a ON a.id = po.application_id
     WHERE po.id = ? AND a.landlord_id = ?'
);
$stmt->execute([$orderId, (int)$user['id']]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('Payment order not found.');
}

$applicationId = (int)$order['application_id'];

// Idempotent — only mark paid once. If already paid, just redirect.
if ($order['status'] === 'PAID') {
    $_SESSION['flash_success'] = 'Payment already recorded. Receipt: ' . e($order['receipt_number']);
    redirect('landlord/application-show.php?id=' . $applicationId);
}

$receipt = 'RCPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
mark_payment_order_paid($orderId, $receipt, 'PayMongo');
advance_application($applicationId, 'PAID', 6);
audit_log((int)$user['id'], 'PAYMENT_SUCCESS', 'payment_orders', $orderId, ['receipt_number' => $receipt]);

$_SESSION['flash_success'] = 'Payment successful. Receipt: ' . $receipt;
redirect('landlord/application-show.php?id=' . $applicationId);
