# TG Gateways

A self-hosted, direct-to-merchant UPI checkout application. It creates hosted UPI orders, shows UPI QR/intents, detects matching receipt emails through IMAP, records a unique UTR under a database transaction, and sends signed webhooks. **It is not a bank, PSP, payment aggregator, escrow, or substitute for an eligible merchant account.** Payments settle to the merchant's UPI account; this code does not move money.

---

## 1. What you need

| Requirement | Notes |
| --- | --- |
| Docker Engine + Compose v2 | Recommended path. `docker compose version` must work. |
| A UPI ID you are allowed to collect on | Personal or business; confirm the provider's terms and limits. |
| A dedicated mailbox that receives UPI receipts | Gmail recommended. Needs IMAP enabled and, for Gmail, a 16-character app password (requires 2-Step Verification). |
| A public HTTPS hostname for production | Checkout links and payment links are shared with customers, and webhooks must reach you over HTTPS. `localhost` is fine while you evaluate. |
| Optional: PHP 8.1+, Node 18+ | Only for running the test suite without Docker. |

There are two supported setups: **Docker Compose** (below) and a **manual Apache/Nginx install** (§5).

---

## 2. Quick start with Docker Compose

```sh
git clone https://github.com/ShadowGamerIND37/tggateways.git
cd tggateways

cp .env.example .env

# 1. Encryption key: 64 hex characters. MySQL credentials can never be read back without it.
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
#    (no PHP locally? use: openssl rand -hex 32)

# 2. Edit .env and set at least:
#    APP_ENCRYPTION_KEY=<the 64 characters you just generated>
#    DB_PASSWORD=<a long random password>
#    MYSQL_ROOT_PASSWORD=<another long random password>
#    APP_URL=http://localhost:8080      # must match what the browser uses
#    SESSION_SECURE=false               # only while testing on http://localhost
$EDITOR .env

docker compose up --build -d
docker compose ps            # web, database and cron should become healthy
```

Open **http://localhost:8080/register.php** and create your merchant account.

What the stack starts:

| Service | Role |
| --- | --- |
| `web` | PHP 8.3 + Apache with `pdo_mysql`, `curl`, `gd`, `imap`, `mod_rewrite`. Serves the app. |
| `database` | MySQL 8.4. Loads `database/schema.sql` automatically the first time the volume is created. |
| `cron` | Runs `cron/imap-parser.php` every 5 seconds: expires stale orders, reads new receipt mail, retries failed webhooks. |

Check the deployment at any time:

```sh
curl -s http://localhost:8080/api/health.php | python3 -m json.tool
docker compose logs -f cron      # worker output, one JSON line per pass
```

`/api/health.php` is public and returns component states only. `database: operational`
means the schema is loaded and reachable. `receipt_parser` reports
`configuration_required`, `no_active_account`, `worker_stale` or `operational` — it is
`operational` only after the worker has completed a successful pass within the last two
minutes, which is the real end-to-end proof that receipt polling works.

---

## 3. First-run checklist (10 minutes)

1. **Create the merchant login** at `/register.php` (12-character password minimum). You land on **Integrations**.
2. **Add your receiving account**: account label, UPI ID (`name@bank`), receipt mailbox address, IMAP host (`imap.gmail.com`, port `993`) and the app password. Save.
3. **Test the mailbox** with *Test mailbox connection*. It opens the mailbox read-only and reports the message count; it does not consume any mail.
4. **Create a payment link** on **Payment Links**, open the `…/pay.php?link=…` URL in a private window, and scan the QR with a UPI app — **use ₹1 and pay yourself**.
5. Watch the payment appear: the checkout page polls `/api/checkout-status.php`, and within one worker cycle (≈5 s plus the provider's email delay) the order flips to paid. Verify in **Transactions**.
6. **Set your webhook endpoint** on **Webhooks** if you are integrating a backend. The signature is `sha256=HMAC_SHA256(rawBody, api_key)` in `X-TGGateway-Signature`.
7. Confirm `/api/health.php` reports `receipt_parser: operational`.

A fresh deployment **starts reading the mailbox from the newest message present**. Mail
that was already in the inbox is deliberately ignored so a first import cannot mark
historical orders as paid. Anything that arrives after that is processed even if the
provider (or your phone) already marked it read.

---

## 4. Day-to-day commands

```sh
docker compose logs -f web                       # request log
docker compose logs -f cron                      # receipt matching + webhook retries
docker compose restart cron                       # after editing integrations
docker compose down                               # stop, keep data
docker compose down -v                            # stop and DELETE the database volume
docker compose up --build -d                      # rebuild after pulling changes

# Run one worker pass by hand (useful while debugging a mailbox):
docker compose exec cron php /var/www/html/cron/imap-parser.php
```

Back up the two things you cannot regenerate: the MySQL volume and `APP_ENCRYPTION_KEY`
(in `.env`). Without the key, stored mailbox passwords cannot be decrypted.

```sh
docker compose exec database \
  sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" tggateways' > backup-$(date +%F).sql
```

---

## 5. Manual installation (no Docker)

**Requirements:** PHP 8.1+ with `pdo_mysql`, `curl`, `openssl`, `mbstring`, `gd` and
`imap`; MySQL 8 (or MariaDB 10.6+); Composer 2; Apache with `mod_rewrite`/`mod_headers`,
or Nginx.

```sh
sudo apt install php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring \
     php8.3-gd php8.3-imap composer apache2 libapache2-mod-php8.3 mysql-server
sudo a2enmod rewrite headers

git clone https://github.com/ShadowGamerIND37/tggateways.git /var/www/tggateways
cd /var/www/tggateways
composer install --no-dev --optimize-autoloader

mysql -u root -p -e "CREATE DATABASE tggateways CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'tggateways'@'localhost' IDENTIFIED BY 'REPLACE_ME';
  GRANT ALL ON tggateways.* TO 'tggateways'@'localhost'; FLUSH PRIVILEGES;"
mysql -u tggateways -p tggateways < database/schema.sql

cp .env.example .env && $EDITOR .env
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # APP_ENCRYPTION_KEY
chmod 600 .env
chown -R www-data:www-data /var/www/tggateways
```

Point the virtual host's `DocumentRoot` at the repository root (the included
`.htaccess` sets rewrite and access rules). Apache is enough; Nginx needs the
equivalent `try_files`/`fastcgi` block and must deny `/includes`, `/database`, `/cron`,
`/sdk`, `/examples`, `.env` and `*.sql`.

Run the worker from cron (or systemd timer or supervisor) as `www-data`:

```sh
* * * * * www-data cd /var/www/tggateways && for i in $(seq 1 12); do php cron/imap-parser.php >/dev/null 2>&1; sleep 5; done
```

That runs twelve passes a minute, matching the Compose worker. If your email volume is
low, once a minute is also valid — receipts are picked up whenever the worker next runs,
and the checkout page keeps showing *pending* until then.

Set a real `APP_URL`, use HTTPS, and put `SESSION_SECURE=true` in `.env` once you are on
TLS.

---

## 6. What actually happens

1. A merchant server calls `POST /api/create-order` with its API key. The API creates a short-lived order and returns a hosted checkout URL, UPI intent, QR URL and the exact payable amount.
2. The customer pays directly to the merchant's configured UPI ID in their UPI app. TG Gateways never receives or holds the funds.
3. The worker polls the configured mailbox over TLS and reads messages newer than its saved UID watermark — including ones already marked read. It extracts an INR amount and a 12-digit UTR, then looks for a pending order **on the same receiving account**, with **the same amount**, **created no later than the receipt**, and not expired.
4. The match is claimed inside a database transaction; a unique index on the UTR makes a second claim impossible, so a duplicate receipt can never pay a second order.
5. On success the order is marked paid and `payment.succeeded` is signed with HMAC-SHA256 and delivered. Failures retry with 5, 10, 20, 40 and 80 minute backoff.
6. Unmatched or unparseable receipts are still written to `receipt_events` for audit, so you can see *why* something was not matched. They never prove payment.

If a receipt carries a `tg_…` order reference in its remark (the checkout inserts it),
only that order can be claimed. When an amount alone matches several pending orders, the
oldest is claimed and the event is flagged `ambiguous` for review.

---

## 7. API and dashboard

- `POST /api/create-order` (also `/api/create-order.php`): create order.
- `GET /api/checkout-status.php?order_id=…`: public display status only; do not fulfil from polling.
- `GET /api/verify-order.php?order_id=…`: authenticated merchant lookup (the fulfilment authority).
- `GET /api/qr-image.php?order_id=…`: PNG when Composer's QR package is installed (the checkout renders the QR in-browser either way).
- `GET|POST /api/manage-links.php`: manage reusable payment links.
- `GET /api/get-transactions.php`: filtered transaction history.
- `GET /api/status.php?order_ref=…`: legacy authenticated order status.
- `GET /api/health.php`: public deployment health (no merchant data).

Merchant dashboard pages cover orders, payment links, API key rotation, webhook logs and
endpoint health, mailbox/UPI configuration and additional accounts. `/docs.php` has
request samples, HMAC verification, the Python and PHP SDKs, and links `openapi.json`.

---

## 8. Tests

```sh
node tests/run.mjs                 # auto-detects php, then a php-wasm CLI
PHP_BIN=/usr/bin/php node tests/run.mjs
```

116 checks run against a throwaway SQLite database: receipt parsing/matching, AES-GCM
round-trips, auth and CSRF, input validation, rate limits, order creation, status and
verification endpoints, the hosted checkout page, settlement idempotency (matched →
duplicate → unmatched → unparsed), worker housekeeping, webhook signing/queueing/retry
and the SSRF URL policy. MySQL remains the production database; SQLite exists so the
same code is testable without a database server.

Webhook delivery is tested through `tests/webhook-transport.php`, which records the exact
outgoing request instead of using the network, so signing and retry logic can be asserted
deterministically. Production delivery always uses the HTTPS-only, DNS-pinned transport.

---

## 9. Production checklist — do not skip

- Confirm you are allowed to accept these payments with your UPI provider and follow its limits, business rules and customer-dispute process. Multiple accounts are not a way to bypass provider controls.
- Test mailbox access, actual receipt templates, email delays, exact amounts, UTR parsing, duplicate receipts, concurrent orders and refund/chargeback handling. Email notifications are not a bank settlement API and may be delayed or changed by the provider.
- Add email verification, password reset/account recovery, a proper privacy notice, data-retention/deletion controls, audit trails, alerting and operational support.
- Use a dedicated mailbox; the app password grants full access to that mailbox. Protect the encryption key and database separately and rotate/revoke credentials promptly.
- Webhook destinations are restricted to HTTPS and checked for public DNS addresses, but deployments still need egress controls, monitoring and an idempotent receiver.
- Review local privacy, tax, consumer and payment regulations with qualified professionals. No legal/tax exemption or compliance status is implied by this project.

This repository is a functional direct-UPI application — not a payment aggregator and not
a claim of 1:1 source-code equivalence to another service. It requires your own merchant
account, credentials, hosting and testing to process actual payments.
