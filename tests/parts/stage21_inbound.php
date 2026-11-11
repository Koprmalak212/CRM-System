<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Email to ticket: reading a message, the rules that turn it into a ticket or a reply, the mailbox over IMAP (a stand-in server on
// port 3243, which must be free), the settings and the reply by email. Included by tests/run.php inside its try block.
require_once TESTS_DIR . '/../includes/modules/inbound.php';
$imapPort = 3243; $imapBox = TESTS_DIR . '/.imap_box'; $imapSeen = TESTS_DIR . '/.imap_seen';
$inboxSaved21 = $pdo->query('SELECT * FROM inbox_settings WHERE id = 1')->fetch() ?: null;
$mailSaved21 = $pdo->query('SELECT * FROM mail_settings WHERE id = 1')->fetch() ?: null;
$outbox21 = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM mail_outbox')->fetchColumn();
$rmBox21 = function () use ($imapBox, $imapSeen) { foreach (glob($imapBox . '/*.eml') ?: [] as $f) { @unlink($f); } @rmdir($imapBox); @unlink($imapSeen); };
$putRow21 = function (string $table, ?array $row) use ($pdo) {
    $pdo->exec("DELETE FROM `$table`");
    if ($row) { $cols = array_keys($row); $pdo->prepare("INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row)); }
};
$clean21 = function () use ($pdo, $outbox21) {
    $pdo->exec("DELETE FROM ticket_emails WHERE from_email LIKE '%@zzmail.test' OR message_id LIKE '%@zzmail.test>' OR message_id LIKE '<no-id-%'");
    $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZE %' OR subject = 'let me in' OR requester_email LIKE '%@zzmail.test'");
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@zzmail.test'");
    $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZE %'");
    $pdo->exec('DELETE FROM mail_outbox WHERE id > ' . $outbox21);
    $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND (target LIKE '%ZZE %' OR action IN ('Inbox settings saved', 'Inbox test', 'Inbox password removed') OR (action IN ('Ticket opened', 'Ticket updated') AND actor IN ('system', 'email')))");
    $pdo->exec('DELETE FROM rate_limits');
};
$clean21(); $rmBox21();
// a message as a mail program would send it
$eml21 = function (string $from, string $subject, string $body, string $id, array $extra = []): string {
    return "From: $from\r\nTo: support@zzdesk.test\r\nSubject: $subject\r\nMessage-ID: <$id@zzmail.test>\r\nDate: " . date('r') . "\r\n" . ($extra ? implode("\r\n", $extra) . "\r\n" : '') . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$body\r\n";
};
$cfg21 = ['address' => 'support@zzdesk.test', 'auto_reply' => 1] + inbox_settings($pdo);
$imapProc = null;
try {
    // outgoing mail goes to a closed port for this stage, so nothing can reach a real mail server
    $putRow21('mail_settings', ['id' => 1, 'host' => '127.0.0.1', 'port' => 3299, 'encryption' => 'none', 'username' => '', 'password_enc' => null, 'from_email' => 'crm@zzdesk.test', 'from_name' => 'ZZ Desk', 'base_url' => '', 'updated_at' => date('Y-m-d H:i:s')]);

    section('70. Email to ticket: reading a message');
    $m = mail_parse("From: =?UTF-8?B?SsO8cmdlbiBNw7xsbGVy?= <Jurgen@ZZmail.test>\r\nSubject: =?UTF-8?Q?St=C3=B6ring_op_de_lijn?=\r\nMessage-ID: <a1@zzmail.test>\r\nIn-Reply-To: <p0@zzmail.test>\r\nReferences: <p0@zzmail.test>\r\n <p1@zzmail.test>\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nHet caf=E9 heeft geen internet.\r\n");
    same([$m['from_email'], $m['from_name'], $m['subject']], ['jurgen@zzmail.test', 'Jürgen Müller', 'Störing op de lijn'], 'the sender and an encoded subject are read (address in lower case)');
    same($m['text'], 'Het café heeft geen internet.', 'quoted-printable text in another character set becomes UTF-8');
    same([$m['message_id'], $m['refs']], ['<a1@zzmail.test>', ['<p0@zzmail.test>', '<p1@zzmail.test>']], 'the message id and the ids it answers are read, also from a folded header');
    $multi = "From: a@zzmail.test\r\nSubject: Multi\r\nContent-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n--outer\r\nContent-Type: multipart/alternative; boundary=inner\r\n\r\n--inner\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>HTML <b>version</b></p>\r\n--inner\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode("Plain version\nline two") . "\r\n--inner--\r\n--outer\r\nContent-Type: application/pdf; name=x.pdf\r\nContent-Disposition: attachment; filename=x.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0=\r\n--outer--\r\n";
    same(mail_parse($multi)['text'], "Plain version\nline two", 'in a message with parts the plain text is used, base64 is decoded and the attachment is skipped');
    $html = "From: a@zzmail.test\r\nSubject: H\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<html><head><style>p{color:red}</style></head><body><p>Hello &amp; welcome</p><script>alert(1)</script><div>Second line</div></body></html>";
    $ht = mail_parse($html)['text'];
    check(str_contains($ht, 'Hello & welcome') && str_contains($ht, 'Second line') && !str_contains($ht, 'alert') && !str_contains($ht, 'color:red') && !str_contains($ht, '<'), 'a message that is only HTML becomes plain text without tags, scripts or styles', $ht);
    same(mail_strip_quote("Thanks, that works.\n\nOn Mon, 3 Nov 2026 at 10:00, Support <s@x.test> wrote:\n> Did it help?\n> Regards"), 'Thanks, that works.', 'the quoted earlier mail is cut off a reply');
    same(mail_strip_quote("Bedankt!\n\nOp ma 3 nov 2026 om 10:00 schreef Support <s@x.test>:\n> Werkt het?"), 'Bedankt!', 'also in Dutch');
    same(mail_strip_quote("> only a quote"), '> only a quote', 'a message that is nothing but a quote is kept whole');
    foreach ([['Auto-Submitted: auto-replied', 'an automatic reply'], ['Precedence: bulk', 'bulk mail'], ['List-Id: <news.zzmail.test>', 'a mailing list']] as [$hdr, $what]) {
        same(mail_parse("From: a@zzmail.test\r\n$hdr\r\nSubject: x\r\n\r\nbody")['auto'], true, "$what is recognised as automatic mail");
    }
    same(mail_parse("From: MAILER-DAEMON@zzmail.test\r\nSubject: Undelivered\r\n\r\nbody")['auto'], true, 'a bounce from mailer-daemon is recognised as automatic mail');
    same(mail_parse("From: a@zzmail.test\r\nAuto-Submitted: no\r\nSubject: x\r\n\r\nbody")['auto'], false, 'a normal message is not');
    same(mail_parse("Subject: no sender\r\n\r\nbody")['from_email'], '', 'a message without a sender has no address');
    $built = mail_build('crm@zzdesk.test', 'Desk', 'c@zzmail.test', 'S', 'B', 'h', ['reply_to' => 'support@zzdesk.test', 'in_reply_to' => '<a1@zzmail.test>', 'auto' => true]);
    check(str_contains($built, "Reply-To: <support@zzdesk.test>\r\n") && str_contains($built, "In-Reply-To: <a1@zzmail.test>\r\n") && str_contains($built, 'Auto-Submitted: auto-replied'), 'outgoing mail can carry Reply-To, In-Reply-To and the automatic-answer mark');
    $evil = mail_build('crm@zzdesk.test', 'Desk', 'c@zzmail.test', 'S', 'B', 'h', ['reply_to' => "a@b.test>\r\nBcc: x@evil.test", 'in_reply_to' => "<a@b>\r\nBcc: x@evil.test"]);
    check(!str_contains($evil, 'Bcc') && !str_contains($evil, 'Reply-To') && !str_contains($evil, 'In-Reply-To'), 'a header value with a line break in it is left out, never written');

    section('71. Email to ticket: from message to ticket');
    $pdo->exec("INSERT INTO companies (name, email) VALUES ('ZZE Mail Co', 'office@zzmail.test'), ('ZZE Other Co', 'other@zzmail.test')");
    $co21 = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZE Mail Co'")->fetchColumn();
    $pdo->prepare("INSERT INTO users (name, email, country, last_seen, created_at, company_id) VALUES ('ZZE Known Person', 'known@zzmail.test', 'Netherlands', NOW(), NOW(), ?), ('ZZE Colleague', 'colleague@zzmail.test', 'Netherlands', NOW(), NOW(), ?)")->execute([$co21, $co21]);
    $r = inbound_process($pdo, $eml21('Known Person <known@zzmail.test>', 'ZZE internet is down', "Hello,\nour internet is down since 9:00.", 'm1'), $cfg21);
    $t = ticket_find($pdo, $r['ticket_id']);
    check($r['outcome'] === 'new' && $t && $t['subject'] === 'ZZE internet is down' && str_contains($t['description'], 'since 9:00'), 'a message from a customer opens a ticket with its subject and text');
    same([(int) $t['company_id'], $t['user_name'], $t['requester_email'], $t['created_by'], $t['priority']], [$co21, 'ZZE Known Person', 'known@zzmail.test', 'email', 'normal'], 'a known sender is linked to the person and the company');
    check($t['resolve_due_at'] !== null, 'the emailed ticket gets its SLA targets like any other');
    $tid21 = (int) $t['id']; $ref21 = $t['reference'];
    $ob = $pdo->query('SELECT * FROM mail_outbox WHERE id > ' . $outbox21 . ' ORDER BY id DESC LIMIT 1')->fetch();
    check($ob && $ob['to_email'] === 'known@zzmail.test' && $ob['subject'] === "[$ref21] ZZE internet is down" && $ob['kind'] === 'ticket' && str_contains($ob['body'], $ref21), 'the sender gets a confirmation with the reference in the subject');
    same(inbound_process($pdo, $eml21('known@zzmail.test', 'ZZE internet is down', 'same again', 'm1'), $cfg21)['outcome'], 'duplicate', 'the same message handled twice is recognised by its Message-ID');
    same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE requester_email = 'known@zzmail.test'")->fetchColumn(), 1, 'and does not become a second ticket');
    // a reply by reference
    $r = inbound_process($pdo, $eml21('known@zzmail.test', "RE: [$ref21] ZZE internet is down", "Still down.\n\nOn Mon, 3 Nov 2026 at 10:00, Desk <crm@zzdesk.test> wrote:\n> We have opened ticket", 'm2'), $cfg21);
    $cm = $pdo->query("SELECT * FROM ticket_comments WHERE ticket_id = $tid21 ORDER BY id DESC LIMIT 1")->fetch();
    check($r['outcome'] === 'reply' && $r['ticket_id'] === $tid21 && $cm['body'] === 'Still down.' && $cm['source'] === 'email' && $cm['author'] === 'known@zzmail.test', 'a reply with the reference in its subject is added to the thread, without the quoted text');
    same(ticket_find($pdo, $tid21)['first_response_at'], null, 'a customer reply is not a first response by staff');
    $r = inbound_process($pdo, $eml21('colleague@zzmail.test', "[$ref21] also here", 'Same problem at my desk.', 'm3'), $cfg21);
    same($r['outcome'], 'reply', 'a colleague of the same company may add to the ticket');
    $r = inbound_process($pdo, $eml21('known@zzmail.test', 'another subject, same conversation', 'One more detail.', 'm4', ['In-Reply-To: <m2@zzmail.test>']), $cfg21);
    check($r['outcome'] === 'reply' && $r['ticket_id'] === $tid21, 'a reply without the reference is matched by the mail it answers');
    // somebody else quoting the reference
    $r = inbound_process($pdo, $eml21('stranger@zzmail.test', "[$ref21] let me in", 'I am not part of this.', 'm5'), $cfg21);
    check($r['outcome'] === 'new' && $r['ticket_id'] !== $tid21, 'a stranger who quotes the reference gets a new ticket instead of a place in the thread');
    $st = ticket_find($pdo, $r['ticket_id']);
    same([$st['company_id'], $st['user_id'], $st['subject']], [null, null, 'let me in'], 'an unknown sender gets a ticket without company, and the foreign reference is taken out of the subject');
    $r = inbound_process($pdo, $eml21('office@zzmail.test', 'ZZE question about the invoice', 'Can you resend it?', 'm6'), $cfg21);
    same((int) ticket_find($pdo, $r['ticket_id'])['company_id'], $co21, 'the general address of a company links the ticket to that company');
    // reopen
    ticket_update($pdo, ticket_find($pdo, $tid21), ['status' => 'resolved']);
    inbound_process($pdo, $eml21('known@zzmail.test', "Re: [$ref21] ZZE internet is down", 'It is down again!', 'm7'), $cfg21);
    $t = ticket_find($pdo, $tid21);
    same([$t['status'], $t['resolved_at']], ['open', null], 'a reply to a resolved ticket reopens it');
    ticket_update($pdo, $t, ['status' => 'waiting']);
    inbound_process($pdo, $eml21('known@zzmail.test', "Re: [$ref21] ZZE internet is down", 'Here is the information you asked for.', 'm8'), $cfg21);
    $t = ticket_find($pdo, $tid21);
    same([$t['status'], $t['waiting_since']], ['open', null], 'a reply takes a waiting ticket out of waiting, so the SLA clock runs again');
    // what is ignored
    $n0 = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
    same(inbound_process($pdo, $eml21('known@zzmail.test', 'ZZE Out of office', 'I am away.', 'm9', ['Auto-Submitted: auto-replied']), $cfg21)['note'], 'automatic mail', 'an out-of-office answer is ignored');
    same(inbound_process($pdo, $eml21('support@zzdesk.test', 'ZZE loop', 'x', 'm10'), $cfg21)['note'], 'sent by this system', 'mail from the support address itself is ignored');
    same(inbound_process($pdo, $eml21('crm@zzdesk.test', 'ZZE loop 2', 'x', 'm11'), $cfg21)['note'], 'sent by this system', 'and so is mail from the address the system sends with');
    same(inbound_process($pdo, "Subject: ZZE nobody\r\nMessage-ID: <m12@zzmail.test>\r\n\r\nx", $cfg21)['note'], 'no sender address', 'a message without a sender is ignored');
    same((int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn(), $n0, 'none of those opened a ticket');
    for ($i = 0; $i < INBOUND_MAX_PER_SENDER + 2; $i++) { $last = inbound_process($pdo, $eml21('flood@zzmail.test', "ZZE flood $i", 'x', "f$i"), ['auto_reply' => 0] + $cfg21); }
    same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE requester_email = 'flood@zzmail.test'")->fetchColumn(), INBOUND_MAX_PER_SENDER, 'one sender can open at most ' . INBOUND_MAX_PER_SENDER . ' tickets an hour');
    check($last['outcome'] === 'ignored' && str_contains($last['note'], 'too many'), 'the rest is ignored, with the reason noted');
    $noReply = ['auto_reply' => 0] + $cfg21;
    $ob0 = (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();
    inbound_process($pdo, $eml21('quiet@zzmail.test', 'ZZE no confirmation please', 'x', 'm13'), $noReply);
    same((int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn(), $ob0, 'with the confirmation turned off no mail is sent for a new ticket');
    $long = inbound_process($pdo, $eml21('long@zzmail.test', 'ZZE ' . str_repeat('long subject ', 30), str_repeat('word ', 900), 'm14'), $noReply);
    $lt = ticket_find($pdo, $long['ticket_id']);
    check(mb_strlen($lt['subject']) <= 160 && mb_strlen($lt['subject']) >= 150 && mb_strlen($lt['description']) <= 2000 && str_contains($lt['description'], 'cut off here'), 'a very long subject and text are shortened to fit, and the text says so');
    $xss = inbound_process($pdo, $eml21('x@zzmail.test', 'ZZE <script>alert(1)</script>', '<img src=x onerror=alert(1)>', 'm15'), $noReply);
    $page = http('GET', "$base/tickets/ticket.php?id=" . $xss['ticket_id'], ['jar' => $admin])['body'];
    check(!str_contains($page, '<script>alert(1)</script>') && !str_contains($page, '<img src=x') && str_contains($page, '&lt;img src=x'), 'HTML in a message is shown as text on the ticket page, never run');

    section('72. Email to ticket: the mailbox over IMAP');
    @mkdir($imapBox);
    file_put_contents("$imapBox/1.eml", $eml21('known@zzmail.test', 'ZZE via imap one', 'First message.', 'i1'));
    file_put_contents("$imapBox/2.eml", $eml21('known@zzmail.test', "Re: [$ref21] ZZE internet is down", 'Reply over IMAP.', 'i2'));
    file_put_contents("$imapBox/3.eml", $eml21('known@zzmail.test', 'ZZE Away', 'auto', 'i3', ['Auto-Submitted: auto-generated']));
    $imapProc = proc_open([PHP_BINARY, TESTS_DIR . '/mock_imap.php', (string) $imapPort], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/mock_imap.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/mock_imap.log', 'a']], $imapPipes);
    for ($i = 0; $i < 30; $i++) { $c = @stream_socket_client("tcp://127.0.0.1:$imapPort", $en, $es, 1); if ($c) { fclose($c); break; } usleep(200000); }
    $imapCfg = ['host' => '127.0.0.1', 'port' => $imapPort, 'encryption' => 'none', 'username' => 'support "desk"', 'folder' => 'INBOX'];
    $r = imap_fetch_unread($imapCfg, 'goodpass', null);
    same([$r['state'], $r['unread'], $r['count']], ['ok', 3, 0], 'the connection test signs in (a user name with quotes in it) and counts 3 unread messages, without reading them');
    same(imap_fetch_unread($imapCfg, 'wrongpass', null)['state'], 'unauthorized', 'a wrong password is reported as unauthorized');
    check(!str_contains(json_encode(imap_fetch_unread($imapCfg, 'wrong-SECRET-pass', null)), 'SECRET'), 'and the error never contains the password');
    same(imap_fetch_unread(['folder' => 'Missing'] + $imapCfg, 'goodpass', null)['state'], 'invalid', 'a folder that does not exist is reported');
    same(imap_fetch_unread(['port' => 3297] + $imapCfg, 'goodpass', null)['state'], 'unreachable', 'a closed port is reported as unreachable');
    same(imap_fetch_unread($imapCfg, "good\r\nA9 LOGOUT", null)['state'], 'invalid', 'a password with a line break is refused before anything is sent');
    $putRow21('inbox_settings', ['id' => 1, 'enabled' => 1, 'host' => '127.0.0.1', 'port' => $imapPort, 'encryption' => 'none', 'username' => 'support', 'password_enc' => secret_encrypt('goodpass'), 'folder' => 'INBOX', 'address' => 'support@zzdesk.test', 'auto_reply' => 0]);
    $run = inbox_run($pdo);
    same([$run['state'], $run['new'], $run['replies'], $run['ignored']], ['ok', 1, 1, 1], 'one run reads the mailbox: 1 new ticket, 1 reply, 1 ignored');
    same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE subject = 'ZZE via imap one'")->fetchColumn(), 1, 'the new message became a ticket');
    same((string) $pdo->query("SELECT body FROM ticket_comments WHERE ticket_id = $tid21 ORDER BY id DESC LIMIT 1")->fetchColumn(), 'Reply over IMAP.', 'the reply landed in the right thread');
    same(json_decode((string) file_get_contents($imapSeen), true), [1, 2, 3], 'all three were marked as read on the server');
    $run = inbox_run($pdo);
    same([$run['new'], $run['replies'], $run['ignored']], [0, 0, 0], 'a second run finds nothing new');
    @unlink($imapSeen);   // the server "forgets" that they were read
    $run = inbox_run($pdo);
    same([$run['new'], $run['replies'], $run['ignored'], (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE subject = 'ZZE via imap one'")->fetchColumn()], [0, 0, 3, 1], 'messages offered again are recognised and never become a second ticket');
    $s = inbox_settings($pdo);
    check($s['last_state'] === 'ok' && $s['last_run_at'] !== null, 'the settings remember when the mailbox was last read and how it went');
    $pdo->exec('UPDATE inbox_settings SET enabled = 0 WHERE id = 1');
    same(inbox_run($pdo)['state'], 'off', 'turned off: nothing is read');
    $out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg((string) realpath(TESTS_DIR . '/../bin/poll_inbox.php')), $out, $rc);
    check($rc === 0 && str_contains(implode("\n", $out), 'turned off'), 'bin/poll_inbox.php says so and exits cleanly', implode(' ', $out));
    same(http('GET', "$base/bin/poll_inbox.php")['code'], 403, 'the job can not be started from a browser');
    same(module_for_page('bin/poll_inbox.php'), 'tickets', 'and it belongs to the tickets module');

    section('73. Email to ticket: settings and replying by email');
    $pdo->exec('DELETE FROM inbox_settings'); $pdo->exec('DELETE FROM rate_limits');
    $boxForm = ['tab' => 'tickets', 'action' => 'inbox_save', 'host' => '127.0.0.1', 'port' => (string) $imapPort, 'encryption' => 'none', 'username' => 'support', 'password' => 'goodpass', 'folder' => 'INBOX', 'address' => 'Support@ZZdesk.test', 'auto_reply' => '1', 'enabled' => '1'];
    same($post($user, 'settings/settings.php?tab=tickets', $boxForm, 'settings/settings.php')['code'], 403, 'a normal account can not save the mailbox');
    $post($admin, 'settings/settings.php?tab=tickets', ['host' => 'bad host!'] + $boxForm, 'settings/settings.php?tab=tickets');
    $post($admin, 'settings/settings.php?tab=tickets', ['password' => ''] + $boxForm, 'settings/settings.php?tab=tickets');
    same((int) $pdo->query('SELECT COUNT(*) FROM inbox_settings')->fetchColumn(), 0, 'an invalid server name, or turning it on without a password, saves nothing');
    $post($admin, 'settings/settings.php?tab=tickets', $boxForm, 'settings/settings.php?tab=tickets');
    $s = $pdo->query('SELECT * FROM inbox_settings WHERE id = 1')->fetch();
    check($s && (int) $s['enabled'] === 1 && $s['address'] === 'support@zzdesk.test' && !str_contains((string) $s['password_enc'], 'goodpass') && secret_decrypt((string) $s['password_enc']) === 'goodpass', 'the mailbox is saved and its password is stored encrypted');
    $tabHtml = http('GET', "$base/settings/settings.php?tab=tickets", ['jar' => $admin])['body'];
    check(!str_contains($tabHtml, 'goodpass') && str_contains($tabHtml, 'leave empty to keep'), 'the settings page never shows the password');
    $post($admin, 'settings/settings.php?tab=tickets', ['password' => '', 'folder' => 'Support'] + $boxForm, 'settings/settings.php?tab=tickets');
    $s = $pdo->query('SELECT * FROM inbox_settings WHERE id = 1')->fetch();
    check($s['folder'] === 'Support' && secret_decrypt((string) $s['password_enc']) === 'goodpass', 'a blank password keeps the saved one');
    $pdo->exec("UPDATE inbox_settings SET folder = 'INBOX' WHERE id = 1");
    file_put_contents("$imapBox/4.eml", $eml21('known@zzmail.test', 'ZZE from the button', 'Read by the button.', 'i4'));
    $post($admin, 'settings/settings.php?tab=tickets', ['tab' => 'tickets', 'action' => 'inbox_run'], 'settings/settings.php?tab=tickets');
    same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE subject = 'ZZE from the button'")->fetchColumn(), 1, '"Read the mailbox now" reads it from the settings page');
    check(str_contains(http('GET', "$base/settings/settings.php?tab=tickets", ['jar' => $admin])['body'], 'ZZE from the button'), 'and the page lists the messages that were read');
    $log = (string) $pdo->query("SELECT GROUP_CONCAT(CONCAT(action, ' ', target)) FROM activity_logs WHERE action = 'Inbox settings saved'")->fetchColumn();
    check(str_contains($log, 'Inbox settings saved') && !str_contains($log, 'goodpass'), 'saving is in the activity log, without the password');
    // replying by email from the ticket page
    $tp = http('GET', "$base/tickets/ticket.php?id=$tid21", ['jar' => $admin])['body'];
    check(str_contains($tp, 'name="email_reply"') && str_contains($tp, 'known@zzmail.test') && str_contains($tp, 'by email'), 'an emailed ticket offers "also email this reply" and marks the comments that came in by email');
    $ob0 = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM mail_outbox')->fetchColumn();
    $post($admin, 'tickets/ticket.php', ['id' => (string) $tid21, 'action' => 'comment', 'body' => 'An engineer comes by tomorrow.', 'email_reply' => '1'], "tickets/ticket.php?id=$tid21");
    $ob = $pdo->query("SELECT * FROM mail_outbox WHERE id > $ob0 ORDER BY id DESC LIMIT 1")->fetch();
    check($ob && $ob['to_email'] === 'known@zzmail.test' && $ob['subject'] === "Re: [$ref21] ZZE internet is down" && str_contains($ob['body'], 'An engineer comes by tomorrow.') && str_contains($ob['body'], 'Kind regards'), 'the reply is mailed to the customer with the reference in the subject and a sign-off');
    check(ticket_find($pdo, $tid21)['first_response_at'] !== null, 'and it counts as the first response by staff');
    $post($admin, 'tickets/ticket.php', ['id' => (string) $tid21, 'action' => 'comment', 'body' => 'Internal note, not for the customer.'], "tickets/ticket.php?id=$tid21");
    same((int) $pdo->query("SELECT COUNT(*) FROM mail_outbox WHERE id > $ob0")->fetchColumn(), 1, 'a comment without the tick is not mailed');
    same(ticket_reply_address($pdo, ['requester_email' => '', 'user_id' => null]), '', 'a ticket without requester or contact person has nobody to mail');
    $plain = (int) ticket_create($pdo, ['subject' => 'ZZE typed in by hand', 'description' => 'x', 'priority' => 'normal'], 'tester')[0];
    check(!str_contains(http('GET', "$base/tickets/ticket.php?id=$plain", ['jar' => $admin])['body'], 'name="email_reply"'), 'and its page does not offer to mail the reply');
    // privacy: a sender without a user account can be found, exported and removed
    $pv = http('GET', "$base/privacy/privacy.php?email=stranger@zzmail.test", ['jar' => $admin])['body'];
    check(str_contains($pv, 'Tickets sent in by email') && str_contains($pv, 'value="erase_mail"'), 'Privacy requests finds a sender who only ever mailed the support address');
    $ex = $post($admin, 'privacy/privacy.php', ['email' => 'stranger@zzmail.test', 'action' => 'export'], 'privacy/privacy.php?email=stranger@zzmail.test');
    check(str_contains($ex['body'], '"emailed_tickets"') && str_contains($ex['body'], 'let me in'), 'the export holds the ticket that person mailed in');
    $post($admin, 'privacy/privacy.php', ['email' => 'stranger@zzmail.test', 'action' => 'erase_mail'], 'privacy/privacy.php?email=stranger@zzmail.test');
    same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE requester_email = 'stranger@zzmail.test'")->fetchColumn(), 1, 'without the confirmation tick nothing is removed');
    $post($admin, 'privacy/privacy.php', ['email' => 'stranger@zzmail.test', 'action' => 'erase_mail', 'confirm' => 'yes'], 'privacy/privacy.php?email=stranger@zzmail.test');
    same([(int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE requester_email = 'stranger@zzmail.test'")->fetchColumn(), (int) $pdo->query("SELECT COUNT(*) FROM ticket_emails WHERE from_email = 'stranger@zzmail.test'")->fetchColumn(), (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE subject = 'let me in'")->fetchColumn()], [0, 0, 1], 'with it the address is gone from the ticket and the list of messages read, and the ticket itself stays');
} finally {
    if (is_resource($imapProc)) { proc_terminate($imapProc); proc_close($imapProc); }
    $rmBox21();
    $putRow21('inbox_settings', $inboxSaved21);
    $putRow21('mail_settings', $mailSaved21);
    $clean21();
}
