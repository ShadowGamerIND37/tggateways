<?php declare(strict_types=1); require_once __DIR__.'/includes/apidoc.php'; ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Developer docs — TG Gateways</title><link rel="stylesheet" href="/assets/css/design-system.css"><link rel="stylesheet" href="/assets/css/marketing.css"><style>
/* Documentation layout. The previous sheet set body copy at 12px, sidebar
   links at 10px and code at 10px — below the readable floor on any display,
   and the 220px fixed sidebar left the article column narrow enough that
   code blocks scrolled horizontally on a laptop. Sizes and spacing below are
   tied to the design system's scale so docs read like the rest of the app. */
.docs-wrap{max-width:1200px;margin:36px auto 96px;display:grid;grid-template-columns:250px minmax(0,1fr);gap:48px;padding:0 24px;align-items:start}
.docs-nav{position:sticky;top:24px;align-self:start;padding:16px;background:#fff;border:1px solid var(--color-hairline);border-radius:14px;max-height:calc(100vh - 48px);overflow-y:auto}
.docs-nav strong{display:block;font-size:10.5px;letter-spacing:.7px;color:var(--color-ink-mute-2);padding:4px 10px 10px}
.docs-nav a{display:block;padding:8px 10px;border-radius:8px;color:#5a6a85;font-size:12.5px;line-height:1.4;letter-spacing:-.1px;transition:background .12s,color .12s}
.docs-nav a:hover{background:#f2f5fa;color:var(--color-ink)}
.docs-main{min-width:0}
.docs-main h1{font-size:34px;line-height:1.15;margin:10px 0 14px;letter-spacing:-1.1px;color:var(--color-ink)}
.docs-main h2{font-size:21px;margin:52px 0 14px;letter-spacing:-.5px;color:var(--color-ink);scroll-margin-top:24px}
.docs-main h2:first-of-type{margin-top:32px}
.docs-main h3{font-size:15px;margin:28px 0 8px;color:var(--color-ink);letter-spacing:-.2px}
.docs-main p,.docs-main li{font-size:13.5px;color:#54637a;line-height:1.85}
.docs-main p{margin:12px 0}
.docs-main ul,.docs-main ol{padding-left:22px;margin:12px 0}
.docs-main li{margin:6px 0}
.docs-main code{font-family:'Consolas','Monaco',monospace;font-size:12px;background:#eef2f8;padding:2px 6px;border-radius:5px;color:#22304a}
.docs-main pre{white-space:pre-wrap;word-break:break-word;overflow:auto;padding:18px 20px;background:#0f172a;color:#dbe5f5;border-radius:12px;font:12px/1.85 'Consolas','Monaco',monospace;margin:16px 0;border:1px solid #1e293b}
.docs-main pre code{background:none;padding:0;color:inherit;font-size:inherit}
.docs-main a{color:var(--color-primary);text-decoration:none}
.docs-main a:hover{text-decoration:underline}
.docs-main > .eyebrow{margin-top:6px}
.doc-callout{padding:16px 18px;background:#f1f6ff;border:1px solid #d8e5ff;border-left:3px solid #2563eb;border-radius:9px;color:#42536f;font-size:12.5px;line-height:1.8;margin:18px 0}
.docs-header{border-bottom:1px solid var(--color-hairline);background:#fff;position:sticky;top:0;z-index:20}
/* Section anchors get a quiet divider so long pages have rhythm. */
.docs-main h2{border-top:1px solid #f0f3f8;padding-top:34px}
.docs-main h2:first-of-type{border-top:none;padding-top:0}
/* Active section marker, driven by the scroll-spy script. */
.docs-nav a.active{background:var(--color-primary-soft,#eef4ff);color:var(--blue-700,#1d4ed8);font-weight:650}
.docs-nav a.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:16px;border-radius:0 3px 3px 0;background:#2563eb}
.docs-nav a{position:relative}
/* Copy affordance on code blocks. */
.docs-main pre{position:relative}
.copy-code{position:absolute;top:9px;right:9px;font:600 10.5px/1 var(--font,system-ui);letter-spacing:.3px;padding:6px 9px;border-radius:6px;border:1px solid #2b3a52;background:#1b2740;color:#a8bad4;cursor:pointer;opacity:0;transition:opacity .15s,background .15s,color .15s}
.docs-main pre:hover .copy-code,.copy-code:focus{opacity:1}
.copy-code:hover{background:#243450;color:#dbe5f5}
@media(hover:none){.copy-code{opacity:1}}
@media(max-width:900px){
  .docs-wrap{grid-template-columns:1fr;margin:20px auto 60px;gap:24px;padding:0 16px}
  .docs-nav{position:static;max-height:none;display:flex;overflow-x:auto;gap:5px;padding:10px}
  .docs-nav strong{display:none}
  .docs-nav a{white-space:nowrap;padding:7px 11px;background:#f6f8fb}
  .docs-main h1{font-size:26px}
  .docs-main h2{font-size:18px;margin-top:36px;padding-top:26px}
  .docs-main p,.docs-main li{font-size:13px}
}
</style></head><body><header class="docs-header"><div class="container topnav"><a class="brand" href="/"><img src="/assets/img/tg-mark.png" alt="">TG Gateways</a><nav class="navlinks"><a href="/docs.php">Docs</a><a href="/index.php#system">Status</a><a href="/dashboard.php">Dashboard</a></nav><a class="btn" href="/login.php">Dashboard login →</a></div></header><main class="container docs-wrap"><nav class="docs-nav" id="docs-nav">
    <strong>GETTING STARTED</strong>
    <a href="#quickstart">Quick start</a>
    <a href="#ai-context">AI agent context</a>
    <strong>INTEGRATING</strong>
    <a href="#auth">Base URL &amp; auth</a>
    <a href="#routing">Gateway setup</a>
    <a href="#api">API endpoints</a>
    <a href="#webhooks">Webhooks</a>
    <a href="#examples">Code examples</a>
    <strong>OPERATING</strong>
    <a href="#troubleshooting">Troubleshooting</a>
    <a href="#admin">Admin panel</a>
    <a href="#security">Security notes</a>
  </nav><article class="docs-main"><div class="eyebrow">DEVELOPER DOCUMENTATION</div><h1>Build payments into your product.</h1><p>Integrate direct-to-merchant UPI checkout, then receive a status update from your configured payment-receipt workflow.</p><span class="pill pill-blue">API v1 · Starter implementation</span> <span class="pill pill-green"><i class="dot"></i> Deployment-dependent</span><div style="display:flex;gap:12px;flex-wrap:wrap;margin:22px 0"><code id="install">pip install -e ./sdk/python-sdk</code><button class="btn btn-outline" data-copy-target="#install">Copy</button><a class="btn btn-outline" href="https://pypi.org/project/tggateways/">PyPI page (if published) ↗</a><a class="btn btn-outline" href="https://github.com/ShadowGamerIND37/tggateways">GitHub source ↗</a><a class="btn btn-outline" href="/openapi.json">OpenAPI JSON ↗</a><a class="btn btn-outline" href="/docs.txt">Plain-text reference ↗</a><a class="btn btn-outline" href="/llms.txt">llms.txt ↗</a><a class="btn btn-outline" href="/ai-prompt.txt">AI prompt ↗</a></div>
<div class="doc-callout">The code shown here is a self-hosted application and is not a payment aggregator. Merchant eligibility, account setup, settlement, provider fees, tax treatment and privacy obligations are determined by your providers and applicable law. Test in a controlled environment before production use.</div>
<h2 id="quickstart">Quick start</h2><ol><li>Deploy PHP 8.1+ with PDO MySQL, OpenSSL, cURL and Composer dependencies.</li><li>Import <code>database/schema.sql</code>; configure secrets in a private <code>.env</code>.</li><li>Sign in at <code>/admin/login.php</code> with <code>admin</code>/<code>admin</code> and change that password immediately.</li><li>Provision an authenticated merchant and configure an active UPI account.</li><li>Send a server-side create-order request with your API key, then present the hosted checkout URL.</li><li>Set up and verify your receipt mailbox parser only after testing provider email formats. Configure a private cron.</li></ol><pre>composer install
mysql -u tggateways -p tggateways &lt; database/schema.sql
cp .env.example .env
# set APP_URL, DB_*, and a random 32-byte APP_ENCRYPTION_KEY</pre>
<h2 id="ai-context">Master AI agent context</h2><p>Copy this project-specific context into your coding assistant before asking it to extend the integration.</p><pre id="ai-prompt"><?=htmlspecialchars(tg_doc_ai_prompt(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></pre><button class="btn" data-copy-target="#ai-prompt">Copy Complete AI Prompt</button>
<h2 id="auth">Base URL & authentication</h2><p>Set your deployment's base URL. The example production domain shown in product copy is <code>https://tggateways.in</code>; use your actual HTTPS deployment URL.</p><pre>POST /api/create-order
X-Api-Key: tg_your_secret_key
Content-Type: application/json

Authorization: Bearer tg_your_secret_key</pre><p>API keys are merchant secrets. Do not expose them in browser JavaScript. The included API layer enforces a per-IP/per-operation request window; tune it to your server capacity. Polling every few seconds from every visitor can add load—prefer webhooks for fulfillment and use the public status endpoint only for checkout display.</p>
<h2 id="routing">Gateway setup & routing</h2><h3>UPI account & receipt mailbox</h3><ol><li>Configure an account through the merchant dashboard Integrations page.</li><li>Use a dedicated mailbox, enable IMAP, and create a scoped app password where supported.</li><li>The app password is encrypted with AES-256-GCM using the deployment's environment key. Set restrictive file permissions on <code>.env</code>.</li><li>Run <code>php cron/imap-parser.php</code> from a private scheduler. Monitor failures without logging message contents or credentials.</li><li>Validate parsing with your provider's current email format; adjust regex only from authorized test samples.</li></ol><div class="doc-callout">Never use multiple accounts to evade provider limits or account controls. Keep mailbox and financial-data access to the minimum required.</div>
<h2 id="api">Core API endpoints</h2><h3>1. Create order · POST /api/create-order</h3><pre>curl -X POST https://your-host.example/api/create-order \\
  -H 'X-Api-Key: tg_your_secret_key' -H 'Content-Type: application/json' \\
  -d '{"amount":499.00,"customer_name":"Asha","customer_email":"asha@example.com"}'</pre><pre>{"order_id":"tg_A1B2C3D4","qr_url":"https://your-deployment/api/qr-image.php?order_id=tg_A1B2C3D4","qr_path":"/api/qr-image.php?order_id=tg_A1B2C3D4","checkout_url":"https://your-deployment/pay.php?order_id=tg_A1B2C3D4","upi_intent":"upi://pay?...","payable_amount":"499.00","currency":"INR","status":"pending","expires_in_seconds":600}</pre><h3>Retry safety · Idempotency-Key</h3>
<p>Any client that retries on a timeout has to know whether the first attempt created an order. Send a unique <code>Idempotency-Key</code> header to reduce duplicate orders on retries. It is not an exactly-once guarantee: a crash or persistence failure can leave an ambiguous result, so reconcile before creating a replacement order.</p>
<pre>curl -X POST https://your-host.example/api/create-order \\
  -H 'X-Api-Key: tg_your_secret_key' \\
  -H 'Idempotency-Key: b1c2d3e4-5f60-4718-9a2b-3c4d5e6f7081' \\
  -H 'Content-Type: application/json' \\
  -d '{"amount":499.00,"note":"cart_8821"}'</pre>
<ul>
<li><strong>Same key, same body</strong> — the original response comes back byte for byte, with the original status code and <code>Idempotent-Replay: true</code>. No second order is created.</li>
<li><strong>Same key, different body</strong> — <code>409</code>. Silently honouring either request would be worse than refusing; use a fresh key.</li>
<li><strong>Key still in flight</strong> — <code>409</code> with <code>Retry-After: 1</code>. Retry the same request shortly.</li>
<li><strong>Failed validation</strong> — explicit order validation failures release the key, so you can fix the body and retry. Other errors may leave a reservation temporarily in flight.</li>
</ul>
<p>The key body comparison ignores JSON key order and whitespace, so a re-serialised retry still replays. Keys are scoped per merchant; the retention setting defaults to 24 hours, with opportunistic cleanup. Do not rely on exact expiry or reuse old keys. Omitting the header keeps the old behaviour: every request creates an order.</p>
<h3>2. Public checkout status · GET /api/checkout-status.php?order_id=…</h3><p>Returns display-safe order status and amount, with UTR hidden until success. Do not use this public route as your fulfillment authority.</p><h3>3. Server verification · GET /api/verify-order.php?order_id=…</h3><p>Requires API-key authentication; call from a trusted server.</p><h3>4. Hosted checkout · GET /pay.php?order_id=…</h3><p>Displays the QR, UPI-app intent shortcuts, countdown and status poller. Reusable links use <code>/pay.php?link=SLUG</code>.</p><h3>5. QR image · GET /api/qr-image.php?order_id=…</h3><p>Returns a PNG when Composer dependencies are installed. The image encodes a UPI payment intent for the configured active merchant account.</p><h3>6. Payment links · POST /api/manage-links.php</h3><pre>{"title":"Consultation","amount":499,"description":"Optional details"}</pre><p>GET on the same route lists the authenticated merchant's links.</p><h3>7. Transaction history · GET /api/get-transactions.php?status=success&amp;page=1&amp;limit=25</h3><p>Optional filters: <code>status</code> (pending, success, expired), <code>search</code> (order ID or UTR), <code>page</code> and <code>limit</code> (max 100).</p>
<h2 id="webhooks">Webhooks & HMAC-SHA256</h2><p>Events are sent as JSON. The signature is <code>sha256=hex(HMAC-SHA256(raw_body, merchant_api_key))</code> in <code>X-TGGateway-Signature</code>. Retry scheduling starts at approximately 5, 10 and 15 minutes, followed by a final bounded attempt. Make handlers idempotent by order ID.</p><pre>{"event":"payment.succeeded","order_id":"tg_A1B2C3D4","amount":499,"payable_amount":499,"status":"success","utr":"123456789012","transaction_id":"123456789012","paid_at":"2026-01-01 12:00:00"}</pre>
<h2 id="examples">Polyglot reference snippets</h2><h3>Python · SDK / requests</h3><pre>from tggateways import TGGateway
client = TGGateway("https://your-host.example", "tg_your_secret_key")
order = client.create_order(499.00, customer_name="Asha")
print(order["checkout_url"])
print(client.verify_order(order["order_id"])["status"])</pre><h3>Python · Webhook signature verification</h3><pre>import hmac, hashlib

def valid_signature(raw_body: bytes, header: str, api_key: str) -&gt; bool:
    supplied = header.removeprefix("sha256=")
    expected = hmac.new(api_key.encode(), raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, supplied)</pre><h3>Node.js · Express listener</h3><pre>import express from 'express';
import crypto from 'node:crypto';
const app = express();
app.post('/webhooks/tg', express.raw({type:'application/json'}), (req,res) =&gt; {
  const supplied = (req.get('X-TGGateway-Signature') || '').replace(/^sha256=/, '');
  const expected = crypto.createHmac('sha256', process.env.TG_API_KEY).update(req.body).digest();
  let actual; try { actual = Buffer.from(supplied, 'hex'); } catch { return res.sendStatus(401); }
  if (actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) return res.sendStatus(401);
  const event = JSON.parse(req.body.toString());
  // Verify event + idempotently fulfill successful order here.
  res.sendStatus(200);
});</pre><h3>PHP · cURL create order</h3><pre>$ch = curl_init('https://your-host.example/api/create-order');
curl_setopt_array($ch, [CURLOPT_POST =&gt; true,
  CURLOPT_HTTPHEADER =&gt; ['Content-Type: application/json', 'X-Api-Key: '.$apiKey],
  CURLOPT_POSTFIELDS =&gt; json_encode(['amount' =&gt; 499.00]), CURLOPT_RETURNTRANSFER =&gt; true]);
$response = curl_exec($ch);
$order = json_decode($response, true);</pre><h3>PHP · Verify webhook</h3><pre>$raw = file_get_contents('php://input');
$header = $_SERVER['HTTP_X_TGGATEWAY_SIGNATURE'] ?? '';
$expected = 'sha256='.hash_hmac('sha256', $raw, $apiKey);
if (!hash_equals($expected, $header)) { http_response_code(401); exit; }
$event = json_decode($raw, true);</pre>
<h2 id="troubleshooting">Troubleshooting</h2>
<p>Most "it does not work" reports fall into four buckets. Work through them in order — each step eliminates a whole class of cause.</p>
<h3>Payments never confirm</h3>
<ol>
<li><strong>Is the mailbox connected?</strong> Integrations shows a red banner if the last poll failed, and the wallet is skipped entirely when no app password is stored. A disconnected wallet produces <em>no receipts and no error</em>, which looks identical to "the customer has not paid yet".</li>
<li><strong>Is the worker running?</strong> <code>php cron/imap-parser.php</code> has to be scheduled. Confirm with the <em>Receipt worker</em> line on the admin Deployment page: if no mailbox has ever been polled, the worker is not running.</li>
<li><strong>Did the receipt arrive at the wrong address?</strong> The app password must belong to the mailbox that the UPI provider emails. A personal inbox will never see receipts for a merchant handle.</li>
<li><strong>Does the amount match?</strong> Settlement matches on amount and, where present, the order reference. A receipt for ₹499.00 will not claim a ₹499.50 order.</li>
</ol>
<h3>Sign-in or setup problems</h3>
<ul>
<li><strong>"Invalid IMAP host"</strong> — the field wants a hostname such as <code>imap.gmail.com</code>, not a URL. Only port <code>993</code> triggers implicit TLS; any other port opens plain TCP and then upgrades with STARTTLS, so the host and port must agree.</li>
<li><strong>App password rejected</strong> — Google app passwords are exactly 16 characters and require 2-Step Verification to be enabled first. The account password will never work here.</li>
<li><strong>Two-factor code never arrives</strong> — with <code>SMTP_HOST</code> empty the app skips the second factor entirely rather than showing a dead end. Set SMTP to enable it.</li>
<li><strong>A wallet will not delete</strong> — it still has pending orders. Deleting it would orphan them, so removal is refused until they settle or expire.</li>
</ul>
<h3>Locked out of an account</h3>
<p>Merchants recover their own password at <a href="/forgot-password.php">/forgot-password.php</a>. The link is emailed, works once, expires after an hour, and is stored only as a hash &mdash; a database leak cannot be replayed into account access. Requesting a second link invalidates the first.</p>
<ul>
<li><strong>No email arrives</strong> — recovery needs a mail transport. Set <code>SMTP_HOST</code> and <code>APP_URL</code>; the page says so explicitly rather than pretending the mail was sent. Without SMTP, an operator has to reset the password directly.</li>
<li><strong>The link points at the wrong host</strong> — it is built from <code>APP_URL</code>, never from the request's <code>Host</code> header, precisely so a spoofed header cannot redirect a reset token. Set <code>APP_URL</code> to the address visitors actually open.</li>
<li><strong>Reset does not fix a suspended account</strong> — suspension is checked at sign-in, not at reset.</li>
</ul>
<h3>Issuing a refund</h3>
<p>This deployment collects money straight into your own UPI account, so the app cannot move money back. A refund is therefore recorded, not executed: return the money from your UPI app, then record it on the order.</p>
<ol>
<li>Open <a href="/transactions.php">Transactions</a> and click the order.</li>
<li>Under <strong>Refunds</strong>, enter the amount, your refund UTR and a reason.</li>
</ol>
<p>What is enforced: the total refunded can never exceed what was collected, only a paid order can be refunded, and an order only becomes <strong>Refunded</strong> once the full amount is returned. A partial refund leaves it <strong>Captured</strong> with the remaining balance shown, so a half-refunded order is not mistaken for a whole one.</p>
<div class="doc-callout"><strong>Dashboard revenue reconciles.</strong> It is collected minus refunded. When refunds exist the card shows the gross, the refunded total and the net, so money never appears to vanish.</div>
<h3>Webhooks not firing</h3>
<p>Only <code>payment.succeeded</code> is sent, and only once per order. A webhook URL must be HTTPS and must not resolve to a private address. Failed deliveries retry with backoff and stop after a bounded number of attempts; the admin overview counts the ones that have failed three or more times.</p>

<h2 id="admin">Admin panel</h2>
<p>Operators use a separate console at <code>/admin/</code>. It is deliberately not reachable from a merchant session: administrators live in their own table and their own session store, so a merchant account cannot be escalated into an operator by any merchant-side action.</p>
<h3>First run</h3>
<p>The first request on a fresh database creates one owner account:</p>
<pre>URL:      /admin/login.php
Username: admin
Password: admin</pre>
<div class="doc-callout"><strong>Change this immediately.</strong> The panel shows a persistent warning until you do, and every new administrator created from the panel is also flagged until they rotate their own password.</div>
<h3>What it does</h3>
<ul>
<li><strong>Overview</strong> — merchants, collections, disconnected wallets, failing webhooks and unpaid invoices. The disconnected-wallet count is the one to watch: those mailboxes silently stop confirming payments.</li>
<li><strong>Merchants</strong> — search by name, email or API key; suspend and restore; internal notes that the merchant never sees.</li>
<li><strong>Orders</strong> — every order across the deployment, read-only. Status is never hand-editable, because an operator who can mark money as collected that never arrived corrupts the audit trail.</li>
<li><strong>Addons &amp; Billing</strong> — subscriptions, invoices and marking an invoice paid once the money has arrived out of band.</li>
<li><strong>Support</strong> — read and reply to tickets; replies go through the same SMTP transport as the rest of the app.</li>
<li><strong>Audit log</strong> — admin and merchant actions in one ordered stream.</li>
<li><strong>Administrators</strong> — add, disable, change role, delete. Three protections are enforced: the last owner cannot be demoted or deleted, nobody can delete themselves, and only an owner can manage administrators.</li>
<li><strong>Deployment</strong> — preflight checks that report what is misconfigured and what each gap breaks. Read-only on purpose: these values live in <code>.env</code> and need a restart, so an editable box would silently do nothing.</li>
</ul>
<h3>Roles</h3>
<ul>
<li><strong>Owner</strong> — everything, including administrators.</li>
<li><strong>Staff</strong> — merchants, billing, support, addons.</li>
<li><strong>Viewer</strong> — read-only.</li>
</ul>
<div class="doc-callout">Administrator sessions are stored server-side as a SHA-256 of a random token, so a database leak does not hand over logins. Changing your password revokes your other sessions.</div>

<h2 id="security">Security & privacy notes</h2><ul><li>Webhook URL configuration must be locked down against SSRF before public production deployment.</li><li>UTR uniqueness and row locks help prevent duplicate assignment; reconcile with provider records and handle unmatched receipts operationally.</li><li>Do not store customer payment credentials. Limit access to mailbox receipts and promptly delete data not needed for operations.</li><li>HTTPS, strict secrets handling, backups, audit logging without secrets, dependency updates, and incident response are deployment responsibilities.</li><li>No claim of legal compliance or GST exemption is made by this software. Obtain professional advice for your facts and jurisdiction.</li></ul><p><a href="/">← Back to TG Gateways</a></p></article></main><script src="/assets/js/main.js"></script>
<script>
/* Highlight the section currently in view. Eleven flat links with no position
   indicator meant a reader had no idea where they were in a long page. */
(function () {
  var links = Array.prototype.slice.call(document.querySelectorAll('#docs-nav a'));
  var targets = links.map(function (a) {
    return document.getElementById(a.getAttribute('href').slice(1));
  }).filter(Boolean);
  if (!targets.length) return;

  var active = null;
  function setActive(id) {
    if (active === id) return;
    active = id;
    links.forEach(function (a) {
      var on = a.getAttribute('href') === '#' + id;
      a.classList.toggle('active', on);
      if (on) a.setAttribute('aria-current', 'true');
      else a.removeAttribute('aria-current');
    });
  }

  if ('IntersectionObserver' in window) {
    var seen = new Map();
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { seen.set(e.target.id, e.isIntersecting); });
      // Topmost visible heading wins, so the marker does not jump between
      // two headings that are both briefly on screen.
      var first = targets.filter(function (t) { return seen.get(t.id); })[0];
      if (first) setActive(first.id);
    }, { rootMargin: '-10% 0px -70% 0px', threshold: 0 });
    targets.forEach(function (t) { io.observe(t); });
  }

  // A linked section must be visible even if the reader only scrolled to the
  // URL fragment and never triggered an observer callback.
  function syncFromScroll() {
    var y = window.scrollY + 90;
    var current = targets[0];
    targets.forEach(function (t) { if (t.offsetTop <= y) current = t; });
    if (current) setActive(current.id);
  }
  window.addEventListener('scroll', syncFromScroll, { passive: true });
  syncFromScroll();

  // Copy buttons on every code block: reading a snippet is one step, getting
  // it into an editor is the step that actually matters.
  document.querySelectorAll('.docs-main pre').forEach(function (pre) {
    // This block has a dedicated copy button; keep it free of injected text.
    if (pre.id === 'ai-prompt' || pre.querySelector('.copy-code')) return;
    var btn = document.createElement('button');
    btn.className = 'copy-code';
    btn.type = 'button';
    btn.textContent = 'Copy';
    btn.addEventListener('click', function () {
      var clone = pre.cloneNode(true);
      var copyButton = clone.querySelector('.copy-code');
      if (copyButton) copyButton.remove();
      var text = clone.textContent;
      var done = function () {
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = 'Copy'; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { btn.textContent = 'Failed'; });
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) { btn.textContent = 'Failed'; }
        document.body.removeChild(ta);
      }
    });
    pre.appendChild(btn);
  });
})();
</script>
</body></html>
