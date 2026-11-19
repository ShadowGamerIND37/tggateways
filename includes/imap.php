<?php
declare(strict_types=1);
/**
 * IMAP receipt ingestion: connect, read new receipt mail, decode it, hand it to
 * the settlement engine. Credentials and message bodies are never written to
 * logs.
 *
 * Uses the bundled pure-PHP client (includes/imap-client.php) instead of PHP's
 * `imap` extension, which is no longer shipped by the official Docker images and
 * whose Debian build dependency no longer exists.
 */
require_once __DIR__.'/db.php';
require_once __DIR__.'/receipt.php';
require_once __DIR__.'/settlement.php';
require_once __DIR__.'/crypto.php';
require_once __DIR__.'/notify.php';
require_once __DIR__.'/webhook.php';
require_once __DIR__.'/mime.php';
require_once __DIR__.'/imap-client.php';

/** The client only needs PHP's OpenSSL streams, which every supported build has. */
function imap_supported(): bool {
    return function_exists('stream_socket_client') && extension_loaded('openssl');
}

/** Human-readable reason polling cannot run, or null when it can. */
function imap_unsupported_reason(): ?string {
    if (!function_exists('stream_socket_client')) return 'PHP stream sockets are unavailable';
    if (!extension_loaded('openssl')) return 'PHP OpenSSL extension is unavailable (TLS is required)';
    return null;
}

function imap_connection_string(array $account): string {
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($account['imap_host'] ?? 'imap.gmail.com')) ?: 'imap.gmail.com';
    return $host.':'.((int)($account['imap_port'] ?? 993) ?: 993);
}

/**
 * Gmail-style receipt mail arrives already read when the mailbox is opened on a
 * phone or in the web UI, so only polling UNSEEN would silently miss payments.
 * Walk by UID with a persistent watermark instead: cheap on every run, and a
 * deleted or archived message can never rewind the watermark.
 */
function imap_last_uid(PDO $pdo, int $accountId): int {
    $key = 'imap_uid:'.$accountId;
    $stmt = $pdo->prepare('SELECT state_value FROM system_state WHERE state_key=?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    if ($value === false) return -1; // unknown: seed on the next open connection
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? (int)($decoded['uid'] ?? 0) : 0;
}

function imap_set_last_uid(PDO $pdo, int $accountId, int $uid): void {
    $key = 'imap_uid:'.$accountId;
    $value = json_encode(['uid' => $uid]);
    $pdo->prepare('UPDATE system_state SET state_value=?, updated_at=? WHERE state_key=?')->execute([$value, db_now(), $key]);
}

/** Open a mailbox using the account's stored, encrypted credentials. */
function imap_open_account(array $account): TgImapClient {
    $password = decrypt_secret((string)$account['google_app_password']);
    [$host, $port] = explode(':', imap_connection_string($account).':', 2);
    $host = $host !== '' ? $host : 'imap.gmail.com';
    $port = (int)($port !== '' ? $port : 993) ?: 993;
    return TgImapClient::connect(
        $host,
        $port,
        (string)$account['linked_email'],
        $password,
        filter_var(envv('IMAP_VALIDATE_CERT', 'true'), FILTER_VALIDATE_BOOLEAN),
        (int)envv('IMAP_TIMEOUT', '10') ?: 10
    );
}

/** Test the configured mailbox without ingesting anything (dashboard diagnostic). */
function imap_test_connection(array $account): array {
    $reason = imap_unsupported_reason();
    if ($reason !== null) return ['ok' => false, 'message' => 'Cannot test the mailbox: '.$reason];
    try {
        $client = imap_open_account($account);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    try {
        $count = $client->numMessages();
        return ['ok' => true, 'message' => 'Mailbox reachable. '.$count.' message(s) present. New receipts will be picked up by the worker.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    } finally {
        $client->logout();
    }
}

/** Read one account's mailbox once. Throws only when the connection fails. */
function imap_ingest_account(PDO $pdo, array $account): array {
    $summary = ['account' => (int)$account['id'], 'checked' => 0, 'matched' => 0, 'unmatched' => 0, 'duplicate' => 0, 'errors' => 0];

    try {
        $client = imap_open_account($account);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $pdo->prepare('UPDATE merchant_accounts SET last_error=?, last_checked_at=? WHERE id=?')->execute([substr($error, 0, 480), db_now(), (int)$account['id']]);
        throw new RuntimeException($error);
    }

    try {
        $uids = $client->uids();
        $newest = $uids ? max($uids) : 0;
        $watermark = imap_last_uid($pdo, (int)$account['id']);
        if ($watermark < 1) {
            // Seed at the newest message present so a fresh deployment never
            // replays historical receipts; only mail arriving afterwards counts.
            imap_set_last_uid($pdo, (int)$account['id'], $newest);
            $uids = [];
            $summary['seeded_at_uid'] = $newest;
        } else {
            $uids = array_values(array_filter($uids, static fn(int $uid): bool => $uid > $watermark));
        }

        foreach ($uids as $uid) {
            $summary['checked']++;
            $raw = $client->fetchRaw($uid);
            if ($raw === null || $raw === '') {
                $summary['errors']++;
                imap_set_last_uid($pdo, (int)$account['id'], $uid);
                continue;
            }

            $messageId = mime_message_id($raw);
            $text = mime_extract_text($raw);
            $receipt = receipt_parse($text['text']);

            if (!claim_receipt_message($pdo, (int)$account['id'], $messageId)) {
                // Another worker already ingested this message.
                $summary['duplicate']++;
                imap_set_last_uid($pdo, (int)$account['id'], $uid);
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
                } elseif ($outcome['state'] === 'duplicate') {
                    $summary['duplicate']++;
                } else {
                    $summary['unmatched']++;
                }
            } catch (Throwable $e) {
                $summary['errors']++;
                error_log('Receipt settlement failed for account '.$account['id'].': '.$e->getMessage());
            }

            $client->markSeen($uid);
            imap_set_last_uid($pdo, (int)$account['id'], $uid);
            unset($raw, $text);
        }

        $pdo->prepare('UPDATE merchant_accounts SET last_checked_at=?, last_error=NULL WHERE id=?')->execute([db_now(), (int)$account['id']]);
    } finally {
        $client->logout();
    }
    return $summary;
}

/** Worker entry point: every active account, failures isolated per account. */
function imap_ingest_all(PDO $pdo): array {
    $reason = imap_unsupported_reason();
    if ($reason !== null) return ['error' => 'Receipt polling unavailable: '.$reason];
    $accounts = $pdo->query('SELECT id,merchant_id,linked_email,imap_host,imap_port,google_app_password FROM merchant_accounts WHERE is_active=1')->fetchAll();
    $results = [];
    foreach ($accounts as $account) {
        try {
            $results[] = imap_ingest_account($pdo, $account);
        } catch (Throwable $e) {
            error_log('IMAP account '.$account['id'].' failed: '.$e->getMessage());
            $results[] = ['account' => (int)$account['id'], 'error' => $e->getMessage()];
        }
    }
    return $results;
}
