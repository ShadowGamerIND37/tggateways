<?php
declare(strict_types=1);
/**
 * Payment Links — parity with the FamGateway page:
 * a "Generate Payment Link" card, a "My Payment Links" table
 * (slug, amount, status, created, expires, actions) and the
 * "gateway not configured" call to action.
 */
require_once __DIR__.'/includes/merchant.php';
require_once __DIR__.'/includes/auth.php';
$merchant = require_login();
$pageTitle = 'Payment Links';
$pdo = db();
$id = (int)$merchant['id'];
$notice = '';
$noticeType = 'success';

$configured = tg_has_active_account($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit('payment-links', 20, 300);
    $action = (string)($_POST['action'] ?? 'create');

    if ($action === 'deactivate' || $action === 'activate') {
        // Only ever touch this merchant's own row, and only flip the flag.
        $slug = substr((string)($_POST['slug'] ?? ''), 0, 100);
        $on = $action === 'activate' ? 1 : 0;
        $q = $pdo->prepare('UPDATE payment_links SET is_active=? WHERE merchant_id=? AND slug=?');
        $q->execute([$on, $id, $slug]);
        if ($q->rowCount() > 0) {
            $notice = $action === 'activate' ? 'Link reactivated.' : 'Link deactivated. Anyone opening it now sees an error.';
            tg_audit($id, $action === 'activate' ? 'link.activated' : 'link.deactivated', $slug);
        } else {
            $notice = 'That link was not found on this account.';
            $noticeType = 'error';
        }
    } else {
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160);
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $days = (int)($_POST['expiry_days'] ?? 0);
        $redirect = trim((string)($_POST['redirect_url'] ?? ''));
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 1500);
        $custom = strtolower(trim((string)($_POST['slug'] ?? '')));

        $problem = null;
        if (!$configured) {
            $problem = 'Configure a receiving account under Integrations before creating links.';
        } elseif ($title === '') {
            $problem = 'Give the link a title so you can recognise it later.';
        } elseif ($amount === false || $amount < 1 || $amount > 1000000) {
            $problem = 'Enter an amount between ₹1 and ₹10,00,000.';
        } elseif (!in_array($days, [0, 1, 7, 30], true)) {
            $problem = 'Choose one of the offered expiry windows.';
        } elseif ($redirect !== '' && !valid_http_url($redirect)) {
            $problem = 'The redirect URL must be a valid HTTPS address.';
        } elseif ($custom !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{2,60}$/', $custom)) {
            $problem = 'Custom slugs are 3–61 characters: lowercase letters, numbers and hyphens.';
        }

        if ($problem !== null) {
            $notice = $problem;
            $noticeType = 'error';
        } else {
            $slug = $custom !== '' ? $custom : bin2hex(random_bytes(9));
            $taken = $pdo->prepare('SELECT 1 FROM payment_links WHERE slug=?');
            $taken->execute([$slug]);
            if ($taken->fetchColumn()) {
                $notice = 'That slug is already taken. Pick another.';
                $noticeType = 'error';
            } else {
                $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
                $q = $pdo->prepare('INSERT INTO payment_links(merchant_id,slug,title,amount,description,redirect_url,is_active,expires_at,created_at)
                                    VALUES(?,?,?,?,?,?,1,?,?)');
                $q->execute([$id, $slug, $title, number_format((float)$amount, 2, '.', ''), $description ?: null,
                    $redirect ?: null, $expires, date('Y-m-d H:i:s')]);
                $notice = 'Payment link created. Share it with your customer.';
                tg_audit($id, 'link.created', $slug.' ₹'.number_format((float)$amount, 2));
            }
        }
    }
}

$q = $pdo->prepare('SELECT slug,title,amount,description,redirect_url,is_active,expires_at,created_at
                      FROM payment_links WHERE merchant_id=? ORDER BY created_at DESC, id DESC LIMIT 100');
$q->execute([$id]);
$links = $q->fetchAll();

/** Public URL for a link, honouring APP_URL when it is set. */
$linkUrl = function (string $slug): string {
    $base = trim((string)envv('APP_URL', ''));
    return ($base !== '' ? rtrim($base, '/') : '') . '/pay.php?link='.rawurlencode($slug);
};

/** Derived display state: expired beats inactive beats active. */
$linkState = function (array $link): string {
    if ($link['expires_at'] && strtotime((string)$link['expires_at']) < time()) return 'Expired';
    if (!(int)$link['is_active']) return 'Inactive';
    return 'Active';
};

require __DIR__.'/includes/dashboard-header.php';
?>

<div class="page-title-row dash-anim-right is-visible" style="margin-bottom:24px">
  <div>
    <h1 style="font-size:22px;font-weight:700;color:var(--color-ink);letter-spacing:-.5px">Payment Links</h1>
    <p style="font-size:13px;color:var(--color-ink-mute);margin:4px 0 0">Create and manage checkout URLs to share with your customers.</p>
  </div>
</div>

<?php if ($notice): ?>
  <div class="callout callout-<?=$noticeType === 'error' ? 'danger' : 'success'?>" style="margin-bottom:18px">
    <?=icon($noticeType === 'error' ? 'alert-circle' : 'check-circle', 16)?><span><?=h($notice)?></span>
  </div>
<?php endif ?>

<?php if (!$configured): ?>
  <div class="callout callout-warning" style="margin-bottom:18px">
    <?=icon('alert-triangle', 16)?>
    <span><strong>Payment Gateway Not Configured.</strong>
      Please configure your receipt mailbox and UPI settings to start creating payment links.</span>
    <a class="btn btn-primary btn-sm" style="margin-left:auto" href="/integrations.php">Configure Now</a>
  </div>
<?php endif ?>

<div class="dashboard-bottom-grid" style="align-items:start">

  <!-- Generate -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title"><?=icon('link', 16)?> Generate Payment Link</div>
        <div class="card-sub">Create an instant checkout link with a fixed amount to share with your customers.</div>
      </div>
    </div>
    <form method="post" class="form-grid" style="margin-top:4px">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="create">
      <div class="form-group">
        <label for="link-title">Link title</label>
        <input class="form-input" id="link-title" name="title" maxlength="160" placeholder="e.g. Design consultation" required>
      </div>
      <div class="form-group">
        <label for="link-amount">Amount (INR)</label>
        <input class="form-input" id="link-amount" type="number" min="1" max="1000000" step="0.01" name="amount" placeholder="499.00" required>
      </div>
      <div class="form-group">
        <label for="link-slug">Custom slug <span class="form-hint" style="display:inline">(optional)</span></label>
        <input class="form-input" id="link-slug" name="slug" maxlength="61" pattern="[a-z0-9][a-z0-9\-]{2,60}"
               placeholder="leave blank for a random id">
        <span class="form-hint">Your URL becomes <code>/pay.php?link=&lt;slug&gt;</code>.</span>
      </div>
      <div class="form-group">
        <label for="link-expiry">Expiry</label>
        <select class="form-input" id="link-expiry" name="expiry_days">
          <option value="0">No expiry</option>
          <option value="1">1 day</option>
          <option value="7">7 days</option>
          <option value="30">30 days</option>
        </select>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label for="link-redirect">Post-payment redirect URL <span class="form-hint" style="display:inline">(optional)</span></label>
        <input class="form-input" id="link-redirect" type="url" name="redirect_url" placeholder="https://your-site.example/thank-you">
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label for="link-desc">Description <span class="form-hint" style="display:inline">(optional)</span></label>
        <textarea class="form-input" id="link-desc" name="description" rows="2" maxlength="1500"
                  placeholder="Shown to your customer on the checkout page"></textarea>
      </div>
      <div class="form-actions" style="grid-column:1/-1">
        <button class="btn btn-primary" <?=$configured ? '' : 'disabled title="Configure a receiving account first"'?>>
          <?=icon('link', 14)?> Generate Payment Link
        </button>
      </div>
    </form>
  </div>

  <!-- List -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="card-header" style="padding:22px 24px;border-bottom:1px solid var(--color-hairline)">
      <div>
        <div class="card-title"><?=icon('list', 16)?> My Payment Links</div>
        <div class="card-sub">Manage your active and expired payment links.</div>
      </div>
      <span class="badge badge-neutral"><?=count($links)?> total</span>
    </div>
    <?php if (!$links): ?>
      <div style="padding:54px 24px;text-align:center">
        <div style="color:var(--color-ink-mute-2);margin-bottom:10px"><?=icon('link', 30)?></div>
        <div style="font-weight:600;font-size:14px">No payment links generated yet</div>
        <div class="form-hint" style="margin-top:6px">Create your custom payment link to get started.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>Link (Slug)</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Created</th>
              <th>Expires</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($links as $l): $state = $linkState($l); $url = $linkUrl((string)$l['slug']); ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:13px"><?=h((string)$l['title'])?></div>
                <div class="form-hint"><code><?=h((string)$l['slug'])?></code></div>
              </td>
              <td style="font-variant-numeric:tabular-nums">₹<?=number_format((float)$l['amount'], 2)?></td>
              <td><?=tg_status_badge(strtolower($state))?></td>
              <td class="form-hint"><?=h(date('d M Y', strtotime((string)$l['created_at'])))?></td>
              <td class="form-hint"><?=h($l['expires_at'] ? date('d M Y', strtotime((string)$l['expires_at'])) : 'Never')?></td>
              <td style="text-align:right;white-space:nowrap">
                <?php if ($state === 'Active'): ?>
                  <button class="btn btn-secondary btn-sm" type="button"
                          data-copy-value="<?=h($url)?>"
                          onclick="navigator.clipboard.writeText(this.dataset.copyValue);const t=this.textContent;this.textContent='Copied';setTimeout(()=>this.textContent=t,1200)">
                    <?=icon('copy', 13)?> Copy
                  </button>
                  <a class="btn btn-secondary btn-sm" href="<?=h($url)?>" target="_blank" rel="noopener"><?=icon('external-link', 13)?> Open</a>
                <?php endif ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Are you sure you want to proceed?')">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="slug" value="<?=h((string)$l['slug'])?>">
                  <input type="hidden" name="action" value="<?=(int)$l['is_active'] ? 'deactivate' : 'activate'?>">
                  <button class="btn btn-secondary btn-sm"><?=(int)$l['is_active'] ? 'Deactivate' : 'Activate'?></button>
                </form>
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </div>
</div>

<?php require __DIR__.'/includes/dashboard-footer.php'; ?>
