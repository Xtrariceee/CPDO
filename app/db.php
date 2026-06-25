<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    global $config;

    if ($pdo instanceof PDO) {
        // Ping the connection — reconnect if it dropped (e.g. MySQL gone-away)
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (PDOException $e) {
            $pdo = null; // force reconnect below
        }
    }

    $db  = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['database'],
        $db['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // Note: persistent connections are intentionally NOT used here.
        // PDO::ATTR_PERSISTENT causes lastInsertId() to return 0 on reused
        // pool connections, which breaks any code that inserts a parent row
        // then immediately inserts child rows using the returned ID.
    ];

    // Retry up to 3 times with a short back-off (handles transient
    // "connection refused" spikes when XAMPP is under load).
    $attempts = 0;
    $lastError = null;
    while ($attempts < 3) {
        try {
            $pdo = new PDO($dsn, $db['username'], $db['password'], $options);

            // Self-healing migration for properties schema
            static $migrated = false;
            if (!$migrated) {
                $migrated = true;
                try {
                    $stmt = $pdo->prepare(
                        'SELECT COUNT(*)
                         FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = "properties"
                           AND COLUMN_NAME = ?'
                    );
                    $stmt->execute(['house_rules']);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $pdo->exec('ALTER TABLE properties ADD COLUMN house_rules TEXT NULL AFTER status');
                    }
                    $stmt->execute(['rules_accepted']);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $pdo->exec('ALTER TABLE properties ADD COLUMN rules_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER house_rules');
                    }
                    $stmt->execute(['rules_accepted_at']);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $pdo->exec('ALTER TABLE properties ADD COLUMN rules_accepted_at TIMESTAMP NULL DEFAULT NULL AFTER rules_accepted');
                    }

                    // ── RENTAL APPLICATION WORKFLOW TABLES ──────────────────
                    $pdo->exec('CREATE TABLE IF NOT EXISTS rental_applications (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        tenant_id BIGINT UNSIGNED NOT NULL,
                        property_id BIGINT UNSIGNED NOT NULL,
                        status ENUM(\'PENDING\', \'ACCEPTED\', \'DECLINED\', \'AGREED\', \'REGISTRY_FILLED\', \'DRAFT_SENT\', \'SIGNING_SCHEDULED\', \'SIGNED\', \'PAID\', \'COMPLETED\') NOT NULL DEFAULT \'PENDING\',
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

                    $pdo->exec('CREATE TABLE IF NOT EXISTS rental_application_messages (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        application_id INT UNSIGNED NOT NULL,
                        sender_id BIGINT UNSIGNED NOT NULL,
                        message TEXT NOT NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        CONSTRAINT fk_msg_application FOREIGN KEY (application_id) REFERENCES rental_applications(id) ON UPDATE CASCADE ON DELETE CASCADE,
                        CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

                    $pdo->exec('CREATE TABLE IF NOT EXISTS tenant_registries (
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

                    $pdo->exec('CREATE TABLE IF NOT EXISTS lease_agreements (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        application_id INT UNSIGNED NOT NULL,
                        monthly_rent DECIMAL(12,2) NOT NULL,
                        security_deposit DECIMAL(12,2) NOT NULL,
                        advance_payment DECIMAL(12,2) NOT NULL,
                        start_date DATE NOT NULL,
                        end_date DATE NOT NULL,
                        terms TEXT NULL,
                        status ENUM(\'DRAFT\', \'SENT\', \'SIGNED\', \'PAID\') NOT NULL DEFAULT \'DRAFT\',
                        signing_scheduled_at DATETIME NULL,
                        signing_location VARCHAR(255) NULL,
                        paymongo_checkout_id VARCHAR(120) NULL,
                        paymongo_payment_id VARCHAR(120) NULL,
                        paid_at DATETIME NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        CONSTRAINT fk_lease_application FOREIGN KEY (application_id) REFERENCES rental_applications(id) ON UPDATE CASCADE ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');

                    // Self-healing columns for rental_applications screening extensions
                    $screeningCols = [
                        'income_proof_type'           => 'VARCHAR(50) NULL DEFAULT NULL',
                        'income_proof_path_1'         => 'VARCHAR(255) NULL DEFAULT NULL',
                        'income_proof_path_2'         => 'VARCHAR(255) NULL DEFAULT NULL',
                        'employment_stability_months' => 'INT UNSIGNED NULL DEFAULT NULL',
                        'has_guarantor'               => 'TINYINT(1) NOT NULL DEFAULT 0',
                        'guarantor_name'              => 'VARCHAR(190) NULL DEFAULT NULL',
                        'guarantor_income'            => 'DECIMAL(12,2) NULL DEFAULT NULL',
                        'guarantor_income_proof_path' => 'VARCHAR(255) NULL DEFAULT NULL',
                        'guarantor_employment_status' => 'VARCHAR(50) NULL DEFAULT NULL',
                        'guarantor_stability_months'  => 'INT UNSIGNED NULL DEFAULT NULL',
                        'credit_bankruptcies'         => 'TINYINT(1) NOT NULL DEFAULT 0',
                        'credit_collections'          => 'TINYINT(1) NOT NULL DEFAULT 0',
                        'record_evictions'            => 'TINYINT(1) NOT NULL DEFAULT 0',
                        'record_illegal_activity'     => 'TINYINT(1) NOT NULL DEFAULT 0',
                        'landlord_ref_name_1'         => 'VARCHAR(190) NULL DEFAULT NULL',
                        'landlord_ref_phone_1'        => 'VARCHAR(50) NULL DEFAULT NULL',
                        'landlord_ref_name_2'         => 'VARCHAR(190) NULL DEFAULT NULL',
                        'landlord_ref_phone_2'        => 'VARCHAR(50) NULL DEFAULT NULL',
                        'ref_paid_on_time'            => 'TINYINT(1) NOT NULL DEFAULT 1',
                        'ref_clean_sanitary'          => 'TINYINT(1) NOT NULL DEFAULT 1',
                        'ref_adhered_rules'           => 'TINYINT(1) NOT NULL DEFAULT 1',
                        'ref_proper_notice'           => 'TINYINT(1) NOT NULL DEFAULT 1',
                        'requested_move_in_date'      => 'DATE NULL DEFAULT NULL',
                        'gov_id_type'                 => 'VARCHAR(50) NULL DEFAULT NULL',
                        'gov_id_number'               => 'VARCHAR(100) NULL DEFAULT NULL',
                        'gov_id_path'                 => 'VARCHAR(255) NULL DEFAULT NULL'
                    ];

                    $stmtScr = $pdo->prepare(
                        'SELECT COUNT(*)
                         FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = "rental_applications"
                           AND COLUMN_NAME = ?'
                    );

                    foreach ($screeningCols as $col => $definition) {
                        $stmtScr->execute([$col]);
                        if ((int)$stmtScr->fetchColumn() === 0) {
                            $pdo->exec("ALTER TABLE rental_applications ADD COLUMN $col $definition");
                        }
                    }

                    // Self-healing migration for notifications: add rental_application_id column
                    $stmtNotif = $pdo->prepare(
                        'SELECT COUNT(*)
                         FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = "notifications"
                           AND COLUMN_NAME = ?'
                    );
                    $stmtNotif->execute(['rental_application_id']);
                    if ((int)$stmtNotif->fetchColumn() === 0) {
                        $pdo->exec('ALTER TABLE notifications ADD COLUMN rental_application_id INT UNSIGNED NULL AFTER application_id');
                        $pdo->exec('ALTER TABLE notifications ADD CONSTRAINT fk_notifications_rental_application FOREIGN KEY (rental_application_id) REFERENCES rental_applications(id) ON UPDATE CASCADE ON DELETE CASCADE');
                    }

                    // Self-healing migration for leases: add signing_scheduled_at and signing_location columns
                    $stmtLeases = $pdo->prepare(
                        'SELECT COUNT(*)
                         FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = "leases"
                           AND COLUMN_NAME = ?'
                    );
                    $stmtLeases->execute(['signing_scheduled_at']);
                    if ((int)$stmtLeases->fetchColumn() === 0) {
                        $pdo->exec('ALTER TABLE leases ADD COLUMN signing_scheduled_at DATETIME NULL AFTER status');
                    }
                    $stmtLeases->execute(['signing_location']);
                    if ((int)$stmtLeases->fetchColumn() === 0) {
                        $pdo->exec('ALTER TABLE leases ADD COLUMN signing_location VARCHAR(255) NULL AFTER signing_scheduled_at');
                    }

                    // ── MESSAGING TABLES ────────────────────────────────────
                    $pdo->exec('CREATE TABLE IF NOT EXISTS message_threads (
                        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        landlord_id         BIGINT UNSIGNED NOT NULL,
                        tenant_id           BIGINT UNSIGNED NOT NULL,
                        property_id         BIGINT UNSIGNED NULL,
                        context_type        ENUM(\'inquiry\',\'lease\',\'maintenance\',\'broadcast\',\'general\') NOT NULL DEFAULT \'general\',
                        context_id          BIGINT UNSIGNED NULL,
                        subject             VARCHAR(255) NOT NULL DEFAULT \'Message\',
                        status              ENUM(\'open\',\'closed\',\'archived\') NOT NULL DEFAULT \'open\',
                        last_message_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        unread_landlord     INT UNSIGNED NOT NULL DEFAULT 0,
                        unread_tenant       INT UNSIGNED NOT NULL DEFAULT 0,
                        created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        CONSTRAINT fk_mthread_landlord FOREIGN KEY (landlord_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
                        CONSTRAINT fk_mthread_tenant   FOREIGN KEY (tenant_id)   REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
                        CONSTRAINT fk_mthread_property FOREIGN KEY (property_id)  REFERENCES properties(id) ON UPDATE CASCADE ON DELETE SET NULL,
                        INDEX idx_mthread_landlord_last (landlord_id, last_message_at),
                        INDEX idx_mthread_tenant_last   (tenant_id, last_message_at),
                        INDEX idx_mthread_context       (context_type, context_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                    $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
                        id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        thread_id            BIGINT UNSIGNED NOT NULL,
                        sender_id            BIGINT UNSIGNED NOT NULL,
                        body                 TEXT NULL,
                        message_type         ENUM(\'text\',\'image\',\'pdf\',\'map\',\'template\',\'system\') NOT NULL DEFAULT \'text\',
                        attachment_path      VARCHAR(500) NULL,
                        attachment_mime      VARCHAR(80)  NULL,
                        attachment_name      VARCHAR(255) NULL,
                        is_read_by_recipient TINYINT(1)   NOT NULL DEFAULT 0,
                        read_at              DATETIME     NULL,
                        created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        CONSTRAINT fk_messages_thread  FOREIGN KEY (thread_id)  REFERENCES message_threads(id) ON UPDATE CASCADE ON DELETE CASCADE,
                        CONSTRAINT fk_messages_sender  FOREIGN KEY (sender_id)  REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
                        INDEX idx_messages_thread_created (thread_id, created_at),
                        INDEX idx_messages_sender         (sender_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                    $pdo->exec('CREATE TABLE IF NOT EXISTS message_broadcasts (
                        id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        landlord_id        BIGINT UNSIGNED NOT NULL,
                        target_type        ENUM(\'all_tenants\',\'property\',\'custom\') NOT NULL DEFAULT \'all_tenants\',
                        target_property_id BIGINT UNSIGNED NULL,
                        subject            VARCHAR(255) NOT NULL,
                        body               TEXT NOT NULL,
                        attachment_path    VARCHAR(500) NULL,
                        attachment_mime    VARCHAR(80)  NULL,
                        attachment_name    VARCHAR(255) NULL,
                        recipient_count    INT UNSIGNED NOT NULL DEFAULT 0,
                        sent_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        CONSTRAINT fk_mbcast_landlord  FOREIGN KEY (landlord_id)        REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
                        CONSTRAINT fk_mbcast_property  FOREIGN KEY (target_property_id) REFERENCES properties(id) ON UPDATE CASCADE ON DELETE SET NULL,
                        INDEX idx_mbcast_landlord (landlord_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                } catch (PDOException $e) {
                    error_log('[CPDO DB MIGRATION] Failed to update properties, create rental tables, or alter notifications: ' . $e->getMessage());
                }
            }

            return $pdo;
        } catch (PDOException $e) {
            $lastError = $e;
            $attempts++;
            if ($attempts < 3) {
                usleep(150_000); // wait 150 ms before retrying
            }
        }
    }

    // All retries exhausted — log and show a friendly error page
    error_log('[CPDO DB] Connection failed after 3 attempts: ' . $lastError->getMessage());
    http_response_code(503);
    // Avoid leaking DSN details to the browser
    exit(
        '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Service Unavailable</title></head><body style="font-family:sans-serif;padding:40px;">'
        . '<h2>Database temporarily unavailable</h2>'
        . '<p>The server could not connect to the database. '
        . 'Please try again in a moment or contact the administrator.</p>'
        . '</body></html>'
    );
}

function db_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = (int)$stmt->fetchColumn() > 0;

    return $cache[$key];
}
