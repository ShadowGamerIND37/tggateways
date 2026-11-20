<?php
declare(strict_types=1);
/**
 * Legacy status endpoint for integrations built against an order-creation flow
 * that polls /api/status?order_ref=… after creating an order.
 *
 * The hosted checkout polls /api/checkout-status.php instead. Public health
 * lives at /api/health.php; this route always requires a merchant API key.
 */
require_once __DIR__.'/../includes/db.php';
require_method('GET');

$merchant = merchant_by_api_key();
rate_limit('status:'.$merchant['id'], 120, 60);

$orderId = (string)($_GET['order_id'] ?? '');
$orderRef = (string)($_GET['order_ref'] ?? $_GET['reference'] ?? '');
if ($orderId === '' && $orderRef === '') json_response(['error'=>'Provide order_id or order_ref'], 422);

$column = $orderId !== '' ? 'order_id' : 'note';
$value = $orderId !== '' ? substr($orderId, 0, 40) : substr($orderRef, 0, 255);

$q = db()->prepare("SELECT order_id,status,amount,payable_amount,utr,transaction_id,created_at,paid_at,expires_at FROM orders WHERE merchant_id=? AND $column=? ORDER BY created_at DESC LIMIT 1");
$q->execute([$merchant['id'], $value]);
$order = $q->fetch();
if (!$order) json_response(['error'=>'Order not found'], 404);

// An authenticated lookup should not depend on a checkout page or cron visit
// to transition an already-expired pending order. Re-read after the guarded
// update so concurrent settlement is never reported as expired by mistake.
if ($order['status'] === 'pending' && strtotime((string)$order['expires_at']) <= time()) {
    db()->prepare("UPDATE orders SET status='expired' WHERE merchant_id=? AND order_id=? AND status='pending' AND expires_at<=?")
        ->execute([$merchant['id'], $order['order_id'], db_now()]);
    $q->execute([$merchant['id'], $value]);
    $order = $q->fetch();
    if (!$order) json_response(['error'=>'Order not found'], 404);
}


$order['amount'] = (float)$order['amount'];
$order['payable_amount'] = (float)$order['payable_amount'];
$order['paid'] = $order['status'] === 'success';
json_response($order);
