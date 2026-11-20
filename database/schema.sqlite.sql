-- SQLite schema, used for local evaluation and the automated test suite.
-- MySQL deployments use database/schema.sql instead.
CREATE TABLE IF NOT EXISTS merchants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  api_key TEXT NOT NULL UNIQUE,
  webhook_url TEXT,
  redirect_url TEXT,
  discord_webhook_url TEXT,
  brand_color TEXT NOT NULL DEFAULT '#2563eb',
  payment_timeout_minutes INTEGER NOT NULL DEFAULT 10,
  payment_timeout_seconds INTEGER NULL,
  api_key_last_used_at TEXT,
  status TEXT NOT NULL DEFAULT 'active',
  suspended_reason TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS merchant_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  account_label TEXT NOT NULL,
  upi_id TEXT NOT NULL,
  payee_name TEXT,
  linked_email TEXT NOT NULL,
  imap_host TEXT NOT NULL DEFAULT 'imap.gmail.com',
  imap_port INTEGER NOT NULL DEFAULT 993,
  google_app_password TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  last_checked_at TEXT,
  last_error TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_accounts_active ON merchant_accounts(merchant_id, is_active);

CREATE TABLE IF NOT EXISTS payment_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  amount NUMERIC NOT NULL,
  description TEXT,
  redirect_url TEXT,
  is_active INTEGER NOT NULL DEFAULT 1,
  expires_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id TEXT NOT NULL UNIQUE,
  merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  merchant_account_id INTEGER REFERENCES merchant_accounts(id) ON DELETE SET NULL,
  payment_link_id INTEGER REFERENCES payment_links(id) ON DELETE SET NULL,
  amount NUMERIC NOT NULL,
  payable_amount NUMERIC NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  source TEXT NOT NULL DEFAULT 'api',
  webhook_url TEXT,
  redirect_url TEXT,
  customer_name TEXT,
  customer_email TEXT,
  customer_phone TEXT,
  note TEXT,
  utr TEXT UNIQUE,
  transaction_id TEXT,
  sender_name TEXT,
  receipt_message_id TEXT,
  paid_via TEXT,
  expires_at TEXT NOT NULL,
  paid_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_order_account_match ON orders(merchant_account_id, status, payable_amount, created_at);
CREATE INDEX IF NOT EXISTS idx_order_merchant ON orders(merchant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_order_expiry ON orders(status, expires_at);

CREATE TABLE IF NOT EXISTS receipt_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  merchant_account_id INTEGER NOT NULL REFERENCES merchant_accounts(id) ON DELETE CASCADE,
  message_id TEXT,
  utr TEXT UNIQUE,
  amount NUMERIC,
  sender_name TEXT,
  order_ref TEXT,
  matched_order_id TEXT,
  state TEXT NOT NULL,
  detail TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_receipt_state ON receipt_events(merchant_account_id, state, created_at);

CREATE TABLE IF NOT EXISTS webhook_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id TEXT NOT NULL REFERENCES orders(order_id) ON DELETE CASCADE,
  event TEXT NOT NULL DEFAULT 'payment.succeeded',
  payload TEXT NOT NULL,
  signature TEXT,
  response_code INTEGER,
  attempt_count INTEGER NOT NULL DEFAULT 0,
  last_error TEXT,
  next_retry_at TEXT,
  delivered_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_webhook_retry ON webhook_logs(next_retry_at, delivered_at);

CREATE TABLE IF NOT EXISTS notification_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  order_id TEXT NOT NULL REFERENCES orders(order_id) ON DELETE CASCADE,
  channel TEXT NOT NULL,
  response_code INTEGER,
  last_error TEXT,
  delivered_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS api_rate_limits (
  rate_key TEXT PRIMARY KEY,
  window_started_at INTEGER NOT NULL,
  request_count INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS system_state (
  state_key TEXT PRIMARY KEY,
  state_value TEXT NOT NULL,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS merchant_profiles (
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
);

CREATE TABLE IF NOT EXISTS merchant_settings (
  merchant_id INTEGER PRIMARY KEY REFERENCES merchants(id) ON DELETE CASCADE,
  default_redirect_url TEXT,
  default_webhook_url TEXT,
  payment_timeout_minutes INTEGER NOT NULL DEFAULT 10,
  checkout_note TEXT,
  updated_at TEXT
);

CREATE TABLE IF NOT EXISTS merchant_addons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  addon_key TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  months INTEGER NOT NULL DEFAULT 1,
  price NUMERIC NOT NULL DEFAULT 0,
  started_at TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at TEXT
);

CREATE TABLE IF NOT EXISTS invoices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  merchant_id INTEGER NOT NULL REFERENCES merchants(id) ON DELETE CASCADE,
  invoice_no TEXT NOT NULL UNIQUE,
  description TEXT NOT NULL,
  amount NUMERIC NOT NULL,
  tax_amount NUMERIC NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'paid',
  issued_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS tickets (
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
);

CREATE TABLE IF NOT EXISTS ticket_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  author TEXT NOT NULL DEFAULT 'merchant',
  body TEXT NOT NULL,
  attachment_count INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ticket_attachments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  message_id INTEGER,
  merchant_id INTEGER NOT NULL,
  stored_name TEXT NOT NULL,
  original_name TEXT NOT NULL,
  mime TEXT NOT NULL,
  bytes INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  merchant_id INTEGER,
  action TEXT NOT NULL,
  detail TEXT,
  ip TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS status_days (
  day TEXT NOT NULL,
  component TEXT NOT NULL,
  checks INTEGER NOT NULL DEFAULT 0,
  ok INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (day, component)
);

CREATE TABLE IF NOT EXISTS api_request_activity (merchant_id INTEGER NOT NULL, activity_date TEXT NOT NULL, request_count INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (merchant_id, activity_date));
