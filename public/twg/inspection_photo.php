<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user    = require_login();
$photoId = (int)($_GET['id'] ?? 0);
if (!$photoId) { http_response_code(404); exit('Not found.'); }
$stmt = db()->prepare('SELECT file_data, file_mime FROM inspection_photos WHERE id = ?');
$stmt->execute([$photoId]);
$photo = $stmt->fetch();
if (!$photo || empty($photo['file_data'])) { http_response_code(404); exit('Photo not found.'); }
header('Content-Type: '  . $photo['file_mime']);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo $photo['file_data'];
exit;
