-- TG Gateways / MySQL 8 schema.
-- Fresh install: `mysql -u root -p < database/schema.sql`
-- The Docker Compose stack applies this automatically on first boot.
CREATE DATABASE IF NOT EXISTS tggateways CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tggateways;

CREATE TABLE IF NOT EXISTS merchants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  api_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  webhook_url VARCHAR(2048) NULL,
  redirect_url VARCHAR(2048) NULL,
  discord_webhook_url VARCHAR(2048) NULL,
  brand_color CHAR(7) NOT NULL DEFAULT '#2563eb',
  payment_timeout_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  api_key_last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS merchant_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id BIGINT UNSIGNED NOT NULL,
  account_label VARCHAR(100) NOT NULL,
  upi_id VARCHAR(190) NOT NULL,
  payee_name VARCHAR(120) NULL,
  linked_email VARCHAR(190) NOT NULL,
  imap_host VARCHAR(190) NOT NULL DEFAULT 'imap.gmail.com',
  imap_port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
  google_app_password TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_checked_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_accounts_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
  INDEX idx_accounts_active (merchant_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,
  title VARCHAR(160) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  description TEXT NULL,
  redirect_url VARCHAR(2048) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_links_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
  INDEX idx_links_merchant (merchant_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(40) NOT NULL UNIQUE,
  merchant_id BIGINT UNSIGNED NOT NULL,
  merchant_account_id BIGINT UNSIGNED NULL,
  payment_link_id BIGINT UNSIGNED NULL,
  amount DECIMAL(10,2) NOT NULL,
  payable_amount DECIMAL(10,2) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  source VARCHAR(16) NOT NULL DEFAULT 'api',
  webhook_url VARCHAR(2048) NULL,
  redirect_url VARCHAR(2048) NULL,
  customer_name VARCHAR(120) NULL,
  customer_email VARCHAR(190) NULL,
  customer_phone VARCHAR(32) NULL,
  note VARCHAR(255) NULL,
  utr VARCHAR(64) NULL UNIQUE,
  transaction_id VARCHAR(120) NULL,
  sender_name VARCHAR(120) NULL,
  receipt_message_id VARCHAR(255) NULL,
  paid_via VARCHAR(40) NULL,
  expires_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
  CONSTRAINT fk_orders_account FOREIGN KEY (merchant_account_id) REFERENCES merchant_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_link FOREIGN KEY (payment_link_id) REFERENCES payment_links(id) ON DELETE SET NULL,
  INDEX idx_order_account_match (merchant_account_id, status, payable_amount, created_at),
  INDEX idx_order_merchant (merchant_id, created_at),
  INDEX idx_order_expiry (status, expires_at)
) ENGINE=InnoDB;

-- One row per receipt email parsed by the worker. Keeps unmatched/ambiguous
-- payments visible instead of silently discarding them.
CREATE TABLE IF NOT EXISTS receipt_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id BIGINT UNSIGNED NOT NULL,
  merchant_account_id BIGINT UNSIGNED NOT NULL,
  message_id VARCHAR(255) NULL,
  utr VARCHAR(64) NULL,
  amount DECIMAL(10,2) NULL,
  sender_name VARCHAR(120) NULL,
  order_ref VARCHAR(64) NULL,
  matched_order_id VARCHAR(40) NULL,
  state VARCHAR(16) NOT NULL,
  detail VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_receipt_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
  CONSTRAINT fk_receipt_account FOREIGN KEY (merchant_account_id) REFERENCES merchant_accounts(id) ON DELETE CASCADE,
  UNIQUE KEY uq_receipt_utr (utr),
  INDEX idx_receipt_state (merchant_account_id, state, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS webhook_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(40) NOT NULL,
  event VARCHAR(40) NOT NULL DEFAULT 'payment.succeeded',
  payload MEDIUMTEXT NOT NULL,
  signature VARCHAR(128) NULL,
  response_code INT NULL,
  attempt_count INT NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  next_retry_at DATETIME NULL,
  delivered_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_webhook_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  INDEX idx_webhook_retry (next_retry_at, delivered_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id BIGINT UNSIGNED NOT NULL,
  order_id VARCHAR(40) NOT NULL,
  channel VARCHAR(24) NOT NULL,
  response_code INT NULL,
  last_error VARCHAR(255) NULL,
  delivered_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notification_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  INDEX idx_notification_order (order_id, channel)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_rate_limits (
  rate_key CHAR(64) PRIMARY KEY,
  window_started_at INT UNSIGNED NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_rate_window (window_started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_state (
  state_key VARCHAR(64) PRIMARY KEY,
  state_value VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS merchant_profiles (
  merchant_id BIGINT UNSIGNED PRIMARY KEY,
  phone VARCHAR(32) NULL,
  support_url VARCHAR(255) NULL,
  avatar MEDIUMTEXT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
  notify_payments TINYINT(1) NOT NULL DEFAULT 1,
  notify_webhooks TINYINT(1) NOT NULL DEFAULT 1,
  notify_security TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  CONSTRAINT fk_profile_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS merchant_settings (
  merchant_id BIGINT UNSIGNED PRIMARY KEY,
  default_redirect_url VARCHAR(2048) NULL,
  default_webhook_url VARCHAR(2048) NULL,
  payment_timeout_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  checkout_note VARCHAR(255) NULL,
  updated_at DATETIME NULL,
  CONSTRAINT fk_settings_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS merchant_addons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id BIGINT UNSIGNED NOT NULL,
  addon_key VARCHAR(40) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  months TINYINT UNSIGNED NOT NULL DEFAULT 1,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  CONSTRAINT fk_addons_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
  INDEX idx_addons_merchant (merchant_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id BIGINT UNSIGNED NOT NULL,
  invoice_no VARCHAR(40) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'paid',
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invoices_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
  INDEX idx_invoices_merchant (merchant_id, issued_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id BIGINT UNSIGNED NOT NULL,
  reference VARCHAR(40) NOT NULL UNIQUE,
  topic VARCHAR(30) NOT NULL DEFAULT 'general',
  subject VARCHAR(200) NOT NULL,
  order_ref VARCHAR(64) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  last_author VARCHAR(10) NOT NULL DEFAULT 'merchant',
  attachments INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  CONSTRAINT fk_tickets_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
  INDEX idx_tickets_merchant (merchant_id, status, updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED NOT NULL,
  author VARCHAR(10) NOT NULL DEFAULT 'merchant',
  body TEXT NOT NULL,
  attachment_count INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  INDEX idx_messages_ticket (ticket_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id BIGINT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(255) NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_merchant (merchant_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS schema_migrations (
  migration_id VARCHAR(120) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS status_days (
  day DATE NOT NULL,
  component VARCHAR(40) NOT NULL,
  checks INT UNSIGNED NOT NULL DEFAULT 0,
  ok INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, component)
) ENGINE=InnoDB;
