-- ═══════════════════════════════════════════════════════════════════════════════
-- CPDO Land Reclassification Portal — Master Database Schema
-- Reflects the live database as of the current build.
-- Run this file on a FRESH database only. All tables are dropped and recreated.
-- Existing data will be lost.
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS land_reclassification
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE land_reclassification;

SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW  IF EXISTS decisions;
DROP VIEW  IF EXISTS payments;
DROP VIEW  IF EXISTS evaluations;
DROP VIEW  IF EXISTS documents;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS role_upgrade_requests;
DROP TABLE IF EXISTS staff_designation_requests;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS properties;
DROP TABLE IF EXISTS compliance_uploads;
DROP TABLE IF EXISTS final_outputs;
DROP TABLE IF EXISTS votes;
DROP TABLE IF EXISTS meetings;
DROP TABLE IF EXISTS inspections;
DROP TABLE IF EXISTS payment_orders;
DROP TABLE IF EXISTS requirement_documents;
DROP TABLE IF EXISTS requirement_guidelines;
DROP TABLE IF EXISTS applications;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- USERS
-- Roles: admin | zoning_officer | admin_officer | twg_member | landlord |
--        tenant | pending_staff (self-registered CPDO employee awaiting role)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE users (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name             VARCHAR(100) NOT NULL,
    middle_name            VARCHAR(100) NULL,
    last_name              VARCHAR(100) NOT NULL,
    email                  VARCHAR(190) NOT NULL,
    password_hash          VARCHAR(255) NULL,
    google_id              VARCHAR(190) NULL,
    role                   ENUM(
                               'admin',
                               'zoning_officer',
                               'admin_officer',
                               'twg_member',
                               'landlord',
                               'tenant',
                               'pending_staff'
                           ) NOT NULL DEFAULT 'landlord',
    -- google_registered_role: role selected at Google OAuth registration time.
    -- NULL = Google account auto-created (sign-in only, no explicit registration).
    google_registered_role ENUM('landlord','tenant') NULL DEFAULT NULL,
    status                 ENUM('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
    otp_code               VARCHAR(6)   NULL,
    otp_expiry             DATETIME     NULL,
    is_verified            TINYINT(1)   NOT NULL DEFAULT 0,
    created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email     (email),
    UNIQUE KEY uq_users_google_id (google_id),
    INDEX idx_users_role_status   (role, status),
    INDEX idx_users_verified      (is_verified),
    INDEX idx_users_name          (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- APPLICATIONS
-- Core CPDO workflow entity.
--
-- Column order matches the live database exactly (Locational Clearance fields
-- were added via ALTER TABLE between account_address and property_title).
--
-- Locational Clearance form fields (Process 2 — captured digitally):
--   corporation_name, representative_name, type_of_project, lot_area,
--   building_area, project_cost, nature_of_application (+_other),
--   right_over_land, existing_land_use (+_other), sworn_statement,
--   vicinity_map_pdf_path
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE applications (
    id                           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id                  BIGINT UNSIGNED NOT NULL,
    registry_number              VARCHAR(40)   NOT NULL,

    -- ── Core applicant fields ────────────────────────────────────────────────
    account_name                 VARCHAR(160)  NOT NULL,
    account_address              TEXT          NOT NULL,

    -- ── Locational Clearance form fields ────────────────────────────────────
    corporation_name             VARCHAR(190)  NULL,
    representative_name          VARCHAR(190)  NULL,
    type_of_project              VARCHAR(190)  NULL,
    lot_area                     DECIMAL(14,2) NULL,
    building_area                DECIMAL(14,2) NULL,
    project_cost                 DECIMAL(16,2) NULL,
    nature_of_application        ENUM('new_development','improvement','others') NULL,
    nature_of_application_other  VARCHAR(190)  NULL,
    right_over_land              ENUM('owner','lessee') NULL,
    existing_land_use            ENUM(
                                     'residential',
                                     'commercial',
                                     'industrial',
                                     'institutional',
                                     'agricultural',
                                     'others'
                                 ) NULL,
    existing_land_use_other      VARCHAR(190)  NULL,
    sworn_statement              TINYINT(1)    NOT NULL DEFAULT 0,
    vicinity_map_pdf_path        VARCHAR(255)  NULL,   -- relative path to generated PDF

    -- ── Property / project location ─────────────────────────────────────────
    property_title               VARCHAR(190)  NOT NULL,
    property_address             TEXT          NOT NULL,
    coordinates                  VARCHAR(120)  NULL,

    -- ── Encrypted sensitive field ────────────────────────────────────────────
    sensitive_land_title_enc     TEXT          NULL,
    sensitive_land_title_nonce   VARCHAR(32)   NULL,

    -- ── Workflow state ───────────────────────────────────────────────────────
    status      ENUM(
                    'pending_documents',
                    'under_evaluation',
                    'for_payment',
                    'for_inspection',
                    'under_deliberation',
                    'approved',
                    'rejected'
                ) NOT NULL DEFAULT 'pending_documents',
    phase_status ENUM(
                    'DRAFT',
                    'SUBMITTED',
                    'PRE_EVALUATION',
                    'PAYMENT_PENDING',
                    'PAID',
                    'INSPECTION_SCHEDULED',
                    'INSPECTION_DONE',
                    'FOR_MEETING',
                    'DELIBERATION',
                    'APPROVED',
                    'DISAPPROVED',
                    'DEFERRED'
                ) NOT NULL DEFAULT 'DRAFT',
    current_process TINYINT UNSIGNED NOT NULL DEFAULT 1,

    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_applications_registry (registry_number),
    CONSTRAINT fk_applications_landlord
        FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_applications_status       (status),
    INDEX idx_applications_phase_status (phase_status),
    INDEX idx_applications_landlord     (landlord_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- REQUIREMENT DOCUMENTS
-- 18 mandatory documents per application.
-- Note: 'application_form' was removed — the form is now captured digitally
-- via the Locational Clearance form above.
-- group_name = issuing office / where to secure the document.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE requirement_documents (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id       BIGINT UNSIGNED NOT NULL,
    requirement_key      VARCHAR(80)  NOT NULL,
    group_name           VARCHAR(120) NOT NULL,
    title                VARCHAR(255) NOT NULL,
    details              TEXT         NULL COMMENT 'Guidance text shown to the landlord on the upload page',
    -- File stored as binary in DB; file_path kept for legacy/fallback
    file_path            VARCHAR(255) NULL,
    file_data            LONGBLOB     NULL,
    file_mime            VARCHAR(80)  NULL,
    original_name_enc    TEXT         NULL,
    original_name_nonce  VARCHAR(32)  NULL,
    evaluation_status    ENUM('PENDING','PASSED','FAILED') NOT NULL DEFAULT 'PENDING',
    officer_notes        TEXT         NULL,
    evaluated_by         BIGINT UNSIGNED NULL,
    evaluated_at         DATETIME     NULL,
    uploaded_at          DATETIME     NULL,
    UNIQUE KEY app_requirement (application_id, requirement_key),
    CONSTRAINT fk_documents_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_documents_evaluator
        FOREIGN KEY (evaluated_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_documents_eval_status  (evaluation_status),
    INDEX idx_documents_application  (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- REQUIREMENT GUIDELINES
-- Zoning Officer-managed overrides for requirement titles and guidance details.
-- When a row exists for a requirement_key and is_active = 1, it supersedes the
-- code defaults in workflow.php for all landlords immediately.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE requirement_guidelines (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requirement_key VARCHAR(80)  NOT NULL,
    group_name      VARCHAR(120) NOT NULL,
    title           VARCHAR(255) NOT NULL,
    details         TEXT         NOT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    updated_by      BIGINT UNSIGNED NULL,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_guidelines_key (requirement_key),
    CONSTRAINT fk_guidelines_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_guidelines_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- PAYMENT ORDERS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE payment_orders (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id        BIGINT UNSIGNED NOT NULL,
    op_number             VARCHAR(40)   NOT NULL,
    registry_number       VARCHAR(40)   NOT NULL,
    account_name          VARCHAR(160)  NULL,
    payment_for           VARCHAR(190)  NOT NULL DEFAULT 'CPDO Land Reclassification Service Fee',
    account_code          VARCHAR(30)   NOT NULL DEFAULT '4-02-01-020-8-6',
    service_fee           DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
    status                ENUM('PENDING','PAID','FAILED') NOT NULL DEFAULT 'PENDING',
    payment_method        VARCHAR(40)   NULL,
    paymongo_checkout_id  VARCHAR(120)  NULL,
    receipt_number        VARCHAR(60)   NULL,
    paid_at               DATETIME      NULL,
    verified_by           BIGINT UNSIGNED NULL,
    verified_at           DATETIME      NULL,
    created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_op_number (op_number),
    CONSTRAINT fk_payments_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_payments_verified_by
        FOREIGN KEY (verified_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_payment_status (status),
    INDEX idx_payment_verified (verified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- INSPECTIONS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE inspections (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id     BIGINT UNSIGNED NOT NULL,
    scheduled_at       DATETIME     NULL,
    scheduled_date     DATE         NULL,
    scheduled_time     TIME         NULL,
    assigned_by        BIGINT UNSIGNED NULL,
    findings           TEXT         NULL,
    site_plan_valid    TINYINT(1)   NOT NULL DEFAULT 0,
    coordinates_valid  TINYINT(1)   NOT NULL DEFAULT 0,
    finalized_at       DATETIME     NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inspections_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_inspections_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_inspections_schedule (scheduled_at),
    INDEX idx_inspections_schedule_parts (scheduled_date, scheduled_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- MEETINGS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE meetings (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    scheduled_at   DATETIME     NULL,
    minutes        TEXT         NULL,
    created_by     BIGINT UNSIGNED NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_meetings_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_meetings_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_meetings_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- VOTES (TWG deliberation)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE votes (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    twg_member_id  BIGINT UNSIGNED NOT NULL,
    vote           ENUM('APPROVED','DISAPPROVED','DEFERRED') NOT NULL,
    notes          TEXT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY app_member_vote (application_id, twg_member_id),
    CONSTRAINT fk_votes_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_votes_twg_member
        FOREIGN KEY (twg_member_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_votes_application  (application_id),
    INDEX idx_votes_twg_member   (twg_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- FINAL OUTPUTS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE final_outputs (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id        BIGINT UNSIGNED NOT NULL,
    signature_file_path   VARCHAR(255) NULL,
    resolution_file_path  VARCHAR(255) NULL,
    endorsement_number    VARCHAR(80)  NULL,
    uploaded_by           BIGINT UNSIGNED NULL,
    uploaded_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_final_outputs_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_final_outputs_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_final_outputs_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- COMPLIANCE UPLOADS
-- Alternative compliance path (skip full CPDO workflow via verified documents).
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE compliance_uploads (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id               BIGINT UNSIGNED NOT NULL,
    property_title            VARCHAR(190) NOT NULL,
    approved_resolution_path  VARCHAR(255) NULL,
    zoning_clearance_path     VARCHAR(255) NULL,
    proof_of_ownership_path   VARCHAR(255) NULL,
    status                    ENUM('PENDING_VERIFICATION','VERIFIED','REJECTED') NOT NULL DEFAULT 'PENDING_VERIFICATION',
    officer_notes             TEXT         NULL,
    reviewed_by               BIGINT UNSIGNED NULL,
    reviewed_at               DATETIME     NULL,
    created_at                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_compliance_landlord
        FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_compliance_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_compliance_status   (status),
    INDEX idx_compliance_landlord (landlord_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- PROPERTIES
-- Rental property listings. Linked to either a CPDO application or a
-- compliance upload as the compliance source.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE properties (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id          BIGINT UNSIGNED NOT NULL,
    application_id       BIGINT UNSIGNED NULL,
    compliance_upload_id BIGINT UNSIGNED NULL,
    title                VARCHAR(190)  NOT NULL,
    address              TEXT          NOT NULL,
    description          TEXT          NULL,
    monthly_rent         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status               ENUM('ACTIVE','PENDING','INACTIVE') NOT NULL DEFAULT 'PENDING',
    created_at           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_properties_landlord
        FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_properties_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_properties_compliance
        FOREIGN KEY (compliance_upload_id) REFERENCES compliance_uploads(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_properties_status      (status),
    INDEX idx_properties_landlord    (landlord_id),
    INDEX idx_properties_active_rent (status, monthly_rent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- NOTIFICATIONS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE notifications (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NULL,
    title          VARCHAR(190) NOT NULL,
    message        TEXT         NOT NULL,
    read_at        DATETIME     NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notifications_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_notifications_user_read    (user_id, read_at),
    INDEX idx_notifications_application  (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- ROLE UPGRADE REQUESTS
-- Tenant → Landlord upgrade requests reviewed by System Admin.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE role_upgrade_requests (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    from_role   ENUM('tenant')   NOT NULL DEFAULT 'tenant',
    to_role     ENUM('landlord') NOT NULL DEFAULT 'landlord',
    reason      TEXT         NULL,
    status      ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME     NULL,
    admin_notes TEXT         NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_upgrade_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_upgrade_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_upgrade_status (status),
    INDEX idx_upgrade_user   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- STAFF DESIGNATION REQUESTS
-- Self-registered CPDO employees (pending_staff) request their official role.
-- System Admin reviews and promotes them to the requested role.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE staff_designation_requests (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    requested_role ENUM('zoning_officer','admin_officer','twg_member') NOT NULL,
    status         ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    admin_notes    TEXT         NULL,
    reviewed_by    BIGINT UNSIGNED NULL,
    reviewed_at    DATETIME     NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_desig_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_desig_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_desig_status (status),
    INDEX idx_desig_user   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- AUDIT LOGS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NULL,
    action      VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80)  NULL,
    entity_id   BIGINT UNSIGNED NULL,
    ip_address  VARCHAR(64)  NULL,
    user_agent  VARCHAR(255) NULL,
    details     JSON         NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_audit_user_created   (user_id, created_at),
    INDEX idx_audit_action_created (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- VIEWS (convenience aliases)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW documents AS
    SELECT * FROM requirement_documents;

CREATE OR REPLACE VIEW evaluations AS
    SELECT id, application_id, requirement_key,
           evaluation_status, officer_notes, evaluated_by, evaluated_at
    FROM requirement_documents;

CREATE OR REPLACE VIEW payments AS
    SELECT * FROM payment_orders;

CREATE OR REPLACE VIEW decisions AS
    SELECT * FROM votes;

-- ─────────────────────────────────────────────────────────────────────────────
-- SEED DATA — Development accounts only (password for all: Test1234)
-- Remove this block before deploying to production.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO users
    (first_name, middle_name, last_name, email, password_hash, role, status, is_verified)
VALUES
    ('System',         NULL,  'Admin',   'admin@test.com',        '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'admin',          'ACTIVE', 1),
    ('Zoning',         NULL,  'Officer', 'zoning@test.com',       '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'zoning_officer', 'ACTIVE', 1),
    ('Administrative', NULL,  'Officer', 'adminofficer@test.com', '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'admin_officer',  'ACTIVE', 1),
    ('LZRC',           'TWG', 'Member',  'twg@test.com',          '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'twg_member',     'ACTIVE', 1),
    ('Demo',           NULL,  'Landlord','landlord@test.com',     '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'landlord',       'ACTIVE', 1),
    ('Demo',           NULL,  'Tenant',  'tenant@test.com',       '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'tenant',         'ACTIVE', 1);
