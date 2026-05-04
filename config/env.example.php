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
        'client_id'    => '',
        'client_secret' => '',
        'redirect_uri' => 'http://localhost/LandReclassification/public/oauth_google_callback.php',
    ],
    'paymongo' => [
        'secret_key'     => '',
        'public_key'     => '',
        'webhook_secret' => '',
    ],
    'mail' => [
        /*
         * Set 'host' to your SMTP server address.
         * Leave empty to fall back to PHP mail() (local MTA required).
         */
        'host'       => '',
        'port'       => 587,
        'username'   => '',
        'password'   => '',
        'encryption' => 'tls',   // 'tls' (STARTTLS on port 587) or 'ssl' (port 465)
        'from_email' => 'no-reply@localhost.test',
        'from_name'  => 'CPDO Land Portal',
    ],
];
