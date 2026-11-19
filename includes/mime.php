<?php
declare(strict_types=1);
/**
 * MIME decoding for receipt mail — pure functions, no network, no database, so
 * the parsing rules can be unit-tested against real provider samples.
 *
 * Providers rarely send one plain-text part. Gmail forwards arrive as
 * multipart/mixed containing multipart/alternative, sometimes quoted-printable,
 * sometimes base64, and the amount usually lives in the HTML alternative. The
 * receipt parser therefore receives decoded text, never transfer-encoded text.
 */

/** Split a raw message into decoded headers and bodies. */
function mime_parse_message(string $raw): array
{
    [$headerBlock, $body] = mime_split($raw);
    return [
        'headers' => mime_parse_headers($headerBlock),
        'body' => $body,
    ];
}

/**
 * Split headers from body at the first empty line.
 *
 * @return array{0:string,1:string}
 */
function mime_split(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $parts = explode("\n\n", $raw, 2);
    if (count($parts) === 1) return [$raw, ''];
    return [$parts[0], $parts[1]];
}

/**
 * Parse header lines into a lower-cased map, unfolding continuation lines and
 * decoding RFC 2047 encoded words in the values.
 *
 * @return array<string,string>
 */
function mime_parse_headers(string $headerBlock): array
{
    $headers = [];
    $current = null;
    foreach (explode("\n", $headerBlock) as $line) {
        if ($line === '') continue;
        if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
            $headers[$current] .= ' '.trim($line);
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $current = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));
        // First occurrence wins; repeated Received/ARC headers are not needed here.
        if (!isset($headers[$current])) {
            $headers[$current] = mime_decode_header($value);
        }
    }
    return $headers;
}

/** Decode RFC 2047 encoded-words (=?UTF-8?B?...?= / =?UTF-8?Q?...?=). */
function mime_decode_header(string $value): string
{
    if (!str_contains($value, '=?')) return $value;
    $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if (is_string($decoded) && $decoded !== '') return $decoded;
    return preg_replace_callback(
        '/=\?([^?*]+)(?:\*[^?]*)?\?([BbQq])\?([^?]*)\?=/',
        static function (array $m): string {
            $charset = strtoupper($m[1]) ?: 'UTF-8';
            $data = $m[2] === 'B' || $m[2] === 'b'
                ? (string)base64_decode($m[3], true)
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            if ($charset !== 'UTF-8' && $charset !== 'US-ASCII' && function_exists('mb_convert_encoding')) {
                $data = (string)mb_convert_encoding($data, 'UTF-8', $charset);
            }
            return $data;
        },
        $value
    ) ?? $value;
}

/**
 * Decode a body part according to its Content-Transfer-Encoding.
 * 3 = base64, 4 = quoted-printable, anything else is 7bit/8bit/binary.
 */
function mime_decode_transfer(string $body, int|string $encoding): string
{
    $encoding = is_string($encoding) ? strtolower(trim($encoding)) : (string)$encoding;
    return match (true) {
        $encoding === '3', $encoding === 'base64' => mime_base64_decode($body),
        $encoding === '4', $encoding === 'quoted-printable' => quoted_printable_decode($body),
        default => $body,
    };
}

/**
 * Decode base64 leniently.
 *
 * Strict decoding fails the whole part if a sender glued the MIME boundary onto
 * the last line or used a stray character, which loses the amount entirely.
 * Retry with whitespace and non-alphabet bytes removed, then pad — a partially
 * recovered receipt is far more useful than none, and the parser still has to
 * find a valid amount and UTR before anything can be marked paid.
 */
function mime_base64_decode(string $body): string
{
    $clean = preg_replace('/\s+/', '', $body) ?? $body;
    $strict = base64_decode($clean, true);
    if ($strict !== false) return $strict;

    // Keep only the base64 alphabet, then drop everything after the first pad
    // character: leftover junk after "=" is what breaks a glued boundary.
    $lenient = preg_replace('/[^A-Za-z0-9+\/=]/', '', $clean) ?? '';
    $pad = strpos($lenient, '=');
    if ($pad !== false) $lenient = substr($lenient, 0, $pad);
    $lenient = rtrim($lenient, '=');
    $remainder = strlen($lenient) % 4;
    if ($remainder !== 0) $lenient .= str_repeat('=', 4 - $remainder);
    $decoded = base64_decode($lenient, true);
    return $decoded === false ? '' : $decoded;
}

/** Best-effort charset conversion so ₹ and names survive. */
function mime_to_utf8(string $text, ?string $charset): string
{
    $charset = strtoupper(trim((string)$charset));
    if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII' || $charset === 'ASCII') return $text;
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
        if (is_string($converted)) return $converted;
    }
    if (function_exists('iconv')) {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
        if (is_string($converted)) return $converted;
    }
    return $text;
}

/**
 * Extract the best human-readable text from a raw message.
 *
 * Plain text is preferred (it is what bank receipts look like); the HTML
 * alternative is used when there is no plain part.
 *
 * @return array{text:string,html:bool,headers:array<string,string>}
 */
function mime_extract_text(string $raw): array
{
    $message = mime_parse_message($raw);
    $headers = $message['headers'];
    $contentType = (string)($headers['content-type'] ?? 'text/plain');

    $plain = '';
    $html = '';
    mime_walk($contentType, $headers, $message['body'], $plain, $html);

    if ($plain !== '') {
        return ['text' => mime_normalise_utf8($plain), 'html' => false, 'headers' => $headers];
    }
    return ['text' => mime_normalise_utf8($html), 'html' => $html !== '', 'headers' => $headers];
}

/**
 * The message's Message-ID without angle brackets, capped for storage.
 */
function mime_message_id(string $raw): ?string
{
    $message = mime_parse_message($raw);
    $id = trim((string)($message['headers']['message-id'] ?? ''), " \t<>");
    return $id !== '' ? substr($id, 0, 250) : null;
}

/** Normalise UTF-8 text: collapse whitespace but keep line breaks meaningful. */
function mime_normalise_utf8(string $text): string
{
    if (!preg_match('//u', $text)) {
        // Not valid UTF-8 (e.g. Latin-1 receipt). Fall back so the parser still sees text.
        $text = mime_to_utf8($text, 'ISO-8859-1');
        if (!preg_match('//u', $text)) {
            $text = (string)preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', ' ', $text);
        }
    }
    return trim(str_replace(["\r\n", "\r"], "\n", $text));
}

/**
 * Recursively walk a MIME tree and record the first plain and first HTML part.
 * Inline images and attachments are skipped by type, not by name guessing.
 */
function mime_walk(string $contentType, array $headers, string $body, string &$plain, string &$html): void
{
    $type = strtolower(trim(explode(';', $contentType)[0]));
    $params = mime_params($contentType);

    if (str_starts_with($type, 'multipart/')) {
        $boundary = $params['boundary'] ?? '';
        if ($boundary === '') return;
        foreach (mime_split_parts($body, $boundary) as $part) {
            // walk() is always entered with \n line endings (mime_split normalises
            // them); accept raw \r\n too so a part split from an unusual source
            // still separates its headers from its body.
            [$partHeaderBlock, $partBody] = mime_split(str_replace("\r\n", "\n", $part));
            $partHeaders = mime_parse_headers($partHeaderBlock);
            mime_walk((string)($partHeaders['content-type'] ?? 'text/plain'), $partHeaders, $partBody, $plain, $html);
        }
        return;
    }

    $disposition = strtolower((string)($headers['content-disposition'] ?? ''));
    if (str_starts_with($disposition, 'attachment')) return;

    $encoding = (string)($headers['content-transfer-encoding'] ?? '7bit');
    $decoded = mime_decode_transfer($body, $encoding);
    $charset = $params['charset'] ?? null;

    if ($type === 'text/plain' && $plain === '') {
        $plain = mime_to_utf8($decoded, $charset);
    } elseif ($type === 'text/html' && $html === '') {
        $html = mime_to_utf8($decoded, $charset);
    }
}

/** @return list<string> */
function mime_split_parts(string $body, string $boundary): array
{
    $delimiter = '--'.$boundary;
    $parts = [];
    $current = null;
    foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
        $trimmed = rtrim($line);
        if ($trimmed === $delimiter || $trimmed === $delimiter.'--') {
            if ($current !== null) $parts[] = $current;
            if ($trimmed === $delimiter.'--') { $current = null; break; }
            $current = '';
            continue;
        }
        if ($current !== null) $current .= $line."\n";
    }
    if ($current !== null && trim($current) !== '') $parts[] = $current;
    return $parts;
}

/** @return array<string,string> */
function mime_params(string $headerValue): array
{
    $params = [];
    if (preg_match_all('/;\s*([A-Za-z0-9_-]+)\s*=\s*(?:"([^"]*)"|([^;\s]+))/', $headerValue, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $params[strtolower($match[1])] = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
        }
    }
    return $params;
}
