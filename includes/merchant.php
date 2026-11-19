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

/* tg_table_exists() lives in db.php so the migration runner can use it during boot. */

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
        // Payment-link states reuse the same pill styling with their own labels.
        'active'   => ['success', 'Active'],
        'inactive' => ['expired', 'Inactive'],
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

/**
 * Record a merchant-visible action. Best-effort: auditing must never break the
 * request it is describing, so a failure is logged and swallowed.
 */
function tg_audit(?int $merchantId, string $action, string $detail = ''): void {
    try {
        if (!tg_table_exists('audit_log')) return;
        $q = db()->prepare('INSERT INTO audit_log(merchant_id,action,detail,ip,created_at) VALUES(?,?,?,?,?)');
        $q->execute([$merchantId, $action, mb_substr($detail, 0, 255), function_exists('client_ip') ? client_ip() : null, db_now()]);
    } catch (Throwable $e) {
        error_log('audit: '.$e->getMessage());
    }
}

/* ============================================================
   Receiving accounts: validation, caps and payout re-auth
   ============================================================ */

/** Maximum number of receiving accounts a merchant may hold. */
function tg_account_limit(): int {
    return max(1, (int)envv('MAX_RECEIVING_ACCOUNTS', '5'));
}

/**
 * UPI domains the operator allows, or [] for any well-formed VPA.
 *
 * The upstream product is FamPay-specific and only accepts @fam/@yesfam. This
 * gateway is provider-agnostic, so the default stays permissive and an operator
 * can lock it down (e.g. `UPI_ID_DOMAINS=fam,yesfam`) without a code change.
 *
 * @return list<string>
 */
function tg_upi_domains(): array {
    $raw = trim((string)envv('UPI_ID_DOMAINS', ''));
    if ($raw === '') return [];
    return array_values(array_filter(array_map(
        static fn (string $d): string => strtolower(trim($d, " \t@")),
        explode(',', $raw)
    )));
}

/**
 * Validate a UPI VPA. Returns null when valid, otherwise an operator-facing
 * message explaining exactly what is wrong.
 */
function tg_validate_upi(string $upi): ?string {
    if ($upi === '') return 'Enter the UPI ID that receives the payments.';
    if (strlen($upi) > 190) return 'That UPI ID is too long.';
    // VPA grammar: local@handle, both sides limited to the characters the NPCI
    // apps themselves accept. Rejecting anything else early beats a failed
    // payment against a typo'd handle.
    if (!preg_match('/^[A-Za-z0-9._-]{2,128}@[A-Za-z0-9.-]{2,190}$/', $upi)) {
        return 'That does not look like a UPI ID. Use the form name@bank, for example name@okhdfcbank.';
    }
    $allowed = tg_upi_domains();
    if ($allowed !== []) {
        $domain = strtolower(substr($upi, strrpos($upi, '@') + 1));
        $root = explode('.', $domain)[0];
        if (!in_array($domain, $allowed, true) && !in_array($root, $allowed, true)) {
            return 'This deployment only accepts UPI IDs ending in @'.implode(', @', $allowed).'.';
        }
    }
    return null;
}

/** How many receiving accounts the merchant currently holds. */
function tg_account_count(int $merchantId): int {
    $q = db()->prepare('SELECT COUNT(*) FROM merchant_accounts WHERE merchant_id=?');
    $q->execute([$merchantId]);
    return (int)$q->fetchColumn();
}

/**
 * Has this session proven the merchant's password recently?
 *
 * Uses a short-lived session stamp rather than a per-request check so the user
 * authorises once and can then make several payout edits without re-typing.
 */
function tg_reauth_fresh(int $window = 900): bool {
    return (int)($_SESSION['reauth_at'] ?? 0) >= time() - $window;
}

/** Verify a password and, on success, stamp the session. */
function tg_reauth_password(int $merchantId, string $password): bool {
    $q = db()->prepare('SELECT password FROM merchants WHERE id=?');
    $q->execute([$merchantId]);
    $hash = (string)$q->fetchColumn();
    if ($hash === '' || !password_verify($password, $hash)) return false;
    $_SESSION['reauth_at'] = time();
    return true;
}

/** Drop the re-auth stamp (called after a payout change lands). */
function tg_reauth_clear(): void {
    unset($_SESSION['reauth_at']);
}

/** Mask an email for display: s****7@gmail.com */
function tg_mask_email(string $email): string {
    $at = strrpos($email, '@');
    if ($at === false || $at < 1) return $email;
    $local = substr($email, 0, $at);
    $domain = substr($email, $at);
    if (strlen($local) <= 2) return $local[0].'***'.$domain;
    return $local[0].str_repeat('*', max(3, strlen($local) - 2)).substr($local, -1).$domain;
}

/** Mask an email for display: shadowgamerind37@… → s**************7@gmail.com */
function tg_masked_email(string $email): string {
    $at = strrpos($email, '@');
    if ($at === false || $at < 1) return $email;
    $local = substr($email, 0, $at);
    $domain = substr($email, $at);
    if (strlen($local) <= 2) return $local[0].'***'.$domain;
    return $local[0].str_repeat('*', max(3, strlen($local) - 2)).substr($local, -1).$domain;
}
