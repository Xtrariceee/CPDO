<?php

declare(strict_types=1);

/* ── String role constants (used throughout existing code) ── */
const ROLE_SYSTEM_ADMIN  = 'admin';
const ROLE_ZONING        = 'zoning_officer';
const ROLE_ADMIN_OFFICER = 'admin_officer';
const ROLE_TWG           = 'twg_member';
const ROLE_LANDLORD      = 'landlord';
const ROLE_TENANT        = 'tenant';
const ROLE_PENDING_STAFF = 'pending_staff'; // Self-registered CPDO employee awaiting role assignment

/* ── Numeric role_id mapping (per spec) ──────────────────────
   1 = Tenant
   2 = Landlord
   3 = CPDO Administrative Officer
   4 = CPDO Zoning Officer IV
   5 = CPDO LZRC TWG Member
   (System Admin = 0, super-user, access to both portals)
   ─────────────────────────────────────────────────────────── */
const ROLE_ID_TENANT        = 1;
const ROLE_ID_LANDLORD      = 2;
const ROLE_ID_ADMIN_OFFICER = 3;
const ROLE_ID_ZONING        = 4;
const ROLE_ID_TWG           = 5;

/* Roles that belong to the PUBLIC portal (tenant / landlord) */
const PUBLIC_ROLES = [ROLE_TENANT, ROLE_LANDLORD];

/* Roles that belong to the CPDO portal (staff) */
const CPDO_ROLES = [ROLE_SYSTEM_ADMIN, ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_TWG];

/* All roles that may log in to the CPDO portal (includes pending staff) */
const CPDO_ALL_ROLES = [ROLE_SYSTEM_ADMIN, ROLE_ZONING, ROLE_ADMIN_OFFICER, ROLE_TWG, ROLE_PENDING_STAFF];

function role_to_id(string $role): int
{
    return match ($role) {
        ROLE_TENANT        => ROLE_ID_TENANT,
        ROLE_LANDLORD      => ROLE_ID_LANDLORD,
        ROLE_ADMIN_OFFICER => ROLE_ID_ADMIN_OFFICER,
        ROLE_ZONING        => ROLE_ID_ZONING,
        ROLE_TWG           => ROLE_ID_TWG,
        default            => 0,
    };
}

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
        trim((string)($user['first_name']  ?? '')),
        trim((string)($user['middle_name'] ?? '')),
        trim((string)($user['last_name']   ?? '')),
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

/**
 * Require PUBLIC portal role (tenant / landlord).
 * Blocks CPDO staff from accessing the rental portal.
 */
function require_public_role(): array
{
    $user = require_login();
    if (!in_array($user['role'], PUBLIC_ROLES, true)) {
        http_response_code(403);
        exit('Access denied. This portal is for Tenants and Landlords only.');
    }
    return $user;
}

/**
 * Require CPDO portal role (admin / zoning / admin_officer / twg).
 * Blocks tenants and landlords from accessing the government portal.
 */
function require_cpdo_role(): array
{
    $user = require_login();
    if (!in_array($user['role'], CPDO_ROLES, true)) {
        http_response_code(403);
        exit('Access denied. This portal is for CPDO staff only.');
    }
    return $user;
}

function dashboard_for_role(string $role): string
{
    global $config;
    $pub  = rtrim($config['app']['base_url'], '/');
    $cpdo = rtrim($config['app']['cpdo_url'] ?? str_replace('/public', '/cpdo', $pub), '/');

    return match ($role) {
        ROLE_TENANT        => $pub  . '/tenant/dashboard.php',
        ROLE_LANDLORD      => $pub  . '/landlord/dashboard.php',
        ROLE_SYSTEM_ADMIN  => $cpdo . '/admin/dashboard/',
        ROLE_ADMIN_OFFICER => $cpdo . '/admin-officer/dashboard/',
        ROLE_ZONING        => $cpdo . '/zoning/dashboard/',
        ROLE_TWG           => $cpdo . '/twg/dashboard/',
        ROLE_PENDING_STAFF => $cpdo . '/pending-staff/dashboard.php',
        default            => $pub  . '/index.php',
    };
}

function role_label(string $role): string
{
    return match ($role) {
        ROLE_SYSTEM_ADMIN  => 'System Admin',
        ROLE_ZONING        => 'Zoning Officer IV',
        ROLE_ADMIN_OFFICER => 'Administrative Officer',
        ROLE_TWG           => 'LZRC TWG Member',
        ROLE_LANDLORD      => 'Landlord',
        ROLE_TENANT        => 'Tenant',
        ROLE_PENDING_STAFF => 'Pending Staff',
        default            => $role,
    };
}

/**
 * Require the pending_staff role specifically.
 * Used by the restricted pending-staff dashboard.
 */
function require_pending_staff(): array
{
    $user = require_login();
    if ($user['role'] !== ROLE_PENDING_STAFF) {
        // Fully provisioned staff — send them to their real dashboard
        header('Location: ' . dashboard_for_role($user['role']));
        exit;
    }
    return $user;
}


