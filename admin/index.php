<?php
declare(strict_types=1);
/**
 * Admin overview: the deployment at a glance.
 *
 * Every number here is a live COUNT against the real tables. Nothing is
 * pre-computed or cached, because an operator reaching for this page is
 * usually trying to find out what changed in the last few minutes.
 */
require_once __DIR__.'/../includes/admin-shell.php';
require_once __DIR__.'/../includes/webhook.php';

$pdo = db();
$admin = tg_admin_require();

/** Safe scalar query: the panel must render even if one table is missing. */
function admin_scalar(string $sql, array $args = [], $fallback = 0) {
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        $value = $stmt->fetchColumn();
        return $value === false ? $fallback : $value;
    } catch (Throwable $e) {
        error_log('admin stat: '.$e->getMessage());
        return $fallback;
    }
}

$merchants     = (int)admin_scalar('SELECT COUNT(*) FROM merchants');
$activeMerch   = (int)admin_scalar("SELECT COUNT(*) FROM merchants WHERE COALESCE(status,'active')='active'");
$suspended     = $merchants - $activeMerch;
$wallets       = (int)admin_scalar('SELECT COUNT(*) FROM merchant_accounts WHERE is_active=1');
$disconnected  = (int)admin_scalar("SELECT COUNT(*) FROM merchant_accounts WHERE is_active=1 AND (google_app_password IS NULL OR google_app_password='')");

$paidToday     = (float)admin_scalar("SELECT COALESCE(SUM(payable_amount),0) FROM orders WHERE status='success' AND paid_at>=?", [date('Y-m-d').' 00:00:00']);
$paidTotal     = (float)admin_scalar("SELECT COALESCE(SUM(payable_amount),0) FROM orders WHERE status='success'");
$ordersToday   = (int)admin_scalar("SELECT COUNT(*) FROM orders WHERE created_at>=?", [date('Y-m-d').' 00:00:00']);
$pending       = (int)admin_scalar("SELECT COUNT(*) FROM orders WHERE status='pending' AND expires_at>?", [db_now()]);
$failedHooks   = (int)admin_scalar('SELECT COUNT(*) FROM webhook_logs WHERE delivered_at IS NULL AND (next_retry_at IS NULL OR attempt_count>=?)', [webhook_max_attempts()]);
$openTickets   = (int)admin_scalar("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('closed','resolved')");
$unpaidInv     = (float)admin_scalar("SELECT COALESCE(SUM(amount+tax_amount),0) FROM invoices WHERE status='unpaid'");

$recentOrders = [];
try {
    $recentOrders = $pdo->query(
        "SELECT o.order_id,o.amount,o.status,o.created_at,m.name merchant
         FROM orders o LEFT JOIN merchants m ON m.id=o.merchant_id
         ORDER BY o.id DESC LIMIT 8"
    )->fetchAll();
} catch (Throwable $e) { error_log('admin overview: '.$e->getMessage()); }

$recentMerchants = [];
try {
    $recentMerchants = $pdo->query(
        "SELECT id,name,email,created_at FROM merchants ORDER BY id DESC LIMIT 6"
    )->fetchAll();
} catch (Throwable $e) { /* ignored */ }

/** Rupee formatting that never shows a confusing float tail. */
function admin_money(float $amount): string {
    return '₹'.number_format($amount, 2, '.', ',');
}

admin_header('Overview', 'overview');
?>

<div class="admin-stats">
  <div class="admin-stat">
    <div class="admin-stat-label">Merchants</div>
    <div class="admin-stat-value"><?=number_format($merchants)?></div>
    <div class="admin-stat-foot">
      <?=number_format($activeMerch)?> active<?php if ($suspended > 0): ?> · <span style="color:#b91c1c;font-weight:600"><?=number_format($suspended)?> suspended</span><?php endif ?>
    </div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Collected today</div>
    <div class="admin-stat-value pos"><?=h(admin_money($paidToday))?></div>
    <div class="admin-stat-foot"><?=number_format($ordersToday)?> orders created</div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Collected all time</div>
    <div class="admin-stat-value"><?=h(admin_money($paidTotal))?></div>
    <div class="admin-stat-foot"><?=number_format($pending)?> awaiting payment now</div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Receiving wallets</div>
    <div class="admin-stat-value<?=$disconnected > 0 ? ' warn' : ''?>"><?=number_format($wallets)?></div>
    <div class="admin-stat-foot">
      <?php if ($disconnected > 0): ?>
        <span style="color:#b45309;font-weight:600"><?=number_format($disconnected)?> disconnected</span> — no receipts will arrive
      <?php else: ?>
        All connected
      <?php endif ?>
    </div>
  </div>
</div>

<div class="admin-stats">
  <div class="admin-stat">
    <div class="admin-stat-label">Open tickets</div>
    <div class="admin-stat-value<?=$openTickets > 0 ? ' warn' : ''?>"><?=number_format($openTickets)?></div>
    <div class="admin-stat-foot"><a href="/admin/tickets.php" style="color:inherit">Review support →</a></div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Webhooks needing attention</div>
    <div class="admin-stat-value<?=$failedHooks > 0 ? ' neg' : ''?>"><?=number_format($failedHooks)?></div>
    <div class="admin-stat-foot"><?php if (tg_admin_can('view_pii')): ?><a href="/admin/operations.php?view=webhooks&amp;state=attention" style="color:inherit">Inspect queue →</a><?php else: ?>Retry limit reached or unscheduled<?php endif ?></div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Unpaid addon invoices</div>
    <div class="admin-stat-value"><?=h(admin_money($unpaidInv))?></div>
    <div class="admin-stat-foot"><a href="/admin/addons.php" style="color:inherit">Billing →</a></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:20px" class="admin-overview-grid">
  <div class="admin-panel">
    <div class="admin-panel-head">
      <h2>Latest orders</h2>
      <a class="admin-btn admin-btn-ghost admin-btn-sm" href="/admin/orders.php">All orders</a>
    </div>
    <div class="admin-table-wrap">
      <?php if (!$recentOrders): ?>
        <div class="admin-empty">No orders yet.</div>
      <?php else: ?>
        <table class="admin-table">
          <thead><tr><th>Order</th><th>Merchant</th><th class="num">Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recentOrders as $o): ?>
            <?php
              $status = (string)$o['status'];
              $pill = match ($status) {
                  'success' => 'admin-pill-ok',
                  'pending' => 'admin-pill-warn',
                  'expired' => 'admin-pill-neutral',
                  'failed'  => 'admin-pill-bad',
                  default   => 'admin-pill-neutral',
              };
            ?>
            <tr>
              <td><code style="font-size:11px"><?=h((string)$o['order_id'])?></code></td>
              <td class="muted"><?=h((string)($o['merchant'] ?? '—'))?></td>
              <td class="num"><?=h(admin_money((float)$o['amount']))?></td>
              <td><span class="admin-pill <?=$pill?>"><?=h(ucfirst($status))?></span></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </div>
  </div>

  <div class="admin-panel">
    <div class="admin-panel-head">
      <h2>Newest merchants</h2>
      <a class="admin-btn admin-btn-ghost admin-btn-sm" href="/admin/merchants.php">Manage</a>
    </div>
    <div class="admin-table-wrap">
      <?php if (!$recentMerchants): ?>
        <div class="admin-empty">No merchants registered.</div>
      <?php else: ?>
        <table class="admin-table">
          <tbody>
          <?php foreach ($recentMerchants as $m): ?>
            <tr>
              <td>
                <div style="font-weight:600"><?=h((string)$m['name'])?></div>
                <div class="muted" style="font-size:11px"><?=h((string)$m['email'])?></div>
              </td>
              <td class="muted" style="text-align:right;font-size:11px"><?=h(substr((string)$m['created_at'], 0, 10))?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </div>
  </div>
</div>

<?php if ($disconnected > 0): ?>
<div class="callout callout-warning" style="margin-top:4px">
  <?=icon('alert-circle', 16)?>
  <span>
    <strong><?=number_format($disconnected)?> receiving wallet<?=$disconnected === 1 ? '' : 's'?></strong>
    have no app password stored. Those mailboxes cannot be polled, so payments into them will never be confirmed.
    Open <a href="/admin/merchants.php" style="font-weight:600">Merchants</a> to find the affected accounts.
  </span>
</div>
<?php endif ?>

<style>
@media (max-width: 980px) { .admin-overview-grid { grid-template-columns: 1fr !important; } }
</style>

<?php admin_footer(); ?>
