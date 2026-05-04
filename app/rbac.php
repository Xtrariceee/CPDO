<?php

declare(strict_types=1);

const ROLE_SYSTEM_ADMIN = 'admin';
const ROLE_ZONING = 'zoning_officer';
const ROLE_ADMIN_OFFICER = 'admin_officer';
const ROLE_TWG = 'twg_member';
const ROLE_LANDLORD = 'landlord';
const ROLE_TENANT = 'tenant';

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND status = "ACTIVE"');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function user_full_name(array $user): string
{
    $parts = [
        trim((string)($user['first_name'] ?? '')),
        trim((string)($user['middle_name'] ?? '')),
        trim((string)($user['last_name'] ?? '')),
    ];
    $name = trim(implode(' ', array_filter($parts)));
    return $name !== '' ? $name : (string)($user['email'] ?? 'User');
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
    return $user;
}

function require_any_officer_or_admin(): array
{
    return require_role([ROLE_SYSTEM_ADMIN, ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_TWG]);
}

function dashboard_for_role(string $role): string
{
    return match ($role) {
        ROLE_LANDLORD      => 'landlord/dashboard.php',
        ROLE_SYSTEM_ADMIN  => 'admin/dashboard/',
        ROLE_ADMIN_OFFICER => 'admin-officer/dashboard/',
        ROLE_ZONING        => 'zoning/dashboard/',
        ROLE_TWG           => 'twg/dashboard/',
        ROLE_TENANT        => 'tenant/dashboard.php',
        default            => 'index.php',
    };
}

function role_label(string $role): string
{
    return match ($role) {
        ROLE_SYSTEM_ADMIN => 'System Admin',
        ROLE_ZONING => 'Zoning Officer IV',
        ROLE_ADMIN_OFFICER => 'Administrative Officer',
        ROLE_TWG => 'LZRC TWG Member',
        ROLE_LANDLORD => 'Landlord',
        ROLE_TENANT => 'Tenant',
        default => $role,
    };
}
