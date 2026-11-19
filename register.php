<?php
declare(strict_types=1);
/**
 * Merchant self-registration.
 *
 * Mirrors the reference flow: details form → 6-digit email code → dashboard.
 * The code step is skipped when no mail transport is configured, so a
 * fresh self-hosted deployment can still be provisioned.
 */
require_once __DIR__.'/includes/auth-shell.php';
require_once __DIR__.'/includes/notify.php';

if (current_merchant()) redirect_to('/dashboard.php');

$error = '';
$step = 'details';
$posted = ['name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('register', 8, 3600);
    $action = (string)($_POST['action'] ?? 'create');

    if ($action === 'verify') {
        $step = 'verify';
        $code = preg_replace('/\D/', '', (string)($_POST['otp'] ?? '')) ?? '';
        $pending = $_SESSION['pending_register'] ?? null;
        if (!is_array($pending)) {
            $error = 'Your registration session expired. Please fill the form again.';
            $step = 'details';
        } elseif ((int)($pending['expires'] ?? 0) < time()) {
            $error = 'That code expired. Please register again to receive a new one.';
            unset($_SESSION['pending_register']);
            $step = 'details';
        } elseif ($code === '' || !hash_equals((string)$pending['code'], $code)) {
            $error = 'That code did not match. Check the email and try again.';
            $posted = ['name' => (string)$pending['name'], 'email' => (string)$pending['email'], 'phone' => (string)($pending['phone'] ?? '')];
        } else {
            try {
                $key = 'tg_'.bin2hex(random_bytes(30));
                $q = db()->prepare('INSERT INTO merchants(name,email,password,api_key) VALUES(?,?,?,?)');
                $q->execute([$pending['name'], $pending['email'], $pending['password'], $key]);
                unset($_SESSION['pending_register']);
                login_merchant(['id' => (int)db()->lastInsertId(), 'name' => $pending['name'], 'email' => $pending['email']]);
                redirect_to('/integrations.php?welcome=1');
            } catch (PDOException $e) {
                $code = (string)$e->getCode();
                $driverCode = (int)($e->errorInfo[1] ?? 0);
                if ($code === '23000' || in_array($driverCode, [19, 1062], true)) {
                    $error = 'An account with that email already exists.';
                } else {
                    $error = 'Could not create your account.';
                    error_log($e->getMessage());
                }
                unset($_SESSION['pending_register']);
                $step = 'details';
            }
        }
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $phone = preg_replace('/\D/', '', (string)($_POST['phone'] ?? '')) ?? '';
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $terms = isset($_POST['terms']);
        $posted = ['name' => $name, 'email' => (string)($email ?: ''), 'phone' => $phone];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $error = 'Enter your full name (at least 2 characters).';
        } elseif (!$email) {
            $error = 'Enter a valid email address.';
        } elseif ($phone !== '' && strlen($phone) < 10) {
            $error = 'Enter a 10-digit phone number, or leave it blank.';
        } elseif (strlen($password) < 12) {
            $error = 'Choose a password of at least 12 characters.';
        } elseif ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        } elseif (!$terms) {
            $error = 'Please accept the Terms of Service and Privacy Policy.';
        } else {
            $existing = db()->prepare('SELECT id FROM merchants WHERE email=?');
            $existing->execute([$email]);
            if ($existing->fetch()) {
                $error = 'An account with that email already exists.';
            } elseif (tg_two_factor_required()) {
                $otp = (string)random_int(100000, 999999);
                $_SESSION['pending_register'] = [
                    'name' => $name, 'email' => $email, 'phone' => $phone,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'code' => $otp, 'expires' => time() + 900,
                ];
                tg_send_2fa_code($email, $otp);
                $step = 'verify';
            } else {
                try {
                    $key = 'tg_'.bin2hex(random_bytes(30));
                    $q = db()->prepare('INSERT INTO merchants(name,email,password,api_key) VALUES(?,?,?,?)');
                    $q->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $key]);
                    login_merchant(['id' => (int)db()->lastInsertId(), 'name' => $name, 'email' => $email]);
                    redirect_to('/integrations.php?welcome=1');
                } catch (PDOException $e) {
                    $error = 'Could not create your account.';
                    error_log($e->getMessage());
                }
            }
        }
    }
} elseif (!empty($_SESSION['pending_register'])) {
    $step = 'verify';
    $posted = ['name' => (string)$_SESSION['pending_register']['name'], 'email' => (string)$_SESSION['pending_register']['email'], 'phone' => (string)($_SESSION['pending_register']['phone'] ?? '')];
}

tg_auth_header(
    'Register',
    'Get started in minutes.',
    'minutes.',
    'Create a free account and start accepting UPI payments through our API instantly.',
    [
        'Free forever — zero platform fees',
        'Configure UPI notifications in minutes',
        'Instant API key & live webhook alerts',
    ]
);
?>
<div class="auth-form-header">
  <h1 class="auth-form-title"><?=$step === 'verify' ? 'Verify your email' : 'Create your account'?></h1>
  <p class="auth-form-subtitle"><?=$step === 'verify'
      ? 'Enter the 6-digit code sent to '.h($posted['email']).'.'
      : 'Free forever. No card, no platform fee, no minimum volume.'?></p>
</div>

<?=tg_auth_error($error)?>

<?php if ($step === 'verify'): ?>
  <form method="post" style="display:grid;gap:14px">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="verify">
    <div class="form-group">
      <label class="form-label" for="otp">Enter 6-digit OTP</label>
      <input class="form-input" id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code"
             pattern="[0-9]{6}" maxlength="6" required autofocus
             style="letter-spacing:.5em;font-size:20px;text-align:center;font-weight:600">
    </div>
    <button class="btn btn-primary" type="submit" style="width:100%">Verify &amp; Complete Registration <?=icon('arrow-right', 15)?></button>
    <a class="auth-back" href="/register.php" style="justify-content:center">← Go back</a>
  </form>
<?php else: ?>
  <form method="post" style="display:grid;gap:14px">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="form-group">
      <label class="form-label" for="name">Full Name</label>
      <input class="form-input" id="name" name="name" maxlength="120" required value="<?=h($posted['name'])?>" placeholder="Rohan Sharma">
    </div>
    <div class="form-group">
      <label class="form-label" for="email">Email address</label>
      <input class="form-input" id="email" type="email" name="email" required autocomplete="email" value="<?=h($posted['email'])?>" placeholder="you@example.com">
    </div>
    <div class="form-group">
      <label class="form-label" for="phone">Phone Number (WhatsApp)</label>
      <div style="display:flex;gap:8px">
        <span class="form-input" style="width:64px;flex:none;display:flex;align-items:center;justify-content:center;background:#f8fafc;color:var(--color-ink-mute)">+91</span>
        <input class="form-input" id="phone" name="phone" inputmode="numeric" maxlength="10" value="<?=h($posted['phone'])?>" placeholder="9876543210">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label" for="password">Password</label>
      <input class="form-input" id="password" type="password" name="password" minlength="12" required autocomplete="new-password" placeholder="At least 12 characters">
    </div>
    <div class="form-group">
      <label class="form-label" for="confirm_password">Confirm Password</label>
      <input class="form-input" id="confirm_password" type="password" name="confirm_password" minlength="12" required autocomplete="new-password" placeholder="Repeat your password">
    </div>
    <label style="display:flex;gap:9px;align-items:flex-start;font-size:12.5px;color:var(--color-ink-mute);line-height:1.6">
      <input type="checkbox" name="terms" value="1" required style="margin-top:2px">
      <span>I confirm that I am 18 years of age or older and agree to the
        <a href="/docs.php" style="color:var(--color-primary)">Terms of Service</a> and
        <a href="/privacy.php" style="color:var(--color-primary)">Privacy Policy</a>.</span>
    </label>
    <button class="btn btn-primary" type="submit" style="width:100%">Create Account <?=icon('arrow-right', 15)?></button>
  </form>
<?php endif ?>

<div class="auth-form-footer">
  Already have an account? <a href="/login.php">Sign in</a>
</div>
<?php tg_auth_footer(); ?>
