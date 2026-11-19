<?php
declare(strict_types=1);
/**
 * Merchant-level helpers shared by the dashboard pages.
 *
 * Kept separate from auth.php so the session/CSRF primitives stay small and
 * auditable; these functions are presentation concerns (merchant id, avatar,
 * display name) plus one schema-aware "do the commerce tables exist yet?" probe
 * that keeps pages usable before the SQLite/MySQL migration has run.
 */
require_once __DIR__.'/db.php';

/**
 * Public merchant id — the "MID" shown in the sidebar and on receipts.
 *
 * Derived from the row id so it is stable and needs no extra column: ten
 * digits, no leading zero (matching the 10-digit MIDs the reference shows).
 */
function tg_merchant_mid(int $merchantId): string {
    return (string)(7576900000 + $merchantId);
}

/** Data-URI avatar for a merchant, or '' when none was uploaded. */
function tg_merchant_avatar(int $merchantId): string {
    if (!tg_table_exists('merchant_profiles')) return '';
    $s = db()->prepare('SELECT avatar FROM merchant_profiles WHERE merchant_id=?');
    $s->execute([$merchantId]);
    $avatar = (string)$s->fetchColumn();
    return $avatar === '' ? '' : $avatar;
}

/**
 * True when a table exists. Lets pages render a "run the migration" callout
 * instead of fataling on an older database.
 */
function tg_table_exists(string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        if (db_driver() === 'sqlite') {
            $s = db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $s->execute([$table]);
            return $cache[$table] = (bool)$s->fetchColumn();
        }
        $s = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $s->execute([$table]);
        return $cache[$table] = (int)$s->fetchColumn() > 0;
    } catch (Throwable) {
        return $cache[$table] = false;
    }
}

/** True when the merchant has at least one active receiving account. */
function tg_has_active_account(int $merchantId): bool {
    $s = db()->prepare('SELECT COUNT(*) FROM merchant_accounts WHERE merchant_id=? AND is_active=1');
    $s->execute([$merchantId]);
    return (int)$s->fetchColumn() > 0;
}

/**
 * Human-friendly relative time ("2 minutes ago") used in tables where an
 * absolute timestamp would widen the column too much.
 */
function tg_time_ago(?string $timestamp): string {
    if ($timestamp === null || $timestamp === '') return '—';
    $ts = strtotime($timestamp);
    if ($ts === false) return '—';
    $delta = time() - $ts;
    if ($delta < 60) return 'just now';
    foreach ([[31536000, 'year'], [2592000, 'month'], [604800, 'week'], [86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$unit, $label]) {
        if ($delta >= $unit) {
            $n = (int)floor($delta / $unit);
            return $n.' '.$label.($n === 1 ? '' : 's').' ago';
        }
    }
    return 'just now';
}

/** Render an order status as the reference's coloured badge. */
function tg_status_badge(string $status): string {
    $map = [
        'success' => ['success', 'Captured'],
        'pending' => ['pending', 'Created'],
        'expired' => ['expired', 'Expired'],
        'failed'  => ['failed', 'Failed'],
    ];
    [$kind, $label] = $map[strtolower($status)] ?? ['expired', ucfirst($status)];
    return '<span class="status-badge status-'.$kind.'">'.h($label).'</span>';
}

/** Time-of-day greeting used by the dashboard heading. */
function tg_greeting(?int $timestamp = null): string {
    $hour = (int)date('G', $timestamp ?? time());
    if ($hour < 12) return 'Good morning';
    if ($hour < 17) return 'Good afternoon';
    return 'Good evening';
}
