<?php
declare(strict_types=1);
/**
 * Integrations — parity with the FamGateway page:
 * a "Gmail & FamPay" card with a Verify & Connect action, a four-step
 * app-password guide, a connection test and a disconnect confirmation.
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/crypto.php';
require_once __DIR__.'/includes/imap.php';
$merchant = require_login();
$pageTitle = 'Integrations';
$pdo = db();
$id = (int)$merchant['id'];
$notice = '';
$noticeType = 'success';
$test = null;

$q = $pdo->prepare('SELECT id,account_label,upi_id,linked_email,imap_host,imap_port,is_active,last_checked_at,last_error
                      FROM merchant_accounts WHERE merchant_id=? ORDER BY is_active DESC, id LIMIT 1');
$q->execute([$id]);
$account = $q->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('integrations', 30, 300);
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'test') {
        $row = $pdo->prepare('SELECT merchant_id,linked_email,imap_host,imap_port,google_app_password FROM merchant_accounts WHERE merchant_id=? ORDER BY id LIMIT 1');
        $row->execute([$id]);
        $acc = $row->fetch();
        $test = $acc ? imap_test_connection($acc) : ['ok' => false, 'message' => 'Save a receiving account before testing.'];
    } elseif ($action === 'disconnect') {
        // Clearing the stored password stops receipt polling without deleting
        // the account, so nothing else about the setup is lost.
        if (!$account) {
            $notice = 'There is nothing to disconnect yet.';
            $noticeType = 'error';
        } elseif (trim((string)($_POST['confirm_label'] ?? '')) !== (string)$account['linked_email']) {
            $notice = 'Type the mailbox address exactly to confirm the disconnect.';
            $noticeType = 'error';
        } else {
            $pdo->prepare("UPDATE merchant_accounts SET google_app_password='' WHERE id=? AND merchant_id=?")
                ->execute([(int)$account['id'], $id]);
            $notice = 'Mailbox disconnected. Automated payment verification is paused until you reconnect.';
            tg_audit($id, 'integration.disconnected', (string)$account['linked_email']);
            $q->execute([$id]);
            $account = $q->fetch();
        }
    } else {
        $label = trim((string)($_POST['account_label'] ?? '')) ?: 'Primary UPI';
        $upi = trim((string)($_POST['upi_id'] ?? ''));
        $email = filter_var($_POST['linked_email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pass = preg_replace('/\s+/', '', (string)($_POST['app_password'] ?? '')) ?? '';
        $upiProblem = tg_validate_upi($upi);
        $imapHost = trim((string)($_POST['imap_host'] ?? '')) ?: 'imap.gmail.com';
        $imapPort = (int)($_POST['imap_port'] ?? 993) ?: 993;

        if ($upiProblem !== null) {
            $notice = $upiProblem;
            $noticeType = 'error';
        } elseif (!$email) {
            $notice = 'Enter the Gmail address that receives the payment alerts.';
            $noticeType = 'error';
        } elseif ($pass !== '' && !preg_match('/^[A-Za-z0-9]{16}$/', $pass)) {
            $notice = 'A Google app password is exactly 16 characters with no spaces.';
            $noticeType = 'error';
        } elseif (!preg_match('/^[A-Za-z0-9.-]{3,190}$/', $imapHost) || $imapPort < 1 || $imapPort > 65535) {
            $notice = 'Enter a valid IMAP host and port.';
            $noticeType = 'error';
        } else {
            try {
                $existing = $pdo->prepare('SELECT id FROM merchant_accounts WHERE merchant_id=? ORDER BY id LIMIT 1');
                $existing->execute([$id]);
                $row = $existing->fetch();
                if ($row) {
                    if ($pass !== '') {
                        $pdo->prepare('UPDATE merchant_accounts SET account_label=?,upi_id=?,linked_email=?,imap_host=?,imap_port=?,google_app_password=? WHERE id=? AND merchant_id=?')
                            ->execute([$label, $upi, $email, $imapHost, $imapPort, encrypt_secret($pass), $row['id'], $id]);
                    } else {
                        $pdo->prepare('UPDATE merchant_accounts SET account_label=?,upi_id=?,linked_email=?,imap_host=?,imap_port=? WHERE id=? AND merchant_id=?')
                            ->execute([$label, $upi, $email, $imapHost, $imapPort, $row['id'], $id]);
                    }
                } else {
                    if ($pass === '') throw new RuntimeException('An app password is required for the first connection.');
                    $pdo->prepare('INSERT INTO merchant_accounts(merchant_id,account_label,upi_id,linked_email,imap_host,imap_port,google_app_password,is_active)
                                   VALUES(?,?,?,?,?,?,?,1)')
                        ->execute([$id, $label, $upi, $email, $imapHost, $imapPort, encrypt_secret($pass)]);
                }
                $notice = 'Connected. Run the mailbox test, then confirm a real receipt is recognised before accepting live payments.';
                tg_audit($id, 'integration.connected', $upi);
                $q->execute([$id]);
                $account = $q->fetch();
            } catch (Throwable $e) {
                error_log('Integration save failed: '.$e->getMessage());
                $notice = 'Could not save settings. Confirm the encryption key and database configuration.';
                $noticeType = 'error';
            }
        }
    }
}

// A stored password means the mailbox can be polled; the page never decrypts it.
$hasPassword = false;
if ($account) {
    $pw = $pdo->prepare('SELECT google_app_password FROM merchant_accounts WHERE id=?');
    $pw->execute([(int)$account['id']]);
    $hasPassword = trim((string)$pw->fetchColumn()) !== '';
}

require __DIR__.'/includes/dashboard-header.php';
?>

<div class="page-title-row dash-anim-right is-visible" style="margin-bottom:24px">
  <div>
    <h1 style="font-size:22px;font-weight:700;color:var(--color-ink);letter-spacing:-.5px">Integrations</h1>
    <p style="font-size:13px;color:var(--color-ink-mute);margin:4px 0 0">Connect your FamPay Gmail to automate real-time UPI payment verification.</p>
  </div>
</div>

<?php if ($notice): ?>
  <div class="callout callout-<?=$noticeType === 'error' ? 'danger' : 'success'?>" style="margin-bottom:18px">
    <?=icon($noticeType === 'error' ? 'alert-circle' : 'check-circle', 16)?><span><?=h($notice)?></span>
  </div>
<?php endif ?>

<div class="dashboard-bottom-grid" style="align-items:start">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <div>
        <div class="card-title"><?=icon('mail', 16)?> Gmail &amp; FamPay</div>
        <div class="card-sub">Reads FamPay transaction alert emails via IMAP.</div>
      </div>
      <span class="status-badge status-<?=$hasPassword ? 'success' : 'expired'?>">
        <?=$hasPassword ? 'Connected' : 'Not Connected'?>
      </span>
    </div>

    <form method="post" class="form-grid" style="margin-top:4px">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="save">
      <div class="form-group">
        <label for="int-label">Account label</label>
        <input id="int-label" class="form-input" name="account_label" maxlength="100"
               value="<?=h((string)($account['account_label'] ?? 'Primary UPI'))?>">
      </div>
      <div class="form-group">
        <label for="int-upi">FamPay UPI ID</label>
        <input id="int-upi" class="form-input" name="upi_id" required placeholder="name@fam"
               value="<?=h((string)($account['upi_id'] ?? ''))?>">
        <?php if (tg_upi_domains()): ?>
          <span class="form-hint">Must end with @<?=h(implode(' or @', tg_upi_domains()))?></span>
        <?php else: ?>
          <span class="form-hint">The VPA that receives payments, e.g. name@fam</span>
        <?php endif ?>
      </div>
      <div class="form-group">
        <label for="int-email">FamPay-linked Gmail address</label>
        <input id="int-email" class="form-input" type="email" name="linked_email" required
               value="<?=h((string)($account['linked_email'] ?? ''))?>">
      </div>
      <div class="form-group">
        <label for="int-pass">Google app password (16 characters)</label>
        <input id="int-pass" class="form-input" type="password" name="app_password" autocomplete="new-password"
               pattern="[A-Za-z0-9 ]{16,20}" placeholder="<?=$hasPassword ? 'Leave blank to keep the stored one' : '16 characters'?>"
               <?=$hasPassword ? '' : 'required'?>>
        <span class="form-hint">Requires IMAP enabled in Gmail. Stored encrypted with AES-256.</span>
      </div>
      <div class="form-group">
        <label for="int-host">IMAP host</label>
        <input id="int-host" class="form-input" name="imap_host" required
               value="<?=h((string)($account['imap_host'] ?? 'imap.gmail.com'))?>">
      </div>
      <div class="form-group">
        <label for="int-port">IMAP port</label>
        <input id="int-port" class="form-input" type="number" min="1" max="65535" name="imap_port" required
               value="<?=(int)($account['imap_port'] ?? 993)?>">
      </div>
      <div class="form-actions" style="grid-column:1/-1">
        <button class="btn btn-primary"><?=icon('plug', 14)?> Verify &amp; Connect</button>
        <?php if ($hasPassword): ?>
          <button class="btn btn-secondary" type="submit" form="disconnect-form">Disconnect</button>
        <?php endif ?>
      </div>
    </form>

    <?php if ($account): ?>
      <div style="margin-top:16px;border-top:1px solid var(--color-hairline);padding-top:14px">
        <div class="form-hint">Last parser check: <?=h($account['last_checked_at'] ?: 'Not checked yet')?></div>
        <?php if (!empty($account['last_error'])): ?>
          <div class="form-hint" style="color:var(--color-danger-text);margin-top:4px">Last error: <?=h((string)$account['last_error'])?></div>
        <?php endif ?>
      </div>
    <?php endif ?>
  </div>

  <div>
    <div class="card" style="margin-bottom:20px">
      <div class="card-header">
        <div>
          <div class="card-title"><?=icon('list', 16)?> Setup Instructions</div>
          <div class="card-sub">Automated payment verification reads FamPay transaction alert emails via IMAP.</div>
        </div>
      </div>
      <div class="callout callout-info" style="margin-top:0">
        <?=icon('shield', 16)?>
        <span>Use a dedicated mailbox with only the access needed for payment receipts. The app password
          grants mailbox access, not just receipt access; revoke it when no longer required.</span>
      </div>
      <ol class="tg-steps" style="margin:16px 0 0">
        <li>
          <strong>Turn on 2-Step Verification</strong>
          <span>Enable it in your Google Security settings for the mailbox that receives FamPay alerts.</span>
        </li>
        <li>
          <strong>Enable IMAP in Gmail</strong>
          <span>Gmail settings → Forwarding &amp; POP/IMAP → Enable IMAP, then save.</span>
        </li>
        <li>
          <strong>Generate a 16-digit app password</strong>
          <span>Google Account → App passwords, name it after this deployment, and copy the 16-character code.</span>
        </li>
        <li>
          <strong>Paste it in the connection setup</strong>
          <span>Enter the Gmail address, app password and FamPay UPI ID, then press Verify &amp; Connect.</span>
        </li>
      </ol>
    </div>

    <div class="card">
      <div class="card-header">
        <div>
          <div class="card-title"><?=icon('activity', 16)?> Connection test</div>
          <div class="card-sub">Saves nothing. Opens the mailbox read-only and reports whether the credentials work.</div>
        </div>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="test">
        <button class="btn btn-secondary"><?=icon('refresh-cw', 14)?> Test mailbox connection</button>
      </form>
      <?php if ($test): ?>
        <div class="callout callout-<?=$test['ok'] ? 'success' : 'danger'?>" style="margin-top:14px;margin-bottom:0">
          <?=icon($test['ok'] ? 'check-circle' : 'alert-circle', 16)?><span><?=h($test['message'])?></span>
        </div>
      <?php endif ?>
    </div>
  </div>
</div>

<?php if ($hasPassword && $account): ?>
  <dialog class="tg-dialog" id="disconnect-dialog">
    <form method="post" id="disconnect-form">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="disconnect">
      <h2 style="margin:0 0 6px;font-size:16px">Disconnect FamPay Gmail?</h2>
      <p class="form-hint" style="font-size:12.5px;margin:0 0 14px">
        Automated payment verifications will pause immediately until you reconnect your credentials.
        Type <strong><?=h((string)$account['linked_email'])?></strong> to confirm.
      </p>
      <input class="form-input" name="confirm_label" autocomplete="off" required
             placeholder="<?=h((string)$account['linked_email'])?>">
      <div class="form-actions" style="margin-top:16px;justify-content:flex-end">
        <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
        <button class="btn btn-danger">Yes, Disconnect</button>
      </div>
    </form>
  </dialog>
  <script>
  document.querySelector('button[form="disconnect-form"]')?.addEventListener('click', (event) => {
    event.preventDefault();
    document.getElementById('disconnect-dialog')?.showModal();
  });
  </script>
<?php endif ?>

<?php require __DIR__.'/includes/dashboard-footer.php'; ?>
