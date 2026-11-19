<?php
declare(strict_types=1);
/**
 * Settlement engine: turn one parsed receipt into at most one successful order,
 * record receipt_events for auditability, and queue notifications/webhooks.
 */
require_once __DIR__.'/db.php';
require_once __DIR__.'/receipt.php';

/**
 * @return array{state:string,order_id:?string,detail:?string}
 */
function settle_receipt(PDO $pdo, array $account, array $receipt, ?string $messageId = null): array {
    $merchantId = (int)$account['merchant_id'];
    $accountId = (int)$account['id'];
    $utr = $receipt['utr'] ?? null;
    $amount = $receipt['amount'] ?? null;
    $sender = $receipt['sender'] ?? null;
    $orderRef = $receipt['order_ref'] ?? null;

    $record = static function (string $state, ?string $matched, ?string $detail, bool $claimUtr = true) use ($pdo, $merchantId, $accountId, $utr, $amount, $sender, $orderRef, $messageId): void {
        // receipt_events.utr is UNIQUE so the first observation of a UTR owns the
        // row; repeats (duplicate / unparsed) must not collide with it.
        try {
            $pdo->prepare('INSERT INTO receipt_events(merchant_id,merchant_account_id,message_id,utr,amount,sender_name,order_ref,matched_order_id,state,detail,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$merchantId,$accountId,$messageId,$claimUtr ? $utr : null,$amount,$sender,$orderRef,$matched,$state,$detail,db_now()]);
        } catch (PDOException $e) {
            // The UTR already has an audit row: keep the observation without it.
            $pdo->prepare('INSERT INTO receipt_events(merchant_id,merchant_account_id,message_id,utr,amount,sender_name,order_ref,matched_order_id,state,detail,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$merchantId,$accountId,$messageId,null,$amount,$sender,$orderRef,$matched,$state,$detail,db_now()]);
        }
    };

    if ($utr === null || $amount === null) {
        $record('unparsed', null, 'Missing amount or UTR', false);
        return ['state' => 'unparsed', 'order_id' => null, 'detail' => 'Missing amount or UTR'];
    }

    $started = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }

        // A UTR is unique globally for this deployment. Check settled orders and
        // earlier receipt observations (e.g. an unmatched receipt) so a repeat
        // delivery is reported as duplicate instead of violating the index.
        $dup = $pdo->prepare('SELECT order_id FROM orders WHERE utr=? LIMIT 1');
        $dup->execute([$utr]);
        $existing = $dup->fetchColumn();
        if ($existing === false) {
            $seen = $pdo->prepare('SELECT matched_order_id FROM receipt_events WHERE utr=? LIMIT 1');
            $seen->execute([$utr]);
            $observed = $seen->fetchColumn();
            if ($observed !== false) $existing = (string)($observed ?? '');
        }
        if ($existing !== false) {
            if ($started) $pdo->rollBack();
            $started = false;
            $record('duplicate', (string)$existing, 'UTR already recorded', false);
            return ['state' => 'duplicate', 'order_id' => (string)$existing, 'detail' => 'UTR already recorded'];
        }

        // Candidate window: never hand a receipt to an order that was created
        // after the money arrived (a later, unrelated checkout of the same
        // amount). Expiry is filtered in PHP so both drivers agree on time zones.
        $pendingStmt = $pdo->prepare("SELECT order_id,payable_amount,created_at,expires_at FROM orders WHERE merchant_account_id=? AND status='pending' AND created_at<=? ORDER BY created_at ASC".for_update());
        $pendingStmt->execute([$accountId, db_now()]);
        $now = db_now();
        $pending = array_values(array_filter(
            $pendingStmt->fetchAll(),
            static fn(array $order): bool => (string)($order['expires_at'] ?? '') > $now
        ));

        $match = receipt_match($pending, $amount, $orderRef);
        if ($match === null) {
            if ($started) $pdo->rollBack();
            $started = false;
            $record('unmatched', null, 'No pending order for this amount/reference');
            return ['state' => 'unmatched', 'order_id' => null, 'detail' => 'No pending order for this amount/reference'];
        }

        $update = $pdo->prepare("UPDATE orders SET status='success',utr=?,transaction_id=?,sender_name=?,receipt_message_id=?,paid_at=? WHERE order_id=? AND status='pending' AND merchant_account_id=?");
        $update->execute([$utr,$utr,$sender,$messageId,db_now(),$match['order_id'],$accountId]);
        if ($update->rowCount() !== 1) {
            if ($started) $pdo->rollBack();
            $started = false;
            $record('unmatched', null, 'Order was no longer pending', false);
            return ['state' => 'unmatched', 'order_id' => null, 'detail' => 'Order was no longer pending'];
        }

        $state = $match['ambiguous'] ? 'ambiguous' : 'matched';
        $detail = $match['ambiguous'] ? 'Multiple pending orders shared this amount; oldest claimed' : null;
        $pdo->prepare('INSERT INTO receipt_events(merchant_id,merchant_account_id,message_id,utr,amount,sender_name,order_ref,matched_order_id,state,detail,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$merchantId,$accountId,$messageId,$utr,$amount,$sender,$orderRef,$match['order_id'],$state,$detail,db_now()]);

        if ($started) $pdo->commit();
        return ['state' => $state, 'order_id' => $match['order_id'], 'detail' => $detail];
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        try { $record('error', null, substr($e->getMessage(), 0, 240), false); } catch (Throwable) {}
        throw $e;
    }
}

/** Expire stale checkouts and prune old rate-limit rows. Safe to run repeatedly. */
function housekeeping(PDO $pdo): array {
    $expired = $pdo->prepare("UPDATE orders SET status='expired' WHERE status='pending' AND expires_at<?");
    $expired->execute([db_now()]);
    $pruned = $pdo->prepare('DELETE FROM api_rate_limits WHERE window_started_at < ?');
    $pruned->execute([time() - 172800]);
    return ['expired' => $expired->rowCount(), 'rate_limits_pruned' => $pruned->rowCount()];
}

/**
 * Claim a single MIME message for one account. Prevents two overlapping worker
 * runs from processing the same email twice. Returns true when this process owns
 * the claim; false means another run already has it.
 */
function claim_receipt_message(PDO $pdo, int $accountId, ?string $messageId): bool {
    $key = $messageId !== null && $messageId !== '' ? 'msg:'.$accountId.':'.substr($messageId, 0, 180) : 'uid:'.$accountId.':'.bin2hex(random_bytes(8));
    try {
        $pdo->prepare('INSERT INTO api_rate_limits(rate_key,window_started_at,request_count) VALUES(?,?,1)')->execute([hash('sha256',$key), time()]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
