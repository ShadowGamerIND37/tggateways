<?php
declare(strict_types=1);
/**
 * Merchant sign-in.
 *
 * Two steps, as in the reference design: password, then a 6-digit email code
 * when the deployment requires two-factor authentication. The code step is
 * only offered when mail is actually configured, so a single factor
 * deployment never shows a dead-end screen.
 */
require_once __DIR__.'/includes/auth-shell.php';
require_once __DIR__.'/includes/notify.php';

if (current_merchant()) redirect_to('/dashboard.php');

$error = '';
$step = 'password';
if (isset($_GET['suspended'])) {
    $error = 'This account has been suspended. Contact support if you believe this is a mistake.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('login', 12, 300);
    $action = (string)($_POST['action'] ?? 'signin');

    if ($action === 'verify') {
        $step = 'twofactor';
        $pendingId = (int)($_SESSION['pending_2fa']['merchant_id'] ?? 0);
        $code = preg_replace('/\D/', '', (string)($_POST['otp'] ?? '')) ?? '';
        $expected = (string)($_SESSION['pending_2fa']['code'] ?? '');
        $expires = (int)($_SESSION['pending_2fa']['expires'] ?? 0);

        if ($pendingId <= 0) {
            $error = 'Your sign-in session expired. Please start again.';
            $step = 'password';
        } elseif ($expires < time()) {
            $error = 'That code has expired. Sign in again to receive a new one.';
            unset($_SESSION['pending_2fa']);
            $step = 'password';
        } elseif ($code === '' || !hash_equals($expected, $code)) {
            $error = 'That code did not match. Check the email and try again.';
        } else {
            $q = db()->prepare('SELECT id,name,email FROM merchants WHERE id=?');
            $q->execute([$pendingId]);
            $m = $q->fetch();
            unset($_SESSION['pending_2fa']);
            if (!$m) {
                $error = 'That account no longer exists.';
                $step = 'password';
            } else {
                login_merchant($m);
                redirect_to('/dashboard.php');
            }
        }
    } else {
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pass = (string)($_POST['password'] ?? '');
        if (tg_two_factor_required()) {
            // Defer the password verdict until after the code: a wrong password
            // and a right password must be indistinguishable to an attacker
            // until the second factor is supplied.
            $q = db()->prepare('SELECT id,name,email,password FROM merchants WHERE email=?');
            $q->execute([$email ?: '']);
            $m = $q->fetch();
            if ($m && password_verify($pass, $m['password'])) {
                $code = (string)random_int(100000, 999999);
                $_SESSION['pending_2fa'] = ['merchant_id' => (int)$m['id'], 'code' => $code, 'expires' => time() + 600];
                tg_send_2fa_code((string)$m['email'], $code);
                $step = 'twofactor';
            } else {
                $error = 'Email or password did not match.';
            }
        } else {
            try {
                $q = db()->prepare('SELECT id,name,email,password,status,suspended_reason FROM merchants WHERE email=?');
                $q->execute([$email ?: '']);
                $m = $q->fetch();
                if ($m && password_verify($pass, $m['password'])) {
                    if (($m['status'] ?? 'active') !== 'active') {
                        // A suspended merchant keeps their data and their API
                        // key record, but cannot sign in. The reason is shown so
                        // they know to contact the operator instead of assuming
                        // the password broke.
                        $error = 'This account is suspended.'.(($m['suspended_reason'] ?? '') !== '' ? ' '.$m['suspended_reason'] : ' Contact support.');
                    } else {
                        login_merchant($m);
                        redirect_to('/dashboard.php');
                    }
                }
            } catch (Throwable) {
                $error = 'Sign-in is unavailable. Check the database setup.';
            }
            if ($error === '') $error = 'Email or password did not match.';
        }
    }
} elseif (!empty($_SESSION['pending_2fa'])) {
    $step = 'twofactor';
}

tg_auth_header(
    'Sign In',
    'UPI payments, automated.',
    'automated.',
    'The most reliable UPI payment gateway built for developers. Accept payments in seconds with a simple API call.',
    [
        'Real-time bank receipt matching',
        'Instant webhook callbacks on payment',
        'No commission — zero platform fees',
    ]
);
?>
<div class="auth-form-header">
  <h1 class="auth-form-title"><?=$step === 'twofactor' ? 'Two-Factor Authentication' : 'Sign in to your account'?></h1>
  <p class="auth-form-subtitle"><?=$step === 'twofactor'
      ? 'A 6-digit verification code was sent to your email address.'
      : 'Welcome back. Enter your credentials to open the dashboard.'?></p>
</div>

<?=tg_auth_error($error)?>

<?php if ($step === 'twofactor'): ?>
  <form method="post" style="display:grid;gap:14px">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="verify">
    <div class="form-group">
      <label class="form-label" for="otp">Enter 6-Digit Code</label>
      <input class="form-input" id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code"
             pattern="[0-9]{6}" maxlength="6" required autofocus
             style="letter-spacing:.5em;font-size:20px;text-align:center;font-weight:600">
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%">Verify Code <?=icon('arrow-right', 15)?></button>
    <a class="auth-back" href="/login.php" style="justify-content:center"><?=icon('arrow-right', 13)?> Back to sign in</a>
  </form>
<?php else: ?>
  <div style="display:grid;gap:10px">
    <button class="social-btn" type="button" disabled title="Configure Google OAuth to enable this">
      <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.76h3.57c2.08-1.92 3.27-4.74 3.27-8.09Z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.76c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z"/><path fill="#FBBC05" d="M5.84 14.11a6.6 6.6 0 0 1 0-4.22V7.05H2.18a11 11 0 0 0 0 9.9l3.66-2.84Z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1A11 11 0 0 0 2.18 7.05l3.66 2.84c.87-2.6 3.3-4.51 6.16-4.51Z"/></svg>
      Continue with Google
    </button>
    <button class="social-btn" type="button" disabled title="Passkeys are not configured on this deployment">
      <?=icon('shield', 16)?> Sign in with a passkey
    </button>
  </div>
  <div class="divider">or sign in with email</div>
  <form method="post" style="display:grid;gap:14px">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="form-group">
      <label class="form-label" for="email">Email address</label>
      <input class="form-input" id="email" type="email" name="email" required autocomplete="username"
             value="<?=h((string)($_POST['email'] ?? ''))?>" placeholder="you@example.com">
    </div>
    <div class="form-group">
      <label class="form-label" for="password">Password</label>
      <input class="form-input" id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••••••">
    </div>
    <div style="display:flex;justify-content:flex-end">
      <a href="/forgot-password.php" style="font-size:12px;color:var(--color-ink-mute);text-decoration:none">
        Forgot password?
      </a>
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%">Sign In <?=icon('arrow-right', 15)?></button>
  </form>
<?php endif ?>

<div class="auth-form-footer">
  Don't have an account? <a href="/register.php">Create one free</a>
  <div style="margin-top:12px;font-size:11px;color:var(--color-ink-mute-2)">
    <a href="/docs.php">Documentation</a> · <a href="/status.php">System status</a> · <a href="/privacy.php">Privacy</a>
  </div>
</div>
<?php tg_auth_footer(); ?>
