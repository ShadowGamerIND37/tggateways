<?php
declare(strict_types=1);
/**
 * Merchant dashboard chrome: sidebar + mobile header + main region.
 *
 * The navigation mirrors the FamGateway merchant panel one-for-one (same
 * items, same icons, same grouping) so a merchant moving between the two
 * products finds everything where they expect it.
 *
 * Usage from a page:
 *   $pageTitle = 'Dashboard';
 *   require __DIR__.'/includes/dashboard-header.php';   // opens .main-content
 *   ... page markup ...
 *   require __DIR__.'/includes/dashboard-footer.php';   // closes it
 */
require_once __DIR__.'/auth.php';
require_once __DIR__.'/icons.php';
require_once __DIR__.'/merchant.php';

$merchant = require_login();
$active = basename($_SERVER['SCRIPT_NAME']);
$mid = tg_merchant_mid((int)$merchant['id']);
$avatar = tg_merchant_avatar((int)$merchant['id']);

/** Sidebar model: [label, path, icon]. Grouped exactly like the reference. */
$navMain = [
    ['Dashboard',     '/dashboard.php',      'layout-dashboard'],
    ['Transactions',  '/transactions.php',   'receipt'],
    ['Payment Links', '/payment-links.php',  'link-2'],
    ['API Keys',      '/api-keys.php',       'key-round'],
    ['Webhooks',      '/webhooks.php',       'webhook'],
    ['Settings',      '/settings.php',       'settings'],
    ['Integrations',  '/integrations.php',   'plug'],
    ['Multi-Accounts','/multi-accounts.php', 'layers'],
];
$navCommerce = [
    ['Addons',        '/addons.php',    'puzzle',     'PRO'],
    ['My Services',   '/services.php',  'shopping-bag'],
    ['Invoices',      '/invoices.php',  'file-text'],
];
$navAccount = [
    ['Profile',       '/profile.php',   'user'],
    ['Support Tickets','/tickets.php',  'life-buoy'],
];
$navResources = [
    ['Documentation', '/docs.php',      'book-open'],
    ['System Status', '/status.php',    'activity'],
];

/**
 * Render one sidebar link. Marks it active when it is the current script, so
 * every page in the panel gets correct highlighting without extra wiring.
 */
function tg_nav_item(array $item, string $active): string {
    [$label, $path] = $item;
    $icon = $item[2] ?? 'activity';
    $badge = $item[3] ?? null;
    $isActive = basename($path) === $active;
    $out = '<a href="'.$path.'" class="sidebar-item'.($isActive ? ' active' : '').'">'
        .icon($icon, 16).' <span>'.h($label).'</span>';
    if ($badge !== null) {
        $out .= '<span class="sidebar-item-badge">'.h($badge).'</span>';
    }
    return $out.'</a>';
}

$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($pageTitle)?> — TG Gateways</title>
<link rel="stylesheet" href="/assets/css/design-system.css">
<link rel="icon" href="/assets/img/tg-mark.png" type="image/png">
<script defer src="/assets/js/main.js"></script>
</head>
<body>
<div class="dashboard-layout">

  <!-- Mobile top bar -->
  <div class="mobile-header">
    <div class="mobile-brand">
      <a href="/dashboard.php"><img src="/assets/img/tg-mark.png" alt="TG Gateways" style="height:28px;width:28px;display:block"></a>
      <span style="font-weight:700;color:#f1f5f9;font-size:15px;margin-left:10px">TG Gateways</span>
    </div>
    <button class="mobile-menu-btn" onclick="openSidebar()" aria-label="Open navigation"><?=icon('menu',22)?></button>
  </div>

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div class="sidebar-brand">
          <a href="/dashboard.php" class="sidebar-brand-icon" style="display:flex;align-items:center;gap:10px;color:#f1f5f9;font-weight:700;font-size:15px">
            <img src="/assets/img/tg-mark.png" alt="" style="height:28px;width:28px;display:block">
            TG Gateways
          </a>
        </div>
        <button class="mobile-menu-btn" onclick="closeSidebar()" style="display:none" id="closeSidebarBtn" aria-label="Close navigation"><?=icon('x',20)?></button>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="sidebar-section-label">Main</div>
      <?php foreach ($navMain as $_item) echo tg_nav_item($_item, $active); ?>

      <div class="sidebar-section-label">Commerce</div>
      <?php foreach ($navCommerce as $_item) echo tg_nav_item($_item, $active); ?>

      <div class="sidebar-section-label">Account</div>
      <?php foreach ($navAccount as $_item) echo tg_nav_item($_item, $active); ?>

      <div class="sidebar-section-label">Resources</div>
      <?php foreach ($navResources as $_item) echo tg_nav_item($_item, $active); ?>
    </nav>

    <div class="sidebar-footer">
      <a href="/profile.php" class="sidebar-user-card" title="View profile">
        <?php if ($avatar !== ''): ?>
          <img src="<?=h($avatar)?>" class="sidebar-avatar" alt="">
        <?php else: ?>
          <span class="sidebar-avatar" style="display:flex;align-items:center;justify-content:center;font-weight:700;color:#cbd5e1"><?=h(strtoupper(substr($merchant['name'], 0, 1)))?></span>
        <?php endif ?>
        <div style="overflow:hidden;min-width:0;flex:1">
          <div class="sidebar-user-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($merchant['name'])?></div>
        </div>
      </a>
      <div class="sidebar-mid-pill" onclick="copyNavMid('<?=h($mid)?>', this)" title="Click to copy Merchant ID">
        <span class="sidebar-mid-label">
          <span class="sidebar-mid-dot"></span>
          MID: <code><?=h($mid)?></code>
        </span>
        <span class="sidebar-mid-copy-btn"><?=icon('copy',12)?></span>
      </div>
      <a href="/logout.php" class="sidebar-logout"><?=icon('log-out',15)?> Sign Out</a>
    </div>
  </aside>
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

  <main class="main-content">
    <div class="wrap">
