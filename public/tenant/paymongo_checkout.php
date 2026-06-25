<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TENANT]);

$leaseId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT la.*, ra.tenant_id, ra.id AS application_id, p.title AS property_title
     FROM lease_agreements la
     JOIN rental_applications ra ON ra.id = la.application_id
     JOIN properties p ON p.id = ra.property_id
     WHERE la.id = ? AND ra.tenant_id = ?'
);
$stmt->execute([$leaseId, (int)$user['id']]);
$lease = $stmt->fetch();

if (!$lease) {
    http_response_code(404);
    exit('Lease agreement not found.');
}

if ($lease['status'] !== 'SIGNED') {
    $_SESSION['flash_error'] = 'Lease agreement is not in a payable state.';
    redirect('tenant/application-status.php?id=' . $lease['application_id']);
}

$applicationId = (int)$lease['application_id'];
$baseUrl       = rtrim($config['app']['base_url'], '/');
$secretKey     = trim($config['paymongo']['secret_key'] ?? '');

// Compute payment items
$securityDeposit = (float)$lease['security_deposit'];
$advanceRent = (float)$lease['advance_payment'];
$totalAmount = $securityDeposit + $advanceRent;

// ── Demo / no-key path ────────────────────────────────────────────────────────
if (empty($secretKey)) {
    $_SESSION['flash_success'] = 'Demo payment simulation initiated.';
    redirect('tenant/payment_success.php?id=' . $leaseId);
}

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
        __DIR__ . '/../../storage/cacert.pem',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) { return $path; }
    }
    return '';
})();

// ── Build PayMongo checkout session payload ───────────────────────────────────
$successUrl = $baseUrl . '/tenant/payment_success.php?id=' . $leaseId;
$cancelUrl  = $baseUrl . '/tenant/application-status.php?id=' . $applicationId;

$lineItems = [];
if ($securityDeposit > 0) {
    $lineItems[] = [
        'currency' => 'PHP',
        'amount'   => (int)round($securityDeposit * 100),
        'name'     => 'Security Deposit - ' . $lease['property_title'],
        'quantity' => 1,
    ];
}
if ($advanceRent > 0) {
    $lineItems[] = [
        'currency' => 'PHP',
        'amount'   => (int)round($advanceRent * 100),
        'name'     => 'Advance Payment - ' . $lease['property_title'],
        'quantity' => 1,
    ];
}

// Fallback in case both are zero (prevent PayMongo API error)
if (empty($lineItems)) {
    $lineItems[] = [
        'currency' => 'PHP',
        'amount'   => 10000, // PHP 100.00 fallback
        'name'     => 'Lease Administrative Verification Fee',
        'quantity' => 1,
    ];
}

$payload = [
    'data' => [
        'attributes' => [
            'line_items' => $lineItems,
            'payment_method_types' => ['gcash', 'paymaya', 'card'],
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'description'          => 'Lease Agreement Payment for property: ' . $lease['property_title'],
            'reference_number'     => 'LEASE-' . $leaseId,
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
    audit_log((int)$user['id'], 'PAYMONGO_LEASE_CURL_ERROR', 'lease_agreements', $leaseId, ['error' => $curlError]);
    $_SESSION['flash_error'] = 'Could not reach PayMongo: ' . $curlError;
    redirect('tenant/application-status.php?id=' . $applicationId);
}

$data = json_decode((string)$response, true);

// ── Handle PayMongo API error ─────────────────────────────────────────────────
if ($httpCode < 200 || $httpCode >= 300 || empty($data['data']['attributes']['checkout_url'])) {
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

    audit_log((int)$user['id'], 'PAYMONGO_LEASE_API_ERROR', 'lease_agreements', $leaseId, [
        'http_code' => $httpCode,
        'detail'    => $detail,
    ]);

    $_SESSION['flash_error'] = 'PayMongo error: ' . $detail;
    redirect('tenant/application-status.php?id=' . $applicationId);
}

// ── Success — redirect to PayMongo checkout ───────────────────────────────────
$checkoutId  = $data['data']['id'];
$checkoutUrl = $data['data']['attributes']['checkout_url'];

db()->prepare('UPDATE lease_agreements SET paymongo_checkout_id = ? WHERE id = ?')
   ->execute([$checkoutId, $leaseId]);

audit_log((int)$user['id'], 'PAYMONGO_LEASE_CHECKOUT_CREATED', 'lease_agreements', $leaseId, [
    'checkout_id' => $checkoutId,
]);

header('Location: ' . $checkoutUrl);
exit;
