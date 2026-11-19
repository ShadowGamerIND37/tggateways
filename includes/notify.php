<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';

/**
 * Outbound HTTP POST with the deployment's SSRF policy: HTTPS only, public
 * destinations only, DNS-pinned to the exact addresses that were validated so a
 * second DNS answer cannot redirect the request to an internal host.
 * Returns ['code'=>int,'body'=>string,'error'=>?string].
 */
function tg_http_post(string $url, string $body, array $headers, int $timeout = 8): array {
    if (!function_exists('curl_init')) return ['code' => 0, 'body' => '', 'error' => 'cURL extension not available'];
    $resolve = safe_webhook_resolve($url);
    if ($resolve === false) return ['code' => 0, 'body' => '', 'error' => 'Destination rejected by outbound URL policy'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE => $resolve,
    ]);
    if (filter_var(envv('WEBHOOK_STRICT_TLS', 'true'), FILTER_VALIDATE_BOOLEAN)) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = $response === false ? curl_error($ch) : null;
    unset($ch);
    return ['code' => $code, 'body' => is_string($response) ? $response : '', 'error' => $error];
}

/**
 * Discord notification for a successful payment. Idempotent per order+channel.
 * Never blocks or breaks the settlement path.
 */
function notify_discord(PDO $pdo, string $orderId): bool {
    $q = $pdo->prepare('SELECT o.order_id,o.amount,o.utr,o.sender_name,o.paid_at,m.name merchant_name,m.discord_webhook_url FROM orders o JOIN merchants m ON m.id=o.merchant_id WHERE o.order_id=?');
    $q->execute([$orderId]);
    $row = $q->fetch();
    if (!$row || !$row['discord_webhook_url']) return false;

    $seen = $pdo->prepare("SELECT 1 FROM notification_logs WHERE order_id=? AND channel='discord' AND delivered_at IS NOT NULL LIMIT 1");
    $seen->execute([$orderId]);
    if ($seen->fetchColumn()) return true;

    $content = sprintf(
        "💰 **Payment received**\n**Amount:** ₹%s\n**Order:** `%s`\n**UTR:** `%s`\n**Payer:** %s\n**Merchant:** %s",
        number_format((float)$row['amount'], 2),
        $row['order_id'],
        $row['utr'] ?: '—',
        $row['sender_name'] ?: '—',
        $row['merchant_name']
    );
    $body = json_encode(['content' => $content, 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result = tg_http_post((string)$row['discord_webhook_url'], $body, ['Content-Type: application/json', 'User-Agent: TG-Gateways/1.0']);
    $ok = $result['code'] >= 200 && $result['code'] < 300;
    $pdo->prepare('INSERT INTO notification_logs(merchant_id,order_id,channel,response_code,last_error,delivered_at,created_at) SELECT merchant_id,order_id,?,?,?,?,? FROM orders WHERE order_id=?')
        ->execute(['discord',$result['code'] ?: null,$ok ? null : substr((string)$result['error'], 0, 250),$ok ? db_now() : null,db_now(),$orderId]);
    return $ok;
}

/** Run every configured notification for an order. Failures are logged only. */
function notify_all(PDO $pdo, string $orderId): void {
    foreach (['discord' => 'notify_discord'] as $channel => $fn) {
        if (!function_exists($fn)) continue;
        try { $fn($pdo, $orderId); }
        catch (Throwable $e) { error_log('Notification ('.$channel.') failed for order '.$orderId.': '.$e->getMessage()); }
    }
}
