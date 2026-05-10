<?php
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');

try {
    $user = require_role([ROLE_LANDLORD]);
    verify_csrf();

    $applicationId  = (int)($_POST['application_id'] ?? 0);
    $requirementKey = trim($_POST['requirement_key'] ?? '');
    if ($requirementKey === 'vicinity_map') {
        throw new RuntimeException('Vicinity Map is generated automatically from the application map.');
    }

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

    // Store file binary in DB
    $upload        = read_upload_for_db($_FILES['document_file']);
    $encryptedName = encrypt_sensitive($_FILES['document_file']['name']);

    $update = db()->prepare(
        'UPDATE requirement_documents
         SET file_data = ?, file_mime = ?, file_path = NULL,
             original_name_enc = ?, original_name_nonce = ?, uploaded_at = NOW()
         WHERE id = ?'
    );
    $update->execute([
        $upload['file_data'],
        $upload['file_mime'],
        $encryptedName['ciphertext'],
        $encryptedName['nonce'],
        (int)$document['id'],
    ]);

    // ── If this document was previously FAILED, reset it to PENDING and
    //    notify all active Zoning Officers so they know to re-evaluate. ────────
    $wasFailed = ($document['evaluation_status'] ?? '') === 'FAILED';
    if ($wasFailed) {
        db()->prepare(
            'UPDATE requirement_documents
             SET evaluation_status = "PENDING", officer_notes = NULL,
                 evaluated_by = NULL, evaluated_at = NULL
             WHERE id = ?'
        )->execute([(int)$document['id']]);

        // Fetch application details for the notification message
        $appInfo = db()->prepare(
            'SELECT a.registry_number, a.property_title,
                    CONCAT_WS(" ", u.first_name, u.last_name) AS landlord_name
             FROM applications a
             JOIN users u ON u.id = a.landlord_id
             WHERE a.id = ?'
        );
        $appInfo->execute([$applicationId]);
        $appRow = $appInfo->fetch();

        $notifTitle = 'Document Re-uploaded: ' . ($document['title'] ?? $requirementKey);
        $notifMsg   = sprintf(
            'Applicant %s has re-uploaded "%s" for application %s (%s). The document has been reset to Pending and is ready for re-evaluation.',
            $appRow['landlord_name']   ?? 'Applicant',
            $document['title']         ?? $requirementKey,
            $appRow['registry_number'] ?? '',
            $appRow['property_title']  ?? ''
        );

        notify_role(ROLE_ZONING, $applicationId, $notifTitle, $notifMsg);
        audit_log((int)$user['id'], 'FAILED_DOC_REUPLOADED', 'requirement_documents', (int)$document['id'], [
            'requirement_key' => $requirementKey,
            'application_id'  => $applicationId,
        ]);
    } else {
        audit_log((int)$user['id'], 'REQUIREMENT_AUTOSAVED', 'requirement_documents', (int)$document['id']);
    }

    echo json_encode([
        'ok'          => true,
        'message'     => 'File saved.',
        'preview_url' => rtrim($config['app']['base_url'], '/') . '/document_preview.php?id=' . (int)$document['id'],
        'file_mime'   => $upload['file_mime'],
    ]);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
