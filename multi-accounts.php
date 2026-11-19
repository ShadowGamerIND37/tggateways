<?php
declare(strict_types=1);
/**
 * Multi-FamPay Account Manager — parity with the reference, plus the safety
 * rules that a payout screen needs:
 *
 *   - a hard cap on linked accounts (MAX_RECEIVING_ACCOUNTS, default 5)
 *   - UPI ID validation, optionally restricted to specific handles
 *   - changing the payout UPI or the receipt mailbox requires the account
 *     password (or a 6-digit email code when SMTP is configured)
 *   - exactly one account receives new orders at a time
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/crypto.php';
require_once __DIR__.'/includes/notify.php';

/**
 * Apply a validated account update. Shared by the normal save path and by the
 * post-authorisation replay so both behave identically.
 *
 * @param array{id:int,label:string,upi:string,payee:string,email:string,imap_host:string,imap_port:int,app_password:string} $data
 */
function tg_apply_account_edit(PDO $pdo, int $merchantId, array $data): string {
    $targetId = (int)$data['id'];
    $password = (string)($data['app_password'] ?? '');
    $params = [mb_substr((string)$data['label'], 0, 100), (string)$data['upi'], $data['payee'] ?: null,
        (string)$data['email'], (string)$data['imap_host'], (int)$data['imap_port']];
    if ($password !== '') {
        $pdo->prepare('UPDATE merchant_accounts SET account_label=?,upi_id=?,payee_name=?,linked_email=?,imap_host=?,imap_port=?,google_app_password=? WHERE id=? AND merchant_id=?')
            ->execute([...$params, encrypt_secret($password), $targetId, $merchantId]);
    } else {
        $pdo->prepare('UPDATE merchant_accounts SET account_label=?,upi_id=?,payee_name=?,linked_email=?,imap_host=?,imap_port=? WHERE id=? AND merchant_id=?')
            ->execute([...$params, $targetId, $merchantId]);
    }
    tg_audit($merchantId, 'account.payout_changed', (string)$data['upi']);
    tg_reauth_clear();
    return 'Payout details updated. New orders use the new UPI ID immediately.';
}

$merchant = require_login();
$pageTitle = 'Multi-Accounts';
$pdo = db();
$id = (int)$merchant['id'];
$notice = '';
$error = '';
$editing = (int)($_GET['edit'] ?? 0);
$editingRow = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('multi-accounts', 20, 300);
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'reauth') {
            $password = (string)($_POST['password'] ?? '');
            if (tg_reauth_password($id, $password)) {
                $editing = (int)($_POST['edit'] ?? 0);
                // Replay the edit that was blocked on authorisation, so the
                // merchant does not have to retype the payout details.
                $replay = $_SESSION['pending_account_edit'] ?? null;
                if (is_array($replay) && (int)($replay['id'] ?? 0) === $editing && $editing > 0) {
                    $notice = tg_apply_account_edit($pdo, $id, $replay);
                    unset($_SESSION['pending_account_edit']);
                    $editing = 0;
                } else {
                    $notice = 'Authorised. You can change the payout details for 15 minutes.';
                }
            } else {
                $error = 'That password did not match.';
                $editing = (int)($_POST['edit'] ?? 0);
            }
        } elseif ($action === 'reauth_otp_send') {
            // Alternative to the password: mail a 6-digit code to the address on
            // file, then replay the blocked edit once it is verified.
            rate_limit('reauth-otp', 5, 600);
            $editing = (int)($_POST['edit'] ?? 0);
            $sentTo = tg_reauth_otp_send($id);
            if ($sentTo === null) {
                $error = 'Email delivery is not configured on this deployment. Use your account password instead.';
            } else {
                $notice = 'A 6-digit code is on its way to '.tg_mask_email($sentTo).'. It expires in 10 minutes.';
            }
        } elseif ($action === 'reauth_otp_verify') {
            $editing = (int)($_POST['edit'] ?? 0);
            if (tg_reauth_otp_verify((string)($_POST['otp'] ?? ''))) {
                $replay = $_SESSION['pending_account_edit'] ?? null;
                if (is_array($replay) && (int)($replay['id'] ?? 0) === $editing && $editing > 0) {
                    $notice = tg_apply_account_edit($pdo, $id, $replay);
                    unset($_SESSION['pending_account_edit']);
                    $editing = 0;
                } else {
                    $notice = 'Authorised. You can change the payout details for 15 minutes.';
                }
            } else {
                $error = 'That code is wrong or has expired.';
            }
        } elseif ($action === 'add' || $action === 'update') {
            $label = trim((string)($_POST['account_label'] ?? ''));
            $upi = trim((string)($_POST['upi_id'] ?? ''));
            $payee = trim((string)($_POST['payee_name'] ?? ''));
            $email = filter_var($_POST['linked_email'] ?? '', FILTER_VALIDATE_EMAIL);
            $password = preg_replace('/\s+/', '', (string)($_POST['app_password'] ?? '')) ?? '';
            $imapHost = trim((string)($_POST['imap_host'] ?? '')) ?: 'imap.gmail.com';
            $imapPort = (int)($_POST['imap_port'] ?? 993) ?: 993;
            $targetId = (int)($_POST['id'] ?? 0);

            $existing = null;
            if ($action === 'update') {
                $q = $pdo->prepare('SELECT * FROM merchant_accounts WHERE id=? AND merchant_id=?');
                $q->execute([$targetId, $id]);
                $existing = $q->fetch() ?: null;
                $editing = $targetId;
                if (!$existing) $error = 'That account no longer exists.';
            }

            $upiProblem = tg_validate_upi($upi);
            $changesPayout = $existing !== null
                && ($existing['upi_id'] !== $upi || $existing['linked_email'] !== ($email ?: ''));

            if ($error === '' && mb_strlen($label) < 2) {
                $error = 'Give the account a short label, e.g. "Primary family wallet".';
            } elseif ($error === '' && $upiProblem !== null) {
                $error = $upiProblem;
            } elseif ($error === '' && !$email) {
                $error = 'Enter the email address that receives the payment alerts.';
            } elseif ($error === '' && !preg_match('/^[A-Za-z0-9.-]{3,190}$/', $imapHost)) {
                $error = 'Enter a valid IMAP hostname.';
            } elseif ($error === '' && ($imapPort < 1 || $imapPort > 65535)) {
                $error = 'The IMAP port must be between 1 and 65535.';
            } elseif ($error === '' && $password !== '' && !preg_match('/^[A-Za-z0-9]{16}$/', $password)) {
                $error = 'A Google app password is exactly 16 characters with no spaces.';
            } elseif ($action === 'add' && $password === '') {
                $error = 'An app password is required so receipts can be read from that mailbox.';
            } elseif ($action === 'add' && tg_account_count($id) >= tg_account_limit()) {
                $error = 'This deployment allows '.tg_account_limit().' receiving accounts. Remove one before adding another.';
            } elseif ($error === '' && $changesPayout && !tg_reauth_fresh()) {
                $error = 'Changing the payout UPI or receipt mailbox needs your account password. '
                    . 'Scroll to the authorisation panel and confirm, then save again.';
                $_SESSION['pending_account_edit'] = [
                    'id' => $targetId, 'action' => $action, 'label' => $label, 'upi' => $upi,
                    'payee' => $payee, 'email' => (string)$email, 'imap_host' => $imapHost,
                    'imap_port' => $imapPort, 'app_password' => $password,
                ];
            } elseif ($error === '') {
                if ($action === 'add') {
                    // The first wallet on an account must go live immediately:
                    // a merchant with zero active accounts receives no orders and
                    // the worker polls no mailbox, so nothing would ever settle.
                    $isFirst = tg_account_count($id) === 0;
                    $q = $pdo->prepare('INSERT INTO merchant_accounts(merchant_id,account_label,upi_id,payee_name,linked_email,imap_host,imap_port,google_app_password,is_active) VALUES(?,?,?,?,?,?,?,?,?)');
                    $q->execute([$id, mb_substr($label, 0, 100), $upi, $payee ?: null, (string)$email,
                        $imapHost, $imapPort, encrypt_secret($password), $isFirst ? 1 : 0]);
                    tg_audit($id, 'account.added', $upi);
                    $notice = $isFirst
                        ? 'Primary wallet connected and set active. New orders route to it immediately.'
                        : 'Standby account added. Activate it when its mailbox test passes.';
                } else {
                    // An empty app password means "keep the stored one".
                    $notice = tg_apply_account_edit($pdo, $id, [
                        'id' => $targetId, 'label' => $label, 'upi' => $upi, 'payee' => $payee,
                        'email' => (string)$email, 'imap_host' => $imapHost,
                        'imap_port' => $imapPort, 'app_password' => $password,
                    ]);
                    unset($_SESSION['pending_account_edit']);
                    $editing = 0;
                }
            }
        } elseif ($action === 'activate') {
            $target = (int)($_POST['id'] ?? 0);
            $row = $pdo->prepare('SELECT id,upi_id FROM merchant_accounts WHERE id=? AND merchant_id=?');
            $row->execute([$target, $id]);
            $account = $row->fetch();
            if (!$account) {
                $error = 'That account no longer exists.';
            } else {
                // Exactly one active receiver: activating a standby retires the
                // previous primary instead of silently splitting traffic.
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE merchant_accounts SET is_active=0 WHERE merchant_id=?')->execute([$id]);
                $pdo->prepare('UPDATE merchant_accounts SET is_active=1 WHERE id=? AND merchant_id=?')->execute([$target, $id]);
                $pdo->commit();
                tg_audit($id, 'account.activated', (string)$account['upi_id']);
                $notice = 'Active receiving account switched. New orders use this UPI ID.';
            }
        } elseif ($action === 'deactivate') {
            $target = (int)($_POST['id'] ?? 0);
            $active = $pdo->prepare('SELECT COUNT(*) FROM merchant_accounts WHERE merchant_id=? AND is_active=1');
            $active->execute([$id]);
            if ((int)$active->fetchColumn() <= 1) {
                $error = 'Keep at least one active account; add and activate another one first.';
            } else {
                $pdo->prepare('UPDATE merchant_accounts SET is_active=0 WHERE id=? AND merchant_id=?')->execute([$target, $id]);
                $notice = 'Account moved to standby. It will not receive new orders.';
            }
        } elseif ($action === 'delete') {
            $target = (int)($_POST['id'] ?? 0);
            $row = $pdo->prepare('SELECT account_label,is_active FROM merchant_accounts WHERE id=? AND merchant_id=?');
            $row->execute([$target, $id]);
            $acc = $row->fetch();
            $typed = trim((string)($_POST['confirm_label'] ?? ''));
            if (!$acc) {
                $error = 'That account no longer exists.';
            } elseif ((int)$acc['is_active'] === 1) {
                $error = 'Switch the active account before removing this one.';
            } elseif ($typed !== (string)$acc['account_label']) {
                // The dialog asks for the label to be typed. Enforce it here too,
                // otherwise removing the attribute in devtools skips the check.
                $error = 'Type the account label exactly to confirm removal.';
            } else {
                // Orders reference the wallet with ON DELETE SET NULL. Deleting
                // a wallet that still has pending orders would orphan them:
                // they stay payable but no receipt could ever claim them,
                // because settlement matches on merchant_account_id. Refuse
                // instead, and say how many orders are involved.
                $open = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE merchant_account_id=? AND status='pending'");
                $open->execute([$target]);
                $pendingCount = (int)$open->fetchColumn();
                if ($pendingCount > 0) {
                    $error = 'This wallet still has '.$pendingCount.' pending order'
                        .($pendingCount === 1 ? '' : 's')
                        .'. Wait for them to expire or settle before removing it.';
                } else {
                    $pdo->prepare('DELETE FROM merchant_accounts WHERE id=? AND merchant_id=?')->execute([$target, $id]);
                    $notice = 'Account removed.';
                    tg_audit($id, 'account.removed', (string)$acc['account_label']);
                }
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('multi-accounts: '.$e->getMessage());
        $error = 'That change could not be saved.';
    }
}

$q = $pdo->prepare('SELECT id,account_label,upi_id,payee_name,linked_email,imap_host,imap_port,is_active,last_checked_at,last_error FROM merchant_accounts WHERE merchant_id=? ORDER BY is_active DESC, id');
$q->execute([$id]);
$accounts = $q->fetchAll();
$activeRow = $accounts[0] ?? null;
$standby = array_slice($accounts, 1);

if ($editing > 0 && $editingRow === null) {
    foreach ($accounts as $row) {
        if ((int)$row['id'] === $editing) { $editingRow = $row; break; }
    }
    if ($editingRow === null) $editing = 0;
}
$reauthFresh = tg_reauth_fresh();
$maskedEmail = $activeRow ? tg_mask_email((string)$activeRow['linked_email']) : '';
$otpPending = isset($_SESSION['reauth_otp']) && is_array($_SESSION['reauth_otp']);
$atLimit = count($accounts) >= tg_account_limit();

require __DIR__.'/includes/dashboard-header.php';
?>

<div class="page-title-row" style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-size:22px;font-weight:700;letter-spacing:-.5px;margin:0">Multi-FamPay Account Manager</h1>
    <p style="font-size:13px;color:var(--color-ink-mute);margin:4px 0 0">Connect multiple FamPay wallets and switch active receiving account with 1 click.</p>
  </div>
  <span class="status-badge status-<?=$atLimit ? 'pending' : 'success'?>">
    Add FamPay Account (<?=count($accounts)?>/<?=tg_account_limit()?>)
  </span>
</div>

<?php if ($notice): ?><div class="callout callout-success" style="margin-bottom:18px"><?=icon('check-circle', 16)?><span><?=h($notice)?></span></div><?php endif ?>
<?php if ($error): ?><div class="callout callout-error" style="margin-bottom:18px"><?=icon('alert-circle', 16)?><span><?=h($error)?></span></div><?php endif ?>

<div class="callout callout-info" style="margin-bottom:20px">
  <?=icon('shield', 16)?>
  <span>Additional accounts are for operational continuity only. This tool does not raise, remove or bypass any UPI
    provider or bank limit. Confirm each account is permitted for your business, and test its mailbox before activating it.</span>
</div>

<?php if ($activeRow): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <div>
        <div class="card-title"><?=icon('wallet', 16)?> Active receiving account</div>
        <div class="card-sub">New orders route to this UPI ID.</div>
      </div>
      <span class="status-badge status-success"><?=icon('check', 12)?> Active</span>
    </div>
    <div class="form-grid" style="gap:14px 24px">
      <div><div class="form-hint">Label</div><div style="font-weight:600;font-size:13.5px"><?=h((string)$activeRow['account_label'])?></div></div>
      <div><div class="form-hint">UPI ID</div><div style="font-weight:600;font-size:13.5px"><code><?=h((string)$activeRow['upi_id'])?></code></div></div>
      <div><div class="form-hint">Receipt mailbox</div><div style="font-size:13.5px"><?=h((string)$activeRow['linked_email'])?></div></div>
      <div><div class="form-hint">IMAP</div><div style="font-size:13.5px"><?=h((string)$activeRow['imap_host'])?>:<?=(int)$activeRow['imap_port']?></div></div>
    </div>
    <?php if (!empty($activeRow['last_error'])): ?>
      <div class="status-badge status-failed" style="margin-top:14px"><?=icon('alert-circle', 12)?> Last check failed: <?=h((string)$activeRow['last_error'])?></div>
    <?php endif ?>
    <div style="display:flex;gap:9px;margin-top:16px;flex-wrap:wrap">
      <a class="btn btn-secondary btn-sm" href="?edit=<?=(int)$activeRow['id']?>"><?=icon('settings', 14)?> Edit details</a>
      <a class="btn btn-secondary btn-sm" href="/integrations.php"><?=icon('plug', 14)?> Mailbox test</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="margin-bottom:20px">
    <div class="empty-state">
      <div class="empty-state-icon"><?=icon('wallet', 22)?></div>
      <div class="empty-state-title">No receiving account configured</div>
      <div class="empty-state-sub">Add one below before creating payment links.</div>
    </div>
  </div>
<?php endif ?>

<?php if ($editingRow): ?>
  <?php
  $row = $editingRow;
  // Only the payout UPI and receipt mailbox need re-auth; renaming an account
  // or replacing its app password saves without this check. The panel stays
  // visible because the server cannot know what the merchant is about to type.
  $payoutLocked = !$reauthFresh;
  ?>
  <div class="card" style="margin-bottom:20px;border-color:var(--color-primary)">
    <div class="card-header">
      <div class="card-title"><?=icon('settings', 16)?> Edit <?=h((string)$row['account_label'])?></div>
      <div class="card-sub">Changing the payout UPI or receipt mailbox needs authorisation.</div>
    </div>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?=(int)$row['id']?>">
      <div class="form-group"><label class="form-label">Account Label</label>
        <input class="form-input" name="account_label" required maxlength="100" value="<?=h((string)$row['account_label'])?>"></div>
      <div class="form-group"><label class="form-label">Payee name (optional)</label>
        <input class="form-input" name="payee_name" maxlength="120" value="<?=h((string)($row['payee_name'] ?? ''))?>"></div>
      <div class="form-group"><label class="form-label">UPI ID</label>
        <input class="form-input" name="upi_id" required value="<?=h((string)$row['upi_id'])?>">
        <?php if (tg_upi_domains()): ?><span class="form-hint">Allowed handles: @<?=h(implode(', @', tg_upi_domains()))?></span><?php endif ?></div>
      <div class="form-group"><label class="form-label">Receipt mailbox</label>
        <input class="form-input" type="email" name="linked_email" required value="<?=h((string)$row['linked_email'])?>"></div>
      <div class="form-group"><label class="form-label">IMAP host</label>
        <input class="form-input" name="imap_host" required value="<?=h((string)$row['imap_host'])?>"></div>
      <div class="form-group"><label class="form-label">IMAP port</label>
        <input class="form-input" type="number" name="imap_port" min="1" max="65535" required value="<?=(int)$row['imap_port']?>"></div>
      <div class="form-group" style="grid-column:1/-1"><label class="form-label">Google app password</label>
        <input class="form-input" type="password" name="app_password" autocomplete="new-password" placeholder="Leave blank to keep the stored password">
        <span class="form-hint">16 characters, no spaces. Only replaced when you type a new one.</span></div>
      <div class="form-actions" style="grid-column:1/-1">
        <button class="btn btn-primary">Save Changes</button>
        <a class="btn btn-secondary" href="/multi-accounts.php">Cancel</a>
      </div>
    </form>

    <?php if ($payoutLocked): ?>
      <div style="margin-top:18px;border-top:1px solid var(--color-hairline);padding-top:16px">
        <div class="card-title" style="font-size:13px"><?=icon('shield', 15)?> Security check: authorise UPI change</div>
        <p style="font-size:12.5px;color:var(--color-ink-mute);line-height:1.8;margin:6px 0 12px">
          Modifying the payout UPI ID requires authorisation. Enter your account password,
          or verify with a 6-digit code sent to <strong><?=h($maskedEmail ?: 'your mailbox')?></strong>.
        </p>
        <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start">
          <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="reauth">
            <input type="hidden" name="edit" value="<?=(int)$row['id']?>">
            <div class="form-group" style="min-width:240px"><label class="form-label">Option A: account password</label>
              <input class="form-input" type="password" name="password" required autocomplete="current-password"></div>
            <button class="btn btn-dark btn-sm">Authorise</button>
          </form>

          <div style="border-left:1px solid var(--color-hairline);padding-left:22px;min-width:260px">
            <?php if (($otpPending ?? false)): ?>
              <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="reauth_otp_verify">
                <input type="hidden" name="edit" value="<?=(int)$row['id']?>">
                <div class="form-group" style="min-width:150px"><label class="form-label">Enter the 6-digit code</label>
                  <input class="form-input" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                         autocomplete="one-time-code" required placeholder="000000"></div>
                <button class="btn btn-primary btn-sm">Verify</button>
              </form>
            <?php elseif (tg_mail_transport_available()): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="reauth_otp_send">
                <input type="hidden" name="edit" value="<?=(int)$row['id']?>">
                <div class="form-label" style="margin-bottom:8px">Option B: verify via email OTP</div>
                <button class="btn btn-secondary btn-sm"><?=icon('mail', 14)?> Send OTP to Email</button>
              </form>
            <?php else: ?>
              <div class="form-label" style="margin-bottom:8px">Option B: email OTP unavailable</div>
              <div class="form-hint">No SMTP transport is configured, so only the password works here.</div>
            <?php endif ?>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="callout callout-success" style="margin-top:16px">
        <?=icon('check-circle', 16)?><span>Payout changes are authorised for this session.</span>
      </div>
    <?php endif ?>
  </div>
<?php endif ?>

<div class="card" style="padding:0;overflow:hidden;margin-bottom:20px">
  <div style="padding:18px 24px;border-bottom:1px solid var(--color-hairline);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      <div class="card-title" style="font-size:14px">Standby Accounts (<?=count($standby)?>)</div>
      <div class="card-sub">Tap &ldquo;Add FamPay Account&rdquo; to link your backup wallets.</div>
    </div>
  </div>
  <div class="table-responsive">
    <table>
      <thead><tr><th>Account</th><th>UPI ID</th><th>Receipt mailbox</th><th>State</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php if (!$standby): ?>
        <tr><td colspan="5">
          <div class="empty-state" style="padding:34px 20px">
            <div class="empty-state-icon"><?=icon('layers', 20)?></div>
            <div class="empty-state-title">No standby accounts</div>
            <div class="empty-state-sub">Link a backup wallet below for instant 1-click switch-over.</div>
          </div>
        </td></tr>
      <?php endif ?>
      <?php foreach ($standby as $a): ?>
        <tr>
          <td style="font-weight:600"><?=h((string)$a['account_label'])?></td>
          <td><code style="font-size:12px"><?=h((string)$a['upi_id'])?></code></td>
          <td><?=h((string)$a['linked_email'])?></td>
          <td><span class="status-badge status-expired">Standby</span></td>
          <td style="text-align:right">
            <div style="display:inline-flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="action" value="activate">
                <input type="hidden" name="id" value="<?=(int)$a['id']?>">
                <button class="btn btn-primary btn-sm">Activate</button>
              </form>
              <a class="btn btn-secondary btn-sm" href="?edit=<?=(int)$a['id']?>">Edit</a>
              <button class="btn btn-secondary btn-sm" type="button" title="Remove"
                      data-open-dialog="remove-<?=(int)$a['id']?>"><?=icon('trash', 13)?></button>
            </div>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($atLimit): ?>
  <div class="callout callout-warning" style="margin-bottom:18px">
    <?=icon('alert-triangle', 16)?>
    <span>You have reached the <?=tg_account_limit()?>-account limit. Remove a standby account to add another.</span>
  </div>
<?php endif ?>

<div class="card">
  <div class="card-header">
    <div class="card-title"><?=icon('plus', 16)?> Add FamPay Account</div>
    <div class="card-sub">Link a backup wallet — use a different UPI ID and mailbox from your primary.</div>
  </div>
  <?php if ($atLimit): ?>
    <p style="font-size:13px;color:var(--color-ink-mute);margin:0">Account limit reached.</p>
  <?php else: ?>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="add">
    <div class="form-group"><label class="form-label">Account label / nickname</label>
      <input class="form-input" name="account_label" required maxlength="100" placeholder="Backup wallet"></div>
    <div class="form-group"><label class="form-label">Payee name (optional)</label>
      <input class="form-input" name="payee_name" maxlength="120" placeholder="Shown in the UPI app"></div>
    <div class="form-group"><label class="form-label">UPI ID</label>
      <input class="form-input" name="upi_id" required placeholder="name@bank">
      <?php if (tg_upi_domains()): ?><span class="form-hint">Must end in @<?=h(implode(', @', tg_upi_domains()))?></span>
      <?php else: ?><span class="form-hint">The VPA that receives payments, e.g. name@okhdfcbank</span><?php endif ?></div>
    <div class="form-group"><label class="form-label">Receipt mailbox</label>
      <input class="form-input" type="email" name="linked_email" required placeholder="wallet@example.com"></div>
    <div class="form-group"><label class="form-label">Google app password</label>
      <input class="form-input" type="password" name="app_password" required autocomplete="new-password" placeholder="16 characters">
      <span class="form-hint">Google Account → Security → App passwords. Encrypted with AES-256 at rest.</span></div>
    <div class="form-group"><label class="form-label">IMAP host</label>
      <input class="form-input" name="imap_host" value="imap.gmail.com" required></div>
    <div class="form-group"><label class="form-label">IMAP port</label>
      <input class="form-input" type="number" name="imap_port" min="1" max="65535" value="993" required></div>
    <div class="form-actions" style="grid-column:1/-1">
      <button class="btn btn-primary">Connect &amp; save account</button>
    </div>
  </form>
  <?php endif ?>
</div>

<?php /* Removal modals. Native <dialog>, so there is no JS library to load and
        Escape/backdrop close work without help. The confirmation is typed, not
        clicked, because removing a standby wallet cannot be undone. */ ?>
<?php foreach ($standby as $a): $label = (string)$a['account_label']; ?>
  <dialog class="tg-dialog" id="remove-<?=(int)$a['id']?>">
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?=(int)$a['id']?>">
      <h2 style="margin:0 0 6px;font-size:16px">Remove Account?</h2>
      <p class="form-hint" style="font-size:12.5px;margin:0 0 14px">
        Are you sure you want to remove <strong><?=h($label)?></strong>?
        This wallet will no longer be available for standby switching.
      </p>
      <label class="form-label" for="confirm-remove-<?=(int)$a['id']?>">
        Type the account label to confirm
      </label>
      <input class="form-input" id="confirm-remove-<?=(int)$a['id']?>" name="confirm_label"
             autocomplete="off" placeholder="<?=h($label)?>" required>
      <div class="form-actions" style="margin-top:16px;justify-content:flex-end">
        <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
        <button class="btn btn-danger">Yes, Remove Account</button>
      </div>
    </form>
  </dialog>
<?php endforeach ?>

<script>
/* Tiny dialog controller: [data-open-dialog] opens by id, [data-close-dialog]
   closes the nearest <dialog>, and a click on the backdrop dismisses. */
document.addEventListener('click', (event) => {
  const opener = event.target.closest('[data-open-dialog]');
  if (opener) {
    const dialog = document.getElementById(opener.dataset.openDialog);
    if (dialog && dialog.showModal) dialog.showModal();
    return;
  }
  const closer = event.target.closest('[data-close-dialog]');
  if (closer) {
    const dialog = closer.closest('dialog');
    if (dialog) dialog.close();
    return;
  }
  if (event.target instanceof HTMLDialogElement
      && event.target.getBoundingClientRect
      && event.clientX === 0 && event.clientY === 0) {
    event.target.close();
  }
});
</script>

<?php require __DIR__.'/includes/dashboard-footer.php'; ?>
