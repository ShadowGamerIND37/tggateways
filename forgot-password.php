<?php
declare(strict_types=1);
/**
 * Request a password-reset link.
 *
 * The response is identical whether or not the address has an account, so this
 * page cannot be used to discover who is registered.
 */
require_once __DIR__.'/includes/auth-shell.php';
require_once __DIR__.'/includes/password-reset.php';

if (current_merchant()) redirect_to('/dashboard.php');

$error = '';
$sent = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // Tighter than sign-in: a reset sends mail, which costs money and can be
    // used to flood an inbox.
    rate_limit('password-reset', 5, 900);
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter the email address on your account.';
    } elseif (!tg_password_reset_request($email)) {
        $error = 'This deployment has no mail transport configured, so a reset '
            . 'link cannot be sent. Ask your administrator to set SMTP_HOST, '
            . 'or to reset the password directly.';
    } else {
        $sent = true;
    }
}

tg_auth_header(
    'Reset Password',
    'Get back into your account.',
    'account.',
    'We email a single-use link that lets you choose a new password. Nothing changes until you use it.',
    [
        'Link expires in one hour',
        'Works once, then stops working',
        'Your API key and integrations are unaffected',
    ]
);
?>
<div class="auth-form-header">
  <h1 class="auth-form-title">Reset your password</h1>
  <p class="auth-form-subtitle">Enter your account email and we will send a reset link.</p>
</div>

<?php if ($sent): ?>
  <div class="callout callout-success" style="margin-bottom:16px">
    <?=icon('check-circle', 15)?>
    <span>If an account exists for <strong><?=h($email)?></strong>, a reset link is on its way.
      Check the spam folder too; the link expires in an hour.</span>
  </div>
  <a class="btn btn-secondary" href="/login.php" style="width:100%;justify-content:center">
    Back to sign in
  </a>
<?php else: ?>
  <?=tg_auth_error($error)?>
  <form method="post" style="display:grid;gap:14px">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="form-group">
      <label class="form-label" for="email">Email address</label>
      <input class="form-input" id="email" type="email" name="email" required autocomplete="username"
             value="<?=h($email)?>" placeholder="you@example.com" autofocus>
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%">
      Email me a reset link <?=icon('arrow-right', 15)?>
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
