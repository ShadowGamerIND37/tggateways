<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/orders.php';
require_method('GET');
rate_limit('qr', 120, 60);

$orderId = (string)($_GET['order_id'] ?? '');
if (!preg_match('/^tg_[A-Z0-9]{8,40}$/', $orderId)) { http_response_code(422); exit('Invalid order_id'); }

$order = find_checkout_order(db(), $orderId);
if ($order && $order['status'] === 'pending' && strtotime((string)$order['expires_at']) <= time()) {
    db()->prepare("UPDATE orders SET status='expired' WHERE order_id=? AND status='pending' AND expires_at<=?")->execute([$orderId, db_now()]);
    $order = find_checkout_order(db(), $orderId);
}
if (!$order || $order['status'] !== 'pending') { http_response_code(404); exit('QR unavailable'); }

$upi = upi_intent($order);

// Optional: server-side PNG when the Composer QR package is installed. The
// hosted checkout renders the QR in the browser with the vendored MIT library,
// so this endpoint is only needed by integrations that want a static image.
$autoload = dirname(__DIR__).'/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (class_exists(Endroid\QrCode\Builder\Builder::class)) {
        try {
            $builder = new Endroid\QrCode\Builder\Builder(
                writer: new Endroid\QrCode\Writer\PngWriter(),
                data: $upi,
                size: 360,
                margin: 16,
            );
            $result = $builder->build();
            tg_header('Content-Type: '.$result->getMimeType());
            tg_header('Cache-Control: no-store');
            echo $result->getString();
            exit;
        } catch (Throwable $e) {
            error_log('QR generation failed: '.$e->getMessage());
        }
    }
}

// No server-side QR package installed. Return the payment intent as JSON so a
// client can render the QR itself instead of receiving a broken image.
http_response_code(503);
tg_header('Content-Type: application/json; charset=utf-8');
tg_header('Cache-Control: no-store');
echo json_encode([
    'error' => 'Server-side QR rendering requires the Composer package (composer install). The hosted checkout renders the QR in the browser.',
    'upi_intent' => $upi,
]);
