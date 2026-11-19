<?php
declare(strict_types=1);
/**
 * Test-only webhook transport.
 *
 * Production delivery uses tg_http_post() (HTTPS only, DNS-pinned, SSRF-safe).
 * The test suite needs to observe the exact signed request and to simulate a
 * failing endpoint, so it points TG_WEBHOOK_TRANSPORT_FILE at this file and
 * reads the spool. No network is involved: the wire format (headers, body,
 * signature, retry/backoff bookkeeping) is what these tests assert.
 *
 * Env:
 *   TG_WEBHOOK_SPOOL       file to append one JSON record per attempt
 *   TG_WEBHOOK_TEST_CODE   HTTP status the fake endpoint returns (default 200)
 */
return static function (string $url, string $body, array $headers): array {
    $spool = (string)getenv('TG_WEBHOOK_SPOOL');
    if ($spool !== '') {
        file_put_contents($spool, json_encode([
            'url' => $url,
            'body' => $body,
            'headers' => $headers,
        ]).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    $code = (int)(getenv('TG_WEBHOOK_TEST_CODE') ?: 200);
    return [
        'code' => $code,
        'body' => $code >= 200 && $code < 300 ? '{"ok":true}' : 'endpoint error',
        'error' => $code >= 200 && $code < 300 ? null : 'Simulated HTTP '.$code,
    ];
};
