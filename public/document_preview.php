<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user       = require_login();
$documentId = (int)($_GET['id'] ?? 0);
$type       = $_GET['type'] ?? 'document';

header('X-Frame-Options: SAMEORIGIN');

// ── Final output (endorsement / resolution) ───────────────────────────────
if ($type === 'final_output') {
    $stmt = db()->prepare(
        'SELECT fo.*, a.landlord_id
         FROM final_outputs fo
         JOIN applications a ON a.id = fo.application_id
         WHERE fo.id = ?'
    );
    $stmt->execute([$documentId]);
    $fo = $stmt->fetch();

    if (!$fo) { http_response_code(404); exit('Document not found.'); }

    $allowed = (int)$fo['landlord_id'] === (int)$user['id']
        || in_array($user['role'], [ROLE_SYSTEM_ADMIN, ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_TWG], true);
    if (!$allowed) { http_response_code(403); exit('Access denied.'); }

    if (empty($fo['resolution_file_path'])) { http_response_code(404); exit('Resolution file not found.'); }

    $absPath    = realpath(__DIR__ . '/../' . $fo['resolution_file_path']);
    $storageDir = realpath(__DIR__ . '/../storage');

    if (!$absPath || !$storageDir || !str_starts_with($absPath, $storageDir)) {
        http_response_code(404); exit('File not found.');
    }

    $ext  = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'pdf'  => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        default => 'application/octet-stream',
    };

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="endorsement-resolution.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($absPath));
    readfile($absPath);
    exit;
}

$stmt = db()->prepare(
    'SELECT rd.*, a.landlord_id
     FROM requirement_documents rd
     JOIN applications a ON a.id = rd.application_id
     WHERE rd.id = ?'
);
$stmt->execute([$documentId]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$allowed = (int)$document['landlord_id'] === (int)$user['id']
    || in_array($user['role'], [ROLE_SYSTEM_ADMIN, ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_TWG], true);

if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

// Serve from DB (preferred)
if (!empty($document['file_data'])) {
    $mime = $document['file_mime'] ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="document-preview"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($document['file_data']));
    echo $document['file_data'];
    exit;
}

// Fallback: serve from filesystem (legacy)
if (!empty($document['file_path'])) {
    $path        = realpath(__DIR__ . '/../' . $document['file_path']);
    $storageRoot = realpath(__DIR__ . '/../storage/uploads');
    if (!$path || !$storageRoot || !str_starts_with($path, $storageRoot)) {
        http_response_code(404);
        exit('Document not found.');
    }
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="document-preview"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

http_response_code(404);
exit('Document not found.');
