<?php
declare(strict_types=1);
/**
 * The API described once, rendered four ways.
 *
 * Shared endpoint summaries and AI integration guidance for the text references
 * and the copyable prompt on docs.php. Verify these descriptions against the
 * endpoint implementations when the API changes.
 */
require_once __DIR__.'/db.php';

/** Absolute base URL for examples, honouring the configured APP_URL. */
function tg_doc_base_url(): string {
    $configured = trim((string)envv('APP_URL', ''));
    if ($configured !== '') return rtrim($configured, '/');
    return 'http://localhost:'.(string)envv('TG_HTTP_PORT', '8090');
}

/**
 * Canonical endpoint list.
 *
 * @return list<array{method:string,path:string,auth:string,summary:string,params:array<string,string>,returns:string}>
 */
function tg_doc_endpoints(): array {
    return [
        [
            'method' => 'POST', 'path' => '/api/create-order', 'auth' => 'API key',
            'summary' => 'Create an order and return a hosted checkout URL, QR image URL and UPI intent link.',
            'params' => [
                'amount' => 'required — amount in INR, two decimals',
                'redirect_url' => 'optional — where the checkout returns after payment',
                'webhook_url' => 'optional — overrides the account webhook for this order',
                'customer_name' => 'optional', 'customer_email' => 'optional', 'customer_phone' => 'optional',
                'note' => 'optional — free text shown on the checkout',
            ],
            'returns' => 'order_id, checkout_url, qr_url, upi_intent, payable_amount, expires_at',
        ],
        [
            'method' => 'GET', 'path' => '/api/checkout-status.php', 'auth' => 'None (public)',
            'summary' => 'Poll the display status of an order from the checkout page or a bot.',
            'params' => ['order_id' => 'required'],
            'returns' => 'status (pending|success|expired|refunded), payable_amount, expires_at and payment details when successful',
        ],
        [
            'method' => 'GET', 'path' => '/api/verify-order.php', 'auth' => 'API key',
            'summary' => 'Authoritative order lookup. This is the fulfillment signal — polling is not.',
            'params' => ['order_id' => 'required'],
            'returns' => 'status, paid_at, utr, amount, payer details',
        ],
        [
            'method' => 'GET', 'path' => '/api/qr-image.php', 'auth' => 'None (public)',
            'summary' => 'PNG stream of the order QR, embeddable in HTML or a chat message.',
            'params' => ['order_id' => 'required'],
            'returns' => 'image/png',
        ],
        [
            'method' => 'GET|POST', 'path' => '/api/manage-links.php', 'auth' => 'API key',
            'summary' => 'List or create reusable payment links.',
            'params' => [
                'title' => 'POST — required link name', 'amount' => 'POST — required fixed amount',
                'description' => 'POST — optional description', 'redirect_url' => 'POST — optional HTTPS URL',
            ],
            'returns' => 'GET: links; POST: slug, title, amount and public URL',
        ],
        [
            'method' => 'GET', 'path' => '/api/get-transactions.php', 'auth' => 'API key',
            'summary' => 'Paginated transaction history with the same filters as the dashboard.',
            'params' => [
                'status' => 'success|pending|expired', 'search' => 'order ID or UTR',
                'limit' => '1–100', 'page' => 'page number',
            ],
            'returns' => 'rows plus total and page metadata',
        ],
        [
            'method' => 'GET', 'path' => '/api/health.php', 'auth' => 'None (public)',
            'summary' => 'Deployment health for monitors and the public status page.',
            'params' => [],
            'returns' => 'component states only — never merchant data',
        ],
    ];
}

/** The webhook contract, shared by every renderer. */
function tg_doc_webhook(): array {
    return [
        'event' => 'payment.succeeded',
        'signature_header' => 'X-TGGateway-Signature',
        'signature_rule' => 'sha256= followed by hex hmac_sha256(raw_request_body, merchant_api_key), compared in constant time',
        'retries' => 'exponential backoff from WEBHOOK_BACKOFF_SECONDS (default 300s), up to WEBHOOK_MAX_ATTEMPTS (default 5)',
        'body' => [
            'order_id' => 'tg_XXXXXXXX', 'amount' => '199.00', 'payable_amount' => '199.00',
            'status' => 'success', 'utr' => '428712345678', 'sender_name' => 'Rohan Sharma',
            'paid_at' => '2026-11-18 17:02:14', 'event' => 'payment.succeeded',
        ],
    ];
}

/** Plain-text API reference (docs.txt). */
function tg_doc_text(): string {
    $base = tg_doc_base_url();
    $out = "TG GATEWAYS — API REFERENCE\n"
        . "Generated ".gmdate('Y-m-d H:i')." UTC\n"
        . "Base URL: $base\n\n"
        . "AUTHENTICATION\n"
        . "  Send the merchant API key as either header:\n"
        . "    X-Api-Key: tg_...\n"
        . "    Authorization: Bearer tg_...\n"
        . "  Keys are created and rotated at $base/api-keys.php\n\n"
        . "FULFILMENT RULE\n"
        . "  Fulfil an order when either the signed webhook arrives, or\n"
        . "  GET /api/verify-order.php confirms status=success. Never fulfil from\n"
        . "  the public polling endpoint alone.\n\n"
        . "ENDPOINTS\n";

    foreach (tg_doc_endpoints() as $endpoint) {
        $out .= "\n".$endpoint['method'].' '.$endpoint['path']."\n"
            . '  Auth: '.$endpoint['auth']."\n"
            . '  '.$endpoint['summary']."\n";
        foreach ($endpoint['params'] as $name => $help) {
            $out .= '    - '.$name.': '.$help."\n";
        }
        $out .= '  Returns: '.$endpoint['returns']."\n";
    }

    $hook = tg_doc_webhook();
    $out .= "\nWEBHOOKS\n"
        . '  Event: '.$hook['event']."\n"
        . '  Header: '.$hook['signature_header']."\n"
        . '  Signature: '.$hook['signature_rule']."\n"
        . '  Retries: '.$hook['retries']."\n"
        . "  Body:\n    ".str_replace("\n", "\n    ", json_encode($hook['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))."\n"
        . "\nMORE\n"
        . "  OpenAPI 3.1: $base/openapi.json\n"
        . "  HTML docs:   $base/docs.php\n"
        . "  LLM context: $base/llms.txt\n"
        . "  Status:      $base/status.php\n";
    return $out;
}

/**
 * LLM-oriented context (llms.txt).
 *
 * Follows the emerging llms.txt convention: a short product statement, then
 * links, then the facts a model needs to answer questions or write code.
 */
function tg_doc_llms(): string {
    $base = tg_doc_base_url();
    $out = "# TG Gateways\n\n"
        . "> Self-hosted, non-custodial UPI payment gateway. Merchants create an order,\n"
        . "> the customer pays a UPI QR or intent link, and the gateway confirms the\n"
        . "> payment by reading the bank receipt email — the money never touches the\n"
        . "> gateway's account.\n\n"
        . "## What it is\n\n"
        . "- Direct peer-to-peer settlement to the merchant's own UPI ID.\n"
        . "- Verification by IMAP receipt parsing plus bank UTR matching, with an\n"
        . "  idempotent settlement ledger so a receipt can never fulfill twice.\n"
        . "- Hosted checkout, reusable payment links, signed webhooks, a merchant\n"
        . "  dashboard, and a public status page.\n"
        . "- Self-hosted on PHP 8.1+ and MySQL 8 (SQLite for evaluation and tests).\n\n"
        . "## What it is not\n\n"
        . "- Not a custodian: it never holds or routes customer funds.\n"
        . "- Not a PCI card processor: UPI only.\n"
        . "- Not a way to bypass bank or UPI provider limits — additional receiving\n"
        . "  accounts are for operational continuity only.\n\n"
        . "## Endpoints\n\n";
    foreach (tg_doc_endpoints() as $endpoint) {
        $out .= '- '.$endpoint['method'].' `'.$endpoint['path'].'` — '.$endpoint['summary']."\n";
    }
    $out .= "\n## Integration rule\n\n"
        . "Fulfil only on the signed webhook or on an authoritative `verify-order`\n"
        . "response. The public polling endpoint discloses display state and must not\n"
        . "be treated as proof of payment.\n\n"
        . "## Files\n\n"
        . "- [OpenAPI specification]($base/openapi.json)\n"
        . "- [Plain-text API reference]($base/docs.txt)\n"
        . "- [AI integration prompt]($base/ai-prompt.txt)\n"
        . "- [Human documentation]($base/docs.php)\n"
        . "- [Service status]($base/status.php)\n";
    return $out;
}

/** Detailed, copyable integration brief for AI coding assistants. */
function tg_doc_ai_prompt(): string {
    $base = tg_doc_base_url();
    return "TG GATEWAYS — AI INTEGRATION BRIEF\n\nConfigured base URL: $base\n\n" . <<<'PROMPT'
ROLE AND WORKING METHOD
You are a coding assistant helping the user integrate or extend TG Gateways.
First inspect the actual project and deployed API version. Ask for missing
requirements (stack, desired checkout flow, and deployment URL) rather than
inventing credentials or endpoints. Reuse the project's conventions and explain
any assumptions. Implement and test real behavior; label examples, limitations,
and unimplemented features clearly. Do not access or print secrets.

PRODUCT AND PREREQUISITES
TG Gateways is a self-hosted, non-custodial UPI checkout application: the
customer pays the merchant's UPI account directly. The application does not
hold, transfer, or refund money. Payment confirmation depends on the configured
receipt email, IMAP worker and matching rules; provider email formats and delays
vary. Test them with authorized examples, monitor the private worker, and do not
promise instant or guaranteed settlement. Merchants must have an active UPI
account configured before creating an order. Obtain the API key through the
merchant dashboard. Use the actual deployment's HTTPS base URL in production;
the configured URL above is deployment-specific, not a public service promise.

AUTHENTICATION AND SECURITY
- Send X-Api-Key: <merchant_api_key> or Authorization: Bearer <merchant_api_key>
  from a trusted server only. Never embed the key in client-side code, checkout
  URLs, logs, screenshots, or example output. Use environment variables or a
  secret manager. Use TLS, least privilege, and validate external inputs.
- API-key rotation also affects webhook verification. Plan rotation and queued
  webhook handling; do not assume a separate webhook signing secret exists.
- Treat redirect URLs, customer inputs, and browser-reported success as untrusted.
  A customer returning to a redirect URL is not proof of payment.

ORDER CREATION: BACKEND FIRST
POST /api/create-order.php with Content-Type: application/json and API-key auth.
The rewrite alias /api/create-order is available when server routing is set up.
Example request body (replace sample data, never include an actual secret):
  {"amount":499.00,"customer_name":"Asha","customer_email":"asha@example.com",
   "note":"cart-123","redirect_url":"https://merchant.example/paid",
   "webhook_url":"https://merchant.example/webhooks/tg"}
amount is INR 1 to 1,000,000 with at most two decimal places. The other fields
shown are optional; customer_phone is also supported. URLs must pass server URL
validation; use HTTPS. webhook_url overrides the merchant default for this order.
A 201 JSON response includes order_id, status=pending, currency, amount,
payable_amount, checkout_url, checkout_path, qr_url, qr_path, upi_intent,
upi_id, expires_at and expires_in_seconds. Use the exact payable_amount; store
and compare monetary values in integer paise or a decimal type, not binary float.
Persist order_id alongside the merchant's internal cart or purchase, expected
amount and currency. Show checkout_url to the customer or use qr_url/upi_intent.
The configured APP_URL must be correct for absolute URLs. The default payment
window is 10 minutes; expiry alone does not prove whether money arrived late.

RETRIES AND IDEMPOTENCY
Generate and persist one unique Idempotency-Key for each intended order before
sending the first request; use the same key and body for retries. Keys are
merchant-scoped, up to 190 printable ASCII characters without spaces. A completed
same-body replay returns the stored response and Idempotent-Replay: true; different
content returns 409. An in-flight request returns 409 and Retry-After: 1. Inspect
the error before deciding to retry. JSON object key order and whitespace are
normalized. Explicit input validation failures release the reservation; do not
assume every 4xx does so. Without a key, retries can create additional orders.
Retention is deployment-configurable (IDEMPOTENCY_TTL defaults to 86400 seconds)
and cleanup is opportunistic; do not assume exact expiry or reuse old keys.
IMPORTANT: reservations can be reclaimed after a stale timeout and order creation
and recording the response are not one atomic transaction. Crashes or storage
failures can therefore still create ambiguity or duplicates. Do not promise
exactly-once charging; reconcile uncertain attempts before issuing a new order.
Handle 400/422 input errors, 401 bad/missing key, 403 suspended account,
404 unknown order, 409 conflicts or missing active account, 429 rate limiting,
and transient network/5xx errors without exposing secrets. Back off on retries.

PAYMENT CONFIRMATION AND FULFILLMENT
GET /api/checkout-status.php?order_id=... is public display state only; never
fulfill solely from that response, a QR scan, browser redirect, or UPI app claim.
GET /api/verify-order.php?order_id=... uses API-key auth and returns the merchant's
order, status, amount, payable_amount, utr, transaction_id and timestamps.
The authenticated lookup or a verified payment.succeeded webhook can inform
fulfillment. Match order_id to a locally known purchase and check expected amount,
currency as applicable, event and status before fulfilling. Treat unknown orders,
mismatches, pending, expired and refunded statuses as not currently payable.
A full refund changes order status to refunded; partial refunds leave success.
A delayed success event may describe an order since refunded: check the current
order before fulfilling and reconcile manually when necessary.
The verify and public-status responses do not expose a refund amount. Do not
assume success means no partial refund. Treat late or ambiguous payments as a
manual reconciliation case instead of automatically charging or fulfilling again.

WEBHOOKS: VERIFY THE EXACT BYTES
The implemented event is payment.succeeded. X-TGGateway-Signature is exactly
"sha256=" followed by the lowercase hex HMAC-SHA256 of the raw HTTP body, keyed
by the merchant API key. Compare in constant time. Capture raw bytes before JSON
parsing; reserializing JSON changes the signature. Reject missing/invalid
signatures. Process only known event types and matching orders and amounts.
Webhooks may be delivered more than once. Use durable deduplication keyed by
order_id, with a unique constraint and transactional or otherwise recoverable
fulfillment. Return 2xx only after durable acceptance; duplicate accepted events
should return 2xx. Do not mark fulfilled before the durable operation succeeds.
Non-2xx and transport errors can be retried by the worker, with bounded attempts;
monitor failures and reconcile missed notifications. Signatures have no timestamp
freshness check, so deduplication and order validation are essential.

Python signature check (raw_body is bytes; supplied is the header value):
  import hashlib, hmac
  expected = 'sha256=' + hmac.new(api_key.encode('utf-8'), raw_body, hashlib.sha256).hexdigest()
  valid = hmac.compare_digest(expected, supplied or '')

PHP signature check (obtain $rawBody using file_get_contents('php://input')):
  $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $apiKey);
  $valid = hash_equals($expected, $_SERVER['HTTP_X_TGGATEWAY_SIGNATURE'] ?? '');

OTHER IMPLEMENTED ROUTES
- GET /api/qr-image.php?order_id=...: public PNG QR image; dependencies required.
- GET /api/manage-links.php: authenticated list; POST: create a fixed-amount
  link using title, amount, and optional description and redirect_url. Do not
  invent update/delete actions or custom slugs for this API.
- GET /api/get-transactions.php: authenticated history; status accepts pending,
  success or expired; search matches order ID/UTR; page and limit (1–100).
- GET /api/health.php: public deployment health; not payment confirmation.

REFUNDS AND LIMITATIONS
Refunds are manual records in the Transactions dashboard: the merchant must
return funds separately using their UPI/bank app, then record the amount, optional
bank reference and reason. The application does not transfer funds. It caps
recorded refunds to the collected amount; only a full refund sets status to
refunded. There is no refund API or payment.refunded webhook in this version.
Do not claim that a refund button executes a transfer, or that an integration
will automatically receive refund notifications. Check current code before
assuming a newer capability.

IMPLEMENTATION CHECKLIST
1. Establish the user's stack and requirements; never copy placeholder secrets.
2. Create orders server-side, persist the local mapping and idempotency key, and
   send the customer to the hosted checkout. Do not trust prices from browsers.
3. Build a raw-body verified webhook and/or authenticated verification flow;
   validate the mapped order and amounts, and make fulfillment durable and safe
   against duplicates and crashes. Separate payment state from fulfillment state.
4. Test invalid signatures, modified bodies, unknown/mismatched orders, duplicate
   delivery, pending/expired/refunded states, timeouts, retry conflicts, and worker
   or database failures. Use controlled test accounts and authorized receipts.
5. Explain setup, environment variables, how to run tests, reconciliation and
   remaining production risks. Distinguish tested behavior from assumptions.

SOURCE MAP AND REFERENCES
api/create-order.php       Order creation and validation
includes/idempotency.php   Request retry behavior and its limitations
api/verify-order.php       Authenticated lookup
api/checkout-status.php    Public display status (public-status.php)
includes/settlement.php    Receipt matching and atomic settlement
includes/receipt.php       Receipt extraction and matching rules
includes/webhook.php       Event payload, signing and delivery retries
includes/refund.php        Manual refund records
openapi.json, docs.php     API reference; cross-check against implementation
PROMPT;
}
