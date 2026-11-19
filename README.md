# TG Gateways

A self-hosted, direct-to-merchant UPI checkout application. It creates hosted UPI orders, shows UPI QR/intents, detects matching receipt emails through IMAP, records a unique UTR under a database transaction, and sends signed webhooks. **It is not a bank, PSP, payment aggregator, escrow, or substitute for an eligible merchant account.** Payments settle to the merchant's UPI account; this code does not move money.

## Run the application

Requirements: Docker Compose v2. The Compose stack runs PHP/Apache, MySQL 8.4, the QR generator, the IMAP extension, and a cron worker that polls every five seconds.

```sh
cp .env.example .env
# Generate a key and paste it as APP_ENCRYPTION_KEY in .env:
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
# Change DB_PASSWORD and MYSQL_ROOT_PASSWORD in .env before exposing anything.
docker compose up --build -d
```

Open `http://localhost:8080/register.php`, create a merchant login, then configure the receiving UPI ID and a dedicated Gmail receipt mailbox under **Integrations**. The database is initialized from `database/schema.sql` when the MySQL volume is first created. To reset a disposable local database and re-run the schema, use `docker compose down -v` (this deletes local database data).

For local HTTP development `SESSION_SECURE=false` is set in the example environment. **Before production, use HTTPS and set `SESSION_SECURE=true`, replace all sample passwords, protect `.env`, configure backups/monitoring, and review the deployment checklist below.** `APP_URL` must be set to the browser-visible base URL. The default is a sample domain, so change it for local use.

The extensionless routes in `.htaccess` require Apache `mod_rewrite`; the Docker image enables it. In Nginx, configure equivalent routes or call the explicit `.php` endpoints.

## What actually happens

1. A merchant server calls `POST /api/create-order` using its API key. The API creates a short-lived order and returns a hosted checkout, UPI intent, QR URL, and exact payable amount.
2. The customer pays directly to the merchant's configured UPI ID in their UPI app. TG Gateways never receives or holds the funds.
3. The private cron worker polls the configured mailbox over TLS. It extracts a supported INR amount and 12-digit UTR, locks a pending order for that exact receiving account and amount, and relies on a unique UTR index plus transaction locking to stop duplicate assignment.
4. On a match, the order is marked successful and a `payment.succeeded` webhook is signed using HMAC-SHA256. Failed delivery attempts are retried by the worker.
5. Unmatched receipt formats do **not** prove payment. Validate the parser against your provider's authorized test receipts; extend its patterns and operational handling before accepting live payments.

## API and dashboard

- `POST /api/create-order` (also `/api/create-order.php`): create order.
- `GET /api/checkout-status.php?order_id=…` (also `/api/public-status.php`): public display status only; do not fulfill from polling.
- `GET /api/verify-order.php?order_id=…`: authenticated merchant lookup.
- `GET /api/qr-image.php?order_id=…`: PNG when Composer's QR package is installed (the checkout renders the QR in-browser either way).
- `GET|POST /api/manage-links.php`: manage reusable links.
- `GET /api/get-transactions.php`: filtered transaction history.
- `GET /api/status.php?order_ref=…`: legacy authenticated order status.
- `GET /api/health.php`: public deployment health (no merchant data).

Merchant dashboard pages cover orders, payment links, API key rotation, webhook logs, mailbox/UPI configuration, and extra account configuration. See `/docs.php` for request samples, HMAC verification, Python/PHP SDKs, and the OpenAPI document.

## Tests

```sh
node tests/run.mjs            # uses php on PATH, or PHP_BIN=/usr/bin/php
```

The suite boots a throwaway SQLite database and executes the real endpoint files
(auth, validation, rate limits), the receipt parsing/matching rules, AES-GCM
round-trips, settlement idempotency, webhook signing and the SSRF URL policy.
MySQL remains the production database; SQLite exists so the same code is
testable without a database server.

## Production checklist — do not skip

- Confirm you are allowed to accept these payments with your UPI provider and follow its limits, business rules, and customer-dispute process. Multiple accounts are not a way to bypass provider controls.
- Test mailbox access, actual receipt templates, email delays, exact amounts, UTR parsing, duplicate receipts, concurrent orders, and refund/chargeback handling. Email notifications are not a bank settlement API and may be delayed or changed by the provider.
- Add email verification, password reset/account recovery, a proper privacy notice, data retention/deletion controls, audit trails, alerting, and operational support.
- Use a dedicated mailbox; the app password allows access to that mailbox. Protect the encryption key and database separately; rotate/revoke credentials promptly.
- Webhook destinations are restricted to HTTPS and checked for public DNS addresses, but deployments still need egress controls, monitoring, and an idempotent receiver.
- Review local privacy, tax, consumer, and payment regulations with qualified professionals. No legal/tax exemption or compliance status is implied by this project.

This repository is a functional direct-UPI application starter—not a payment aggregator and not a claim of 1:1 source-code equivalence to another service. It requires your own merchant account, credentials, hosting, and testing to process actual payments.
