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
  api_key_last_used_at TEXT,
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
