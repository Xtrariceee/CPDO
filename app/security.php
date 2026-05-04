<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }
    }
}

function password_is_strong(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password);
}

function enforce_session_timeout(int $seconds): void
{
    if (!empty($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $seconds) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash_error'] = 'Session expired after 15 minutes of inactivity.';
    }
    $_SESSION['last_activity'] = time();
}

function encrypt_sensitive(?string $plain): array
{
    global $config;
    if ($plain === null || $plain === '') {
        return ['ciphertext' => null, 'nonce' => null];
    }

    $key = hex2bin($config['security']['encryption_key_hex']);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Invalid encryption key. Configure config/env.php.');
    }

    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

    return [
        'ciphertext' => base64_encode($ciphertext . $tag),
        'nonce' => bin2hex($nonce),
    ];
}

function decrypt_sensitive(?string $ciphertext, ?string $nonceHex): ?string
{
    global $config;
    if (!$ciphertext || !$nonceHex) {
        return null;
    }

    $key = hex2bin($config['security']['encryption_key_hex']);
    $payload = base64_decode($ciphertext, true);
    $nonce = hex2bin($nonceHex);
    if ($key === false || $payload === false || $nonce === false || strlen($payload) < 16) {
        return null;
    }

    $tag = substr($payload, -16);
    $rawCiphertext = substr($payload, 0, -16);
    $plain = openssl_decrypt($rawCiphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

    return $plain === false ? null : $plain;
}

function secure_upload(array $file, string $directory, array $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png']): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Only PDF, JPG, JPEG, and PNG files are allowed.');
    }

    $targetDir = __DIR__ . '/../storage/uploads/' . trim($directory, '/');
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $targetDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Unable to store uploaded file.');
    }

    return 'storage/uploads/' . trim($directory, '/') . '/' . $filename;
}

function redirect(string $path): void
{
    global $config;
    header('Location: ' . rtrim($config['app']['base_url'], '/') . '/' . ltrim($path, '/'));
    exit;
}
