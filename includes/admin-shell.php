<?php
declare(strict_types=1);
/**
 * Admin panel chrome.
 *
 * Deliberately separate from the merchant dashboard header: an operator is not
 * a merchant, and the two sidebars must not share state. Uses the same design
 * system so the product still feels like one application.
 *
 * Usage:
 *   require_once __DIR__.'/../includes/admin-shell.php';
 *   admin_header('Overview', 'overview');   // pass the nav key, not a filename
 *   ... page ...
 *   admin_footer();
 */
require_once __DIR__.'/admin.php';
require_once __DIR__.'/icons.php';

/** Sidebar model: [key, label, href, icon]. */
function tg_admin_nav(): array {
    return [
        ['overview',  'Overview',       '/admin/index.php',      'layout-dashboard'],
        ['merchants', 'Merchants',      '/admin/merchants.php',  'users'],
        ['orders',    'Orders',         '/admin/orders.php',     'receipt'],
        ['addons',    'Addons & Billing','/admin/addons.php',    'puzzle'],
        ['tickets',   'Support',        '/admin/tickets.php',    'life-buoy'],
        ['audit',     'Audit log',      '/admin/audit.php',      'shield'],
        ['admins',    'Administrators', '/admin/admins.php',     'key-round'],
        ['settings',  'Deployment',     '/admin/settings.php',   'settings'],
    ];
}

function admin_header(string $title, string $activeKey): void {
    $admin = tg_admin_require();
    $mustRotate = (int)($admin['must_change_password'] ?? 0) === 1;
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> · Admin · TG Gateways</title>
<link rel="icon" href="/assets/img/favicon.png" type="image/png">
<link rel="stylesheet" href="/assets/css/design-system.css">
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-shell">
  <aside class="admin-sidebar">
    <a class="admin-brand" href="/admin/index.php">
      <img src="/assets/img/tg-mark.png" alt="">
      <span><strong>TG Gateways</strong><small>Administration</small></span>
    </a>
    <nav class="admin-nav">
      <?php foreach (tg_admin_nav() as [$key, $label, $href, $icon]): ?>
        <a href="<?=h($href)?>" class="admin-nav-item<?=$key === $activeKey ? ' active' : ''?>">
          <?=icon($icon, 16)?><span><?=h($label)?></span>
        </a>
      <?php endforeach ?>
    </nav>
    <div class="admin-sidebar-foot">
      <a href="/dashboard.php" class="admin-nav-item"><?=icon('external-link', 15)?><span>Merchant view</span></a>
      <a href="/admin/logout.php" class="admin-nav-item"><?=icon('log-out', 15)?><span>Sign out</span></a>
    </div>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <div class="admin-topbar-title">
        <h1><?=h($title)?></h1>
      </div>
      <div class="admin-topbar-user">
        <span class="admin-role admin-role-<?=h((string)$admin['role'])?>"><?=h(ucfirst((string)$admin['role']))?></span>
        <span class="admin-who"><?=h((string)$admin['name'])?></span>
      </div>
    </header>
    <?php if ($mustRotate): ?>
      <div class="admin-banner admin-banner-warn">
        <?=icon('alert-circle', 16)?>
        <span>You are signed in with the default <code>admin</code>/<code>admin</code> credentials.
          <a href="/admin/admins.php#password">Change the password</a> before this deployment takes real traffic.</span>
      </div>
    <?php endif ?>
    <main class="admin-content">
<?php
}

function admin_footer(): void {
    ?></main>
  </div>
</div>
</body>
</html><?php
}

/** Small helper so pages do not repeat the notice/error markup. */
function admin_flash(string $notice, string $error): void {
    if ($notice !== '') echo '<div class="callout callout-success" style="margin-bottom:18px">'.icon('check-circle', 16).'<span>'.h($notice).'</span></div>';
    if ($error !== '') echo '<div class="callout callout-error" style="margin-bottom:18px">'.icon('alert-circle', 16).'<span>'.h($error).'</span></div>';
}
