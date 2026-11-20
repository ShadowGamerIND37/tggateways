<?php
declare(strict_types=1);
/**
 * The API described once, rendered four ways.
 *
 * docs.php, docs.txt, llms.txt and ai-prompt.txt all read from this file, so a
 * route cannot drift between the HTML docs and the machine-readable ones. The
 * reference product ships the same trio and LLM tooling depends on it.
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
            'returns' => 'status (pending|success|expired), amount, utr and transaction_id once paid',
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
            'summary' => 'Create, list, update or deactivate reusable payment links.',
            'params' => [
                'action' => 'create|list|update|delete',
                'title' => 'create — link name', 'amount' => 'create/update — fixed amount',
                'slug' => 'optional — custom slug', 'expires_at' => 'optional — ISO timestamp',
            ],
            'returns' => 'the link row, including its public /pay/<slug> URL',
        ],
        [
            'method' => 'GET', 'path' => '/api/get-transactions.php', 'auth' => 'API key',
            'summary' => 'Paginated transaction history with the same filters as the dashboard.',
            'params' => [
                'filter' => 'all|success|pending|expired',
                'date' => 'today|yesterday|7days|30days|this_month|all',
                'limit' => '1–25', 'page' => 'page number',
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
        'signature_rule' => 'hmac_sha256(raw_request_body, merchant_api_key), hex encoded, compared in constant time',
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
        . "- [Human documentation]($base/docs.php)\n"
        . "- [Service status]($base/status.php)\n";
    return $out;
}

/**
 * Master prompt for AI coding assistants (ai-prompt.txt).
 *
 * Deliberately short and factual. The upstream product ships a very long
 * marketing prompt; what actually helps an assistant is the contract, the
 * safety rule and the file map.
 */
function tg_doc_ai_prompt(): string {
    $base = tg_doc_base_url();
    $out = "You are integrating TG Gateways, a self-hosted non-custodial UPI payment gateway.\n\n"
        . "BASE URL\n  $base\n\n"
        . "AUTH\n  X-Api-Key: <merchant api key>  (or Authorization: Bearer <key>)\n"
        . "  The key is server-side only. Never put it in browser code.\n\n"
        . "HAPPY PATH\n"
        . "  1. POST /api/create-order with {\"amount\": 199.00}\n"
        . "     -> order_id, checkout_url, qr_url, upi_intent\n"
        . "  2. Send the customer to checkout_url, or render qr_url.\n"
        . "  3. Fulfil on the signed webhook, or on GET /api/verify-order.php?order_id=...\n"
        . "     Do NOT fulfil from /api/checkout-status.php — it is display state only.\n\n"
        . "WEBHOOK VERIFICATION (python)\n"
        . "  expected = hmac.new(api_key.encode(), raw_body, hashlib.sha256).hexdigest()\n"
        . "  hmac.compare_digest(expected, headers['X-TGGateway-Signature'])\n\n"
        . "WEBHOOK VERIFICATION (php)\n"
        . "  \$expected = hash_hmac('sha256', \$rawBody, \$apiKey);\n"
        . "  hash_equals(\$expected, \$_SERVER['HTTP_X_TGGATEWAY_SIGNATURE']);\n\n"
        . "IDEMPOTENCY\n"
        . "  Webhooks may be delivered more than once. Key your fulfilment on order_id\n"
        . "  and treat a repeat as a no-op.\n\n"
        . "  POST /api/create-order.php accepts an Idempotency-Key header. Send a unique\n"
        . "  string and a retry with the same body returns the original order (with\n"
        . "  Idempotent-Replay: true) instead of creating a second one. The same key with\n"
        . "  a different body is a 409. A 4xx validation failure does not consume the\n"
        . "  key. Keys are per merchant and replayable for 24 hours.\n\n"
        . "FILES IN THIS REPOSITORY\n"
        . "  api/create-order.php        order creation + rate limiting\n"
        . "  api/verify-order.php        authoritative status\n"
        . "  api/checkout-status.php     public display status\n"
        . "  api/qr-image.php            PNG QR stream\n"
        . "  api/manage-links.php        reusable payment links\n"
        . "  api/get-transactions.php    history with filters\n"
        . "  includes/settlement.php     receipt -> paid order, atomic\n"
        . "  includes/receipt.php        UTR/amount extraction from bank mail\n"
        . "  includes/webhook.php        signing, retry queue, SSRF policy\n"
        . "  openapi.json                OpenAPI 3.1 contract\n\n"
        . "CONSTRAINTS\n"
        . "  - Amounts are INR with two decimals.\n"
        . "  - Orders expire after the merchant's payment window (default 10 minutes).\n"
        . "  - An order is payable for its exact payable_amount; never round it.\n";
    return $out;
}
