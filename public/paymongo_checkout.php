<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD]);

$orderId = (int)($_GET['order'] ?? 0);
$stmt = db()->prepare(
    'SELECT po.*, a.landlord_id, a.property_title, a.id AS application_id
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
$baseUrl       = rtrim($config['app']['base_url'], '/');
$secretKey     = trim($config['paymongo']['secret_key'] ?? '');

// ── Demo / no-key path ────────────────────────────────────────────────────────
if (empty($secretKey)) {
    $receipt = 'RCPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    mark_payment_order_paid($orderId, $receipt, 'Demo Payment');
    advance_application($applicationId, 'PAID', 6);
    audit_log((int)$user['id'], 'PAYMENT_SIMULATED', 'payment_orders', $orderId, ['receipt_number' => $receipt]);
    $_SESSION['flash_success'] = 'Demo payment completed. Receipt: ' . $receipt;
    redirect('landlord/application-show.php?id=' . $applicationId);
}

// Note: PayMongo redirect URLs (success_url / cancel_url) do not require HTTPS —
// only webhook endpoints do. Localhost development works fine with the live API.

// ── Resolve CA bundle (WampServer / XAMPP SSL fix) ────────────────────────────
$caBundle = (function (): string {
    $phpIni = ini_get('curl.cainfo');
    if ($phpIni && is_file($phpIni)) { return $phpIni; }
    $candidates = [
        'C:/wamp64/bin/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION . '/extras/ssl/cacert.pem',
        'C:/wamp64/bin/php/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/extras/ssl/cacert.pem',
        'C:/wamp/bin/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION . '/extras/ssl/cacert.pem',
        'C:/xampp/php/extras/ssl/cacert.pem',
        'C:/xampp/php/cacert.pem',
        __DIR__ . '/../storage/cacert.pem',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) { return $path; }
    }
    return '';
})();

// ── Build PayMongo checkout session payload ───────────────────────────────────
$successUrl = $baseUrl . '/payment_success.php?order=' . $orderId;
$cancelUrl  = $baseUrl . '/landlord/application-show.php?id=' . $applicationId;

$payload = [
    'data' => [
        'attributes' => [
            'line_items' => [[
                'currency' => 'PHP',
                'amount'   => (int)round((float)$order['service_fee'] * 100),
                'name'     => $order['payment_for'] ?? default_payment_for($order),
                'quantity' => 1,
            ]],
            'payment_method_types' => ['gcash', 'paymaya', 'card'],
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'description'          => 'OP ' . $order['op_number'] . ' / Registry ' . $order['registry_number'],
            'reference_number'     => $order['op_number'],
        ],
    ],
];

// ── POST to PayMongo ──────────────────────────────────────────────────────────
$curlOpts = [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_USERPWD        => $secretKey . ':',
    CURLOPT_POSTFIELDS     => json_encode($payload),
];
if ($caBundle !== '') {
    $curlOpts[CURLOPT_CAINFO] = $caBundle;
}

$ch       = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt_array($ch, $curlOpts);
$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ── Handle cURL-level failure ─────────────────────────────────────────────────
if ($response === false || $curlError !== '') {
    audit_log((int)$user['id'], 'PAYMONGO_CURL_ERROR', 'payment_orders', $orderId, ['error' => $curlError]);
    $_SESSION['flash_error'] = 'Could not reach PayMongo: ' . $curlError;
    redirect('landlord/application-show.php?id=' . $applicationId);
}

$data = json_decode((string)$response, true);

// ── Handle PayMongo API error ─────────────────────────────────────────────────
if ($httpCode < 200 || $httpCode >= 300 || empty($data['data']['attributes']['checkout_url'])) {
    // Extract the first PayMongo error message if available
    $apiErrors = $data['errors'] ?? [];
    $detail    = '';
    if (!empty($apiErrors)) {
        $detail = implode(' | ', array_map(
            fn($e) => ($e['code'] ?? '') . ': ' . ($e['detail'] ?? ''),
            $apiErrors
        ));
    } else {
        $detail = 'HTTP ' . $httpCode . ' — ' . substr((string)$response, 0, 300);
    }

    audit_log((int)$user['id'], 'PAYMONGO_API_ERROR', 'payment_orders', $orderId, [
        'http_code' => $httpCode,
        'detail'    => $detail,
    ]);

    $_SESSION['flash_error'] = 'PayMongo error: ' . $detail;
    redirect('landlord/application-show.php?id=' . $applicationId);
}

// ── Success — redirect to PayMongo checkout ───────────────────────────────────
$checkoutId  = $data['data']['id'];
$checkoutUrl = $data['data']['attributes']['checkout_url'];

db()->prepare('UPDATE payment_orders SET paymongo_checkout_id = ? WHERE id = ?')
   ->execute([$checkoutId, $orderId]);

audit_log((int)$user['id'], 'PAYMONGO_CHECKOUT_CREATED', 'payment_orders', $orderId, [
    'checkout_id' => $checkoutId,
]);

header('Location: ' . $checkoutUrl);
exit;
