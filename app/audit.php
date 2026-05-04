<?php

declare(strict_types=1);

function audit_log(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, details)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        $details ? json_encode($details) : null,
    ]);
}

function notify_user(int $userId, ?int $applicationId, string $title, string $message): void
{
    try {
        $stmt = db()->prepare('INSERT INTO notifications (user_id, application_id, title, message) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $applicationId, $title, $message]);
    } catch (Throwable $exception) {
        audit_log(null, 'NOTIFICATION_FAILED', 'users', $userId, ['error' => $exception->getMessage()]);
    }
}

function notify_role(string $role, ?int $applicationId, string $title, string $message): void
{
    $stmt = db()->prepare('SELECT id FROM users WHERE role = ? AND status = "ACTIVE"');
    $stmt->execute([$role]);
    foreach ($stmt->fetchAll() as $user) {
        notify_user((int)$user['id'], $applicationId, $title, $message);
    }
}
