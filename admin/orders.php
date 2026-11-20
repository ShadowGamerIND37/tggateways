<?php
declare(strict_types=1);
/**
 * Every order on the deployment, newest first.
 *
 * Read-only on purpose: an operator who can hand-edit an order's status can
 * mark money as collected that never arrived, and the audit trail would blame
 * the merchant. Corrections belong to the receipt pipeline.
 */
require_once __DIR__.'/../includes/admin-shell.php';

$pdo = db();
tg_admin_require();

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$where = [];
$args = [];
if ($q !== '') {
    $where[] = '(o.order_id LIKE ? OR o.utr LIKE ? OR m.email LIKE ? OR m.name LIKE ?)';
    $like = '%'.$q.'%';
    array_push($args, $like, $like, $like, $like);
}
if (in_array($status, ['pending', 'success', 'expired', 'failed'], true)) {
    $where[] = 'o.status = ?';
    $args[] = $status;
}
$sql = "SELECT o.order_id,o.amount,o.payable_amount,o.status,o.utr,o.sender_name,o.paid_via,o.created_at,o.paid_at,
               m.name merchant,m.email merchant_email,m.id merchant_id
        FROM orders o LEFT JOIN merchants m ON m.id=o.merchant_id"
     . ($where ? ' WHERE '.implode(' AND ', $where) : '')
     . ' ORDER BY o.id DESC LIMIT 300';

$rows = [];
$error = '';
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = 'Orders could not be loaded.';
    error_log('admin orders: '.$e->getMessage());
}

$totals = ['success' => 0.0, 'pending' => 0, 'expired' => 0];
foreach ($rows as $r) {
    if ($r['status'] === 'success') $totals['success'] += (float)$r['payable_amount'];
    elseif ($r['status'] === 'pending') $totals['pending']++;
    elseif ($r['status'] === 'expired') $totals['expired']++;
}

admin_header('Orders', 'orders');
admin_flash('', $error);
?>

<div class="admin-stats">
  <div class="admin-stat">
    <div class="admin-stat-label">Collected (shown rows)</div>
    <div class="admin-stat-value pos">₹<?=number_format($totals['success'], 2)?></div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Still pending</div>
    <div class="admin-stat-value warn"><?=number_format($totals['pending'])?></div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Expired unpaid</div>
    <div class="admin-stat-value"><?=number_format($totals['expired'])?></div>
  </div>
</div>

<form method="get" class="admin-toolbar">
  <div class="admin-search">
    <?=icon('search', 15)?>
    <input type="search" name="q" value="<?=h($q)?>" placeholder="Order id, UTR, merchant name or email…" aria-label="Search orders">
  </div>
  <select class="admin-select" name="status" style="max-width:160px" onchange="this.form.submit()" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (['pending', 'success', 'expired', 'failed'] as $s): ?>
      <option value="<?=$s?>"<?=$status === $s ? ' selected' : ''?>><?=ucfirst($s)?></option>
    <?php endforeach ?>
  </select>
  <button class="admin-btn admin-btn-ghost"><?=icon('search', 14)?> Filter</button>
  <?php if ($q !== '' || $status !== ''): ?><a class="admin-btn admin-btn-ghost" href="/admin/orders.php">Clear</a><?php endif ?>
  <span style="margin-left:auto;font-size:11.5px;color:var(--color-ink-mute)"><?=count($rows)?> shown (max 300)</span>
</form>

<div class="admin-panel">
  <div class="admin-table-wrap">
    <?php if (!$rows): ?>
      <div class="admin-empty">No orders match this filter.</div>
    <?php else: ?>
      <table class="admin-table">
        <thead>
          <tr><th>Order</th><th>Merchant</th><th class="num">Amount</th><th>Status</th><th>UTR</th><th>Created</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $o): ?>
          <?php
            $st = (string)$o['status'];
            $pill = match ($st) {
                'success' => 'admin-pill-ok', 'pending' => 'admin-pill-warn',
                'failed' => 'admin-pill-bad', default => 'admin-pill-neutral',
            };
          ?>
          <tr>
            <td><code style="font-size:11px"><?=h((string)$o['order_id'])?></code></td>
            <td>
              <div style="font-weight:500"><?=h((string)($o['merchant'] ?? '—'))?></div>
              <div class="muted" style="font-size:11px"><?=h((string)($o['merchant_email'] ?? ''))?></div>
            </td>
            <td class="num">₹<?=number_format((float)$o['payable_amount'], 2)?></td>
            <td><span class="admin-pill <?=$pill?>"><?=h(ucfirst($st))?></span></td>
            <td class="muted" style="font-size:11px"><?=h((string)($o['utr'] ?? '—'))?></td>
            <td class="muted" style="font-size:11px"><?=h(substr((string)$o['created_at'], 0, 16))?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>
</div>

<?php admin_footer(); ?>
