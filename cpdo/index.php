<?php
require_once __DIR__ . '/../app/bootstrap_cpdo.php';

$user = current_user();
if ($user && in_array($user['role'], CPDO_ROLES, true)) {
    header('Location: ' . dashboard_for_role($user['role']));
    exit;
}

// Not logged in — redirect to CPDO login
redirect('login.php');
