<?php
declare(strict_types=1);
/**
 * Split-screen shell shared by login, register and the OTP / 2FA steps.
 *
 * Left panel is the branded marketing side from the reference design; right
 * panel holds the form. On narrow screens the left panel collapses (see
 * `.auth-left { display:none }` in the design system) and the form gains a
 * small logo, so the page works on a phone without horizontal scrolling.
 */
require_once __DIR__.'/auth.php';
require_once __DIR__.'/icons.php';

/**
 * @param string $title    Browser title.
 * @param string $heading  Left-panel headline (plain text; `$accent` is highlighted).
 * @param string $accent   Word inside the headline to colour.
 */
function tg_auth_header(string $title, string $heading, string $accent, string $lead, array $features): void {
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> — TG Gateways</title>
<link rel="stylesheet" href="/assets/css/design-system.css">
<link rel="icon" href="/assets/img/logo.svg" type="image/svg+xml">
<script defer src="/assets/js/main.js"></script>
</head>
<body>
<div class="auth-shell">
  <aside class="auth-left">
    <a href="/" class="auth-left-brand">
      <img src="/assets/img/logo.svg" alt="" style="height:32px;width:32px">
      TG Gateways
    </a>
    <div style="position:relative;z-index:1">
      <h2><?php
        $pos = strpos($heading, $accent);
        if ($pos === false) {
            echo h($heading);
        } else {
            echo h(substr($heading, 0, $pos)).'<span>'.h($accent).'</span>'.h(substr($heading, $pos + strlen($accent)));
        }
      ?></h2>
      <p><?=h($lead)?></p>
      <div class="auth-features">
        <?php foreach ($features as $feature): ?>
          <div class="auth-feature">
            <span class="auth-feature-dot"><?=icon('check', 12)?></span>
            <?=h($feature)?>
          </div>
        <?php endforeach ?>
      </div>
    </div>
    <div class="auth-copyright">© <?=date('Y')?> TG Gateways · Self-hosted UPI payment gateway</div>
  </aside>
  <main class="auth-right">
    <div class="auth-form-card">
      <a href="/" class="auth-form-logo" style="align-items:center;gap:10px;font-weight:700;font-size:16px;margin-bottom:22px">
        <img src="/assets/img/logo.svg" alt="" style="height:30px;width:30px"> TG Gateways
      </a>
<?php
}

/** Close the shell and the document. */
function tg_auth_footer(): void {
    ?>
    </div>
  </main>
</div>
</body>
</html>
<?php
}

/** A dismissible error banner used by every auth form. */
function tg_auth_error(string $message): string {
    if ($message === '') return '';
    return '<div class="alert-error">'.icon('alert-circle', 15).'<span>'.h($message).'</span></div>';
}
