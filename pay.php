<?php
declare(strict_types=1);
require_once __DIR__.'/includes/orders.php';

$pdo = db();
$orderId = substr((string)($_GET['order_id'] ?? ''), 0, 40);
$linkSlug = substr((string)($_GET['link'] ?? ''), 0, 100);
$notice = '';

if ($linkSlug !== '') {
    rate_limit('pay-link', 20, 60);
    $q = $pdo->prepare('SELECT l.*,m.name merchant_name,m.id merchant_id,m.payment_timeout_minutes FROM payment_links l JOIN merchants m ON m.id=l.merchant_id WHERE l.slug=? AND l.is_active=1');
    $q->execute([$linkSlug]);
    $link = $q->fetch();
    if (!$link || ($link['expires_at'] && strtotime((string)$link['expires_at']) < time())) {
        $notice = 'This payment link is unavailable or has expired.';
    } else {
        $account = active_account($pdo, (int)$link['merchant_id']);
        if (!$account) {
            $notice = 'This merchant has not configured a receiving account yet.';
        } else {
            $created = create_order_record($pdo, (int)$link['merchant_id'], (int)$link['payment_timeout_minutes'], [
                'amount' => $link['amount'],
                'merchant_account_id' => $account['id'],
                'payment_link_id' => $link['id'],
                'source' => 'link',
                'redirect_url' => $link['redirect_url'],
                'note' => $link['title'],
            ]);
            redirect_to('/pay.php?order_id='.rawurlencode($created['order_id']));
        }
    }
}

$order = $orderId !== '' ? find_checkout_order($pdo, $orderId) : null;
if (!$order) {
    $notice = $notice ?: 'Payment order was not found.';
} elseif ($order['status'] === 'pending' && strtotime((string)$order['expires_at']) <= time()) {
    $pdo->prepare("UPDATE orders SET status='expired' WHERE order_id=? AND status='pending'")->execute([$order['order_id']]);
    $order['status'] = 'expired';
}

$deadline = $order ? strtotime((string)$order['expires_at']) : time();
$upi = $order ? upi_intent($order) : '';
$redirect = $order && valid_http_url($order['redirect_url']) ? (string)$order['redirect_url'] : '';
$brand = $order && preg_match('/^#[0-9a-f]{6}$/i', (string)$order['brand_color']) ? $order['brand_color'] : '#2563eb';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Secure checkout — TG Gateways</title>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body{background:#f1f4f9}
.checkout{--brand:<?=h($brand)?>;width:min(460px,calc(100% - 28px));margin:46px auto;background:#fff;border:1px solid #e7ebf2;border-radius:18px;padding:27px;box-shadow:0 18px 60px #20365b14;text-align:center}
.checkout .brand{margin:0 auto 4px}
.checkout-brand{font-size:11px;color:#8994a5;margin-top:16px;letter-spacing:.08em}
.checkout h1{font-size:19px;margin:6px}
.checkout-total{font-size:34px;font-weight:700;letter-spacing:-1px;margin:15px 0 3px}
.checkout-meta{font-size:10px;color:#8692a5}
.checkout-qr{width:238px;height:238px;margin:20px auto 13px;display:block;border:1px solid #eef1f6;border-radius:14px;padding:8px;background:#fff}
.upi-actions{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
.upi-actions a{padding:9px 12px;border:1px solid #e3e8f0;border-radius:9px;font-size:10px;font-weight:600;color:#23314d}
.upi-actions a:hover{border-color:var(--brand);color:var(--brand)}
.wait{margin-top:16px;padding:13px;background:#f1f6ff;border-radius:10px;font-size:11px;color:#49648d}
.expired{background:#fff2ee;color:#b84c35}
.success{background:#eaf8f1;color:#18895f}
.checkout-foot{font-size:9px;color:#9aa4b4;margin-top:18px;line-height:1.7}
.checkout-foot a{color:#5473ad}
.copy-upi{margin-top:12px;font-size:10px;color:#6b7a90}
.copy-upi code{background:#f4f6fa;border:1px solid #e7ebf2;border-radius:7px;padding:3px 7px}
</style>
</head>
<body>
<main class="checkout">
  <a class="brand" href="/"><img src="/assets/img/logo.svg" alt="">TG Gateways</a>
  <?php if ($notice): ?>
    <div class="note" style="margin-top:20px"><?=h($notice)?></div>
  <?php elseif ($order): ?>
    <div class="checkout-brand">PAYING TO</div>
    <h1><?=h($order['merchant_name'])?></h1>
    <div class="checkout-total">₹<?=number_format((float)$order['payable_amount'],2)?></div>
    <div class="checkout-meta">INR · Order <?=h($order['order_id'])?></div>
    <?php if ($order['status'] === 'pending'): ?>
      <div class="checkout-qr" id="qr" data-upi="<?=h($upi)?>" aria-label="UPI payment QR code"></div>
      <div class="upi-actions">
        <a href="<?=h($upi)?>" rel="noopener">▦ Open UPI app</a>
        <a href="<?=h($upi)?>" rel="noopener">Google Pay</a>
        <a href="<?=h($upi)?>" rel="noopener">PhonePe</a>
        <a href="<?=h($upi)?>" rel="noopener">Paytm</a>
      </div>
      <div class="wait" id="payment-status">◌ Waiting for payment…</div>
      <div class="checkout-meta" style="margin-top:12px">Expires in <strong id="countdown">--:--</strong></div>
      <div class="copy-upi">or pay to <code id="upi-id"><?=h($order['upi_id'] ?: '—')?></code>
        <button type="button" class="btn btn-outline" style="padding:4px 8px;font-size:9px" data-copy-target="#upi-id">Copy UPI ID</button>
      </div>
    <?php else: ?>
      <div class="wait <?=$order['status'] === 'success' ? 'success' : 'expired'?>">
        <?php if ($order['status'] === 'success'): ?>
          ✓ Payment received<?=$order['utr'] ? ' · UTR '.h($order['utr']) : ''?>
        <?php else: ?>
          This payment order has expired. Ask the merchant for a new link.
        <?php endif ?>
      </div>
    <?php endif ?>
    <div class="checkout-foot">
      Payments go directly to the merchant's UPI account; this page only confirms the result.<br>
      If your UPI app shows the payment completed, do not pay again.
      <a href="/">Powered by TG Gateways</a>
    </div>
  <?php endif ?>
</main>
<?php if ($order && $order['status'] === 'pending'): ?>
<script src="/assets/js/vendor/qrcode.js"></script>
<script>
(function () {
  const target = document.getElementById('qr');
  const upi = target.dataset.upi;
  try {
    // Type 0 = auto-size, level M error correction.
    const qr = qrcode(0, 'M');
    qr.addData(upi);
    qr.make();
    target.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 8, scalable: true });
  } catch (error) {
    target.textContent = 'QR unavailable — use the UPI buttons below.';
  }

  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const node = document.querySelector(button.dataset.copyTarget);
      if (!node) return;
      try {
        await navigator.clipboard.writeText(node.textContent.trim());
        button.textContent = 'Copied';
      } catch (error) {
        button.textContent = 'Copy failed';
      }
    });
  });

  const orderId = <?=json_encode($order['order_id'])?>;
  const deadline = <?=json_encode($deadline)?>;
  const redirect = <?=json_encode($redirect)?>;
  const statusBox = document.getElementById('payment-status');
  let done = false;

  async function poll() {
    try {
      const response = await fetch('/api/checkout-status.php?order_id=' + encodeURIComponent(orderId), { cache: 'no-store' });
      if (response.ok) {
        const data = await response.json();
        if (data.status === 'success') {
          done = true;
          statusBox.className = 'wait success';
          statusBox.textContent = data.utr ? '✓ Payment received · UTR ' + data.utr : '✓ Payment received!';
          if (redirect) setTimeout(() => location.assign(redirect), 1800);
          return;
        }
        if (data.status === 'expired') {
          done = true;
          statusBox.className = 'wait expired';
          statusBox.textContent = 'This payment order has expired';
          return;
        }
      }
    } catch (error) { /* transient network error: retry */ }
    setTimeout(poll, 3000);
  }

  function tick() {
    if (done) return;
    const remaining = Math.max(0, deadline - Math.floor(Date.now() / 1000));
    document.getElementById('countdown').textContent =
      String(Math.floor(remaining / 60)).padStart(2, '0') + ':' + String(remaining % 60).padStart(2, '0');
    if (remaining === 0) {
      statusBox.className = 'wait expired';
      statusBox.textContent = 'This payment order has expired';
      return;
    }
    setTimeout(tick, 1000);
  }

  poll();
  tick();
})();
</script>
<?php endif ?>
</body>
</html>
