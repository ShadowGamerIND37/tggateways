<?php
declare(strict_types=1);
/**
 * Deployment-wide audit trail.
 *
 * Reads the same `audit_log` table the merchant panel writes to, so an operator
 * sees admin actions and merchant actions in one ordered stream. Exports to CSV
 * for an incident report.
 */
require_once __DIR__.'/../includes/admin-shell.php';

$pdo = db();
tg_admin_require();

$q = trim((string)($_GET['q'] ?? ''));
$where = [];
$args = [];
if ($q !== '') {
    $where[] = '(a.action LIKE ? OR a.detail LIKE ? OR m.email LIKE ?)';
    $like = '%'.$q.'%';
    array_push($args, $like, $like, $like);
}
$sql = 'SELECT a.action,a.detail,a.ip,a.created_at,m.email merchant_email
        FROM audit_log a LEFT JOIN merchants m ON m.id=a.merchant_id'
     . ($where ? ' WHERE '.implode(' AND ', $where) : '')
     . ' ORDER BY a.id DESC LIMIT 400';

$rows = [];
$error = '';
try {
    $stmt = $pdo->prepare($sql);
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
  <span style="margin-left:auto;font-size:11.5px;color:var(--color-ink-mute)"><?=count($rows)?> entries (max 400)</span>
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
</div>

<?php admin_footer(); ?>
