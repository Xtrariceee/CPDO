<?php
/**
 * RentEase Messaging — Quick-Reply Template Library
 * Templates are grouped by category for the UI picker.
 * Each template has: id, category, label, icon, body (supports {{placeholders}}).
 */

declare(strict_types=1);

function get_message_templates(): array
{
    return [
        // ── Lease & Agreement ──────────────────────────────────────────
        [
            'id'       => 'lease_end_date',
            'category' => 'Lease',
            'label'    => 'Lease End Date',
            'icon'     => '📅',
            'body'     => "Hi {{tenant_name}},\n\nYour current lease is set to expire on {{lease_end_date}}. If you wish to renew, please let us know at least 30 days before the end date so we can prepare the renewal paperwork.\n\nFeel free to reply here if you have any questions.\n\nBest regards,\n{{landlord_name}}",
        ],
        [
            'id'       => 'lease_renewal_offer',
            'category' => 'Lease',
            'label'    => 'Lease Renewal Offer',
            'icon'     => '🔄',
            'body'     => "Hi {{tenant_name}},\n\nWe'd love to have you continue as a resident at {{property_name}}. We're pleased to offer a lease renewal at ₱{{monthly_rent}}/month for another 12 months starting {{new_start_date}}.\n\nPlease confirm your interest by replying to this message.\n\nWarm regards,\n{{landlord_name}}",
        ],
        [
            'id'       => 'lease_signing_reminder',
            'category' => 'Lease',
            'label'    => 'Signing Reminder',
            'icon'     => '✍️',
            'body'     => "Hi {{tenant_name}},\n\nThis is a friendly reminder that your lease signing is scheduled for {{signing_date}} at {{signing_location}}.\n\nPlease bring a valid government-issued ID and two (2) copies of the signed agreement.\n\nSee you then!\n{{landlord_name}}",
        ],

        // ── Payment & Billing ──────────────────────────────────────────
        [
            'id'       => 'rent_reminder',
            'category' => 'Payment',
            'label'    => 'Rent Due Reminder',
            'icon'     => '💵',
            'body'     => "Hi {{tenant_name}},\n\nThis is a friendly reminder that your monthly rent of ₱{{monthly_rent}} for {{property_name}} is due on {{due_date}}.\n\nPayment can be made through the Payment Hub in your tenant portal. If you have already paid, please disregard this message.\n\nThank you!\n{{landlord_name}}",
        ],
        [
            'id'       => 'payment_overdue',
            'category' => 'Payment',
            'label'    => 'Overdue Payment Notice',
            'icon'     => '⚠️',
            'body'     => "Hi {{tenant_name}},\n\nOur records show that your rent payment for {{month_year}} amounting to ₱{{monthly_rent}} is now {{days_overdue}} days overdue.\n\nPlease settle your balance as soon as possible to avoid late fees. If you are experiencing difficulties, please reach out so we can discuss a payment arrangement.\n\nThank you,\n{{landlord_name}}",
        ],
        [
            'id'       => 'payment_received',
            'category' => 'Payment',
            'label'    => 'Payment Confirmation',
            'icon'     => '✅',
            'body'     => "Hi {{tenant_name}},\n\nWe have received your payment of ₱{{amount_paid}} for {{month_year}}. Your account is now up to date.\n\nThank you for paying on time!\n\nBest,\n{{landlord_name}}",
        ],

        // ── Maintenance ────────────────────────────────────────────────
        [
            'id'       => 'maintenance_acknowledged',
            'category' => 'Maintenance',
            'label'    => 'Maintenance Acknowledged',
            'icon'     => '🔧',
            'body'     => "Hi {{tenant_name}},\n\nThank you for reporting the issue. We have received your maintenance request and will schedule a technician to visit within 24–48 hours.\n\nPlease ensure someone is available at the unit during the visit. We will confirm the schedule shortly.\n\nRegards,\n{{landlord_name}}",
        ],
        [
            'id'       => 'maintenance_scheduled',
            'category' => 'Maintenance',
            'label'    => 'Maintenance Schedule',
            'icon'     => '🛠️',
            'body'     => "Hi {{tenant_name}},\n\nThe maintenance visit for your reported issue has been scheduled for {{maintenance_date}} between {{maintenance_time_range}}.\n\nOur technician will attend to the following:\n• {{issue_description}}\n\nPlease make sure access is available at the specified time.\n\nThank you,\n{{landlord_name}}",
        ],
        [
            'id'       => 'maintenance_completed',
            'category' => 'Maintenance',
            'label'    => 'Maintenance Completed',
            'icon'     => '✔️',
            'body'     => "Hi {{tenant_name}},\n\nWe're happy to inform you that the maintenance work at your unit has been completed. Please check and confirm that the issue has been fully resolved.\n\nIf you notice anything still needs attention, please reply to this message.\n\nThank you for your patience!\n{{landlord_name}}",
        ],

        // ── Notices & Announcements ────────────────────────────────────
        [
            'id'       => 'water_shutoff',
            'category' => 'Notice',
            'label'    => 'Water Shut-off Notice',
            'icon'     => '🚰',
            'body'     => "⚠️ IMPORTANT NOTICE\n\nDear Resident,\n\nPlease be advised that water service will be temporarily interrupted on {{shutoff_date}} from {{start_time}} to {{end_time}} due to scheduled maintenance/repair work.\n\nWe recommend storing sufficient water in advance. We apologize for any inconvenience this may cause.\n\n— Property Management\n{{landlord_name}}",
        ],
        [
            'id'       => 'inspection_notice',
            'category' => 'Notice',
            'label'    => 'Unit Inspection Notice',
            'icon'     => '🔍',
            'body'     => "Hi {{tenant_name}},\n\nThis is to inform you that we will be conducting a routine unit inspection on {{inspection_date}} at {{inspection_time}}.\n\nThe inspection covers general unit condition, fixtures, and appliances. This will take approximately 30–45 minutes. Your presence is welcome but not required.\n\nThank you,\n{{landlord_name}}",
        ],
        [
            'id'       => 'move_out_reminder',
            'category' => 'Notice',
            'label'    => 'Move-Out Reminder',
            'icon'     => '📦',
            'body'     => "Hi {{tenant_name}},\n\nAs your lease ends on {{lease_end_date}}, we'd like to remind you to:\n\n1. Clear all personal belongings by the move-out date\n2. Return all keys and access cards\n3. Ensure the unit is clean and in good condition\n4. Provide a forwarding address for the security deposit refund\n\nPlease let us know if you need assistance with move-out coordination.\n\nBest regards,\n{{landlord_name}}",
        ],
    ];
}

/**
 * Return templates grouped by category for the UI accordion/picker.
 */
function get_message_templates_grouped(): array
{
    $grouped = [];
    foreach (get_message_templates() as $tpl) {
        $grouped[$tpl['category']][] = $tpl;
    }
    return $grouped;
}
