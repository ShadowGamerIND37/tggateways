<?php
declare(strict_types=1);
/**
 * Administrator accounts.
 *
 * Three invariants this page protects, each of which would otherwise be a
 * one-way door:
 *   1. The last owner cannot be demoted or deleted. Losing every owner means no
 *      one can ever reach the panel again without hand-editing the database.
 *   2. An admin cannot delete themselves mid-session.
 *   3. Only the owner role can mint or demote admins at all.
 */
require_once __DIR__.'/../includes/admin-shell.php';

$pdo = db();
$me = tg_admin_require();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tg_admin_verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    // Anyone may rotate their own password, even a viewer.
    if ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $next = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $stmt = $pdo->prepare('SELECT password FROM admins WHERE id=?');
        $stmt->execute([$me['id']]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $error = 'Your current password did not match.';
        } elseif (strlen($next) < 10) {
            $error = 'Use at least 10 characters. The seeded default is 5 for a reason: it must be replaced.';
        } elseif ($next !== $confirm) {
            $error = 'The two new passwords did not match.';
        } elseif (password_verify($next, $hash)) {
            $error = 'Choose a password different from the current one.';
        } else {
            $pdo->prepare('UPDATE admins SET password=?,must_change_password=0 WHERE id=?')
                ->execute([password_hash($next, PASSWORD_DEFAULT), $me['id']]);
            // Revoke every other session for this admin: a password change must
            // invalidate whatever was already signed in.
            $pdo->prepare('UPDATE admin_sessions SET revoked_at=? WHERE admin_id=? AND revoked_at IS NULL AND token_hash<>?')
                ->execute([db_now(), $me['id'], hash('sha256', (string)($_COOKIE[tg_admin_session_cookie()] ?? ''))]);
            tg_audit(null, 'admin.password_changed', (string)$me['email']);
            $notice = 'Password changed. Other sessions signed out.';
        }
    } elseif (!tg_admin_can('manage_admins')) {
        $error = 'Only an owner can manage administrators.';
    } elseif ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['owner', 'staff', 'viewer'], true) ? (string)$_POST['role'] : 'staff';

        if ($name === '' || $email === '') {
            $error = 'A name and a sign-in email are both required.';
        } elseif (strlen($pass) < 10) {
            $error = 'Give the new administrator a password of at least 10 characters.';
        } else {
            try {
                $pdo->prepare('INSERT INTO admins(name,email,password,role,status,must_change_password,created_at) VALUES(?,?,?,?,?,1,?)')
                    ->execute([mb_substr($name, 0, 120), mb_substr($email, 0, 190), password_hash($pass, PASSWORD_DEFAULT), $role, 'active', db_now()]);
                tg_audit(null, 'admin.created', $email.' ('.$role.')');
                $notice = $name.' can now sign in at /admin/login.php.';
            } catch (Throwable $e) {
                $error = 'That email is already used by another administrator.';
            }
        }
    } elseif ($action === 'toggle') {
        $target = (int)($_POST['admin_id'] ?? 0);
        if ($target === (int)$me['id']) {
            $error = 'You cannot disable your own account.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE id=?');
            $stmt->execute([$target]);
            $t = $stmt->fetch();
            if (!$t) {
                $error = 'That administrator no longer exists.';
            } else {
                $newStatus = $t['status'] === 'active' ? 'disabled' : 'active';
                if ($newStatus === 'disabled' && $t['role'] === 'owner' && tg_admin_owner_count($target) === 0) {
                    $error = 'That is the last active owner. Promote someone else first.';
                } else {
                    $pdo->prepare('UPDATE admins SET status=? WHERE id=?')->execute([$newStatus, $target]);
                    if ($newStatus === 'disabled') {
                        $pdo->prepare('UPDATE admin_sessions SET revoked_at=? WHERE admin_id=? AND revoked_at IS NULL')
                            ->execute([db_now(), $target]);
                    }
                    tg_audit(null, 'admin.' . ($newStatus === 'active' ? 'enabled' : 'disabled'), (string)$t['email']);
                    $notice = $t['email'].' is now '.$newStatus.'.';
                }
            }
        }
    } elseif ($action === 'role') {
        $target = (int)($_POST['admin_id'] ?? 0);
        $role = in_array($_POST['new_role'] ?? '', ['owner', 'staff', 'viewer'], true) ? (string)$_POST['new_role'] : '';
        if ($role === '') {
            $error = 'Unknown role.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE id=?');
            $stmt->execute([$target]);
            $t = $stmt->fetch();
            if (!$t) {
                $error = 'That administrator no longer exists.';
            } elseif ($t['role'] === 'owner' && $role !== 'owner' && tg_admin_owner_count($target) === 0) {
                // Checked before the self-change rule: an owner demoting
                // themselves is precisely how a deployment loses its last owner.
                $error = 'That is the last active owner. Promote someone else before demoting them.';
            } elseif ($target === (int)$me['id']) {
                $error = 'You cannot change your own role.';
            } else {
                $pdo->prepare('UPDATE admins SET role=? WHERE id=?')->execute([$role, $target]);
                tg_audit(null, 'admin.role_changed', (string)$t['email'].' → '.$role);
                $notice = $t['email'].' is now '.$role.'.';
            }
        }
    } elseif ($action === 'delete') {
        $target = (int)($_POST['admin_id'] ?? 0);
        if ($target === (int)$me['id']) {
            $error = 'You cannot delete your own account.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE id=?');
            $stmt->execute([$target]);
            $t = $stmt->fetch();
            if (!$t) {
                $error = 'That administrator no longer exists.';
            } elseif ($t['role'] === 'owner' && tg_admin_owner_count($target) === 0) {
                $error = 'That is the last active owner. Create another owner first.';
            } else {
                $pdo->prepare('UPDATE admin_sessions SET revoked_at=? WHERE admin_id=?')->execute([db_now(), $target]);
                $pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$target]);
                tg_audit(null, 'admin.deleted', (string)$t['email']);
                $notice = $t['email'].' was removed.';
            }
        }
    }
}

$admins = [];
try {
    // "Live" means a session that was seen in the last 30 minutes. Bound value,
    // not interpolated, so the window cannot be abused to inject SQL.
    $stmt = $pdo->prepare(
        "SELECT a.*, (SELECT COUNT(*) FROM admin_sessions s
                      WHERE s.admin_id=a.id AND s.revoked_at IS NULL AND s.last_seen_at>=?) live
         FROM admins a ORDER BY a.id"
    );
    $stmt->execute([db_now(-1800)]);
    $admins = $stmt->fetchAll();
} catch (Throwable $e) {
    try { $admins = $pdo->query('SELECT *, 0 AS live FROM admins ORDER BY id')->fetchAll(); }
    catch (Throwable $e2) { error_log('admin admins: '.$e2->getMessage()); }
}

admin_header('Administrators', 'admins');
admin_flash($notice, $error);
?>

<div class="admin-panel">
  <div class="admin-panel-head"><h2>Administrator accounts</h2></div>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($admins as $a): ?>
        <?php $isMe = (int)$a['id'] === (int)$me['id']; ?>
        <tr>
          <td>
            <div style="font-weight:600"><?=h((string)$a['name'])?><?=$isMe ? ' <span class="admin-pill admin-pill-info" style="margin-left:5px">You</span>' : ''?></div>
            <div class="muted" style="font-size:11px"><?=h((string)$a['email'])?></div>
          </td>
          <td>
            <?php if (tg_admin_can('manage_admins') && !$isMe): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
                <input type="hidden" name="action" value="role">
                <input type="hidden" name="admin_id" value="<?=(int)$a['id']?>">
                <select class="admin-select admin-btn-sm" name="new_role" style="width:auto;padding:5px 8px;font-size:11.5px" onchange="this.form.submit()">
                  <?php foreach (['owner', 'staff', 'viewer'] as $r): ?>
                    <option value="<?=$r?>"<?=$a['role'] === $r ? ' selected' : ''?>><?=ucfirst($r)?></option>
                  <?php endforeach ?>
                </select>
              </form>
            <?php else: ?>
              <span class="admin-role admin-role-<?=h((string)$a['role'])?>"><?=h(ucfirst((string)$a['role']))?></span>
            <?php endif ?>
          </td>
          <td>
            <span class="admin-pill <?=$a['status'] === 'active' ? 'admin-pill-ok' : 'admin-pill-bad'?>"><?=h(ucfirst((string)$a['status']))?></span>
            <?php if ((int)($a['must_change_password'] ?? 0) === 1): ?>
              <span class="admin-pill admin-pill-warn">Default password</span>
            <?php endif ?>
          </td>
          <td class="muted" style="font-size:11px">
            <?=($a['last_login_at'] ?? null) ? h(substr((string)$a['last_login_at'], 0, 16)) : 'never'?>
            <?php if ((int)($a['live'] ?? 0) > 0): ?><br><span style="color:#047857;font-weight:600"><?=(int)$a['live']?> active session<?=(int)$a['live'] === 1 ? '' : 's'?></span><?php endif ?>
          </td>
          <td style="text-align:right">
            <?php if (tg_admin_can('manage_admins') && !$isMe): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('<?=$a['status'] === 'active' ? 'Disable' : 'Enable'?> this administrator?')">
                <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="admin_id" value="<?=(int)$a['id']?>">
                <button class="admin-btn admin-btn-ghost admin-btn-sm"><?=$a['status'] === 'active' ? 'Disable' : 'Enable'?></button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Permanently remove this administrator?')">
                <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="admin_id" value="<?=(int)$a['id']?>">
                <button class="admin-btn admin-btn-danger admin-btn-sm"><?=icon('trash', 12)?></button>
              </form>
            <?php else: ?>
              <span class="muted" style="font-size:11px">—</span>
            <?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px">
  <div class="admin-panel" id="password">
    <div class="admin-panel-head"><h2>Change your password</h2></div>
    <div class="admin-panel-body">
      <?php if ((int)($me['must_change_password'] ?? 0) === 1): ?>
        <div class="callout callout-warning" style="margin-bottom:16px">
          <?=icon('alert-circle', 15)?><span>You are still using the default password. Change it now.</span>
        </div>
      <?php endif ?>
      <form method="post">
        <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
        <input type="hidden" name="action" value="password">
        <div class="admin-field">
          <label for="current_password">Current password</label>
          <input class="admin-input" id="current_password" name="current_password" type="password" required autocomplete="current-password">
        </div>
        <div class="admin-field">
          <label for="new_password">New password</label>
          <input class="admin-input" id="new_password" name="new_password" type="password" required minlength="10" autocomplete="new-password">
          <div class="hint">At least 10 characters. Changing it signs out your other sessions.</div>
        </div>
        <div class="admin-field">
          <label for="confirm_password">Repeat the new password</label>
          <input class="admin-input" id="confirm_password" name="confirm_password" type="password" required minlength="10" autocomplete="new-password">
        </div>
        <button class="admin-btn admin-btn-primary"><?=icon('lock', 14)?> Update password</button>
      </form>
    </div>
  </div>

  <?php if (tg_admin_can('manage_admins')): ?>
  <div class="admin-panel">
    <div class="admin-panel-head"><h2>Add an administrator</h2></div>
    <div class="admin-panel-body">
      <form method="post">
        <input type="hidden" name="admin_csrf" value="<?=h(tg_admin_csrf())?>">
        <input type="hidden" name="action" value="create">
        <div class="admin-field">
          <label for="name">Name</label>
          <input class="admin-input" id="name" name="name" required maxlength="120" placeholder="Priya Sharma">
        </div>
        <div class="admin-field">
          <label for="email">Sign-in email</label>
          <input class="admin-input" id="email" name="email" type="email" required maxlength="190" placeholder="ops@example.com">
        </div>
        <div class="admin-field">
          <label for="password">Initial password</label>
          <input class="admin-input" id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
          <div class="hint">They are prompted to change it after signing in.</div>
        </div>
        <div class="admin-field">
          <label for="role">Role</label>
          <select class="admin-select" id="role" name="role">
            <option value="staff">Staff — manage merchants, billing and support</option>
            <option value="viewer">Viewer — read-only</option>
            <option value="owner">Owner — full control, including administrators</option>
          </select>
        </div>
        <button class="admin-btn admin-btn-primary"><?=icon('users', 14)?> Create administrator</button>
      </form>
    </div>
  </div>
  <?php endif ?>
</div>

<?php admin_footer(); ?>
