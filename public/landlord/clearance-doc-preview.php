<?php
/**
 * Streams the uploaded clearance document back to the landlord (or AO).
 */
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_LANDLORD, ROLE_ADMIN_OFFICER, ROLE_SYSTEM_ADMIN]);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Missing id.'); }

$stmt = db()->prepare('SELECT * FROM clearance_requests WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) { http_response_code(404); exit('Not found.'); }

/* landlords may only see their own */
if ($user['role'] === ROLE_LANDLORD && (int)$row['landlord_id'] !== (int)$user['id']) {
    http_response_code(403); exit('Forbidden.');
}

if (empty($row['file_data'])) { http_response_code(404); exit('No file.'); }

$mime = $row['file_mime'] ?? 'application/octet-stream';
$name = $row['original_name'] ?? ('clearance-doc-' . $id . '.pdf');
$dl   = isset($_GET['download']);

header('Content-Type: '       . $mime);
header('Content-Length: '     . strlen($row['file_data']));
header('Content-Disposition: ' . ($dl ? 'attachment' : 'inline') . '; filename="' . addslashes($name) . '"');
header('Cache-Control: private, max-age=3600');
echo $row['file_data'];
exit;
