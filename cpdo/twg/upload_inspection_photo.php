<?php
/**
 * AJAX endpoint — upload a single inspection site photo.
 * Returns JSON: { ok, photo_id, thumb_url, message }
 */
ob_start();
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
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

    // Verify the application exists and has a scheduled inspection
    $appStmt = db()->prepare(
        'SELECT a.id FROM applications a WHERE a.id = ?'
    );
    $appStmt->execute([$applicationId]);
    if (!$appStmt->fetch()) {
        throw new RuntimeException('Application not found.');
    }

    $inspection = latest_inspection_for_application($applicationId);
    if (!$inspection) {
        throw new RuntimeException('No inspection record found for this application.');
    }

    // Validate uploaded file
    if (empty($_FILES['photo']['name']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension.',
        ];
        $errCode = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
        throw new RuntimeException($uploadErrors[$errCode] ?? 'Upload error code ' . $errCode);
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
    $maxBytes     = 10 * 1024 * 1024; // 10 MB

    $tmpPath  = $_FILES['photo']['tmp_name'];
    $fileSize = (int)$_FILES['photo']['size'];

    if ($fileSize > $maxBytes) {
        throw new RuntimeException('Photo must be 10 MB or smaller.');
    }

    // Detect MIME from file content, not the browser-supplied type
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $fileMime = $finfo->file($tmpPath);
    if (!in_array($fileMime, $allowedMimes, true)) {
        throw new RuntimeException('Only JPEG, PNG, WebP, or HEIC images are accepted.');
    }

    $fileData = file_get_contents($tmpPath);
    if ($fileData === false) {
        throw new RuntimeException('Could not read uploaded file.');
    }

    // Insert into inspection_photos
    $stmt = db()->prepare(
        'INSERT INTO inspection_photos
             (inspection_id, application_id, uploaded_by, file_data, file_mime, file_size, caption, category)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$inspection['id'],
        $applicationId,
        (int)$user['id'],
        $fileData,
        $fileMime,
        $fileSize,
        $caption ?: null,
        $category,
    ]);

    $photoId = (int)db()->lastInsertId();

    audit_log((int)$user['id'], 'INSPECTION_PHOTO_UPLOADED', 'inspection_photos', $photoId, [
        'application_id' => $applicationId,
        'category'       => $category,
        'file_size'      => $fileSize,
    ]);

    global $config;
    $cpdoUrl  = rtrim($config['app']['cpdo_url'] ?? '', '/');
    $thumbUrl = $cpdoUrl . '/twg/inspection_photo.php?id=' . $photoId;

    echo json_encode([
        'ok'        => true,
        'photo_id'  => $photoId,
        'thumb_url' => $thumbUrl,
        'message'   => 'Photo uploaded.',
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
