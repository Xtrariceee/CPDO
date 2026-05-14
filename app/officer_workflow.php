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

function default_payment_for(array $application = []): string
{
    return 'CPDO Land Reclassification Service Fee';
}

function create_payment_insert(array $application, string $opNumber): array
{
    $columns = [
        'application_id', 'op_number', 'registry_number',
        'account_name', 'payment_for',
    ];
    $values = [
        (int)$application['id'],
        $opNumber,
        $application['registry_number'],
        $application['account_name'] ?? $application['landlord_name'] ?? null,
        default_payment_for($application),
    ];

    return [$columns, $values];
}

function create_payment_order_if_missing(array $application, int $userId): array
{
    $existing = payment_order_for_application((int)$application['id']);
    if ($existing) {
        return $existing;
    }

    $opNumber = 'OP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $pdo  = db();
    [$columns, $values] = create_payment_insert($application, $opNumber);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare(
        'INSERT INTO payment_orders (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
    );
    $stmt->execute($values);
    advance_application((int)$application['id'], 'PAYMENT_PENDING', 4);
    audit_log($userId, 'ORDER_OF_PAYMENT_GENERATED', 'payment_orders', (int)$pdo->lastInsertId(), ['op_number' => $opNumber]);

    return payment_order_for_application((int)$application['id']);
}

function mark_payment_order_paid(int $orderId, string $receiptNumber, string $paymentMethod): void
{
    $stmt = db()->prepare(
        'UPDATE payment_orders
         SET status = "PAID", receipt_number = ?, paid_at = NOW(), payment_method = ?
         WHERE id = ?'
    );
    $stmt->execute([$receiptNumber, $paymentMethod, $orderId]);
}

function verify_payment_order(int $orderId, int $userId): void
{
    $stmt = db()->prepare(
        'UPDATE payment_orders SET verified_at = NOW(), verified_by = ? WHERE id = ?'
    );
    $stmt->execute([$userId, $orderId]);
}

function payment_order_is_verified(array $order): bool
{
    if (array_key_exists('verified_at', $order)) {
        return !empty($order['verified_at']);
    }

    return ($order['status'] ?? '') === 'PAID';
}

function schedule_inspection_for_application(int $applicationId, string $date, string $time, int $userId): string
{
    $date = trim($date);
    $time = trim($time);
    if ($date === '' || $time === '') {
        throw new RuntimeException('Inspection date and time are required.');
    }

    $timeWithSeconds = strlen($time) === 5 ? $time . ':00' : $time;
    $scheduledAt = $date . ' ' . $timeWithSeconds;

    $stmt = db()->prepare(
        'INSERT INTO inspections
             (application_id, scheduled_at, scheduled_date, scheduled_time, assigned_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$applicationId, $scheduledAt, $date, $timeWithSeconds, $userId]);

    return $scheduledAt;
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

function all_meetings_for_application(int $applicationId): array
{
    $stmt = db()->prepare('SELECT * FROM meetings WHERE application_id = ? ORDER BY scheduled_at DESC, id DESC');
    $stmt->execute([$applicationId]);
    return $stmt->fetchAll();
}
