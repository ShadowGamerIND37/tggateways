<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';

/** HTML-escape helper shared by public and dashboard pages. */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Create a pending order. Shared by the API and hosted payment links. */
function create_order_record(PDO $pdo, int $merchantId, int $timeoutSeconds, array $fields): array {
    $orderId = 'tg_'.strtoupper(bin2hex(random_bytes(14)));
    $amountText = number_format((float)$fields['amount'], 2, '.', '');
    $timeout = max(1, min(7200, $timeoutSeconds));
    $expiresAt = db_now($timeout);
    $stmt = $pdo->prepare("INSERT INTO orders(order_id,merchant_id,merchant_account_id,payment_link_id,amount,payable_amount,status,source,webhook_url,redirect_url,customer_name,customer_email,customer_phone,note,expires_at,created_at) VALUES(?,?,?,?,?,?,'pending',?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $orderId,
        $merchantId,
        $fields['merchant_account_id'] ?? null,
        $fields['payment_link_id'] ?? null,
        $amountText,
        $amountText,
        $fields['source'] ?? 'api',
        $fields['webhook_url'] ?? null,
        $fields['redirect_url'] ?? null,
        $fields['customer_name'] ?? null,
        $fields['customer_email'] ?? null,
        $fields['customer_phone'] ?? null,
        $fields['note'] ?? null,
        $expiresAt,
        db_now(),
    ]);
    return ['order_id'=>$orderId,'amount'=>$amountText,'expires_at'=>$expiresAt,'expires_in_seconds'=>$timeout];
}

/** Build the UPI deep link for an order. */
function upi_intent(array $order): string {
    return 'upi://pay?'.http_build_query([
        'pa'=>$order['upi_id'],
        'pn'=>$order['payee_name'] ?: $order['merchant_name'],
        'am'=>number_format((float)$order['payable_amount'],2,'.',''),
        'cu'=>'INR',
        'tn'=>$order['order_id'],
    ]);
}

/** Fetch an order with its merchant + receiving account for public checkout. */
function find_checkout_order(PDO $pdo, string $orderId): ?array {
    $q = $pdo->prepare('SELECT o.order_id,o.merchant_id,o.merchant_account_id,o.amount,o.payable_amount,o.status,o.utr,o.paid_at,o.expires_at,o.note,m.name merchant_name,m.redirect_url merchant_redirect,COALESCE(o.redirect_url,m.redirect_url) redirect_url,m.brand_color,a.upi_id,a.payee_name FROM orders o JOIN merchants m ON m.id=o.merchant_id LEFT JOIN merchant_accounts a ON a.id=o.merchant_account_id WHERE o.order_id=? LIMIT 1');
    $q->execute([$orderId]);
    $order = $q->fetch();
    return $order ?: null;
}

/** Active receiving account for a merchant (first active), if any. */
function active_account(PDO $pdo, int $merchantId): ?array {
    $q = $pdo->prepare('SELECT id,upi_id,payee_name FROM merchant_accounts WHERE merchant_id=? AND is_active=1 ORDER BY id LIMIT 1');
    $q->execute([$merchantId]);
    $row = $q->fetch();
    return $row ?: null;
}
