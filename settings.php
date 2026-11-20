<?php
declare(strict_types=1);
/**
 * Settings — account-level defaults that apply to every new order:
 * default redirect/webhook URLs, payment timeout and a checkout note.
 *
 * Deliberately separate from Profile: profile is about the person, settings are
 * about the payment flow and are read by the checkout and API layers.
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/auth.php';
$merchant = require_login();
$pageTitle = 'Settings';
$pdo = db();
$id = (int)$merchant['id'];
$notice = '';
$error = '';

/** Settings row, materialised on first read. */
function tg_settings_row(PDO $pdo, int $merchantId): array {
    $q = $pdo->prepare('SELECT * FROM merchant_settings WHERE merchant_id=?');
    $q->execute([$merchantId]);
    $row = $q->fetch();
    if ($row) return $row;
    $m = $pdo->prepare('SELECT webhook_url, redirect_url, payment_timeout_minutes FROM merchants WHERE id=?');
    $m->execute([$merchantId]);
    $base = $m->fetch() ?: [];
    $pdo->prepare('INSERT INTO merchant_settings(merchant_id,default_redirect_url,default_webhook_url,payment_timeout_minutes) VALUES(?,?,?,?)')
        ->execute([$merchantId, $base['redirect_url'] ?? null, $base['webhook_url'] ?? null, (int)($base['payment_timeout_minutes'] ?? 10)]);
    $q->execute([$merchantId]);
    return $q->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('settings', 30, 60);
    $action = (string)($_POST['action'] ?? 'general');
    try {
        if ($action === 'general') {
            $redirect = trim((string)($_POST['default_redirect_url'] ?? ''));
            $webhook = trim((string)($_POST['default_webhook_url'] ?? ''));
            $timeoutRaw = (string)($_POST['payment_timeout_minutes'] ?? '10');
            $unit = (string)($_POST['payment_timeout_unit'] ?? 'minutes');
            $timeoutValid = preg_match('/^[0-9]+$/', $timeoutRaw) === 1;
            $timeout = $timeoutValid ? (int)$timeoutRaw : 0;
            $seconds = $unit === 'seconds' ? $timeout : $timeout * 60;
            $note = trim((string)($_POST['checkout_note'] ?? ''));
            if ($redirect !== '' && !filter_var($redirect, FILTER_VALIDATE_URL)) {
                $error = 'The redirect URL must be a full URL including https://.';
            } elseif ($webhook !== '' && !filter_var($webhook, FILTER_VALIDATE_URL)) {
                $error = 'The webhook URL must be a full URL including https://.';
            } elseif ($webhook !== '' && !str_starts_with(strtolower($webhook), 'https://')) {
                $error = 'The webhook URL must use https:// — signed callbacks never go over plain HTTP.';
            } elseif (!$timeoutValid || !in_array($unit, ['seconds', 'minutes'], true) || ($unit === 'seconds' && ($timeout < 1 || $timeout > 3600)) || ($unit === 'minutes' && ($timeout < 2 || $timeout > 60))) {
                $error = 'Choose 1–3600 seconds or 2–60 minutes.';
            } elseif (mb_strlen($note) > 255) {
                $error = 'Keep the checkout note under 255 characters.';
            } else {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE merchant_settings SET default_redirect_url=?,default_webhook_url=?,payment_timeout_minutes=?,checkout_note=?,updated_at=? WHERE merchant_id=?')
                    ->execute([$redirect ?: null, $webhook ?: null, max(2, (int)ceil($seconds / 60)), $note ?: null, db_now(), $id]);
                // Keep the legacy columns on `merchants` in step: the API reads
                // them when an order does not override the value.
                $pdo->prepare('UPDATE merchants SET redirect_url=?, webhook_url=?, payment_timeout_minutes=?, payment_timeout_seconds=?, payment_timeout_display_unit=? WHERE id=?')
                    ->execute([$redirect ?: null, $webhook ?: null, max(2, (int)ceil($seconds / 60)), $seconds, $unit, $id]);
                $pdo->commit();
                tg_audit($id, 'settings.general', 'payment defaults updated');
                $notice = 'Payment defaults saved.';
            }
        } elseif ($action === 'timezone') {
            $tz = (string)($_POST['timezone'] ?? 'Asia/Kolkata');
            if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                $error = 'Choose a valid timezone.';
            } else {
                $pdo->prepare('UPDATE merchant_profiles SET timezone=?, updated_at=? WHERE merchant_id=?')->execute([$tz, db_now(), $id]);
                $notice = 'Timezone saved. Timestamps in the dashboard use this zone.';
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('settings: '.$e->getMessage());
        $error = 'Those settings could not be saved.';
    }
}

$settings = tg_settings_row($pdo, $id);
$windowQuery = $pdo->prepare('SELECT payment_timeout_minutes,payment_timeout_seconds,payment_timeout_display_unit FROM merchants WHERE id=?');
$windowQuery->execute([$id]);
$windowRow = $windowQuery->fetch() ?: [];
$windowSeconds = payment_window_seconds($windowRow);
// Legacy rows did not preserve the chosen display unit; infer it until saved.
$windowUnit = $windowRow['payment_timeout_display_unit'] ?? null;
if (!in_array($windowUnit, ['seconds', 'minutes'], true) || ($windowUnit === 'minutes' && $windowSeconds % 60 !== 0)) {
    $windowUnit = $windowSeconds >= 120 && $windowSeconds % 60 === 0 ? 'minutes' : 'seconds';
}
$windowValue = $windowUnit === 'minutes' ? intdiv($windowSeconds, 60) : $windowSeconds;
$tzRow = $pdo->prepare('SELECT timezone FROM merchant_profiles WHERE merchant_id=?');
$tzRow->execute([$id]);
$timezone = (string)($tzRow->fetchColumn() ?: 'Asia/Kolkata');

require __DIR__.'/includes/dashboard-header.php';
?>

<div class="page-title-row" style="margin-bottom:20px">
  <h1 style="font-size:22px;font-weight:700;letter-spacing:-.5px;margin:0">Settings</h1>
  <p style="font-size:13px;color:var(--color-ink-mute);margin:4px 0 0">Defaults applied to every new order created through the API or a payment link.</p>
</div>

<?php if ($notice): ?><div class="callout callout-success" style="margin-bottom:18px"><?=icon('check-circle', 16)?><span><?=h($notice)?></span></div><?php endif ?>
<?php if ($error): ?><div class="callout callout-error" style="margin-bottom:18px"><?=icon('alert-circle', 16)?><span><?=h($error)?></span></div><?php endif ?>

<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
  <input type="hidden" name="action" value="general">
  <div class="card-header">
    <div class="card-title"><?=icon('settings', 16)?> Payment defaults</div>
    <div class="card-sub">Used when a request does not pass its own value.</div>
  </div>
  <div class="form-grid">
    <div class="form-group" style="grid-column:1/-1">
      <label class="form-label">Default redirect URL</label>
      <input class="form-input" name="default_redirect_url" placeholder="https://yoursite.com/payment-success"
             value="<?=h((string)($settings['default_redirect_url'] ?? ''))?>">
      <span class="form-hint">Where the hosted checkout sends the customer after a capture.</span>
    </div>
    <div class="form-group" style="grid-column:1/-1">
      <label class="form-label">Default webhook URL</label>
      <input class="form-input" name="default_webhook_url" placeholder="https://yoursite.com/api/webhook"
             value="<?=h((string)($settings['default_webhook_url'] ?? ''))?>">
      <span class="form-hint">Signed <code>payment.succeeded</code> callbacks are POSTed here. Per-order overrides win.</span>
    </div>
    <div class="form-group">
      <label class="form-label" for="payment-window">Payment window</label>
      <div class="payment-window-fields">
        <input class="form-input" id="payment-window" type="number" name="payment_timeout_minutes" min="1" max="3600" step="1" required value="<?=h((string)$windowValue)?>">
        <select class="form-input" name="payment_timeout_unit" aria-label="Payment window unit">
          <option value="minutes" <?=$windowUnit === 'minutes' ? 'selected' : ''?>>Minutes</option>
          <option value="seconds" <?=$windowUnit === 'seconds' ? 'selected' : ''?>>Seconds</option>
        </select>
      </div>
      <span class="form-hint">Enter 2–60 minutes or 1–3600 seconds. This is the countdown for each new checkout. Existing orders keep their expiry; short windows may expire before payment is confirmed.</span>
    </div>
    <div class="form-group">
      <label class="form-label">Checkout note</label>
      <input class="form-input" name="checkout_note" maxlength="255" placeholder="e.g. Include your order ID in the UPI remark"
             value="<?=h((string)($settings['checkout_note'] ?? ''))?>">
      <span class="form-hint">Shown under the QR on the hosted checkout page.</span>
    </div>
    <div class="form-actions" style="grid-column:1/-1"><button class="btn btn-primary">Save defaults</button></div>
  </div>
</form>

<form method="post" class="card" style="margin-top:20px">
  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
  <input type="hidden" name="action" value="timezone">
  <div class="card-header">
    <div class="card-title"><?=icon('clock', 16)?> Localisation</div>
    <div class="card-sub">Display timezone for the dashboard and receipts.</div>
  </div>
  <div class="form-grid">
    <div class="form-group">
      <label class="form-label">Timezone</label>
      <select class="form-input" name="timezone">
        <?php foreach (['Asia/Kolkata', 'Asia/Dubai', 'UTC', 'Asia/Singapore', 'Europe/London', 'America/New_York'] as $zone): ?>
          <option value="<?=$zone?>" <?=$timezone === $zone ? 'selected' : ''?>><?=$zone?></option>
        <?php endforeach ?>
      </select>
    </div>
    <div class="form-actions" style="grid-column:1/-1"><button class="btn btn-secondary">Save timezone</button></div>
  </div>
</form>

<div class="card" style="margin-top:20px">
  <div class="card-header">
    <div class="card-title"><?=icon('shield', 16)?> Security</div>
    <div class="card-sub">Changes that protect the account rather than the payment flow.</div>
  </div>
  <p style="font-size:13px;color:var(--color-ink-mute);line-height:1.9;margin:0">
    Password and email changes live on <a href="/profile.php?tab=security" style="color:var(--color-primary);font-weight:600">Profile → Security</a>.
    API keys can be rotated on <a href="/api-keys.php" style="color:var(--color-primary);font-weight:600">API Keys</a>;
    rotating immediately invalidates the old key for every integration.
  </p>
</div>

<?php require __DIR__.'/includes/dashboard-footer.php'; ?>
