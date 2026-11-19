<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/db.php';
$merchant = merchant_by_api_key();
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    rate_limit('links:'.$merchant['id'], 30, 60);
    $in = request_json();
    $title = $in['title'] ?? null;
    $rawAmount = $in['amount'] ?? null;
    $redirect = $in['redirect_url'] ?? null;
    if (!is_string($title) || trim($title) === '' || (!is_int($rawAmount) && !is_float($rawAmount) && !is_string($rawAmount)) || !is_numeric($rawAmount) || !valid_http_url($redirect)) {
        json_response(['error'=>'Provide a title, a numeric amount, and an optional HTTPS redirect URL'], 422);
    }
    if (isset($in['description']) && !is_string($in['description'])) json_response(['error'=>'description must be a string'], 422);
    $amount = (float)$rawAmount;
    if ($amount < 1 || $amount > 1000000 || round($amount, 2) !== $amount) json_response(['error'=>'amount must be between 1 and 1,000,000 with at most two decimals'], 422);

    $slug = bin2hex(random_bytes(9));
    $q = $pdo->prepare('INSERT INTO payment_links(merchant_id,slug,title,amount,description,redirect_url,is_active,created_at) VALUES(?,?,?,?,?,?,1,?)');
    $q->execute([$merchant['id'],$slug,substr(trim($title),0,160),number_format($amount,2,'.',''),substr((string)($in['description'] ?? ''),0,1500),is_string($redirect) && $redirect !== '' ? $redirect : null,db_now()]);
    $base = rtrim((string)envv('APP_URL',''),'/');
    json_response(['slug'=>$slug,'title'=>trim($title),'amount'=>number_format($amount,2,'.',''),'url'=>$base.'/pay.php?link='.rawurlencode($slug)], 201);
}

require_method('GET');
$q = $pdo->prepare('SELECT slug,title,amount,description,redirect_url,is_active,expires_at,created_at FROM payment_links WHERE merchant_id=? ORDER BY created_at DESC LIMIT 100');
$q->execute([$merchant['id']]);
json_response(['links'=>$q->fetchAll()]);
