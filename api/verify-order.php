<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/db.php';
require_method('GET');
$merchant = merchant_by_api_key();
rate_limit('verify:'.$merchant['id'], 120, 60);
$orderId = substr((string)($_GET['order_id'] ?? ''), 0, 40);
$q = db()->prepare('SELECT order_id,status,amount,payable_amount,utr,transaction_id,sender_name,created_at,paid_at,expires_at FROM orders WHERE merchant_id=? AND order_id=?');
$q->execute([$merchant['id'], $orderId]);
$order = $q->fetch();
if (!$order) json_response(['error'=>'Order not found'], 404);

// An authenticated lookup should not depend on a checkout page or cron visit
// to transition an already-expired pending order. Re-read after the guarded
// update so concurrent settlement is never reported as expired by mistake.
if ($order['status'] === 'pending' && strtotime((string)$order['expires_at']) <= time()) {
    db()->prepare("UPDATE orders SET status='expired' WHERE merchant_id=? AND order_id=? AND status='pending' AND expires_at<=?")
        ->execute([$merchant['id'], $order['order_id'], db_now()]);
    $q->execute([$merchant['id'], $orderId]);
    $order = $q->fetch();
    if (!$order) json_response(['error'=>'Order not found'], 404);
}

$order['amount'] = (float)$order['amount'];
$order['payable_amount'] = (float)$order['payable_amount'];
json_response($order);
