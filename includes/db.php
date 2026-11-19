<?php
declare(strict_types=1);

function load_env(string $path): void {
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key); $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) { putenv($key.'='.$value); $_ENV[$key] = $value; }
    }
}
load_env(dirname(__DIR__).'/.env');

/** Read a configuration value from the environment (loaded from .env above). */
function envv(string $key, ?string $default = null): ?string { $v = getenv($key); return $v === false ? $default : $v; }

require_once __DIR__.'/errors.php';
tg_install_error_handlers();

function db_driver(): string {
    $driver = strtolower((string)envv('DB_DRIVER', 'mysql'));
    return in_array($driver, ['mysql', 'sqlite'], true) ? $driver : 'mysql';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (db_driver() === 'sqlite') {
        $path = (string)envv('DB_SQLITE_PATH', dirname(__DIR__).'/storage/tggateways.sqlite');
        if ($path === ':memory:') {
            $pdo = new PDO('sqlite::memory:', null, null, $options);
        } else {
            if (!is_dir(dirname($path))) @mkdir(dirname($path), 0770, true);
            $pdo = new PDO('sqlite:'.$path, null, null, $options);
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', envv('DB_HOST','127.0.0.1'), envv('DB_PORT','3306'), envv('DB_NAME','tggateways'));
    $pdo = new PDO($dsn, (string)envv('DB_USER','root'), (string)envv('DB_PASSWORD',''), $options);
    return $pdo;
}

/** Portable "now" for bound parameters. */
function db_now(int $offsetSeconds = 0): string { return date('Y-m-d H:i:s', time() + $offsetSeconds); }

/** Row-lock suffix, omitted on SQLite (single-writer engine). */
function for_update(): string { return db_driver() === 'sqlite' ? '' : ' FOR UPDATE'; }

/**
 * Send a response header. Production behaviour is unchanged; CLI test runs keep
 * a readable copy so the request runner can emit real HTTP headers. PHP's
 * header() is a no-op in CLI, so both channels are written.
 */
function tg_header(string $header, bool $replace = true): void {
    if (PHP_SAPI !== 'cli' && headers_sent()) return;
    if (PHP_SAPI === 'cli' || headers_sent() === false) $GLOBALS['TG_RESPONSE_HEADERS'][] = $header;
    if (PHP_SAPI !== 'cli') header($header, $replace);
}

/** Raw request body with a test hook: TG_TEST_BODY_PATH supplies php://input in CLI. */
function request_body(): string {
    static $body = null;
    if ($body !== null) return $body;
    $hook = envv('TG_TEST_BODY_PATH');
    if ($hook !== null && $hook !== '' && is_file($hook)) return $body = (string)file_get_contents($hook);
    // php://input cannot be read reliably during CLI test runs.
    if (PHP_SAPI === 'cli') return $body = '';
    return $body = (string)file_get_contents('php://input');
}

function json_response(array $data, int $status=200): never {
    http_response_code($status);
    tg_header('Content-Type: application/json; charset=utf-8');
    tg_header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function request_json(): array {
    $data = json_decode(request_body(), true);
    if (!is_array($data)) json_response(['error'=>'Expected a JSON object'], 400);
    return $data;
}

function redirect_to(string $location, int $status = 302): never {
    http_response_code($status);
    tg_header('Location: '.$location);
    exit;
}

function api_key_from_request(): ?string {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$key && preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) $key = $m[1];
    return $key !== '' ? $key : null;
}

function merchant_by_api_key(?string $rawKey = null): array {
    $key = $rawKey ?? api_key_from_request();
    if (!$key || strlen($key) > 128) json_response(['error'=>'Valid API key required'], 401);
    // api_key is stored as ascii_bin / case-sensitive text, so no BINARY cast is needed.
    $q=db()->prepare('SELECT id,name,email,api_key,webhook_url,redirect_url,discord_webhook_url,brand_color FROM merchants WHERE api_key = ?');
    $q->execute([$key]);
    $merchant=$q->fetch();
    if (!$merchant) json_response(['error'=>'Invalid API key'], 401);
    return $merchant;
}

function require_method(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) { tg_header('Allow: '.implode(', ', $methods)); json_response(['error'=>'Method not allowed'],405); }
}

function rate_limit(string $identity, int $limit=90, int $window=60): void {
    $pdo = db();
    // Production uses the client address; the CLI harness has none, so the
    // identity alone scopes the counter instead of collapsing every caller into
    // one bucket.
    $address = PHP_SAPI === 'cli' ? 'cli' : (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash('sha256', $identity.'|'.$address);
    $now = time();
    $cutoff = $now - $window;
    if (db_driver() === 'sqlite') {
        $q = $pdo->prepare('INSERT INTO api_rate_limits(rate_key,window_started_at,request_count) VALUES(?,?,1) ON CONFLICT(rate_key) DO UPDATE SET request_count=CASE WHEN window_started_at < ? THEN 1 ELSE request_count+1 END, window_started_at=CASE WHEN window_started_at < ? THEN ? ELSE window_started_at END');
        $q->execute([$key,$now,$cutoff,$cutoff,$now]);
    } else {
        $q = $pdo->prepare('INSERT INTO api_rate_limits(rate_key,window_started_at,request_count) VALUES(?,?,1) ON DUPLICATE KEY UPDATE request_count=IF(window_started_at < ?,1,request_count+1), window_started_at=IF(window_started_at < ?,?,window_started_at)');
        $q->execute([$key,$now,$cutoff,$cutoff,$now]);
    }
    $s = $pdo->prepare('SELECT request_count,window_started_at FROM api_rate_limits WHERE rate_key=?');
    $s->execute([$key]);
    $row = $s->fetch();
    if ((int)$row['request_count'] > $limit) {
        tg_header('Retry-After: '.max(1,$window-($now-(int)$row['window_started_at'])));
        json_response(['error'=>'Rate limit exceeded'],429);
    }
}

function valid_http_url(mixed $url): bool {
    if ($url === null || $url === '') return true;
    if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) return false;
    $p=parse_url($url);
    return is_array($p) && strtolower((string)($p['scheme']??''))==='https' && !empty($p['host']) && !isset($p['user']) && !isset($p['pass']) && (!isset($p['port']) || (int)$p['port']===443);
}

/**
 * Resolve a webhook destination to DNS-pinned cURL entries, rejecting anything
 * that is not public HTTPS. Returns false when the endpoint is not permitted.
 */
function safe_webhook_resolve(string $url): array|false {
    if (!valid_http_url($url)) return false;
    $host=strtolower(rtrim((string)parse_url($url,PHP_URL_HOST),'.'));
    if ($host==='' || str_contains($host,':') || $host==='localhost' || !str_contains($host,'.') || preg_match('/\.(local|localhost|internal|test|invalid|example)$/',$host)) return false;
    $ips=[];
    if (filter_var($host,FILTER_VALIDATE_IP)) $ips[]=$host;
    else {
        foreach (dns_get_record($host,DNS_A|DNS_AAAA) ?: [] as $record) { if (!empty($record['ip'])) $ips[]=$record['ip']; if (!empty($record['ipv6'])) $ips[]=$record['ipv6']; }
    }
    if (!$ips) return false;
    $pinned=[];
    foreach (array_unique($ips) as $ip) {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        $pinned[] = str_contains($ip,':') ? $host.':443:['.$ip.']' : $host.':443:'.$ip;
    }
    return $pinned;
}

function client_ip(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/**
 * Configuration problems that would break payments or security. Surfaced on the
 * dashboard and by the worker instead of failing silently later.
 *
 * @return list<string>
 */
function tg_config_problems(): array {
    $problems = [];

    $key = (string)envv('APP_ENCRYPTION_KEY', '');
    if (!preg_match('/^[a-f0-9]{64}$/i', $key)) {
        $problems[] = 'APP_ENCRYPTION_KEY must be 64 hex characters (32 random bytes). Connection secrets cannot be stored until it is set.';
    }

    $appUrl = (string)envv('APP_URL', '');
    if ($appUrl === '' || !filter_var($appUrl, FILTER_VALIDATE_URL)) {
        $problems[] = 'APP_URL must be the full public base URL, e.g. https://pay.example.com.';
    } else {
        $scheme = strtolower((string)parse_url($appUrl, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($appUrl, PHP_URL_HOST));
        $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local');
        if ($scheme !== 'https' && !$localHost) {
            $problems[] = 'APP_URL should use https:// outside local development; checkout links are shared with customers.';
        }
    }

    $secure = filter_var(envv('SESSION_SECURE', 'true'), FILTER_VALIDATE_BOOLEAN);
    $localOnly = str_starts_with(strtolower($appUrl), 'http://localhost') || str_starts_with(strtolower($appUrl), 'http://127.0.0.1');
    if (!$secure && !$localOnly) {
        $problems[] = 'SESSION_SECURE is false while APP_URL is not localhost. Session cookies could travel over plain HTTP.';
    }

    if (db_driver() === 'mysql' && in_array((string)envv('DB_PASSWORD', ''), ['', 'change-me'], true)) {
        $problems[] = 'DB_PASSWORD is empty or the sample value; set a real database password.';
    }

    return $problems;
}
