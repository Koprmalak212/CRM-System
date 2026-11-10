<?php
declare(strict_types=1);

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
        $perm = in_array($_POST['perm'] ?? '', ['none', 'view', 'manage'], true) ? $_POST['perm'] : 'none';
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
        $_SESSION['temp_pw'] = ['username' => $username, 'password' => $pw, 'kind' => 'created'];
        log_activity('warning', 'Team member added', $username . ' · ' . $role . ' · integrations ' . $perm);
        flash('Account created for ' . $name . '. Copy the temporary password now: it will not be shown again.');
        return;
    }

    $target = $load($pdo, $id);
    if (!$target) { flash('That account no longer exists.', 'bad'); return; }
    $isSelf = (int) $target['id'] === (int) $admin['id'];

    if ($action === 'update_user') {
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
        $perm = in_array($_POST['perm'] ?? '', ['none', 'view', 'manage'], true) ? $_POST['perm'] : 'none';
        if ($role === 'admin') { $perm = 'manage'; }
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
        $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_password = 1 WHERE id = ?')->execute([hash_password($pw), $id]);
        $_SESSION['temp_pw'] = ['username' => $target['username'], 'password' => $pw, 'kind' => 'reset'];
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
    $temp = $_SESSION['temp_pw'] ?? null;
    unset($_SESSION['temp_pw']);
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
    <p class="mt-1 max-w-2xl text-sm text-soft"><strong class="text-ink">Admins</strong> can use everything, including this tab and the integrations. <strong class="text-ink">Users</strong> can use the dashboard but only open the Integrations tab if you give them <em>View only</em> (see results, run checks) or <em>Can manage</em> (also add endpoints and API keys).</p>
  </div>
  <div class="overflow-x-auto"><table class="w-full min-w-[880px] text-left text-sm">
    <thead class="bg-butter-50 text-xs text-soft"><tr><th scope="col" class="px-4 py-3 font-semibold">Person</th><th scope="col" class="px-4 py-3 font-semibold">Role</th><th scope="col" class="px-4 py-3 font-semibold">Integrations</th><th scope="col" class="px-4 py-3 font-semibold">Status</th><th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th></tr></thead>
    <tbody class="divide-y divide-line">
    <?php foreach ($members as $m): $id = (int) $m['id']; $self = $id === (int) $admin['id']; $active = (int) $m['is_active'] === 1; ?>
      <tr class="<?= $active ? 'hover:bg-butter-50' : 'opacity-60' ?>">
        <td class="px-4 py-3"><div class="flex items-center gap-3"><?= avatar_html($m['avatar'] ?? null, $m['name'], 'h-10 w-10') ?><div class="min-w-0"><p class="font-semibold"><?= e($m['name']) ?><?= $self ? ' <span class="text-xs font-medium text-soft">(you)</span>' : '' ?></p><p class="text-soft"><span class="notranslate"><?= e($m['username']) ?></span> · <?= e($m['email']) ?></p></div></div></td>
        <td colspan="2" class="px-4 py-3">
          <form method="post" class="flex flex-wrap items-center gap-2"><?= csrf_field() ?><input type="hidden" name="tab" value="team"><input type="hidden" name="action" value="update_user"><input type="hidden" name="id" value="<?= $id ?>">
            <label class="sr-only" for="role-<?= $id ?>">Role of <?= e($m['name']) ?></label>
            <select id="role-<?= $id ?>" name="role" class="<?= $small ?>"><option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>Admin</option><option value="user" <?= $m['role'] === 'user' ? 'selected' : '' ?>>User</option></select>
            <label class="sr-only" for="perm-<?= $id ?>">Integrations access of <?= e($m['name']) ?></label>
            <select id="perm-<?= $id ?>" name="perm" class="<?= $small ?>"><?php foreach (PERM_LABELS as $k => $l): ?><option value="<?= $k ?>" <?= $m['perm_integrations'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
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
    <div class="grid grid-cols-2 gap-3">
      <div><label for="n-role" class="text-sm font-semibold">Role</label><select id="n-role" name="role" class="<?= $field ?> mt-1.5"><option value="user">User</option><option value="admin">Admin</option></select></div>
      <div><label for="n-perm" class="text-sm font-semibold">Integrations</label><select id="n-perm" name="perm" class="<?= $field ?> mt-1.5"><?php foreach (PERM_LABELS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <p class="text-sm text-soft sm:col-span-2">A temporary password is generated and shown once. The person must replace it at first sign-in.</p>
    <div class="sm:col-span-2"><button class="pressable h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Create account</button></div>
  </form>
</section>
<?php
}
