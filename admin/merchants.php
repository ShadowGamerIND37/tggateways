<?php
declare(strict_types=1);
/**
 * Merchant administration: search, inspect, suspend, restore, add notes.
 *
 * Suspension is enforced in three places, not just here: the login form refuses
 * a suspended account, `require_login()` is unaware of status so nothing in the
 * dashboard half-works, and the API rejects a suspended merchant's key. A badge
 * on this page that did not actually stop anything would be theatre.
 */
require_once __DIR__.'/../includes/admin-shell.php';

$pdo = db();
$admin = tg_admin_require();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tg_admin_verify_csrf();
    if (!tg_admin_can('manage_merchants')) {
        $error = 'Your role cannot change merchant accounts.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $target = (int)($_POST['merchant_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM merchants WHERE id=? LIMIT 1');
        $stmt->execute([$target]);
        $target_row = $stmt->fetch();

        if (!$target_row) {
            $error = 'That merchant no longer exists.';
        } elseif ($action === 'suspend') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            $pdo->prepare("UPDATE merchants SET status='suspended',suspended_reason=? WHERE id=?")
                ->execute([$reason !== '' ? mb_substr($reason, 0, 255) : null, $target]);
            tg_audit($target, 'admin.merchant_suspended', $reason);
            $notice = $target_row['name'].' suspended. They can no longer sign in or call the API.';
        } elseif ($action === 'restore') {
            $pdo->prepare("UPDATE merchants SET status='active',suspended_reason=NULL WHERE id=?")
                ->execute([$target]);
            tg_audit($target, 'admin.merchant_restored', '');
            $notice = $target_row['name'].' restored to active.';
        } elseif ($action === 'note') {
            $note = trim((string)($_POST['note'] ?? ''));
            if ($note === '') {
                $error = 'Write something before saving a note.';
            } else {
                $pdo->prepare('INSERT INTO admin_notes(merchant_id,admin_id,note,created_at) VALUES(?,?,?,?)')
                    ->execute([$target, $admin['id'], mb_substr($note, 0, 2000), db_now()]);
                tg_audit($target, 'admin.note_added', mb_substr($note, 0, 120));
                $notice = 'Note saved on '.$target_row['name'].'.';
            }
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$where = [];
$args = [];
if ($q !== '') {
    $where[] = '(name LIKE ? OR email LIKE ? OR api_key LIKE ?)';
    $like = '%'.$q.'%';
    array_push($args, $like, $like, $like);
}
if ($statusFilter === 'suspended') $where[] = "COALESCE(status,'active')='suspended'";
if ($statusFilter === 'active')    $where[] = "COALESCE(status,'active')='active'";
$sql = 'SELECT * FROM merchants'.($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY id DESC LIMIT 200';

$rows = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $error !== '' ? $error : 'The merchant list could not be loaded.';
    error_log('admin merchants: '.$e->getMessage());
}

// Per-merchant aggregates, one query instead of N.
$stats = [];
try {
    foreach ($pdo->query(
        "SELECT merchant_id,
                COUNT(*) AS orders,
                SUM(CASE WHEN status='success' THEN payable_amount ELSE 0 END) AS collected,
                MAX(created_at) AS last_order
         FROM orders GROUP BY merchant_id"
    )->fetchAll() as $s) {
        $stats[(int)$s['merchant_id']] = $s;
    }
} catch (Throwable $e) { /* aggregates are optional */ }

$walletCounts = [];
try {
    foreach ($pdo->query('SELECT merchant_id, COUNT(*) c, SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) active FROM merchant_accounts GROUP BY merchant_id')->fetchAll() as $w) {
        $walletCounts[(int)$w['merchant_id']] = $w;
    }
} catch (Throwable $e) { /* ignored */ }

admin_header('Merchants', 'merchants');
admin_flash($notice, $error);
?>

<form method="get" class="admin-toolbar">
  <div class="admin-search">
    <?=icon('search', 15)?>
    <input type="search" name="q" value="<?=h($q)?>" placeholder="Search by name, email or API key…" aria-label="Search merchants">
  </div>
  <select class="admin-select" name="status" style="max-width:170px" aria-label="Status filter" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <option value="active"<?=$statusFilter === 'active' ? ' selected' : ''?>>Active only</option>
    <option value="suspended"<?=$statusFilter === 'suspended' ? ' selected' : ''?>>Suspended only</option>
  </select>
  <button class="admin-btn admin-btn-ghost"><?=icon('search', 14)?> Filter</button>
  <?php if ($q !== '' || $statusFilter !== ''): ?>
    <a class="admin-btn admin-btn-ghost" href="/admin/merchants.php">Clear</a>
  <?php endif ?>
  <span class="muted" style="margin-left:auto;font-size:11.5px;color:var(--color-ink-mute)">
    <?=count($rows)?> shown
  </span>
</form>

<?php if (!$rows): ?>
  <div class="admin-panel"><div class="admin-empty">
    No merchants match<?=$q !== '' ? ' “'.h($q).'”' : ''?>. <?php if ($q !== '' || $statusFilter !== ''): ?><a href="/admin/merchants.php">Show all</a><?php endif ?>
  </div></div>
<?php endif ?>

<?php foreach ($rows as $m): ?>
  <?php
    $mid = (int)$m['id'];
    $isSuspended = ($m['status'] ?? 'active') === 'suspended';
    $s = $stats[$mid] ?? null;
    $w = $walletCounts[$mid] ?? null;
    $openPanel = (isset($_GET['open']) && (int)$_GET['open'] === $mid);
  ?>
  <div class="admin-panel" id="m<?=$mid?>">
    <div class="admin-panel-body" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
      <div style="flex:1 1 260px;min-width:0">
        <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap">
          <strong style="font-size:14px"><?=h((string)$m['name'])?></strong>
          <?php if ($isSuspended): ?>
            <span class="admin-pill admin-pill-bad"><?=icon('ban', 11)?> Suspended</span>
          <?php else: ?>
            <span class="admin-pill admin-pill-ok">Active</span>
          <?php endif ?>
          <span class="admin-pill admin-pill-neutral">#<?=$mid?></span>
        </div>
        <div style="font-size:12px;color:var(--color-ink-mute);margin-top:5px">
          <?=h((string)$m['email'])?> · joined <?=h(substr((string)$m['created_at'], 0, 10))?>
        </div>
        <?php if ($isSuspended && ($m['suspended_reason'] ?? '') !== ''): ?>
          <div style="font-size:11.5px;color:#b91c1c;margin-top:6px">
            <strong>Reason:</strong> <?=h((string)$m['suspended_reason'])?>
          </div>
        <?php endif ?>
        <div style="display:flex;gap:18px;margin-top:10px;flex-wrap:wrap;font-size:11.5px;color:var(--color-ink-mute)">
          <span><strong style="color:var(--color-ink)"><?=number_format((int)($s['orders'] ?? 0))?></strong> orders</span>
          <span><strong style="color:var(--color-ink)">₹<?=number_format((float)($s['collected'] ?? 0), 2)?></strong> collected</span>
          <span><strong style="color:var(--color-ink)"><?=(int)($w['active'] ?? 0)?></strong> active wallet<?=(int)($w['active'] ?? 0) === 1 ? '' : 's'?></span>
          <span>last order <?=h((string)(($s['last_order'] ?? '') !== '' && ($s['last_order'] ?? null) !== null ? substr((string)$s['last_order'], 0, 10) : '—'))?></span>
        </div>
      </div>
      <div class="admin-actions">
        <a class="admin-btn admin-btn-ghost admin-btn-sm" href="/admin/merchants.php?open=<?=$mid?>#m<?=$mid?>">
          <?=icon('file-text', 13)?> Notes
        </a>
        <?php if ($isSuspended): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="merchant_id" value="<?=$mid?>">
            <button class="admin-btn admin-btn-ghost admin-btn-sm"<?=tg_admin_can('manage_merchants') ? '' : ' disabled'?>><?=icon('check-circle', 13)?> Restore</button>
          </form>
        <?php else: ?>
          <button class="admin-btn admin-btn-danger admin-btn-sm" type="button"
                  onclick="document.getElementById('suspend<?=$mid?>').showModal()"
                  <?=tg_admin_can('manage_merchants') ? '' : 'disabled'?>><?=icon('ban', 13)?> Suspend</button>
        <?php endif ?>
      </div>
    </div>

    <?php if ($openPanel): ?>
      <?php
        $notes = [];
        try {
            $nq = $pdo->prepare('SELECT n.note,n.created_at,a.email admin_email FROM admin_notes n LEFT JOIN admins a ON a.id=n.admin_id WHERE n.merchant_id=? ORDER BY n.id DESC LIMIT 30');
            $nq->execute([$mid]);
            $notes = $nq->fetchAll();
        } catch (Throwable $e) { /* ignored */ }
      ?>
      <div style="border-top:1px solid var(--color-hairline);padding:18px 20px;background:#fafbfd">
        <h3 style="font-size:12.5px;margin:0 0 12px">Internal notes <span class="muted" style="font-weight:400">(never shown to the merchant)</span></h3>
        <?php if ($notes): ?>
          <ul style="list-style:none;padding:0;margin:0 0 14px;display:flex;flex-direction:column;gap:9px">
            <?php foreach ($notes as $n): ?>
              <li style="background:#fff;border:1px solid var(--color-hairline);border-radius:9px;padding:10px 12px;font-size:12px;line-height:1.7">
                <?=nl2br(h((string)$n['note']))?>
                <div style="font-size:10.5px;color:var(--color-ink-mute-2);margin-top:6px">
                  <?=h((string)($n['admin_email'] ?? 'system'))?> · <?=h(substr((string)$n['created_at'], 0, 16))?>
                </div>
              </li>
            <?php endforeach ?>
          </ul>
        <?php else: ?>
          <p style="font-size:12px;color:var(--color-ink-mute);margin:0 0 12px">No notes yet.</p>
        <?php endif ?>
        <form method="post" style="display:flex;gap:9px;align-items:flex-start;flex-wrap:wrap">
          <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
          <input type="hidden" name="action" value="note">
          <input type="hidden" name="merchant_id" value="<?=$mid?>">
          <textarea class="admin-textarea" name="note" rows="2" style="flex:1 1 300px;min-height:44px"
                    placeholder="e.g. called about a stuck receipt, asked to rotate the app password"></textarea>
          <button class="admin-btn admin-btn-primary admin-btn-sm">Save note</button>
        </form>
      </div>
    <?php endif ?>
  </div>

  <?php if (!$isSuspended): ?>
  <dialog id="suspend<?=$mid?>" class="tg-dialog">
    <form method="post">
      <h2 style="font-size:16px;margin:0 0 6px">Suspend <?=h((string)$m['name'])?>?</h2>
      <p style="font-size:12.5px;color:var(--color-ink-mute);line-height:1.7;margin:0 0 14px">
        They will be signed out on next request, cannot sign in again, and their API key will be refused.
        Their orders and data are kept, and this is reversible.
      </p>
      <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
      <input type="hidden" name="action" value="suspend">
      <input type="hidden" name="merchant_id" value="<?=$mid?>">
      <div class="admin-field">
        <label for="reason<?=$mid?>">Reason (optional, shown to them on the sign-in screen)</label>
        <input class="admin-input" id="reason<?=$mid?>" name="reason" maxlength="255" placeholder="Unpaid addon invoice INV-202611-AB12C3">
      </div>
      <div class="admin-actions" style="justify-content:flex-end">
        <button class="admin-btn admin-btn-ghost" type="button" onclick="this.closest('dialog').close()">Cancel</button>
        <button class="admin-btn admin-btn-danger">Suspend account</button>
      </div>
    </form>
  </dialog>
  <?php endif ?>
<?php endforeach ?>

<?php admin_footer(); ?>
