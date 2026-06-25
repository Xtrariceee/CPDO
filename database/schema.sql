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
DROP TABLE IF EXISTS offenses;
DROP TABLE IF EXISTS invoice_items;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS leases;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS message_broadcasts;
DROP TABLE IF EXISTS message_threads;
DROP TABLE IF EXISTS lease_agreements;
DROP TABLE IF EXISTS tenant_registries;
DROP TABLE IF EXISTS rental_application_messages;
DROP TABLE IF EXISTS rental_applications;
DROP TABLE IF EXISTS properties;
DROP TABLE IF EXISTS compliance_uploads;
DROP TABLE IF EXISTS clearance_requests;
DROP TABLE IF EXISTS final_outputs;
DROP TABLE IF EXISTS votes;
DROP TABLE IF EXISTS meetings;
DROP TABLE IF EXISTS inspection_photos;
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
    phone_number           VARCHAR(20)  NULL,
    date_of_birth          DATE         NULL,
    gender                 ENUM('male','female','prefer_not_to_say') NULL,
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
--   vicinity_map_pdf_path, land_polygon_geojson, land_polygon_area_sqm
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
    land_polygon_geojson         MEDIUMTEXT    NULL,   -- GeoJSON polygon drawn by applicant
    land_polygon_area_sqm        DECIMAL(14,2) NULL,   -- computed area in sqm

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
-- INSPECTION PHOTOS
-- Site photos uploaded by TWG members during field inspection.
-- Stored as binary blobs; caption and category are optional metadata.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE inspection_photos (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id  BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    uploaded_by    BIGINT UNSIGNED NOT NULL,
    file_data      LONGBLOB     NOT NULL,
    file_mime      VARCHAR(80)  NOT NULL,
    file_size      INT UNSIGNED NOT NULL DEFAULT 0,
    caption        VARCHAR(255) NULL,
    category       ENUM(
                       'frontage',
                       'road_access',
                       'lot_view',
                       'adjacent_uses',
                       'existing_structures',
                       'issue_area',
                       'other'
                   ) NOT NULL DEFAULT 'other',
    uploaded_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_photos_inspection
        FOREIGN KEY (inspection_id) REFERENCES inspections(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_photos_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_photos_uploader
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_photos_inspection  (inspection_id),
    INDEX idx_photos_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- MEETINGS
-- One row per application meeting. Minutes are stored as structured plain text.
-- Meeting history (saves, PDF generations) is tracked via audit_logs with
-- action = 'TWG_MINUTES_SAVED' and entity_type = 'meetings'.
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
-- NOTE: The voting system has been superseded by the Minutes of Meeting workflow.
-- The committee decision is now derived from the TWG meeting minutes saved in
-- the meetings table. This table is retained for historical data only.
-- New applications use phase_status = 'DELIBERATION' after minutes are saved,
-- and the Zoning Officer generates the resolution directly from the minutes.
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
    resolution_data       JSON         NULL COMMENT 'Resolution field data saved by Zoning Officer; used to pre-fill AO endorsement form',
    endorsement_number    VARCHAR(80)  NULL,
    emailed_at            DATETIME     NULL COMMENT 'Timestamp when AO notified applicant via email',
    uploaded_by           BIGINT UNSIGNED NULL,
    uploaded_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_final_outputs_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_final_outputs_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE KEY uk_final_outputs_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- COMPLIANCE UPLOADS
-- Alternative compliance path (skip full CPDO workflow via verified documents).
-- property_title and property_address tie this record to a specific property
-- so the eligibility check in property_compliance_status() can scope
-- verification per address and registration intent.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE compliance_uploads (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id               BIGINT UNSIGNED NOT NULL,
    property_title            VARCHAR(190) NOT NULL,
    property_address          TEXT         NOT NULL
                                  COMMENT 'The exact address this compliance record covers; used for per-property eligibility checks.',
    approved_resolution_path  VARCHAR(255) NULL,
    zoning_clearance_path     VARCHAR(255) NULL,
    proof_of_ownership_path   VARCHAR(255) NULL,
    -- Extended document columns (added by skip-compliance v2)
    building_permit_path                    VARCHAR(500) NULL,
    certificate_of_occupancy_path           VARCHAR(500) NULL,
    barangay_business_clearance_path        VARCHAR(500) NULL,
    mayors_business_permit_path             VARCHAR(500) NULL,
    fire_safety_inspection_certificate_path VARCHAR(500) NULL,
    sanitary_permit_path                    VARCHAR(500) NULL,
    bir_registration_path                   VARCHAR(500) NULL,
    -- Landlord government ID (mandatory for commercial lessors)
    government_id_path                      VARCHAR(500) NULL,
    government_id_type                      VARCHAR(80)  NULL,
    government_id_number                    VARCHAR(120) NULL,
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
-- Monthly rent is captured later once the listing is approved, so new
-- registration may create a property with a default rent of 0.00.
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
    house_rules          TEXT          NULL,
    rules_accepted       TINYINT(1)    NOT NULL DEFAULT 0,
    rules_accepted_at    TIMESTAMP     NULL DEFAULT NULL,
    extended_details     JSON          NULL COMMENT 'Stores listing_type, property_category, payment_frequency, security_deposit, advance_rent, address_details (unit_floor, building_name, street, barangay, city_municipality, province, zip_code), floor_area, floor_area_unit, lot_size, bedrooms, bathrooms, year_built, furnishing_status, indoor_features, outdoor_features, image_gallery, video_gallery, floor_plan, virtual_tour_link',
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
    rental_application_id INT UNSIGNED NULL,
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
    CONSTRAINT fk_notifications_rental_application
        FOREIGN KEY (rental_application_id) REFERENCES rental_applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_notifications_user_read    (user_id, read_at),
    INDEX idx_notifications_application  (application_id),
    INDEX idx_notifications_rental_application (rental_application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- ROLE UPGRADE REQUESTS
-- Tenant → Landlord upgrade requests reviewed by System Admin.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE role_upgrade_requests (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               BIGINT UNSIGNED NOT NULL,
    from_role             ENUM('tenant')   NOT NULL DEFAULT 'tenant',
    to_role               ENUM('landlord') NOT NULL DEFAULT 'landlord',
    reason                TEXT         NULL,
    compliance_upload_id  BIGINT UNSIGNED NULL,
    status                ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    reviewed_by           BIGINT UNSIGNED NULL,
    reviewed_at           DATETIME     NULL,
    admin_notes           TEXT         NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_upgrade_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_upgrade_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_upgrade_compliance_upload
        FOREIGN KEY (compliance_upload_id) REFERENCES compliance_uploads(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_upgrade_status (status),
    INDEX idx_upgrade_user   (user_id),
    INDEX idx_upgrade_compliance_upload (compliance_upload_id)
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
-- CLEARANCE REQUESTS
-- Landlord-submitted requests for business clearances and certifications
-- (Building Permit, Certificate of Occupancy, Barangay Business Clearance,
--  Mayor's Permit, FSIC, Sanitary Permit, BIR Registration).
-- The Admin Officer reviews each request and, upon approval, the system
-- auto-generates a PDF certificate stored in certificate_data.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE clearance_requests (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id      BIGINT UNSIGNED NOT NULL,
    clearance_key    VARCHAR(80)     NOT NULL
                         COMMENT 'Matches catalogue key: building_permit, certificate_of_occupancy, etc.',
    title            VARCHAR(190)    NOT NULL
                         COMMENT 'Human-readable name of the clearance / permit',
    office           VARCHAR(190)    NOT NULL
                         COMMENT 'Issuing government office',
    -- Uploaded supporting document stored as binary (same pattern as requirement_documents)
    file_data        LONGBLOB        NULL,
    file_mime        VARCHAR(80)     NULL,
    original_name    VARCHAR(255)    NULL,
    -- Workflow
    status           ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    officer_notes    TEXT            NULL,
    reviewed_by      BIGINT UNSIGNED NULL,
    reviewed_at      DATETIME        NULL,
    -- Generated certificate PDF (populated by AO on approval)
    certificate_data LONGBLOB        NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_clr_landlord
        FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_clr_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_clr_landlord (landlord_id),
    INDEX idx_clr_status   (status),
    INDEX idx_clr_key      (clearance_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- RENTAL APPLICATIONS WORKFLOW
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE rental_applications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    property_id BIGINT UNSIGNED NOT NULL,
    status ENUM(
        'PENDING', 'ACCEPTED', 'DECLINED', 'AGREED',
        'REGISTRY_FILLED', 'DRAFT_SENT', 'SIGNING_SCHEDULED',
        'SIGNED', 'PAID', 'COMPLETED',
        'pending', 'approved', 'converted_to_registry'
    ) NOT NULL DEFAULT 'PENDING',
    monthly_income DECIMAL(12,2) NOT NULL,
    occupants_count INT UNSIGNED NOT NULL,
    employment_status VARCHAR(50) NOT NULL,
    pets TINYINT(1) NOT NULL DEFAULT 0,
    smoking TINYINT(1) NOT NULL DEFAULT 0,
    message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rental_app_tenant FOREIGN KEY (tenant_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_rental_app_property FOREIGN KEY (property_id) REFERENCES properties(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rental_application_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id INT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_application FOREIGN KEY (application_id) REFERENCES rental_applications(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_registries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id INT UNSIGNED NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    email VARCHAR(190) NULL,
    emergency_contact_name VARCHAR(190) NOT NULL,
    emergency_contact_phone VARCHAR(20) NOT NULL,
    occupants_details JSON NOT NULL,
    gov_id_type VARCHAR(50) NOT NULL,
    gov_id_number VARCHAR(100) NOT NULL,
    gov_id_path VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reg_application FOREIGN KEY (application_id) REFERENCES rental_applications(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lease_agreements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id INT UNSIGNED NOT NULL,
    monthly_rent DECIMAL(12,2) NOT NULL,
    security_deposit DECIMAL(12,2) NOT NULL,
    advance_payment DECIMAL(12,2) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    terms TEXT NULL,
    status ENUM('DRAFT', 'SENT', 'SIGNED', 'PAID') NOT NULL DEFAULT 'DRAFT',
    signing_scheduled_at DATETIME NULL,
    signing_location VARCHAR(255) NULL,
    paymongo_checkout_id VARCHAR(120) NULL,
    paymongo_payment_id VARCHAR(120) NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lease_application FOREIGN KEY (application_id) REFERENCES rental_applications(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- LEASES
-- Primary lease record linking tenant, property, and rental application.
-- Supersedes lease_agreements for active workflow tracking.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE leases (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id              BIGINT UNSIGNED NOT NULL,
    property_id            BIGINT UNSIGNED NOT NULL,
    rental_application_id  INT UNSIGNED NULL,
    monthly_rent           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    security_deposit       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    advance_payment        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    start_date             DATE NOT NULL,
    end_date               DATE NOT NULL,
    terms                  TEXT NULL,
    status                 ENUM('draft', 'pending_signature', 'awaiting_initial_payment', 'active') NOT NULL DEFAULT 'draft',
    signing_scheduled_at   DATETIME NULL,
    signing_location       VARCHAR(255) NULL,
    signed_at              DATETIME NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_leases_tenant
        FOREIGN KEY (tenant_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_leases_property
        FOREIGN KEY (property_id) REFERENCES properties(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_leases_rental_app
        FOREIGN KEY (rental_application_id) REFERENCES rental_applications(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_leases_status   (status),
    INDEX idx_leases_tenant   (tenant_id),
    INDEX idx_leases_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- INVOICES
-- Monthly billing cycle records linked to a lease.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE invoices (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lease_id     BIGINT UNSIGNED NOT NULL,
    tenant_id    BIGINT UNSIGNED NOT NULL,
    billing_date DATE NOT NULL COMMENT 'Start date of the billing month (e.g. 2026-07-01)',
    due_date     DATE NOT NULL,
    status       ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid',
    paid_at      DATETIME NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoices_lease
        FOREIGN KEY (lease_id) REFERENCES leases(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_invoices_tenant
        FOREIGN KEY (tenant_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_invoices_status      (status),
    INDEX idx_invoices_tenant      (tenant_id),
    INDEX idx_invoices_lease_status (lease_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- INVOICE ITEMS
-- Itemized line entries for each invoice (rent, utilities, fines, etc.).
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE invoice_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id  BIGINT UNSIGNED NOT NULL,
    type        ENUM('move_in_fees', 'monthly_rent', 'utilities', 'offense_fines') NOT NULL,
    amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    description VARCHAR(255) NOT NULL COMMENT 'Breakdown label details',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoice_items_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_invoice_items_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- OFFENSES
-- Tracks tenant rules violations and associated fines.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE offenses (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    violation_details TEXT NOT NULL,
    fine_amount       DECIMAL(12,2) NULL,
    offense_date      DATETIME NOT NULL,
    billed_item_id    BIGINT UNSIGNED NULL COMMENT 'Referenced once billed to tenant in invoice_items',
    status            ENUM('pending_billing', 'billed', 'waived') NOT NULL DEFAULT 'pending_billing',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_offenses_tenant
        FOREIGN KEY (tenant_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_offenses_billed_item
        FOREIGN KEY (billed_item_id) REFERENCES invoice_items(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_offenses_tenant (tenant_id),
    INDEX idx_offenses_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- MESSAGE THREADS
-- Unified container for all landlord↔tenant conversations.
-- context_type scopes the thread to an inquiry, lease, maintenance, or broadcast.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE message_threads (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id         BIGINT UNSIGNED NOT NULL,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    property_id         BIGINT UNSIGNED NULL,
    context_type        ENUM('inquiry','lease','maintenance','broadcast','general') NOT NULL DEFAULT 'general',
    context_id          BIGINT UNSIGNED NULL COMMENT 'rental_application_id or lease_id depending on context_type',
    subject             VARCHAR(255) NOT NULL DEFAULT 'Message',
    status              ENUM('open','closed','archived') NOT NULL DEFAULT 'open',
    last_message_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    unread_landlord     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unread count for landlord',
    unread_tenant       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unread count for tenant',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mthread_landlord
        FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mthread_tenant
        FOREIGN KEY (tenant_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mthread_property
        FOREIGN KEY (property_id) REFERENCES properties(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_mthread_landlord_last  (landlord_id, last_message_at),
    INDEX idx_mthread_tenant_last    (tenant_id, last_message_at),
    INDEX idx_mthread_context        (context_type, context_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- MESSAGES
-- Individual messages within a thread. Supports text, rich media attachments,
-- quick-reply templates, and read receipts.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE messages (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id                BIGINT UNSIGNED NOT NULL,
    sender_id                BIGINT UNSIGNED NOT NULL,
    body                     TEXT NULL,
    message_type             ENUM('text','image','pdf','map','template','system') NOT NULL DEFAULT 'text',
    attachment_path          VARCHAR(500) NULL,
    attachment_mime          VARCHAR(80)  NULL,
    attachment_name          VARCHAR(255) NULL,
    is_read_by_recipient     TINYINT(1)   NOT NULL DEFAULT 0,
    read_at                  DATETIME     NULL,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_messages_thread
        FOREIGN KEY (thread_id) REFERENCES message_threads(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender
        FOREIGN KEY (sender_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_messages_thread_created (thread_id, created_at),
    INDEX idx_messages_sender         (sender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- MESSAGE BROADCASTS
-- Landlord mass-notice records. Fan-out to individual threads is handled
-- in PHP on send; this table tracks the broadcast metadata.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE message_broadcasts (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id           BIGINT UNSIGNED NOT NULL,
    target_type           ENUM('all_tenants','property','custom') NOT NULL DEFAULT 'all_tenants',
    target_property_id    BIGINT UNSIGNED NULL,
    subject               VARCHAR(255) NOT NULL,
    body                  TEXT NOT NULL,
    attachment_path       VARCHAR(500) NULL,
    attachment_mime       VARCHAR(80)  NULL,
    attachment_name       VARCHAR(255) NULL,
    recipient_count       INT UNSIGNED NOT NULL DEFAULT 0,
    sent_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mbcast_landlord
        FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mbcast_property
        FOREIGN KEY (target_property_id) REFERENCES properties(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_mbcast_landlord (landlord_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- SEED DATA — Development accounts only (password for all: Test1234)
-- Remove this block before deploying to production.
-- ─────────────────────────────────────────────────────────────────────────────
-- Insert requirement guideline defaults for landlord/property eligibility
INSERT INTO requirement_guidelines (requirement_key, group_name, title, details, is_active)
VALUES
    ('mayors_permit', 'Local Government', 'Mayor''s / Business Permit', 'Mandatory for commercial landlords. Upload a clear scanned copy of the Mayor''s or Business Permit issued by the LGU.', 1),
    ('barangay_business_clearance', 'Barangay', 'Barangay Business Clearance', 'Mandatory supporting document. Upload the Barangay Business Clearance for the business address.', 1),
    ('bir_registration', 'BIR', 'BIR Registration (Form 2303)', 'Mandatory. Upload the BIR Registration (Form 2303) to prove tax registration and ability to issue official receipts.', 1),
    ('government_id', 'Applicant', 'Government-issued Primary ID', 'Mandatory. Upload a primary government ID and enter the ID type and number. Accepted IDs: Philippine Passport, UMID, Driver''s License, SSS ID, GSIS ID, Voter''s ID, PRC ID, PhilHealth ID.', 1),
    ('building_permit', 'Building Office', 'Building Permit', 'Mandatory. Upload the building permit proving construction was approved by the local building official.', 1),
    ('certificate_of_occupancy', 'Building Office', 'Certificate of Occupancy', 'MANDATORY. Properties cannot be listed without a verified Certificate of Occupancy.', 1),
    ('fire_safety_inspection_certificate', 'BFP', 'Fire Safety Inspection Certificate (FSIC)', 'MANDATORY. Upload the latest annual FSIC issued by the Bureau of Fire Protection.', 1),
    ('sanitary_permit', 'City Health Office', 'Sanitary Permit', 'Sanitary permit confirming the property meets local health and sanitation requirements. Enforcement can be toggled by Zoning Officer.', 1);

INSERT INTO users
    (first_name, middle_name, last_name, email, password_hash, role, status, is_verified)
VALUES
    ('System',         NULL,  'Admin',   'admin@test.com',        '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'admin',          'ACTIVE', 1),
    ('Zoning',         NULL,  'Officer', 'zoning@test.com',       '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'zoning_officer', 'ACTIVE', 1),
    ('Administrative', NULL,  'Officer', 'adminofficer@test.com', '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'admin_officer',  'ACTIVE', 1),
    ('LZRC',           'TWG', 'Member',  'twg@test.com',          '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'twg_member',     'ACTIVE', 1),
    ('Demo',           NULL,  'Landlord','landlord@test.com',     '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'landlord',       'ACTIVE', 1),
    ('Demo',           NULL,  'Tenant',  'tenant@test.com',       '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'tenant',         'ACTIVE', 1);
