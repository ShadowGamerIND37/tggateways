<?php
declare(strict_types=1);
/**
 * Schema migrations.
 *
 * `database/schema.sql` and `schema.sqlite.sql` only run on a fresh volume, so
 * existing deployments need an idempotent upgrade path. Each migration is a
 * list of statements applied in order; applied ids are recorded in
 * `schema_migrations` so a re-run is a no-op.
 *
 * Statements are dialect-tagged because column types and auto-increment syntax
 * differ between MySQL 8 and SQLite.
 */
require_once __DIR__.'/db.php';

/** Ordered migration list. Append only — never edit an applied migration. */
function tg_migrations(): array {
    return [
        '2026_11_18_merchant_profile' => [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS merchant_profiles (
                    merchant_id BIGINT UNSIGNED PRIMARY KEY,
                    phone VARCHAR(32) NULL,
                    support_url VARCHAR(255) NULL,
                    avatar MEDIUMTEXT NULL,
                    timezone VARCHAR(64) NOT NULL DEFAULT \'Asia/Kolkata\',
                    notify_payments TINYINT(1) NOT NULL DEFAULT 1,
                    notify_webhooks TINYINT(1) NOT NULL DEFAULT 1,
                    notify_security TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL,
                    CONSTRAINT fk_profile_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
                ) ENGINE=InnoDB',
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS merchant_profiles (
                    merchant_id INTEGER PRIMARY KEY REFERENCES merchants(id) ON DELETE CASCADE,
                    phone TEXT,
                    support_url TEXT,
                    avatar TEXT,
                    timezone TEXT NOT NULL DEFAULT 'Asia/Kolkata',
                    notify_payments INTEGER NOT NULL DEFAULT 1,
                    notify_webhooks INTEGER NOT NULL DEFAULT 1,
                    notify_security INTEGER NOT NULL DEFAULT 1,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT
                )",
            ],
        ],
        '2026_11_18_settings_and_billing' => [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS merchant_settings (
                    merchant_id BIGINT UNSIGNED PRIMARY KEY,
                    default_redirect_url VARCHAR(2048) NULL,
                    default_webhook_url VARCHAR(2048) NULL,
                    payment_timeout_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
                    checkout_note VARCHAR(255) NULL,
                    updated_at DATETIME NULL,
                    CONSTRAINT fk_settings_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
                ) ENGINE=InnoDB',
                'CREATE TABLE IF NOT EXISTS merchant_addons (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    merchant_id BIGINT UNSIGNED NOT NULL,
                    addon_key VARCHAR(40) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT \'active\',
                    months TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    price DECIMAL(10,2) NOT NULL DEFAULT 0,
                    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NULL,
                    CONSTRAINT fk_addons_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
                    INDEX idx_addons_merchant (merchant_id, status)
                ) ENGINE=InnoDB',
                'CREATE TABLE IF NOT EXISTS invoices (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    merchant_id BIGINT UNSIGNED NOT NULL,
                    invoice_no VARCHAR(40) NOT NULL UNIQUE,
                    description VARCHAR(255) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT \'paid\',
                    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_invoices_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
                    INDEX idx_invoices_merchant (merchant_id, issued_at)
                ) ENGINE=InnoDB',
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS merchant_settings (
                    merchant_id INTEGER PRIMARY KEY REFERENCES merchants(id) ON DELETE CASCADE,
                    default_redirect_url TEXT,
                    default_webhook_url TEXT,
                    payment_timeout_minutes INTEGER NOT NULL DEFAULT 10,
                    checkout_note TEXT,
                    updated_at TEXT
                )",
                "CREATE TABLE IF NOT EXISTS merchant_addons (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
                    addon_key TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'active',
                    months INTEGER NOT NULL DEFAULT 1,
                    price NUMERIC NOT NULL DEFAULT 0,
                    started_at TEXT NOT NULL DEFAULT (datetime('now')),
                    expires_at TEXT
                )",
                "CREATE TABLE IF NOT EXISTS invoices (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
                    invoice_no TEXT NOT NULL UNIQUE,
                    description TEXT NOT NULL,
                    amount NUMERIC NOT NULL,
                    tax_amount NUMERIC NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT 'paid',
                    issued_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
            ],
        ],
        '2026_11_18_support_tickets' => [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS tickets (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    merchant_id BIGINT UNSIGNED NOT NULL,
                    reference VARCHAR(40) NOT NULL UNIQUE,
                    topic VARCHAR(30) NOT NULL DEFAULT \'general\',
                    subject VARCHAR(200) NOT NULL,
                    order_ref VARCHAR(64) NULL,
                    status VARCHAR(20) NOT NULL DEFAULT \'open\',
                    last_author VARCHAR(10) NOT NULL DEFAULT \'merchant\',
                    attachments INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL,
                    CONSTRAINT fk_tickets_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
                    INDEX idx_tickets_merchant (merchant_id, status, updated_at)
                ) ENGINE=InnoDB',
                'CREATE TABLE IF NOT EXISTS ticket_messages (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ticket_id BIGINT UNSIGNED NOT NULL,
                    author VARCHAR(10) NOT NULL DEFAULT \'merchant\',
                    body TEXT NOT NULL,
                    attachment_count INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                    INDEX idx_messages_ticket (ticket_id, created_at)
                ) ENGINE=InnoDB',
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS tickets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
                    reference TEXT NOT NULL UNIQUE,
                    topic TEXT NOT NULL DEFAULT 'general',
                    subject TEXT NOT NULL,
                    order_ref TEXT,
                    status TEXT NOT NULL DEFAULT 'open',
                    last_author TEXT NOT NULL DEFAULT 'merchant',
                    attachments INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT
                )",
                "CREATE TABLE IF NOT EXISTS ticket_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
                    author TEXT NOT NULL DEFAULT 'merchant',
                    body TEXT NOT NULL,
                    attachment_count INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
            ],
        ],
        '2026_11_18_status_days' => [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS status_days (
                    day DATE NOT NULL,
                    component VARCHAR(40) NOT NULL,
                    checks INT UNSIGNED NOT NULL DEFAULT 0,
                    ok INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (day, component)
                ) ENGINE=InnoDB',
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS status_days (
                    day TEXT NOT NULL,
                    component TEXT NOT NULL,
                    checks INTEGER NOT NULL DEFAULT 0,
                    ok INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY (day, component)
                )",
            ],
        ],
        '2026_11_18_audit_and_status' => [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS audit_log (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    merchant_id BIGINT UNSIGNED NULL,
                    action VARCHAR(60) NOT NULL,
                    detail VARCHAR(255) NULL,
                    ip VARCHAR(64) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_audit_merchant (merchant_id, created_at)
                ) ENGINE=InnoDB',
                'ALTER TABLE orders ADD COLUMN paid_via VARCHAR(40) NULL',
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS audit_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    merchant_id INTEGER,
                    action TEXT NOT NULL,
                    detail TEXT,
                    ip TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                'ALTER TABLE orders ADD COLUMN paid_via TEXT',
            ],
        ],
        /* Admin panel. `admins` is deliberately a separate table from
           `merchants`: an admin is not a merchant and must never be able to
           take payments, and a merchant must never be able to become an admin
           by editing their own row. The seeded account is created on first run
           with the credential pair admin/admin and `must_change_password` set,
           so the operator is forced to rotate it at first sign-in. */
        '2026_11_18_admin_panel' => [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS admins (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL,
                    email VARCHAR(190) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    role VARCHAR(20) NOT NULL DEFAULT \'owner\',
                    status VARCHAR(20) NOT NULL DEFAULT \'active\',
                    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
                    last_login_at DATETIME NULL,
                    last_login_ip VARCHAR(64) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB',
                'CREATE TABLE IF NOT EXISTS admin_sessions (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    admin_id BIGINT UNSIGNED NOT NULL,
                    token_hash CHAR(64) NOT NULL UNIQUE,
                    ip VARCHAR(64) NULL,
                    user_agent VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen_at DATETIME NULL,
                    revoked_at DATETIME NULL,
                    INDEX idx_admin_session (admin_id, revoked_at)
                ) ENGINE=InnoDB',
                'CREATE TABLE IF NOT EXISTS admin_notes (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    merchant_id BIGINT UNSIGNED NOT NULL,
                    admin_id BIGINT UNSIGNED NULL,
                    note TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_note_merchant (merchant_id, created_at)
                ) ENGINE=InnoDB',
                'ALTER TABLE merchants ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT \'active\'',
                'ALTER TABLE merchants ADD COLUMN suspended_reason VARCHAR(255) NULL',
                'ALTER TABLE invoices ADD COLUMN paid_at DATETIME NULL',
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS admins (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    email TEXT NOT NULL UNIQUE,
                    password TEXT NOT NULL,
                    role TEXT NOT NULL DEFAULT 'owner',
                    status TEXT NOT NULL DEFAULT 'active',
                    must_change_password INTEGER NOT NULL DEFAULT 1,
                    last_login_at TEXT,
                    last_login_ip TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                "CREATE TABLE IF NOT EXISTS admin_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    admin_id INTEGER NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE,
                    ip TEXT,
                    user_agent TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    last_seen_at TEXT,
                    revoked_at TEXT
                )",
                "CREATE TABLE IF NOT EXISTS admin_notes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    merchant_id INTEGER NOT NULL,
                    admin_id INTEGER,
                    note TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                "ALTER TABLE merchants ADD COLUMN status TEXT NOT NULL DEFAULT 'active'",
                'ALTER TABLE merchants ADD COLUMN suspended_reason TEXT',
                'ALTER TABLE invoices ADD COLUMN paid_at TEXT',
            ],
        ],
        '2026_11_18_ticket_attachments' => [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS ticket_attachments (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ticket_id BIGINT UNSIGNED NOT NULL,
                    message_id BIGINT UNSIGNED NULL,
                    merchant_id BIGINT UNSIGNED NOT NULL,
                    stored_name VARCHAR(80) NOT NULL,
                    original_name VARCHAR(200) NOT NULL,
                    mime VARCHAR(100) NOT NULL,
                    bytes INT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_attach_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                    INDEX idx_attach_ticket (ticket_id)
                ) ENGINE=InnoDB',
            ],
            'sqlite' => [
                'CREATE TABLE IF NOT EXISTS ticket_attachments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
                    message_id INTEGER,
                    merchant_id INTEGER NOT NULL,
                    stored_name TEXT NOT NULL,
                    original_name TEXT NOT NULL,
                    mime TEXT NOT NULL,
                    bytes INTEGER NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
            ],
        ],
        /* Self-service password recovery.
           Until this existed the only way back into a locked account was an
           operator editing the database by hand: login.php literally told the
           user to "ask your deployment administrator". Tokens are stored as a
           SHA-256 hash, never in the clear, so a database leak cannot be
           replayed against the accounts it protects. */
        '2026_11_18_password_reset' => [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS password_resets (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    merchant_id BIGINT UNSIGNED NOT NULL,
                    token_hash CHAR(64) NOT NULL UNIQUE,
                    requested_ip VARCHAR(64) NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_reset_merchant (merchant_id, used_at)
                ) ENGINE=InnoDB',
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS password_resets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    merchant_id INTEGER NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE,
                    requested_ip TEXT,
                    expires_at TEXT NOT NULL,
                    used_at TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                'CREATE INDEX IF NOT EXISTS idx_reset_merchant ON password_resets(merchant_id, used_at)',
            ],
        ],
        /* Indexes for the two list pages that scanned a whole table on every
           load: tickets by owner+activity, invoices by owner+date. */
        '2026_11_18_list_indexes' => [
            'mysql' => [
                'CREATE INDEX idx_ticket_merchant ON tickets(merchant_id, updated_at)',
                'CREATE INDEX idx_invoice_merchant ON invoices(merchant_id, issued_at)',
            ],
            'sqlite' => [
                'CREATE INDEX IF NOT EXISTS idx_ticket_merchant ON tickets(merchant_id, updated_at)',
                'CREATE INDEX IF NOT EXISTS idx_invoice_merchant ON invoices(merchant_id, issued_at)',
            ],
        ],
        /* Idempotent order creation.
           A merchant server that times out and retries POST /api/create-order.php
           used to get a second order for one intended charge. The key is scoped
           per merchant and stores the exact response body, so a replay returns
           the original order rather than creating another one. */
        '2026_11_18_idempotency' => [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS idempotency_keys (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    merchant_id BIGINT UNSIGNED NOT NULL,
                    idem_key VARCHAR(190) NOT NULL,
                    request_hash CHAR(64) NOT NULL,
                    response_body MEDIUMTEXT NULL,
                    response_code INT NULL,
                    order_id VARCHAR(64) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_idem (merchant_id, idem_key),
                    INDEX idx_idem_created (created_at)
                ) ENGINE=InnoDB',
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS idempotency_keys (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    merchant_id INTEGER NOT NULL,
                    idem_key TEXT NOT NULL,
                    request_hash TEXT NOT NULL,
                    response_body TEXT,
                    response_code INTEGER,
                    order_id TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                'CREATE UNIQUE INDEX IF NOT EXISTS uniq_idem ON idempotency_keys(merchant_id, idem_key)',
                'CREATE INDEX IF NOT EXISTS idx_idem_created ON idempotency_keys(created_at)',
            ],
        ],
        /* Refunds.
           The gateway could take money and never give it back: no refund table,
           no status, no event. A direct-UPI merchant refunds out of band (they
           send money back from their own UPI app), so the honest thing to model
           is a *record* of that refund plus the proof, not a claim to have moved
           the money. `utr` is the refund's own bank reference. */
        '2026_11_18_refunds' => [
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS refunds (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    order_id VARCHAR(64) NOT NULL,
                    merchant_id BIGINT UNSIGNED NOT NULL,
                    refund_id VARCHAR(64) NOT NULL UNIQUE,
                    amount DECIMAL(12,2) NOT NULL,
                    reason VARCHAR(255) NULL,
                    refunded_by VARCHAR(190) NULL,
                    utr VARCHAR(64) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_refund_order (order_id),
                    INDEX idx_refund_merchant (merchant_id, created_at)
                ) ENGINE=InnoDB',
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS refunds (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    order_id TEXT NOT NULL,
                    merchant_id INTEGER NOT NULL,
                    refund_id TEXT NOT NULL UNIQUE,
                    amount NUMERIC NOT NULL,
                    reason TEXT,
                    refunded_by TEXT,
                    utr TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )",
                'CREATE INDEX IF NOT EXISTS idx_refund_order ON refunds(order_id)',
                'CREATE INDEX IF NOT EXISTS idx_refund_merchant ON refunds(merchant_id, created_at)',
            ],
        ],


        '2026_11_19_checkout_seconds_and_api_activity' => [
            'mysql' => [
                'ALTER TABLE merchants ADD COLUMN payment_timeout_seconds INT UNSIGNED NULL',
                'CREATE TABLE IF NOT EXISTS api_request_activity (merchant_id BIGINT UNSIGNED NOT NULL, activity_date DATE NOT NULL, request_count INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (merchant_id, activity_date)) ENGINE=InnoDB',
            ],
            'sqlite' => [
                'ALTER TABLE merchants ADD COLUMN payment_timeout_seconds INTEGER NULL',
                'CREATE TABLE IF NOT EXISTS api_request_activity (merchant_id INTEGER NOT NULL, activity_date TEXT NOT NULL, request_count INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (merchant_id, activity_date))',
            ],
        ],

    ];
}

/** Has a migration already been applied? */
function tg_migration_applied(PDO $pdo, string $id): bool {
    $q = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration_id=?');
    $q->execute([$id]);
    return (bool)$q->fetchColumn();
}

/**
 * Apply every pending migration.
 *
 * Returns the ids applied this run. A statement that fails for a benign reason
 * (a column or index that already exists because someone patched by hand) is
 * logged and skipped; anything else aborts so a half-applied schema is loud.
 *
 * @return array{applied:list<string>,skipped:list<string>,errors:list<string>}
 */
function tg_run_migrations(?PDO $pdo = null): array {
    $pdo = $pdo ?? db();
    $driver = db_driver();
    $out = ['applied' => [], 'skipped' => [], 'errors' => []];

    $pdo->exec($driver === 'sqlite'
        ? "CREATE TABLE IF NOT EXISTS schema_migrations (migration_id TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT (datetime('now')))"
        : 'CREATE TABLE IF NOT EXISTS schema_migrations (migration_id VARCHAR(120) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');

    foreach (tg_migrations() as $id => $statements) {
        if (tg_migration_applied($pdo, $id)) continue;
        foreach ($statements[$driver] ?? [] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                $message = $e->getMessage();
                // Idempotent-ish handling for hand-patched deployments.
                $benign = stripos($message, 'duplicate column') !== false
                    || stripos($message, 'already exists') !== false
                    || stripos($message, 'duplicate key name') !== false;
                if ($benign) {
                    $out['skipped'][] = $id.': '.substr($message, 0, 120);
                    continue;
                }
                $out['errors'][] = $id.': '.$message;
                return $out;
            }
        }
        $insert = $pdo->prepare('INSERT INTO schema_migrations(migration_id) VALUES(?)');
        try { $insert->execute([$id]); } catch (PDOException) { /* concurrent runner won the race */ }
        $out['applied'][] = $id;
    }
    return $out;
}

/** True when every migration in the list has been recorded. */
function tg_migrations_pending(): bool {
    if (!tg_table_exists('schema_migrations')) return true;
    foreach (array_keys(tg_migrations()) as $id) {
        if (!tg_migration_applied(db(), $id)) return true;
    }
    return false;
}
