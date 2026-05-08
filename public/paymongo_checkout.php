<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

$orderId = (int)($_GET['order'] ?? 0);
$stmt = db()->prepare(
    'SELECT po.*, a.landlord_id, a.property_title FROM payment_orders po
     JOIN applications a ON a.id = po.application_id
     WHERE po.id = ? AND a.landlord_id = ?'
);
$stmt->execute([$orderId, (int)$user['id']]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    exit('Payment order not found.');
}

if (empty($config['paymongo']['secret_key'])) {
    $receipt = 'RCPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    mark_payment_order_paid($orderId, $receipt, 'Demo Payment');
    advance_application((int)$order['application_id'], 'PAID', 6);
    audit_log((int)$user['id'], 'PAYMENT_SIMULATED', 'payment_orders', $orderId, ['receipt_number' => $receipt]);
    $_SESSION['flash_success'] = 'Demo payment completed and digital receipt generated: ' . $receipt;
    redirect('landlord/application-show.php?id=' . (int)$order['application_id']);
}

$payload = [
    'data' => [
        'attributes' => [
            'line_items' => [[
                'currency' => 'PHP',
                'amount' => (int)((float)$order['service_fee'] * 100),
                'name' => $order['payment_for'] ?? default_payment_for($order),
                'quantity' => 1,
            ]],
            'payment_method_types' => ['gcash', 'paymaya', 'card'],
            'success_url' => $config['app']['base_url'] . '/payment_success.php?order=' . $orderId,
            'cancel_url' => $config['app']['base_url'] . '/landlord/application-show.php?id=' . (int)$order['application_id'],
            'description' => 'OP ' . $order['op_number'] . ' / Registry ' . $order['registry_number'],
        ],
    ],
];

$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_USERPWD => $config['paymongo']['secret_key'] . ':',
    CURLOPT_POSTFIELDS => json_encode($payload),
]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$data = json_decode((string)$response, true);
if ($status >= 200 && $status < 300 && !empty($data['data']['attributes']['checkout_url'])) {
    $update = db()->prepare('UPDATE payment_orders SET paymongo_checkout_id = ? WHERE id = ?');
    $update->execute([$data['data']['id'], $orderId]);
    audit_log((int)$user['id'], 'PAYMONGO_CHECKOUT_CREATED', 'payment_orders', $orderId);
    header('Location: ' . $data['data']['attributes']['checkout_url']);
    exit;
}

$_SESSION['flash_error'] = 'Unable to create PayMongo checkout session.';
redirect('landlord/application-show.php?id=' . (int)$order['application_id']);
