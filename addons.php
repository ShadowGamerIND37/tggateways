<?php
declare(strict_types=1);
/**
 * Addons Marketplace.
 *
 *   fampay — free forever, the native core (always available)
 *   others — shown with their real brand mark, marked "coming soon" and
 *            genuinely not purchasable: the POST handler refuses anything
 *            without `purchasable`, so a hand-crafted request cannot buy one.
 *
 * No addon currently charges money, because none of the external gateways is
 * implemented yet. The billing code below stays wired so switching a gateway on
 * later needs a catalogue flag, not new plumbing.
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
            'logo' => '/assets/img/gateways/fampay.png',
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
            'logo' => '/assets/img/gateways/paytm.png',
            'name' => 'Paytm for Business',
            'tag' => 'Coming Soon',
            'price' => null,
            'blurb' => 'Accept customer UPI payments into your Paytm Business account with 0% platform commission and direct settlement.',
            'needs' => 'Paytm Merchant ID & UPI ID',
            'features' => ['Direct bank settlement', '0% platform commission', 'Unlimited transactions', 'Official MSME/GST invoices'],
            'purchasable' => false,
            'cta' => 'Coming soon',
            'href' => null,
        ],
        'bharatpe' => [
            'logo' => '/assets/img/gateways/bharatpe.png',
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
            'logo' => '/assets/img/gateways/slice.png',
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
            'logo' => '/assets/img/gateways/mobikwik.png',
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
            'logo' => '/assets/img/gateways/phonepe.png',
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

<div class="addon-grid">
  <?php foreach (tg_addon_catalogue() as $key => $addon): ?>
    <?php
      $isActive = isset($active[$key]);
      $isComing = empty($addon['purchasable']) && $addon['href'] === null;
      $isFree = $addon['price'] === 0;
    ?>
    <article class="addon-card<?=$isComing ? ' addon-card-muted' : ''?><?=$isActive ? ' addon-card-live' : ''?>">
      <div class="addon-card-top">
        <span class="addon-logo">
          <img src="<?=h($addon['logo'])?>" alt="<?=h($addon['name'])?> logo" loading="lazy" width="44" height="44">
        </span>
        <div class="addon-headline">
          <h3><?=h($addon['name'])?></h3>
          <span class="addon-tag <?=$isComing ? 'addon-tag-soon' : ($isFree ? 'addon-tag-free' : 'addon-tag-paid')?>">
            <?=h($addon['tag'])?>
          </span>
        </div>
        <?php if ($isActive): ?>
          <span class="addon-live-flag"><?=icon('check-circle', 13)?> Active</span>
        <?php endif ?>
      </div>

      <div class="addon-price">
        <?php if ($addon['price'] === null): ?>
          <span class="addon-price-soon"><?=icon('clock', 15)?> Coming soon</span>
        <?php elseif ($isFree): ?>
          <strong>₹0</strong><span>free forever</span>
        <?php else: ?>
          <strong>₹<?=number_format((float)$addon['price'])?></strong><span>/ month</span>
        <?php endif ?>
      </div>

      <p class="addon-blurb"><?=h($addon['blurb'])?></p>

      <ul class="addon-features">
        <?php foreach ($addon['features'] as $feature): ?>
          <li><?=icon('check', 13)?><span><?=h($feature)?></span></li>
        <?php endforeach ?>
      </ul>

      <div class="addon-needs"><strong>Needs</strong> <?=h($addon['needs'])?></div>

      <div class="addon-actions">
        <?php if (!empty($addon['purchasable'])): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="addon" value="<?=h($key)?>">
            <select class="form-input" name="months" aria-label="Billing term">
              <?php foreach ([1 => '1 month', 3 => '3 months', 6 => '6 months', 12 => '12 months'] as $m => $label): ?>
                <option value="<?=$m?>"><?=$label?> · ₹<?=number_format((float)$addon['price'] * $m)?></option>
              <?php endforeach ?>
            </select>
            <button class="btn btn-primary btn-sm"><?=h($addon['cta'])?></button>
          </form>
        <?php elseif ($addon['href'] !== null): ?>
          <a class="btn btn-secondary btn-sm" href="<?=h($addon['href'])?>"><?=icon('plug', 14)?> <?=h($addon['cta'])?></a>
        <?php else: ?>
          <button class="btn btn-secondary btn-sm" disabled><?=icon('clock', 14)?> <?=h($addon['cta'])?></button>
          <span class="addon-note">Not available to buy yet</span>
        <?php endif ?>
      </div>
    </article>
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
