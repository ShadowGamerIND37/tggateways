<?php
declare(strict_types=1);
/**
 * Pure receipt parsing + matching rules. No database or network calls live here,
 * so the rules are unit-testable. Bank/provider emails change over time: extend
 * these patterns with your own non-sensitive samples and rerun tests/run.php.
 */

/** Normalise an email body: strip HTML, decode entities, collapse whitespace. */
function receipt_normalise(string $raw): string {
    $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $raw) ?? $raw;
    $text = preg_replace('#<br\s*/?>|</p>|</div>|</tr>#i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\u{20b9}"], '₹', $text);
    return trim(preg_replace('/[ \t]+/', ' ', $text) ?? $text);
}

/** Extract a 12-digit UTR / UPI reference. Returns null when absent. */
function receipt_extract_utr(string $text): ?string {
    $patterns = [
        '/\bUTR\s*(?:NO\.?|NUMBER|REF(?:ERENCE)?)?\s*[:\-]?\s*([0-9]{12})\b/iu',
        '/\bUPI\s*(?:REF(?:ERENCE)?|TRANSACTION)?\s*(?:ID|NO\.?|NUMBER)?\s*[:\-]?\s*([0-9]{12})\b/iu',
        '/\b(?:RRN|REF(?:ERENCE)?\s*(?:NO|NUMBER|ID))\s*[:\-]?\s*([0-9]{12})\b/iu',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) return $m[1];
    }
    return null;
}

/** Extract a rupee amount. Handles ₹, INR, Rs. and decimal/comma forms. */
function receipt_extract_amount(string $text): ?string {
    if (!preg_match('/(?:₹|INR|Rs\.?)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $text, $m)) return null;
    $value = (float)str_replace(',', '', $m[1]);
    if ($value <= 0 || $value > 10000000) return null;
    return number_format($value, 2, '.', '');
}

/** Best-effort payer name; nullable. */
function receipt_extract_sender(string $text): ?string {
    if (!preg_match('/\b(?:paid by|from|sender|remitter)\s*[:\-]\s*([A-Za-z][A-Za-z .]{1,60})/iu', $text, $m)) return null;
    $name = trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]);
    $name = preg_replace('/\b(?:via|through|using|on)\b.*$/iu', '', $name) ?? $name;
    $name = trim($name);
    return $name !== '' ? substr($name, 0, 120) : null;
}

/** Extract a TG order reference embedded in the payment remark, if present. */
function receipt_extract_order_ref(string $text): ?string {
    if (preg_match('/\b(tg_[A-Za-z0-9]{6,40})\b/i', $text, $m)) {
        return 'tg_'.strtoupper(substr($m[1], 3));
    }
    return null;
}

/**
 * Parse a receipt body into structured facts.
 * @return array{utr:?string,amount:?string,sender:?string,order_ref:?string,ok:bool}
 */
function receipt_parse(string $rawBody): array {
    $text = receipt_normalise($rawBody);
    $utr = receipt_extract_utr($text);
    $amount = receipt_extract_amount($text);
    return [
        'utr' => $utr,
        'amount' => $amount,
        'sender' => receipt_extract_sender($text),
        'order_ref' => receipt_extract_order_ref($text),
        'ok' => $utr !== null && $amount !== null,
    ];
}

/**
 * Pick the single best pending order for a parsed receipt, strictly within one
 * receiving account. Rules, in order:
 *   1. If the remark contains an order reference, it must match a pending order of
 *      the same amount. Otherwise no match is returned — never fall back to
 *      amount-only matching, which is how one customer's payment gets credited to
 *      another customer's order.
 *   2. Exact amount match. When several pending orders share that amount, only the
 *      oldest is eligible and the result is flagged ambiguous for review.
 *
 * @param array<int,array{order_id:string,payable_amount:string|float,created_at:string}> $pending
 * @return array{order_id:string,ambiguous:bool,candidates:int}|null
 */
function receipt_match(array $pending, string $amount, ?string $orderRef): ?array {
    $want = number_format((float)$amount, 2, '.', '');
    $same = [];
    foreach ($pending as $order) {
        if (number_format((float)$order['payable_amount'], 2, '.', '') === $want) $same[] = $order;
    }
    if (!$same) return null;
    if ($orderRef !== null && $orderRef !== '') {
        foreach ($same as $order) {
            if (strcasecmp((string)$order['order_id'], $orderRef) === 0) {
                return ['order_id' => (string)$order['order_id'], 'ambiguous' => false, 'candidates' => 1];
            }
        }
        return null;
    }
    usort($same, static fn($a, $b) => strcmp((string)$a['created_at'], (string)$b['created_at']));
    return ['order_id' => (string)$same[0]['order_id'], 'ambiguous' => count($same) > 1, 'candidates' => count($same)];
}
