<?php
declare(strict_types=1);

require_once __DIR__.'/env.php';
/**
 * Resolve the real client address behind a reverse proxy or CDN.
 *
 * A Cloudflare Tunnel (cloudflared) connects to the container from the Docker
 * bridge, so without this every visitor would share one address: rate limits
 * would be global, per-IP abuse limits would be useless, and the audit trail
 * would not identify a client. The forwarded header is only trusted when the
 * request actually arrived from a configured proxy, so a direct visitor cannot
 * forge their own address.
 *
 * Precedence:
 *   1. TRUSTED_PROXIES is empty  -> use REMOTE_ADDR (safe default).
 *   2. Header named by PROXY_IP_HEADER (default CF-Connecting-IP) when
 *      REMOTE_ADDR is inside TRUSTED_PROXIES.
 *   3. Last entry of X-Forwarded-For in the same trusted case: cloudflared
 *      appends the address it saw, and a client-supplied header cannot control
 *      that final entry.
 */

/** True when the request arrived from a proxy listed in TRUSTED_PROXIES. */
function tg_from_trusted_proxy(?string $remote, ?string $list = null): bool
{
    if ($remote === null || $remote === '' || !filter_var($remote, FILTER_VALIDATE_IP)) return false;
    $list ??= (string)envv('TRUSTED_PROXIES', '');
    if (trim($list) === '') return false;

    foreach (preg_split('/[\s,]+/', trim($list)) ?: [] as $entry) {
        if ($entry === '') continue;
        if (str_contains($entry, '/')) {
            if (tg_ip_in_cidr($remote, $entry)) return true;
            continue;
        }
        // docker compose bridge network, or "container:tggateways-web-1".
        if ($entry === $remote || str_starts_with($entry, 'container:') || str_starts_with($entry, 'name:')) return true;
    }
    return false;
}

/** CIDR membership for IPv4 and IPv6. */
function tg_ip_in_cidr(string $ip, string $subnet, ?int $bits = null): bool
{
    // Accept both "10.0.0.0/8" and ("10.0.0.0", 8): callers reach for either.
    if (str_contains($subnet, '/')) {
        [$subnet, $prefix] = explode('/', $subnet, 2);
        $bits ??= (int)$prefix;
    }
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($subnet);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    // A bare address with no prefix means "this one host".
    $bits ??= strlen($ipBin) * 8;
    $bits = max(0, min($bits, strlen($ipBin) * 8));
    $wholeBytes = intdiv($bits, 8);
    $remainder = $bits % 8;
    if ($wholeBytes > 0 && substr($ipBin, 0, $wholeBytes) !== substr($netBin, 0, $wholeBytes)) return false;
    if ($remainder === 0) return true;
    $mask = 0xFF << (8 - $remainder) & 0xFF;
    return (ord($ipBin[$wholeBytes]) & $mask) === (ord($netBin[$wholeBytes]) & $mask);
}

/**
 * The client address for this request.
 *
 * @param array<string,string>|null $server  injectable for tests
 */
function tg_client_ip(?array $server = null, ?string $trustedList = null, ?string $header = null): string
{
    $server ??= $_SERVER;
    $remote = (string)($server['REMOTE_ADDR'] ?? '');
    $header ??= (string)envv('PROXY_IP_HEADER', 'HTTP_CF_CONNECTING_IP');

    if (tg_from_trusted_proxy($remote, $trustedList)) {
        $forwarded = trim((string)($server[$header] ?? ''));
        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP)) return $forwarded;

        $chain = (string)($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($chain !== '') {
            $entries = array_values(array_filter(array_map('trim', explode(',', $chain))));
            $last = (string)end($entries);
            if (filter_var($last, FILTER_VALIDATE_IP)) return $last;
        }
    }

    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}
