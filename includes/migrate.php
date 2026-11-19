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
