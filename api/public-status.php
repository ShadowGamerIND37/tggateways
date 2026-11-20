<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/db.php';
require_method('GET');

/**
 * Public status for the hosted checkout page only. It intentionally exposes no
 * customer data and is not a fulfillment signal: merchants must rely on the
 * signed webhook or the authenticated status endpoint.
 */
$orderId = (string)($_GET['order_id'] ?? '');
if (!preg_match('/^tg_[A-Z0-9]{8,40}$/', $orderId)) json_response(['error'=>'Invalid order_id'], 422);
rate_limit('public-status', 180, 60);

$pdo = db();
$q = $pdo->prepare('SELECT order_id,status,payable_amount,expires_at,utr,transaction_id,paid_at FROM orders WHERE order_id=?');
$q->execute([$orderId]);
$o = $q->fetch();
if (!$o) json_response(['error'=>'Order not found'], 404);

if ($o['status'] === 'pending' && strtotime((string)$o['expires_at']) <= time()) {
    $pdo->prepare("UPDATE orders SET status='expired' WHERE order_id=? AND status='pending' AND expires_at<=?")->execute([$orderId, db_now()]);
    $q->execute([$orderId]);
    $o = $q->fetch();
}
json_response([
    'order_id' => $o['order_id'],
    'status' => $o['status'],
    'payable_amount' => (float)$o['payable_amount'],
    'expires_at' => $o['expires_at'],
    'paid_at' => $o['status'] === 'success' ? $o['paid_at'] : null,
    'utr' => $o['status'] === 'success' ? $o['utr'] : null,
    'transaction_id' => $o['status'] === 'success' ? $o['transaction_id'] : null,
]);
