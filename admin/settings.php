<?php
declare(strict_types=1);
/**
 * Deployment health and configuration.
 *
 * Read-only by design. Anything that changes runtime behaviour here lives in
 * `.env` and requires a container restart, so offering an editable box would be
 * a lie: the change would silently do nothing until the operator restarted the
 * service, and they would have no way to know. This page instead reports what is
 * set, what is missing, and what each missing piece breaks.
 */
require_once __DIR__.'/../includes/admin-shell.php';

$pdo = db();
tg_admin_require();

/** A check is [label, ok|warn|fail, detail]. */
$checks = [];

$smtpHost = (string)envv('SMTP_HOST', '');
$checks[] = [
    'Outbound email (SMTP)',
    $smtpHost !== '' ? 'ok' : 'warn',
    $smtpHost !== ''
        ? 'Configured via '.$smtpHost.' — 2FA codes and support replies are delivered.'
        : 'SMTP_HOST is empty. Two-factor codes and ticket replies cannot be emailed, so the 2FA step is skipped entirely.',
];

$mailFrom = (string)envv('MAIL_FROM', '');
$checks[] = [
    'Sender address',
    $mailFrom !== '' ? 'ok' : 'warn',
    $mailFrom !== '' ? $mailFrom : 'MAIL_FROM is empty; mail falls back to a local address most providers reject.',
];

$certValidation = filter_var(envv('IMAP_VALIDATE_CERT', 'true'), FILTER_VALIDATE_BOOLEAN);
$checks[] = [
    'IMAP certificate validation',
    $certValidation ? 'ok' : 'fail',
    $certValidation
        ? 'Server certificates are verified on every mailbox connection.'
        : 'IMAP_VALIDATE_CERT is off. Receipts are fetched over an unverified connection — anyone on the path could read the app password.',
];

$sessionSecure = filter_var(envv('SESSION_SECURE', 'true'), FILTER_VALIDATE_BOOLEAN);
$checks[] = [
    'Session cookie Secure flag',
    $sessionSecure ? 'ok' : 'warn',
    $sessionSecure
        ? 'Cookies are only sent over HTTPS.'
        : 'SESSION_SECURE is off, so session cookies travel over plain HTTP. Only correct for local testing.',
];

$imapTimeout = (int)envv('IMAP_TIMEOUT', '10');
$checks[] = [
    'IMAP timeout',
    $imapTimeout >= 5 && $imapTimeout <= 60 ? 'ok' : 'warn',
    $imapTimeout.' seconds per mailbox operation.',
];

$appKey = (string)envv('APP_ENCRYPTION_KEY', '');
$checks[] = [
    'Encryption key',
    strlen($appKey) === 64 ? 'ok' : 'fail',
    strlen($appKey) === 64
        ? 'App passwords are encrypted at rest with this key.'
        : 'APP_ENCRYPTION_KEY is missing or the wrong length. Stored app passwords cannot be decrypted, so no receipts can be read.',
];

$driver = db_driver();
$checks[] = [
    'Database driver',
    $driver === 'mysql' ? 'ok' : 'warn',
    $driver === 'mysql'
        ? 'MySQL — the production driver.'
        : 'SQLite. Fine for a single-instance trial; MySQL handles concurrent workers correctly.',
];

$workerSeen = null;
try {
    $workerSeen = $pdo->query("SELECT MAX(last_checked_at) FROM merchant_accounts")->fetchColumn();
} catch (Throwable $e) { /* table shape may differ */ }
$stale = $workerSeen === null || $workerSeen === false
    || strtotime((string)$workerSeen) < time() - 900;
$checks[] = [
    'Receipt worker',
    $stale ? 'warn' : 'ok',
    $workerSeen
        ? 'Last mailbox poll at '.substr((string)$workerSeen, 0, 16).' UTC.'
        : 'No mailbox has ever been polled. Confirm the worker container is running (see the README cron section).',
];

$env = [
    'DB_DRIVER' => $driver,
    'APP_URL' => (string)envv('APP_URL', ''),
    'APP_ENCRYPTION_KEY' => strlen($appKey) === 64 ? 'set (64 hex characters)' : 'missing',
    'SMTP_HOST' => $smtpHost !== '' ? $smtpHost : 'not set',
    'SMTP_PORT' => (string)envv('SMTP_PORT', '587'),
    'MAIL_FROM' => $mailFrom !== '' ? $mailFrom : 'not set',
    'IMAP_TIMEOUT' => (string)$imapTimeout,
    'IMAP_VALIDATE_CERT' => $certValidation ? 'true' : 'false',
    'SESSION_SECURE' => $sessionSecure ? 'true' : 'false',
];

$phpVersion = PHP_VERSION;
$exts = ['pdo_mysql', 'pdo_sqlite', 'openssl', 'mbstring', 'curl'];
$missing = array_values(array_filter($exts, fn($e) => !extension_loaded($e)));

admin_header('Deployment', 'settings');
?>

<div class="admin-panel">
  <div class="admin-panel-head"><h2>Preflight checks</h2></div>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Check</th><th>State</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach ($checks as [$label, $state, $detail]): ?>
        <?php
          $pill = match ($state) {
              'ok' => 'admin-pill-ok', 'warn' => 'admin-pill-warn', default => 'admin-pill-bad',
          };
          $word = match ($state) { 'ok' => 'OK', 'warn' => 'Attention', default => 'Failing' };
        ?>
        <tr>
          <td style="font-weight:600;white-space:nowrap"><?=h($label)?></td>
          <td><span class="admin-pill <?=$pill?>"><?=$word?></span></td>
          <td class="muted" style="font-size:11.5px;line-height:1.7"><?=h($detail)?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<div class="admin-panel">
  <div class="admin-panel-head">
    <h2>Environment</h2>
    <span class="muted" style="font-size:11px">Read-only — edit <code>.env</code> and restart the service</span>
  </div>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <tbody>
      <?php foreach ($env as $key => $value): ?>
        <tr>
          <td style="font-weight:600;width:230px"><code style="font-size:11.5px"><?=h($key)?></code></td>
          <td class="muted"><?=h($value)?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<div class="admin-panel">
  <div class="admin-panel-head"><h2>Runtime</h2></div>
  <div class="admin-panel-body">
    <div class="admin-stats" style="margin:0">
      <div class="admin-stat">
        <div class="admin-stat-label">PHP</div>
        <div class="admin-stat-value" style="font-size:19px"><?=h($phpVersion)?></div>
      </div>
      <div class="admin-stat">
        <div class="admin-stat-label">Extensions</div>
        <div class="admin-stat-value <?=$missing ? 'neg' : 'pos'?>" style="font-size:19px">
          <?=$missing ? count($missing).' missing' : 'All present'?>
        </div>
        <?php if ($missing): ?>
          <div class="admin-stat-foot"><?=h(implode(', ', $missing))?></div>
        <?php endif ?>
      </div>
      <div class="admin-stat">
        <div class="admin-stat-label">Server time</div>
        <div class="admin-stat-value" style="font-size:19px"><?=h(date('H:i'))?></div>
        <div class="admin-stat-foot"><?=h(date('D, j M Y'))?></div>
      </div>
    </div>
  </div>
</div>

<div class="callout callout-info">
  <?=icon('alert-circle', 16)?>
  <span>
    Secrets are never displayed here — the encryption key and SMTP password are reported only as set or missing.
    Rotating the key invalidates every stored app password, so do that deliberately, not casually.
  </span>
</div>

<?php admin_footer(); ?>
