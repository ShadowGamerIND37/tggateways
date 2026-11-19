<?php
declare(strict_types=1);
/**
 * Admin sign-in gate.
 *
 * Rate limited on the same table as the merchant login so an attacker cannot
 * get a fresh budget by switching between the two forms. No "user not found"
 * message: a wrong address and a wrong password produce identical text.
 */
require_once __DIR__.'/../includes/admin.php';
require_once __DIR__.'/../includes/icons.php';

if (tg_admin_current()) redirect_to('/admin/index.php');

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('admin_login', 10, 300);
    $email = trim((string)($_POST['email'] ?? ''));
    $error = (string)tg_admin_login($email, (string)($_POST['password'] ?? '')) ;
    if ($error === '') {
        $next = (string)($_POST['next'] ?? '');
        // Only ever bounce to a path inside /admin, so a crafted `next` cannot
        // turn this form into an open redirect.
        if (!preg_match('#^/admin/[A-Za-z0-9_\-./?=&%]*$#', $next)) $next = '/admin/index.php';
        redirect_to($next);
    }
}
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('#^/admin/[A-Za-z0-9_\-./?=&%]*$#', $next)) $next = '/admin/index.php';
$seeded = false;
try { $seeded = tg_table_exists('admins') && (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0; } catch (Throwable) {}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Administrator sign-in · TG Gateways</title>
<link rel="icon" href="/assets/img/favicon.png" type="image/png">
<link rel="stylesheet" href="/assets/css/design-system.css">
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<div class="admin-gate">
  <div class="admin-gate-card">
    <div class="admin-gate-brand">
      <img src="/assets/img/tg-mark.png" alt="TG Gateways">
      <h1>Administrator sign-in</h1>
      <p>Restricted console for deployment operators.</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="callout callout-error" style="margin-bottom:18px"><?=icon('alert-circle', 16)?><span><?=h($error)?></span></div>
    <?php endif ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="next" value="<?=h($next)?>">
      <div class="admin-field">
        <label for="email">Administrator username or email</label>
        <input class="admin-input" id="email" name="email" type="text" required autofocus
               autocomplete="username" value="<?=h($email)?>" placeholder="admin">
      </div>
      <div class="admin-field">
        <label for="password">Password</label>
        <input class="admin-input" id="password" name="password" type="password" required
               autocomplete="current-password" placeholder="••••••••••••">
      </div>
      <button class="admin-btn admin-btn-primary"><?=icon('lock', 15)?> Sign in to admin</button>
    </form>

    <?php if (!$seeded): ?>
      <div class="admin-gate-note">
        No administrator exists yet. One is created automatically on first run.
      </div>
    <?php else: ?>
      <div class="admin-gate-note">
        First run seeds <code>admin</code> / <code>admin</code>. Change that password immediately
        from <strong>Administrators</strong> after signing in — the panel keeps reminding you until you do.
      </div>
    <?php endif ?>

    <p style="text-align:center;margin:18px 0 0;font-size:11.5px">
      <a href="/login.php" style="color:var(--color-ink-mute);text-decoration:none">← Merchant sign-in</a>
    </p>
  </div>
</div>
</body>
</html>
