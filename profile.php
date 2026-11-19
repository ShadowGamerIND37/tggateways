<?php
declare(strict_types=1);
/**
 * Profile Settings — parity with the reference's five tabs:
 * General (details, email, phone), Branding (QR avatar), Security (password),
 * Notifications (per-event email toggles) and Social logins (not configured on
 * a self-hosted install, stated plainly rather than as broken buttons).
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/notify.php';
$merchant = require_login();
$pageTitle = 'Profile Settings';
$pdo = db();
$id = (int)$merchant['id'];
$notice = '';
$error = '';

/** Current profile row, created on demand so the page never has a null case. */
function tg_profile_row(PDO $pdo, int $merchantId): array {
    $q = $pdo->prepare('SELECT * FROM merchant_profiles WHERE merchant_id=?');
    $q->execute([$merchantId]);
    $row = $q->fetch();
    if ($row) return $row;
    $pdo->prepare('INSERT INTO merchant_profiles(merchant_id) VALUES(?)')->execute([$merchantId]);
    $q->execute([$merchantId]);
    return $q->fetch() ?: ['phone' => '', 'support_url' => '', 'avatar' => '', 'timezone' => 'Asia/Kolkata',
        'notify_payments' => 1, 'notify_webhooks' => 1, 'notify_security' => 1];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('profile', 30, 60);
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'details') {
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = preg_replace('/\D/', '', (string)($_POST['phone'] ?? '')) ?? '';
            $support = trim((string)($_POST['support_url'] ?? ''));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                $error = 'Enter your full name (2–120 characters).';
            } elseif ($phone !== '' && strlen($phone) < 10) {
                $error = 'Enter a 10-digit phone number, or leave it blank.';
            } elseif ($support !== '' && !filter_var($support, FILTER_VALIDATE_URL)) {
                $error = 'The support link must be a full URL, e.g. https://wa.me/919999999999';
            } else {
                $pdo->prepare('UPDATE merchants SET name=? WHERE id=?')->execute([$name, $id]);
                $_SESSION['merchant_name'] = $name;
                $pdo->prepare("UPDATE merchant_profiles SET phone=?, support_url=?, updated_at=? WHERE merchant_id=?")
                    ->execute([$phone, $support, db_now(), $id]);
                tg_audit($id, 'profile.details', 'name/phone updated');
                $notice = 'Personal details saved.';
            }
        } elseif ($action === 'email') {
            $newEmail = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
            $pending = $_SESSION['pending_email'] ?? null;
            $code = preg_replace('/\D/', '', (string)($_POST['otp'] ?? '')) ?? '';
            if ($newEmail) {
                if (!tg_mail_transport_available()) {
                    $error = 'Email changes need a configured mail transport (SMTP_HOST) so the code can be delivered.';
                } else {
                    $taken = $pdo->prepare('SELECT id FROM merchants WHERE email=? AND id<>?');
                    $taken->execute([$newEmail, $id]);
                    if ($taken->fetch()) {
                        $error = 'That email is already in use by another account.';
                    } else {
                        $otp = (string)random_int(100000, 999999);
                        $_SESSION['pending_email'] = ['email' => $newEmail, 'code' => $otp, 'expires' => time() + 900];
                        tg_send_2fa_code($newEmail, $otp);
                        $notice = 'A 6-digit code was sent to '.$newEmail.'. Enter it below to confirm.';
                    }
                }
            } elseif (is_array($pending) && hash_equals((string)$pending['code'], $code) && (int)$pending['expires'] >= time()) {
                $pdo->prepare('UPDATE merchants SET email=? WHERE id=?')->execute([(string)$pending['email'], $id]);
                $_SESSION['merchant_email'] = (string)$pending['email'];
                unset($_SESSION['pending_email']);
                tg_audit($id, 'profile.email', 'email changed');
                $notice = 'Email address updated.';
            } elseif (!is_array($pending)) {
                $error = 'Enter the new email address first.';
            } else {
                $error = 'That code did not match, or it expired.';
            }
        } elseif ($action === 'password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            $q = $pdo->prepare('SELECT password FROM merchants WHERE id=?');
            $q->execute([$id]);
            $hash = (string)$q->fetchColumn();
            if (!password_verify($current, $hash)) {
                $error = 'Your current password did not match.';
            } elseif (strlen($new) < 12) {
                $error = 'The new password must be at least 12 characters.';
            } elseif ($new !== $confirm) {
                $error = 'The two new passwords do not match.';
            } else {
                $pdo->prepare('UPDATE merchants SET password=? WHERE id=?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
                tg_audit($id, 'profile.password', 'password changed');
                $notice = 'Password changed. Other sessions keep working until they expire.';
            }
        } elseif ($action === 'notifications') {
            $pdo->prepare('UPDATE merchant_profiles SET notify_payments=?, notify_webhooks=?, notify_security=?, updated_at=? WHERE merchant_id=?')
                ->execute([
                    isset($_POST['notify_payments']) ? 1 : 0,
                    isset($_POST['notify_webhooks']) ? 1 : 0,
                    isset($_POST['notify_security']) ? 1 : 0,
                    db_now(), $id,
                ]);
            $notice = 'Notification preferences saved.';
        } elseif ($action === 'avatar') {
            $data = (string)($_POST['avatar_data'] ?? '');
            if ($data === '') {
                $pdo->prepare('UPDATE merchant_profiles SET avatar=NULL, updated_at=? WHERE merchant_id=?')->execute([db_now(), $id]);
                $notice = 'Avatar removed.';
            } elseif (!preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $data)) {
                $error = 'Upload a PNG, JPEG or WEBP image.';
            } elseif (strlen($data) > 400_000) {
                $error = 'That image is too large. Use one under 200 KB (it is resized in the browser).';
            } else {
                $pdo->prepare('UPDATE merchant_profiles SET avatar=?, updated_at=? WHERE merchant_id=?')->execute([$data, db_now(), $id]);
                tg_audit($id, 'profile.avatar', 'avatar updated');
                $notice = 'Avatar saved. It now appears in your QR codes and the sidebar.';
            }
        }
    } catch (Throwable $e) {
        error_log('profile: '.$e->getMessage());
        $error = 'That change could not be saved. Please try again.';
    }
}

$profile = tg_profile_row($pdo, $id);
$tab = (string)($_GET['tab'] ?? 'general');
if (!in_array($tab, ['general', 'branding', 'security', 'notifications', 'social'], true)) $tab = 'general';
$mid = tg_merchant_mid($id);

require __DIR__.'/includes/dashboard-header.php';
?>

<div class="page-title-row" style="margin-bottom:20px">
  <h1 style="font-size:22px;font-weight:700;letter-spacing:-.5px;margin:0">Profile Settings</h1>
  <p style="font-size:13px;color:var(--color-ink-mute);margin:4px 0 0">Manage your account details and custom brand QR avatar.</p>
</div>

<?php if ($notice): ?>
  <div class="callout callout-success" style="margin-bottom:18px"><?=icon('check-circle', 16)?><span><?=h($notice)?></span></div>
<?php endif ?>
<?php if ($error): ?>
  <div class="callout callout-error" style="margin-bottom:18px"><?=icon('alert-circle', 16)?><span><?=h($error)?></span></div>
<?php endif ?>

<div class="filter-tabs" style="margin-bottom:20px">
  <?php foreach (['general' => 'General', 'branding' => 'Branding', 'security' => 'Security', 'notifications' => 'Notifications', 'social' => 'Social Logins'] as $key => $label): ?>
    <a class="filter-tab <?=$tab === $key ? 'active' : ''?>" href="?tab=<?=$key?>" style="text-decoration:none"><?=$label?></a>
  <?php endforeach ?>
</div>

<?php if ($tab === 'general'): ?>
  <div class="card">
    <div class="card-header">
      <div class="card-title"><?=icon('user', 16)?> Personal Details</div>
      <div class="card-sub">Your name appears on receipts and the checkout page.</div>
    </div>
    <dl style="display:grid;grid-template-columns:180px 1fr;gap:12px 20px;font-size:13px;margin:0 0 24px">
      <dt style="color:var(--color-ink-mute)">Merchant ID</dt><dd style="margin:0"><code><?=h($mid)?></code></dd>
      <dt style="color:var(--color-ink-mute)">Full name</dt><dd style="margin:0;font-weight:500"><?=h($merchant['name'])?></dd>
      <dt style="color:var(--color-ink-mute)">Email address</dt><dd style="margin:0"><?=h($merchant['email'])?></dd>
      <dt style="color:var(--color-ink-mute)">Phone number</dt><dd style="margin:0"><?=h($profile['phone'] ?: 'Not provided')?></dd>
      <dt style="color:var(--color-ink-mute)">Support / contact</dt><dd style="margin:0"><?=h($profile['support_url'] ?: 'Not provided')?></dd>
    </dl>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="details">
      <div class="form-group"><label class="form-label">Full Name</label>
        <input class="form-input" name="name" maxlength="120" required value="<?=h($merchant['name'])?>"></div>
      <div class="form-group"><label class="form-label">Phone Number</label>
        <div style="display:flex;gap:8px"><span class="form-input" style="width:64px;flex:none;display:flex;align-items:center;justify-content:center;background:#f8fafc;color:var(--color-ink-mute)">+91</span>
        <input class="form-input" name="phone" inputmode="numeric" maxlength="10" value="<?=h($profile['phone'])?>"></div></div>
      <div class="form-group" style="grid-column:1/-1"><label class="form-label">Support Link</label>
        <input class="form-input" name="support_url" placeholder="https://wa.me/919999999999" value="<?=h($profile['support_url'])?>"></div>
      <div class="form-actions" style="grid-column:1/-1"><button class="btn btn-primary">Save Changes</button></div>
    </form>
  </div>

  <div class="card" style="margin-top:20px">
    <div class="card-header">
      <div class="card-title"><?=icon('mail', 16)?> Email Address</div>
      <div class="card-sub">Changing the sign-in address needs a code sent to the new address.</div>
    </div>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="email">
      <div class="form-group" style="grid-column:1/-1"><label class="form-label">New Email Address</label>
        <input class="form-input" type="email" name="email" placeholder="<?=h($merchant['email'])?>"></div>
      <?php if (!empty($_SESSION['pending_email'])): ?>
        <div class="form-group" style="grid-column:1/-1"><label class="form-label">6-Digit Code sent to <?=h((string)$_SESSION['pending_email']['email'])?></label>
          <input class="form-input" name="otp" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" placeholder="000000"></div>
      <?php endif ?>
      <div class="form-actions" style="grid-column:1/-1">
        <button class="btn btn-secondary"><?=empty($_SESSION['pending_email']) ? 'Send code' : 'Verify & update'?></button>
      </div>
    </form>
  </div>
<?php endif ?>

<?php if ($tab === 'branding'): ?>
  <div class="card">
    <div class="card-header">
      <div class="card-title"><?=icon('qr-code', 16)?> Profile Avatar</div>
      <div class="card-sub">This avatar is embedded in the centre of your generated QR codes and shown in the sidebar.</div>
    </div>
    <div style="display:flex;gap:26px;align-items:center;flex-wrap:wrap">
      <div style="width:120px;height:120px;border-radius:50%;overflow:hidden;border:1px solid var(--color-hairline);background:#f8fafc;display:flex;align-items:center;justify-content:center">
        <?php if (!empty($profile['avatar'])): ?>
          <img src="<?=h($profile['avatar'])?>" alt="" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
          <span style="font-size:38px;font-weight:700;color:var(--color-ink-mute-2)"><?=h(strtoupper(substr($merchant['name'], 0, 1)))?></span>
        <?php endif ?>
      </div>
      <form method="post" style="flex:1;min-width:260px" id="avatarForm">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="avatar">
        <input type="hidden" name="avatar_data" id="avatarData">
        <input type="file" accept="image/png,image/jpeg,image/webp" id="avatarFile"
               style="font-size:13px;margin-bottom:12px">
        <p style="font-size:12px;color:var(--color-ink-mute);margin:0 0 12px">
          PNG, JPEG or WEBP. Resized to 256×256 in your browser before upload, so large photos are fine.
        </p>
        <div style="display:flex;gap:10px">
          <button class="btn btn-primary" type="submit">Upload New Avatar</button>
          <?php if (!empty($profile['avatar'])): ?>
            <button class="btn btn-secondary" type="submit" name="avatar_data" value="">Remove</button>
          <?php endif ?>
        </div>
      </form>
    </div>
  </div>
  <script>
  /* Resize client-side: keeps uploads small and avoids a server-side image
     library, which this PHP build does not guarantee. */
  document.getElementById('avatarFile')?.addEventListener('change', function () {
    var file = this.files && this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function () {
      var img = new Image();
      img.onload = function () {
        var size = 256, canvas = document.createElement('canvas');
        canvas.width = canvas.height = size;
        var ctx = canvas.getContext('2d');
        var scale = Math.max(size / img.width, size / img.height);
        var w = img.width * scale, h = img.height * scale;
        ctx.drawImage(img, (size - w) / 2, (size - h) / 2, w, h);
        document.getElementById('avatarData').value = canvas.toDataURL('image/jpeg', 0.85);
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  });
  </script>
<?php endif ?>

<?php if ($tab === 'security'): ?>
  <div class="card">
    <div class="card-header">
      <div class="card-title"><?=icon('shield', 16)?> Change Password</div>
      <div class="card-sub">Minimum 12 characters. Existing sessions are not signed out automatically.</div>
    </div>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="password">
      <div class="form-group" style="grid-column:1/-1"><label class="form-label">Current Password</label>
        <input class="form-input" type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="form-group"><label class="form-label">New Password</label>
        <input class="form-input" type="password" name="new_password" minlength="12" required autocomplete="new-password"></div>
      <div class="form-group"><label class="form-label">Confirm New Password</label>
        <input class="form-input" type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></div>
      <div class="form-actions" style="grid-column:1/-1"><button class="btn btn-primary">Update Password</button></div>
    </form>
  </div>
<?php endif ?>

<?php if ($tab === 'notifications'): ?>
  <div class="card">
    <div class="card-header">
      <div class="card-title"><?=icon('mail', 16)?> Email Notifications</div>
      <div class="card-sub">Applies only when a mail transport is configured on this deployment.</div>
    </div>
    <?php if (!tg_mail_transport_available()): ?>
      <div class="callout callout-warning" style="margin-bottom:16px"><?=icon('alert-triangle', 16)?>
        <span>No mail transport configured, so these toggles cannot deliver yet. Set <code>SMTP_HOST</code> in <code>.env</code> to enable email.</span></div>
    <?php endif ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="notifications">
      <?php foreach ([
          'notify_payments' => ['Payment received', 'A receipt was matched and an order captured.'],
          'notify_webhooks' => ['Webhook failures', 'A signed callback exhausted its retries.'],
          'notify_security' => ['Security events', 'Password, email or API-key changes.'],
      ] as $key => [$label, $help]): ?>
        <label style="display:flex;gap:11px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--color-hairline)">
          <input type="checkbox" name="<?=$key?>" value="1" <?=!empty($profile[$key]) ? 'checked' : ''?> style="margin-top:3px">
          <span><strong style="font-size:13px"><?=h($label)?></strong>
            <span style="display:block;font-size:12px;color:var(--color-ink-mute)"><?=h($help)?></span></span>
        </label>
      <?php endforeach ?>
      <div class="form-actions" style="margin-top:18px"><button class="btn btn-primary">Save Preferences</button></div>
    </form>
  </div>
<?php endif ?>

<?php if ($tab === 'social'): ?>
  <div class="card">
    <div class="card-header">
      <div class="card-title"><?=icon('plug', 16)?> Social Logins</div>
      <div class="card-sub">Optional single sign-on for this deployment.</div>
    </div>
    <p style="font-size:13px;color:var(--color-ink-mute);line-height:1.8;margin:0 0 16px">
      Google and passkey sign-in are not configured here. They need OAuth credentials and a
      relying-party domain, both of which are per-deployment decisions — enabling them by
      default would create buttons that fail for every visitor.
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-secondary" disabled title="Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET"><?=icon('shield', 15)?> Google (not configured)</button>
      <button class="btn btn-secondary" disabled title="Requires an HTTPS relying-party domain"><?=icon('key-round', 15)?> Passkey (not configured)</button>
    </div>
  </div>
<?php endif ?>

<?php require __DIR__.'/includes/dashboard-footer.php'; ?>
