<?php
declare(strict_types=1);
/**
 * Addon subscriptions and the invoices raised for them.
 *
 * Operators can mark an invoice paid (the money arrives out of band, since this
 * deployment ships without a card processor) and cancel a subscription. Neither
 * action pretends to move money through a gateway.
 */
require_once __DIR__.'/../includes/admin-shell.php';

$pdo = db();
$me = tg_admin_require();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tg_admin_verify_csrf();
    if (!tg_admin_can('manage_billing')) {
        $error = 'Your role cannot change billing.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'mark_paid') {
                $invoiceId = (int)($_POST['invoice_id'] ?? 0);
                $pdo->prepare("UPDATE invoices SET status='paid',paid_at=? WHERE id=?")->execute([db_now(), $invoiceId]);
                tg_audit(null, 'admin.invoice_paid', '#'.$invoiceId);
                $notice = 'Invoice marked paid. Record the actual payment reference outside this panel.';
            } elseif ($action === 'cancel_sub') {
                $subId = (int)($_POST['subscription_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT merchant_id,addon_key FROM merchant_addons WHERE id=?');
                $stmt->execute([$subId]);
                $sub = $stmt->fetch();
                if ($sub) {
                    $pdo->prepare("UPDATE merchant_addons SET status='cancelled',expires_at=? WHERE id=?")->execute([db_now(), $subId]);
                    tg_audit((int)$sub['merchant_id'], 'admin.addon_cancelled', (string)$sub['addon_key']);
                    $notice = 'Subscription cancelled.';
                }
            }
        } catch (Throwable $e) {
            $error = 'That change could not be saved.';
            error_log('admin addons: '.$e->getMessage());
        }
    }
}

$subs = [];
$invoices = [];
try {
    $subs = $pdo->query(
        "SELECT s.*, m.name merchant, m.email merchant_email
         FROM merchant_addons s LEFT JOIN merchants m ON m.id=s.merchant_id
         ORDER BY s.id DESC LIMIT 200"
    )->fetchAll();
} catch (Throwable $e) { error_log('admin subs: '.$e->getMessage()); }

try {
    $invoices = $pdo->query(
        "SELECT i.*, m.name merchant, m.email merchant_email
         FROM invoices i LEFT JOIN merchants m ON m.id=i.merchant_id
         ORDER BY i.id DESC LIMIT 200"
    )->fetchAll();
} catch (Throwable $e) { error_log('admin invoices: '.$e->getMessage()); }

$mrr = 0.0;
foreach ($subs as $s) {
    if (($s['status'] ?? '') === 'active' && (float)$s['price'] > 0) $mrr += (float)$s['price'] / max(1, (int)$s['months']);
}
$unpaid = 0.0;
foreach ($invoices as $i) if ($i['status'] === 'unpaid') $unpaid += (float)$i['amount'] + (float)$i['tax_amount'];

admin_header('Addons & Billing', 'addons');
admin_flash($notice, $error);
?>

<div class="admin-stats">
  <div class="admin-stat">
    <div class="admin-stat-label">Active subscriptions</div>
    <div class="admin-stat-value"><?=number_format(count(array_filter($subs, fn($s) => $s['status'] === 'active')))?></div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Approx. MRR</div>
    <div class="admin-stat-value pos">₹<?=number_format($mrr, 2)?></div>
    <div class="admin-stat-foot">Active addons, normalised to one month</div>
  </div>
  <div class="admin-stat">
    <div class="admin-stat-label">Outstanding</div>
    <div class="admin-stat-value<?=$unpaid > 0 ? ' warn' : ''?>">₹<?=number_format($unpaid, 2)?></div>
    <div class="admin-stat-foot"><?=count(array_filter($invoices, fn($i) => $i['status'] === 'unpaid'))?> unpaid invoices</div>
  </div>
</div>

<div class="admin-panel">
  <div class="admin-panel-head"><h2>Subscriptions</h2></div>
  <div class="admin-table-wrap">
    <?php if (!$subs): ?>
      <div class="admin-empty">No addon subscriptions yet.</div>
    <?php else: ?>
      <table class="admin-table">
        <thead><tr><th>Merchant</th><th>Addon</th><th>Term</th><th class="num">Price</th><th>Status</th><th>Expires</th><th style="text-align:right">Action</th></tr></thead>
        <tbody>
        <?php foreach ($subs as $s): ?>
          <tr>
            <td>
              <div style="font-weight:500"><?=h((string)($s['merchant'] ?? '—'))?></div>
              <div class="muted" style="font-size:11px"><?=h((string)($s['merchant_email'] ?? ''))?></div>
            </td>
            <td><code style="font-size:11px"><?=h((string)$s['addon_key'])?></code></td>
            <td class="muted"><?=(int)$s['months']?> mo</td>
            <td class="num">₹<?=number_format((float)$s['price'], 2)?></td>
            <td><span class="admin-pill <?=$s['status'] === 'active' ? 'admin-pill-ok' : 'admin-pill-neutral'?>"><?=h(ucfirst((string)$s['status']))?></span></td>
            <td class="muted" style="font-size:11px"><?=h(substr((string)($s['expires_at'] ?? ''), 0, 10))?></td>
            <td style="text-align:right">
              <?php if ($s['status'] === 'active'): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Cancel this subscription?')">
                  <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
                  <input type="hidden" name="action" value="cancel_sub">
                  <input type="hidden" name="subscription_id" value="<?=(int)$s['id']?>">
                  <button class="admin-btn admin-btn-danger admin-btn-sm"<?=tg_admin_can('manage_billing') ? '' : ' disabled'?>>Cancel</button>
                </form>
              <?php else: ?><span class="muted" style="font-size:11px">—</span><?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>
</div>

<div class="admin-panel">
  <div class="admin-panel-head"><h2>Invoices</h2></div>
  <div class="admin-table-wrap">
    <?php if (!$invoices): ?>
      <div class="admin-empty">No invoices raised yet.</div>
    <?php else: ?>
      <table class="admin-table">
        <thead><tr><th>Invoice</th><th>Merchant</th><th>Description</th><th class="num">Total</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $i): ?>
          <?php
            $total = (float)$i['amount'] + (float)$i['tax_amount'];
            $pill = match ((string)$i['status']) {
                'paid' => 'admin-pill-ok', 'unpaid' => 'admin-pill-warn', default => 'admin-pill-neutral',
            };
          ?>
          <tr>
            <td><code style="font-size:11px"><?=h((string)$i['invoice_no'])?></code></td>
            <td>
              <div style="font-weight:500"><?=h((string)($i['merchant'] ?? '—'))?></div>
              <div class="muted" style="font-size:11px"><?=h((string)($i['merchant_email'] ?? ''))?></div>
            </td>
            <td class="muted" style="font-size:11.5px"><?=h((string)$i['description'])?></td>
            <td class="num">₹<?=number_format($total, 2)?></td>
            <td><span class="admin-pill <?=$pill?>"><?=h(ucfirst((string)$i['status']))?></span></td>
            <td style="text-align:right">
              <?php if ($i['status'] !== 'paid'): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
                  <input type="hidden" name="action" value="mark_paid">
                  <input type="hidden" name="invoice_id" value="<?=(int)$i['id']?>">
                  <button class="admin-btn admin-btn-ghost admin-btn-sm"<?=tg_admin_can('manage_billing') ? '' : ' disabled'?>>Mark paid</button>
                </form>
              <?php else: ?><span class="muted" style="font-size:11px"><?=h(substr((string)($i['paid_at'] ?? ''), 0, 10))?></span><?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>
</div>

<?php admin_footer(); ?>
