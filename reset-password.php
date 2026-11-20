<?php
declare(strict_types=1);
/**
 * Choose a new password from an emailed reset link.
 *
 * The token is validated before the form is drawn so a dead link says so
 * immediately, rather than after the user has typed a password twice.
 */
require_once __DIR__.'/includes/auth-shell.php';
require_once __DIR__.'/includes/password-reset.php';

if (current_merchant()) redirect_to('/dashboard.php');

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$lookup = tg_password_reset_lookup($token);
$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('password-reset-consume', 10, 900);
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    if (!$lookup) {
        $error = 'That reset link is invalid, already used, or expired. Request a new one.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        $error = tg_password_reset_consume($token, $password);
        if ($error === '') {
            $done = true;
            $lookup = null;
        }
    }
}

tg_auth_header(
    'Choose a New Password',
    'Pick something new.',
    'new.',
    'Your reset link is single-use. Choosing a password here consumes it and returns you to sign-in.',
    [
        'At least 8 characters',
        'API key and webhooks untouched',
        'Sign in immediately after',
    ]
);
?>
<div class="auth-form-header">
  <h1 class="auth-form-title">Choose a new password</h1>
  <p class="auth-form-subtitle">
    <?=$done ? 'Your password has been changed.' : 'Enter it twice to confirm.'?>
  </p>
</div>

<?php if ($done): ?>
  <div class="callout callout-success" style="margin-bottom:16px">
    <?=icon('check-circle', 15)?>
    <span>Your password is updated. You can sign in with it now.</span>
  </div>
  <a class="btn btn-primary" href="/login.php" style="width:100%;justify-content:center">
    Sign in <?=icon('arrow-right', 15)?>
  </a>
<?php elseif (!$lookup): ?>
  <?=tg_auth_error($error !== '' ? $error : 'That reset link is invalid, already used, or expired.')?>
  <a class="btn btn-primary" href="/forgot-password.php" style="width:100%;justify-content:center">
    Request a new link <?=icon('arrow-right', 15)?>
  </a>
<?php else: ?>
  <?=tg_auth_error($error)?>
  <form method="post" style="display:grid;gap:14px">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="token" value="<?=h($token)?>">
    <div class="form-group">
      <label class="form-label" for="password">New password</label>
      <input class="form-input" id="password" type="password" name="password" required
             minlength="8" maxlength="200" autocomplete="new-password" autofocus
             placeholder="At least 8 characters">
    </div>
    <div class="form-group">
      <label class="form-label" for="confirm">Confirm new password</label>
      <input class="form-input" id="confirm" type="password" name="confirm" required
             minlength="8" maxlength="200" autocomplete="new-password"
             placeholder="Type it again">
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%">
      Save new password <?=icon('arrow-right', 15)?>
    </button>
    <a class="auth-back" href="/login.php" style="justify-content:center">
      <?=icon('arrow-right', 13)?> Back to sign in
    </a>
  </form>
<?php endif ?>

<div class="auth-form-footer">
  Don't have an account? <a href="/register.php">Create one free</a>
  <div style="margin-top:12px;font-size:11px;color:var(--color-ink-mute-2)">
    <a href="/docs.php">Documentation</a> · <a href="/status.php">System status</a> · <a href="/privacy.php">Privacy</a>
  </div>
</div>
<?php tg_auth_footer(); ?>
