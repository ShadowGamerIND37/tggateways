<?php
declare(strict_types=1);
/**
 * Refunds.
 *
 * This gateway collects money into the merchant's own UPI account; it does not
 * hold the float and cannot move money back. A merchant refunds from their own
 * UPI app, and what this module records is *that they did* — the amount, the
 * refund's own bank reference (UTR) and who issued it. Pretending otherwise
 * would be the worst kind of fake feature: a button that claims to return
 * money it never touches.
 *
 * What is enforced here is the part software can actually guarantee:
 *
 *  - A refund can only be recorded against an order that was actually paid.
 *  - The sum of refunds can never exceed what was collected, checked inside a
 *    transaction so two concurrent partial refunds cannot both pass the check.
 *  - Each refund carries its own id and its bank UTR, so a customer can match
 *    the credit in their statement.
 *  - The order moves to `refunded` only when the full amount is returned;
 *    partial refunds leave it `success` with the balance visible.
 */

require_once __DIR__.'/db.php';
require_once __DIR__.'/merchant.php'; // tg_audit

/** Refund states are derived from the sum, not stored, so they cannot drift. */
function refund_summary(PDO $pdo, string $orderId): array {
    $q = $pdo->prepare('SELECT COALESCE(SUM(amount),0) total, COUNT(*) n FROM refunds WHERE order_id=?');
    $q->execute([$orderId]);
    $row = $q->fetch() ?: ['total' => 0, 'n' => 0];
    $collected = $pdo->prepare('SELECT payable_amount,status FROM orders WHERE order_id=? LIMIT 1');
    $collected->execute([$orderId]);
    $order = $collected->fetch() ?: ['payable_amount' => 0, 'status' => ''];
    $paid = round((float)$order['payable_amount'], 2);
    $refunded = round((float)$row['total'], 2);
    return [
        'paid' => $paid,
        'refunded' => $refunded,
        'remaining' => round(max(0.0, $paid - $refunded), 2),
        'count' => (int)$row['n'],
        'full' => $paid > 0 && $refunded >= $paid - 0.001,
        'order_status' => (string)$order['status'],
    ];
}

/**
 * Record a refund.
 *
 * Returns the new refund id, or an error string. The caller decides how to
 * present it; nothing here throws at the caller.
 *
 * @return array{ok:bool,refund_id?:string,error?:string,summary?:array}
 */
function refund_create(PDO $pdo, int $merchantId, string $orderId, string $amountText, string $reason, string $refundedBy, ?string $utr): array {
    $amount = (float)$amountText;
    if (!is_finite($amount) || $amount <= 0) return ['ok' => false, 'error' => 'Enter a refund amount greater than zero.'];
    if (round($amount, 2) !== $amount) return ['ok' => false, 'error' => 'Amounts take at most two decimal places.'];

    $started = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }

        // Lock the order row first, so the balance check and the insert cannot
        // interleave with another refund for the same order.
        $q = $pdo->prepare('SELECT order_id,merchant_id,payable_amount,status FROM orders WHERE order_id=? LIMIT 1'.for_update());
        $q->execute([$orderId]);
        $order = $q->fetch();
        if (!$order || (int)$order['merchant_id'] !== $merchantId) {
            if ($started) $pdo->rollBack();
            return ['ok' => false, 'error' => 'That order no longer exists.'];
        }
        if ((string)$order['status'] !== 'success') {
            if ($started) $pdo->rollBack();
            return ['ok' => false, 'error' => 'Only a paid order can be refunded. This one is '.$order['status'].'.'];
        }

        $already = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id=?');
        $already->execute([$orderId]);
        $refundedSoFar = round((float)$already->fetchColumn(), 2);
        $paid = round((float)$order['payable_amount'], 2);
        $remaining = round($paid - $refundedSoFar, 2);

        if ($remaining <= 0) {
            if ($started) $pdo->rollBack();
            return ['ok' => false, 'error' => 'This order has already been refunded in full.'];
        }
        if ($amount > $remaining + 0.001) {
            if ($started) $pdo->rollBack();
            return ['ok' => false, 'error' => 'That is more than the '.number_format($remaining, 2).' still refundable on this order.'];
        }

        $refundId = 'rf_'.strtoupper(bin2hex(random_bytes(8)));
        $pdo->prepare('INSERT INTO refunds(order_id,merchant_id,refund_id,amount,reason,refunded_by,utr,created_at) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([
                $orderId, $merchantId, $refundId,
                number_format($amount, 2, '.', ''),
                $reason !== '' ? substr($reason, 0, 255) : null,
                $refundedBy !== '' ? substr($refundedBy, 0, 190) : null,
                $utr !== null && $utr !== '' ? substr($utr, 0, 64) : null,
                db_now(),
            ]);

        // Only a fully refunded order changes status. A partial refund leaves the
        // order `success`, because it was paid and remains partly honoured.
        $fullyRefunded = $amount + $refundedSoFar >= $paid - 0.001;
        if ($fullyRefunded) {
            $pdo->prepare("UPDATE orders SET status='refunded' WHERE order_id=?")->execute([$orderId]);
        }

        $summary = refund_summary($pdo, $orderId);
        if ($started) $pdo->commit();
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        error_log('refund_create: '.$e->getMessage());
        return ['ok' => false, 'error' => 'Could not record the refund. Please try again.'];
    }

    tg_audit($merchantId, 'order.refunded', $orderId.' '.number_format($amount, 2).' '.$refundId);
    return ['ok' => true, 'refund_id' => $refundId, 'summary' => $summary];
}

/** Every refund for one order, newest first. */
function refunds_for_order(PDO $pdo, int $merchantId, string $orderId): array {
    $q = $pdo->prepare('SELECT refund_id,amount,reason,refunded_by,utr,created_at FROM refunds WHERE order_id=? AND merchant_id=? ORDER BY id DESC');
    $q->execute([$orderId, $merchantId]);
    return $q->fetchAll();
}
