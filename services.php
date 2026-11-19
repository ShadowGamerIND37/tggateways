<?php
declare(strict_types=1);
/**
 * My Services — what the merchant actually has: the free core gateway plus any
 * purchased addons, with validity and where to manage each one.
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/auth.php';
$merchant = require_login();
$pageTitle = 'My Services';
$pdo = db();
$id = (int)$merchant['id'];

$accounts = $pdo->prepare('SELECT COUNT(*) FROM merchant_accounts WHERE merchant_id=? AND is_active=1');
$accounts->execute([$id]);
$accountCount = (int)$accounts->fetchColumn();

$addons = [];
$q = $pdo->prepare('SELECT addon_key, months, price, started_at, expires_at, status FROM merchant_addons WHERE merchant_id=? ORDER BY started_at DESC');
$q->execute([$id]);
foreach ($q->fetchAll() as $row) $addons[] = $row;

/** Friendly name for an addon key, falling back to the raw key. */
function tg_service_name(string $key): string {
    return ['paytm' => 'Paytm for Business', 'fampay' => 'FamPay UPI Gateway', 'bharatpe' => 'BharatPe for Business'][$key] ?? ucfirst($key);
}

require __DIR__.'/includes/dashboard-header.php';
?>

<div class="page-title-row" style="margin-bottom:20px">
  <h1 style="font-size:22px;font-weight:700;letter-spacing:-.5px;margin:0">My Services &amp; Subscriptions</h1>
  <p style="font-size:13px;color:var(--color-ink-mute);margin:4px 0 0">Active gateway integrations, commercial power-ups and recurring passes.</p>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:20px 24px;border-bottom:1px solid var(--color-hairline)">
    <div class="card-title"><?=icon('shopping-bag', 16)?> Total Services: <?=1 + count($addons)?></div>
    <div class="card-sub">One core gateway plus <?=count($addons)?> addon<?=count($addons) === 1 ? '' : 's'?>.</div>
  </div>
  <div class="table-responsive">
    <table>
      <thead><tr><th>Service &amp; Integration</th><th>Active plan &amp; billing</th><th>Validity &amp; status</th><th style="text-align:right">Action</th></tr></thead>
      <tbody>
        <tr>
          <td>
            <div style="font-weight:600">UPI Gateway</div>
            <div style="font-size:11.5px;color:var(--color-ink-mute)">Core gateway · non-custodial</div>
          </td>
          <td>Free Forever</td>
          <td>
            <span class="status-badge status-success"><?=icon('check', 12)?> Active</span>
            <div style="font-size:11.5px;color:var(--color-ink-mute);margin-top:5px">Perpetual · <?=$accountCount?> receiving account<?=$accountCount === 1 ? '' : 's'?></div>
          </td>
          <td style="text-align:right"><a class="btn btn-secondary btn-sm" href="/integrations.php">Accounts →</a></td>
        </tr>
        <?php foreach ($addons as $addon): ?>
          <?php
          $expired = $addon['expires_at'] !== null && strtotime((string)$addon['expires_at']) < time();
          $status = $expired ? 'expired' : (string)$addon['status'];
          ?>
          <tr>
            <td>
              <div style="font-weight:600"><?=h(tg_service_name((string)$addon['addon_key']))?></div>
              <div style="font-size:11.5px;color:var(--color-ink-mute)">Gateway addon</div>
            </td>
            <td>₹<?=number_format((float)$addon['price'], 2)?> · <?=(int)$addon['months']?> month<?=(int)$addon['months'] === 1 ? '' : 's'?></td>
            <td>
              <span class="status-badge status-<?=$status === 'active' ? 'success' : 'expired'?>">
                <?=icon($status === 'active' ? 'check' : 'alert-circle', 12)?> <?=h(ucfirst($status))?>
              </span>
              <div style="font-size:11.5px;color:var(--color-ink-mute);margin-top:5px">
                <?=h($addon['expires_at'] ? 'Until '.date('d M Y', strtotime((string)$addon['expires_at'])) : 'No expiry')?>
              </div>
            </td>
            <td style="text-align:right"><a class="btn btn-secondary btn-sm" href="/invoices.php">Invoice →</a></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:20px">
  <div class="card-header">
    <div class="card-title"><?=icon('puzzle', 16)?> Want more commercial power-ups?</div>
    <div class="card-sub">Browse gateway addons, multi-routing bridges and priority automation tools.</div>
  </div>
  <a class="btn btn-primary btn-sm" href="/addons.php">Explore Addons Marketplace <?=icon('arrow-right', 14)?></a>
</div>

<?php require __DIR__.'/includes/dashboard-footer.php'; ?>
