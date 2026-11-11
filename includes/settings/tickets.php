<?php
declare(strict_types=1);

/** Settings > Tickets: the SLA targets per priority, and the mailbox that is read for email to ticket. Admins only, Tickets module only. */

require_once dirname(__DIR__) . '/modules/inbound.php';

/** Hours as typed ("4", "0,5") to minutes, or null when it is not a number above zero and at most a year. */
function sla_hours_input(string $s): ?int
{
    $s = trim(str_replace(',', '.', $s));
    if ($s === '' || !is_numeric($s) || (float) $s <= 0) { return null; }
    $min = (int) round((float) $s * 60);
    return $min >= 1 && $min <= SLA_MAX_MINUTES ? $min : null;
}

function tab_handle(PDO $pdo, array $admin): void
{
    if (!is_admin()) { deny_access('tickets:handle'); }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'sla_save') {
        $new = []; $err = [];
        foreach (TICKET_PRIORITIES as $p => $label) {
            $a = sla_hours_input((string) ($_POST['respond_' . $p] ?? ''));
            $b = sla_hours_input((string) ($_POST['resolve_' . $p] ?? ''));
            if ($a === null || $b === null) { $err[] = $label . ': enter both targets in hours, above zero (for example 0,5 or 24).'; continue; }
            if ($a > $b) { $err[] = $label . ': the first response can not take longer than the solution.'; continue; }
            $new[$p] = [$a, $b];
        }
        if ($err) { flash(implode(' ', $err), 'bad'); return; }
        $up = $pdo->prepare('INSERT INTO sla_policies (priority, respond_min, resolve_min) VALUES (?,?,?) ON DUPLICATE KEY UPDATE respond_min = VALUES(respond_min), resolve_min = VALUES(resolve_min)');
        foreach ($new as $p => [$a, $b]) { $up->execute([$p, $a, $b]); }
        $on = !empty($_POST['sla_enabled']);
        $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('sla_enabled', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$on ? '1' : '0']);
        log_activity('warning', 'SLA targets saved', $on ? 'on' : 'off');
        flash('SLA targets saved. They apply to tickets opened from now on, and to a ticket whose priority changes.');
        return;
    }

    if ($action === 'inbox_save') {
        $cur = inbox_settings($pdo);
        $host = trim((string) ($_POST['host'] ?? ''));
        $port = (int) ($_POST['port'] ?? 993);
        $enc = (string) ($_POST['encryption'] ?? 'ssl');
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $folder = trim((string) ($_POST['folder'] ?? '')) ?: 'INBOX';
        $address = strtolower(trim((string) ($_POST['address'] ?? '')));
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        $err = [];
        if ($host !== '' && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,188}[A-Za-z0-9])?$/', $host)) { $err[] = 'The mail server name is not valid.'; }
        if ($port < 1 || $port > 65535) { $err[] = 'The port must be between 1 and 65535.'; }
        if (!in_array($enc, ['ssl', 'starttls', 'none'], true)) { $err[] = 'Choose how the connection is secured.'; }
        if (mb_strlen($user) > 190 || preg_match('/[\r\n]/', $user)) { $err[] = 'The user name is not valid.'; }
        if (preg_match('/[\r\n\x00]/', $pass) || strlen($pass) > 512) { $err[] = 'The password contains a character that is not allowed.'; }
        if (!preg_match('/^[A-Za-z0-9 ._\/-]{1,80}$/', $folder)) { $err[] = 'The folder name can hold letters, digits, spaces, dots, dashes and slashes.'; }
        if ($address !== '' && (!filter_var($address, FILTER_VALIDATE_EMAIL) || mb_strlen($address) > 190)) { $err[] = 'The support address is not a valid email address.'; }
        if ($enabled && ($host === '' || $user === '' || ($pass === '' && empty($cur['password_enc'])))) { $err[] = 'To turn it on, enter the mail server, the user name and the password.'; }
        if ($err) { flash(implode(' ', $err), 'bad'); return; }
        $pw = $pass !== '' ? secret_encrypt($pass) : $cur['password_enc'];   // a blank password keeps the saved one
        $pdo->prepare('INSERT INTO inbox_settings (id, enabled, host, port, encryption, username, password_enc, folder, address, auto_reply, updated_at) VALUES (1,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), host = VALUES(host), port = VALUES(port), encryption = VALUES(encryption), username = VALUES(username), password_enc = VALUES(password_enc), folder = VALUES(folder), address = VALUES(address), auto_reply = VALUES(auto_reply), updated_at = VALUES(updated_at)')
            ->execute([$enabled, $host, $port, $enc, $user, $pw, $folder, $address, !empty($_POST['auto_reply']) ? 1 : 0]);
        log_activity('warning', 'Inbox settings saved', $host !== '' ? $host . ':' . $port . ($enabled ? ' (on)' : ' (off)') : '(no mail server)');   // never the password
        flash($enabled ? 'Saved. Use "Test the connection" to check it, or "Read the mailbox now".' : 'Saved. Email to ticket is off.');
        return;
    }

    if ($action === 'inbox_clear_password') {
        $pdo->exec('UPDATE inbox_settings SET password_enc = NULL, enabled = 0 WHERE id = 1');
        log_activity('warning', 'Inbox password removed', '');
        flash('The saved mailbox password was removed and email to ticket was turned off.');
        return;
    }

    if ($action === 'inbox_test' || $action === 'inbox_run') {
        [$allowed] = rate_hit('inbox', 10, 60);   // each try opens a connection to the mail server
        if (!$allowed) { flash('Too many tries in a minute. Wait a moment and try again.', 'bad'); return; }
        $cfg = inbox_settings($pdo);
        if ($cfg['host'] === '' || empty($cfg['password_enc'])) { flash('Save the mail server, user name and password first.', 'bad'); return; }
        if ($action === 'inbox_test') {
            $r = imap_fetch_unread($cfg, secret_decrypt((string) $cfg['password_enc']), null);
            log_activity('info', 'Inbox test', $r['state']);
            flash($r['state'] === 'ok' ? 'The mailbox answers. ' . $r['unread'] . ' unread message' . ($r['unread'] === 1 ? '' : 's') . ' waiting.' : 'The mailbox could not be read: ' . $r['error'], $r['state'] === 'ok' ? 'ok' : 'bad');
            return;
        }
        if (!(int) $cfg['enabled']) { flash('Turn email to ticket on first.', 'bad'); return; }
        $r = inbox_run($pdo);
        flash($r['state'] === 'ok' ? $r['new'] . ' new ticket' . ($r['new'] === 1 ? '' : 's') . ', ' . $r['replies'] . ' repl' . ($r['replies'] === 1 ? 'y' : 'ies') . ' added, ' . $r['ignored'] . ' ignored.' : ($r['state'] === 'busy' ? 'Another run is still busy.' : 'The mailbox could not be read: ' . $r['error']), $r['state'] === 'ok' ? 'ok' : 'bad');
        return;
    }
}

function tab_render(PDO $pdo, array $admin): void
{
    $pol = sla_policies($pdo, true);
    $on = sla_enabled($pdo, true);
    $c = inbox_settings($pdo);
    $hasPw = !empty($c['password_enc']);
    $h = fn(int $min): string => rtrim(rtrim(number_format($min / 60, 2, ',', ''), '0'), ',');
    $field = 'mt-1.5 h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $cell = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm tabular-nums focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $log = $pdo->query('SELECT e.*, t.reference FROM ticket_emails e LEFT JOIN tickets t ON t.id = e.ticket_id ORDER BY e.id DESC LIMIT 15')->fetchAll();
    $states = ['never' => 'Not read yet', 'ok' => 'Read without problems', 'unauthorized' => 'The user name or password was refused', 'unreachable' => 'The mail server could not be reached', 'invalid' => 'The mail server gave an unexpected answer', 'busy' => 'Busy'];
    ?>
<div class="grid items-start gap-6 lg:grid-cols-2">
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="sla-h">
    <h2 id="sla-h" class="text-lg font-bold tracking-tight">SLA targets</h2>
    <p class="mt-1 text-sm text-soft">How long a first response and the solution may take, per priority, in hours. The clock runs 24 hours a day and stands still while a ticket is waiting for the customer. A contract can set shorter targets for one company.</p>
    <form method="post" class="mt-5" novalidate><?= csrf_field() ?><input type="hidden" name="tab" value="tickets"><input type="hidden" name="action" value="sla_save">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[360px] text-left text-sm" data-plain>
          <thead class="text-xs text-soft"><tr><th scope="col" class="pb-2 pr-3 font-semibold">Priority</th><th scope="col" class="pb-2 pr-3 font-semibold">First response (hours)</th><th scope="col" class="pb-2 font-semibold">Solution (hours)</th></tr></thead>
          <tbody>
            <?php foreach (array_reverse(TICKET_PRIORITIES, true) as $p => $label): ?>
              <tr><th scope="row" class="py-1.5 pr-3 font-semibold"><?= e($label) ?></th>
                <td class="py-1.5 pr-3"><input name="respond_<?= $p ?>" inputmode="decimal" maxlength="8" value="<?= e($h($pol[$p]['respond_min'])) ?>" aria-label="<?= e($label) ?>: first response in hours" class="<?= $cell ?>"></td>
                <td class="py-1.5"><input name="resolve_<?= $p ?>" inputmode="decimal" maxlength="8" value="<?= e($h($pol[$p]['resolve_min'])) ?>" aria-label="<?= e($label) ?>: solution in hours" class="<?= $cell ?>"></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <label for="sla_enabled" class="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-line px-4 py-3 hover:bg-butter-50">
        <input id="sla_enabled" type="checkbox" name="sla_enabled" value="1" <?= $on ? 'checked' : '' ?> class="mt-1 h-4 w-4 shrink-0 accent-lime">
        <span><span class="block text-sm font-semibold">Track the SLA</span><span class="block text-sm text-soft">New tickets get their two targets. A ticket that is about to miss one raises a notification; one that misses its solution target is escalated.</span></span></label>
      <button class="pressable mt-5 h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Save targets</button>
    </form>
  </section>

  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="in-h">
    <h2 id="in-h" class="text-lg font-bold tracking-tight">Email to ticket</h2>
    <p class="mt-1 text-sm text-soft">A mailbox, for example support@, is read over IMAP. Every unread message becomes a ticket, and a reply with the reference in its subject is added to that ticket. The password is stored encrypted.</p>
    <form method="post" class="mt-5 grid gap-4 sm:grid-cols-2" novalidate><?= csrf_field() ?><input type="hidden" name="tab" value="tickets"><input type="hidden" name="action" value="inbox_save">
      <div class="sm:col-span-2"><label for="in_host" class="text-sm font-semibold">Mail server (IMAP)</label><input id="in_host" name="host" maxlength="190" value="<?= e($c['host']) ?>" placeholder="imap.example.com" autocomplete="off" class="<?= $field ?> notranslate"></div>
      <div><label for="in_port" class="text-sm font-semibold">Port</label><input id="in_port" name="port" inputmode="numeric" maxlength="5" value="<?= (int) $c['port'] ?>" class="<?= $field ?>"></div>
      <div><label for="in_enc" class="text-sm font-semibold">Security</label><select id="in_enc" name="encryption" class="<?= $field ?>"><?php foreach (['ssl' => 'SSL/TLS (port 993)', 'starttls' => 'STARTTLS (port 143)', 'none' => 'None (not recommended)'] as $k => $l): ?><option value="<?= $k ?>" <?= $c['encryption'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label for="in_user" class="text-sm font-semibold">User name</label><input id="in_user" name="username" maxlength="190" value="<?= e($c['username']) ?>" autocomplete="off" class="<?= $field ?> notranslate"></div>
      <div><label for="in_pass" class="text-sm font-semibold">Password</label><input id="in_pass" name="password" type="password" autocomplete="new-password" maxlength="512" placeholder="<?= $hasPw ? '••••••••  (saved, leave empty to keep)' : '' ?>" class="<?= $field ?>"></div>
      <div><label for="in_folder" class="text-sm font-semibold">Folder</label><input id="in_folder" name="folder" maxlength="80" value="<?= e($c['folder']) ?>" class="<?= $field ?> notranslate"></div>
      <div><label for="in_addr" class="text-sm font-semibold">Support address</label><input id="in_addr" name="address" type="email" maxlength="190" value="<?= e($c['address']) ?>" placeholder="support@example.com" class="<?= $field ?> notranslate"><p class="mt-1 text-xs text-soft">Customers reply to this address. Mail from it is never turned into a ticket.</p></div>
      <label for="in_auto" class="flex cursor-pointer items-start gap-3 rounded-xl border border-line px-4 py-3 hover:bg-butter-50 sm:col-span-2">
        <input id="in_auto" type="checkbox" name="auto_reply" value="1" <?= (int) $c['auto_reply'] ? 'checked' : '' ?> class="mt-1 h-4 w-4 shrink-0 accent-lime">
        <span><span class="block text-sm font-semibold">Confirm a new ticket to the sender</span><span class="block text-sm text-soft">One short email with the reference, so their replies find the ticket.</span></span></label>
      <label for="in_on" class="flex cursor-pointer items-start gap-3 rounded-xl border border-line px-4 py-3 hover:bg-butter-50 sm:col-span-2">
        <input id="in_on" type="checkbox" name="enabled" value="1" <?= (int) $c['enabled'] ? 'checked' : '' ?> class="mt-1 h-4 w-4 shrink-0 accent-lime">
        <span><span class="block text-sm font-semibold">Read this mailbox</span><span class="block text-sm text-soft">Schedule <code class="notranslate">php bin/poll_inbox.php</code> every few minutes, or use the button below.</span></span></label>
      <div class="sm:col-span-2"><button class="pressable h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Save mailbox</button></div>
    </form>
    <div class="mt-4 flex flex-wrap gap-3 border-t border-line pt-4">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="tickets"><input type="hidden" name="action" value="inbox_test"><button class="pressable h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-lime hover:text-navy">Test the connection</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="tickets"><input type="hidden" name="action" value="inbox_run"><button class="pressable h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-lime hover:text-navy">Read the mailbox now</button></form>
      <?php if ($hasPw): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="tickets"><input type="hidden" name="action" value="inbox_clear_password"><button class="h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Remove the saved password</button></form><?php endif; ?>
    </div>
    <dl class="mt-4 divide-y divide-line border-t border-line text-sm">
      <div class="flex justify-between gap-4 py-3"><dt class="text-soft">Last read</dt><dd class="text-right font-semibold"><?= $c['last_run_at'] ? e(ago((string) $c['last_run_at'])) : 'Never' ?></dd></div>
      <div class="flex justify-between gap-4 py-3"><dt class="text-soft">Result</dt><dd class="text-right font-semibold"><?= e($states[$c['last_state']] ?? $c['last_state']) ?><?= $c['last_error'] !== '' ? '<span class="block text-xs font-normal text-[#FF9C8C]">' . e($c['last_error']) . '</span>' : '' ?></dd></div>
    </dl>
  </section>
</div>

<section class="rise mt-6 rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="log-h">
  <div class="border-b border-line p-5 sm:p-6"><h2 id="log-h" class="text-lg font-bold tracking-tight">Last messages read</h2><p class="mt-1 text-sm text-soft">What happened to the 15 most recent emails. Only the sender, the subject and the outcome are kept here.</p></div>
  <?php if ($log): ?>
    <ul class="divide-y divide-line">
      <?php foreach ($log as $m): ?>
        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 text-sm sm:px-6">
          <?= badge(['new' => 'open', 'reply' => 'received', 'ignored' => 'other'][$m['outcome']]) ?>
          <span class="min-w-0 max-w-full truncate font-semibold"><?= e($m['subject'] !== '' ? $m['subject'] : '(no subject)') ?></span><span class="text-soft notranslate"><?= e($m['from_email']) ?></span>
          <span class="text-soft"><?= e(ago($m['received_at'])) ?> · <?= $m['ticket_id'] !== null ? '<a class="underline decoration-lime decoration-2 underline-offset-4" href="tickets/ticket.php?id=' . (int) $m['ticket_id'] . '">' . e($m['note']) . '</a>' : e($m['note']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?><p class="px-5 py-10 text-center text-sm text-soft sm:px-6">No email has been read yet.</p><?php endif; ?>
</section>
<?php
}
