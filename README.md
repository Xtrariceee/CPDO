# Land Reclassification and Rental Management Platform

Secure G2C workflow prototype for the CPDO land reclassification and rezoning process, with a compliance-gated transition into landlord property listings.

## Stack

- PHP 8+
- MySQL / MariaDB
- Bootstrap 5
- JavaScript
- Local auth with bcrypt
- Google OAuth 2.0 and PayMongo integration points

## Setup

1. Start XAMPP Apache and MySQL.
2. Open phpMyAdmin at `http://localhost/phpmyadmin`.
3. Import the full `database/schema.sql` file. It creates the database, tables, views, foreign keys, and test accounts.
4. Run `composer install` if the `vendor` folder is missing. PHPMailer is required for OTP email delivery.
5. Copy `config/env.example.php` to `config/env.php` if needed and update database and SMTP credentials.
6. Open `http://localhost/LandReclassification/` or `http://localhost/LandReclassification/public/`.

Registration creates an unverified account, sends a 6-digit OTP, and redirects to `verify_otp.php`. Users must verify the OTP and then log in manually. Google sign-in also requires OTP verification for unverified Google-linked accounts.

## Demo Users

The imported schema creates these accounts. Password for all accounts: `12345678`.

| Role | Email |
| --- | --- |
| System Admin | `admin@test.com` |
| Zoning Officer IV | `zoning@test.com` |
| Administrative Officer | `adminofficer@test.com` |
| LZRC TWG Member | `twg@test.com` |
| Landlord | `landlord@test.com` |
| Tenant | `tenant@test.com` |

Dedicated officer route groups:

- System Admin: `http://localhost/LandReclassification/public/admin/dashboard/`
- Zoning Officer IV: `http://localhost/LandReclassification/public/zoning/dashboard/`
- Administrative Officer: `http://localhost/LandReclassification/public/admin-officer/dashboard/`
- LZRC TWG Member: `http://localhost/LandReclassification/public/twg/dashboard/`

## Security Features

- Bcrypt password hashing
- RBAC checks on every protected action
- 15-minute inactivity logout
- Audit logs for logins, document reviews, workflow decisions, and officer actions
- Encrypted sensitive document metadata fields using AES-256-GCM
- DLP headers, no-store caching, export restrictions, and best-effort screenshot deterrence
