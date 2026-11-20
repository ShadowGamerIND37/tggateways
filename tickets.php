<?php
declare(strict_types=1);
/**
 * Support Helpdesk — parity with the reference:
 * counters per state, status tabs, an "Open New Ticket" form with topic, order
 * reference and message, and a thread view for replies.
 *
 * Attachments are counted and described but stored nowhere: accepting uploads
 * into a payments deployment needs a private store, a size limit and a virus
 * policy, none of which this slice can promise. The form says so instead of
 * silently discarding files.
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/attachments.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/notify.php';
$merchant = require_login();
$pageTitle = 'Support Tickets';
$pdo = db();
$id = (int)$merchant['id'];
$notice = '';
$error = '';
$view = (int)($_GET['id'] ?? 0);

$topics = ['payment' => 'Payment / UTR', 'imap' => 'Gmail / IMAP', 'api' => 'API / Webhook', 'general' => 'General'];

/** All tickets for this merchant plus their reply counts. */
function tg_ticket_counts(PDO $pdo, int $merchantId): array {
    $q = $pdo->prepare('SELECT status, COUNT(*) n FROM tickets WHERE merchant_id=? GROUP BY status');
    $q->execute([$merchantId]);
    $counts = ['all' => 0, 'open' => 0, 'in_progress' => 0, 'awaiting_reply' => 0, 'resolved' => 0];
    foreach ($q->fetchAll() as $row) {
        $counts[$row['status']] = (int)$row['n'];
        $counts['all'] += (int)$row['n'];
    }
    return $counts;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('tickets', 12, 300);
    $action = (string)($_POST['action'] ?? 'create');
    try {
        if ($action === 'create') {
            $topic = (string)($_POST['topic'] ?? 'general');
            $subject = trim((string)($_POST['subject'] ?? ''));
            $orderRef = trim((string)($_POST['order_ref'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $attachments = min(5, max(0, (int)($_POST['attachment_count'] ?? 0)));
            if (!isset($topics[$topic])) $topic = 'general';
            if (mb_strlen($subject) < 4 || mb_strlen($subject) > 200) {
                $error = 'Give the ticket a subject of 4–200 characters.';
            } elseif (mb_strlen($message) < 10) {
                $error = 'Describe the issue in at least 10 characters.';
            } else {
                $reference = 'TCK-'.strtoupper(bin2hex(random_bytes(4)));
                $pdo->prepare('INSERT INTO tickets(merchant_id,reference,topic,subject,order_ref,status,last_author,attachments,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$id, $reference, $topic, $subject, $orderRef ?: null, 'open', 'merchant', $attachments, db_now(), db_now()]);
                $ticketId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO ticket_messages(ticket_id,author,body,attachment_count,created_at) VALUES(?,?,?,?,?)')
                    ->execute([$ticketId, 'merchant', $message, $attachments, db_now()]);
                $messageId = (int)$pdo->lastInsertId();
                $uploaded = tg_ticket_collect_uploads($ticketId, $id, $messageId);
                if ($uploaded['errors']) $error = implode(' ', $uploaded['errors']);
                if ($uploaded['stored'] > 0) {
                    $pdo->prepare('UPDATE tickets SET attachments=? WHERE id=?')->execute([$uploaded['stored'], $ticketId]);
                    $pdo->prepare('UPDATE ticket_messages SET attachment_count=? WHERE id=?')->execute([$uploaded['stored'], $messageId]);
                }
                tg_audit($id, 'ticket.created', $reference);
                $notice = 'Ticket '.$reference.' opened. Our team replies by email.';
                $view = $ticketId;
            }
        } elseif ($action === 'reply') {
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $body = trim((string)($_POST['message'] ?? ''));
            $own = $pdo->prepare('SELECT reference,status FROM tickets WHERE id=? AND merchant_id=?');
            $own->execute([$ticketId, $id]);
            $ticket = $own->fetch();
            if (!$ticket) {
                $error = 'That ticket no longer exists.';
            } elseif ($body === '') {
                $error = 'Write a reply before sending.';
            } else {
                $pdo->prepare('INSERT INTO ticket_messages(ticket_id,author,body,created_at) VALUES(?,?,?,?)')
                    ->execute([$ticketId, 'merchant', mb_substr($body, 0, 5000), db_now()]);
                $pdo->prepare("UPDATE tickets SET status='in_progress', last_author='merchant', updated_at=? WHERE id=?")
                    ->execute([db_now(), $ticketId]);
                $notice = 'Reply sent on '.$ticket['reference'].'.';
                $view = $ticketId;
            }
        } elseif ($action === 'close') {
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $pdo->prepare("UPDATE tickets SET status='resolved', updated_at=? WHERE id=? AND merchant_id=?")
                ->execute([db_now(), $ticketId, $id]);
            $notice = 'Ticket marked resolved. Reply any time to reopen it.';
        }
    } catch (Throwable $e) {
        error_log('tickets: '.$e->getMessage());
        $error = 'That action could not be completed.';
    }
}

$counts = tg_ticket_counts($pdo, $id);
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'open', 'in_progress', 'awaiting_reply', 'resolved'], true)) $status = 'all';

$listSql = 'SELECT * FROM tickets WHERE merchant_id=?'.($status === 'all' ? '' : ' AND status=?').' ORDER BY COALESCE(updated_at, created_at) DESC';
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($status === 'all' ? [$id] : [$id, $status]);
$tickets = $listStmt->fetchAll();

$thread = null;
$messages = [];
if ($view > 0) {
    $t = $pdo->prepare('SELECT * FROM tickets WHERE id=? AND merchant_id=?');
    $t->execute([$view, $id]);
    $thread = $t->fetch() ?: null;
    if ($thread) {
        $m = $pdo->prepare('SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY created_at, id');
        $m->execute([$view]);
        $messages = $m->fetchAll();
    }
}

// Files for the ticket currently on screen (empty when none is open).
$attachments = ($view > 0 && $thread !== null) ? tg_attachments_for((int)$view, $id) : [];

require __DIR__.'/includes/dashboard-header.php';
?>

<div class="page-title-row" style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-size:22px;font-weight:700;letter-spacing:-.5px;margin:0">Support Helpdesk</h1>
    <p style="font-size:13px;color:var(--color-ink-mute);margin:4px 0 0">Submit and track payment inquiries, bank UTR delays and API requests.</p>
  </div>
  <a class="btn btn-primary btn-sm" href="#new-ticket"><?=icon('plus', 14)?> Open New Ticket</a>
</div>

<?php if ($notice): ?><div class="callout callout-success" style="margin-bottom:18px"><?=icon('check-circle', 16)?><span><?=h($notice)?></span></div><?php endif ?>
<?php if ($error): ?><div class="callout callout-error" style="margin-bottom:18px"><?=icon('alert-circle', 16)?><span><?=h($error)?></span></div><?php endif ?>

<div class="stat-grid" style="margin-bottom:20px">
  <?php foreach ([
      ['Total Tickets', $counts['all'], 'blue'],
      ['Open', $counts['open'], 'orange'],
      ['In Progress', $counts['in_progress'], 'purple'],
      ['Resolved', $counts['resolved'], 'green'],
  ] as [$label, $value, $tone]): ?>
    <div class="stat-card">
      <div class="stat-card-top">
        <div class="stat-card-label"><?=h($label)?></div>
        <div class="stat-card-icon <?=$tone?>"><?=icon('life-buoy', 18)?></div>
      </div>
      <div class="stat-card-value tnum"><?=number_format((int)$value)?></div>
    </div>
  <?php endforeach ?>
</div>

<?php if ($thread): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
      <div>
        <div class="card-title"><?=icon('life-buoy', 16)?> <?=h((string)$thread['subject'])?></div>
        <div class="card-sub">
          <code><?=h((string)$thread['reference'])?></code> ·
          <?=h($topics[(string)$thread['topic']] ?? 'General')?> ·
          opened <?=h(date('d M Y, H:i', strtotime((string)$thread['created_at'])))?>
          <?php if (!empty($thread['order_ref'])): ?> · order <code><?=h((string)$thread['order_ref'])?></code><?php endif ?>
        </div>
      </div>
      <a class="btn btn-secondary btn-sm" href="/tickets.php">Close view</a>
    </div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <?php foreach ($messages as $msg): $mine = $msg['author'] === 'merchant'; ?>
        <div style="align-self:<?=$mine ? 'flex-end' : 'flex-start'?>;max-width:88%;background:<?=$mine ? 'var(--color-primary-soft)' : '#f8fafc'?>;border:1px solid var(--color-hairline);border-radius:var(--r-md);padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:var(--color-ink-mute);margin-bottom:5px">
            <?=$mine ? 'You' : 'Support'?> · <?=h(date('d M Y, H:i', strtotime((string)$msg['created_at'])))?>
          </div>
          <div style="font-size:13px;line-height:1.75;white-space:pre-wrap"><?=h((string)$msg['body'])?></div>
          <?php $msgFiles = array_values(array_filter($attachments, fn(array $a): bool => (int)($a['message_id'] ?? 0) === (int)$msg['id'])); ?>
          <?php if ($msgFiles): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
              <?php foreach ($msgFiles as $file): ?>
                <a class="btn btn-secondary btn-sm" href="/attachment.php?id=<?=(int)$file['id']?>">
                  <?=icon($file['mime'] === 'application/pdf' ? 'file-text' : 'image', 12)?>
                  <?=h((string)$file['original_name'])?>
                  <span class="form-hint" style="display:inline">(<?=h(tg_attachment_size((int)$file['bytes']))?>)</span>
                </a>
              <?php endforeach ?>
            </div>
          <?php elseif ((int)$msg['attachment_count'] > 0): ?>
            <div style="font-size:11px;color:var(--color-ink-mute);margin-top:6px"><?=icon('file-text', 11)?> <?=(int)$msg['attachment_count']?> attachment(s) referenced</div>
          <?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
    <?php if ($thread['status'] !== 'resolved'): ?>
      <form method="post" style="margin-top:18px;display:grid;gap:10px">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="ticket_id" value="<?=(int)$thread['id']?>">
        <textarea class="form-input" name="message" rows="3" required placeholder="Add more detail, or answer support's question"></textarea>
        <div style="display:flex;gap:10px">
          <button class="btn btn-primary btn-sm">Send reply</button>
          <button class="btn btn-secondary btn-sm" type="submit" name="action" value="close" onclick="return confirm('Mark this ticket resolved?')">Mark resolved</button>
        </div>
      </form>
    <?php endif ?>
  </div>
<?php endif ?>

<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:0 24px">
    <div class="filter-tabs">
      <?php foreach (['all' => 'All', 'open' => 'Open', 'in_progress' => 'In Progress', 'awaiting_reply' => 'Awaiting Reply', 'resolved' => 'Resolved'] as $key => $label): ?>
        <a class="filter-tab <?=$status === $key ? 'active' : ''?>" href="?status=<?=$key?>" style="text-decoration:none">
          <?=$label?><span class="count"><?=number_format((int)$counts[$key])?></span>
        </a>
      <?php endforeach ?>
    </div>
  </div>
  <div class="table-responsive" style="border-top:1px solid var(--color-hairline)">
    <table class="table">
      <thead><tr><th>Ticket</th><th>Subject</th><th>Topic</th><th>Last update</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
      <tbody>
      <?php if (!$tickets): ?>
        <tr><td colspan="6">
          <div class="empty-state">
            <div class="empty-state-icon"><?=icon('life-buoy', 22)?></div>
            <div class="empty-state-title">No Support Tickets Found</div>
            <div class="empty-state-sub">Missing customer payment, delayed bank UTR, or an API question? Open a ticket and we will help.</div>
            <a class="btn btn-primary btn-sm" href="#new-ticket" style="margin-top:14px"><?=icon('plus', 14)?> Open New Ticket</a>
          </div>
        </td></tr>
      <?php endif ?>
      <?php foreach ($tickets as $t): ?>
        <tr>
          <td><code style="font-size:12px;font-weight:600"><?=h((string)$t['reference'])?></code></td>
          <td><div style="font-weight:500"><?=h((string)$t['subject'])?></div></td>
          <td><?=h($topics[(string)$t['topic']] ?? 'General')?></td>
          <td style="white-space:nowrap"><?=h(tg_time_ago((string)($t['updated_at'] ?: $t['created_at'])))?></td>
          <td>
            <?php
            $badge = ['open' => 'pending', 'in_progress' => 'pending', 'awaiting_reply' => 'expired', 'resolved' => 'success'][(string)$t['status']] ?? 'expired';
            $label = ['open' => 'Open', 'in_progress' => 'In Progress', 'awaiting_reply' => 'Awaiting Reply', 'resolved' => 'Resolved'][(string)$t['status']] ?? ucfirst((string)$t['status']);
            ?>
            <span class="status-badge status-<?=$badge?>"><?=h($label)?></span>
          </td>
          <td style="text-align:right"><a class="btn btn-secondary btn-sm" href="?id=<?=(int)$t['id']?>">View</a></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" id="new-ticket" style="margin-top:20px">
  <div class="card-header">
    <div class="card-title"><?=icon('plus', 16)?> Open Support Ticket</div>
    <div class="card-sub">Include the order ID or bank UTR so we can trace the payment immediately.</div>
  </div>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create">
    <div class="form-group">
      <label class="form-label">Topic</label>
      <select class="form-input" name="topic">
        <?php foreach ($topics as $key => $label): ?>
          <option value="<?=$key?>"><?=h($label)?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Order ID or Bank UTR <span style="color:var(--color-ink-mute-2);font-weight:400">(optional)</span></label>
      <input class="form-input" name="order_ref" maxlength="64" placeholder="fg_DYCBZH5D or 428712345678">
    </div>
    <div class="form-group" style="grid-column:1/-1">
      <label class="form-label">Subject</label>
      <input class="form-input" name="subject" required minlength="4" maxlength="200" placeholder="Payment captured but order still shows Created">
    </div>
    <div class="form-group" style="grid-column:1/-1">
      <label class="form-label">Message</label>
      <textarea class="form-input" name="message" rows="5" required minlength="10" placeholder="What happened, what you expected, and any reference numbers."></textarea>
    </div>
    <div class="form-group" style="grid-column:1/-1">
      <label class="form-label" for="ticket-files">Attach Screenshots (Max 5)</label>
      <input class="form-input" id="ticket-files" type="file" name="attachments[]" multiple
             accept="image/jpeg,image/png,image/webp,application/pdf">
      <span class="form-hint">JPG, PNG, WEBP or PDF, up to 5 MB each, maximum 5 files.
        Files are stored outside the web root and served only to you.</span>
    </div>
    <?php if ($error !== '' && !$view): ?>
      <div class="form-group" style="grid-column:1/-1">
        <div class="callout callout-error"><?=icon('alert-circle', 15)?><span><?=h($error)?></span></div>
      </div>
    <?php endif ?>
    <!-- Without this the form was unreachable: every field could be filled in
         and there was no control that submitted it. -->
    <div class="form-actions" style="grid-column:1/-1;margin-top:4px">
      <button class="btn btn-primary" type="submit">
        <?=icon('plus', 15)?> Open ticket
      </button>
      <span class="form-hint" style="margin:0">A reference number is emailed to you immediately.</span>
    </div>
  </form>
</div>

<?php require __DIR__.'/includes/dashboard-footer.php'; ?>
