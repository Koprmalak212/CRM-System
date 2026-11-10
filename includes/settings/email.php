<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mailer.php';

function tab_handle(PDO $pdo, array $admin): void
{
    if (!is_admin()) { deny_access('email:handle'); }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $host = trim((string) ($_POST['host'] ?? ''));
        $port = (int) ($_POST['port'] ?? 587);
        $enc = (string) ($_POST['encryption'] ?? 'starttls');
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $fromEmail = trim((string) ($_POST['from_email'] ?? ''));
        $fromName = mail_clean_line((string) ($_POST['from_name'] ?? ''));
        $base = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $err = [];
        if ($host !== '' && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,188}[A-Za-z0-9])?$/', $host)) { $err[] = 'The mail server name is not valid.'; }
        if ($port < 1 || $port > 65535) { $err[] = 'The port must be between 1 and 65535.'; }
        if (!in_array($enc, ['none', 'starttls', 'ssl'], true)) { $err[] = 'Choose how the connection is secured.'; }
        if (mb_strlen($user) > 190 || preg_match('/[\r\n]/', $user)) { $err[] = 'The user name is not valid.'; }
        if ($fromEmail !== '' && (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($fromEmail) > 190)) { $err[] = 'The sender address is not a valid email address.'; }
        if (mb_strlen($fromName) > 80) { $err[] = 'The sender name can be at most 80 characters.'; }
        if ($base !== '' && (!preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $base) || mb_strlen($base) > 190)) { $err[] = 'The site address must look like https://admin.example.com (no spaces, no question mark).'; }
        if ($host !== '' && $fromEmail === '') { $err[] = 'Enter the sender address: most mail servers refuse messages without one.'; }
        if ($err) { flash(implode(' ', $err), 'bad'); return; }
        $cur = mail_settings($pdo);
        $enc_pw = $pass !== '' ? secret_encrypt(substr($pass, 0, 512)) : $cur['password_enc'];   // a blank password keeps the saved one
        $pdo->prepare('INSERT INTO mail_settings (id, host, port, encryption, username, password_enc, from_email, from_name, base_url, updated_at) VALUES (1,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE host = VALUES(host), port = VALUES(port), encryption = VALUES(encryption), username = VALUES(username), password_enc = VALUES(password_enc), from_email = VALUES(from_email), from_name = VALUES(from_name), base_url = VALUES(base_url), updated_at = VALUES(updated_at)')
            ->execute([$host, $port, $enc, $user, $enc_pw, $fromEmail, $fromName !== '' ? $fromName : 'Telecomlijn Admin', $base]);
        log_activity('warning', 'Email settings saved', $host !== '' ? $host . ':' . $port : '(no SMTP server)');   // never the password
        flash($host !== '' ? 'Email settings saved. Use "Send a test email" to check them.' : 'Saved. Without a mail server, messages are only recorded in the outbox.');
        return;
    }

    if ($action === 'clear_password') {
        $pdo->exec('UPDATE mail_settings SET password_enc = NULL WHERE id = 1');
        log_activity('warning', 'Email password removed', '');
        flash('The saved mail password was removed.');
        return;
    }

    if ($action === 'test') {
        [$status, , $note] = mail_send($pdo, (string) $admin['email'], 'Test email from Telecomlijn Admin', "This is a test message.\n\nIf you can read this, the email settings work.\n", 'test');
        log_activity('info', 'Test email', $status);
        flash($status === 'sent' ? 'Test email sent to ' . $admin['email'] . '.' : ($status === 'recorded' ? 'Recorded in the outbox below (nothing was sent): ' . $note : 'The test email failed: ' . $note), $status === 'failed' ? 'bad' : 'ok');
        return;
    }

    if ($action === 'clear_outbox') {
        $pdo->exec('DELETE FROM mail_outbox');
        log_activity('warning', 'Email outbox cleared', '');
        flash('The outbox was emptied.');
        return;
    }
}

function tab_render(PDO $pdo, array $admin): void
{
    $c = mail_settings($pdo);
    $field = 'mt-1.5 h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $hasPw = !empty($c['password_enc']);
    $rows = $pdo->query('SELECT id, kind, to_email, subject, body, status, error, created_at FROM mail_outbox ORDER BY id DESC LIMIT 50')->fetchAll();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();
    ?>
<div class="grid gap-6 lg:grid-cols-2">
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="smtp-h">
    <h2 id="smtp-h" class="text-lg font-bold tracking-tight">Email / SMTP</h2>
    <p class="mt-1 text-sm text-soft">For real delivery enter the details of an email account (SMTP) from your provider. Left blank, nothing is sent: password-reset and invitation links appear in the outbox below instead, so they can be copied by hand. The password is stored encrypted.</p>
    <form method="post" class="mt-5 grid gap-4 sm:grid-cols-2" novalidate><?= csrf_field() ?><input type="hidden" name="tab" value="email"><input type="hidden" name="action" value="save">
      <div class="sm:col-span-2"><label for="host" class="text-sm font-semibold">Mail server</label><input id="host" name="host" maxlength="190" value="<?= e($c['host']) ?>" placeholder="smtp.example.com" autocomplete="off" class="<?= $field ?> notranslate"></div>
      <div><label for="port" class="text-sm font-semibold">Port</label><input id="port" name="port" inputmode="numeric" maxlength="5" value="<?= (int) $c['port'] ?>" class="<?= $field ?>"></div>
      <div><label for="encryption" class="text-sm font-semibold">Security</label><select id="encryption" name="encryption" class="<?= $field ?>"><?php foreach (['starttls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (not recommended)'] as $k => $l): ?><option value="<?= $k ?>" <?= $c['encryption'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label for="username" class="text-sm font-semibold">User name</label><input id="username" name="username" maxlength="190" value="<?= e($c['username']) ?>" autocomplete="off" class="<?= $field ?> notranslate"></div>
      <div><label for="password" class="text-sm font-semibold">Password</label><input id="password" name="password" type="password" autocomplete="new-password" maxlength="512" placeholder="<?= $hasPw ? '••••••••  (saved, leave empty to keep)' : '' ?>" class="<?= $field ?>"></div>
      <div><label for="from_email" class="text-sm font-semibold">Sender address</label><input id="from_email" name="from_email" type="email" maxlength="190" value="<?= e($c['from_email']) ?>" placeholder="no-reply@telecomlijn.nl" class="<?= $field ?>"></div>
      <div><label for="from_name" class="text-sm font-semibold">Sender name</label><input id="from_name" name="from_name" maxlength="80" value="<?= e($c['from_name']) ?>" class="<?= $field ?>"></div>
      <div class="sm:col-span-2"><label for="base_url" class="text-sm font-semibold">Site address used in links <span class="font-normal text-soft">(required on a real server)</span></label><input id="base_url" name="base_url" maxlength="190" value="<?= e($c['base_url']) ?>" placeholder="https://admin.telecomlijn.nl" class="<?= $field ?> notranslate"><p class="mt-1 text-xs text-soft">Links in emails always use this address, never the one in the request, so nobody can make a reset email point to another site. On localhost it can stay empty.</p></div>
      <div class="flex flex-wrap gap-3 sm:col-span-2"><button class="pressable h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Save</button></div>
    </form>
    <div class="mt-4 flex flex-wrap gap-3 border-t border-line pt-4">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="email"><input type="hidden" name="action" value="test"><button class="pressable h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-lime hover:text-navy">Send a test email to <?= e($admin['email']) ?></button></form>
      <?php if ($hasPw): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="email"><input type="hidden" name="action" value="clear_password"><button class="h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Remove the saved password</button></form><?php endif; ?>
    </div>
  </section>

  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="st-h">
    <h2 id="st-h" class="text-lg font-bold tracking-tight">Status</h2>
    <dl class="mt-4 divide-y divide-line text-sm">
      <div class="flex justify-between gap-4 py-3"><dt class="text-soft">Delivery</dt><dd class="text-right font-semibold"><?= $c['host'] !== '' ? 'SMTP: ' . e($c['host']) . ':' . (int) $c['port'] : (mail_is_local() ? 'Outbox only (no mail server on this computer)' : 'The server\'s mail system') ?></dd></div>
      <div class="flex justify-between gap-4 py-3"><dt class="text-soft">Notifications go to</dt><dd class="text-right font-semibold notranslate"><?= ($to = notify_email($pdo)) !== '' ? e($to) : 'nobody (turned off)' ?></dd></div>
      <div class="flex justify-between gap-4 py-3"><dt class="text-soft">Links in emails point to</dt><dd class="text-right font-semibold notranslate"><?= ($b = mail_base_url($pdo)) !== null ? e($b) : 'not set: emails with links are not sent' ?></dd></div>
      <div class="flex justify-between gap-4 py-3"><dt class="text-soft">Messages in the outbox</dt><dd class="text-right font-semibold tabular-nums"><?= $count ?></dd></div>
    </dl>
  </section>
</div>

<section class="rise mt-6 rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="ob-h">
  <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-5 sm:p-6">
    <div><h2 id="ob-h" class="text-lg font-bold tracking-tight">Outbox</h2><p class="mt-1 text-sm text-soft">The last 50 messages. Delivered messages with a one-time link lose their text. Entries are removed after <?= MAIL_KEEP_DAYS ?> days.</p></div>
    <?php if ($count): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="email"><input type="hidden" name="action" value="clear_outbox"><button class="h-10 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Empty the outbox</button></form><?php endif; ?>
  </div>
  <?php if ($rows): ?>
    <ul class="divide-y divide-line">
      <?php foreach ($rows as $m): ?>
        <li class="px-5 py-3.5 sm:px-6">
          <details>
            <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              <?= badge($m['status']) ?>
              <span class="font-semibold"><?= e($m['subject']) ?></span><span class="text-soft notranslate">to <?= e($m['to_email']) ?></span><span class="text-soft"><?= e(ago($m['created_at'])) ?> · <?= e($m['kind']) ?></span>
            </summary>
            <?php if ($m['error'] !== ''): ?><p class="mt-2 text-sm text-[#FF9C8C]"><?= e($m['error']) ?></p><?php endif; ?>
            <pre class="mt-3 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-xl bg-deep p-4 text-xs notranslate"><?= e($m['body']) ?></pre>
          </details>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?><p class="px-5 py-10 text-center text-sm text-soft sm:px-6">Nothing has been sent yet.</p><?php endif; ?>
</section>
<?php
}
