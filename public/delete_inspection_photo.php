<?php
require_once __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json');
try {
    $user    = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);
    verify_csrf();
    $photoId = (int)($_POST['photo_id'] ?? 0);
    if (!$photoId) { throw new RuntimeException('Invalid photo ID.'); }
    $stmt = db()->prepare('SELECT id, uploaded_by FROM inspection_photos WHERE id = ?');
    $stmt->execute([$photoId]);
    $photo = $stmt->fetch();
    if (!$photo) { throw new RuntimeException('Photo not found.'); }
    if ((int)$photo['uploaded_by'] !== (int)$user['id'] && $user['role'] !== ROLE_SYSTEM_ADMIN) {
        throw new RuntimeException('You can only delete photos you uploaded.');
    }
    db()->prepare('DELETE FROM inspection_photos WHERE id = ?')->execute([$photoId]);
    audit_log((int)$user['id'], 'INSPECTION_PHOTO_DELETED', 'inspection_photos', $photoId);
    echo json_encode(['ok' => true, 'message' => 'Photo deleted.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
