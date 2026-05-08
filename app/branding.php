<?php

declare(strict_types=1);

function cpdo_logo_url(array $config): ?string
{
    $configuredUrl = trim((string)($config['app']['cpdo_logo_url'] ?? ''));
    if ($configuredUrl !== '') {
        return $configuredUrl;
    }

    $configuredPath = trim((string)($config['app']['cpdo_logo_path'] ?? ''));
    $candidates = [];

    if ($configuredPath !== '') {
        $candidates[] = $configuredPath;
    }

    $candidates = array_merge($candidates, [
        'assets/img/cpdo-logo.png',
        'assets/img/cpdo-logo.svg',
        'assets/img/cpdo-logo.jpg',
        'assets/img/cpdo-logo.jpeg',
        'assets/img/cpdo-logo.webp',
    ]);

    $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    if ($baseUrl === '') {
        return null;
    }

    $publicRoot = dirname(__DIR__) . '/public';

    foreach (array_unique($candidates) as $candidate) {
        $relative = ltrim(str_replace('\\', '/', $candidate), '/');
        if (str_starts_with($relative, 'public/')) {
            $relative = substr($relative, 7);
        }

        $file = $publicRoot . '/' . $relative;
        if (!is_file($file)) {
            continue;
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relative)));
        return $baseUrl . '/' . $encodedPath . '?v=' . filemtime($file);
    }

    return null;
}

function rentease_logo_url(array $config): ?string
{
    $configuredUrl = trim((string)($config['app']['rentease_logo_url'] ?? ''));
    if ($configuredUrl !== '') {
        return $configuredUrl;
    }

    $configuredPath = trim((string)($config['app']['rentease_logo_path'] ?? ''));
    $candidates = [];

    if ($configuredPath !== '') {
        $candidates[] = $configuredPath;
    }

    $candidates = array_merge($candidates, [
        'assets/img/rentease-logo.png',
        'assets/img/rentease-logo.svg',
        'assets/img/rentease-logo.jpg',
        'assets/img/rentease-logo.jpeg',
        'assets/img/rentease-logo.webp',
    ]);

    $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    if ($baseUrl === '') {
        return null;
    }

    $publicRoot = dirname(__DIR__) . '/public';

    foreach (array_unique($candidates) as $candidate) {
        $relative = ltrim(str_replace('\\', '/', $candidate), '/');
        if (str_starts_with($relative, 'public/')) {
            $relative = substr($relative, 7);
        }

        $file = $publicRoot . '/' . $relative;
        if (!is_file($file)) {
            continue;
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relative)));
        return $baseUrl . '/' . $encodedPath . '?v=' . filemtime($file);
    }

    return null;
}
