<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';
$merchant = require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
// CLI test harness supplies $_GET without filter_input's web SAPI population.
if (PHP_SAPI === 'cli') $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$q = db()->prepare('SELECT invoice_no,description,amount,tax_amount,status,issued_at FROM invoices WHERE id=? AND merchant_id=?');
$q->execute([$id ?: 0, $merchant['id']]);
$invoice = $q->fetch();
if (!$invoice) { http_response_code(404); exit('Billing record unavailable'); }
tg_header('Cache-Control: no-store');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Billing record <?=h($invoice['invoice_no'])?></title><link rel="stylesheet" href="/assets/css/design-system.css"><link rel="stylesheet" href="/assets/css/marketing.css"><style>
.billing-document{max-width:700px;margin:50px auto;background:#fff;border:1px solid #e7ebf2;border-radius:18px;padding:36px;box-shadow:0 18px 55px #20365b12}.billing-document h1{margin:28px 0 8px}.billing-document dl{display:grid;grid-template-columns:minmax(120px,1fr) 2fr;gap:16px;margin:28px 0}.billing-document dt{color:#68758a}.billing-document dd{margin:0;overflow-wrap:anywhere}.billing-document .total{border-top:1px solid #e7ebf2;padding-top:16px;font-weight:700}.billing-document p{color:#68758a;line-height:1.7}@media(max-width:600px){.billing-document{margin:14px;padding:24px}}@media print{.no-print{display:none}.billing-document{margin:0;border:0;box-shadow:none}}
</style></head><body><article class="billing-document">
<a class="brand" href="/dashboard.php"><img src="/assets/img/tg-mark.png" alt="">TG Gateways</a><h1>Billing record</h1><p>For <?=h($merchant['name'])?> · <?=h($invoice['invoice_no'])?></p>
<dl><dt>Description</dt><dd><?=h($invoice['description'])?></dd><dt>Issued</dt><dd><?=h($invoice['issued_at'])?></dd><dt>Status</dt><dd><?=h(ucfirst($invoice['status']))?></dd><dt>Subtotal</dt><dd>₹<?=number_format((float)$invoice['amount'], 2)?></dd><dt>Tax</dt><dd>₹<?=number_format((float)$invoice['tax_amount'], 2)?></dd><dt class="total">Total</dt><dd class="total">₹<?=number_format((float)$invoice['amount'] + (float)$invoice['tax_amount'], 2)?></dd></dl>
<p>This is a record of the information stored in the application. It is not represented as a statutory tax invoice.</p><div class="no-print"><button class="btn" type="button" onclick="window.print()">Print / Save as PDF</button> <a class="btn btn-outline" href="/invoices.php">Back to billing</a></div>
</article></body></html>
