<?php

declare(strict_types=1);

$envFile = __DIR__ . '/../config/env.php';
$config = file_exists($envFile) ? require $envFile : require __DIR__ . '/../config/env.example.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

session_name('CPDO_SECURE_SESSION');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/officer_workflow.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/vicinity_map_pdf.php';
require_once __DIR__ . '/resolution_pdf.php';

enforce_session_timeout((int)$config['app']['session_timeout_seconds']);
