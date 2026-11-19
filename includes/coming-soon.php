<?php
declare(strict_types=1);
/**
 * Stock dashboard pages that are scheduled for a later slice.
 *
 * Returning a real 200 with the full merchant chrome (rather than a bare
 * "not found") matters here: the sidebar now links to these destinations, and
 * dead links in a payment product look like a broken deployment. Each page
 * states plainly what it is, what already exists elsewhere, and when it lands.
 */
require_once __DIR__.'/auth.php';
require_once __DIR__.'/icons.php';

/**
 * @param array{title:string,lead:string,done:list<string>,next:list<string>} $spec
 */
function tg_coming_soon(array $spec): void {
    $merchant = require_login();
    $pageTitle = $spec['title'];
    require __DIR__.'/dashboard-header.php';
    ?>
    <div class="page-title-row" style="margin-bottom:24px">
      <h1 style="font-size:22px;font-weight:700;color:var(--color-ink);letter-spacing:-.5px"><?=h($spec['title'])?></h1>
      <p style="font-size:13px;color:var(--color-ink-mute);margin-top:4px"><?=h($spec['lead'])?></p>
    </div>
    <div class="card" style="max-width:720px">
      <div class="card-header">
        <div class="card-title"><?=icon('clock', 16)?> <span>Being built</span></div>
        <div class="card-sub">This slice ships with the schema and UI work in progress.</div>
      </div>
      <div class="dashboard-bottom-grid" style="grid-template-columns:1fr 1fr">
        <div>
          <div class="field-label" style="font-weight:600;margin-bottom:8px;font-size:12px">Already available</div>
          <ul style="margin:0;padding-left:18px;font-size:13px;line-height:2;color:var(--color-ink-mute)">
            <?php foreach ($spec['done'] as $item): ?><li><?=h($item)?></li><?php endforeach ?>
          </ul>
        </div>
        <div>
          <div class="field-label" style="font-weight:600;margin-bottom:8px;font-size:12px">Landing next</div>
          <ul style="margin:0;padding-left:18px;font-size:13px;line-height:2;color:var(--color-ink-mute)">
            <?php foreach ($spec['next'] as $item): ?><li><?=h($item)?></li><?php endforeach ?>
          </ul>
        </div>
      </div>
      <div style="margin-top:22px;display:flex;gap:10px;flex-wrap:wrap">
        <a href="/dashboard.php" class="btn btn-primary btn-sm"><?=icon('arrow-right', 14)?> Back to dashboard</a>
        <a href="/tickets.php" class="btn btn-secondary btn-sm"><?=icon('life-buoy', 14)?> Contact support</a>
      </div>
    </div>
    <?php
    require __DIR__.'/dashboard-footer.php';
}
