<?php
declare(strict_types=1);
/**
 * Addons Marketplace — the three gateways from the reference:
 *   fampay   — free forever, the native core (always available)
 *   paytm    — ₹49/month addon, billing in 1/3/6/12-month terms
 *   bharatpe — marked "coming soon", not purchasable
 *
 * Buying an addon records an invoice and a service row; nothing pretends to
 * charge a card. Payment for addons is arranged out of band, matching how a
 * self-hosted deployment without a payment processor has to work.
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/auth.php';
$merchant = require_login();
$pageTitle = 'Addons';
$pdo = db();
$id = (int)$merchant['id'];
$notice = '';
$error = '';

/** Catalogue. Prices are integer rupees per month. */
function tg_addon_catalogue(): array {
    return [
        'fampay' => [
            'name' => 'FamPay UPI Gateway',
            'tag' => 'Free Forever',
            'price' => 0,
            'blurb' => 'The native FamPay personal & merchant UPI routing engine, included with every account.',
            'needs' => 'FamPay UPI ID & Google App Password',
            'features' => ['Direct FamApp & UPI routing', '0% platform commission', 'Unlimited transactions', 'Dynamic QR & deep links'],
            'purchasable' => false,
            'cta' => 'Configure gateway',
            'href' => '/integrations.php',
        ],
        'paytm' => [
            'name' => 'Paytm for Business',
            'tag' => 'Gateway Addon',
            'price' => 49,
            'blurb' => 'Accept customer UPI payments into your Paytm Business account with 0% platform commission and direct settlement.',
            'needs' => 'Paytm Merchant ID & UPI ID',
            'features' => ['Direct bank settlement', '0% platform commission', 'Unlimited transactions', 'Official MSME/GST invoices'],
            'purchasable' => true,
            'cta' => 'Buy now',
            'href' => null,
        ],
        'bharatpe' => [
            'name' => 'BharatPe for Business',
            'tag' => 'Coming Soon',
            'price' => null,
            'blurb' => 'Accept customer UPI payments directly into your BharatPe QR merchant account with 0% platform commission and direct bank settlement.',
            'needs' => 'BharatPe merchant account',
            'features' => ['Direct BharatPe QR routing', '0% platform commission', 'Instant settlement'],
            'purchasable' => false,
            'cta' => 'Coming soon',
            'href' => null,
        ],
        'slice' => [
            'name' => 'Slice UPI Gateway',
            'tag' => 'Coming Soon',
            'price' => null,
            'blurb' => 'Accept customer UPI payments into your Slice (@slc) account with 0% platform commission and direct bank settlement.',
            'needs' => 'Slice merchant account',
            'features' => ['Direct Slice UPI routing', '0% platform commission', 'Instant settlement'],
            'purchasable' => false,
            'cta' => 'Coming soon',
            'href' => null,
        ],
        'mobikwik' => [
            'name' => 'MobiKwik UPI Gateway',
            'tag' => 'Coming Soon',
            'price' => null,
            'blurb' => 'Accept customer UPI payments into your MobiKwik merchant wallet with direct settlement.',
            'needs' => 'MobiKwik merchant account',
            'features' => ['Direct MobiKwik UPI routing', '0% platform commission', 'Instant settlement'],
            'purchasable' => false,
            'cta' => 'Coming soon',
            'href' => null,
        ],
        'phonepe' => [
            'name' => 'PhonePe for Business',
            'tag' => 'Coming Soon',
            'price' => null,
            'blurb' => 'Accept customer UPI payments into your PhonePe Business account with direct bank settlement.',
            'needs' => 'PhonePe Business merchant account',
            'features' => ['Direct PhonePe UPI routing', '0% platform commission', 'Instant settlement'],
            'purchasable' => false,
            'cta' => 'Coming soon',
            'href' => null,
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('addons', 10, 300);
    $key = (string)($_POST['addon'] ?? '');
    $months = (int)($_POST['months'] ?? 1);
    if (!in_array($months, [1, 3, 6, 12], true)) $months = 1;
    $catalogue = tg_addon_catalogue();
    $addon = $catalogue[$key] ?? null;
    if (!$addon || empty($addon['purchasable'])) {
        $error = 'That addon is not available for purchase.';
    } else {
        try {
            $price = (float)$addon['price'] * $months;
            // GST is charged as a separate line so the invoice totals stay legible.
            $tax = round($price * 0.18, 2);
            $expires = date('Y-m-d H:i:s', strtotime("+$months months"));
            $pdo->prepare('INSERT INTO merchant_addons(merchant_id,addon_key,status,months,price,started_at,expires_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([$id, $key, 'active', $months, $price, db_now(), $expires]);
            $invoiceNo = 'INV-'.date('Ym').'-'.strtoupper(bin2hex(random_bytes(3)));
            $pdo->prepare('INSERT INTO invoices(merchant_id,invoice_no,description,amount,tax_amount,status,issued_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([$id, $invoiceNo, $addon['name'].' — '.$months.' month(s)', $price, $tax, 'unpaid', db_now()]);
            tg_audit($id, 'addon.purchased', $key.' x'.$months);
            $notice = $addon['name'].' added for '.$months.' month(s). Invoice '.$invoiceNo.' is on the Invoices page — '
                . 'this deployment has no card processor, so settle it out of band and the service stays active.';
        } catch (Throwable $e) {
            error_log('addons: '.$e->getMessage());
            $error = 'The addon could not be added.';
        }
    }
}

$active = [];
$activeStmt = $pdo->prepare("SELECT addon_key, months, price, started_at, expires_at, status FROM merchant_addons WHERE merchant_id=? AND status='active'");
$activeStmt->execute([$id]);
foreach ($activeStmt->fetchAll() as $row) $active[(string)$row['addon_key']] = $row;

require __DIR__.'/includes/dashboard-header.php';
?>

<div class="page-title-row" style="margin-bottom:20px">
  <h1 style="font-size:22px;font-weight:700;letter-spacing:-.5px;margin:0">Addons</h1>
  <p style="font-size:13px;color:var(--color-ink-mute);margin:4px 0 0">Commercial gateway power-ups and active integrations for your merchant account.</p>
</div>

<?php if ($notice): ?><div class="callout callout-success" style="margin-bottom:18px"><?=icon('check-circle', 16)?><span><?=h($notice)?></span></div><?php endif ?>
<?php if ($error): ?><div class="callout callout-error" style="margin-bottom:18px"><?=icon('alert-circle', 16)?><span><?=h($error)?></span></div><?php endif ?>

<div class="feature-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
  <?php foreach (tg_addon_catalogue() as $key => $addon): ?>
    <?php $isActive = isset($active[$key]); ?>
    <div class="card" style="display:flex;flex-direction:column;gap:14px;padding:26px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
        <div>
          <h3 style="font-size:16px;margin:0 0 4px;color:var(--color-ink)"><?=h($addon['name'])?></h3>
          <span style="font-size:11.5px;color:var(--color-ink-mute)"><?=h($addon['tag'])?></span>
        </div>
        <?php if ($isActive): ?>
          <span class="status-badge status-success"><?=icon('check', 12)?> Active</span>
        <?php endif ?>
      </div>
      <div style="font-size:26px;font-weight:800;letter-spacing:-1px;color:var(--color-ink)">
        <?php if ($addon['price'] === null): ?>
          <span style="font-size:18px">Coming soon</span>
        <?php elseif ($addon['price'] === 0): ?>
          ₹0 <span style="font-size:13px;font-weight:500;color:var(--color-ink-mute)">/Free Forever</span>
        <?php else: ?>
          ₹<?=number_format((float)$addon['price'])?> <span style="font-size:13px;font-weight:500;color:var(--color-ink-mute)">/month</span>
        <?php endif ?>
      </div>
      <p style="font-size:12.5px;color:var(--color-ink-mute);line-height:1.75;margin:0"><?=h($addon['blurb'])?></p>
      <ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.95;color:var(--color-ink-mute)">
        <?php foreach ($addon['features'] as $feature): ?><li><?=h($feature)?></li><?php endforeach ?>
      </ul>
      <div style="font-size:11.5px;color:var(--color-ink-mute-2);margin-top:auto"><?=h($addon['needs'])?></div>

      <?php if (!empty($addon['purchasable'])): ?>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="addon" value="<?=h($key)?>">
          <select class="form-input" name="months" style="max-width:150px" aria-label="Billing term">
            <?php foreach ([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'] as $m => $label): ?>
              <option value="<?=$m?>"><?=$label?> · ₹<?=number_format((float)$addon['price'] * $m)?></option>
            <?php endforeach ?>
          </select>
          <button class="btn btn-primary btn-sm"><?=h($addon['cta'])?></button>
        </form>
      <?php elseif ($addon['href'] !== null): ?>
        <a class="btn btn-secondary btn-sm" href="<?=h($addon['href'])?>" style="align-self:flex-start"><?=icon('plug', 14)?> <?=h($addon['cta'])?></a>
      <?php else: ?>
        <button class="btn btn-secondary btn-sm" disabled style="align-self:flex-start"><?=icon('clock', 14)?> <?=h($addon['cta'])?></button>
      <?php endif ?>
    </div>
  <?php endforeach ?>
</div>

<div class="callout callout-info" style="margin-top:20px">
  <?=icon('alert-circle', 16)?>
  <span>
    Addon fees are invoiced, not auto-charged: this deployment ships without a card processor.
    See <a href="/invoices.php" style="font-weight:600">Invoices</a> for the generated bill.
  </span>
</div>

<?php require __DIR__.'/includes/dashboard-footer.php'; ?>
