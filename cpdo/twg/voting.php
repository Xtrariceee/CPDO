<?php
/**
 * TWG Voting — REMOVED
 *
 * The decision is now derived from the Minutes of Meeting (meeting.php).
 * After the TWG saves the minutes, the application moves to DELIBERATION
 * and the Zoning Officer can generate the resolution directly.
 *
 * This page redirects to the meeting management page.
 */
require_once __DIR__ . '/../../app/bootstrap_cpdo.php';
$user = require_role([ROLE_TWG, ROLE_SYSTEM_ADMIN]);

$applicationId = (int)($_GET['id'] ?? 0);

$_SESSION['flash_error'] = 'The voting system has been removed. The committee decision is now recorded in the Minutes of Meeting. Please use the Meeting Management page to save the minutes and advance the application.';

if ($applicationId) {
    redirect('twg/meeting.php?id=' . $applicationId);
} else {
    redirect('twg/meeting.php');
}
