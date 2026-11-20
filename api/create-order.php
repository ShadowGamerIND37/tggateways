<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/idempotency.php';
require_method('POST');
$merchant = merchant_by_api_key();

/* Idempotency, before anything can have a side effect.
   A caller that times out has no way to know whether the order was created;
   retrying is the only sane client behaviour, so the server must make the retry
   safe. Without a key the endpoint behaves exactly as before. */
$idemKey = idem_key_from_request();
$idemHash = '';
if ($idemKey !== null) {
    if (strlen($idemKey) > IDEM_KEY_MAX) {
        json_response(['error' => 'Idempotency-Key must be at most '.IDEM_KEY_MAX.' characters'], 400);
    }
    if (!preg_match('/^[\x21-\x7E]+$/', $idemKey)) {
        json_response(['error' => 'Idempotency-Key must be printable ASCII with no spaces'], 400);
    }
    $idemHash = idem_request_hash(request_body());
    $reserve = idem_reserve(db(), (int)$merchant['id'], $idemKey, $idemHash);
    if ($reserve['state'] === 'replay') {
        tg_header('Idempotent-Replay: true');
        tg_header('Content-Type: application/json; charset=utf-8');
        tg_header('Cache-Control: no-store');
        http_response_code((int)($reserve['row']['response_code'] ?? 201));
        echo (string)$reserve['row']['response_body'];
        exit;
    }
    if ($reserve['state'] === 'mismatch') {
        json_response([
            'error' => 'This Idempotency-Key was already used with a different request body',
            'hint' => 'Use a new key for a different request, or resend the original body.',
        ], 409);
    }
    if ($reserve['state'] === 'inflight') {
        tg_header('Retry-After: 1');
        json_response([
            'error' => 'A request with this Idempotency-Key is still being processed',
            'hint' => 'Retry the same request shortly; it will not create a second order.',
        ], 409);
    }
}
// Defaults match the published contract; operators can tune them per deployment.
rate_limit('create:'.$merchant['id'], (int)envv('RATE_LIMIT_CREATE_MAX', '60'), (int)envv('RATE_LIMIT_CREATE_WINDOW', '60'));
/* Any 4xx before the insert means nothing was created, so the key must not be
   burned: the caller fixes the body and retries with the same key. */
$fail = static function (array $payload, int $status) use (&$idemKey, $merchant): never {
    if ($idemKey !== null) idem_release(db(), (int)$merchant['id'], $idemKey);
    json_response($payload, $status);
};

$in = request_json();

$rawAmount = $in['amount'] ?? null;
if ((!is_int($rawAmount) && !is_float($rawAmount) && !is_string($rawAmount)) || !is_numeric($rawAmount)) $fail(['error'=>'amount must be numeric'], 422);
$amount = (float)$rawAmount;
if (!is_finite($amount) || $amount < 1 || $amount > 1000000 || round($amount, 2) !== $amount) {
    $fail(['error'=>'amount must be between 1 and 1,000,000 with at most two decimal places'], 422);
}
foreach (['customer_name','customer_email','customer_phone','note'] as $field) {
    if (isset($in[$field]) && !is_string($in[$field])) $fail(['error'=>$field.' must be a string'], 422);
}
$email = $in['customer_email'] ?? null;
if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $fail(['error'=>'customer_email must be a valid email address'], 422);

$redirect = $in['redirect_url'] ?? $merchant['redirect_url'];
$webhook = $in['webhook_url'] ?? null;
if (!valid_http_url($redirect) || !valid_http_url($webhook)) $fail(['error'=>'Redirect and webhook URLs must be valid HTTPS URLs'], 422);

$pdo = db();
$accountStmt = $pdo->prepare('SELECT id,upi_id,payee_name FROM merchant_accounts WHERE merchant_id=? AND is_active=1 ORDER BY id LIMIT 1');
$accountStmt->execute([$merchant['id']]);
$account = $accountStmt->fetch();
if (!$account) $fail(['error'=>'Merchant has not configured an active UPI account'], 409);

$timeout = payment_window_seconds($merchant);
$expiresAt = db_now($timeout);
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
        $expiresAt,
        db_now(),
    ]);
} catch (Throwable $e) {
    error_log('Order insert failed: '.$e->getMessage());
    $fail(['error'=>'Could not create order'], 500);
}

$payee = $account['payee_name'] ?: $merchant['name'];
$upi = 'upi://pay?'.http_build_query(['pa'=>$account['upi_id'],'pn'=>$payee,'am'=>$amountText,'cu'=>'INR','tn'=>$orderId]);
$base = rtrim((string)envv('APP_URL', ''), '/');

$responseBody = json_encode([
    'order_id' => $orderId,
    'status' => 'pending',
    'currency' => 'INR',
    'amount' => (float)$amountText,
    'payable_amount' => (float)$amountText,
    'checkout_url' => $base.'/pay.php?order_id='.rawurlencode($orderId),
    'checkout_path' => '/pay.php?order_id='.rawurlencode($orderId),
    // Absolute, like checkout_url: a server-side integration should not have to
    // guess the deployment's host, which is exactly what a port change affects.
    'qr_url' => $base.'/api/qr-image.php?order_id='.rawurlencode($orderId),
    'qr_path' => '/api/qr-image.php?order_id='.rawurlencode($orderId),
    'upi_intent' => $upi,
    'upi_id' => $account['upi_id'],
    'expires_at' => $expiresAt,
    'expires_in_seconds' => $timeout,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

/* Persist the exact bytes before sending them, so a replay is byte-identical
   and the caller's parser sees no difference between the first response and a
   later one. */
if ($idemKey !== null) idem_complete(db(), (int)$merchant['id'], $idemKey, $responseBody, 201, $orderId);

http_response_code(201);
tg_header('Content-Type: application/json; charset=utf-8');
tg_header('Cache-Control: no-store');
echo $responseBody;
exit;
