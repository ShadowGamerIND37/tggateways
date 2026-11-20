<?php
declare(strict_types=1);
/**
 * Test-only request runner. Executes ONE application script in a fresh process
 * with the given method/query/body/headers/session, then writes the captured
 * response (status, headers, body) as JSON.
 *
 * Running each step in its own process is deliberate: handlers call exit() via
 * json_response()/redirect_to(), which is exactly how they behave under Apache.
 * Session continuity comes from PHP's file session store, like a real client
 * carrying a cookie between requests.
 *
 * Usage: php tests/request.php /path/to/spec.json
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$specPath = $argv[1] ?? '';
$spec = is_file($specPath) ? json_decode((string)file_get_contents($specPath), true) : null;
if (!is_array($spec)) { fwrite(STDERR, "Invalid spec\n"); exit(2); }

foreach (($spec['env'] ?? []) as $key => $value) putenv($key.'='.$value);

session_save_path((string)($spec['session_dir'] ?? sys_get_temp_dir()));
if (!empty($spec['session_id'])) {
    putenv('TG_TEST_SESSION_ID='.$spec['session_id']);
    session_id((string)$spec['session_id']);
}

$bodyFile = tempnam(sys_get_temp_dir(), 'tgbody');
file_put_contents($bodyFile, (string)($spec['body'] ?? ''));
putenv('TG_TEST_BODY_PATH='.$bodyFile);

// Cookies the test client is carrying. The merchant session rides in the PHP
// session file, but the admin panel deliberately uses its own cookie so that a
// merchant session can never be mistaken for an operator, so the harness has to
// replay it the way a browser would.
$_COOKIE = [];
foreach (($spec['cookies'] ?? []) as $name => $value) $_COOKIE[(string)$name] = (string)$value;

$_GET = $spec['query'] ?? [];
$_POST = $spec['post'] ?? [];
$_REQUEST = $_GET + $_POST;
// Simulated uploads: each entry is [field, name, tmp_path, error, size]. The
// app validates real bytes from the temp path, so tests exercise the same
// magic-byte sniffing a browser upload would.
$_FILES = [];
foreach (($spec['files'] ?? []) as $entry) {
    [$field, $name, $tmpPath, $error, $size] = $entry;
    $field = (string)$field;
    if (!isset($_FILES[$field])) {
        $_FILES[$field] = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    }
    $_FILES[$field]['name'][] = $name;
    $_FILES[$field]['type'][] = 'application/octet-stream';
    $_FILES[$field]['tmp_name'][] = $tmpPath;
    $_FILES[$field]['error'][] = $error;
    $_FILES[$field]['size'][] = $size;
}
$_SERVER = array_merge([
    'REQUEST_METHOD' => strtoupper((string)($spec['method'] ?? 'GET')),
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_HOST' => 'localhost',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'SCRIPT_NAME' => '/'.basename((string)$spec['script']),
    'REQUEST_URI' => '/'.basename((string)$spec['script']).(!empty($spec['query']) ? '?'.http_build_query($spec['query']) : ''),
], $spec['server'] ?? []);
foreach (($spec['headers'] ?? []) as $name => $value) {
    $_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
if (isset($spec['headers']['Authorization'])) $_SERVER['HTTP_AUTHORIZATION'] = $spec['headers']['Authorization'];

// PHP does not populate $_SERVER['REQUEST_METHOD'] in CLI, and tg_header() keeps
// headers only in $GLOBALS under CLI, so mirror what Apache would expose.
$_SERVER['REQUEST_METHOD'] = strtoupper((string)($spec['method'] ?? 'GET'));

$GLOBALS['TG_RESPONSE_HEADERS'] = [];
$GLOBALS['TG_TEST_EXCEPTION'] = null;
http_response_code(200);
ob_start();

$sessionsBefore = glob(rtrim((string)($spec['session_dir'] ?? sys_get_temp_dir()), '/').'/sess_*') ?: [];

register_shutdown_function(static function () use ($spec, $bodyFile, $sessionsBefore): void {
    $headers = $GLOBALS['TG_RESPONSE_HEADERS'] ?? [];
    $status = 200;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) $status = (int)$m[1];
    }
    $declared = http_response_code();
    if (is_int($declared) && $declared >= 100) $status = $declared;

    $body = '';
    while (ob_get_level() > 0) {
        $body = (string)ob_get_contents().$body;
        ob_end_clean();
    }
    @unlink($bodyFile);
    // session_regenerate_id() (used on login/registration) issues a new id; the
    // test client must carry it forward like a browser would.
    $dir = rtrim((string)($spec['session_dir'] ?? sys_get_temp_dir()), '/');
    $sessionsAfter = glob($dir.'/sess_*') ?: [];
    $newSessions = array_values(array_diff($sessionsAfter, $sessionsBefore));
    $nextSession = null;
    if (count($newSessions) >= 1) {
        $nextSession = preg_replace('/^sess_/', '', basename((string)end($newSessions)));
    }

    file_put_contents((string)$spec['output'], json_encode([
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
        'exception' => $GLOBALS['TG_TEST_EXCEPTION'],
        'session_id' => $nextSession,
    ], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
});

set_exception_handler(static function (Throwable $e): void {
    $GLOBALS['TG_TEST_EXCEPTION'] = get_class($e).': '.$e->getMessage();
    http_response_code(500);
});

$target = (string)$spec['script'];
if (!is_file($target)) {
    $GLOBALS['TG_TEST_EXCEPTION'] = 'Missing script: '.$target;
    http_response_code(500);
    return;
}

require $target;
