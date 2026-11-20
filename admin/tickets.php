<?php
declare(strict_types=1);
/**
 * Support queue.
 *
 * An operator can read and reply to a ticket from here; the reply goes through
 * the same notify transport as the merchant panel, so there is one mail path to
 * reason about rather than two.
 */
require_once __DIR__.'/../includes/admin-shell.php';
require_once __DIR__.'/../includes/notify.php';
require_once __DIR__.'/../includes/pager.php';

$pdo = db();
$admin = tg_admin_require();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tg_admin_verify_csrf();
    if (!tg_admin_can('manage_support')) { http_response_code(403); exit('Your role cannot reply to tickets.'); }
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $body = trim((string)($_POST['body'] ?? ''));
    $close = !empty($_POST['close']);
    try {
        $tq = $pdo->prepare('SELECT t.*,m.email merchant_email,m.name merchant_name FROM tickets t LEFT JOIN merchants m ON m.id=t.merchant_id WHERE t.id=? LIMIT 1');
        $tq->execute([$ticketId]);
        $ticket = $tq->fetch();
        if (!$ticket) {
            $error = 'That ticket no longer exists.';
        } elseif ($body === '') {
            $error = 'Write a reply before sending.';
        } else {
            $body = mb_substr($body, 0, 5000);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO ticket_messages(ticket_id,author,body,created_at) VALUES(?,'admin',?,?)")
                ->execute([$ticketId, $body, db_now()]);
            $pdo->prepare("UPDATE tickets SET status=?,last_author='admin',updated_at=? WHERE id=?")
                ->execute([$close ? 'resolved' : 'awaiting_reply', db_now(), $ticketId]);
            $pdo->commit();
            tg_audit((int)($ticket['merchant_id'] ?? 0) ?: null, 'admin.ticket_reply', '#'.$ticketId);
            $sent = false;
            try {
              if (!empty($ticket['merchant_email'])) {
                $sent = tg_smtp_send(
                    (string)$ticket['merchant_email'],
                    'Reply to your support ticket #'.$ticketId,
                    "Hello ".($ticket['merchant_name'] ?? 'there').",\n\n".$body."\n\n— TG Gateways support"
                );
              }
            } catch (Throwable $e) { error_log('admin ticket notification: '.$e->getMessage()); }
            $notice = 'Reply saved'.($close ? ' and the ticket was closed.' : '.');
            $notice .= $sent ? ' The mail server accepted the notification.' : ' Email notification could not be sent; check SMTP and contact the merchant separately.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'The reply could not be saved.';
        error_log('admin ticket reply: '.$e->getMessage());
    }
}

$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'active';
if ($status === 'active') $where = " WHERE t.status NOT IN ('resolved','closed')";
elseif (in_array($status, ['open','in_progress','awaiting_reply','resolved','pending','closed'], true)) $where = ' WHERE t.status=?';
else { $status = ''; $where = ''; }
$rows = [];
$errorLoad = '';
$page = tg_paginate(0, 25);
try {
    $args = $where === ' WHERE t.status=?' ? [$status] : [];
    $count = $pdo->prepare('SELECT COUNT(*) FROM tickets t'.$where);
    $count->execute($args);
    $page = tg_paginate((int)$count->fetchColumn(), 25);
    $stmt = $pdo->prepare("SELECT t.id,t.subject,t.status,t.created_at,t.updated_at,m.name merchant,m.email merchant_email,
                                  (SELECT COUNT(*) FROM ticket_messages tm WHERE tm.ticket_id=t.id) messages
                           FROM tickets t LEFT JOIN merchants m ON m.id=t.merchant_id".$where.' ORDER BY t.id DESC LIMIT '.$page['perPage'].' OFFSET '.$page['offset']);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $errorLoad = 'Tickets could not be loaded.';
    error_log('admin tickets: '.$e->getMessage());
}

$openId = (int)($_GET['ticket'] ?? 0);
$thread = [];
$threadTicket = null;
if ($openId > 0) {
    try {
        $tq = $pdo->prepare('SELECT t.*,m.name merchant,m.email merchant_email FROM tickets t LEFT JOIN merchants m ON m.id=t.merchant_id WHERE t.id=?');
        $tq->execute([$openId]);
        $threadTicket = $tq->fetch() ?: null;
        if ($threadTicket) {
            $mq = $pdo->prepare('SELECT author,body,created_at FROM ticket_messages WHERE ticket_id=? ORDER BY id');
            $mq->execute([$openId]);
            $thread = $mq->fetchAll();
        }
    } catch (Throwable $e) { error_log('admin ticket thread: '.$e->getMessage()); }
}

admin_header('Support', 'tickets');
admin_flash($notice, $error !== '' ? $error : $errorLoad);
?>

<?php if ($threadTicket): ?>
  <div class="admin-panel">
    <div class="admin-panel-head">
      <h2>#<?=$openId?> · <?=h((string)$threadTicket['subject'])?></h2>
      <a class="admin-btn admin-btn-ghost admin-btn-sm" href="/admin/tickets.php">← Back to queue</a>
    </div>
    <div class="admin-panel-body">
      <div class="muted" style="font-size:11.5px;margin-bottom:16px">
        <?=h((string)($threadTicket['merchant'] ?? 'Unknown merchant'))?> · <?=h((string)($threadTicket['merchant_email'] ?? ''))?>
        · opened <?=h(substr((string)$threadTicket['created_at'], 0, 16))?>
      </div>
      <?php foreach ($thread as $m): ?>
        <?php $fromAdmin = ($m['author'] ?? '') === 'admin'; ?>
        <div style="margin-bottom:14px;padding:13px 15px;border-radius:10px;border:1px solid var(--color-hairline);background:<?=$fromAdmin ? '#f0f7ff' : '#fafbfd'?>">
          <div style="font-size:10.5px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--color-ink-mute-2);margin-bottom:6px">
            <?=$fromAdmin ? 'Support' : 'Merchant'?> · <?=h(substr((string)$m['created_at'], 0, 16))?>
          </div>
          <div style="font-size:12.5px;line-height:1.8;white-space:pre-wrap"><?=h((string)$m['body'])?></div>
        </div>
      <?php endforeach ?>
      <?php if (tg_admin_can('manage_support')): ?>
      <form method="post" style="margin-top:20px;border-top:1px solid var(--color-hairline);padding-top:18px">
        <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
        <input type="hidden" name="ticket_id" value="<?=$openId?>">
        <div class="admin-field">
          <label for="body">Reply to the merchant</label>
          <textarea class="admin-textarea" id="body" name="body" rows="5" placeholder="Write your reply…"></textarea>
          <div class="hint">Saved on the ticket; an email notification is attempted through the deployment's SMTP settings.</div>
        </div>
        <div class="admin-actions">
          <button class="admin-btn admin-btn-primary">Send reply</button>
          <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--color-ink-mute)">
            <input type="checkbox" name="close" value="1"> Close the ticket after replying
          </label>
        </div>
      </form>
      <?php else: ?><p class="muted">Your role has read-only access to support tickets.</p><?php endif ?>
    </div>
  </div>
<?php endif ?>

<form method="get" class="admin-toolbar">
  <select class="admin-select" name="status" style="max-width:170px" onchange="this.form.submit()" aria-label="Ticket status">
    <?php foreach (['active'=>'All active', 'open'=>'Open', 'in_progress'=>'In progress', 'awaiting_reply'=>'Awaiting reply', 'resolved'=>'Resolved', 'pending'=>'Pending (legacy)', 'closed'=>'Closed (legacy)', ''=>'All tickets'] as $value=>$label): ?>
      <option value="<?=h($value)?>"<?=$status === $value ? ' selected' : ''?>><?=h($label)?></option>
    <?php endforeach ?>
  </select>
  <button class="admin-btn admin-btn-ghost" type="submit"><?=icon('search', 14)?> Filter</button>
  <a class="admin-btn admin-btn-ghost" href="/admin/tickets.php?status=">All tickets</a>
</form>

<div class="admin-panel">
  <div class="admin-table-wrap">
    <?php if (!$rows): ?>
      <div class="admin-empty">No tickets in this view.</div>
    <?php else: ?>
      <table class="admin-table">
        <thead><tr><th>#</th><th>Subject</th><th>Merchant</th><th>Status</th><th class="num">Messages</th><th>Updated</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
          <tr>
            <td class="muted"><?=(int)$t['id']?></td>
            <td><a href="/admin/tickets.php?ticket=<?=(int)$t['id']?>" style="color:var(--color-primary);font-weight:500;text-decoration:none"><?=h((string)$t['subject'])?></a></td>
            <td class="muted" style="font-size:11.5px"><?=h((string)($t['merchant'] ?? '—'))?></td>
            <td>
              <span class="admin-pill <?=$t['status'] === 'open' ? 'admin-pill-warn' : (in_array($t['status'], ['closed','resolved'], true) ? 'admin-pill-neutral' : 'admin-pill-info')?>"><?=h(ucwords(str_replace('_', ' ', (string)$t['status'])))?></span>
            </td>
            <td class="num muted"><?=(int)$t['messages']?></td>
            <td class="muted" style="font-size:11px"><?=h(substr((string)($t['updated_at'] ?? $t['created_at']), 0, 16))?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>
  <?=tg_pager($page, ['status'=>$status, 'per_page'=>$page['perPage']])?>
</div>

<?php admin_footer(); ?>
