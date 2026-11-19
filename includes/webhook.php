<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/notify.php';

function webhook_payload(array $order): array {
    return [
        'id' => 'evt_'.bin2hex(random_bytes(8)),
        'event' => 'payment.succeeded',
        'created_at' => gmdate('c'),
        'order_id' => $order['order_id'],
        'amount' => (float)$order['amount'],
        'payable_amount' => (float)$order['payable_amount'],
        'currency' => 'INR',
        'status' => $order['status'],
        'utr' => $order['utr'],
        'transaction_id' => $order['transaction_id'],
        'sender_name' => $order['sender_name'],
        'customer_name' => $order['customer_name'] ?? null,
        'customer_email' => $order['customer_email'] ?? null,
        'paid_at' => $order['paid_at'],
        'merchant_reference' => $order['note'] ?? null,
    ];
}

/**
 * Queue (and immediately attempt) a signed webhook for a successful order.
 * Delivery failures are recorded, never thrown at the payment path.
 */
function dispatch_webhook(PDO $pdo, string $orderId, bool $force = false): bool {
    $q = $pdo->prepare('SELECT o.order_id,o.merchant_id,o.amount,o.payable_amount,o.status,o.utr,o.transaction_id,o.sender_name,o.customer_name,o.customer_email,o.note,o.paid_at,COALESCE(o.webhook_url,m.webhook_url) webhook_url,m.api_key FROM orders o JOIN merchants m ON m.id=o.merchant_id WHERE o.order_id=?');
    $q->execute([$orderId]);
    $row = $q->fetch();
    if (!$row || $row['status'] !== 'success' || !$row['webhook_url']) return false;

    if (!$force) {
        $seen = $pdo->prepare('SELECT 1 FROM webhook_logs WHERE order_id=? LIMIT 1');
        $seen->execute([$orderId]);
        if ($seen->fetchColumn()) return false;
    }

    $body = json_encode(webhook_payload($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $body, (string)$row['api_key']);
    $pdo->prepare('INSERT INTO webhook_logs(order_id,event,payload,signature,attempt_count,next_retry_at,created_at) VALUES(?,?,?,?,0,?,?)')
        ->execute([$orderId,'payment.succeeded',$body,$signature,db_now(),db_now()]);
    $logId = (int)$pdo->lastInsertId();

    return deliver_webhook($pdo, $logId, (string)$row['webhook_url'], $body, $signature);
}

function deliver_webhook(PDO $pdo, int $logId, string $url, string $body, string $signature): bool {
    $result = tg_http_post($url, $body, [
        'Content-Type: application/json',
        'X-TGGateway-Signature: '.$signature,
        'X-TGGateway-Event: payment.succeeded',
        'User-Agent: TG-Gateways/1.0',
    ]);
    $ok = $result['code'] >= 200 && $result['code'] < 300;
    $pdo->prepare('UPDATE webhook_logs SET response_code=?,attempt_count=attempt_count+1,delivered_at=?,next_retry_at=?,last_error=? WHERE id=?')
        ->execute([$result['code'] ?: null, $ok ? db_now() : null, $ok ? null : db_now(300), $ok ? null : substr((string)$result['error'], 0, 250), $logId]);
    return $ok;
}

/** Called by the worker: retry due webhooks with capped exponential backoff. */
function retry_webhooks(PDO $pdo, int $limit = 25): int {
    $limit = max(1, min(100, $limit));
    $q = $pdo->prepare('SELECT id,order_id,payload,signature,attempt_count FROM webhook_logs WHERE delivered_at IS NULL AND next_retry_at<=? AND attempt_count<? ORDER BY next_retry_at LIMIT '.$limit);
    $q->execute([db_now(), webhook_max_attempts()]);
    $sent = 0;
    foreach ($q->fetchAll() as $log) {
        $m = $pdo->prepare('SELECT m.api_key,COALESCE(o.webhook_url,m.webhook_url) webhook_url FROM merchants m JOIN orders o ON o.merchant_id=m.id WHERE o.order_id=?');
        $m->execute([$log['order_id']]);
        $merchant = $m->fetch();
        if (!$merchant || !$merchant['webhook_url']) {
            // Destination removed: stop retrying rather than looping forever.
            $pdo->prepare('UPDATE webhook_logs SET attempt_count=?,last_error=?,next_retry_at=NULL WHERE id=?')
                ->execute([webhook_max_attempts(), 'Webhook endpoint not configured', $log['id']]);
            continue;
        }
        $attempt = (int)$log['attempt_count'];
        $result = tg_http_post((string)$merchant['webhook_url'], (string)$log['payload'], [
            'Content-Type: application/json',
            'X-TGGateway-Signature: '.(string)$log['signature'],
            'X-TGGateway-Event: '.(string)'payment.succeeded',
            'User-Agent: TG-Gateways/1.0',
        ]);
        $ok = $result['code'] >= 200 && $result['code'] < 300;
        $nextAttempt = $attempt + 1;
        $delay = webhook_backoff($nextAttempt);
        $pdo->prepare('UPDATE webhook_logs SET attempt_count=?,response_code=?,delivered_at=?,next_retry_at=?,last_error=? WHERE id=?')
            ->execute([$nextAttempt,$result['code'] ?: null,$ok ? db_now() : null,$ok ? null : db_now($delay),$ok ? null : substr((string)$result['error'], 0, 250),$log['id']]);
        $sent++;
    }
    return $sent;
}

function webhook_max_attempts(): int { return max(1, (int)envv('WEBHOOK_MAX_ATTEMPTS', '5')); }

/** Backoff in seconds for the next attempt number. */
function webhook_backoff(int $attempt): int {
    $base = max(5, (int)envv('WEBHOOK_BACKOFF_SECONDS', '300'));
    return $base * (2 ** max(0, min(6, $attempt - 1)));
}
