<?php
declare(strict_types=1);
/**
 * Transactions — parity with the FamGateway transactions page:
 * collected-amount summary, status tabs with counters, date-range filter,
 * page-size selector, seven-column table and a real empty state.
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/auth.php';
$merchant = require_login();
$pageTitle = 'Transactions';
$pdo = db();
$id = (int)$merchant['id'];

$status = strtolower((string)($_GET['status'] ?? 'all'));
if (!in_array($status, ['all', 'pending', 'success', 'expired'], true)) $status = 'all';
$range = strtolower((string)($_GET['range'] ?? 'all'));
if (!in_array($range, ['all', 'today', 'yesterday', '7d', '30d', 'this_month', 'last_month'], true)) $range = 'all';
$search = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 80);
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [10, 25, 50], true)) $perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));

/** Date window for the range filter, as [start, end) timestamp bounds. */
function tg_range_bounds(string $range): array {
    $startOfToday = strtotime('today');
    return match ($range) {
        'today' => [$startOfToday, null],
        'yesterday' => [strtotime('-1 day', $startOfToday), $startOfToday],
        '7d' => [strtotime('-6 days', $startOfToday), null],
        '30d' => [strtotime('-29 days', $startOfToday), null],
        'this_month' => [strtotime('first day of this month'), null],
        'last_month' => [strtotime('first day of last month'), strtotime('first day of this month')],
        default => [null, null],
    };
}

/* Base filter shared by the page query and the counters. */
$where = 'merchant_id=?';
$params = [$id];
[$rangeStart, $rangeEnd] = tg_range_bounds($range);
if ($rangeStart !== null) { $where .= ' AND created_at >= ?'; $params[] = date('Y-m-d H:i:s', $rangeStart); }
if ($rangeEnd !== null) { $where .= ' AND created_at < ?'; $params[] = date('Y-m-d H:i:s', $rangeEnd); }
if ($search !== '') {
    $where .= ' AND (order_id LIKE ? OR utr LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?)';
    array_push($params, '%'.$search.'%', '%'.$search.'%', '%'.$search.'%', '%'.$search.'%');
}

/* Counters per tab, computed over the current date/search filter. */
$countStmt = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) total FROM orders WHERE $where GROUP BY status");
$countStmt->execute($params);
$counts = ['all' => 0, 'pending' => 0, 'success' => 0, 'expired' => 0];
$collected = 0.0;
foreach ($countStmt->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['n'];
    $counts['all'] += (int)$row['n'];
    if ($row['status'] === 'success') $collected = (float)$row['total'];
}

$listWhere = $status === 'all' ? $where : $where.' AND status=?';
$listParams = $status === 'all' ? $params : [...$params, $status];
$total = $counts[$status === 'all' ? 'all' : $status];
$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;

$q = $pdo->prepare("SELECT order_id,utr,customer_name,customer_email,customer_phone,amount,status,created_at
                      FROM orders WHERE $listWhere ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET ".(($page - 1) * $perPage));
$q->execute($listParams);
$rows = $q->fetchAll();

/* Payment-method split: this deployment collects over UPI only, but the panel
   shows the breakdown so a future multi-method gateway slots in unchanged. */
$methodSplit = $counts['success'] > 0 ? [['UPI', 100.0]] : [];

/* Order detail drawer (?view=ORDER_ID) — everything known about one order,
   including the fields the table has no room for. */
$detail = null;
$viewId = substr((string)($_GET['view'] ?? ''), 0, 40);
if ($viewId !== '') {
    $d = $pdo->prepare('SELECT order_id,utr,transaction_id,sender_name,customer_name,customer_email,
                               customer_phone,amount,payable_amount,status,source,created_at,paid_at,
                               expires_at,webhook_url,redirect_url,note,merchant_account_id
                          FROM orders WHERE merchant_id=? AND order_id=?');
    $d->execute([$id, $viewId]);
    $detail = $d->fetch() ?: null;
}

/**
 * Build a query string from the current filters, with overrides applied.
 * An override of null removes the key, which is how the detail drawer closes
 * without leaving a stray `view=` in the URL.
 */
$query = function (array $overrides = []) use ($status, $range, $search, $perPage, $page): string {
    $base = ['status' => $status, 'range' => $range, 'search' => $search, 'per_page' => $perPage, 'page' => $page];
    foreach ($overrides as $key => $value) {
        if ($value === null) unset($base[$key]);
        else $base[$key] = $value;
    }
    return '?'.http_build_query($base);
};

require __DIR__.'/includes/dashboard-header.php';
?>

<div class="page-title-row" style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px">
  <div>
    <h1 style="font-size:22px;font-weight:700;letter-spacing:-.5px;margin:0">Transactions</h1>
    <p style="font-size:13px;color:var(--color-ink-mute);margin:4px 0 0">Every order, with its bank reference and customer detail.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-secondary btn-sm" href="/transactions-export.php<?=h($query())?>"><?=icon('download', 14)?> Export CSV</a>
    <a class="btn btn-primary btn-sm" href="/payment-links.php"><?=icon('plus', 14)?> New payment link</a>
  </div>
</div>

<div class="dashboard-bottom-grid" style="grid-template-columns:1.6fr 1fr;margin-bottom:20px">
  <div class="card">
    <div class="card-header" style="margin-bottom:8px">
      <div class="card-title"><?=icon('wallet', 16)?> Collected</div>
      <div class="card-sub">from <?=number_format($counts['success'])?> captured payment<?=$counts['success'] === 1 ? '' : 's'?></div>
    </div>
    <div style="font-size:34px;font-weight:800;letter-spacing:-1.4px;color:#0f172a" class="tnum">₹<?=number_format($collected, 2)?></div>
    <div style="display:flex;gap:18px;margin-top:16px;flex-wrap:wrap">
      <span class="status-badge status-pending"><?=icon('clock', 12)?> Pending <?=number_format($counts['pending'])?></span>
      <span class="status-badge status-expired"><?=icon('alert-circle', 12)?> Expired <?=number_format($counts['expired'])?></span>
    </div>
  </div>
  <div class="card">
    <div class="card-header" style="margin-bottom:12px">
      <div class="card-title"><?=icon('activity', 16)?> Split by payment method</div>
    </div>
    <?php if (!$methodSplit): ?>
      <p style="font-size:12.5px;color:var(--color-ink-mute);margin:0">No captured payments yet.</p>
    <?php else: ?>
      <?php foreach ($methodSplit as [$method, $pct]): ?>
        <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px">
          <strong><?=h($method)?></strong><span style="color:var(--color-ink-mute)"><?=number_format($pct, 2)?>%</span>
        </div>
        <div style="height:8px;border-radius:99px;background:#eef2f8;overflow:hidden">
          <div style="height:100%;width:<?=(float)$pct?>%;background:var(--color-primary);border-radius:99px"></div>
        </div>
      <?php endforeach ?>
    <?php endif ?>
  </div>
</div>

<?php if ($detail): ?>
  <div class="card" style="margin-bottom:20px;border-color:var(--color-primary)">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <div>
        <div class="card-title"><?=icon('receipt', 16)?> <?=h((string)$detail['order_id'])?></div>
        <div class="card-sub">Created <?=h(date('d M Y, H:i:s', strtotime((string)$detail['created_at'])))?></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?=tg_status_badge((string)$detail['status'])?>
        <a class="btn btn-secondary btn-sm" href="/transactions.php<?=h($query(['view' => null]))?>">Close</a>
      </div>
    </div>
    <div class="dashboard-bottom-grid" style="grid-template-columns:1fr 1fr;gap:10px 28px">
      <?php foreach ([
          'Amount' => '₹'.number_format((float)$detail['amount'], 2),
          'Payable amount' => '₹'.number_format((float)$detail['payable_amount'], 2),
          'Bank RRN / UTR' => $detail['utr'] ?: '—',
          'Transaction ID' => $detail['transaction_id'] ?: '—',
          'Customer name' => $detail['customer_name'] ?: '—',
          'Customer email' => $detail['customer_email'] ?: '—',
          'Customer phone' => $detail['customer_phone'] ?: '—',
          'Payer name from bank' => $detail['sender_name'] ?: '—',
          'Source' => $detail['source'] ?: '—',
          'Paid at' => $detail['paid_at'] ?: '—',
          'Expires at' => $detail['expires_at'] ?: '—',
          'Receiving account' => $detail['merchant_account_id'] ? '#'.$detail['merchant_account_id'] : '—',
          'Redirect URL' => $detail['redirect_url'] ?: '—',
          'Webhook URL' => $detail['webhook_url'] ?: '—',
          'Note' => $detail['note'] ?: '—',
      ] as $label => $value): ?>
        <div style="padding:5px 0;border-bottom:1px solid var(--color-hairline)">
          <div class="form-hint"><?=h($label)?></div>
          <div style="font-size:13px;word-break:break-word"><?=h($value)?></div>
        </div>
      <?php endforeach ?>
    </div>
    <?php if ($detail['status'] === 'success'): ?>
      <div style="margin-top:16px">
        <a class="btn btn-primary btn-sm" href="/invoice.php?order_id=<?=h(rawurlencode((string)$detail['order_id']))?>"><?=icon('file-text', 14)?> Open receipt</a>
      </div>
    <?php endif ?>
  </div>
<?php elseif ($viewId !== ''): ?>
  <div class="callout callout-warning" style="margin-bottom:18px">
    <?=icon('alert-circle', 16)?><span>No order <code><?=h($viewId)?></code> on this account.</span>
  </div>
<?php endif ?>

<div class="card" style="padding:0;overflow:hidden">
  <form method="get" style="padding:18px 24px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;border-bottom:1px solid var(--color-hairline)">
    <?php if ($detail): ?><input type="hidden" name="view" value="<?=h((string)$detail['order_id'])?>"><?php endif ?>
    <div style="position:relative;flex:1;min-width:200px">
      <span style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--color-ink-mute-2);display:flex"><?=icon('search', 15)?></span>
      <input class="form-input" style="padding-left:34px" name="search" value="<?=h($search)?>" placeholder="Order ID, UTR, customer">
    </div>
    <select class="form-input" name="range" style="max-width:180px" onchange="this.form.submit()">
      <?php foreach (['all' => 'All Time', 'today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 Days', '30d' => 'Last 30 Days', 'this_month' => 'This Month', 'last_month' => 'Last Month'] as $key => $label): ?>
        <option value="<?=$key?>" <?=$range === $key ? 'selected' : ''?>><?=$label?></option>
      <?php endforeach ?>
    </select>
    <select class="form-input" name="per_page" style="max-width:130px" onchange="this.form.submit()" title="Rows per page">
      <?php foreach ([10, 25, 50] as $n): ?>
        <option value="<?=$n?>" <?=$perPage === $n ? 'selected' : ''?>><?=$n?> / page</option>
      <?php endforeach ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Search</button>
    <?php if ($search !== '' || $range !== 'all' || $status !== 'all'): ?>
      <a class="btn btn-secondary btn-sm" href="/transactions.php">Reset</a>
    <?php endif ?>
  </form>

  <div style="padding:0 24px">
    <div class="filter-tabs">
      <?php foreach (['all' => 'All', 'pending' => 'Created', 'success' => 'Captured', 'expired' => 'Expired'] as $key => $label): ?>
        <a class="filter-tab <?=$status === $key ? 'active' : ''?>" href="<?=h($query(['status' => $key, 'page' => 1]))?>" style="text-decoration:none">
          <?=$label?><span class="count"><?=number_format($counts[$key])?></span>
        </a>
      <?php endforeach ?>
    </div>
  </div>

  <div class="table-responsive" style="border-top:1px solid var(--color-hairline)">
    <table>
      <thead>
        <tr>
          <th>Order ID</th><th>Bank RRN</th><th>Customer detail</th><th>Created on</th>
          <th>Amount</th><th>Status</th><th style="text-align:right">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7">
          <div class="empty-state">
            <div class="empty-state-icon"><?=icon('filter', 22)?></div>
            <div class="empty-state-title">No payments match your current search and filters</div>
            <div class="empty-state-sub">Try a wider date range, or clear the search box.</div>
            <a class="btn btn-secondary btn-sm" href="/transactions.php" style="margin-top:14px">Clear filters</a>
          </div>
        </td></tr>
      <?php endif ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><code style="font-size:12px;font-weight:600"><?=h($r['order_id'])?></code></td>
          <td><?=h($r['utr'] ?: '—')?></td>
          <td>
            <div style="font-weight:500"><?=h($r['customer_name'] ?: ($r['customer_email'] ?: 'Customer'))?></div>
            <?php if (!empty($r['customer_phone'])): ?>
              <div style="font-size:11px;color:var(--color-ink-mute)"><?=h($r['customer_phone'])?></div>
            <?php endif ?>
          </td>
          <td style="white-space:nowrap"><?=h(date('d M Y, H:i', strtotime((string)$r['created_at'])))?></td>
          <td class="tnum" style="font-weight:600">₹<?=number_format((float)$r['amount'], 2)?></td>
          <td><?=tg_status_badge((string)$r['status'])?></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn btn-secondary btn-sm" href="<?=h($query(['view' => (string)$r['order_id']]))?>">Details</a>
            <?php if ($r['status'] === 'success'): ?>
              <a class="btn btn-secondary btn-sm" href="/invoice.php?order_id=<?=rawurlencode((string)$r['order_id'])?>">Receipt</a>
            <?php elseif ($r['status'] === 'pending'): ?>
              <a class="btn btn-secondary btn-sm" href="/pay.php?order_id=<?=rawurlencode((string)$r['order_id'])?>">Checkout</a>
            <?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>

  <div style="padding:14px 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border-top:1px solid var(--color-hairline);font-size:12px;color:var(--color-ink-mute)">
    <span>Showing page <?=$page?> of <?=$pages?> (Total <?=number_format($total)?> records)</span>
    <span style="display:flex;gap:8px">
      <?php if ($page > 1): ?>
        <a class="btn btn-secondary btn-sm" href="<?=h($query(['page' => $page - 1]))?>">← Previous</a>
      <?php endif ?>
      <?php if ($page < $pages): ?>
        <a class="btn btn-secondary btn-sm" href="<?=h($query(['page' => $page + 1]))?>">Next →</a>
      <?php endif ?>
    </span>
  </div>
</div>

<?php require __DIR__.'/includes/dashboard-footer.php'; ?>
