<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/migrate.php';

// Self-upgrading schema: existing containers get new tables on the next
// dashboard request instead of requiring a manual SQL run. Cheap when there is
// nothing to do (one indexed SELECT). Failures are logged, never fatal — the
// panel must still open so an operator can reach the diagnostics.
if (PHP_SAPI !== 'cli' || getenv('TG_SKIP_MIGRATIONS') === false) {
    try {
        if (tg_migrations_pending()) tg_run_migrations();
    } catch (Throwable $e) {
        error_log('migration: '.$e->getMessage());
    }
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('tg_session');
    $testId = envv('TG_TEST_SESSION_ID');
    if ($testId !== null && $testId !== '') session_id(preg_replace('/[^A-Za-z0-9,-]/','',$testId));
    session_set_cookie_params([
        'lifetime'=>0,
        'path'=>'/',
        'secure'=>filter_var(envv('SESSION_SECURE','true'),FILTER_VALIDATE_BOOLEAN),
        'httponly'=>true,
        'samesite'=>'Lax',
    ]);
    // Reject attacker-supplied session ids. Disabled for the CLI harness, which
    // injects a known id to simulate one browser across separate processes.
    if (PHP_SAPI !== 'cli') ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)max(1440, (int)envv('SESSION_LIFETIME', '86400')));
    session_start();
}
start_session();

function current_merchant(): ?array {
    if (!isset($_SESSION['merchant_id'])) return null;
    return ['id'=>(int)$_SESSION['merchant_id'],'name'=>(string)($_SESSION['merchant_name']??'Merchant'),'email'=>(string)($_SESSION['merchant_email']??'')];
}

function require_login(): array {
    $m=current_merchant();
    if (!$m) { redirect_to('/login.php'); }
    return $m;
}

function login_merchant(array $merchant): void {
    // Regenerating the id must also retire the pre-login CSRF token: the old
    // token was handed to an unauthenticated page and must not survive login.
    unset($_SESSION['csrf']);
    session_regenerate_id(true);
    $_SESSION['merchant_id']=(int)$merchant['id'];
    $_SESSION['merchant_name']=(string)$merchant['name'];
    $_SESSION['merchant_email']=(string)$merchant['email'];
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}

function verify_csrf(): void {
    $given = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($given === '' || !hash_equals(csrf_token(), $given)) { http_response_code(419); exit('Security token expired. Refresh and try again.'); }
}

function h(?string $s): string { return htmlspecialchars($s??'', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
