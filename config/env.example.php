<?php

/**
 * Copy this file to config/env.php and fill in your values.
 *
 * MAIL SETUP (required for OTP emails):
 *   Option A – Gmail SMTP (recommended for testing):
 *     host       => 'smtp.gmail.com'
 *     port       => 587
 *     username   => 'your-gmail@gmail.com'
 *     password   => 'your-app-password'   ← generate at myaccount.google.com/apppasswords
 *     encryption => 'tls'
 *
 *   Option B – Mailtrap (safe sandbox for dev):
 *     host       => 'sandbox.smtp.mailtrap.io'
 *     port       => 2525
 *     username   => '<mailtrap-username>'
 *     password   => '<mailtrap-password>'
 *     encryption => 'tls'
 *
 *   Option C – Leave host empty to use PHP mail() (works only if your server
 *              has a local MTA configured, e.g. Postfix/Sendmail).
 *
 * ENCRYPTION KEY:
 *   Generate with: php -r "echo bin2hex(random_bytes(32));"
 */

return [
    'app' => [
        'base_url'                => 'http://localhost/LandReclassification/public',
        'cpdo_url'                => 'http://localhost/LandReclassification/cpdo',
        'cpdo_logo_path'          => 'assets/img/cpdo-logo.png',
        'name'                    => 'CPDO Land Reclassification Portal',
        'session_timeout_seconds' => 900,
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'database' => 'land_reclassification',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    'security' => [
        'encryption_key_hex' => 'replace_with_64_hex_characters',
    ],
    'google' => [
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => '',
        'maps_api_key'  => '',   // ← Add your Google Maps JavaScript API + Static Maps API key here
    ],
    'paymongo' => [
        'secret_key'     => '',
        'public_key'     => '',
        'webhook_secret' => '',
    ],
    'mail' => [
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'encryption' => 'tls',
        'username'   => '',   // ← REPLACE with your Gmail address
        'password'   => '', // ← REPLACE with 16-char App Password
        'from_email' => '',   // ← REPLACE with same Gmail address
        'from_name'  => '',
    ],
];
