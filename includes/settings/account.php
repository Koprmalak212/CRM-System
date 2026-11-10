<?php
declare(strict_types=1);

function tab_handle(PDO $pdo, array $admin): void
{
    if (($_POST['action'] ?? '') === 'notify') {
        if (!is_admin()) { deny_access('settings:notify'); }
        $mail = trim((string) ($_POST['notify_email'] ?? ''));
        if ($mail !== '' && (!filter_var($mail, FILTER_VALIDATE_EMAIL) || mb_strlen($mail) > 190)) { flash('Enter a valid email address, or leave it empty to turn emails off.', 'bad'); return; }
        $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('notify_email', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$mail]);
        log_activity('info', 'Notification address changed', $mail !== '' ? $mail : '(off)');
        flash($mail !== '' ? 'Notifications will be emailed to ' . $mail . '.' : 'Notification emails are off. The bell still works.');
        return;
    }
    $current = substr((string) ($_POST['current'] ?? ''), 0, 1024);
    $new = substr((string) ($_POST['new'] ?? ''), 0, 1024);
    $confirm = substr((string) ($_POST['confirm'] ?? ''), 0, 1024);
    $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $stmt->execute([$admin['id']]);
    $hash = (string) $stmt->fetchColumn();
    if (!password_verify($current, $hash)) {
        flash('Your current password is not correct.', 'bad');
    } elseif (strlen($new) < 12 || strlen($new) > 256) {
        flash('Use between 12 and 256 characters for the new password.', 'bad');
    } elseif (hash_equals($current, $new)) {
        flash('Choose a password that is different from the current one.', 'bad');
    } elseif ($new !== $confirm) {
        flash('The new password and its confirmation do not match.', 'bad');
    } else {
        $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([hash_password($new), $admin['id']]);
        session_regenerate_id(true);
        unset($_SESSION['csrf']);
        log_activity('warning', 'Changed password', $admin['username']);
        flash('Password updated.');
    }
}

function tab_render(PDO $pdo, array $admin): void
{
    $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $tables = $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    $field = 'mt-1.5 h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $roleText = is_admin() ? 'Admin' : 'User';
    $integ = ['none' => 'No access', 'view' => 'View only', 'manage' => 'Can manage'][integrations_level()];
    ?>
<div class="grid gap-6 lg:grid-cols-2">
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="pw-h">
    <h2 id="pw-h" class="text-lg font-bold tracking-tight">Change password</h2>
    <p class="mt-1 text-sm text-soft">Signed in as <?= e($admin['email']) ?> · <?= e($roleText) ?> · Integrations: <?= e($integ) ?></p>
    <form method="post" class="mt-5 space-y-4"><?= csrf_field() ?><input type="hidden" name="tab" value="account">
      <div><label for="current" class="text-sm font-semibold">Current password</label><input id="current" name="current" type="password" autocomplete="current-password" required class="<?= $field ?>"></div>
      <div><label for="new" class="text-sm font-semibold">New password</label><input id="new" name="new" type="password" autocomplete="new-password" required minlength="12" class="<?= $field ?>"><p class="mt-1 text-xs text-soft">At least 12 characters. A short sentence works well.</p></div>
      <div><label for="confirm" class="text-sm font-semibold">Confirm new password</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required class="<?= $field ?>"></div>
      <button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Update password</button>
    </form>
  </section>

  <?php if (is_admin()): require_once dirname(__DIR__) . '/notifications.php'; ?>
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.03s" aria-labelledby="nt-h">
    <h2 id="nt-h" class="text-lg font-bold tracking-tight">Notification email</h2>
    <p class="mt-1 text-sm text-soft">Overdue invoices, failed backups, services that are down and urgent tickets also go to this address. They always show in the bell. Sending needs the email settings (a later step); until then messages are only recorded.</p>
    <form method="post" class="mt-5 space-y-4"><?= csrf_field() ?><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="notify">
      <div><label for="notify_email" class="text-sm font-semibold">Send notifications to</label><input id="notify_email" name="notify_email" type="email" maxlength="190" value="<?= e(notify_email($pdo)) ?>" class="<?= $field ?>"><p class="mt-1 text-xs text-soft">Leave empty to turn emails off.</p></div>
      <button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Save address</button>
    </form>
  </section>
  <?php endif; ?>

  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="env-h">
    <h2 id="env-h" class="text-lg font-bold tracking-tight">Environment</h2>
    <dl class="mt-4 divide-y divide-line text-sm">
      <?php foreach ([['Web server', 'Apache'], ['PHP', 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION], ['Database', 'MariaDB ' . explode('.', $ver)[0] . '.' . (explode('.', $ver)[1] ?? '0')], ['Database name', DB_NAME], ['Tables', (string) $tables], ['Data', demo_loaded() ? 'Demo data loaded (remove it on the Overview page)' : 'Live data']] as [$k, $v]): ?>
        <div class="flex justify-between gap-4 py-3"><dt class="text-soft"><?= e($k) ?></dt><dd class="text-right font-semibold"><?= e($v) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>
</div>
<?php
}
