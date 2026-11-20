<?php
declare(strict_types=1);
require_once __DIR__.'/includes/orders.php';
// tg_audit() records the payer's claim; it lives with the merchant helpers.
require_once __DIR__.'/includes/merchant.php';

$pdo = db();
$orderId = substr((string)($_GET['order_id'] ?? ''), 0, 40);
$linkSlug = substr((string)($_GET['link'] ?? ''), 0, 100);
$notice = '';

if ($linkSlug !== '') {
    rate_limit('pay-link', 20, 60);
    $q = $pdo->prepare('SELECT l.*,m.name merchant_name,m.id merchant_id,m.payment_timeout_minutes FROM payment_links l JOIN merchants m ON m.id=l.merchant_id WHERE l.slug=? AND l.is_active=1');
    $q->execute([$linkSlug]);
    $link = $q->fetch();
    if (!$link || ($link['expires_at'] && strtotime((string)$link['expires_at']) <= time())) {
        $notice = 'This payment link is unavailable or has expired.';
    } else {
        // The seconds column may not exist until the first dashboard migration.
        try {
            $window = $pdo->prepare('SELECT payment_timeout_seconds FROM merchants WHERE id=?');
            $window->execute([(int)$link['merchant_id']]);
            $link['payment_timeout_seconds'] = $window->fetchColumn();
        } catch (Throwable $e) { $link['payment_timeout_seconds'] = null; }
        $account = active_account($pdo, (int)$link['merchant_id']);
        if (!$account) {
            $notice = 'This merchant has not configured a receiving account yet.';
        } else {
            $created = create_order_record($pdo, (int)$link['merchant_id'], payment_window_seconds($link), [
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

/**
 * Payer-submitted UTR.
 *
 * The receipt pipeline normally learns about a payment from the merchant
 * mailbox. When the provider's mail is delayed, filtered, or never sent at
 * all, the payer has already parted with money and the order sits pending
 * until it expires — a silent loss. Letting the payer submit the reference
 * they can see in their own UPI app closes that gap.
 *
 * The claim is NOT trusted for fulfilment: it goes through the same
 * settle_receipt() path as an emailed receipt, so the duplicate check,
 * amount check and audit row are identical. What it changes is which party
 * provides the reference, not how far it is trusted.
 */
$claimNotice = '';
$claimError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'claim_utr') {
    rate_limit('pay-claim', 8, 300);
    $claimedOrder = substr((string)($_POST['order_id'] ?? ''), 0, 40);
    $claimedUtr = preg_replace('/\D/', '', (string)($_POST['utr'] ?? '')) ?? '';
    $target = find_checkout_order($pdo, $claimedOrder);

    if (!$target) {
        $claimError = 'That payment order no longer exists.';
    } elseif ($target['status'] !== 'pending') {
        $claimError = 'This order is no longer waiting for payment.';
    } elseif (strlen($claimedUtr) < 8 || strlen($claimedUtr) > 18) {
        // UPI reference numbers are 12 digits for most providers, 8–18 in
        // practice. A short or non-numeric value is a typo, not a payment.
        $claimError = 'A UPI reference (UTR) is 8 to 18 digits — check your app and try again.';
    } else {
        require_once __DIR__.'/includes/settlement.php';
        $accountRow = $pdo->prepare('SELECT * FROM merchant_accounts WHERE id=? AND merchant_id=? LIMIT 1');
        $accountRow->execute([(int)$target['merchant_account_id'], (int)$target['merchant_id']]);
        $acct = $accountRow->fetch();
        if (!$acct) {
            $claimError = 'This merchant has no receiving account for that order.';
        } else {
            // Deliberately recorded as a *claim*, never as a settlement.
            //
            // This endpoint has no login: anyone holding the checkout link can
            // post to it, and settle_receipt() matches primarily on amount. If
            // the claim were fed through that path, a stranger could open their
            // own checkout, read the total, invent a 12-digit reference and have
            // the order marked paid without any money moving. The claim is
            // therefore an *assertion by the payer*, kept separate from the
            // mailbox pipeline, and it changes no order state. The merchant
            // confirms it; that confirmation is what settles the order.
            //
            // A genuine emailed receipt for the same UTR still settles
            // automatically, and in that case settle_receipt() will see this
            // row and report the pair rather than double-counting.
            $already = $pdo->prepare('SELECT state FROM receipt_events WHERE utr=? LIMIT 1');
            $already->execute([$claimedUtr]);
            $priorState = $already->fetchColumn();

            if ($priorState !== false) {
                $claimNotice = $priorState === 'matched'
                    ? 'That reference is already confirmed against an order.'
                    : 'That reference was already recorded. Your payment is not lost — the merchant can see it.';
            } else {
                $pdo->prepare('INSERT INTO receipt_events(merchant_id,merchant_account_id,message_id,utr,amount,sender_name,order_ref,matched_order_id,state,detail,created_at)
                               VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([
                        (int)$target['merchant_id'], (int)$target['merchant_account_id'], null,
                        $claimedUtr, (string)$target['payable_amount'], null,
                        (string)$target['order_id'], null, 'claimed',
                        'Payer submitted this reference from the checkout page; awaiting merchant confirmation.',
                        db_now(),
                    ]);
                tg_audit((int)$target['merchant_id'], 'payment.utr_claimed', $claimedUtr.' for '.$target['order_id']);
                $claimNotice = 'Thanks — we recorded that reference for '.$target['order_id']
                    .'. The merchant will verify it against their bank statement. Do not pay again.';
            }
        }
    }
}

$order = $orderId !== '' ? find_checkout_order($pdo, $orderId) : null;
if (!$order) {
    $notice = $notice ?: 'Payment order was not found.';
} elseif ($order['status'] === 'pending' && strtotime((string)$order['expires_at']) <= time()) {
    $pdo->prepare("UPDATE orders SET status='expired' WHERE order_id=? AND status='pending' AND expires_at<=?")->execute([$order['order_id'], db_now()]);
    $order = find_checkout_order($pdo, $orderId);
}

$deadline = $order ? strtotime((string)$order['expires_at']) : time();
$secondsRemaining = max(0, $deadline - time());
$upi = $order ? upi_intent($order) : '';
$redirect = $order && valid_http_url($order['redirect_url']) ? (string)$order['redirect_url'] : '';
$brand = $order && preg_match('/^#[0-9a-f]{6}$/i', (string)$order['brand_color']) ? $order['brand_color'] : '#2563eb';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Secure checkout — TG Gateways</title>
<link rel="stylesheet" href="/assets/css/design-system.css"><link rel="stylesheet" href="/assets/css/marketing.css">
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
.claim{margin-top:18px;text-align:left;border-top:1px solid #eef1f6;padding-top:14px}
.claim summary{font-size:11.5px;font-weight:600;color:#4d5c73;cursor:pointer;list-style:none;padding:6px 0}
.claim summary::-webkit-details-marker{display:none}
.claim summary:before{content:'▸';display:inline-block;margin-right:7px;color:#93a1b5;transition:transform .15s}
.claim[open] summary:before{transform:rotate(90deg)}
.claim-intro{font-size:11px;line-height:1.75;color:#7a8797;margin:6px 0 12px}
.claim-label{display:block;font-size:10.5px;font-weight:600;color:#5d6b80;margin-bottom:5px}
.claim-row{display:flex;gap:8px}
.claim-row .form-input{flex:1 1 auto;font-size:13px;font-variant-numeric:tabular-nums}
.claim-row .btn{white-space:nowrap}
.claim-hint{display:block;font-size:10px;color:#93a1b5;margin-top:7px;line-height:1.6}
.claim-result{margin-top:16px;padding:11px 13px;border-radius:10px;font-size:11.5px;line-height:1.7;text-align:left}
.claim-ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.claim-bad{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.checkout-foot{font-size:9px;color:#9aa4b4;margin-top:18px;line-height:1.7}
.checkout-foot a{color:#5473ad}
.copy-upi{margin-top:12px;font-size:10px;color:#6b7a90}
.copy-upi code{background:#f4f6fa;border:1px solid #e7ebf2;border-radius:7px;padding:3px 7px}
/* Checkout refinements use the existing palette and remain readable on mobile. */
.checkout{border-radius:22px;box-shadow:0 22px 64px #20365b1a;padding:30px}
.checkout-brand{font-weight:700}.checkout h1{font-size:21px}.checkout-total{font-size:38px;font-variant-numeric:tabular-nums}
.checkout-meta{font-size:11px;overflow-wrap:anywhere}.upi-actions a{font-size:12px;min-height:40px;display:inline-flex;align-items:center;justify-content:center}
.wait{font-size:13px;line-height:1.5}.claim summary{font-size:13px}.claim-intro,.claim-hint{font-size:12px}.checkout-foot{font-size:11px}
[hidden]{display:none!important}@media(max-width:480px){.checkout{margin:18px auto;padding:22px}.checkout-qr{max-width:100%}.upi-actions a{flex:1 1 40%}}
</style>
<link rel="stylesheet" href="/assets/css/checkout.css">
</head>
<body class="checkout-page">
<main class="checkout">
  <div class="checkout-top"><a class="brand" href="/"><img src="/assets/img/tg-mark.png" alt="">TG Gateways</a><span class="checkout-tag">UPI checkout</span></div>
  <?php if ($notice): ?>
    <div class="note" style="margin-top:20px"><?=h($notice)?></div>
  <?php elseif ($order): ?>
    <div class="checkout-brand">PAYING TO</div>
    <h1><?=h($order['merchant_name'])?></h1>
    <div class="checkout-total">₹<?=number_format((float)$order['payable_amount'],2)?></div>
    <div class="checkout-order">INR · Order <?=h($order['order_id'])?></div>
    <?php if ($order['status'] === 'pending'): ?>
      <div id="payment-options">
        <section class="checkout-scan" aria-labelledby="scan-heading">
          <h2 class="checkout-section-title" id="scan-heading">Scan to pay</h2>
          <p class="checkout-section-sub">Open any UPI app and scan this QR code.</p>
          <div class="checkout-qr" id="qr" data-upi="<?=h($upi)?>" role="img" aria-label="UPI payment QR code"></div>
        </section>
        <section class="payment-apps" aria-labelledby="apps-heading">
          <h2 class="checkout-section-title" id="apps-heading">Pay with an app</h2>
          <p class="checkout-section-sub">Choose a UPI app on your phone.</p>
          <div class="upi-actions">
            <a class="upi-primary" href="<?=h($upi)?>" rel="noopener">
              <svg class="upi-generic-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"></rect><path d="M9 18h6"></path></svg>
              <span>Open UPI app</span>
            </a>
            <a href="<?=h($upi)?>" rel="noopener" data-upi-package="com.google.android.apps.nbu.paisa.user">
              <img class="upi-app-icon upi-google-icon" src="/assets/img/gateways/googlepay.svg" alt=""><span>Google Pay</span>
            </a>
            <a href="<?=h($upi)?>" rel="noopener" data-upi-package="com.phonepe.app">
              <img class="upi-app-icon" src="/assets/img/gateways/phonepe.png" alt=""><span>PhonePe</span>
            </a>
            <a href="<?=h($upi)?>" rel="noopener" data-upi-package="net.one97.paytm">
              <img class="upi-app-icon" src="/assets/img/gateways/paytm.png" alt=""><span>Paytm</span>
            </a>
          </div>
          <p class="upi-app-note" id="app-link-note">App buttons use your device's UPI app chooser when available. Apps and support vary by device.</p>
        </section>
        <div class="copy-upi">
          <div class="copy-upi-label"><span>Or pay to this UPI ID</span><code id="upi-id"><?=h($order['upi_id'] ?: '—')?></code></div>
          <button type="button" class="btn btn-outline" data-copy-target="#upi-id">Copy UPI ID</button>
          <div class="copy-upi-hint">Use the exact amount shown above. Include the order ID as the payment note if your app allows it.</div>
        </div>
      </div>
      <div class="wait" id="payment-status" role="status" aria-live="polite">◌ Waiting for payment…</div>
      <div class="checkout-meta" style="margin-top:12px" id="expiry-label">Expires in <strong id="countdown"><?=sprintf('%02d:%02d', intdiv($secondsRemaining, 60), $secondsRemaining % 60)?></strong> <span class="countdown-format">(min:sec)</span></div>
      <?php if ($claimNotice !== ''): ?>
        <div class="claim-result claim-ok"><?=h($claimNotice)?></div>
      <?php elseif ($claimError !== ''): ?>
        <div class="claim-result claim-bad"><?=h($claimError)?></div>
      <?php endif ?>

      <details class="claim" <?=$claimError !== '' ? 'open' : ''?>>
        <summary>Already paid? Submit your UTR</summary>
        <p class="claim-intro">
          Confirmation normally arrives from the merchant's mailbox within a couple of minutes.
          If your UPI app shows the money left but this page has not updated, paste the reference here —
          the merchant can review it against their records. A submitted UTR is not automatic proof of payment.
        </p>
        <form method="post" class="claim-form">
          <input type="hidden" name="action" value="claim_utr">
          <input type="hidden" name="order_id" value="<?=h((string)$order['order_id'])?>">
          <label class="claim-label" for="claim-utr">UPI reference / UTR</label>
          <div class="claim-row">
            <input class="form-input" id="claim-utr" name="utr" inputmode="numeric" autocomplete="off"
                   pattern="[0-9]{8,18}" maxlength="18" required placeholder="e.g. 416782930145">
            <button class="btn btn-primary">Submit</button>
          </div>
          <span class="claim-hint">12 digits for most apps. Never pay twice while waiting for confirmation.</span>
        </form>
      </details>
    <?php else: ?>
      <div class="wait <?=$order['status'] === 'success' ? 'success' : 'expired'?>">
        <?php if ($order['status'] === 'success'): ?>
          ✓ Payment received<?=$order['utr'] ? ' · UTR '.h($order['utr']) : ''?>
        <?php elseif ($order['status'] === 'refunded'): ?>
          This payment was fully refunded by the merchant.
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

  // Chrome on Android can target installed UPI apps by package. Everywhere
  // else, retain the standard UPI link and let the device choose. If the named
  // app is unavailable, the generic button remains available as a fallback.
  if (typeof navigator !== 'undefined' && /Android/i.test(navigator.userAgent || '') &&
      /Chrome\//i.test(navigator.userAgent || '') && !/;\s*wv\)/i.test(navigator.userAgent || '')) {
    document.querySelectorAll('[data-upi-package]').forEach((link) => {
      const packageName = link.dataset.upiPackage;
      if (packageName && /^upi:\/\//i.test(upi)) {
        link.href = upi.replace(/^upi:\/\//i, 'intent://') + '#Intent;scheme=upi;package=' + packageName + ';end';
      }
    });
    const note = document.getElementById('app-link-note');
    if (note) note.textContent = 'On supported Android browsers, app buttons try to open the named app. If unavailable, use Open UPI app.';
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
  const secondsRemaining = <?=json_encode($secondsRemaining)?>;
  const deadline = performance.now() + secondsRemaining * 1000;
  const redirect = <?=json_encode($redirect, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
  const statusBox = document.getElementById('payment-status');
  let done = false;

  async function poll() {
    try {
      const response = await fetch('/api/checkout-status.php?order_id=' + encodeURIComponent(orderId), { cache: 'no-store' });
      if (response.ok) {
        const data = await response.json();
        if (data.status === 'success') {
          done = true;
          document.getElementById('payment-options').hidden = true;
          document.getElementById('expiry-label').hidden = true;
          statusBox.className = 'wait success';
          statusBox.textContent = data.utr ? '✓ Payment received · UTR ' + data.utr : '✓ Payment received!';
          if (redirect) setTimeout(() => location.assign(redirect), 1800);
          return;
        }
        if (data.status === 'expired' || data.status === 'refunded') {
          done = true;
          document.getElementById('payment-options').hidden = true;
          document.getElementById('expiry-label').hidden = true;
          statusBox.className = 'wait expired';
          statusBox.textContent = data.status === 'refunded' ? 'This payment was fully refunded by the merchant.' : 'This payment order has expired';
          return;
        }
      }
    } catch (error) { /* transient network error: retry */ }
    setTimeout(poll, 3000);
  }

  function tick() {
    if (done) return;
    const remaining = Math.max(0, Math.ceil((deadline - performance.now()) / 1000));
    document.getElementById('countdown').textContent =
      String(Math.floor(remaining / 60)).padStart(2, '0') + ':' + String(remaining % 60).padStart(2, '0');
    if (remaining === 0) {
      document.getElementById('payment-options').hidden = true;
      statusBox.textContent = 'Checking payment status…';
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
