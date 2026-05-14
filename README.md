# CPDO Land Reclassification Portal

> A Government-to-Citizen (G2C) web application that digitizes the end-to-end land reclassification and rezoning workflow of the City Planning and Development Office (CPDO) of Davao City, Philippines.

---

## Table of Contents

- [Project Title](#project-title)
- [Overview](#overview)
- [Problem Statement](#problem-statement)
- [Objectives](#objectives)
- [Target Users / Personas](#target-users--personas)
- [Workflow Summary](#workflow-summary)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Setup & Installation](#setup--installation)
- [Demo Accounts](#demo-accounts)
- [Security](#security)
- [API Integrations](#api-integrations)
- [Database](#database)

---

## Project Title

**CPDO Land Reclassification Portal**
*Digitizing the Land Use Reclassification and Rezoning Process for Davao City*

---

## Overview

The CPDO Land Reclassification Portal is a multi-role, workflow-driven web platform that replaces the manual, paper-based land reclassification process with a structured digital system. Applicants (landlords/property owners) submit reclassification applications online, upload the required government documents, and track their application status in real time. CPDO staff — Zoning Officers, Administrative Officers, and LZRC TWG Members — process each application through a defined sequence of steps: pre-evaluation, payment, site inspection, TWG meeting, resolution generation, and final endorsement issuance.

The system enforces role-based access control, maintains a full audit trail, generates official PDF documents (vicinity maps, legislative resolutions, endorsements), and integrates with PayMongo for online service fee collection and Google Maps for satellite-based vicinity map generation.

---

## Problem Statement

Land reclassification in Davao City has historically been a slow, opaque, and paper-intensive process. Applicants must physically visit multiple government offices to secure 17+ clearances and certifications, submit paper dossiers, and follow up in person with no visibility into where their application stands. CPDO staff manage applications through manual tracking, physical document storage, and informal coordination between the Zoning Officer, Administrative Officer, and the Land Use Reclassification Committee (LZRC) Technical Working Group (TWG).

This creates several compounding problems:

- **Applicants** have no way to track application status, receive feedback on failed documents, or know when meetings or inspections are scheduled.
- **CPDO staff** lack a centralized system for coordinating multi-step workflows across roles, leading to delays, lost documents, and inconsistent processing.
- **Document integrity** is at risk — paper documents can be lost, damaged, or tampered with, and there is no audit trail for officer decisions.
- **Resolution and endorsement generation** is done manually, introducing errors and inconsistency in official legislative documents.
- **Payment collection** requires in-person visits, creating bottlenecks and limiting accessibility for applicants outside the city center.

---

## Objectives

1. **Digitize the full reclassification workflow** — from application submission through document upload, pre-evaluation, payment, inspection, TWG meeting, resolution generation, and final endorsement issuance.

2. **Provide real-time application tracking** — applicants can log in at any time to see their current process step, document evaluation results, scheduled inspection/meeting dates, and payment status.

3. **Enforce structured role-based access** — each CPDO role (Zoning Officer, Administrative Officer, TWG Member, System Admin) sees only the actions relevant to their function, preventing unauthorized access or out-of-sequence processing.

4. **Automate document generation** — generate official PDFs server-side: satellite-based vicinity maps (via Google Static Maps API), Philippine Sangguniang Panlungsod-style legislative resolutions, and endorsement documents — eliminating manual drafting errors.

5. **Enable online payment** — integrate PayMongo to allow applicants to pay the CPDO service fee via GCash, Maya, or card without visiting the office.

6. **Maintain a complete audit trail** — every officer action, document decision, workflow transition, and login event is logged with timestamp, user identity, and contextual details.

7. **Secure sensitive data** — encrypt sensitive document metadata (land title references, file names) using AES-256-GCM; enforce RBAC on every route; apply DLP headers and session timeout.

8. **Support compliance-gated property listing** — once a reclassification application is approved, the landlord becomes eligible to list rental properties on the platform, creating a verified, compliant property marketplace.

---

## Target Users / Personas

### 1. Landlord / Property Owner (Applicant)
**Who:** A private individual or corporation that owns land in Davao City and wants to change its zoning classification (e.g., from Agricultural to Residential or Commercial) to enable a development project.

**Goals:**
- Submit a land reclassification application online without visiting the CPDO office.
- Upload the 17 required government documents from any device.
- Track application status and receive notifications at each workflow step.
- Pay the CPDO service fee online via GCash, Maya, or card.
- Download the official endorsement/resolution document once approved.
- List rental properties on the platform after approval.

**Pain Points (before this system):**
- No visibility into application status.
- Must physically visit multiple offices to secure documents.
- No way to know which documents failed evaluation or why.
- Payment requires an in-person visit to the City Treasurer's Office.

---

### 2. Zoning Officer IV (CPDO Staff)
**Who:** A licensed government officer at the CPDO responsible for evaluating land reclassification applications, scheduling inspections, verifying payments, and generating the official legislative resolution.

**Goals:**
- Review submitted applications and evaluate each document (pass/fail with notes).
- Generate the Order of Payment for the service fee.
- Schedule site inspections and assign TWG members.
- Schedule and manage TWG meetings.
- Generate the formal Philippine Sangguniang Panlungsod-style legislative resolution PDF.
- Manage requirement guidelines visible to applicants.

**Pain Points (before this system):**
- Manual document review with no structured feedback mechanism.
- Coordination with TWG members done informally.
- Resolution drafting done manually in Word, prone to errors.

---

### 3. Administrative Officer (CPDO Staff)
**Who:** A CPDO Administrative Officer responsible for validating the resolution prepared by the Zoning Officer and issuing the official endorsement document to the applicant.

**Goals:**
- Review applications that have completed TWG deliberation.
- Receive the Zoning Officer's drafted resolution pre-filled in the endorsement form.
- Generate the final endorsement PDF and issue it to the applicant.
- Notify the applicant that their application is approved and the document is ready.

**Pain Points (before this system):**
- No structured handoff from the Zoning Officer — relied on physical documents or informal communication.
- Manual endorsement drafting with no pre-filled data from the resolution.

---

### 4. LZRC TWG Member (CPDO Staff)
**Who:** A member of the Land Use Reclassification Committee (LZRC) Technical Working Group, responsible for conducting site inspections and attending/facilitating TWG meetings.

**Goals:**
- View applications assigned for inspection or meeting.
- Record inspection findings and upload site photos.
- Facilitate and document TWG meetings (minutes of meeting).
- Generate the minutes of meeting PDF.

**Pain Points (before this system):**
- Inspection findings recorded on paper, not linked to the application record.
- Meeting minutes drafted manually with no structured template.

---

### 5. System Administrator (CPDO IT / Management)
**Who:** A super-user with full access to both the public portal and the CPDO staff portal.

**Goals:**
- Manage all user accounts (activate, disable, assign roles).
- Review the full audit log of all system actions.
- Approve or reject staff designation requests (pending staff → assigned role).
- Approve or reject tenant-to-landlord role upgrade requests.
- Monitor all applications across all statuses.

---

### 6. Tenant (Public Portal User)
**Who:** A person looking to rent a property listed on the platform.

**Goals:**
- Browse verified, CPDO-compliant rental property listings.
- View property details and contact landlords.
- Request a role upgrade to Landlord if they also own property.

---

## Workflow Summary

The land reclassification process follows 14 sequential steps:

```
P1  DRAFT              Applicant fills the Locational Clearance form and draws land boundary
P2  SUBMITTED          Applicant uploads all 17 required documents
P3  PRE_EVALUATION     Zoning Officer reviews and evaluates each document (pass/fail)
P4  PAYMENT_PENDING    Zoning Officer generates the Order of Payment
P5  PAID               Applicant pays the service fee via PayMongo
P6  INSPECTION_SCHED   Zoning Officer schedules the site inspection
P7  INSPECTION_DONE    TWG Member conducts inspection, records findings, uploads photos
P8  FOR_MEETING        Zoning Officer schedules the TWG meeting
P9  DELIBERATION       TWG Member facilitates meeting, saves Minutes of Meeting
P10 DELIBERATION       Zoning Officer generates the legislative resolution PDF
P11 DELIBERATION       Administrative Officer reviews and issues the endorsement
P12 APPROVED           Applicant downloads the official endorsement document
```

---

## Features

### Public Portal (`/public/`)
- Applicant registration with OTP email verification
- Google OAuth 2.0 sign-in
- Locational Clearance application form with interactive Leaflet map (draw land boundary polygon)
- Automatic vicinity map PDF generation (Google Static Maps satellite + overview)
- 17-document upload interface with camera capture support
- Real-time application status tracking
- PayMongo online payment (GCash, Maya, card)
- Endorsement/resolution document download on approval
- Rental property listing (compliance-gated)
- Tenant dashboard and role upgrade requests

### CPDO Staff Portal (`/cpdo/`)
- Separate login for CPDO staff with OTP verification
- **Zoning Officer:** pre-evaluation, document pass/fail with notes, payment order generation, inspection scheduling, payment verification, TWG meeting scheduling, resolution generation
- **Administrative Officer:** endorsement issuance with pre-filled resolution data from Zoning Officer
- **TWG Member:** inspection management with photo upload, meeting management with minutes of meeting PDF
- **System Admin:** user management, audit log viewer, all-applications overview
- Real-time notifications (bell icon) for all staff roles
- Audit log for every officer action

### Document Generation
- **Vicinity Map PDF** — landscape A4, satellite main map + roadmap inset, legend, signature block
- **Minutes of Meeting PDF** — structured TWG meeting minutes
- **Legislative Resolution PDF** — Philippine Sangguniang Panlungsod format, multi-page, auto-paginated
- **Endorsement Document** — generated server-side by Administrative Officer, stored in `storage/final_outputs/`

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8+ (procedural, no framework) |
| Database | MySQL / MariaDB |
| Frontend | Bootstrap 5, vanilla JavaScript |
| Maps (interactive) | Leaflet.js + OpenStreetMap / ArcGIS tiles |
| Maps (PDF generation) | Google Static Maps API |
| Authentication | Local bcrypt + Google OAuth 2.0 |
| Email | PHPMailer (Gmail SMTP / Mailtrap) |
| Payment | PayMongo (GCash, Maya, card) |
| PDF generation | Pure PHP (raw PDF assembly) + FPDF (vicinity maps) |
| Encryption | AES-256-GCM (sensitive metadata) |

---

## Project Structure

```
LandReclassification/
│
├── app/                        # Core application logic (shared by both portals)
│   ├── audit.php               # Audit logging + notification helpers
│   ├── bootstrap.php           # Public portal bootstrap (session, config, helpers)
│   ├── bootstrap_cpdo.php      # CPDO portal bootstrap
│   ├── branding.php            # Logo/branding helpers
│   ├── db.php                  # PDO database connection singleton
│   ├── mailer.php              # PHPMailer OTP email sender
│   ├── officer_workflow.php    # Officer-side query helpers (applications, payments, inspections)
│   ├── rbac.php                # Role constants, RBAC guards, role helpers
│   ├── resolution_pdf.php      # Legislative resolution PDF generator
│   ├── security.php            # CSRF, encryption, session timeout, DLP headers
│   ├── vicinity_map_pdf.php    # Vicinity map PDF generator (Google Static Maps + FPDF)
│   └── workflow.php            # Application workflow: create, advance, requirements, documents
│
├── config/
│   ├── env.example.php         # Configuration template (copy to env.php)
│   └── env.php                 # Live configuration (not committed — see .gitignore)
│
├── cpdo/                       # CPDO Staff Portal
│   ├── admin/                  # System Admin pages
│   │   ├── applications/       # All-applications overview
│   │   ├── audit/              # Audit log viewer
│   │   ├── dashboard/          # Admin dashboard
│   │   └── users/              # User management
│   ├── admin-officer/          # Administrative Officer pages
│   │   ├── dashboard/          # AO dashboard
│   │   ├── final-output.php    # Generate & issue endorsement (resolution PDF)
│   │   ├── order-payment.php   # Order of Payment management
│   │   └── skip-verification.php # Compliance skip verification
│   ├── twg/                    # LZRC TWG Member pages
│   │   ├── dashboard/          # TWG dashboard
│   │   ├── inspection.php      # Site inspection management
│   │   ├── inspection_photo.php # Inspection photo viewer
│   │   ├── meeting.php         # TWG meeting & minutes management
│   │   ├── upload_inspection_photo.php
│   │   └── delete_inspection_photo.php
│   ├── zoning-officer/         # Zoning Officer pages
│   │   ├── application-view.php # Full application detail view
│   │   ├── consolidation.php   # Application consolidation
│   │   ├── guidelines.php      # Requirement guidelines management
│   │   ├── index.php           # ZO dashboard
│   │   ├── inspection-scheduling.php
│   │   ├── payment-scheduling.php
│   │   ├── payment-verification.php
│   │   ├── pre-evaluation.php  # Document evaluation (pass/fail)
│   │   └── resolution.php      # Legislative resolution generator
│   ├── zoning/dashboard/       # Zoning Officer dashboard (redirect hub)
│   ├── pending-staff/          # Pending staff holding page
│   ├── partials/               # Shared CPDO header/footer
│   ├── index.php               # CPDO portal root (role-based redirect)
│   ├── login.php               # CPDO staff login
│   ├── logout.php
│   ├── register.php            # Staff self-registration
│   ├── verify_otp.php
│   └── mark_notifications_read.php
│
├── public/                     # Public Portal (Applicants / Tenants)
│   ├── admin/                  # Admin pages (public portal mirror)
│   ├── admin-officer/          # AO pages (public portal mirror)
│   ├── assets/
│   │   ├── css/                # app.css, landlord-dashboard.css
│   │   ├── img/                # Logos, branding assets
│   │   └── js/                 # Client-side scripts
│   ├── landlord/
│   │   ├── application-form.php    # Locational Clearance form + Leaflet map
│   │   ├── application-show.php    # Application status & document tracker
│   │   ├── compliance-gateway.php  # Compliance path selector
│   │   ├── dashboard.php           # Landlord dashboard
│   │   ├── requirements-upload.php # Document upload interface
│   │   ├── property-form.php       # Rental property listing form
│   │   └── property-view.php       # Property detail view
│   ├── tenant/dashboard.php    # Tenant dashboard
│   ├── twg/                    # TWG pages (public portal mirror)
│   ├── zoning-officer/         # ZO pages (public portal mirror)
│   ├── partials/               # Shared public header/footer
│   ├── index.php               # Landing page
│   ├── login.php               # Public login
│   ├── register.php            # Public registration
│   ├── verify_otp.php          # OTP verification
│   ├── oauth_google_callback.php # Google OAuth callback
│   ├── paymongo_checkout.php   # PayMongo checkout session creator
│   ├── payment_success.php     # Payment success handler
│   ├── document_preview.php    # Secure document/PDF viewer
│   ├── vicinity_map_preview.php # Vicinity map PDF viewer
│   ├── workflow_action.php     # Generic workflow action handler
│   └── workflow_dashboard.php  # Workflow status dashboard
│
├── database/
│   └── schema.sql              # Full database schema + seed data (run on fresh DB)
│
├── storage/                    # Runtime file storage (not committed)
│   ├── uploads/                # Uploaded requirement documents (legacy path)
│   ├── vicinity_maps/          # Generated vicinity map PDFs
│   ├── final_outputs/          # Generated endorsement/resolution PDFs
│   └── tmp/                    # Temporary files (map image processing)
│
├── vendor/                     # Composer dependencies
│   ├── phpmailer/phpmailer/    # PHPMailer — OTP email delivery
│   └── setasign/fpdf/          # FPDF — vicinity map PDF generation
│
├── config/env.example.php      # ← Copy this to config/env.php and fill in values
├── composer.json
├── index.php                   # Root redirect → public/index.php
└── README.md
```

---

## Setup & Installation

### Prerequisites
- XAMPP (Apache + MySQL) or equivalent PHP 8+ / MySQL environment
- Composer
- A Gmail account with an App Password (for OTP emails)
- A PayMongo test account (for payment integration)
- A Google Cloud project with Maps JavaScript API + Static Maps API enabled

### Steps

**1. Clone / copy the project**
```
Place the project folder at: C:/xampp/htdocs/LandReclassification/
```

**2. Import the database**
- Start XAMPP Apache and MySQL.
- Open `http://localhost/phpmyadmin`.
- Create a new database named `land_reclassification` (or let the schema do it).
- Import `database/schema.sql` — this creates all tables, views, foreign keys, and demo accounts.

**3. Install PHP dependencies**
```bash
cd C:/xampp/htdocs/LandReclassification
composer install
```

**4. Configure environment**
```bash
cp config/env.example.php config/env.php
```
Edit `config/env.php` and fill in:
- Database credentials (`db.host`, `db.database`, `db.username`, `db.password`)
- Gmail SMTP credentials (`mail.username`, `mail.password` — use a 16-char App Password)
- Google API key (`google.maps_api_key` — needs Maps JavaScript API + Static Maps API)
- PayMongo keys (`paymongo.secret_key`, `paymongo.public_key`)
- Encryption key (`security.encryption_key_hex` — generate with `php -r "echo bin2hex(random_bytes(32));"`)

**5. Open the application**
- Public portal: `http://localhost/LandReclassification/public/`
- CPDO staff portal: `http://localhost/LandReclassification/cpdo/`
- Root redirect: `http://localhost/LandReclassification/`

---

## Demo Accounts

All demo accounts use the password: **`Test1234`**

| Role | Email | Portal |
|---|---|---|
| System Admin | `admin@test.com` | CPDO |
| Zoning Officer IV | `zoning@test.com` | CPDO |
| Administrative Officer | `adminofficer@test.com` | CPDO |
| LZRC TWG Member | `twg@test.com` | CPDO |
| Landlord | `landlord@test.com` | Public |
| Tenant | `tenant@test.com` | Public |

**Portal URLs:**

| Role | Dashboard URL |
|---|---|
| System Admin | `/cpdo/admin/dashboard/` |
| Zoning Officer IV | `/cpdo/zoning/dashboard/` |
| Administrative Officer | `/cpdo/admin-officer/dashboard/` |
| LZRC TWG Member | `/cpdo/twg/dashboard/` |
| Landlord | `/public/landlord/dashboard.php` |
| Tenant | `/public/tenant/dashboard.php` |

---

## Security

| Feature | Implementation |
|---|---|
| Password hashing | bcrypt via `password_hash()` |
| Role-based access control | `require_role()` guard on every protected page |
| CSRF protection | Synchronizer token pattern on all POST forms |
| Session timeout | 15-minute inactivity auto-logout |
| Sensitive data encryption | AES-256-GCM for land title references and document filenames |
| Audit logging | Every login, document decision, workflow transition, and officer action |
| DLP headers | `X-Frame-Options`, `X-Content-Type-Options`, `Cache-Control: no-store` |
| OTP verification | 6-digit OTP via email, 10-minute expiry, required on registration and Google sign-in |
| Path traversal prevention | `realpath()` + storage root prefix check on all file serves |

---

## API Integrations

### Google Maps
- **Static Maps API** — used server-side to fetch satellite and roadmap tile images for vicinity map PDF generation.
- **Maps JavaScript API** — used client-side in the application form for the interactive Leaflet map tile layer (via ArcGIS/OSM tiles; the Google key is available for future use).

### PayMongo
- **Checkout Sessions API** — creates a hosted checkout session for GCash, Maya, or card payment of the CPDO service fee (PHP 1,500.00 default).
- On success, `payment_success.php` marks the order as paid and advances the application to the next workflow step.

### Google OAuth 2.0
- Applicants and tenants can sign in with Google.
- New Google accounts are prompted to select a role (landlord/tenant) and verify via OTP.

### PHPMailer / Gmail SMTP
- Sends OTP verification emails on registration and Google sign-in.
- Sends workflow notification emails (meeting scheduled, application approved, etc.).

---

## Database

The full schema is in `database/schema.sql`. Key tables:

| Table | Purpose |
|---|---|
| `users` | All users (applicants, tenants, CPDO staff, admin) |
| `applications` | Core reclassification application records |
| `requirement_documents` | 17 required documents per application (file data stored as BLOB) |
| `requirement_guidelines` | Zoning Officer overrides for document titles and guidance text |
| `payment_orders` | Service fee payment orders linked to applications |
| `inspections` | Site inspection records with findings and schedule |
| `inspection_photos` | Site photos uploaded by TWG members (stored as BLOB) |
| `meetings` | TWG meeting records with structured minutes text |
| `final_outputs` | Endorsement/resolution PDFs + ZO resolution draft data (JSON) |
| `compliance_uploads` | Alternative compliance path (skip full workflow) |
| `properties` | Rental property listings (compliance-gated) |
| `notifications` | In-app notifications for all roles |
| `audit_logs` | Full audit trail of all system actions |
| `votes` | Legacy TWG voting records (superseded by minutes workflow) |
| `role_upgrade_requests` | Tenant → Landlord upgrade requests |
| `staff_designation_requests` | Pending staff → assigned role requests |

To apply the `resolution_data` column to an existing database without reimporting:
```sql
ALTER TABLE final_outputs
ADD COLUMN IF NOT EXISTS resolution_data JSON NULL
AFTER resolution_file_path;
```
