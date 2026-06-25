<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_TENANT]);

$leaseId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT la.*, ra.tenant_id, ra.id AS application_id, p.id AS property_id, p.title AS property_title, p.landlord_id
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

$applicationId = (int)$lease['application_id'];

// Idempotent — only mark paid once
if ($lease['status'] === 'PAID') {
    $_SESSION['flash_success'] = 'Payment already recorded. Transaction reference: ' . e($lease['paymongo_payment_id']);
    redirect('tenant/application-status.php?id=' . $applicationId);
}

// Generate receipt number or fetch from PayMongo if live
$receipt = 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
$totalAmount = (float)$lease['security_deposit'] + (float)$lease['advance_payment'];

$pdo = db();
// Update lease agreement
$pdo->prepare('UPDATE lease_agreements SET status = "PAID", paymongo_payment_id = ?, paid_at = NOW() WHERE id = ?')
    ->execute([$receipt, $leaseId]);

// Update rental application status
$pdo->prepare('UPDATE rental_applications SET status = "PAID" WHERE id = ?')
    ->execute([$applicationId]);

// Notify landlord
$landlordNotif = $pdo->prepare('INSERT INTO notifications (user_id, rental_application_id, title, message, created_at) VALUES (?, ?, ?, ?, NOW())');
$landlordNotif->execute([
    (int)$lease['landlord_id'],
    $applicationId,
    'Lease Deposit & Advance Payment Received',
    'Tenant ' . user_full_name($user) . ' has successfully completed the advance payment and security deposit of ₱' . number_format($totalAmount, 2) . ' for property: "' . $lease['property_title'] . '". Lease is now active.'
]);

// Notify tenant
$tenantNotif = $pdo->prepare('INSERT INTO notifications (user_id, rental_application_id, title, message, created_at) VALUES (?, ?, ?, ?, NOW())');
$tenantNotif->execute([
    (int)$user['id'],
    $applicationId,
    'Lease Payment Confirmed',
    'Your payment of ₱' . number_format($totalAmount, 2) . ' was successfully processed. Your lease agreement for "' . $lease['property_title'] . '" is now active.'
]);

audit_log((int)$user['id'], 'LEASE_PAYMENT_SUCCESS', 'lease_agreements', $leaseId, [
    'receipt_number' => $receipt,
    'total_amount'   => $totalAmount,
]);

$_SESSION['flash_success'] = 'Payment confirmed! Receipt: ' . $receipt;
redirect('tenant/application-status.php?id=' . $applicationId);
