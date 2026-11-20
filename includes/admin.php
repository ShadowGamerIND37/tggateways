<?php
declare(strict_types=1);
/**
 * Administrator accounts and sessions.
 *
 * Admins are a table of their own, never a flag on `merchants`. A merchant row
 * can be created by anyone through /register.php, so if admin-ness were a
 * column there, a registration or profile bug could escalate a paying customer
 * into an operator. Two separate tables mean privilege cannot be granted by
 * writing to a row the merchant controls.
 *
 * Sessions are separate from merchant sessions as well, and stored server-side
 * as a sha256 of a random token so a database leak does not hand over logins.
 * An admin request never reads `$_SESSION['merchant_id']`, and a merchant
 * request never reads the admin session.
 */
/** Username/password seeded on first run. Change these, not the code, to rotate. */
const TG_ADMIN_SEED_EMAIL = 'admin';
const TG_ADMIN_SEED_PASSWORD = 'admin';

// Loaded by auth.php for the first-run seed, so this is a guarded require and
// not a hard one: a hard require would be a require cycle (auth → admin → auth)
// and PHP would run admin.php's body before the constants above were defined.
if (!function_exists('csrf_token')) require_once __DIR__.'/auth.php';
// tg_audit() lives with the merchant helpers; the admin panel records every
// privileged action to the same audit_log so one stream shows both consoles.
require_once __DIR__.'/merchant.php';

/**
 * Create the default operator if no admin exists yet.
 *
 * Returns the created row, or null when an admin already existed. Safe to call
 * on every request: the count query is indexed and bails immediately.
 */
function tg_admin_seed_default(): ?array {
    try {
        if (!tg_table_exists('admins')) return null;
        $pdo = db();
        if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) return null;

        $stmt = $pdo->prepare(
            'INSERT INTO admins(name,email,password,role,status,must_change_password,created_at)
             VALUES(?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            'Administrator',
            TG_ADMIN_SEED_EMAIL,
            password_hash(TG_ADMIN_SEED_PASSWORD, PASSWORD_DEFAULT),
            'owner',
            'active',
            1,
            db_now(),
        ]);
        $id = (int)$pdo->lastInsertId();
        error_log('admin: seeded the default operator account (email "'.TG_ADMIN_SEED_EMAIL.'") — the password must be changed at first sign-in');
        return ['id' => $id, 'email' => TG_ADMIN_SEED_EMAIL];
    } catch (Throwable $e) {
        error_log('admin seed: '.$e->getMessage());
        return null;
    }
}

/**
 * Emit the admin cookie.
 *
 * setcookie() writes through PHP's header layer, which does nothing under the
 * CLI harness, so the test runner (and any future CLI smoke test) would never
 * see the session and every request would look unauthenticated. go through
 * tg_header() — the same shim the rest of the app uses — so there is exactly
 * one code path in both environments.
 */
function tg_admin_set_cookie(string $value, int $expires): void {
    $parts = [tg_admin_session_cookie().'='.$value, 'Path=/'];
    // expires === 0 means a browser-session cookie. Emitting "Max-Age=0" for
    // that case tells the browser to delete the cookie immediately, which
    // silently broke every sign-in; omit both attributes instead.
    if ($expires > 0) {
        $parts[] = 'Expires='.gmdate('D, d M Y H:i:s', $expires).' GMT';
        $parts[] = 'Max-Age='.max(0, $expires - time());
    } elseif ($expires < 0) {
        // Explicit deletion.
        $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        $parts[] = 'Max-Age=0';
    }
    if (filter_var(envv('SESSION_SECURE', 'true'), FILTER_VALIDATE_BOOLEAN)) $parts[] = 'Secure';
    $parts[] = 'HttpOnly';
    $parts[] = 'SameSite=Lax';
    $cookie = implode('; ', $parts);
    tg_header('Set-Cookie: '.$cookie);
    if (PHP_SAPI !== 'cli') setcookie(tg_admin_session_cookie(), $value, [
        'expires' => $expires, 'path' => '/',
        'secure' => filter_var(envv('SESSION_SECURE', 'true'), FILTER_VALIDATE_BOOLEAN),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

/** True when at least one usable operator exists. */
function tg_admin_exists(): bool {
    try {
        if (!tg_table_exists('admins')) return false;
        return (int)db()->query("SELECT COUNT(*) FROM admins WHERE status='active'")->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function tg_admin_session_cookie(): string {
    return 'tg_admin_session';
}

function tg_admin_current(): ?array {
    static $cached = false;
    static $row = null;
    if ($cached) return $row;
    $cached = true;

    $token = (string)($_COOKIE[tg_admin_session_cookie()] ?? '');
    if ($token === '' || strlen($token) < 32) { $row = null; return null; }

    try {
        $pdo = db();
        if (!tg_table_exists('admin_sessions')) { $row = null; return null; }
        $stmt = $pdo->prepare(
            "SELECT a.id,a.name,a.email,a.role,a.status,a.must_change_password
             FROM admin_sessions s JOIN admins a ON a.id=s.admin_id
             WHERE s.token_hash=? AND s.revoked_at IS NULL AND a.status='active' LIMIT 1"
        );
        $stmt->execute([hash('sha256', $token)]);
        $found = $stmt->fetch();
        if (!$found) { $row = null; return null; }

        // Touch the session at most once a minute so a busy panel does not write
        // on every request.
        $pdo->prepare('UPDATE admin_sessions SET last_seen_at=? WHERE token_hash=? AND (last_seen_at IS NULL OR last_seen_at<?)')
            ->execute([db_now(), hash('sha256', $token), db_now(-60)]);
        $row = $found;
        return $row;
    } catch (Throwable $e) {
        error_log('admin session: '.$e->getMessage());
        $row = null;
        return null;
    }
}

/** Sign in. Returns an error string, or null on success. */
function tg_admin_login(string $email, string $password): ?string {
    $email = trim($email);
    if ($email === '' || $password === '') return 'Enter both fields.';
    try {
        $pdo = db();
        if (!tg_table_exists('admins')) return 'The admin tables are missing. Run the migrations.';
        $stmt = $pdo->prepare('SELECT * FROM admins WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        // Compare against a dummy hash when the row is absent so a missing
        // account and a wrong password take the same time.
        $hash = $admin ? (string)$admin['password'] : '$2y$10$usesomesillystringforsalt000000000000000000000000000000000';
        $ok = password_verify($password, $hash);

        if (!$admin || !$ok) {
            tg_audit(null, 'admin.login_failed', substr($email, 0, 120));
            return 'Those details did not match an administrator account.';
        }
        if (($admin['status'] ?? 'active') !== 'active') {
            return 'This administrator account is disabled.';
        }
        if (password_needs_rehash((string)$admin['password'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE admins SET password=? WHERE id=?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
        }

        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO admin_sessions(admin_id,token_hash,ip,user_agent,created_at,last_seen_at) VALUES(?,?,?,?,?,?)')
            ->execute([
                $admin['id'],
                hash('sha256', $token),
                function_exists('client_ip') ? client_ip() : null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                db_now(),
                db_now(),
            ]);
        $pdo->prepare('UPDATE admins SET last_login_at=?,last_login_ip=? WHERE id=?')
            ->execute([db_now(), function_exists('client_ip') ? client_ip() : null, $admin['id']]);

        // Session cookie: HttpOnly, SameSite=Lax, and Secure when the
        // deployment runs behind TLS. Expires 0 = browser-session cookie.
        tg_admin_set_cookie($token, 0);
        $_COOKIE[tg_admin_session_cookie()] = $token;
        tg_audit(null, 'admin.login', substr($email, 0, 120));
        return null;
    } catch (Throwable $e) {
        error_log('admin login: '.$e->getMessage());
        return 'Sign-in could not be completed.';
    }
}

function tg_admin_logout(): void {
    $token = (string)($_COOKIE[tg_admin_session_cookie()] ?? '');
    if ($token !== '') {
        try {
            db()->prepare('UPDATE admin_sessions SET revoked_at=? WHERE token_hash=?')
                ->execute([db_now(), hash('sha256', $token)]);
        } catch (Throwable $e) {
            error_log('admin logout: '.$e->getMessage());
        }
    }
    tg_admin_set_cookie('', time() - 3600);
    unset($_COOKIE[tg_admin_session_cookie()]);
}

/** Every admin page starts with this. Redirects to the gate when unsigned. */
function tg_admin_require(): array {
    $admin = tg_admin_current();
    if (!$admin) {
        $target = (string)($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
        redirect_to('/admin/login.php?next='.rawurlencode($target));
    }
    return $admin;
}

/**
 * CSRF for the admin panel.
 *
 * The merchant panel's csrf_token() lives in the PHP session, which is fine
 * there, but the admin panel must not borrow a token from a session a merchant
 * can also reach. This keeps its own key and its own field name.
 */
function tg_admin_csrf(): string {
    if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['admin_csrf'];
}

function tg_admin_verify_csrf(): void {
    $given = (string)($_POST['admin_csrf'] ?? $_SERVER['HTTP_X_ADMIN_CSRF'] ?? '');
    if ($given === '' || !hash_equals(tg_admin_csrf(), $given)) {
        http_response_code(419);
        exit('Security token expired. Refresh the page and try again.');
    }
}

/** The role ladder. Only `owner` may mint or demote other admins. */
function tg_admin_can(string $ability): bool {
    $admin = tg_admin_current();
    if (!$admin) return false;
    $role = (string)($admin['role'] ?? 'viewer');
    return match ($ability) {
        'manage_admins' => $role === 'owner',
        'manage_merchants', 'manage_billing', 'manage_addons', 'manage_support', 'view_pii' => in_array($role, ['owner', 'staff'], true),
        default => true,
    };
}

/**
 * Refuse to weaken the last owner.
 *
 * Without this, an owner can demote themselves or be deleted, leaving a
 * deployment nobody can administer — a one-way door with no CLI recovery.
 */
function tg_admin_owner_count(int $excluding = 0): int {
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM admins WHERE role='owner' AND status='active' AND id<>?");
        $stmt->execute([$excluding]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}
