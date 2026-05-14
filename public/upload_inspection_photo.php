<?php
ob_start();
require_once __DIR__ . '/../app/bootstrap.php';
ob_clean();
header('Content-Type: application/json');
try {
    $user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);
    verify_csrf();
    $applicationId = (int)($_POST['application_id'] ?? 0);
    $caption       = trim($_POST['caption']  ?? '');
    $category      = trim($_POST['category'] ?? 'other');
    $validCategories = ['frontage','road_access','lot_view','adjacent_uses','existing_structures','issue_area','other'];
    if (!in_array($category, $validCategories, true)) { $category = 'other'; }
    $appStmt = db()->prepare('SELECT id FROM applications WHERE id = ?');
    $appStmt->execute([$applicationId]);
    if (!$appStmt->fetch()) { throw new RuntimeException('Application not found.'); }
    $inspection = latest_inspection_for_application($applicationId);
    if (!$inspection) { throw new RuntimeException('No inspection record found.'); }
    if (empty($_FILES['photo']['name']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code ' . ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE));
    }
    $allowedMimes = ['image/jpeg','image/png','image/webp','image/heic','image/heif'];
    $maxBytes     = 10 * 1024 * 1024;
    $tmpPath      = $_FILES['photo']['tmp_name'];
    $fileSize     = (int)$_FILES['photo']['size'];
    if ($fileSize > $maxBytes) { throw new RuntimeException('Photo must be 10 MB or smaller.'); }
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $fileMime = $finfo->file($tmpPath);
    if (!in_array($fileMime, $allowedMimes, true)) { throw new RuntimeException('Only JPEG, PNG, or WebP images are accepted.'); }
    $fileData = file_get_contents($tmpPath);
    if ($fileData === false) { throw new RuntimeException('Could not read uploaded file.'); }
    $stmt = db()->prepare('INSERT INTO inspection_photos (inspection_id, application_id, uploaded_by, file_data, file_mime, file_size, caption, category) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([(int)$inspection['id'], $applicationId, (int)$user['id'], $fileData, $fileMime, $fileSize, $caption ?: null, $category]);
    $photoId = (int)db()->lastInsertId();
    audit_log((int)$user['id'], 'INSPECTION_PHOTO_UPLOADED', 'inspection_photos', $photoId, ['application_id' => $applicationId]);
    $thumbUrl = rtrim($config['app']['base_url'], '/') . '/twg/inspection_photo.php?id=' . $photoId;
    echo json_encode(['ok' => true, 'photo_id' => $photoId, 'thumb_url' => $thumbUrl, 'message' => 'Photo uploaded.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
