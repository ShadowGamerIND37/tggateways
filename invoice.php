<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/refund.php';
$merchant = require_login();
$id = substr((string)($_GET['order_id'] ?? ''), 0, 64);
$q = db()->prepare("SELECT order_id,amount,payable_amount,status,utr,paid_at,created_at FROM orders WHERE merchant_id=? AND order_id=? AND status IN ('success','refunded')");
$q->execute([$merchant['id'], $id]);
$o = $q->fetch();
if (!$o) { http_response_code(404); exit('Receipt unavailable'); }
$refund = refund_summary(db(), $id);
$refunds = refunds_for_order(db(), (int)$merchant['id'], $id);
tg_header('Cache-Control: no-store');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment receipt <?=h($o['order_id'])?></title><link rel="stylesheet" href="/assets/css/design-system.css"><link rel="stylesheet" href="/assets/css/marketing.css"><style>
.receipt{max-width:700px;margin:50px auto;background:white;border:1px solid #e7ebf2;padding:36px;border-radius:18px;box-shadow:0 18px 55px #20365b12}.receipt h1{margin:28px 0 8px}.receipt dl{display:grid;grid-template-columns:minmax(120px,1fr) 2fr;gap:16px;margin:28px 0}.receipt dt{color:#68758a}.receipt dd{margin:0;overflow-wrap:anywhere}.receipt p{color:#68758a;line-height:1.7}.receipt-refund{border-top:1px solid #e7ebf2;padding:16px 0}.receipt-refund strong{display:block}@media(max-width:600px){.receipt{margin:14px;padding:24px}}@media print{.no-print{display:none}.receipt{margin:0;border:0;box-shadow:none}}
</style></head><body><article class="receipt"><a class="brand" href="/dashboard.php"><img src="/assets/img/tg-mark.png" alt="">TG Gateways</a><h1>Payment receipt</h1><p>Merchant: <?=h($merchant['name'])?></p>
<dl><dt>Order ID</dt><dd><?=h($o['order_id'])?></dd><dt>Amount received</dt><dd>₹<?=number_format((float)$o['payable_amount'],2)?> INR</dd><dt>Payment status</dt><dd><?=$o['status'] === 'refunded' ? 'Fully refunded' : ($refund['count'] ? 'Paid · partially refunded' : 'Paid')?></dd><dt>UTR</dt><dd><?=h($o['utr']?:'—')?></dd><dt>Paid at</dt><dd><?=h($o['paid_at']?:'—')?></dd><dt>Recorded refunds</dt><dd>₹<?=number_format($refund['refunded'],2)?></dd><dt>Net after refunds</dt><dd>₹<?=number_format($refund['remaining'],2)?></dd></dl>
<?php if ($refunds): ?><h2>Recorded refunds</h2><?php foreach ($refunds as $item): ?><div class="receipt-refund"><strong>₹<?=number_format((float)$item['amount'],2)?> · <?=h((string)$item['refund_id'])?></strong><span><?=h((string)$item['created_at'])?> · Reference: <?=h((string)($item['utr'] ?: 'Not provided'))?></span></div><?php endforeach ?><p>Refunds are recorded by the merchant; this receipt does not initiate a transfer.</p><?php endif ?>
<p>This receipt reflects payment and refund status recorded by TG Gateways and is not a tax invoice.</p><div class="no-print"><button class="btn" type="button" onclick="window.print()">Print / Save as PDF</button> <a class="btn btn-outline" href="/transactions.php">Back to transactions</a></div></article></body></html>
