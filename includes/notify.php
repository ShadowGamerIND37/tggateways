<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';

/**
 * Outbound HTTP POST with the deployment's SSRF policy: HTTPS only, public
 * destinations only, DNS-pinned to the exact addresses that were validated so a
 * second DNS answer cannot redirect the request to an internal host.
 * Returns ['code'=>int,'body'=>string,'error'=>?string].
 */
function tg_http_post(string $url, string $body, array $headers, int $timeout = 8): array {
    if (!function_exists('curl_init')) return ['code' => 0, 'body' => '', 'error' => 'cURL extension not available'];
    $resolve = safe_webhook_resolve($url);
    if ($resolve === false) return ['code' => 0, 'body' => '', 'error' => 'Destination rejected by outbound URL policy'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE => $resolve,
    ]);
    if (filter_var(envv('WEBHOOK_STRICT_TLS', 'true'), FILTER_VALIDATE_BOOLEAN)) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = $response === false ? curl_error($ch) : null;
    unset($ch);
    return ['code' => $code, 'body' => is_string($response) ? $response : '', 'error' => $error];
}

/**
 * Discord notification for a successful payment. Idempotent per order+channel.
 * Never blocks or breaks the settlement path.
 */
function notify_discord(PDO $pdo, string $orderId): bool {
    $q = $pdo->prepare('SELECT o.order_id,o.amount,o.utr,o.sender_name,o.paid_at,m.name merchant_name,m.discord_webhook_url FROM orders o JOIN merchants m ON m.id=o.merchant_id WHERE o.order_id=?');
    $q->execute([$orderId]);
    $row = $q->fetch();
    if (!$row || !$row['discord_webhook_url']) return false;

    $seen = $pdo->prepare("SELECT 1 FROM notification_logs WHERE order_id=? AND channel='discord' AND delivered_at IS NOT NULL LIMIT 1");
    $seen->execute([$orderId]);
    if ($seen->fetchColumn()) return true;

    $content = sprintf(
        "💰 **Payment received**\n**Amount:** ₹%s\n**Order:** `%s`\n**UTR:** `%s`\n**Payer:** %s\n**Merchant:** %s",
        number_format((float)$row['amount'], 2),
        $row['order_id'],
        $row['utr'] ?: '—',
        $row['sender_name'] ?: '—',
        $row['merchant_name']
    );
    $body = json_encode(['content' => $content, 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result = tg_http_post((string)$row['discord_webhook_url'], $body, ['Content-Type: application/json', 'User-Agent: TG-Gateways/1.0']);
    $ok = $result['code'] >= 200 && $result['code'] < 300;
    $pdo->prepare('INSERT INTO notification_logs(merchant_id,order_id,channel,response_code,last_error,delivered_at,created_at) SELECT merchant_id,order_id,?,?,?,?,? FROM orders WHERE order_id=?')
        ->execute(['discord',$result['code'] ?: null,$ok ? null : substr((string)$result['error'], 0, 250),$ok ? db_now() : null,db_now(),$orderId]);
    return $ok;
}

/** Run every configured notification for an order. Failures are logged only. */
function notify_all(PDO $pdo, string $orderId): void {
    foreach (['discord' => 'notify_discord'] as $channel => $fn) {
        if (!function_exists($fn)) continue;
        try { $fn($pdo, $orderId); }
        catch (Throwable $e) { error_log('Notification ('.$channel.') failed for order '.$orderId.': '.$e->getMessage()); }
    }
}

/**
 * Two-factor sign-in policy.
 *
 * Enabled only when the deployment can actually deliver the code: SMTP is
 * configured, or PHP's mail() transport is available. Otherwise login stays
 * single-factor rather than trapping the merchant on a code screen that can
 * never receive a message.
 */
function tg_two_factor_required(): bool {
    // Opt-in only. Presence of mail() is not evidence that mail is deliverable
    // (it is compiled in on most builds but often unrouted), and silently
    // requiring a code that never arrives would lock the merchant out.
    if (filter_var(envv('LOGIN_2FA', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        return tg_mail_transport_available();
    }
    return false;
}

/** True when a code could actually be delivered by some transport. */
function tg_mail_transport_available(): bool {
    return trim((string)envv('SMTP_HOST', '')) !== '' || function_exists('mail');
}

/** Deliver a 6-digit sign-in code. Returns false when no transport exists. */
function tg_send_2fa_code(string $email, string $code): bool {
    $subject = 'Your TG Gateways sign-in code';
    $body = "Your verification code is $code\n\nIt expires in 10 minutes. "
        . "If you did not try to sign in, change your password immediately.\n";
    $host = trim((string)envv('SMTP_HOST', ''));
    if ($host !== '') return tg_smtp_send($email, $subject, $body);
    if (!function_exists('mail')) return false;
    $from = (string)envv('MAIL_FROM', 'no-reply@localhost');
    return @mail($email, $subject, $body, 'From: '.$from);
}

/** Send an arbitrary transactional email through SMTP when configured. */
function tg_smtp_send(string $to, string $subject, string $body): bool {
    $host = (string)envv('SMTP_HOST', '');
    if ($host === '') return false;
    $port = (int)envv('SMTP_PORT', '587');
    $user = (string)envv('SMTP_USER', '');
    $pass = (string)envv('SMTP_PASSWORD', '');
    $from = (string)envv('MAIL_FROM', $user !== '' ? $user : 'no-reply@localhost');
    $secure = strtolower((string)envv('SMTP_SECURE', $port === 465 ? 'ssl' : 'tls'));
    $remote = ($secure === 'ssl' ? 'ssl://' : '').$host.':'.$port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { error_log("tg_smtp_send: connect failed ($errno $errstr)"); return false; }
    stream_set_timeout($fp, 10);
    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $out;
    };
    $cmd = static function (string $c) use ($fp, $read): string { fwrite($fp, $c."\r\n"); return $read(); };
    $read();
    $cmd('EHLO '.php_uname('n'));
    if ($secure === 'tls') {
        if (!str_starts_with($cmd('STARTTLS'), '220')) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
        $cmd('EHLO '.php_uname('n'));
    }
    if ($user !== '') {
        if (!str_starts_with($cmd('AUTH LOGIN'), '334')) { fclose($fp); return false; }
        $cmd(base64_encode($user));
        if (!str_starts_with($cmd(base64_encode($pass)), '235')) { fclose($fp); return false; }
    }
    $headers = 'From: '.$from."\r\n".'To: '.$to."\r\n".'Subject: '.$subject."\r\n"
        .'MIME-Version: 1.0'."\r\n".'Content-Type: text/plain; charset=UTF-8'."\r\n";
    $data = $headers."\r\n".str_replace("\n.", "\n..", $body);
    $ok = str_starts_with($cmd('MAIL FROM:<'.$from.'>'), '250')
        && str_starts_with($cmd('RCPT TO:<'.$to.'>'), '250')
        && str_starts_with($cmd('DATA'), '354')
        && str_starts_with($cmd($data."\r\n."), '250');
    $cmd('QUIT');
    fclose($fp);
    return $ok;
}
