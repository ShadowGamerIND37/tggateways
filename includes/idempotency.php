<?php
declare(strict_types=1);
/**
 * Idempotent order creation.
 *
 * A payment API is not allowed to require a caller to distinguish "my request
 * never arrived" from "my request arrived and the response was lost". Without
 * this, the standard remedy — retry on timeout — creates a second order for one
 * intended charge, and the customer sees two checkouts for the same cart.
 *
 * Contract, matching the convention Stripe established and integrators expect:
 *
 *   POST /api/create-order.php
 *   Idempotency-Key: <caller-generated unique string>
 *
 *   first request   -> creates the order, 201, stores the response
 *   exact replay    -> the stored response, same status code, no new order
 *   same key, new body -> 409, because reusing a key for different content is a
 *                      caller bug and silently honouring either one would be wrong
 *   in flight       -> 409 with a retry hint, so the caller does not treat a race
 *                      as a failure and open a second charge
 *
 * The key is scoped per merchant, so one merchant cannot probe or collide with
 * another's keys. Keys are only honoured on POST.
 */

require_once __DIR__.'/db.php';

/** How long a stored response stays replayable. */
function idem_ttl_seconds(): int {
    return max(60, (int)envv('IDEMPOTENCY_TTL', '86400'));
}

/** Longest key we accept; also the column width. */
const IDEM_KEY_MAX = 190;

/**
 * Read the caller's key.
 *
 * Returns null when the header is absent (the request is not idempotent), and
 * never throws: a malformed key is a 400 from the caller's point of view, which
 * require_idem_key() turns into a response.
 */
function idem_key_from_request(): ?string {
    $raw = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    if (!is_string($raw)) return null;
    $trimmed = trim($raw);
    return $trimmed === '' ? null : $trimmed;
}

/**
 * Canonical hash of the request body.
 *
 * Decoded and re-encoded so that key order and insignificant whitespace do not
 * make an identical retry look like different content. Falls back to the raw
 * body when it is not valid JSON, which is what a 400 path wants anyway.
 */
function idem_request_hash(string $body): string {
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        // Sort recursively so {"a":1,"b":2} and {"b":2,"a":1} hash alike.
        $sort = static function (array $value) use (&$sort): array {
            $isList = array_is_list($value);
            if (!$isList) ksort($value);
            foreach ($value as $k => $v) {
                if (is_array($v)) $value[$k] = $sort($v);
            }
            return $value;
        };
        return hash('sha256', json_encode($sort($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    return hash('sha256', $body);
}

/**
 * Reserve a key for this merchant.
 *
 * @return array{state:string,row:?array}
 *   state = 'new'       the caller owns the key and must do the work
 *           'replay'    a completed response exists; return it verbatim
 *           'mismatch'  the key was used with different content
 *           'inflight'  another request holds the key and has not finished
 */
function idem_reserve(PDO $pdo, int $merchantId, string $key, string $requestHash): array {
    // Opportunistic prune: keeps the table bounded without a separate cron job.
    if (random_int(1, 50) === 1) {
        try {
            $pdo->prepare('DELETE FROM idempotency_keys WHERE created_at < ?')
                ->execute([db_now(-idem_ttl_seconds() * 2)]);
        } catch (Throwable $e) {
            error_log('idem prune: '.$e->getMessage());
        }
    }

    $select = $pdo->prepare('SELECT * FROM idempotency_keys WHERE merchant_id=? AND idem_key=? LIMIT 1');
    $select->execute([$merchantId, $key]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    if ($row) return idem_classify_row($row, $requestHash);

    // Reserve by insert. The unique index is what makes this safe: if two
    // requests race, exactly one insert succeeds and the loser re-reads.
    try {
        $pdo->prepare('INSERT INTO idempotency_keys(merchant_id,idem_key,request_hash,created_at) VALUES(?,?,?,?)')
            ->execute([$merchantId, $key, $requestHash, db_now()]);
        return ['state' => 'new', 'row' => null];
    } catch (Throwable $e) {
        // Lost the race, or the key is already there.
        $select->execute([$merchantId, $key]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            error_log('idem_reserve: insert failed and row is absent: '.$e->getMessage());
            return ['state' => 'new', 'row' => null];
        }
        return idem_classify_row($row, $requestHash);
    }
}

/**
 * Decide what an existing row means for this request.
 *
 * @param array<string,mixed> $row
 * @return array{state:string,row:?array}
 */
function idem_classify_row(array $row, string $requestHash): array {
    if (!hash_equals((string)$row['request_hash'], $requestHash)) {
        return ['state' => 'mismatch', 'row' => $row];
    }
    if ($row['response_body'] !== null) {
        return ['state' => 'replay', 'row' => $row];
    }
    // Held but unfinished. A row older than the order window is a crashed
    // request, not a slow one; let the caller proceed rather than block them
    // forever on a reservation nobody will ever complete.
    $age = time() - (int)strtotime((string)$row['created_at']);
    if ($age > max(60, (int)envv('IDEMPOTENCY_STALE', '120'))) {
        try {
            db()->prepare('DELETE FROM idempotency_keys WHERE id=?')->execute([(int)$row['id']]);
        } catch (Throwable $e) {
            error_log('idem stale delete: '.$e->getMessage());
        }
        return ['state' => 'new', 'row' => null];
    }
    return ['state' => 'inflight', 'row' => $row];
}

/**
 * Store the response so a replay can return it byte for byte.
 *
 * Never throws: if persistence fails the order has already been created, and
 * turning that into a 500 would make the caller retry and create a second one.
 */
function idem_complete(PDO $pdo, int $merchantId, string $key, string $body, int $code, ?string $orderId): void {
    try {
        $pdo->prepare('UPDATE idempotency_keys SET response_body=?,response_code=?,order_id=? WHERE merchant_id=? AND idem_key=?')
            ->execute([$body, $code, $orderId, $merchantId, $key]);
    } catch (Throwable $e) {
        error_log('idem_complete: '.$e->getMessage());
    }
}

/**
 * Release a reservation after a request that created nothing.
 *
 * A 4xx caused by bad input must not burn the key: the caller fixes the body
 * and retries with the same key, which is the whole point.
 */
function idem_release(PDO $pdo, int $merchantId, string $key): void {
    try {
        $pdo->prepare('DELETE FROM idempotency_keys WHERE merchant_id=? AND idem_key=? AND response_body IS NULL')
            ->execute([$merchantId, $key]);
    } catch (Throwable $e) {
        error_log('idem_release: '.$e->getMessage());
    }
}
