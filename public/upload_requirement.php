<?php
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');

try {
    $user = require_role([ROLE_LANDLORD]);
    verify_csrf();

    $applicationId = (int)($_POST['application_id'] ?? 0);
    $requirementKey = trim($_POST['requirement_key'] ?? '');

    $appStmt = db()->prepare('SELECT id FROM applications WHERE id = ? AND landlord_id = ?');
    $appStmt->execute([$applicationId, (int)$user['id']]);
    if (!$appStmt->fetch()) {
        throw new RuntimeException('Application not found.');
    }

    $docStmt = db()->prepare('SELECT * FROM requirement_documents WHERE application_id = ? AND requirement_key = ?');
    $docStmt->execute([$applicationId, $requirementKey]);
    $document = $docStmt->fetch();
    if (!$document) {
        throw new RuntimeException('Requirement not found.');
    }

    if (empty($_FILES['document_file']['name'])) {
        throw new RuntimeException('No file selected.');
    }

    $path = secure_upload($_FILES['document_file'], 'applications/' . $applicationId);
    $encryptedName = encrypt_sensitive($_FILES['document_file']['name']);
    $update = db()->prepare(
        'UPDATE requirement_documents
         SET file_path = ?, original_name_enc = ?, original_name_nonce = ?, uploaded_at = NOW()
         WHERE id = ?'
    );
    $update->execute([$path, $encryptedName['ciphertext'], $encryptedName['nonce'], (int)$document['id']]);
    audit_log((int)$user['id'], 'REQUIREMENT_AUTOSAVED', 'requirement_documents', (int)$document['id']);

    echo json_encode([
        'ok' => true,
        'message' => 'File saved.',
        'preview_url' => '../document_preview.php?id=' . (int)$document['id'],
    ]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
