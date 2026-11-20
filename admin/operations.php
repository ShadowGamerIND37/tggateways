<?php
declare(strict_types=1);
/** Read-only operations queue. No payloads, secrets, webhook URLs or raw emails. */
require_once __DIR__.'/../includes/admin-shell.php';
require_once __DIR__.'/../includes/pager.php';
require_once __DIR__.'/../includes/webhook.php';
tg_admin_require();
if (!tg_admin_can('view_pii')) { http_response_code(403); exit('Your role cannot view payment operations.'); }
tg_header('Cache-Control: private, no-store');
$pdo = db();
$view = ($_GET['view'] ?? '') === 'receipts' ? 'receipts' : 'webhooks';
$state = is_string($_GET['state'] ?? null) ? (string)$_GET['state'] : '';
$search = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 80);
$maxAttempts = webhook_max_attempts();
$allowed = $view === 'webhooks' ? ['scheduled','attention','delivered'] : ['matched','ambiguous','unmatched','unparsed','duplicate','error'];
if (!in_array($state, $allowed, true)) $state = '';
$where = [];
$args = [];
if ($view === 'webhooks') {
    $from = ' FROM webhook_logs w LEFT JOIN orders o ON o.order_id=w.order_id LEFT JOIN merchants m ON m.id=o.merchant_id';
    if ($state === 'delivered') $where[] = 'w.delivered_at IS NOT NULL';
    if ($state === 'scheduled') { $where[] = 'w.delivered_at IS NULL AND w.next_retry_at IS NOT NULL AND w.attempt_count<?'; $args[] = $maxAttempts; }
    if ($state === 'attention') { $where[] = 'w.delivered_at IS NULL AND (w.next_retry_at IS NULL OR w.attempt_count>=?)'; $args[] = $maxAttempts; }
    if ($search !== '') { $where[] = '(w.order_id LIKE ? OR m.name LIKE ?)'; $args[] = '%'.$search.'%'; $args[] = '%'.$search.'%'; }
    $select = 'SELECT w.order_id,w.event,w.response_code,w.attempt_count,w.next_retry_at,w.delivered_at,w.created_at,m.name merchant';
    $orderBy = 'w.id';
} else {
    $from = ' FROM receipt_events r LEFT JOIN merchants m ON m.id=r.merchant_id';
    if ($state !== '') { $where[] = 'r.state=?'; $args[] = $state; }
    if ($search !== '') { $where[] = '(r.order_ref LIKE ? OR r.matched_order_id LIKE ? OR r.utr LIKE ? OR m.name LIKE ?)'; for ($i=0; $i<4; $i++) $args[] = '%'.$search.'%'; }
    $select = 'SELECT r.state,r.utr,r.amount,r.order_ref,r.matched_order_id,r.created_at,m.name merchant';
    $orderBy = 'r.id';
}
$filter = $where ? ' WHERE '.implode(' AND ', array_map(static fn($part) => '('.$part.')', $where)) : '';
$rows = [];
$error = '';
$summary = [];
$page = tg_paginate(0, 25);
try {
    if ($view === 'webhooks') {
        $stats = $pdo->prepare('SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END),0) delivered, COALESCE(SUM(CASE WHEN delivered_at IS NULL AND next_retry_at IS NOT NULL AND attempt_count<? THEN 1 ELSE 0 END),0) scheduled, COALESCE(SUM(CASE WHEN delivered_at IS NULL AND (next_retry_at IS NULL OR attempt_count>=?) THEN 1 ELSE 0 END),0) attention FROM webhook_logs');
        $stats->execute([$maxAttempts, $maxAttempts]);
        $summary = $stats->fetch();
    } else {
        $stats = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN state='duplicate' THEN 1 ELSE 0 END),0) duplicates, COALESCE(SUM(CASE WHEN state IN ('unmatched','unparsed','ambiguous','error') THEN 1 ELSE 0 END),0) review, COALESCE(SUM(CASE WHEN state='matched' THEN 1 ELSE 0 END),0) matched FROM receipt_events");
        $summary = $stats->fetch();
    }
    $count = $pdo->prepare('SELECT COUNT(*)'.$from.$filter);
    $count->execute($args);
    $page = tg_paginate((int)$count->fetchColumn(), 25);
    $query = $pdo->prepare($select.$from.$filter.' ORDER BY '.$orderBy.' DESC LIMIT '.$page['perPage'].' OFFSET '.$page['offset']);
    $query->execute($args);
    $rows = $query->fetchAll();
} catch (Throwable $e) {
    error_log('admin operations: '.$e->getMessage());
    $error = 'The operations queue could not be loaded. Check database migrations and server logs.';
}
admin_header('Payment operations', 'operations');
admin_flash('', $error);
?>
<div class="admin-ops-intro"><div><h2>Payment operations</h2><p>Investigate delivery and receipt activity without changing payment state.</p></div><a class="admin-btn admin-btn-ghost" href="/admin/settings.php">Deployment health →</a></div>
<nav class="admin-ops-tabs" aria-label="Operations views"><a class="<?=$view === 'webhooks' ? 'active' : ''?>" href="/admin/operations.php?view=webhooks" <?=$view === 'webhooks' ? 'aria-current="page"' : ''?>>Webhook delivery</a><a class="<?=$view === 'receipts' ? 'active' : ''?>" href="/admin/operations.php?view=receipts" <?=$view === 'receipts' ? 'aria-current="page"' : ''?>>Receipt events</a></nav>
<?php if (!$error): ?>
<div class="admin-stats">
<?php $cards = $view === 'webhooks' ? ['total'=>'Total events','scheduled'=>'Scheduled retries','attention'=>'Needs attention','delivered'=>'Delivered'] : ['total'=>'Total events','duplicates'=>'Duplicate observations','review'=>'Needs review','matched'=>'Matched']; foreach ($cards as $key=>$label): ?>
  <div class="admin-stat"><div class="admin-stat-label"><?=h($label)?></div><div class="admin-stat-value"><?=number_format((int)($summary[$key] ?? 0))?></div></div>
<?php endforeach ?>
</div>
<?php endif ?>
<div class="admin-ops-hint"><?php if ($view === 'webhooks'): ?>Scheduled events are retried by the worker. Needs attention means the retry limit was reached or no retry is scheduled; check the merchant’s webhook configuration and worker. No delivery is triggered here.<?php else: ?>Ambiguous receipts already settled the oldest eligible order. Review unmatched, unparsed, ambiguous or error events against the merchant’s records; this page cannot settle or reverse an order.<?php endif ?> Times use server time (<?=h(date_default_timezone_get())?>).</div>
<form method="get" class="admin-toolbar">
  <input type="hidden" name="view" value="<?=h($view)?>">
  <div class="admin-search"><?=icon('search',15)?><input type="search" name="q" value="<?=h($search)?>" maxlength="80" placeholder="Order reference or merchant<?= $view === 'receipts' ? ' or UTR' : ''?>" aria-label="Search operations"></div>
  <select class="admin-select" name="state" aria-label="Filter by state"><option value="">All states</option><?php foreach ($allowed as $item): ?><option value="<?=h($item)?>"<?=$state === $item ? ' selected' : ''?>><?=h(ucfirst($item))?></option><?php endforeach ?></select>
  <button class="admin-btn admin-btn-ghost">Filter</button><a class="admin-btn admin-btn-ghost" href="/admin/operations.php?view=<?=h($view)?>">Clear</a>
</form>
<div class="admin-panel"><div class="admin-table-wrap">
<?php if ($error): ?><div class="admin-empty">Queue unavailable.</div><?php elseif (!$rows): ?><div class="admin-empty">No events match this view.</div><?php else: ?>
<table class="admin-table">
<?php if ($view === 'webhooks'): ?><thead><tr><th>Order</th><th>Merchant</th><th>Event</th><th>State</th><th>HTTP</th><th>Attempts</th><th>Next retry</th><th>Created</th></tr></thead><tbody>
<?php foreach ($rows as $row): $result = $row['delivered_at'] !== null ? 'Delivered' : (($row['next_retry_at'] !== null && (int)$row['attempt_count'] < $maxAttempts) ? 'Scheduled' : 'Needs attention'); ?>
<tr><td><a href="/admin/orders.php?q=<?=h(rawurlencode((string)$row['order_id']))?>"><code><?=h((string)$row['order_id'])?></code></a></td><td><?=h((string)($row['merchant'] ?? '—'))?></td><td><?=h((string)$row['event'])?></td><td><span class="admin-pill <?=$result === 'Delivered' ? 'admin-pill-ok' : ($result === 'Scheduled' ? 'admin-pill-warn' : 'admin-pill-bad')?>"><?=h($result)?></span></td><td><?=h((string)($row['response_code'] ?? '—'))?></td><td><?=(int)$row['attempt_count']?> / <?=$maxAttempts?></td><td><?=h((string)($row['next_retry_at'] ?? '—'))?></td><td><?=h((string)$row['created_at'])?></td></tr>
<?php endforeach ?></tbody>
<?php else: ?><thead><tr><th>State</th><th>Merchant</th><th>Reference / UTR</th><th>Order</th><th>Amount</th><th>Recorded</th></tr></thead><tbody>
<?php foreach ($rows as $row): $orderRef = (string)($row['matched_order_id'] ?: $row['order_ref'] ?: ''); ?><tr><td><span class="admin-pill <?=$row['state'] === 'matched' ? 'admin-pill-ok' : 'admin-pill-warn'?>"><?=h(ucfirst((string)$row['state']))?></span></td><td><?=h((string)($row['merchant'] ?? '—'))?></td><td><?=h((string)($row['utr'] ?? '—'))?></td><td><?php if ($orderRef !== ''): ?><a href="/admin/orders.php?q=<?=h(rawurlencode($orderRef))?>"><code><?=h($orderRef)?></code></a><?php else: ?>—<?php endif ?></td><td><?=$row['amount'] !== null ? '₹'.number_format((float)$row['amount'],2) : '—'?></td><td><?=h((string)$row['created_at'])?></td></tr><?php endforeach ?></tbody><?php endif ?>
</table><?php endif ?></div><?=tg_pager($page, ['view'=>$view, 'state'=>$state, 'q'=>$search, 'per_page'=>$page['perPage']])?></div>
<?php admin_footer(); ?>
