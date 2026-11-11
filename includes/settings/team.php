<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/mailer.php';

const INVITE_VALID_DAYS = 3;
const PERM_LABELS = ['none' => 'No access', 'view' => 'View only', 'manage' => 'Can manage'];

function temp_password(): string
{
    return strtoupper(bin2hex(random_bytes(3))) . '-' . bin2hex(random_bytes(5)) . '-Aa!';   // 12+ characters, shown once
}

function active_admin_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'admin' AND is_active = 1")->fetchColumn();
}

function tab_handle(PDO $pdo, array $admin): void
{
    if (!is_admin()) { deny_access('team:handle'); }   // second check: never rely only on the tab being hidden
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    $load = static function (PDO $pdo, int $id): ?array {
        $s = $pdo->prepare('SELECT * FROM admins WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    };

    if ($action === 'add_user') {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $perm = module_enabled('health') && in_array($_POST['perm'] ?? '', ['none', 'view', 'manage'], true) ? $_POST['perm'] : 'none';   // no access while the control is hidden
        if ($role === 'admin') { $perm = 'manage'; }
        $errors = [];
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) { $errors[] = 'The username needs 3 to 30 characters: letters, digits, dots, dashes or underscores.'; }
        if ($name === '' || mb_strlen($name) > 80) { $errors[] = 'Enter the person\'s name (up to 80 characters).'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) { $errors[] = 'Enter a valid email address.'; }
        if (!$errors) {
            $s = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ? OR email = ?');
            $s->execute([$username, $email]);
            if ((int) $s->fetchColumn() > 0) { $errors[] = 'That username or email address is already in use.'; }
        }
        if ($errors) { flash(implode(' ', $errors), 'bad'); return; }
        $pw = temp_password();
        $pdo->prepare('INSERT INTO admins (username, email, name, password_hash, role, perm_integrations, must_change_password) VALUES (?,?,?,?,?,?,1)')
            ->execute([$username, $email, $name, hash_password($pw), $role, $perm]);
        $_SESSION['temp_pw'] = ['username' => $username, 'password_enc' => secret_encrypt($pw), 'kind' => 'created'];   // encrypted while it waits to be shown once
        log_activity('warning', 'Team member added', $username . ' · ' . $role . ' · integrations ' . $perm);
        flash('Account created for ' . $name . '. Copy the temporary password now: it will not be shown again.');
        return;
    }

    if ($action === 'invite') {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $perm = module_enabled('health') && in_array($_POST['perm'] ?? '', ['none', 'view', 'manage'], true) ? $_POST['perm'] : 'none';   // no access while the control is hidden
        if ($role === 'admin') { $perm = 'manage'; }
        $errors = [];
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) { $errors[] = 'The username needs 3 to 30 characters: letters, digits, dots, dashes or underscores.'; }
        if ($name === '' || mb_strlen($name) > 80) { $errors[] = 'Enter the person\'s name (up to 80 characters).'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) { $errors[] = 'Enter a valid email address.'; }
        $base = mail_base_url($pdo);
        if ($base === null) { $errors[] = 'Set the site address in Settings > Email first: the invitation contains a link to it.'; }
        if (!$errors) {
            $s = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ? OR email = ?');
            $s->execute([$username, $email]);
            if ((int) $s->fetchColumn() > 0) { $errors[] = 'That username or email address is already in use.'; }
        }
        if ($errors) { flash(implode(' ', $errors), 'bad'); return; }
        $pdo->prepare('DELETE FROM invitations WHERE (email = ? OR username = ?) AND used_at IS NULL')->execute([$email, $username]);   // a new invitation replaces an open one
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO invitations (email, username, name, role, perm_integrations, token_hash, invited_by, expires_at, created_at) VALUES (?,?,?,?,?,?,?, NOW() + INTERVAL ' . INVITE_VALID_DAYS . ' DAY, NOW())')
            ->execute([$email, $username, $name, $role, $perm, hash('sha256', $token), (string) $admin['username']]);
        [$status, , $note] = mail_send($pdo, $email, 'You are invited to Telecomlijn Admin',
            "Hello " . $name . ",\n\n" . ($admin['name'] ?? 'An admin') . " invited you to Telecomlijn Admin as " . $username . ". Open this link within " . INVITE_VALID_DAYS . " days to choose your password:\n\n"
            . $base . '/auth/invite.php?token=' . $token . "\n\nThe link works once. After that you can also add a second sign-in step (an authenticator app) under Settings > Account.\n", 'invite');
        log_activity('warning', 'Invitation sent', $username . ' · ' . $role . ' · ' . $status);
        flash($status === 'sent' ? 'Invitation sent to ' . $email . '.' : ($status === 'recorded' ? 'Invitation created for ' . $email . '. No mail server is set up, so copy the link from Settings > Email > Outbox.' : 'The invitation was created but the email failed: ' . $note . ' Open Settings > Email > Outbox to copy the link.'), $status === 'failed' ? 'bad' : 'ok');
        return;
    }

    if ($action === 'revoke_invite') {
        $pdo->prepare('DELETE FROM invitations WHERE id = ? AND used_at IS NULL')->execute([$id]);
        log_activity('warning', 'Invitation withdrawn', '#' . $id);
        flash('The invitation was withdrawn. Its link no longer works.');
        return;
    }

    $target = $load($pdo, $id);
    if (!$target) { flash('That account no longer exists.', 'bad'); return; }
    $isSelf = (int) $target['id'] === (int) $admin['id'];

    if ($action === 'update_user') {
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
        // with the health module off the integrations control is hidden: the stored value stays as it is
        $perm = module_enabled('health') ? (in_array($_POST['perm'] ?? '', ['none', 'view', 'manage'], true) ? $_POST['perm'] : 'none') : (string) $target['perm_integrations'];
        if ($role === 'admin' && module_enabled('health')) { $perm = 'manage'; }
        if ($target['role'] === 'admin' && $role !== 'admin' && (int) $target['is_active'] === 1 && active_admin_count($pdo) <= 1) {
            flash('There must always be at least one active admin. Make someone else an admin first.', 'bad'); return;
        }
        $pdo->prepare('UPDATE admins SET role = ?, perm_integrations = ? WHERE id = ?')->execute([$role, $perm, $id]);
        log_activity('warning', 'Access changed', $target['username'] . ': ' . $target['role'] . '/' . $target['perm_integrations'] . ' to ' . $role . '/' . $perm);
        flash('Access updated for ' . $target['name'] . '. It applies on their next click.');
        return;
    }

    if ($action === 'toggle_active') {
        if ($isSelf) { flash('You can not disable your own account.', 'bad'); return; }
        if ((int) $target['is_active'] === 1 && $target['role'] === 'admin' && active_admin_count($pdo) <= 1) {
            flash('There must always be at least one active admin.', 'bad'); return;
        }
        $pdo->prepare('UPDATE admins SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        log_activity('warning', (int) $target['is_active'] ? 'Account disabled' : 'Account enabled', $target['username']);
        flash($target['name'] . ((int) $target['is_active'] ? ' was disabled and is signed out.' : ' can sign in again.'));
        return;
    }

    if ($action === 'delete_user') {
        if ($isSelf) { flash('You can not delete your own account.', 'bad'); return; }
        if ((int) $target['is_active'] === 1) { flash('Disable the account first. Only a disabled account can be deleted.', 'bad'); return; }
        $pdo->prepare('DELETE FROM admins WHERE id = ? AND is_active = 0')->execute([$id]);   // related recovery codes and reset tokens go with it; tickets keep existing, unassigned
        log_activity('warning', 'Account deleted', $target['username'] . ' · ' . $target['role']);
        flash('The account of ' . $target['name'] . ' was deleted.');
        return;
    }

    if ($action === 'reset_2fa') {
        if ($isSelf) { flash('Turn off your own two-step sign-in from the Account tab.', 'bad'); return; }
        if (!(int) $target['totp_enabled']) { flash($target['name'] . ' does not use two-step sign-in.', 'bad'); return; }
        $pdo->prepare('UPDATE admins SET totp_secret_enc = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM admin_recovery_codes WHERE admin_id = ?')->execute([$id]);
        log_activity('warning', 'Two-step sign-in reset by admin', $target['username']);
        flash('Two-step sign-in was turned off for ' . $target['name'] . '. They can turn it on again from their Account tab.');
        return;
    }

    if ($action === 'reset_password') {
        if ($isSelf) { flash('Change your own password from the Account tab.', 'bad'); return; }
        $pw = temp_password();
        $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_password = 1, session_epoch = session_epoch + 1 WHERE id = ?')->execute([hash_password($pw), $id]);
        $_SESSION['temp_pw'] = ['username' => $target['username'], 'password_enc' => secret_encrypt($pw), 'kind' => 'reset'];
        log_activity('warning', 'Password reset by admin', $target['username']);
        flash('A new temporary password was set for ' . $target['name'] . '. Copy it now: it will not be shown again.');
        return;
    }
}

function tab_render(PDO $pdo, array $admin): void
{
    $field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $small = 'h-9 rounded-lg border border-line bg-panel px-2 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $btn = 'pressable inline-flex h-9 items-center rounded-lg border border-line px-3 text-sm font-semibold hover:bg-white/5';
    $members = $pdo->query('SELECT * FROM admins ORDER BY role = \'admin\' DESC, name')->fetchAll();
    $invites = $pdo->query('SELECT id, email, username, name, role, expires_at, created_at FROM invitations WHERE used_at IS NULL AND expires_at > NOW() ORDER BY id DESC')->fetchAll();
    $temp = $_SESSION['temp_pw'] ?? null;
    unset($_SESSION['temp_pw']);
    if ($temp) { $temp['password'] = secret_decrypt((string) ($temp['password_enc'] ?? '')); }
    ?>
<?php if ($temp): ?>
  <div role="alert" class="rise mb-6 rounded-2xl border border-butter/30 bg-butter-100 p-5">
    <p class="font-semibold text-butter">Temporary password for <span class="notranslate"><?= e($temp['username']) ?></span>. Copy it now, it will not be shown again.</p>
    <p class="mt-2 break-all rounded-xl bg-deep p-3 text-sm text-ink notranslate select-all"><?= e($temp['password']) ?></p>
    <p class="mt-2 text-sm text-soft">They must choose their own password at first sign-in. Send it to them through a safe channel, not by email in plain text if you can avoid it.</p>
  </div>
<?php endif; ?>

<section class="rise rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="team-h">
  <div class="border-b border-line p-5 sm:p-6">
    <h2 id="team-h" class="text-lg font-bold tracking-tight">People with a login</h2>
    <p class="mt-1 max-w-2xl text-sm text-soft"><strong class="text-ink">Admins</strong> can use everything, including this tab<?= module_enabled('health') ? ' and the integrations' : '' ?>.<?php if (module_enabled('health')): ?> <strong class="text-ink">Users</strong> can use the dashboard but only open the Integrations tab if you give them <em>View only</em> (see results, run checks) or <em>Can manage</em> (also add endpoints and API keys).<?php endif; ?></p>
  </div>
  <div class="overflow-x-auto"><table class="w-full min-w-[880px] text-left text-sm">
    <thead class="bg-butter-50 text-xs text-soft"><tr><th scope="col" class="px-4 py-3 font-semibold">Person</th><th scope="col" class="px-4 py-3 font-semibold">Role</th><?php if (module_enabled('health')): ?><th scope="col" class="px-4 py-3 font-semibold">Integrations</th><?php endif; ?><th scope="col" class="px-4 py-3 font-semibold">Status</th><th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th></tr></thead>
    <tbody class="divide-y divide-line">
    <?php foreach ($members as $m): $id = (int) $m['id']; $self = $id === (int) $admin['id']; $active = (int) $m['is_active'] === 1; ?>
      <tr class="<?= $active ? 'hover:bg-butter-50' : 'bg-ink/5' ?>">
        <td class="px-4 py-3"><div class="flex items-center gap-3"><?= avatar_html($m['avatar'] ?? null, $m['name'], 'h-10 w-10') ?><div class="min-w-0"><p class="font-semibold"><?= e($m['name']) ?><?= $self ? ' <span class="text-xs font-medium text-soft">(you)</span>' : '' ?></p><p class="text-soft"><span class="notranslate"><?= e($m['username']) ?></span> · <?= e($m['email']) ?></p></div></div></td>
        <td colspan="<?= module_enabled('health') ? 2 : 1 ?>" class="px-4 py-3">
          <form method="post" class="flex flex-wrap items-center gap-2"><?= csrf_field() ?><input type="hidden" name="tab" value="team"><input type="hidden" name="action" value="update_user"><input type="hidden" name="id" value="<?= $id ?>">
            <label class="sr-only" for="role-<?= $id ?>">Role of <?= e($m['name']) ?></label>
            <select id="role-<?= $id ?>" name="role" class="<?= $small ?>"><option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>Admin</option><option value="user" <?= $m['role'] === 'user' ? 'selected' : '' ?>>User</option></select>
            <?php if (module_enabled('health')): ?><label class="sr-only" for="perm-<?= $id ?>">Integrations access of <?= e($m['name']) ?></label>
            <select id="perm-<?= $id ?>" name="perm" class="<?= $small ?>"><?php foreach (PERM_LABELS as $k => $l): ?><option value="<?= $k ?>" <?= $m['perm_integrations'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
            <button class="<?= $btn ?> hover:bg-lime hover:text-navy">Save</button>
          </form>
          <?php if ($m['role'] === 'admin'): ?><p class="mt-1 text-xs text-soft">Admins always have full access.</p><?php endif; ?>
        </td>
        <td class="px-4 py-3"><?= $active ? badge('active') : badge('suspended') ?><?= (int) $m['must_change_password'] ? '<p class="mt-1 text-xs text-soft">Must set a new password</p>' : '' ?><?= (int) $m['totp_enabled'] ? '<p class="mt-1 text-xs text-soft">Two-step sign-in on</p>' : '' ?></td>
        <td class="px-4 py-3">
          <div class="flex flex-wrap justify-end gap-2">
            <?php if (!$self): ?>
              <?php if ((int) $m['totp_enabled']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="team"><input type="hidden" name="action" value="reset_2fa"><input type="hidden" name="id" value="<?= $id ?>"><button class="<?= $btn ?> hover:bg-[#FFD3CC] hover:text-[#7E160A]">Reset two-step</button></form><?php endif; ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="team"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= $id ?>"><button class="<?= $btn ?>">Reset password</button></form>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="team"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= $id ?>"><button class="<?= $btn ?> <?= $active ? 'hover:bg-[#FFD3CC] hover:text-[#7E160A]' : 'hover:bg-lime hover:text-navy' ?>"><?= $active ? 'Disable' : 'Enable' ?></button></form>
              <?php if (!$active): ?><form method="post" onsubmit="return confirm('Delete the account of <?= e(addslashes($m['name'])) ?> for good? This can not be undone.');"><?= csrf_field() ?><input type="hidden" name="tab" value="team"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= $id ?>"><button class="<?= $btn ?> hover:bg-[#FFD3CC] hover:text-[#7E160A]">Delete</button></form><?php endif; ?>
            <?php else: ?><span class="text-xs text-soft">Use the Account tab for your own password.</span><?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="rise mt-6 rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="add-h">
  <h2 id="add-h" class="text-lg font-bold tracking-tight">Add a person</h2>
  <form method="post" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate><?= csrf_field() ?><input type="hidden" name="tab" value="team"><input type="hidden" name="action" value="add_user">
    <div><label for="n-name" class="text-sm font-semibold">Full name</label><input id="n-name" name="name" maxlength="80" required class="<?= $field ?> mt-1.5"></div>
    <div><label for="n-user" class="text-sm font-semibold">Username</label><input id="n-user" name="username" maxlength="30" autocomplete="off" required class="<?= $field ?> mt-1.5 notranslate"><p class="mt-1 text-xs text-soft">Lowercase letters, digits, dots, dashes.</p></div>
    <div><label for="n-email" class="text-sm font-semibold">Email</label><input id="n-email" name="email" type="email" maxlength="190" required class="<?= $field ?> mt-1.5"></div>
    <div class="<?= module_enabled('health') ? 'grid grid-cols-2 gap-3' : '' ?>">
      <div><label for="n-role" class="text-sm font-semibold">Role</label><select id="n-role" name="role" class="<?= $field ?> mt-1.5"><option value="user">User</option><option value="admin">Admin</option></select></div>
      <?php if (module_enabled('health')): ?><div><label for="n-perm" class="text-sm font-semibold">Integrations</label><select id="n-perm" name="perm" class="<?= $field ?> mt-1.5"><?php foreach (PERM_LABELS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    </div>
    <p class="text-sm text-soft sm:col-span-2">A temporary password is generated and shown once. The person must replace it at first sign-in.</p>
    <div class="sm:col-span-2"><button class="pressable h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Create account</button></div>
  </form>
</section>
<section class="rise mt-6 rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.08s" aria-labelledby="inv-h">
  <h2 id="inv-h" class="text-lg font-bold tracking-tight">Invite by email</h2>
  <p class="mt-1 text-sm text-soft">The person gets a link, chooses their own password and can then turn on two-step sign-in. The link works once and expires after <?= INVITE_VALID_DAYS ?> days. No temporary password is involved.</p>
  <form method="post" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate><?= csrf_field() ?><input type="hidden" name="tab" value="team"><input type="hidden" name="action" value="invite">
    <div><label for="i-name" class="text-sm font-semibold">Full name</label><input id="i-name" name="name" maxlength="80" required class="<?= $field ?> mt-1.5"></div>
    <div><label for="i-user" class="text-sm font-semibold">Username</label><input id="i-user" name="username" maxlength="30" autocomplete="off" required class="<?= $field ?> mt-1.5 notranslate"></div>
    <div><label for="i-email" class="text-sm font-semibold">Email</label><input id="i-email" name="email" type="email" maxlength="190" required class="<?= $field ?> mt-1.5"></div>
    <div class="<?= module_enabled('health') ? 'grid grid-cols-2 gap-3' : '' ?>">
      <div><label for="i-role" class="text-sm font-semibold">Role</label><select id="i-role" name="role" class="<?= $field ?> mt-1.5"><option value="user">User</option><option value="admin">Admin</option></select></div>
      <?php if (module_enabled('health')): ?><div><label for="i-perm" class="text-sm font-semibold">Integrations</label><select id="i-perm" name="perm" class="<?= $field ?> mt-1.5"><?php foreach (PERM_LABELS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    </div>
    <div class="sm:col-span-2"><button class="pressable h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Send the invitation</button></div>
  </form>
  <?php if ($invites): ?>
    <h3 class="mt-6 text-sm font-bold">Waiting to be accepted</h3>
    <ul class="mt-2 divide-y divide-line text-sm">
      <?php foreach ($invites as $iv): ?>
        <li class="flex flex-wrap items-center justify-between gap-3 py-2.5"><span class="min-w-0"><span class="font-semibold"><?= e($iv['name']) ?></span> <span class="text-soft notranslate"><?= e($iv['email']) ?> · <?= e($iv['username']) ?> · <?= $iv['role'] === 'admin' ? 'admin' : 'user' ?> · expires <?= e(date('j M, H:i', strtotime($iv['expires_at']))) ?></span></span>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="team"><input type="hidden" name="action" value="revoke_invite"><input type="hidden" name="id" value="<?= (int) $iv['id'] ?>"><button class="<?= $btn ?> hover:bg-[#FFD3CC] hover:text-[#7E160A]">Withdraw</button></form></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php
}
