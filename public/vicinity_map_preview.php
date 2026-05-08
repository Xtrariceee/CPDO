<?php
/**
 * Serves the generated vicinity map PDF for a given application.
 * Accessible by the landlord who owns the application and all CPDO staff.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$user          = require_login();
$applicationId = (int)($_GET['id'] ?? 0);

header('X-Frame-Options: SAMEORIGIN');

$stmt = db()->prepare('SELECT id, landlord_id, vicinity_map_pdf_path FROM applications WHERE id = ?');
$stmt->execute([$applicationId]);
$application = $stmt->fetch();

if (!$application || empty($application['vicinity_map_pdf_path'])) {
    http_response_code(404);
    exit('Vicinity map not found.');
}

$allowed = (int)$application['landlord_id'] === (int)$user['id']
    || in_array($user['role'], [ROLE_SYSTEM_ADMIN, ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_TWG], true);

if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

$relativePath = $application['vicinity_map_pdf_path'];
$absPath      = realpath(__DIR__ . '/../' . $relativePath);
$storageRoot  = realpath(__DIR__ . '/../storage/vicinity_maps');

if (!$absPath || !$storageRoot || !str_starts_with($absPath, $storageRoot)) {
    http_response_code(404);
    exit('Vicinity map file not found.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="vicinity_map.pdf"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($absPath));
readfile($absPath);
exit;
