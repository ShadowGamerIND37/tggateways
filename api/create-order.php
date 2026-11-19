<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/db.php';
require_method('POST');
$merchant = merchant_by_api_key();
rate_limit('create:'.$merchant['id'], 60, 60);
$in = request_json();

$rawAmount = $in['amount'] ?? null;
if ((!is_int($rawAmount) && !is_float($rawAmount) && !is_string($rawAmount)) || !is_numeric($rawAmount)) json_response(['error'=>'amount must be numeric'], 422);
$amount = (float)$rawAmount;
if (!is_finite($amount) || $amount < 1 || $amount > 1000000 || round($amount, 2) !== $amount) {
    json_response(['error'=>'amount must be between 1 and 1,000,000 with at most two decimal places'], 422);
}
foreach (['customer_name','customer_email','customer_phone','note'] as $field) {
    if (isset($in[$field]) && !is_string($in[$field])) json_response(['error'=>$field.' must be a string'], 422);
}
$email = $in['customer_email'] ?? null;
if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['error'=>'customer_email must be a valid email address'], 422);

$redirect = $in['redirect_url'] ?? $merchant['redirect_url'];
$webhook = $in['webhook_url'] ?? null;
if (!valid_http_url($redirect) || !valid_http_url($webhook)) json_response(['error'=>'Redirect and webhook URLs must be valid HTTPS URLs'], 422);

$pdo = db();
$accountStmt = $pdo->prepare('SELECT id,upi_id,payee_name FROM merchant_accounts WHERE merchant_id=? AND is_active=1 ORDER BY id LIMIT 1');
$accountStmt->execute([$merchant['id']]);
$account = $accountStmt->fetch();
if (!$account) json_response(['error'=>'Merchant has not configured an active UPI account'], 409);

$timeout = max(2, min(120, (int)($merchant['payment_timeout_minutes'] ?? 10)));
$orderId = 'tg_'.strtoupper(bin2hex(random_bytes(14)));
$amountText = number_format($amount, 2, '.', '');
$note = isset($in['note']) && $in['note'] !== '' ? substr((string)$in['note'], 0, 255) : null;

$insert = $pdo->prepare("INSERT INTO orders(order_id,merchant_id,merchant_account_id,amount,payable_amount,status,source,webhook_url,redirect_url,customer_name,customer_email,customer_phone,note,expires_at,created_at) VALUES(?,?,?,?,?,'pending','api',?,?,?,?,?,?,?,?)");
try {
    // The order reference is embedded in the UPI transaction note so a receipt
    // can be matched by reference instead of amount alone.
    $insert->execute([
        $orderId, $merchant['id'], $account['id'], $amountText, $amountText,
        is_string($webhook) && $webhook !== '' ? $webhook : null,
        is_string($redirect) && $redirect !== '' ? $redirect : null,
        substr((string)($in['customer_name'] ?? ''), 0, 120) ?: null,
        $email ?: null,
        substr((string)($in['customer_phone'] ?? ''), 0, 32) ?: null,
        $note,
        db_now($timeout * 60),
        db_now(),
    ]);
} catch (Throwable $e) {
    error_log('Order insert failed: '.$e->getMessage());
    json_response(['error'=>'Could not create order'], 500);
}

$payee = $account['payee_name'] ?: $merchant['name'];
$upi = 'upi://pay?'.http_build_query(['pa'=>$account['upi_id'],'pn'=>$payee,'am'=>$amountText,'cu'=>'INR','tn'=>$orderId]);
$base = rtrim((string)envv('APP_URL', ''), '/');

json_response([
    'order_id' => $orderId,
    'status' => 'pending',
    'currency' => 'INR',
    'amount' => (float)$amountText,
    'payable_amount' => (float)$amountText,
    'checkout_url' => $base.'/pay.php?order_id='.rawurlencode($orderId),
    'checkout_path' => '/pay.php?order_id='.rawurlencode($orderId),
    'qr_url' => '/api/qr-image.php?order_id='.rawurlencode($orderId),
    'upi_intent' => $upi,
    'upi_id' => $account['upi_id'],
    'expires_at' => db_now($timeout * 60),
    'expires_in_seconds' => $timeout * 60,
], 201);
