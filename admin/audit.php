<?php
declare(strict_types=1);
/**
 * Deployment-wide audit trail.
 *
 * Reads the same `audit_log` table the merchant panel writes to, so an operator
 * sees admin actions and merchant actions in one ordered stream.
 */
require_once __DIR__.'/../includes/admin-shell.php';
require_once __DIR__.'/../includes/pager.php';

$pdo = db();
tg_admin_require();

$q = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 80);
$where = [];
$args = [];
if ($q !== '') {
    $where[] = '(a.action LIKE ? OR a.detail LIKE ? OR m.email LIKE ?)';
    $like = '%'.$q.'%';
    array_push($args, $like, $like, $like);
}
$from = ' FROM audit_log a LEFT JOIN merchants m ON m.id=a.merchant_id'
     . ($where ? ' WHERE '.implode(' AND ', $where) : '');
$rows = [];
$error = '';
$page = tg_paginate(0, 50);
try {
    $count = $pdo->prepare('SELECT COUNT(*)'.$from);
    $count->execute($args);
    $page = tg_paginate((int)$count->fetchColumn(), 50);
    $stmt = $pdo->prepare('SELECT a.action,a.detail,a.ip,a.created_at,m.email merchant_email'.$from.' ORDER BY a.id DESC LIMIT '.$page['perPage'].' OFFSET '.$page['offset']);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = 'The audit log could not be read.';
    error_log('admin audit: '.$e->getMessage());
}

admin_header('Audit log', 'audit');
admin_flash('', $error);
?>

<form method="get" class="admin-toolbar">
  <div class="admin-search">
    <?=icon('search', 15)?>
    <input type="search" name="q" value="<?=h($q)?>" placeholder="Action, detail or merchant email…" aria-label="Search audit log">
  </div>
  <button class="admin-btn admin-btn-ghost"><?=icon('search', 14)?> Filter</button>
  <?php if ($q !== ''): ?><a class="admin-btn admin-btn-ghost" href="/admin/audit.php">Clear</a><?php endif ?>
  <span style="margin-left:auto;font-size:11.5px;color:var(--color-ink-mute)"><?=number_format($page['total'])?> matching entries</span>
</form>

<div class="admin-panel">
  <div class="admin-table-wrap">
    <?php if (!$rows): ?>
      <div class="admin-empty">Nothing recorded yet.</div>
    <?php else: ?>
      <table class="admin-table">
        <thead><tr><th>When</th><th>Action</th><th>Detail</th><th>Merchant</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $isAdminAction = str_starts_with((string)$r['action'], 'admin.'); ?>
          <tr>
            <td class="muted" style="font-size:11px;white-space:nowrap"><?=h(substr((string)$r['created_at'], 0, 16))?></td>
            <td>
              <span class="admin-pill <?=$isAdminAction ? 'admin-pill-info' : 'admin-pill-neutral'?>"><?=h((string)$r['action'])?></span>
            </td>
            <td class="muted" style="font-size:11.5px"><?=h((string)($r['detail'] ?? ''))?></td>
            <td class="muted" style="font-size:11px"><?=h((string)($r['merchant_email'] ?? '—'))?></td>
            <td class="muted" style="font-size:11px"><?=h((string)($r['ip'] ?? '—'))?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>
  <?=tg_pager($page, ['q'=>$q, 'per_page'=>$page['perPage']])?>
</div>

<?php admin_footer(); ?>
