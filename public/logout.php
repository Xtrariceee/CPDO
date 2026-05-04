<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = current_user();
if ($user) {
    audit_log((int)$user['id'], 'LOGOUT', 'users', (int)$user['id'], ['reason' => $_GET['reason'] ?? 'manual']);
}

session_unset();
session_destroy();
session_start();
$_SESSION['flash_success'] = 'You have been signed out.';
redirect('login.php');
