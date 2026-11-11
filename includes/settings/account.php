<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/monitor.php';       // secret_encrypt / secret_decrypt
require_once dirname(__DIR__) . '/modules/twofactor.php';
require_once dirname(__DIR__) . '/modules/avatars.php';

/** Two-step sign-in actions of the Account tab. */
function tab_handle_2fa(PDO $pdo, array $admin, string $action): void
{
    $s = $pdo->prepare('SELECT id, username, password_hash, totp_enabled, totp_secret_enc FROM admins WHERE id = ?');
    $s->execute([$admin['id']]);
    $me = $s->fetch();
    $on = (bool) (int) $me['totp_enabled'];
    $setup = $_SESSION['totp_setup'] ?? null;
    if (is_array($setup) && time() - (int) ($setup['t'] ?? 0) > 900) { $setup = null; unset($_SESSION['totp_setup']); }   // an unfinished set-up expires after 15 minutes

    if ($action === '2fa_start') {
        if ($on) { flash('Two-step sign-in is already on.', 'bad'); return; }
        $_SESSION['totp_setup'] = ['secret' => base64_encode(totp_new_secret()), 't' => time()];
        return;
    }
    if ($action === '2fa_cancel') { unset($_SESSION['totp_setup']); return; }

    if ($action === '2fa_confirm') {
        if ($on || !is_array($setup)) { flash('Start the set-up first.', 'bad'); return; }
        if (tfa_locked($pdo, (string) $me['username'])) { flash('Too many wrong codes. Wait ' . TFA_WINDOW_MINUTES . ' minutes and try again.', 'bad'); return; }
        $secret = (string) base64_decode((string) $setup['secret'], true);
        $step = totp_verify($secret, (string) ($_POST['code'] ?? ''));
        if ($step === null) { tfa_fail($pdo, (string) $me['username']); flash('That code is not right. Check the code in your app and try again.', 'bad'); return; }
        $pdo->prepare('UPDATE admins SET totp_secret_enc = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?')->execute([secret_encrypt($secret), $step, $me['id']]);
        $_SESSION['new_recovery'] = recovery_codes_make($pdo, (int) $me['id']);   // shown once, on the next page
        unset($_SESSION['totp_setup']);
        tfa_clear($pdo, (string) $me['username']);
        log_activity('warning', 'Two-step sign-in turned on', (string) $me['username']);
        flash('Two-step sign-in is on. Save your recovery codes now.');
        return;
    }

    if ($action === '2fa_disable') {
        if (!$on) { flash('Two-step sign-in is not on.', 'bad'); return; }
        if (tfa_locked($pdo, (string) $me['username'])) { flash('Too many wrong codes. Wait ' . TFA_WINDOW_MINUTES . ' minutes and try again.', 'bad'); return; }
        $pw = substr((string) ($_POST['password'] ?? ''), 0, 1024);
        $how = password_verify($pw, (string) $me['password_hash']) ? twofactor_check($pdo, $me, substr((string) ($_POST['code'] ?? ''), 0, 40)) : null;
        if ($how === null) { tfa_fail($pdo, (string) $me['username']); flash('The password or the code is not right, so two-step sign-in stays on.', 'bad'); return; }
        $pdo->prepare('UPDATE admins SET totp_secret_enc = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = ?')->execute([$me['id']]);
        $pdo->prepare('DELETE FROM admin_recovery_codes WHERE admin_id = ?')->execute([$me['id']]);
        tfa_clear($pdo, (string) $me['username']);
        log_activity('warning', 'Two-step sign-in turned off', (string) $me['username']);
        flash('Two-step sign-in is off.');
        return;
    }
}

function tab_handle(PDO $pdo, array $admin): void
{
    if (($_POST['action'] ?? '') === 'avatar_upload') {
        [$ok, $msg] = avatar_save($pdo, $admin, $_FILES['avatar'] ?? null);
        flash($msg, $ok ? 'ok' : 'bad');
        return;
    }
    if (($_POST['action'] ?? '') === 'avatar_remove') {
        avatar_remove($pdo, $admin);
        flash('Your profile picture is removed. Your initials are shown instead.');
        return;
    }
    if (($_POST['action'] ?? '') === 'optin') {
        $on = ($_POST['optin'] ?? '') === '1' ? 1 : 0;
        $pdo->prepare('UPDATE admins SET notify_optin = ? WHERE id = ?')->execute([$on, $admin['id']]);
        log_activity('info', 'Notification emails preference', $on ? 'on' : 'off', (string) $admin['username']);
        flash($on ? 'You will also get notification emails at ' . $admin['email'] . '.' : 'You will no longer get notification emails (the bell still works).');
        return;
    }
    if (($_POST['action'] ?? '') === 'require2fa') {
        if (!is_admin()) { deny_access('settings:require2fa'); }
        $on = ($_POST['require'] ?? '') === '1' ? '1' : '0';
        $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('require_2fa_admins', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$on]);
        log_activity('warning', 'Two-step requirement changed', $on === '1' ? 'required for admins' : 'optional');
        flash($on === '1' ? 'Admins now have to use two-step sign-in. Anyone without it is taken to set it up.' : 'Two-step sign-in is optional again.');
        return;
    }
    if (str_starts_with((string) ($_POST['action'] ?? ''), '2fa_')) { tab_handle_2fa($pdo, $admin, (string) $_POST['action']); return; }
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
        bump_session_epoch($pdo, (int) $admin['id']);   // other devices are signed out
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
  <?php $pic = $pdo->prepare('SELECT avatar FROM admins WHERE id = ?'); $pic->execute([$admin['id']]); $pic = $pic->fetchColumn(); $pic = is_string($pic) ? $pic : null; ?>
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6 lg:col-span-2" aria-labelledby="pic-h">
    <h2 id="pic-h" class="text-lg font-bold tracking-tight">Profile picture</h2>
    <p class="mt-1 text-sm text-soft">Shown next to your name at the top of every page and in the team list. JPG, PNG, WebP and <strong class="text-ink">GIF are supported, animated GIFs included</strong>. Up to <?= (int) (AVATAR_MAX_BYTES / 1048576) ?> MB and <?= AVATAR_MAX_PIXELS ?> by <?= AVATAR_MAX_PIXELS ?> pixels. The picture is shown round, so a square one fits best.</p>
    <div class="mt-5 flex flex-wrap items-center gap-5">
      <?= avatar_html($pic, $admin['name'], 'h-20 w-20', 'text-2xl') ?>
      <form method="post" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3"><?= csrf_field() ?><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="avatar_upload">
        <label for="avatar" class="sr-only">Choose a picture</label>
        <input id="avatar" name="avatar" type="file" accept="image/gif,image/png,image/jpeg,image/webp" required class="max-w-full text-sm file:mr-3 file:h-11 file:cursor-pointer file:rounded-xl file:border file:border-line file:bg-panel file:px-4 file:text-sm file:font-semibold hover:file:bg-butter-50">
        <button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Upload picture</button>
      </form>
      <?php if ($pic !== null): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="avatar_remove">
        <button class="pressable h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-butter-50">Remove picture</button>
      </form>
      <?php endif; ?>
    </div>
  </section>

  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="pw-h">
    <h2 id="pw-h" class="text-lg font-bold tracking-tight">Change password</h2>
    <p class="mt-1 text-sm text-soft">Signed in as <?= e($admin['email']) ?> · <?= e($roleText) ?><?php if (module_enabled('health')): ?> · Integrations: <?= e($integ) ?><?php endif; ?></p>
    <form method="post" class="mt-5 space-y-4"><?= csrf_field() ?><input type="hidden" name="tab" value="account">
      <div><label for="current" class="text-sm font-semibold">Current password</label><input id="current" name="current" type="password" autocomplete="current-password" required class="<?= $field ?>"></div>
      <div><label for="new" class="text-sm font-semibold">New password</label><input id="new" name="new" type="password" autocomplete="new-password" required minlength="12" class="<?= $field ?>"><p class="mt-1 text-xs text-soft">At least 12 characters. A short sentence works well.</p></div>
      <div><label for="confirm" class="text-sm font-semibold">Confirm new password</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required class="<?= $field ?>"></div>
      <button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Update password</button>
    </form>
  </section>

  <?php
    $me = $pdo->prepare('SELECT username, totp_enabled FROM admins WHERE id = ?'); $me->execute([$admin['id']]); $me = $me->fetch();
    $tfaOn = (bool) (int) $me['totp_enabled'];
    $setup = $_SESSION['totp_setup'] ?? null;
    if (is_array($setup) && time() - (int) ($setup['t'] ?? 0) > 900) { $setup = null; unset($_SESSION['totp_setup']); }
    $newCodes = $_SESSION['new_recovery'] ?? null; unset($_SESSION['new_recovery']);   // recovery codes are shown exactly once
    $secret = is_array($setup) ? (string) base64_decode((string) $setup['secret'], true) : '';
  ?>
  <?php if ($newCodes): ?>
  <section class="rise rounded-2xl border border-butter/30 bg-butter-100 p-5 sm:p-6 lg:col-span-2" role="alert" aria-labelledby="rc-h">
    <h2 id="rc-h" class="text-lg font-bold tracking-tight text-butter">Your recovery codes</h2>
    <p class="mt-1 text-sm">Keep these somewhere safe. Each code works once if you lose your phone. They will not be shown again.</p>
    <ul class="mt-4 grid grid-cols-2 gap-2 rounded-xl bg-deep p-4 font-mono text-sm notranslate select-all sm:grid-cols-5"><?php foreach ($newCodes as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul>
  </section>
  <?php endif; ?>
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6 lg:col-span-2" aria-labelledby="tfa-h">
    <h2 id="tfa-h" class="text-lg font-bold tracking-tight">Two-step sign-in</h2>
    <?php if ($tfaOn): ?>
      <p class="mt-1 text-sm text-soft"><?= badge('active') ?> After your password you also enter a 6-digit code from your authenticator app. Unused recovery codes: <strong class="text-ink"><?= recovery_codes_left($pdo, (int) $admin['id']) ?></strong> of <?= TOTP_RECOVERY_COUNT ?>.</p>
      <form method="post" class="mt-5 grid gap-4 sm:grid-cols-3 sm:items-end"><?= csrf_field() ?><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="2fa_disable">
        <div><label for="off_pw" class="text-sm font-semibold">Your password</label><input id="off_pw" name="password" type="password" autocomplete="current-password" required class="<?= $field ?>"></div>
        <div><label for="off_code" class="text-sm font-semibold">Code from the app</label><input id="off_code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="40" class="<?= $field ?>"></div>
        <button class="pressable h-11 rounded-xl border border-line text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Turn off two-step</button>
      </form>
    <?php elseif ($secret !== ''): ?>
      <p class="mt-1 text-sm text-soft">In your authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password and others) choose to add an account by entering a key, and type this key. Then enter the 6-digit code the app shows.</p>
      <dl class="mt-4 space-y-3 text-sm">
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-soft">Secret key</dt><dd class="mt-1 break-all rounded-xl bg-deep p-3 font-mono text-base tracking-wider notranslate select-all"><?= e(totp_secret_text($secret)) ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-soft">Or open this link on the phone that has the app</dt><dd class="mt-1 break-all"><a class="underline decoration-lime decoration-2 underline-offset-4 notranslate" href="<?= e(totp_uri($secret, (string) $me['username'])) ?>"><?= e(totp_uri($secret, (string) $me['username'])) ?></a></dd></div>
      </dl>
      <form method="post" class="mt-5 flex flex-wrap items-end gap-3"><?= csrf_field() ?><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="2fa_confirm">
        <div><label for="on_code" class="text-sm font-semibold">First code from the app</label><input id="on_code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="10" class="<?= $field ?> w-44"></div>
        <button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Confirm and turn on</button>
      </form>
      <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="2fa_cancel"><button class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Cancel</button></form>
    <?php else: ?>
      <p class="mt-1 text-sm text-soft">Add a second step to your own sign-in: a 6-digit code from an authenticator app. Off for now.</p>
      <form method="post" class="mt-4"><?= csrf_field() ?><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="2fa_start"><button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Set up two-step sign-in</button></form>
    <?php endif; ?>
  </section>

  <?php $opt = (int) $pdo->query('SELECT notify_optin FROM admins WHERE id = ' . (int) $admin['id'])->fetchColumn() === 1; ?>
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="op-h">
    <h2 id="op-h" class="text-lg font-bold tracking-tight">Email me notifications</h2>
    <p class="mt-1 text-sm text-soft">Get the same notification emails (overdue invoices, failed backups, services down, urgent tickets) at your own address, <span class="notranslate"><?= e($admin['email']) ?></span>, in addition to the shared address. Now: <strong class="text-ink"><?= $opt ? 'on' : 'off' ?></strong>.</p>
    <form method="post" class="mt-4"><?= csrf_field() ?><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="optin"><input type="hidden" name="optin" value="<?= $opt ? '0' : '1' ?>">
      <button class="pressable h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-lime hover:text-navy"><?= $opt ? 'Stop emailing me' : 'Email me too' ?></button></form>
  </section>

  <?php if (is_admin()): ?>
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.03s" aria-labelledby="nt-h">
    <h2 id="nt-h" class="text-lg font-bold tracking-tight">Notification email</h2>
    <p class="mt-1 text-sm text-soft">Where overdue invoices, failed backups, services that are down and urgent tickets are emailed is set together with the mail server, under <a class="underline decoration-lime decoration-2 underline-offset-4" href="settings/settings.php?tab=email">Settings &gt; Email</a> (EMAIL_TARGET).</p>
  </section>
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="rq-h">
    <h2 id="rq-h" class="text-lg font-bold tracking-tight">Require two-step for admins</h2>
    <?php $req = (string) $pdo->query("SELECT v FROM app_meta WHERE k = 'require_2fa_admins'")->fetchColumn() === '1'; $adminsWithout = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'admin' AND is_active = 1 AND totp_enabled = 0")->fetchColumn(); ?>
    <p class="mt-1 text-sm text-soft">When on, an admin without two-step sign-in can only open this Account tab until they have set it up. Now: <strong class="text-ink"><?= $req ? 'required' : 'optional' ?></strong>; <?= $adminsWithout ?> active admin<?= $adminsWithout === 1 ? '' : 's' ?> without it.</p>
    <form method="post" class="mt-4"><?= csrf_field() ?><input type="hidden" name="tab" value="account"><input type="hidden" name="action" value="require2fa"><input type="hidden" name="require" value="<?= $req ? '0' : '1' ?>">
      <button class="pressable h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-lime hover:text-navy"><?= $req ? 'Make it optional' : 'Require it for admins' ?></button></form>
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
