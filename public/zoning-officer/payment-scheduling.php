<?php
require_once __DIR__ . '/../../app/bootstrap.php';
$user = require_role([ROLE_ZONING, ROLE_SYSTEM_ADMIN]);
// This page has been split into payment-verification.php and inspection-scheduling.php
$id = (int)($_GET['id'] ?? 0);
redirect('zoning-officer/payment-verification.php' . ($id ? '?id=' . $id : ''));

$applicationId = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);

/**
 * Helpers used by payment log and inspection scheduling functions.
 */
if (!function_exists('zo_payment_verified')) {
    function zo_payment_verified(array $order): bool
    {
        try {
            return payment_order_is_verified($order);
        } catch (Throwable $exception) {
            return !empty($order['verified_at']) || !empty($order['verified_by']);
        }
    }
}

if (!function_exists('zo_format_datetime')) {
    function zo_format_datetime(?string $value): string
    {
        if (!$value) {
            return 'Not set';
        }

        $timestamp = strtotime($value);

        if (!$timestamp) {
            return $value;
        }

        return date('M d, Y · h:i A', $timestamp);
    }
}

if (!function_exists('zo_format_date')) {
    function zo_format_date(?string $value): string
    {
        if (!$value) {
            return 'Not set';
        }

        $timestamp = strtotime($value);

        if (!$timestamp) {
            return $value;
        }

        return date('M d, Y', $timestamp);
    }
}

if (!function_exists('zo_payment_logs')) {
    function zo_payment_logs(): array
    {
        $logs = [];

        try {
            $stmt = db()->query(
                "SELECT
                    po.*,
                    po.id AS payment_order_id,
                    po.status AS payment_status,
                    a.id AS application_id,
                    a.registry_number,
                    a.property_title,
                    a.property_address,
                    a.phase_status,
                    a.account_name AS application_account_name
                 FROM payment_orders po
                 INNER JOIN applications a ON a.id = po.application_id
                 WHERE po.status IN ('PENDING', 'PAID')
                    OR a.phase_status IN (
                        'PAYMENT_PENDING',
                        'PAID',
                        'INSPECTION_SCHEDULED',
                        'INSPECTION_DONE',
                        'FOR_MEETING',
                        'DELIBERATION',
                        'APPROVED',
                        'DISAPPROVED',
                        'DEFERRED'
                    )
                 ORDER BY po.id DESC"
            );

            $logs = $stmt->fetchAll();
        } catch (Throwable $exception) {
            $logs = [];
        }

        /*
         * Fallback/补充:
         * If an application is already PAYMENT_PENDING/PAID but has no joined payment_order row,
         * still show it in the payment log list.
         */
        $knownAppIds = [];

        foreach ($logs as $log) {
            $knownAppIds[(int)($log['application_id'] ?? 0)] = true;
        }

        try {
            $paymentApps = officer_applications(['PAYMENT_PENDING', 'PAID']);

            foreach ($paymentApps as $app) {
                $appId = (int)$app['id'];

                if (isset($knownAppIds[$appId])) {
                    continue;
                }

                $order = payment_order_for_application($appId) ?: [];

                $logs[] = array_merge($order, [
                    'payment_order_id'         => (int)($order['id'] ?? 0),
                    'application_id'           => $appId,
                    'registry_number'          => $app['registry_number'] ?? '',
                    'property_title'           => $app['property_title'] ?? '',
                    'property_address'         => $app['property_address'] ?? '',
                    'phase_status'             => $app['phase_status'] ?? '',
                    'application_account_name' => $app['account_name'] ?? '',
                    'payment_status'           => strtoupper($order['status'] ?? (($app['phase_status'] ?? '') === 'PAID' ? 'PAID' : 'PENDING')),
                ]);
            }
        } catch (Throwable $exception) {
            // Keep existing logs.
        }

        return $logs;
    }
}

if (!function_exists('zo_scheduled_inspections')) {
    function zo_scheduled_inspections(): array
    {
        try {
            $stmt = db()->query(
                "SELECT
                    a.id,
                    a.registry_number,
                    a.property_title,
                    a.property_address,
                    a.phase_status,
                    s.scheduled_at
                 FROM inspections s
                 INNER JOIN applications a ON a.id = s.application_id
                 WHERE s.scheduled_at IS NOT NULL
                 ORDER BY s.scheduled_at ASC"
            );
            return $stmt->fetchAll();
        } catch (Throwable $exception) {
            return [];
        }
    }
}

if (!function_exists('zo_inspection_slot_taken')) {
    function zo_inspection_slot_taken(string $date, string $time, int $excludeApplicationId = 0): bool
    {
        if (!$date || !$time) {
            return false;
        }

        try {
            $stmt = db()->prepare(
                "SELECT COUNT(*)
                 FROM inspections
                 WHERE DATE(scheduled_at) = ?
                   AND TIME_FORMAT(scheduled_at, '%H:%i') = ?
                   AND application_id <> ?"
            );
            $stmt->execute([$date, $time, $excludeApplicationId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $exception) {
            return false;
        }
    }
}

/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$applicationId) {
        $_SESSION['flash_error'] = 'No application selected.';
        redirect('zoning-officer/payment-scheduling.php');
    }

    $application = officer_application($applicationId);
    $order       = payment_order_for_application($applicationId);

    if (!$order || strtoupper((string)$order['status']) !== 'PAID') {
        $_SESSION['flash_error'] = 'A paid receipt is required before this action can continue.';
        redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId);
    }

    if ($action === 'verify_payment') {
        verify_payment_order((int)$order['id'], (int)$user['id']);

        audit_log((int)$user['id'], 'P6_PAYMENT_VERIFIED', 'payment_orders', (int)$order['id']);

        $_SESSION['flash_success'] = 'Payment verified successfully.';
        redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId . '#payment-panel');
    }

    if ($action === 'schedule_inspection') {
        if (!payment_order_is_verified($order)) {
            $_SESSION['flash_error'] = 'Verify the payment before scheduling inspection.';
            redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId . '#schedule-panel');
        }

        $inspectionDate = trim($_POST['inspection_date'] ?? '');
        $inspectionTime = trim($_POST['inspection_time'] ?? '');

        if ($inspectionDate === '' || $inspectionTime === '') {
            $_SESSION['flash_error'] = 'Inspection date and time are required.';
            redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId . '#schedule-panel');
        }

        if (zo_inspection_slot_taken($inspectionDate, $inspectionTime, $applicationId)) {
            $_SESSION['flash_error'] = 'That inspection slot is already scheduled. Please choose another date or time.';
            redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId . '#schedule-panel');
        }

        try {
            $scheduledAt = schedule_inspection_for_application(
                $applicationId,
                $inspectionDate,
                $inspectionTime,
                (int)$user['id']
            );
        } catch (RuntimeException $exception) {
            $_SESSION['flash_error'] = $exception->getMessage();
            redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId . '#schedule-panel');
        }

        advance_application($applicationId, 'INSPECTION_SCHEDULED', 7);

        notify_user(
            (int)$application['landlord_id'],
            $applicationId,
            'Inspection scheduled',
            'Your CPDO inspection has been scheduled.'
        );

        notify_role(
            ROLE_TWG,
            $applicationId,
            'New inspection assignment',
            'A CPDO inspection is ready for TWG field validation.'
        );

        audit_log(
            (int)$user['id'],
            'P7_INSPECTION_SCHEDULED',
            'applications',
            $applicationId,
            ['scheduled_at' => $scheduledAt]
        );

        $_SESSION['flash_success'] = 'Inspection scheduled successfully.';
        redirect('zoning-officer/payment-scheduling.php');
    }

    $_SESSION['flash_error'] = 'Unknown payment action.';
    redirect('zoning-officer/payment-scheduling.php?id=' . $applicationId);
}

/*
|--------------------------------------------------------------------------
| Page data
|--------------------------------------------------------------------------
*/

$readyApplications    = officer_applications(['PAID']);
$application          = $applicationId ? officer_application($applicationId) : null;
$order                = $application ? payment_order_for_application((int)$application['id']) : null;
$paymentVerified      = $order ? payment_order_is_verified($order) : false;
$paymentLogs          = zo_payment_logs();
$scheduledInspections = zo_scheduled_inspections();

$pendingPaymentCount = count(array_filter($paymentLogs, function ($log) {
    return strtoupper((string)($log['payment_status'] ?? $log['status'] ?? 'PENDING')) !== 'PAID';
}));

$paidPaymentCount = count(array_filter($paymentLogs, function ($log) {
    return strtoupper((string)($log['payment_status'] ?? $log['status'] ?? '')) === 'PAID';
}));

$verifiedPaymentCount = count(array_filter($paymentLogs, function ($log) {
    return strtoupper((string)($log['payment_status'] ?? $log['status'] ?? '')) === 'PAID' && zo_payment_verified($log);
}));

$readyScheduleCount = count($readyApplications);
$scheduledCount     = count($scheduledInspections);

$calendarEvents = [];

foreach ($scheduledInspections as $inspection) {
    $scheduledAt = $inspection['scheduled_at'] ?? null;
    $timestamp   = $scheduledAt ? strtotime($scheduledAt) : false;

    if (!$timestamp) {
        continue;
    }

    $calendarEvents[] = [
        'id'       => (int)($inspection['id'] ?? 0),
        'date'     => date('Y-m-d', $timestamp),
        'time'     => date('H:i', $timestamp),
        'timeText' => date('h:i A', $timestamp),
        'label'    => trim(($inspection['registry_number'] ?? '') . ' - ' . ($inspection['property_title'] ?? '')),
        'property' => $inspection['property_title'] ?? '',
        'registry' => $inspection['registry_number'] ?? '',
        'address'  => $inspection['property_address'] ?? '',
    ];
}

require __DIR__ . '/../partials/header.php';
?>

<style>
    :root {
        --zo-navy: #0d3154;
        --zo-navy-dark: #08233d;
        --zo-blue: #0d6efd;
        --zo-blue-soft: #eaf3ff;
        --zo-gold: #f6c343;
        --zo-gold-soft: #fff5d6;
        --zo-green: #198754;
        --zo-green-soft: #eafaf1;
        --zo-orange: #b56a00;
        --zo-orange-soft: #fff4d6;
        --zo-red: #dc3545;
        --zo-red-soft: #fff0f0;
        --zo-text: #172033;
        --zo-muted: #6b7280;
        --zo-border: #d8e2ef;
        --zo-card: #ffffff;
    }

    body.zo-payment-page {
        background:
            radial-gradient(circle at 1px 1px, rgba(13, 110, 253, 0.16) 1px, transparent 0),
            linear-gradient(180deg, #f7fbff 0%, #edf3fa 100%);
        background-size: 28px 28px, 100% 100%;
        color: var(--zo-text);
    }

    .zo-shell {
        max-width: 1180px;
        margin: 0 auto;
        padding: 32px 18px 56px;
    }

    .zo-hero {
        position: relative;
        overflow: hidden;
        border-radius: 24px;
        padding: 30px;
        margin-bottom: 22px;
        background:
            radial-gradient(circle at top right, rgba(246, 195, 67, 0.28), transparent 35%),
            linear-gradient(135deg, #ffffff 0%, #f5f9ff 100%);
        border: 1px solid var(--zo-border);
        box-shadow: 0 18px 42px rgba(13, 49, 84, 0.10);
    }

    .zo-hero::before {
        content: "";
        position: absolute;
        top: -90px;
        right: -90px;
        width: 230px;
        height: 230px;
        border-radius: 50%;
        background: rgba(13, 110, 253, 0.08);
        pointer-events: none;
    }

    .zo-hero-content {
        position: relative;
        z-index: 1;
    }

    .zo-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        padding: 6px 12px;
        border-radius: 999px;
        background: var(--zo-blue-soft);
        color: var(--zo-blue);
        font-size: 0.72rem;
        font-weight: 900;
        letter-spacing: 0.09em;
        text-transform: uppercase;
    }

    .zo-eyebrow-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--zo-gold);
        box-shadow: 0 0 0 4px rgba(246, 195, 67, 0.22);
    }

    .zo-title {
        margin: 0;
        color: #071d35;
        font-size: clamp(1.75rem, 4vw, 2.55rem);
        font-weight: 900;
        line-height: 1.05;
        letter-spacing: -0.04em;
    }

    .zo-subtitle {
        max-width: 760px;
        margin: 12px 0 0;
        color: var(--zo-muted);
        font-size: 0.98rem;
        font-weight: 500;
        line-height: 1.7;
    }

    .zo-hero-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 22px;
    }

    .zo-btn-primary,
    .zo-btn-outline,
    .zo-btn-dark {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 40px;
        padding: 10px 16px;
        border-radius: 12px;
        font-size: 0.84rem;
        font-weight: 850;
        line-height: 1;
        text-decoration: none;
        border: 1px solid transparent;
        transition:
            transform 0.18s ease,
            box-shadow 0.18s ease,
            background 0.18s ease,
            border-color 0.18s ease;
    }

    .zo-btn-primary {
        background: var(--zo-blue);
        color: #ffffff;
        box-shadow: 0 10px 22px rgba(13, 110, 253, 0.24);
    }

    .zo-btn-primary:hover {
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(13, 110, 253, 0.30);
    }

    .zo-btn-outline {
        background: #ffffff;
        color: var(--zo-navy);
        border-color: var(--zo-border);
    }

    .zo-btn-outline:hover {
        color: var(--zo-navy);
        background: #f7fbff;
        border-color: #b9cce2;
        transform: translateY(-1px);
    }

    .zo-btn-dark {
        background: var(--zo-navy);
        color: #ffffff;
        box-shadow: 0 10px 20px rgba(13, 49, 84, 0.18);
    }

    .zo-btn-dark:hover {
        color: #ffffff;
        background: var(--zo-navy-dark);
        transform: translateY(-1px);
    }

    .zo-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .zo-stat-card {
        position: relative;
        overflow: hidden;
        min-height: 156px;
        padding: 22px;
        border-radius: 20px;
        background:
            radial-gradient(circle at top right, rgba(13, 110, 253, 0.10), transparent 36%),
            linear-gradient(145deg, #ffffff 0%, #f8fbff 100%);
        border: 1px solid var(--zo-border);
        box-shadow:
            0 16px 34px rgba(13, 49, 84, 0.10),
            inset 0 1px 0 rgba(255, 255, 255, 0.85);
    }

    .zo-stat-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, var(--zo-blue), rgba(13, 110, 253, 0.15));
    }

    .zo-stat-card--pending::before {
        background: linear-gradient(90deg, var(--zo-orange), rgba(181, 106, 0, 0.12));
    }

    .zo-stat-card--paid::before {
        background: linear-gradient(90deg, var(--zo-green), rgba(25, 135, 84, 0.12));
    }

    .zo-stat-card--scheduled::before {
        background: linear-gradient(90deg, var(--zo-blue), rgba(13, 110, 253, 0.12));
    }

    .zo-stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 17px;
        background: var(--zo-blue-soft);
        color: var(--zo-blue);
        box-shadow:
            inset 0 0 0 1px rgba(13, 110, 253, 0.10),
            0 10px 22px rgba(13, 49, 84, 0.08);
    }

    .zo-stat-card--pending .zo-stat-icon {
        background: var(--zo-orange-soft);
        color: var(--zo-orange);
    }

    .zo-stat-card--paid .zo-stat-icon {
        background: var(--zo-green-soft);
        color: var(--zo-green);
    }

    .zo-stat-card--scheduled .zo-stat-icon {
        background: var(--zo-blue-soft);
        color: var(--zo-blue);
    }

    .zo-stat-icon svg {
        width: 23px;
        height: 23px;
        stroke: currentColor;
    }

    .zo-stat-label {
        margin: 0 0 9px;
        color: #071d35;
        font-size: 0.78rem;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .zo-stat-value {
        margin: 0;
        color: #071d35;
        font-size: 2.3rem;
        font-weight: 950;
        line-height: 1;
        letter-spacing: -0.06em;
    }

    .zo-stat-sub {
        margin: 9px 0 0;
        color: var(--zo-muted);
        font-size: 0.82rem;
        font-weight: 650;
        line-height: 1.45;
    }

    .zo-layout {
        display: grid;
        grid-template-columns: 360px minmax(0, 1fr);
        gap: 24px;
        align-items: start;
    }

    .zo-stack {
        display: grid;
        gap: 18px;
    }

    .zo-panel {
        overflow: hidden;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid var(--zo-border);
        box-shadow: 0 18px 42px rgba(13, 49, 84, 0.10);
    }

    .zo-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        padding: 20px 22px;
        border-bottom: 1px solid var(--zo-border);
        background:
            radial-gradient(circle at top right, rgba(13, 110, 253, 0.08), transparent 30%),
            #ffffff;
    }

    .zo-panel-title {
        margin: 0;
        color: #071d35;
        font-size: 1rem;
        font-weight: 900;
        letter-spacing: -0.02em;
    }

    .zo-panel-sub {
        margin: 4px 0 0;
        color: var(--zo-muted);
        font-size: 0.8rem;
        line-height: 1.5;
        font-weight: 500;
    }

    .zo-panel-body {
        padding: 20px 22px;
    }

    .zo-list {
        display: grid;
        gap: 10px;
    }

    .zo-app-link {
        display: block;
        padding: 14px;
        border-radius: 14px;
        border: 1px solid #edf2f8;
        background: #ffffff;
        color: inherit;
        text-decoration: none;
        transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .zo-app-link:hover,
    .zo-app-link.is-active {
        color: inherit;
        transform: translateY(-1px);
        border-color: #b7cce5;
        box-shadow: 0 12px 24px rgba(13, 49, 84, 0.10);
    }

    .zo-app-link.is-active {
        background: linear-gradient(135deg, #eaf3ff 0%, #ffffff 100%);
    }

    .zo-app-registry {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #071d35;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        font-size: 0.78rem;
        font-weight: 900;
        margin-bottom: 5px;
    }

    .zo-app-registry::before {
        content: "";
        width: 8px;
        height: 8px;
        flex: 0 0 8px;
        border-radius: 50%;
        background: var(--zo-blue);
        box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.13);
    }

    .zo-app-title {
        margin: 0;
        color: var(--zo-text);
        font-size: 0.88rem;
        font-weight: 800;
        line-height: 1.35;
    }

    .zo-app-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 9px;
    }

    .zo-mini-badge,
    .zo-status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 9px;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 900;
        line-height: 1;
        white-space: nowrap;
    }

    .zo-mini-badge {
        background: var(--zo-blue-soft);
        color: var(--zo-blue);
    }

    .zo-status-badge--pending {
        background: var(--zo-orange-soft);
        color: var(--zo-orange);
    }

    .zo-status-badge--paid {
        background: var(--zo-green-soft);
        color: var(--zo-green);
    }

    .zo-status-badge--verified {
        background: #e9f8ee;
        color: #116d3b;
    }

    .zo-status-badge--scheduled {
        background: var(--zo-blue-soft);
        color: var(--zo-blue);
    }

    .zo-empty {
        padding: 28px 16px;
        text-align: center;
        color: var(--zo-muted);
        font-size: 0.86rem;
        line-height: 1.6;
    }

    .zo-payment-card {
        padding: 22px;
    }

    .zo-receipt-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 18px;
    }

    .zo-receipt-title {
        margin: 0;
        color: #071d35;
        font-size: 1.12rem;
        font-weight: 900;
        letter-spacing: -0.02em;
    }

    .zo-receipt-sub {
        margin: 5px 0 0;
        color: var(--zo-muted);
        font-size: 0.83rem;
        font-weight: 550;
    }

    .zo-details-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .zo-detail {
        padding: 14px;
        border-radius: 14px;
        background: #f8fbff;
        border: 1px solid #edf2f8;
    }

    .zo-detail-label {
        margin: 0 0 5px;
        color: var(--zo-muted);
        font-size: 0.68rem;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .zo-detail-value {
        margin: 0;
        color: #071d35;
        font-size: 0.9rem;
        font-weight: 850;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }

    .zo-form-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 18px;
    }

    .zo-schedule-card {
        padding: 22px;
    }

    .zo-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .zo-form-label {
        display: block;
        margin-bottom: 7px;
        color: #071d35;
        font-size: 0.78rem;
        font-weight: 900;
    }

    .zo-form-control {
        width: 100%;
        min-height: 42px;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid var(--zo-border);
        background: #ffffff;
        color: var(--zo-text);
        font-size: 0.88rem;
        font-weight: 650;
        outline: none;
        transition: border-color 0.16s ease, box-shadow 0.16s ease;
    }

    .zo-form-control:focus {
        border-color: var(--zo-blue);
        box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.12);
    }

    .zo-form-control:disabled {
        background: #f3f4f6;
        color: #9ca3af;
        cursor: not-allowed;
    }

    .zo-warning {
        display: none;
        margin-top: 14px;
        padding: 12px 14px;
        border-radius: 12px;
        background: var(--zo-red-soft);
        color: #9f1239;
        border: 1px solid #fecdd3;
        font-size: 0.82rem;
        font-weight: 750;
        line-height: 1.45;
    }

    .zo-warning.is-visible {
        display: block;
    }

    .zo-note {
        margin: 12px 0 0;
        color: var(--zo-muted);
        font-size: 0.82rem;
        line-height: 1.55;
    }

    .zo-tabs {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }

    .zo-tab {
        border: 1px solid var(--zo-border);
        background: #ffffff;
        color: var(--zo-muted);
        border-radius: 999px;
        padding: 8px 12px;
        font-size: 0.76rem;
        font-weight: 900;
        cursor: pointer;
        transition: background 0.16s ease, color 0.16s ease, border-color 0.16s ease;
    }

    .zo-tab.is-active {
        background: var(--zo-blue);
        color: #ffffff;
        border-color: var(--zo-blue);
    }

    .zo-search-wrap {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        margin-bottom: 14px;
        border-radius: 12px;
        border: 1px solid var(--zo-border);
        background: #f8fbff;
    }

    .zo-search-wrap svg {
        width: 16px;
        height: 16px;
        color: var(--zo-muted);
        stroke: currentColor;
        flex: 0 0 auto;
    }

    .zo-search-wrap input {
        width: 100%;
        border: 0;
        outline: none;
        background: transparent;
        color: var(--zo-text);
        font-size: 0.84rem;
        font-weight: 600;
    }

    .zo-table-wrap {
        overflow-x: auto;
    }

    .zo-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 10px;
        margin: 0;
        min-width: 780px;
    }

    .zo-table thead th {
        padding: 10px 12px 6px;
        color: #071d35;
        font-size: 0.7rem;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        border: 0;
    }

    .zo-table tbody tr {
        background: #ffffff;
        box-shadow: 0 8px 22px rgba(13, 49, 84, 0.07);
    }

    .zo-table tbody td {
        padding: 14px 12px;
        vertical-align: middle;
        border-top: 1px solid #edf2f8;
        border-bottom: 1px solid #edf2f8;
        color: var(--zo-text);
        font-size: 0.82rem;
        font-weight: 600;
    }

    .zo-table tbody td:first-child {
        border-left: 1px solid #edf2f8;
        border-radius: 14px 0 0 14px;
    }

    .zo-table tbody td:last-child {
        border-right: 1px solid #edf2f8;
        border-radius: 0 14px 14px 0;
    }

    .zo-registry {
        color: #071d35;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        font-size: 0.78rem;
        font-weight: 900;
    }

    .zo-calendar {
        padding: 22px;
    }

    .zo-calendar-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 16px;
    }

    .zo-calendar-title {
        margin: 0;
        color: #071d35;
        font-size: 1rem;
        font-weight: 900;
    }

    .zo-calendar-nav {
        display: flex;
        gap: 8px;
    }

    .zo-calendar-btn {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        border: 1px solid var(--zo-border);
        background: #ffffff;
        color: var(--zo-navy);
        font-weight: 900;
        cursor: pointer;
    }

    .zo-calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        gap: 8px;
    }

    .zo-calendar-weekday {
        text-align: center;
        color: var(--zo-muted);
        font-size: 0.68rem;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        padding-bottom: 4px;
    }

    .zo-calendar-day {
        min-height: 82px;
        padding: 8px;
        border-radius: 14px;
        border: 1px solid #edf2f8;
        background: #ffffff;
    }

    .zo-calendar-day.is-muted {
        opacity: 0.38;
    }

    .zo-calendar-day.is-today {
        border-color: var(--zo-blue);
        box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.08);
    }

    .zo-calendar-number {
        display: flex;
        align-items: center;
        justify-content: space-between;
        color: #071d35;
        font-size: 0.78rem;
        font-weight: 900;
        margin-bottom: 6px;
    }

    .zo-calendar-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        border-radius: 999px;
        background: var(--zo-blue);
        color: #fff;
        font-size: 0.65rem;
        font-weight: 900;
    }

    .zo-calendar-event {
        display: block;
        padding: 5px 6px;
        margin-top: 5px;
        border-radius: 8px;
        background: var(--zo-blue-soft);
        color: var(--zo-blue);
        font-size: 0.66rem;
        font-weight: 850;
        line-height: 1.25;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .zo-schedule-list {
        display: grid;
        gap: 10px;
        margin-top: 18px;
    }

    .zo-schedule-item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 12px;
        padding: 13px;
        border-radius: 14px;
        border: 1px solid #edf2f8;
        background: #f8fbff;
    }

    .zo-schedule-date {
        min-width: 78px;
        padding: 9px 10px;
        border-radius: 12px;
        background: #ffffff;
        color: var(--zo-blue);
        text-align: center;
        font-size: 0.72rem;
        font-weight: 900;
        line-height: 1.35;
    }

    .zo-schedule-title {
        margin: 0 0 3px;
        color: #071d35;
        font-size: 0.85rem;
        font-weight: 900;
        line-height: 1.35;
    }

    .zo-schedule-meta {
        margin: 0;
        color: var(--zo-muted);
        font-size: 0.78rem;
        line-height: 1.45;
        font-weight: 600;
    }

    @media (max-width: 1100px) {
        .zo-stat-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .zo-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 767.98px) {
        .zo-shell {
            padding: 24px 14px 44px;
        }

        .zo-hero {
            padding: 22px;
        }

        .zo-stat-grid {
            grid-template-columns: 1fr;
        }

        .zo-details-grid,
        .zo-form-grid {
            grid-template-columns: 1fr;
        }

        .zo-calendar-grid {
            gap: 5px;
        }

        .zo-calendar-day {
            min-height: 72px;
            padding: 6px;
        }

        .zo-calendar-event {
            display: none;
        }
    }
</style>

<script>
    document.body.classList.add('zo-payment-page');
</script>

<div class="zo-shell">

    <!-- Header -->
    <section class="zo-hero">
        <div class="zo-hero-content">
            <div class="zo-eyebrow">
                <span class="zo-eyebrow-dot"></span>
                Zoning Officer Workspace
            </div>

            <h1 class="zo-title">Payment Verification and Inspection Scheduling</h1>

            <p class="zo-subtitle">
                Verify official payment receipts, monitor paid and pending payment records,
                and schedule site inspections using the calendar to prevent overlapping inspection slots.
            </p>

            <div class="zo-hero-actions">
                <a href="#payment-panel" class="zo-btn-primary">Review Payment</a>
                <a href="#payment-logs" class="zo-btn-outline">View Payment Logs</a>
                <a href="#inspection-calendar" class="zo-btn-outline">Open Calendar</a>
            </div>
        </div>
    </section>

    <!-- Stats -->
    <section class="zo-stat-grid">
        <article class="zo-stat-card zo-stat-card--pending">
            <div class="zo-stat-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M12 6v6l4 2"></path>
                </svg>
            </div>

            <p class="zo-stat-label">Pending Payments</p>
            <p class="zo-stat-value"><?= number_format($pendingPaymentCount) ?></p>
            <p class="zo-stat-sub">Applications still waiting for payment completion.</p>
        </article>

        <article class="zo-stat-card zo-stat-card--paid">
            <div class="zo-stat-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 6 9 17l-5-5"></path>
                </svg>
            </div>

            <p class="zo-stat-label">Paid Receipts</p>
            <p class="zo-stat-value"><?= number_format($paidPaymentCount) ?></p>
            <p class="zo-stat-sub">Applications with recorded paid payment orders.</p>
        </article>

        <article class="zo-stat-card">
            <div class="zo-stat-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 12l2 2 4-4"></path>
                    <path d="M21 12a9 9 0 1 1-9-9"></path>
                </svg>
            </div>

            <p class="zo-stat-label">Verified</p>
            <p class="zo-stat-value"><?= number_format($verifiedPaymentCount) ?></p>
            <p class="zo-stat-sub">Paid receipts already verified by the office.</p>
        </article>

        <article class="zo-stat-card zo-stat-card--scheduled">
            <div class="zo-stat-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 2v4"></path>
                    <path d="M16 2v4"></path>
                    <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                    <path d="M3 10h18"></path>
                </svg>
            </div>

            <p class="zo-stat-label">Scheduled</p>
            <p class="zo-stat-value"><?= number_format($scheduledCount) ?></p>
            <p class="zo-stat-sub">Site inspections currently visible on the calendar.</p>
        </article>
    </section>

    <div class="zo-layout">

        <!-- Left column -->
        <aside class="zo-stack">

            <!-- Ready applications -->
            <section class="zo-panel">
                <div class="zo-panel-header">
                    <div>
                        <h2 class="zo-panel-title">Ready for Scheduling</h2>
                        <p class="zo-panel-sub">
                            Paid applications that can be verified and scheduled.
                        </p>
                    </div>

                    <span class="zo-mini-badge"><?= number_format($readyScheduleCount) ?></span>
                </div>

                <div class="zo-panel-body">
                    <?php if ($readyApplications): ?>
                        <div class="zo-list">
                            <?php foreach ($readyApplications as $row): ?>
                                <a
                                    class="zo-app-link <?= $application && (int)$application['id'] === (int)$row['id'] ? 'is-active' : '' ?>"
                                    href="payment-scheduling.php?id=<?= (int)$row['id'] ?>#payment-panel"
                                >
                                    <div class="zo-app-registry">
                                        <?= e($row['registry_number']) ?>
                                    </div>

                                    <p class="zo-app-title">
                                        <?= e($row['property_title']) ?>
                                    </p>

                                    <div class="zo-app-meta">
                                        <span class="zo-status-badge zo-status-badge--paid">Paid</span>
                                        <span class="zo-mini-badge">Open receipt</span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="zo-empty">
                            No paid applications are currently ready for inspection scheduling.
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Calendar -->
            <section class="zo-panel" id="inspection-calendar">
                <div class="zo-panel-header">
                    <div>
                        <h2 class="zo-panel-title">Inspection Calendar</h2>
                        <p class="zo-panel-sub">
                            Existing site inspections by date and time.
                        </p>
                    </div>
                </div>

                <div class="zo-calendar">
                    <div class="zo-calendar-head">
                        <h3 class="zo-calendar-title" id="calendarTitle">Calendar</h3>

                        <div class="zo-calendar-nav">
                            <button type="button" class="zo-calendar-btn" id="calendarPrev" aria-label="Previous month">
                                &lsaquo;
                            </button>

                            <button type="button" class="zo-calendar-btn" id="calendarNext" aria-label="Next month">
                                &rsaquo;
                            </button>
                        </div>
                    </div>

                    <div class="zo-calendar-grid" id="calendarGrid"></div>

                    <?php if ($calendarEvents): ?>
                        <div class="zo-schedule-list">
                            <?php foreach (array_slice($calendarEvents, 0, 5) as $event): ?>
                                <div class="zo-schedule-item">
                                    <div class="zo-schedule-date">
                                        <?= e(date('M d', strtotime($event['date']))) ?><br>
                                        <?= e($event['timeText']) ?>
                                    </div>

                                    <div>
                                        <p class="zo-schedule-title">
                                            <?= e($event['registry']) ?>
                                        </p>

                                        <p class="zo-schedule-meta">
                                            <?= e($event['property']) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="zo-note">
                            No scheduled inspections are currently visible. Once inspections are scheduled,
                            they will appear on this calendar.
                        </p>
                    <?php endif; ?>
                </div>
            </section>

        </aside>

        <!-- Right column -->
        <main class="zo-stack">

            <?php if ($application && $order): ?>

                <!-- Payment receipt -->
                <section class="zo-panel" id="payment-panel" data-sensitive>
                    <div class="zo-payment-card">
                        <div class="zo-receipt-head">
                            <div>
                                <h2 class="zo-receipt-title">Payment Receipt</h2>

                                <p class="zo-receipt-sub">
                                    <?= e($application['registry_number']) ?> · <?= e($application['property_title']) ?>
                                </p>
                            </div>

                            <?php if ($paymentVerified): ?>
                                <span class="zo-status-badge zo-status-badge--verified">Verified</span>
                            <?php else: ?>
                                <span class="zo-status-badge zo-status-badge--pending">Needs Verification</span>
                            <?php endif; ?>
                        </div>

                        <div class="zo-details-grid">
                            <div class="zo-detail">
                                <p class="zo-detail-label">Account Name</p>
                                <p class="zo-detail-value">
                                    <?= e($order['account_name'] ?? $application['account_name']) ?>
                                </p>
                            </div>

                            <div class="zo-detail">
                                <p class="zo-detail-label">Paying For</p>
                                <p class="zo-detail-value">
                                    <?= e($order['payment_for'] ?? default_payment_for($application)) ?>
                                </p>
                            </div>

                            <div class="zo-detail">
                                <p class="zo-detail-label">Payment Date</p>
                                <p class="zo-detail-value">
                                    <?= e(zo_format_datetime($order['paid_at'] ?? null)) ?>
                                </p>
                            </div>

                            <div class="zo-detail">
                                <p class="zo-detail-label">OP Number</p>
                                <p class="zo-detail-value">
                                    <?= e($order['op_number'] ?? 'Not provided') ?>
                                </p>
                            </div>

                            <div class="zo-detail">
                                <p class="zo-detail-label">Receipt Number</p>
                                <p class="zo-detail-value">
                                    <?= e($order['receipt_number'] ?? 'Pending receipt') ?>
                                </p>
                            </div>

                            <div class="zo-detail">
                                <p class="zo-detail-label">Fee / Amount</p>
                                <p class="zo-detail-value">
                                    <?= currency_php((float)($order['service_fee'] ?? 0)) ?>
                                </p>
                            </div>

                            <div class="zo-detail">
                                <p class="zo-detail-label">Payment Method</p>
                                <p class="zo-detail-value">
                                    <?= e($order['payment_method'] ?? 'PayMongo') ?>
                                </p>
                            </div>

                            <div class="zo-detail">
                                <p class="zo-detail-label">Payment Status</p>
                                <p class="zo-detail-value">
                                    <?= e(strtoupper((string)($order['status'] ?? 'PENDING'))) ?>
                                </p>
                            </div>
                        </div>

                        <form class="zo-form-actions" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                            <input type="hidden" name="action" value="verify_payment">

                            <button class="zo-btn-primary" <?= $paymentVerified ? 'disabled' : '' ?>>
                                <?= $paymentVerified ? 'Payment Verified' : 'Verify Payment' ?>
                            </button>

                            <?php if ($paymentVerified): ?>
                                <span class="zo-note mb-0">
                                    This payment is ready for inspection scheduling.
                                </span>
                            <?php else: ?>
                                <span class="zo-note mb-0">
                                    Verify the payment before selecting an inspection schedule.
                                </span>
                            <?php endif; ?>
                        </form>
                    </div>
                </section>

                <!-- Schedule inspection -->
                <section class="zo-panel" id="schedule-panel">
                    <div class="zo-schedule-card">
                        <div class="zo-receipt-head">
                            <div>
                                <h2 class="zo-receipt-title">Inspection Schedule</h2>

                                <p class="zo-receipt-sub">
                                    Choose an available date and time. Existing schedules are checked before submission.
                                </p>
                            </div>
                        </div>

                        <form method="post" id="scheduleForm">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                            <input type="hidden" name="action" value="schedule_inspection">

                            <div class="zo-form-grid">
                                <div>
                                    <label class="zo-form-label" for="inspectionDate">Inspection Date</label>
                                    <input
                                        class="zo-form-control"
                                        id="inspectionDate"
                                        type="date"
                                        name="inspection_date"
                                        required
                                        min="<?= e(date('Y-m-d')) ?>"
                                        <?= $paymentVerified ? '' : 'disabled' ?>
                                    >
                                </div>

                                <div>
                                    <label class="zo-form-label" for="inspectionTime">Inspection Time</label>
                                    <input
                                        class="zo-form-control"
                                        id="inspectionTime"
                                        type="time"
                                        name="inspection_time"
                                        required
                                        step="900"
                                        <?= $paymentVerified ? '' : 'disabled' ?>
                                    >
                                </div>
                            </div>

                            <div class="zo-warning" id="slotWarning">
                                This date and time already has a scheduled site inspection. Please choose another slot.
                            </div>

                            <div class="zo-form-actions">
                                <button class="zo-btn-primary" id="scheduleSubmitBtn" <?= $paymentVerified ? '' : 'disabled' ?>>
                                    Schedule Inspection
                                </button>

                                <?php if (!$paymentVerified): ?>
                                    <span class="zo-note mb-0">
                                        Payment verification is required before scheduling.
                                    </span>
                                <?php else: ?>
                                    <span class="zo-note mb-0">
                                        The calendar will warn you if the selected slot is already taken.
                                    </span>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </section>

            <?php else: ?>

                <section class="zo-panel">
                    <div class="zo-empty">
                        <strong>Select a paid application</strong><br>
                        Choose an item from the ready-for-scheduling list to view the payment receipt and schedule inspection.
                    </div>
                </section>

            <?php endif; ?>

            <!-- Payment logs -->
            <section class="zo-panel" id="payment-logs">
                <div class="zo-panel-header">
                    <div>
                        <h2 class="zo-panel-title">Payment Logs</h2>
                        <p class="zo-panel-sub">
                            Paid and pending payment records across applications.
                        </p>
                    </div>
                </div>

                <div class="zo-panel-body">
                    <div class="zo-tabs" aria-label="Payment log filters">
                        <button type="button" class="zo-tab is-active" data-payment-filter="all">
                            All
                        </button>

                        <button type="button" class="zo-tab" data-payment-filter="paid">
                            Paid
                        </button>

                        <button type="button" class="zo-tab" data-payment-filter="pending">
                            Pending
                        </button>

                        <button type="button" class="zo-tab" data-payment-filter="verified">
                            Verified
                        </button>
                    </div>

                    <div class="zo-search-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>

                        <input
                            type="search"
                            id="paymentLogSearch"
                            placeholder="Search registry, property, receipt, OP number..."
                            aria-label="Search payment logs"
                        >
                    </div>

                    <?php if ($paymentLogs): ?>
                        <div class="zo-table-wrap">
                            <table class="zo-table" id="paymentLogTable">
                                <thead>
                                    <tr>
                                        <th>Registry</th>
                                        <th>Property</th>
                                        <th>Amount</th>
                                        <th>Payment Date</th>
                                        <th>OP / Receipt</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($paymentLogs as $log): ?>
                                        <?php
                                            $logStatusRaw = strtoupper((string)($log['payment_status'] ?? $log['status'] ?? 'PENDING'));
                                            $logPaid      = $logStatusRaw === 'PAID';
                                            $logVerified  = $logPaid && zo_payment_verified($log);
                                            $logAppId     = (int)($log['application_id'] ?? 0);

                                            if ($logVerified) {
                                                $logFilter = 'verified paid';
                                                $badgeClass = 'zo-status-badge--verified';
                                                $badgeText = 'Verified';
                                            } elseif ($logPaid) {
                                                $logFilter = 'paid';
                                                $badgeClass = 'zo-status-badge--paid';
                                                $badgeText = 'Paid';
                                            } else {
                                                $logFilter = 'pending';
                                                $badgeClass = 'zo-status-badge--pending';
                                                $badgeText = 'Pending';
                                            }
                                        ?>

                                        <tr data-payment-row data-payment-status="<?= e($logFilter) ?>">
                                            <td>
                                                <span class="zo-registry">
                                                    <?= e($log['registry_number'] ?? 'N/A') ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?= e($log['property_title'] ?? 'Untitled Property') ?>
                                            </td>

                                            <td>
                                                <?= currency_php((float)($log['service_fee'] ?? 0)) ?>
                                            </td>

                                            <td>
                                                <?= e(zo_format_datetime($log['paid_at'] ?? null)) ?>
                                            </td>

                                            <td>
                                                <strong>OP:</strong> <?= e($log['op_number'] ?? 'N/A') ?><br>
                                                <strong>Receipt:</strong> <?= e($log['receipt_number'] ?? 'Pending') ?>
                                            </td>

                                            <td>
                                                <span class="zo-status-badge <?= e($badgeClass) ?>">
                                                    <?= e($badgeText) ?>
                                                </span>
                                            </td>

                                            <td class="text-end">
                                                <?php if ($logPaid && $logAppId > 0): ?>
                                                    <a class="zo-btn-outline" href="payment-scheduling.php?id=<?= $logAppId ?>#payment-panel">
                                                        Open
                                                    </a>
                                                <?php else: ?>
                                                    <span class="zo-note">Waiting</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="zo-empty">
                            No payment logs found yet.
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        </main>

    </div>
</div>

<script>
    (function () {
        var inspectionEvents = <?= json_encode($calendarEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        /*
         * Calendar
         */
        var calendarGrid  = document.getElementById('calendarGrid');
        var calendarTitle = document.getElementById('calendarTitle');
        var prevBtn       = document.getElementById('calendarPrev');
        var nextBtn       = document.getElementById('calendarNext');

        var currentDate = new Date();
        currentDate.setDate(1);

        function pad(num) {
            return String(num).padStart(2, '0');
        }

        function toDateKey(date) {
            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
        }

        function eventsForDate(dateKey) {
            return inspectionEvents.filter(function (event) {
                return event.date === dateKey;
            });
        }

        function renderCalendar() {
            if (!calendarGrid || !calendarTitle) {
                return;
            }

            calendarGrid.innerHTML = '';

            var year = currentDate.getFullYear();
            var month = currentDate.getMonth();

            calendarTitle.textContent = currentDate.toLocaleString('default', {
                month: 'long',
                year: 'numeric'
            });

            ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (day) {
                var el = document.createElement('div');
                el.className = 'zo-calendar-weekday';
                el.textContent = day;
                calendarGrid.appendChild(el);
            });

            var firstDay = new Date(year, month, 1);
            var startDate = new Date(firstDay);
            startDate.setDate(firstDay.getDate() - firstDay.getDay());

            var todayKey = toDateKey(new Date());

            for (var i = 0; i < 42; i++) {
                var dayDate = new Date(startDate);
                dayDate.setDate(startDate.getDate() + i);

                var dateKey = toDateKey(dayDate);
                var dayEvents = eventsForDate(dateKey);

                var dayEl = document.createElement('div');
                dayEl.className = 'zo-calendar-day';

                if (dayDate.getMonth() !== month) {
                    dayEl.classList.add('is-muted');
                }

                if (dateKey === todayKey) {
                    dayEl.classList.add('is-today');
                }

                var numberEl = document.createElement('div');
                numberEl.className = 'zo-calendar-number';

                var numberSpan = document.createElement('span');
                numberSpan.textContent = dayDate.getDate();

                numberEl.appendChild(numberSpan);

                if (dayEvents.length) {
                    var countSpan = document.createElement('span');
                    countSpan.className = 'zo-calendar-count';
                    countSpan.textContent = dayEvents.length;
                    numberEl.appendChild(countSpan);
                }

                dayEl.appendChild(numberEl);

                dayEvents.slice(0, 2).forEach(function (event) {
                    var eventEl = document.createElement('span');
                    eventEl.className = 'zo-calendar-event';
                    eventEl.title = event.timeText + ' · ' + event.label;
                    eventEl.textContent = event.timeText + ' · ' + event.registry;
                    dayEl.appendChild(eventEl);
                });

                calendarGrid.appendChild(dayEl);
            }
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                currentDate.setMonth(currentDate.getMonth() - 1);
                renderCalendar();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                currentDate.setMonth(currentDate.getMonth() + 1);
                renderCalendar();
            });
        }

        renderCalendar();

        /*
         * Slot conflict warning
         */
        var dateInput = document.getElementById('inspectionDate');
        var timeInput = document.getElementById('inspectionTime');
        var warning   = document.getElementById('slotWarning');
        var submitBtn = document.getElementById('scheduleSubmitBtn');

        function checkSlot() {
            if (!dateInput || !timeInput || !warning || !submitBtn) {
                return;
            }

            var selectedDate = dateInput.value;
            var selectedTime = timeInput.value;

            if (!selectedDate || !selectedTime) {
                warning.classList.remove('is-visible');
                submitBtn.disabled = submitBtn.hasAttribute('data-original-disabled');
                return;
            }

            var conflict = inspectionEvents.some(function (event) {
                return event.date === selectedDate && event.time === selectedTime;
            });

            if (conflict) {
                warning.classList.add('is-visible');
                submitBtn.disabled = true;
            } else {
                warning.classList.remove('is-visible');

                if (!submitBtn.hasAttribute('data-original-disabled')) {
                    submitBtn.disabled = false;
                }
            }
        }

        if (submitBtn && submitBtn.disabled) {
            submitBtn.setAttribute('data-original-disabled', '1');
        }

        if (dateInput) {
            dateInput.addEventListener('change', checkSlot);
        }

        if (timeInput) {
            timeInput.addEventListener('change', checkSlot);
        }

        /*
         * Payment log filters and search
         */
        var searchInput = document.getElementById('paymentLogSearch');
        var tabButtons  = document.querySelectorAll('[data-payment-filter]');
        var rows        = document.querySelectorAll('[data-payment-row]');
        var activeFilter = 'all';

        function applyPaymentFilters() {
            var query = searchInput ? searchInput.value.toLowerCase().trim() : '';

            rows.forEach(function (row) {
                var rowStatus = row.dataset.paymentStatus || '';
                var rowText = row.textContent.toLowerCase();

                var matchesFilter =
                    activeFilter === 'all' ||
                    rowStatus.indexOf(activeFilter) !== -1;

                var matchesSearch = !query || rowText.indexOf(query) !== -1;

                row.style.display = matchesFilter && matchesSearch ? '' : 'none';
            });
        }

        tabButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                tabButtons.forEach(function (btn) {
                    btn.classList.remove('is-active');
                });

                button.classList.add('is-active');
                activeFilter = button.dataset.paymentFilter || 'all';
                applyPaymentFilters();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', applyPaymentFilters);
        }
    }());
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>