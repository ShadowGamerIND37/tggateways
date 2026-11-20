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

$pdo = db();
$admin = tg_admin_require();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tg_admin_verify_csrf();
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
            $pdo->prepare("INSERT INTO ticket_messages(ticket_id,author,body,created_at) VALUES(?,'admin',?,?)")
                ->execute([$ticketId, mb_substr($body, 0, 5000), db_now()]);
            $pdo->prepare("UPDATE tickets SET status=?,updated_at=? WHERE id=?")
                ->execute([$close ? 'closed' : 'open', db_now(), $ticketId]);
            tg_audit((int)($ticket['merchant_id'] ?? 0) ?: null, 'admin.ticket_reply', '#'.$ticketId);
            if (!empty($ticket['merchant_email'])) {
                tg_smtp_send(
                    (string)$ticket['merchant_email'],
                    'Reply to your support ticket #'.$ticketId,
                    "Hello ".($ticket['merchant_name'] ?? 'there').",\n\n".$body."\n\n— TG Gateways support"
                );
            }
            $notice = 'Reply sent'.($close ? ' and the ticket was closed.' : ' to the merchant.');
        }
    } catch (Throwable $e) {
        $error = 'The reply could not be saved.';
        error_log('admin ticket reply: '.$e->getMessage());
    }
}

$status = (string)($_GET['status'] ?? 'open');
$where = in_array($status, ['open', 'closed', 'pending'], true) ? ' WHERE t.status=?' : '';
$rows = [];
$errorLoad = '';
try {
    $stmt = $pdo->prepare("SELECT t.id,t.subject,t.status,t.created_at,t.updated_at,m.name merchant,m.email merchant_email,
                                  (SELECT COUNT(*) FROM ticket_messages tm WHERE tm.ticket_id=t.id) messages
                           FROM tickets t LEFT JOIN merchants m ON m.id=t.merchant_id".$where.' ORDER BY t.id DESC LIMIT 100');
    $stmt->execute($where !== '' ? [$status] : []);
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
      <form method="post" style="margin-top:20px;border-top:1px solid var(--color-hairline);padding-top:18px">
        <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
        <input type="hidden" name="ticket_id" value="<?=$openId?>">
        <div class="admin-field">
          <label for="body">Reply to the merchant</label>
          <textarea class="admin-textarea" id="body" name="body" rows="5" placeholder="Write your reply…"></textarea>
          <div class="hint">Sent by email through the deployment's SMTP settings and stored on the ticket.</div>
        </div>
        <div class="admin-actions">
          <button class="admin-btn admin-btn-primary">Send reply</button>
          <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--color-ink-mute)">
            <input type="checkbox" name="close" value="1"> Close the ticket after replying
          </label>
        </div>
      </form>
    </div>
  </div>
<?php endif ?>

<form method="get" class="admin-toolbar">
  <select class="admin-select" name="status" style="max-width:170px" onchange="this.form.submit()" aria-label="Ticket status">
    <option value="open"<?=$status === 'open' ? ' selected' : ''?>>Open</option>
    <option value="pending"<?=$status === 'pending' ? ' selected' : ''?>>Pending</option>
    <option value="closed"<?=$status === 'closed' ? ' selected' : ''?>>Closed</option>
  </select>
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
              <span class="admin-pill <?=$t['status'] === 'open' ? 'admin-pill-warn' : ($t['status'] === 'closed' ? 'admin-pill-neutral' : 'admin-pill-info')?>"><?=h(ucfirst((string)$t['status']))?></span>
            </td>
            <td class="num muted"><?=(int)$t['messages']?></td>
            <td class="muted" style="font-size:11px"><?=h(substr((string)($t['updated_at'] ?? $t['created_at']), 0, 16))?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>
</div>

<?php admin_footer(); ?>
