<?php

declare(strict_types=1);

function workflow_status_label(string $status): string
{
    return match ($status) {
        'DRAFT', 'pending_documents' => 'Pending Documents',
        'SUBMITTED', 'PRE_EVALUATION', 'under_evaluation' => 'Under Evaluation',
        'PAYMENT_PENDING', 'for_payment' => 'For Payment',
        'PAID', 'INSPECTION_SCHEDULED', 'INSPECTION_DONE', 'for_inspection' => 'For Inspection',
        'FOR_MEETING', 'DELIBERATION', 'DEFERRED', 'under_deliberation' => 'Under Deliberation',
        'APPROVED', 'approved' => 'Approved',
        'DISAPPROVED', 'rejected' => 'Rejected',
        default => $status,
    };
}

function officer_applications(?array $statuses = null): array
{
    $sql = 'SELECT a.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name, u.email AS landlord_email
            FROM applications a
            JOIN users u ON u.id = a.landlord_id';
    $params = [];

    if ($statuses) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql .= ' WHERE a.phase_status IN (' . $placeholders . ')';
        $params = $statuses;
    }

    $sql .= ' ORDER BY a.updated_at DESC, a.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function officer_application(int $applicationId): array
{
    $stmt = db()->prepare(
        'SELECT a.*, CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name) AS landlord_name, u.email AS landlord_email
         FROM applications a
         JOIN users u ON u.id = a.landlord_id
         WHERE a.id = ?'
    );
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch();
    if (!$application) {
        http_response_code(404);
        exit('Application not found.');
    }
    return $application;
}

function payment_order_for_application(int $applicationId): ?array
{
    $stmt = db()->prepare('SELECT * FROM payment_orders WHERE application_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$applicationId]);
    return $stmt->fetch() ?: null;
}

function create_payment_order_if_missing(array $application, int $userId): array
{
    $existing = payment_order_for_application((int)$application['id']);
    if ($existing) {
        return $existing;
    }

    $opNumber = 'OP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $stmt = db()->prepare('INSERT INTO payment_orders (application_id, op_number, registry_number) VALUES (?, ?, ?)');
    $stmt->execute([(int)$application['id'], $opNumber, $application['registry_number']]);
    advance_application((int)$application['id'], 'PAYMENT_PENDING', 4);
    audit_log($userId, 'ORDER_OF_PAYMENT_GENERATED', 'payment_orders', (int)db()->lastInsertId(), ['op_number' => $opNumber]);

    return payment_order_for_application((int)$application['id']);
}

function requirements_all_passed(int $applicationId): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM requirement_documents WHERE application_id = ? AND evaluation_status != "PASSED"');
    $stmt->execute([$applicationId]);
    return (int)$stmt->fetchColumn() === 0;
}

function latest_inspection_for_application(int $applicationId): ?array
{
    $stmt = db()->prepare('SELECT * FROM inspections WHERE application_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$applicationId]);
    return $stmt->fetch() ?: null;
}

function latest_meeting_for_application(int $applicationId): ?array
{
    $stmt = db()->prepare('SELECT * FROM meetings WHERE application_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$applicationId]);
    return $stmt->fetch() ?: null;
}
