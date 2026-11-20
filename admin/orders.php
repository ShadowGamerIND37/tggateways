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
require_once __DIR__.'/../includes/pager.php';

$pdo = db();
tg_admin_require();

$q = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 80);
$status = (string)($_GET['status'] ?? '');
$where = [];
$args = [];
if ($q !== '') {
    $where[] = '(o.order_id LIKE ? OR o.utr LIKE ? OR m.email LIKE ? OR m.name LIKE ?)';
    $like = '%'.$q.'%';
    array_push($args, $like, $like, $like, $like);
}
if (in_array($status, ['pending', 'success', 'expired', 'failed', 'refunded'], true)) {
    $where[] = 'o.status = ?';
    $args[] = $status;
}
$from = ' FROM orders o LEFT JOIN merchants m ON m.id=o.merchant_id'
     . ($where ? ' WHERE '.implode(' AND ', $where) : '');
$rows = [];
$error = '';
$totals = ['success' => 0.0, 'pending' => 0, 'expired' => 0];
$page = tg_paginate(0, 50);
try {
    // Totals are calculated over all matches, not just this page.
    $stats = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN o.status IN ('success','refunded') THEN o.payable_amount ELSE 0 END),0) gross, COALESCE(SUM(CASE WHEN o.status='pending' THEN 1 ELSE 0 END),0) pending, COALESCE(SUM(CASE WHEN o.status='expired' THEN 1 ELSE 0 END),0) expired".$from);
    $stats->execute($args);
    $all = $stats->fetch();
    $page = tg_paginate((int)$all['total'], 50);
    $totals = ['success'=>(float)$all['gross'], 'pending'=>(int)$all['pending'], 'expired'=>(int)$all['expired']];
    $sql = 'SELECT o.order_id,o.amount,o.payable_amount,o.status,o.utr,o.created_at,m.name merchant,m.email merchant_email,m.id merchant_id'
        .$from.' ORDER BY o.id DESC LIMIT '.$page['perPage'].' OFFSET '.$page['offset'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = 'Orders could not be loaded.';
    error_log('admin orders: '.$e->getMessage());
}

admin_header('Orders', 'orders');
admin_flash('', $error);
?>

<?php if (!$error): ?><div class="admin-stats">
  <div class="admin-stat">
    <div class="admin-stat-label">Gross collected (matching orders)</div>
    <div class="admin-stat-value pos">₹<?=number_format($totals['success'], 2)?></div>
    <div class="admin-stat-foot">Before refunds; includes fully refunded orders.</div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Pending (stored status)</div>
    <div class="admin-stat-value warn"><?=number_format($totals['pending'])?></div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Expired unpaid</div>
    <div class="admin-stat-value"><?=number_format($totals['expired'])?></div>
  </div>
</div><?php endif ?>

<form method="get" class="admin-toolbar">
  <div class="admin-search">
    <?=icon('search', 15)?>
    <input type="search" name="q" value="<?=h($q)?>" placeholder="Order id, UTR, merchant name or email…" aria-label="Search orders">
  </div>
  <select class="admin-select" name="status" style="max-width:160px" onchange="this.form.submit()" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (['pending', 'success', 'expired', 'failed', 'refunded'] as $s): ?>
      <option value="<?=$s?>"<?=$status === $s ? ' selected' : ''?>><?=ucfirst($s)?></option>
    <?php endforeach ?>
  </select>
  <button class="admin-btn admin-btn-ghost"><?=icon('search', 14)?> Filter</button>
  <?php if ($q !== '' || $status !== ''): ?><a class="admin-btn admin-btn-ghost" href="/admin/orders.php">Clear</a><?php endif ?>
  <span style="margin-left:auto;font-size:11.5px;color:var(--color-ink-mute)"><?=number_format($page['total'])?> matching orders</span>
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
  <?=tg_pager($page, ['q'=>$q, 'status'=>$status, 'per_page'=>$page['perPage']])?>
</div>

<?php admin_footer(); ?>
