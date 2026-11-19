<?php
declare(strict_types=1);
/**
 * Dashboard — parity with the FamGateway merchant home:
 * greeting + MID chip, four stat cards, setup checklist, activity chart,
 * recent transactions table with the same seven columns.
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/auth.php';
$merchant = require_login();
$pageTitle = 'Dashboard';
$pdo = db();
$id = (int)$merchant['id'];

$stats = $pdo->prepare(
    "SELECT COUNT(*) total,
            COALESCE(SUM(CASE WHEN status='success' THEN 1 ELSE 0 END),0) success,
            COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) pending,
            COALESCE(SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END),0) expired,
            COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) revenue
       FROM orders WHERE merchant_id=?"
);
$stats->execute([$id]);
$stats = $stats->fetch();
$failed = (int)$stats['expired'];

$recentStmt = $pdo->prepare(
    'SELECT order_id, utr, customer_name, customer_phone, amount, status, created_at
       FROM orders WHERE merchant_id=? ORDER BY created_at DESC, id DESC LIMIT 10'
);
$recentStmt->execute([$id]);
$recent = $recentStmt->fetchAll();

$connected = tg_has_active_account($id);
$mid = tg_merchant_mid($id);

/* Activity series: orders per day for the last 7 days (chart is rendered as a
   CSS bar chart, so no canvas/JS dependency and no external chart library). */
$chart = $pdo->prepare(
    "SELECT substr(created_at,1,10) d, COUNT(*) n,
            COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) revenue
       FROM orders WHERE merchant_id=? AND created_at>=? GROUP BY substr(created_at,1,10)"
);
$chart->execute([$id, date('Y-m-d', strtotime('-6 days'))]);
$days = [];
foreach ($chart->fetchAll() as $row) $days[$row['d']] = $row;
$series = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $series[] = ['date' => $d, 'label' => date('D', strtotime($d)), 'n' => (int)($days[$d]['n'] ?? 0), 'revenue' => (float)($days[$d]['revenue'] ?? 0)];
}
$maxRequests = max(1, max(array_column($series, 'n')));
$configProblems = function_exists('tg_config_problems') ? tg_config_problems() : [];

require __DIR__.'/includes/dashboard-header.php';
?>

<div class="page-title-row dash-anim-right is-visible" style="margin-bottom:24px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:16px">
  <div>
    <h1 style="font-size:22px;font-weight:700;color:var(--color-ink);letter-spacing:-.5px">
      <?=h(tg_greeting())?>, <?=h(explode(' ', $merchant['name'])[0])?> 👋
    </h1>
    <p style="font-size:13px;color:var(--color-ink-mute);margin-top:4px">Here's what's happening with your API today.</p>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <div class="mid-badge-btn" data-copy="<?=h($mid)?>" title="Click to copy Merchant ID">
      <span style="color:var(--color-ink-mute);font-size:11px;font-weight:700;letter-spacing:.5px">MID:</span>
      <code><?=h($mid)?></code>
      <?=icon('copy', 13)?>
    </div>
    <?php if ($connected): ?>
      <span class="status-badge status-success"><?=icon('check-circle', 12)?> Receiving account active</span>
    <?php else: ?>
      <span class="status-badge status-pending" title="Add a receiving account to start verifying payments">
        <?=icon('alert-circle', 12)?> IMAP Not Configured
        <a href="/integrations.php" style="margin-left:8px;padding:2px 8px;background:#f59e0b;color:#fff;border-radius:4px;font-weight:600;font-size:11px;text-decoration:none">Configure</a>
      </span>
    <?php endif ?>
  </div>
</div>

<?php if ($configProblems): ?>
  <div class="callout callout-warning" style="margin-bottom:20px">
    <?=icon('alert-triangle', 16)?>
    <span>
      <strong>Finish setup before taking live payments.</strong>
      <?=h(implode(' ', $configProblems))?>
      <a href="/integrations.php" style="font-weight:600">Open integrations →</a>
    </span>
  </div>
<?php endif ?>

<div class="stat-grid dash-stagger is-visible">
  <div class="stat-card">
    <div class="stat-card-top">
      <div class="stat-card-label">Total Revenue</div>
      <div class="stat-card-icon green"><?=icon('trending-up', 18)?></div>
    </div>
    <div class="stat-card-value tnum" style="color:#10b981">₹<?=number_format((float)$stats['revenue'], 2)?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-top">
      <div class="stat-card-label">Total Requests</div>
      <div class="stat-card-icon blue"><?=icon('activity', 18)?></div>
    </div>
    <div class="stat-card-value tnum"><?=number_format((int)$stats['total'])?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-top">
      <div class="stat-card-label">Successful</div>
      <div class="stat-card-icon green"><?=icon('check-circle', 18)?></div>
    </div>
    <div class="stat-card-value tnum"><?=number_format((int)$stats['success'])?></div>
  </div>
  <div class="stat-card">
    <div class="stat-card-top">
      <div class="stat-card-label">Failed / Pending</div>
      <div class="stat-card-icon red"><?=icon('alert-circle', 18)?></div>
    </div>
    <div class="stat-card-value tnum"><?=number_format((int)$stats['pending'] + $failed)?></div>
  </div>
</div>

<!-- Quick setup -->
<div class="card dash-anim-up is-visible" style="margin-top:20px">
  <div class="card-header">
    <div class="card-title"><?=icon('zap', 16)?> <span>Quick Setup Guide</span></div>
    <div class="card-sub">Four steps from a fresh account to automated UPI collection.</div>
  </div>
  <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
    <?php
    $steps = [
        ['1', 'Connect a receiving account', $connected ? 'Account connected' : 'Add your UPI ID and receipt mailbox.', '/integrations.php', $connected],
        ['2', 'Create your API key', 'One key per integration; keep it server-side.', '/api-keys.php', false],
        ['3', 'Set a webhook endpoint', 'Signed HMAC callbacks on every capture.', '/webhooks.php', false],
        ['4', 'Read the API docs', 'Checkout URLs, polling and verification.', '/docs.php', false],
    ];
    foreach ($steps as [$num, $title, $sub, $href, $done]): ?>
      <a href="<?=$href?>" class="card" style="padding:18px;text-decoration:none;box-shadow:none;border-color:var(--color-hairline)">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
          <span style="width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;background:<?=$done ? '#dcfce7' : 'var(--color-primary-soft)'?>;color:<?=$done ? '#166534' : 'var(--color-primary-deep)'?>">
            <?=$done ? icon('check', 12) : h($num)?>
          </span>
          <strong style="font-size:13px"><?=h($title)?></strong>
        </div>
        <p style="font-size:12px;color:var(--color-ink-mute);line-height:1.7;margin:0"><?=h($sub)?></p>
      </a>
    <?php endforeach ?>
  </div>
</div>

<!-- Activity chart -->
<div class="card dash-anim-up is-visible" style="margin-top:20px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <div class="card-title"><?=icon('activity', 16)?> Activity — Last 7 Days</div>
      <div class="card-sub">API requests and revenue over time</div>
    </div>
    <div class="chart-time-pills">
      <button class="time-pill active" type="button">7D</button>
      <button class="time-pill" type="button" disabled title="Available once longer history exists">15D</button>
      <button class="time-pill" type="button" disabled title="Available once longer history exists">30D</button>
    </div>
  </div>
  <div class="dash-bars" role="img" aria-label="Orders per day for the last seven days">
    <?php foreach ($series as $day): ?>
      <div class="dash-bar-col" title="<?=h($day['label'].' '.$day['date'])?> — <?=$day['n']?> orders, ₹<?=number_format($day['revenue'], 2)?>">
        <span class="dash-bar-tip"><?=$day['n']?></span>
        <div class="dash-bar" style="height:<?=$day['n'] === 0 ? 4 : max(8, (int)round(($day['n'] / $maxRequests) * 100))?>%"></div>
        <span class="dash-bar-label"><?=h($day['label'])?></span>
      </div>
    <?php endforeach ?>
  </div>
</div>

<!-- Recent transactions -->
<div class="card dash-anim-up is-visible" style="padding:0;overflow:hidden;margin-top:20px">
  <div style="padding:22px 28px;border-bottom:1px solid var(--color-hairline);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <div class="card-title"><?=icon('receipt', 16)?> Recent Transactions</div>
      <div class="card-sub">Your last 10 orders</div>
    </div>
    <a href="/transactions.php" class="btn btn-secondary btn-sm"><?=icon('arrow-right', 14)?> View All</a>
  </div>
  <div class="table-responsive">
    <table>
      <thead>
        <tr>
          <th>Order ID</th><th>Bank RRN</th><th>Customer detail</th><th>Created on</th>
          <th>Amount</th><th>Status</th><th style="text-align:right">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="7">
          <div class="empty-state">
            <div class="empty-state-icon"><?=icon('receipt', 22)?></div>
            <div class="empty-state-title">No transactions yet</div>
            <div class="empty-state-sub">Create your first payment link to get started</div>
            <a href="/payment-links.php" class="btn btn-primary btn-sm" style="margin-top:14px"><?=icon('plus', 14)?> New payment link</a>
          </div>
        </td></tr>
      <?php endif ?>
      <?php foreach ($recent as $o): ?>
        <tr>
          <td><code style="font-size:12px;font-weight:600"><?=h($o['order_id'])?></code></td>
          <td><?=h($o['utr'] ?: '—')?></td>
          <td>
            <div style="font-weight:500"><?=h($o['customer_name'] ?: 'Customer')?></div>
            <?php if (!empty($o['customer_phone'])): ?><div style="font-size:11px;color:var(--color-ink-mute)"><?=h($o['customer_phone'])?></div><?php endif ?>
          </td>
          <td style="white-space:nowrap"><?=h(date('d M Y, H:i', strtotime((string)$o['created_at'])))?></td>
          <td class="tnum" style="font-weight:600">₹<?=number_format((float)$o['amount'], 2)?></td>
          <td><?=tg_status_badge((string)$o['status'])?></td>
          <td style="text-align:right">
            <a href="/invoice.php?order_id=<?=h(rawurlencode((string)$o['order_id']))?>" class="btn btn-secondary btn-sm">View</a>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__.'/includes/dashboard-footer.php'; ?>
