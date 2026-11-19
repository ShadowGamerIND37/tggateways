<?php
declare(strict_types=1);

/**
 * Central error handling. Any uncaught throwable ends up here so a deployment
 * never leaks a stack trace or serves a half-rendered page: JSON clients get a
 * JSON error, browsers get a short HTML page, and `log_errors` decides whether
 * details go to the server log.
 *
 * Whether PHP may print diagnostics into the response body.
 *
 * On the web SAPI that must be false: a stray notice corrupts JSON, breaks
 * header() calls and leaks paths to customers. CLI keeps it on so the test suite
 * and manual runs surface problems immediately. APP_DEBUG=true re-enables it for
 * a development deployment.
 */
function tg_error_display_allowed(?string $sapi = null, ?string $debug = null): bool {
    $sapi ??= PHP_SAPI;
    if ($sapi === 'cli' || $sapi === 'phpdbg') return true;
    $debug ??= (string)envv('APP_DEBUG', 'false');
    return filter_var($debug, FILTER_VALIDATE_BOOLEAN);
}

function tg_install_error_handlers(): void {
    if (!tg_error_display_allowed()) {
        // Errors still go to the server log (error_log / stderr), never to output.
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
    }
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED);

    if (!headers_sent()) {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
    }

    set_exception_handler(static function (Throwable $error): void {
        $reference = bin2hex(random_bytes(4));
        error_log(sprintf('[%s] %s: %s in %s:%d', $reference, get_class($error), $error->getMessage(), $error->getFile(), $error->getLine()));

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }

        $wantsJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'json')
            || str_starts_with((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/');
        if ($wantsJson) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Internal server error', 'reference' => $reference], JSON_UNESCAPED_SLASHES);
            return;
        }

        $shown = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Something went wrong</title>'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>body{font:15px/1.7 system-ui,sans-serif;background:#f6f8fb;color:#0b1329;display:grid;place-items:center;min-height:100vh;margin:0}'
            . 'main{background:#fff;border:1px solid #e7ebf2;border-radius:16px;padding:36px;max-width:460px;text-align:center}'
            . 'code{background:#f1f4f9;padding:2px 7px;border-radius:6px;font-size:13px}a{color:#2563eb}</style></head>'
            . '<body><main><h1>Something went wrong</h1>'
            . '<p>The request could not be completed. If you already paid, keep your UPI reference and contact the merchant — do not pay again.</p>'
            . '<p>Reference: <code>'.$shown.'</code></p>'
            . '<p><a href="/">Return to the home page</a></p></main></body></html>';
    });
}
