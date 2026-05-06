-- ═══════════════════════════════════════════════════════════════
-- CPDO Land Reclassification Portal — Database Schema
-- Run this file on a fresh database. All tables are dropped and
-- recreated. Existing data will be lost.
-- ═══════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS land_reclassification
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE land_reclassification;

SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW IF EXISTS decisions;
DROP VIEW IF EXISTS payments;
DROP VIEW IF EXISTS evaluations;
DROP VIEW IF EXISTS documents;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS role_upgrade_requests;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS properties;
DROP TABLE IF EXISTS compliance_uploads;
DROP TABLE IF EXISTS final_outputs;
DROP TABLE IF EXISTS votes;
DROP TABLE IF EXISTS meetings;
DROP TABLE IF EXISTS inspections;
DROP TABLE IF EXISTS payment_orders;
DROP TABLE IF EXISTS requirement_documents;
DROP TABLE IF EXISTS applications;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    google_id VARCHAR(190) NULL UNIQUE,
    role ENUM('admin','zoning_officer','admin_officer','twg_member','landlord','tenant') NOT NULL DEFAULT 'landlord',
    -- google_registered_role: the role the user selected when registering via Google
    -- NULL means Google account was auto-created (sign-in only, no explicit registration)
    google_registered_role ENUM('landlord','tenant') NULL DEFAULT NULL,
    status ENUM('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
    otp_code VARCHAR(6) NULL,
    otp_expiry DATETIME NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_status (role, status),
    INDEX idx_users_verified (is_verified),
    INDEX idx_users_name (last_name, first_name)
) ENGINE=InnoDB;

CREATE TABLE applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id BIGINT UNSIGNED NOT NULL,
    registry_number VARCHAR(40) NOT NULL UNIQUE,
    account_name VARCHAR(160) NOT NULL,
    account_address TEXT NOT NULL,
    property_title VARCHAR(190) NOT NULL,
    property_address TEXT NOT NULL,
    coordinates VARCHAR(120) NULL,
    status ENUM(
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
    sensitive_land_title_enc TEXT NULL,
    sensitive_land_title_nonce VARCHAR(32) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_applications_landlord
        FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    INDEX idx_applications_status (status),
    INDEX idx_applications_phase_status (phase_status),
    INDEX idx_applications_landlord (landlord_id)
) ENGINE=InnoDB;

-- requirement_documents: 19 mandatory documents for Process 1 (Land Reclassification)
-- group_name = issuing office / where to secure the document
CREATE TABLE requirement_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    requirement_key VARCHAR(80) NOT NULL,
    group_name VARCHAR(120) NOT NULL,
    title VARCHAR(255) NOT NULL,
    -- file stored in DB as base64-encoded binary; file_path kept for legacy/fallback
    file_path VARCHAR(255) NULL,
    file_data LONGBLOB NULL,
    file_mime VARCHAR(80) NULL,
    original_name_enc TEXT NULL,
    original_name_nonce VARCHAR(32) NULL,
    evaluation_status ENUM('PENDING','PASSED','FAILED') NOT NULL DEFAULT 'PENDING',
    officer_notes TEXT NULL,
    evaluated_by BIGINT UNSIGNED NULL,
    evaluated_at DATETIME NULL,
    uploaded_at DATETIME NULL,
    UNIQUE KEY app_requirement (application_id, requirement_key),
    CONSTRAINT fk_documents_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_documents_evaluator
        FOREIGN KEY (evaluated_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    INDEX idx_documents_eval_status (evaluation_status),
    INDEX idx_documents_application (application_id)
) ENGINE=InnoDB;

CREATE TABLE payment_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    op_number VARCHAR(40) NOT NULL UNIQUE,
    registry_number VARCHAR(40) NOT NULL,
    account_code VARCHAR(30) NOT NULL DEFAULT '4-02-01-020-8-6',
    service_fee DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
    status ENUM('PENDING','PAID','FAILED') NOT NULL DEFAULT 'PENDING',
    paymongo_checkout_id VARCHAR(120) NULL,
    receipt_number VARCHAR(60) NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    INDEX idx_payment_status (status)
) ENGINE=InnoDB;

CREATE TABLE inspections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    scheduled_at DATETIME NULL,
    assigned_by BIGINT UNSIGNED NULL,
    findings TEXT NULL,
    site_plan_valid TINYINT(1) NOT NULL DEFAULT 0,
    coordinates_valid TINYINT(1) NOT NULL DEFAULT 0,
    finalized_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inspections_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_inspections_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    INDEX idx_inspections_schedule (scheduled_at)
) ENGINE=InnoDB;

CREATE TABLE meetings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    scheduled_at DATETIME NULL,
    minutes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_meetings_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_meetings_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE votes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    twg_member_id BIGINT UNSIGNED NOT NULL,
    vote ENUM('APPROVED','DISAPPROVED','DEFERRED') NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY app_member_vote (application_id, twg_member_id),
    CONSTRAINT fk_votes_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_votes_twg_member
        FOREIGN KEY (twg_member_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE final_outputs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    signature_file_path VARCHAR(255) NULL,
    resolution_file_path VARCHAR(255) NULL,
    endorsement_number VARCHAR(80) NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_final_outputs_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_final_outputs_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE compliance_uploads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id BIGINT UNSIGNED NOT NULL,
    property_title VARCHAR(190) NOT NULL,
    approved_resolution_path VARCHAR(255) NULL,
    zoning_clearance_path VARCHAR(255) NULL,
    proof_of_ownership_path VARCHAR(255) NULL,
    status ENUM('PENDING_VERIFICATION','VERIFIED','REJECTED') NOT NULL DEFAULT 'PENDING_VERIFICATION',
    officer_notes TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_compliance_landlord
        FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_compliance_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    INDEX idx_compliance_status (status)
) ENGINE=InnoDB;

CREATE TABLE properties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NULL,
    compliance_upload_id BIGINT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    address TEXT NOT NULL,
    description TEXT NULL,
    monthly_rent DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('ACTIVE','PENDING','INACTIVE') NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_properties_landlord
        FOREIGN KEY (landlord_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_properties_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_properties_compliance
        FOREIGN KEY (compliance_upload_id) REFERENCES compliance_uploads(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    INDEX idx_properties_status (status),
    INDEX idx_properties_landlord (landlord_id),
    INDEX idx_properties_active_rent (status, monthly_rent)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_notifications_application
        FOREIGN KEY (application_id) REFERENCES applications(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    INDEX idx_notifications_user_read (user_id, read_at)
) ENGINE=InnoDB;

-- role_upgrade_requests: tenant requests to become a landlord
CREATE TABLE role_upgrade_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    from_role ENUM('tenant') NOT NULL DEFAULT 'tenant',
    to_role ENUM('landlord') NOT NULL DEFAULT 'landlord',
    reason TEXT NULL,
    status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    admin_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_upgrade_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_upgrade_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    INDEX idx_upgrade_status (status),
    INDEX idx_upgrade_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    details JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    INDEX idx_audit_user_created (user_id, created_at),
    INDEX idx_audit_action_created (action, created_at)
) ENGINE=InnoDB;

CREATE OR REPLACE VIEW documents AS
SELECT * FROM requirement_documents;

CREATE OR REPLACE VIEW evaluations AS
SELECT
    id,
    application_id,
    requirement_key,
    evaluation_status,
    officer_notes,
    evaluated_by,
    evaluated_at
FROM requirement_documents;

CREATE OR REPLACE VIEW payments AS
SELECT * FROM payment_orders;

CREATE OR REPLACE VIEW decisions AS
SELECT * FROM votes;

INSERT INTO users (first_name, middle_name, last_name, email, password_hash, role, status, is_verified) VALUES
('System', NULL, 'Admin', 'admin@test.com', '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'admin', 'ACTIVE', 1),
('Zoning', NULL, 'Officer', 'zoning@test.com', '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'zoning_officer', 'ACTIVE', 1),
('Administrative', NULL, 'Officer', 'adminofficer@test.com', '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'admin_officer', 'ACTIVE', 1),
('LZRC', 'TWG', 'Member', 'twg@test.com', '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'twg_member', 'ACTIVE', 1),
('Demo', NULL, 'Landlord', 'landlord@test.com', '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'landlord', 'ACTIVE', 1),
('Demo', NULL, 'Tenant', 'tenant@test.com', '$2y$10$A7mmOibGA9VRajydnxbqwOuCVvycv0mbEVDmU54s9EC4ULaSe2D16', 'tenant', 'ACTIVE', 1);
