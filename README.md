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
#    SESSION_SECURE=false               # only while testing on http://localhost
#    TG_HTTP_PORT=8090                  # host port; change it if this one is taken
$EDITOR .env

docker compose up --build -d
docker compose ps            # web, database and cron should become healthy
```

Open **http://localhost:8090/register.php** and create your merchant account.

**Port already in use?** Pick any free port — it is a single setting, and the app's
`APP_URL` follows it automatically under Compose:

```sh
# in .env
TG_HTTP_PORT=9001
docker compose up -d          # no rebuild needed; only publishing uses the port
# then browse http://localhost:9001/register.php
```

Only one variable to change: `TG_HTTP_PORT` publishes the port **and** becomes the
`APP_URL` the app uses for checkout links, so the two cannot drift apart. (If your `.env`
also uncomments `APP_URL`, that value wins — change both, or leave it commented out.)
If publishing still fails, check `sudo lsof -i :8090`; another container or a local proxy
is holding it. `docker compose ps` shows nothing listening when this happens.

What the stack starts:

| Service | Role |
| --- | --- |
| `web` | PHP 8.3 + Apache with `pdo_mysql`, `curl`, `gd`, `mod_rewrite`. Serves the app. |
| `database` | MySQL 8.4. Loads `database/schema.sql` automatically the first time the volume is created. |
| `cron` | Runs `cron/imap-parser.php` every 5 seconds: expires stale orders, reads new receipt mail, retries failed webhooks. Uses only the bundled IMAP client — no `imap` extension in the image. |

Check the deployment at any time:

```sh
curl -s http://localhost:8090/api/health.php | python3 -m json.tool
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

### Behind a Cloudflare Tunnel (or any reverse proxy)

Expose the container with a tunnel when the machine has no public IP (Codespaces, a
laptop, a home server). The app needs three settings, and one of them is easy to miss.

```sh
# 1. Point a tunnel at the published port
cloudflared tunnel --url http://localhost:8009
#    or, with a named tunnel and a hostname you control:
#    cloudflared tunnel route dns tggateways gate.example.com
#    cloudflared tunnel run --url http://localhost:8009 tggateways
```

```sh
# 2. .env — use the https:// URL visitors actually open
APP_URL=https://gate.example.com
SESSION_SECURE=true
# 3. .env — the address cloudflared connects from, so rate limits stay per-visitor
TRUSTED_PROXIES=172.16.0.0/12

docker compose up -d
```

Then confirm the public URL answers:

```sh
curl -s https://gate.example.com/api/health.php | python3 -m json.tool
```

`APP_URL` **must** be the public `https://` URL, not `http://localhost:8009`. It is what
checkout links are built from, and behind an HTTPS tunnel a plain-http `APP_URL` makes the
browser drop the session cookie — login appears to do nothing while the dashboard keeps
redirecting you back. `/api/health.php` reports this as a configuration problem.

`TRUSTED_PROXIES` is optional but recommended: without it every visitor reaches the container
from the tunnel's address, so all rate-limit buckets collapse into one and per-IP limits stop
meaning anything. The forwarded header is ignored unless the request really came from a
trusted proxy, so a direct visitor cannot forge their address.

On Linux, find the bridge address the tunnel appears as:

```sh
docker network inspect tggateways_default --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}'
```

### Local PHP quirks you may hit

These are environment issues, not application errors — the app runs correctly with either.

- **`Xdebug: [Step Debug] Could not connect to debugging client`** — Xdebug is enabled in your
  local PHP but no debugger is listening. It is a startup notice, harmless. Silence it with
  `XDEBUG_MODE=off php -r '…'`, or set `xdebug.start_with_request=no`. In the Docker image
  it cannot appear at all: `/api` must never have stray output, so the image ships
  `docker/php.ini` with `display_errors=Off` and logs to stderr. The application enforces
  the same rule itself (`includes/errors.php`): web SAPIs stay silent, CLI keeps
  diagnostics, and `APP_DEBUG=true` opts back in on a development machine only.
- **`bin2hex(random_bytes(32))` printed 64 characters** — that is the whole key. Paste it
  whole as `APP_ENCRYPTION_KEY`. Do not truncate it, add quotes inside `.env`, or reuse a
  key across deployments: it decrypts the stored mailbox passwords, and there is no
  recovery if it is lost.
- **`PHP Warning: Module "imap" is already loaded`** — some distros load IMAP from
  `conf.d`; ignore it, or remove the duplicate line from your `php.ini`.

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

**Requirements:** PHP 8.1+ with `pdo_mysql`, `curl`, `openssl`, `mbstring` and `gd`;
MySQL 8 (or MariaDB 10.6+); Composer 2; Apache with `mod_rewrite`/`mod_headers`, or Nginx.

PHP's `imap` extension is **not** required: receipt polling uses a bundled pure-PHP IMAP
client over a TLS socket (`includes/imap-client.php`), so there is nothing extra to
install and no build dependency to break.

```sh
sudo apt install php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring \
     php8.3-gd composer apache2 libapache2-mod-php8.3 mysql-server
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
endpoint health, mailbox/UPI configuration and additional accounts.

The API is described once in `includes/apidoc.php` and rendered four ways, so the routes
cannot drift between formats:

| URL | Format | Audience |
| --- | --- | --- |
| `/docs.php` | HTML | merchants |
| `/docs.txt` | plain text | terminals, `curl`, offline reading |
| `/llms.txt` | llms.txt convention | LLMs answering questions about the product |
| `/ai-prompt.txt` | master prompt | assistants writing integration code |
| `/openapi.json` | OpenAPI 3.1 | code generators |

The `.txt` files are PHP scripts (`docs.txt.php`, …) reached through `mod_rewrite`, so they
render from the same source while still looking like static documents.

Transactions can be exported to CSV (`/transactions-export.php`) with the same filters as
the table on screen. Cells beginning with `=`, `+`, `-` or `@` are prefixed with an
apostrophe so a hostile customer name cannot execute as a spreadsheet formula, and the file
carries a UTF-8 BOM so Excel renders ₹ and non-ASCII names correctly.

### Merchant panel

The panel uses the FamGateway layout and design tokens (`assets/css/design-system.css`):
dark sidebar, stat cards, filter tabs and a seven-column transaction table. Navigation:
Dashboard, Transactions, Payment Links, API Keys, Webhooks, Settings, Integrations,
Multi-Accounts, Addons, My Services, Invoices, Profile, Support Tickets, Documentation and
System Status. Profile (five tabs, avatar embedded in QR codes), Settings (payment defaults), Support
Tickets (create/reply/resolve with counters and real screenshot uploads), Addons (Paytm ₹49/month in 1/3/6/12-month
terms), My Services, Invoices and a public System Status page with measured 30-day uptime
are all live.

**Schema upgrades are automatic.** `includes/migrate.php` runs on boot and records applied
migrations in `schema_migrations`, so an existing volume gains new tables on the next
dashboard request. `database/schema.sql` is only for fresh installs. To apply by hand:

```sh
docker compose exec app php -r 'require "includes/db.php"; require "includes/migrate.php"; print_r(tg_run_migrations());'
```

To avoid the boot check entirely (large fleets, read-only filesystems), set
`TG_SKIP_MIGRATIONS=true`.

Two behaviours worth knowing:

- Sign-in runs a second email-code step **only** when `LOGIN_2FA=true` and a mail transport
  is configured. A code screen that cannot deliver mail would lock merchants out, so the
  auto-detect that used to enable it is gone.
- New registrations must accept the Terms and Privacy links, and the account is created
  after email verification when 2FA is on.

### Support ticket attachments

Screenshots and PDFs attached to a support ticket are validated by their leading
bytes, not by the filename or the browser-supplied type — a `.png` containing
SVG markup is refused, because that file would otherwise be handed back to a
browser. Accepted types are JPG, PNG, WEBP and PDF, up to 5 MB each and five per
ticket.

Files are renamed to 32 random hex characters, written to `storage/attachments`
with mode 0600 (outside the document root), and streamed through
`attachment.php` only after the signed-in merchant is confirmed as the owner.
Every download is sent as `Content-Disposition: attachment` with
`X-Content-Type-Options: nosniff`, and `storage/` is blocked in `.htaccess`.
In Docker the directory is a named volume (`tg-attachments`), so uploads survive
`docker compose up --build`.

### Receiving accounts

A merchant can link several UPI wallets and switch the active receiver in one click.
Only one account receives new orders at a time; activating a standby retires the previous
primary rather than splitting traffic silently. Three rules protect the payout path:

- **Cap.** `MAX_RECEIVING_ACCOUNTS` (default 5) limits how many wallets can be linked.
- **Validation.** UPI IDs are checked against VPA grammar. Set `UPI_ID_DOMAINS=fam,yesfam`
  to lock a deployment to specific handles; leave it empty to stay provider-agnostic.
- **Re-authorisation.** Changing the payout UPI or receipt mailbox requires the account
  password. The authorisation lasts 15 minutes, and the blocked edit is replayed
  automatically once it is granted, so nothing has to be retyped.

Every payout change, activation and removal is written to `audit_log`.

---

## 8. Tests

```sh
node tests/run.mjs                 # auto-detects php, then a php-wasm CLI
PHP_BIN=/usr/bin/php node tests/run.mjs
```

311 checks run against a throwaway SQLite database: receipt parsing/matching, MIME
decoding against plain/quoted-printable/base64/forwarded samples, the IMAP protocol client
driven by a scripted peer, AES-GCM round-trips, auth and CSRF, input validation, rate
limits, order creation, status and verification endpoints, the hosted checkout page,
settlement idempotency (matched → duplicate → unmatched → unparsed), worker housekeeping,
webhook signing/queueing/retry and the SSRF URL policy. MySQL remains the production database; SQLite exists so the
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
