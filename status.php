<?php
declare(strict_types=1);
/**
 * Public System Status & Service Uptime.
 *
 * Works signed in (inside the panel) and signed out (public resource), so
 * customers can check the platform before opening a support ticket.
 */
require_once __DIR__.'/includes/auth.php';   // session, h(), current_merchant()
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/status.php';
require_once __DIR__.'/includes/icons.php';

$probe = tg_status_probe();
$history = tg_status_history(30);
$states = array_column($probe, 'state');
$overall = in_array('down', $states, true) ? 'down' : (in_array('degraded', $states, true) ? 'degraded' : 'operational');
$overallUptime = tg_status_uptime(array_merge(...array_values($history)));
$labels = ['operational' => 'Operational', 'degraded' => 'Degraded', 'down' => 'Outage'];
$merchant = function_exists('current_merchant') ? current_merchant() : null;

if ($merchant) {
    $pageTitle = 'System Status';
    require __DIR__.'/includes/dashboard-header.php';
} else {
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>System Status &amp; Service Uptime — TG Gateways</title>
<link rel="stylesheet" href="/assets/css/design-system.css">
<link rel="stylesheet" href="/assets/css/marketing.css">
<link rel="icon" href="/assets/img/tg-mark.png" type="image/png">
</head>
<body>
<header class="container topnav">
  <a class="brand" href="/"><img src="/assets/img/tg-mark.png" alt=""><span>TG Gateways</span></a>
  <nav class="navlinks"><a href="/">Home</a><a href="/docs.php">Docs</a><a href="/status.php">Status</a><a href="/login.php">Sign in</a></nav>
</header>
<main class="container" style="padding:28px 0 60px;max-width:900px">
<?php
}
?>

<div class="page-title-row" style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-size:22px;font-weight:700;letter-spacing:-.5px;margin:0">System Status</h1>
    <p style="font-size:13px;color:var(--color-ink-mute);margin:4px 0 0">Live checks on the API, database, checkout, webhooks and receipt parser.</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <span class="status-badge status-<?=$overall === 'operational' ? 'success' : ($overall === 'degraded' ? 'pending' : 'failed')?>" style="font-size:12px;padding:6px 12px">
      <?=icon($overall === 'operational' ? 'check-circle' : 'alert-triangle', 13)?>
      <?=h($labels[$overall])?>
    </span>
    <?php if ($overallUptime !== null): ?>
      <span style="font-size:12px;color:var(--color-ink-mute)"><?=number_format($overallUptime, 2)?>% up · 30 days</span>
    <?php endif ?>
  </div>
</div>

<?php if ($overall !== 'operational'): ?>
  <div class="callout callout-warning" style="margin-bottom:18px">
    <?=icon('alert-triangle', 16)?>
    <span>One or more components are not fully operational. Captured payments are still recorded;
      retried callbacks continue automatically.</span>
  </div>
<?php endif ?>

<div class="card" style="padding:0;overflow:hidden">
  <?php foreach ($probe as $component => $info): ?>
    <?php
    $label = tg_status_components()[$component] ?? ucfirst($component);
    $tone = ['operational' => 'success', 'degraded' => 'pending', 'down' => 'failed'][$info['state']] ?? 'expired';
    $days = $history[$component] ?? [];
    ?>
    <div style="padding:18px 24px;border-bottom:1px solid var(--color-hairline)">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
        <div>
          <div style="font-size:14px;font-weight:600;color:var(--color-ink)"><?=h($label)?></div>
          <div style="font-size:12px;color:var(--color-ink-mute);margin-top:2px"><?=h($info['detail'])?></div>
        </div>
        <span class="status-badge status-<?=$tone?>">
          <?=icon($info['state'] === 'operational' ? 'check' : 'alert-circle', 12)?>
          <?=h($labels[$info['state']] ?? ucfirst($info['state']))?>
        </span>
      </div>
      <div style="display:flex;gap:3px;margin-top:12px" role="img" aria-label="30-day uptime for <?=h($label)?>">
        <?php foreach ($days as $day):
            $u = $day['uptime'];
            $color = $u === null ? '#e2e8f0' : ($u >= 99.5 ? '#22c55e' : ($u >= 95 ? '#f59e0b' : '#ef4444'));
        ?>
          <span title="<?=h($day['day'])?> — <?=$u === null ? 'no data' : number_format($u, 2).'% uptime'?>"
                style="flex:1;height:26px;border-radius:3px;background:<?=$color?>"></span>
        <?php endforeach ?>
      </div>
    </div>
  <?php endforeach ?>
  <div style="padding:14px 24px;font-size:11.5px;color:var(--color-ink-mute-2);display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <span>Checked <?=h(date('d M Y, H:i:s'))?> · last 30 days</span>
    <span>Grey bars are days with no recorded checks.</span>
  </div>
</div>

<div class="callout callout-info" style="margin-top:20px">
  <?=icon('activity', 16)?>
  <span>Machine-readable health is at <a href="/api/health.php" style="font-weight:600">/api/health.php</a> —
    point your own monitors at it. Incident history is not published on this deployment yet.</span>
</div>

<?php
if ($merchant) {
    require __DIR__.'/includes/dashboard-footer.php';
} else {
    ?>
</main>
<footer class="footer"><div class="container footer-inner">
  <span>© <?=date('Y')?> TG Gateways · Self-hosted UPI payment gateway</span>
  <span><a href="/docs.php">Documentation</a> · <a href="/privacy.php">Privacy</a></span>
</div></footer>
</body>
</html>
<?php
}
