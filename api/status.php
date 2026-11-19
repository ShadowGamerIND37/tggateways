<?php
declare(strict_types=1);
/**
 * Legacy status endpoint for integrations built against an order-creation flow
 * that polls /api/status?order_ref=… after creating an order.
 *
 * The hosted checkout polls /api/checkout-status.php instead. Both return the
 * same facts; this route additionally authenticates the merchant API key so it
 * can be used from server-side code without exposing customer data.
 */
require_once __DIR__.'/../includes/db.php';
require_method('GET');

// Allow health probes to reach the public health payload without a key.
if (isset($_GET['health']) || ($_SERVER['HTTP_ACCEPT'] ?? '') === 'text/health') {
    require __DIR__.'/health.php';
}

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

$order['amount'] = (float)$order['amount'];
$order['payable_amount'] = (float)$order['payable_amount'];
$order['paid'] = $order['status'] === 'success';
json_response($order);
