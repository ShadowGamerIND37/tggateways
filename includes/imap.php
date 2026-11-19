<?php
declare(strict_types=1);
/**
 * IMAP receipt ingestion: connect, read unseen receipt mail, extract the text
 * body, hand it to the settlement engine. Credentials and message bodies are
 * never written to logs.
 */
require_once __DIR__.'/db.php';
require_once __DIR__.'/receipt.php';
require_once __DIR__.'/settlement.php';
require_once __DIR__.'/crypto.php';
require_once __DIR__.'/notify.php';
require_once __DIR__.'/webhook.php';

function imap_supported(): bool { return function_exists('imap_open'); }

function imap_connection_string(array $account): string {
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($account['imap_host'] ?? 'imap.gmail.com')) ?: 'imap.gmail.com';
    $port = (int)($account['imap_port'] ?? 993) ?: 993;
    $flags = filter_var(envv('IMAP_VALIDATE_CERT', 'true'), FILTER_VALIDATE_BOOLEAN) ? '/imap/ssl' : '/imap/ssl/novalidate-cert';
    return '{'.$host.':'.$port.$flags.'}INBOX';
}

/** Decode a MIME body according to its transfer encoding. */
function imap_decode_body(string $body, int $encoding): string {
    return match ($encoding) {
        3 => (string)base64_decode($body, true),
        4 => quoted_printable_decode($body),
        default => $body,
    };
}

/** Walk a message structure and return the best text body (plain preferred). */
function imap_extract_text($imap, int $msgNo, object $structure): string {
    $plain = '';
    $html = '';
    $walk = static function (object $part, string $partNo) use ($imap, $msgNo, &$plain, &$html, &$walk): void {
        if (strtolower((string)($part->type ?? '')) === 'multipart') {
            foreach (($part->parts ?? []) as $index => $child) {
                $walk($child, ($partNo === '' ? '' : $partNo.'.').($index + 1));
            }
            return;
        }
        $subtype = strtolower((string)($part->subtype ?? ''));
        if ($subtype !== 'plain' && $subtype !== 'html') return;
        $body = @imap_fetchbody($imap, $msgNo, $partNo === '' ? '1' : $partNo);
        if (!is_string($body) || $body === '') return;
        $body = imap_decode_body($body, (int)($part->encoding ?? 0));
        if ($subtype === 'plain' && $plain === '') $plain = $body;
        elseif ($subtype === 'html' && $html === '') $html = $body;
    };
    $walk($structure, '');
    return $plain !== '' ? $plain : $html;
}

/** Test the configured mailbox without ingesting anything (dashboard diagnostic). */
function imap_test_connection(array $account): array {
    if (!imap_supported()) return ['ok' => false, 'message' => 'PHP IMAP extension is not installed'];
    try { $password = decrypt_secret((string)$account['google_app_password']); }
    catch (Throwable $e) { return ['ok' => false, 'message' => 'Stored credential could not be decrypted: '.$e->getMessage()]; }
    $imap = @imap_open(imap_connection_string($account), (string)$account['linked_email'], $password, OP_READONLY, 1);
    if (!$imap) return ['ok' => false, 'message' => imap_last_error() ?: 'Could not connect to the IMAP server'];
    $count = @imap_num_msg($imap);
    imap_close($imap);
    return ['ok' => true, 'message' => 'Mailbox reachable. '.($count !== false ? (int)$count : 0).' message(s) present.'];
}

/** Read one account's mailbox once. Throws only when the connection fails. */
function imap_ingest_account(PDO $pdo, array $account): array {
    $summary = ['account' => (int)$account['id'], 'checked' => 0, 'matched' => 0, 'unmatched' => 0, 'duplicate' => 0, 'errors' => 0];
    $password = decrypt_secret((string)$account['google_app_password']);
    $imap = @imap_open(imap_connection_string($account), (string)$account['linked_email'], $password, 0, 1);
    if (!$imap) {
        $error = imap_last_error() ?: 'IMAP connection failed';
        $pdo->prepare('UPDATE merchant_accounts SET last_error=?, last_checked_at=? WHERE id=?')->execute([substr($error, 0, 480), db_now(), (int)$account['id']]);
        throw new RuntimeException($error);
    }
    try {
        $uids = imap_search($imap, 'UNSEEN', SE_UID) ?: [];
        foreach ($uids as $uid) {
            $uid = (int)$uid;
            $msgNo = imap_msgno($imap, $uid);
            if (!$msgNo) continue;
            $summary['checked']++;
            $structure = @imap_fetchstructure($imap, $msgNo);
            if (!$structure) { $summary['errors']++; continue; }
            $raw = imap_extract_text($imap, $msgNo, $structure);
            $header = (string)@imap_fetchheader($imap, $msgNo);
            $messageId = preg_match('/^Message-ID:\s*<([^>]+)>/mi', $header, $m) ? substr($m[1], 0, 250) : null;
            $receipt = receipt_parse($raw !== '' ? $raw : $header);
            if (!claim_receipt_message($pdo, (int)$account['id'], $messageId)) {
                // Another worker already ingested this message.
                $summary['duplicate']++;
                continue;
            }
            try {
                $outcome = settle_receipt($pdo, $account, $receipt, $messageId);
                if ($outcome['state'] === 'matched' || $outcome['state'] === 'ambiguous') {
                    $summary['matched']++;
                    if ($outcome['order_id']) {
                        notify_all($pdo, $outcome['order_id']);
                        dispatch_webhook($pdo, $outcome['order_id']);
                    }
                } elseif ($outcome['state'] === 'duplicate') $summary['duplicate']++;
                else $summary['unmatched']++;
            } catch (Throwable $e) {
                $summary['errors']++;
                error_log('Receipt settlement failed for account '.$account['id'].': '.$e->getMessage());
            }
            // Mark processed so a single receipt is never read twice on this account.
            @imap_setflag_full($imap, (string)$uid, '\\Seen', ST_UID);
            unset($raw);
        }
        $pdo->prepare('UPDATE merchant_accounts SET last_checked_at=?, last_error=NULL WHERE id=?')->execute([db_now(), (int)$account['id']]);
    } finally {
        imap_close($imap);
    }
    return $summary;
}

/** Worker entry point: every active account, failures isolated per account. */
function imap_ingest_all(PDO $pdo): array {
    if (!imap_supported()) return ['error' => 'PHP IMAP extension is required for receipt polling'];
    $accounts = $pdo->query('SELECT id,merchant_id,linked_email,imap_host,imap_port,google_app_password FROM merchant_accounts WHERE is_active=1')->fetchAll();
    $results = [];
    foreach ($accounts as $account) {
        try { $results[] = imap_ingest_account($pdo, $account); }
        catch (Throwable $e) {
            error_log('IMAP account '.$account['id'].' failed: '.$e->getMessage());
            $results[] = ['account' => (int)$account['id'], 'error' => $e->getMessage()];
        }
    }
    return $results;
}
