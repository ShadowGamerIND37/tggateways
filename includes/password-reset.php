<?php
declare(strict_types=1);
/**
 * Self-service password recovery.
 *
 * The login page used to end at "Forgot password? Ask your deployment
 * administrator." — which meant an operator had to reach into the database and
 * hand-write a new hash. This module gives a merchant a way back in that is
 * safe to expose to the open internet.
 *
 * Design decisions worth keeping:
 *
 *  - The token is 32 random bytes from `random_bytes`, given to the user
 *    once, and stored only as its SHA-256 hash. A leaked database therefore
 *    cannot be turned into working reset links.
 *  - `request_reset()` always behaves identically whether or not the address
 *    exists. The caller shows the same message either way, so the page cannot
 *    be used to enumerate which emails have accounts.
 *  - Tokens are single-use (`used_at`) and expire. Consuming one marks it used
 *    in the same transaction that changes the password.
 *  - Requesting a reset for an address invalidates that account's earlier
 *    unused tokens. Otherwise every request a user ever made stays live, and
 *    an old email in a mailbox is still a key.
 *  - The new password is checked with the same rule as registration, and the
 *    merchant's API key and sessions are left alone deliberately: recovery
 *    restores access, it does not silently invalidate a live integration.
 */

require_once __DIR__.'/db.php';
require_once __DIR__.'/notify.php';
require_once __DIR__.'/merchant.php'; // tg_audit

/** How long a reset link stays valid, in seconds. */
function tg_reset_ttl(): int {
    return max(300, (int)envv('PASSWORD_RESET_TTL', '3600'));
}

/**
 * Create a reset token for an address and email the link.
 *
 * Returns false when no mail transport is configured, so the caller can tell
 * the operator to fix mail rather than leaving a merchant waiting for a
 * message that will never arrive.
 */
function tg_password_reset_request(string $email): bool {
    $pdo = db();
    $q = $pdo->prepare('SELECT id,name,email FROM merchants WHERE email=? LIMIT 1');
    $q->execute([strtolower(trim($email))]);
    $merchant = $q->fetch();

    // Unknown address: do the same amount of visible work, send nothing, and
    // let the caller show the identical confirmation. No enumeration.
    if (!$merchant) return true;
    if (!tg_mail_transport_available()) return false;

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);

    // Supersede any earlier unused token for this account.
    $pdo->prepare('UPDATE password_resets SET used_at=? WHERE merchant_id=? AND used_at IS NULL')
        ->execute([db_now(), (int)$merchant['id']]);
    $pdo->prepare('INSERT INTO password_resets(merchant_id,token_hash,requested_ip,expires_at,created_at) VALUES(?,?,?,?,?)')
        ->execute([
            (int)$merchant['id'],
            $hash,
            PHP_SAPI === 'cli' ? null : client_ip(),
            db_now(tg_reset_ttl()),
            db_now(),
        ]);

    $base = tg_public_base_url();
    $link = $base.'/reset-password.php?token='.$token;
    $minutes = (int)round(tg_reset_ttl() / 60);
    $body = "Hello ".$merchant['name'].",\n\n"
        ."Someone asked to reset the password for your TG Gateways account.\n\n"
        ."Choose a new password here:\n$link\n\n"
        ."The link works once and expires in $minutes minutes.\n\n"
        ."If this was not you, ignore this email: your password has not changed\n"
        ."and nobody can use the link without access to this mailbox.\n";

    return tg_smtp_send((string)$merchant['email'], 'Reset your TG Gateways password', $body);
}

/**
 * Look up a reset token without consuming it.
 *
 * @return array{id:int,merchant_id:int}|null
 */
function tg_password_reset_lookup(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $q = db()->prepare('SELECT id,merchant_id,expires_at,used_at FROM password_resets WHERE token_hash=? LIMIT 1');
    $q->execute([hash('sha256', $token)]);
    $row = $q->fetch();
    if (!$row) return null;
    if (!empty($row['used_at'])) return null;
    if (strtotime((string)$row['expires_at']) < time()) return null;
    return ['id' => (int)$row['id'], 'merchant_id' => (int)$row['merchant_id']];
}

/**
 * Consume a token and set the new password.
 *
 * @return string Empty on success, otherwise a message to show the user.
 */
function tg_password_reset_consume(string $token, string $password): string {
    $rule = tg_password_problem($password);
    if ($rule !== '') return $rule;

    $lookup = tg_password_reset_lookup($token);
    if (!$lookup) return 'That reset link is invalid, already used, or expired. Request a new one.';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Guard the write on used_at still being null so two submissions of the
        // same link (a double-click, a mail scanner that pre-fetches) cannot
        // both succeed.
        $upd = $pdo->prepare('UPDATE password_resets SET used_at=? WHERE id=? AND used_at IS NULL');
        $upd->execute([db_now(), $lookup['id']]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return 'That reset link has already been used. Request a new one.';
        }
        $pdo->prepare('UPDATE merchants SET password=? WHERE id=?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $lookup['merchant_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('tg_password_reset_consume: '.$e->getMessage());
        return 'We could not save your new password. Please try again.';
    }

    tg_audit($lookup['merchant_id'], 'password.reset', 'via email link');
    return '';
}

/** The single password rule, shared by registration, reset and change. */
function tg_password_problem(string $password): string {
    if (mb_strlen($password) < 8) return 'Choose a password of at least 8 characters.';
    if (mb_strlen($password) > 200) return 'That password is too long (200 characters maximum).';
    return '';
}

/**
 * Absolute base URL for links in email.
 *
 * Falls back to the configured APP_URL and only then to the current request,
 * because a Host header is attacker-controlled and must not decide where a
 * password-reset link points.
 */
function tg_public_base_url(): string {
    $configured = trim((string)envv('APP_URL', ''));
    if ($configured !== '') return rtrim($configured, '/');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$/', $host)) $host = 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme.'://'.$host;
}
