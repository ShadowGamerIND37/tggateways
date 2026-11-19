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
$order['amount'] = (float)$order['amount'];
$order['payable_amount'] = (float)$order['payable_amount'];
json_response($order);
