<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 8 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 8: email, password reset, invitations
    // =========================================================
    $smtpPort = 3225; $smtpLog = TESTS_DIR . '/.smtp_log'; $smtpMode = TESTS_DIR . '/.smtp_mode';
    $mailBackup = $pdo->query('SELECT * FROM mail_settings WHERE id = 1')->fetch() ?: null;
    $notifyBackup = $pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'")->fetchColumn();
    $outboxStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM mail_outbox')->fetchColumn();
    @unlink($smtpLog); @unlink($smtpMode);
    $smtpProc = proc_open([PHP_BINARY, TESTS_DIR . '/mock_smtp.php', (string) $smtpPort], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/mock_smtp.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/mock_smtp.log', 'a']], $smtpPipes);
    for ($i = 0; $i < 30; $i++) { $t = @stream_socket_client("tcp://127.0.0.1:$smtpPort", $en, $es, 1); if ($t) { fclose($t); break; } usleep(200000); }
    $smtpLast = function () use ($smtpLog) { $l = is_file($smtpLog) ? array_filter(explode("\n", (string) file_get_contents($smtpLog))) : []; return $l ? json_decode(end($l), true) : null; };
    $smtpCount = fn() => is_file($smtpLog) ? count(array_filter(explode("\n", (string) file_get_contents($smtpLog)))) : 0;
    $cleanMail = function () use ($pdo, $mailBackup, $notifyBackup, $outboxStart, $smtpMode) {
        @unlink($smtpMode);
        $pdo->exec("DELETE FROM admins WHERE username LIKE 'zz.rst%' OR username LIKE 'zz.inv%'");
        $pdo->exec("DELETE FROM invitations WHERE username LIKE 'zz.inv%' OR email LIKE 'zz.inv%'");
        $pdo->exec("DELETE FROM login_attempts WHERE username LIKE 'zz.%'");
        $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZM%'");
        $pdo->exec("DELETE FROM notifications WHERE title LIKE '%ZZM%' OR body LIKE '%ZZM%'");
        $pdo->exec('DELETE FROM mail_outbox WHERE id > ' . $outboxStart);
        $pdo->exec('DELETE FROM mail_settings');
        if ($mailBackup) { $pdo->prepare('INSERT INTO mail_settings (id, host, port, encryption, username, password_enc, from_email, from_name, base_url, updated_at) VALUES (1,?,?,?,?,?,?,?,?,?)')->execute([$mailBackup['host'], $mailBackup['port'], $mailBackup['encryption'], $mailBackup['username'], $mailBackup['password_enc'], $mailBackup['from_email'], $mailBackup['from_name'], $mailBackup['base_url'], $mailBackup['updated_at']]); }
        if ($notifyBackup !== false) { $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('notify_email', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$notifyBackup]); }
        $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND (action IN ('Email settings saved','Email password removed','Test email','Email outbox cleared','Password reset requested','Password reset completed','Password reset not sent','Invitation sent','Invitation accepted','Invitation withdrawn') OR target LIKE 'zz.%')");
        $pdo->exec('DELETE FROM rate_limits');
    };
    $cleanMail();
    try {
    section('23. Email: the mailer and Settings > Email');
    $pdo->exec('DELETE FROM rate_limits');
    same(http('GET', "$base/settings/settings.php?tab=email")['code'], 302, 'signed out: the Email tab redirects to the login page');
    same(http('GET', "$base/settings/settings.php?tab=email", ['jar' => $user])['code'], 403, 'a normal account gets 403 on the Email tab');
    check(!str_contains(http('GET', "$base/settings/settings.php", ['jar' => $user])['body'], 'tab=email') && str_contains(http('GET', "$base/settings/settings.php", ['jar' => $admin])['body'], 'tab=email'), 'the Email tab is hidden from normal accounts and shown to admins');
    same($post($user, 'settings/settings.php?tab=email', ['tab' => 'email', 'action' => 'save', 'host' => 'evil.example.test', 'port' => '25', 'encryption' => 'none', 'from_email' => 'x@example.test'], 'settings/settings.php')['code'], 403, 'a forged save by a normal account is 403');
    same((int) $pdo->query('SELECT COUNT(*) FROM mail_settings')->fetchColumn(), 0, 'and nothing was saved');
    check(str_contains(http('GET', "$base/settings/settings.php?tab=email", ['jar' => $admin])['body'], 'Outbox only (no mail server on this computer)'), 'with no settings the status says messages are only kept in the outbox (localhost)');
    $em = fn(array $d) => $post($admin, 'settings/settings.php?tab=email', ['tab' => 'email', 'action' => 'save'] + $d, 'settings/settings.php?tab=email');
    $good = ['host' => '127.0.0.1', 'port' => (string) $smtpPort, 'encryption' => 'none', 'username' => 'mailer-user', 'password' => 'S3cret-Mail-Pw', 'from_email' => 'no-reply@example.test', 'from_name' => 'Test Sender', 'base_url' => ''];
    foreach ([['mail server name is not valid', ['host' => 'bad host!']], ['port must be between', ['port' => '70000']], ['port must be between', ['port' => '0']], ['how the connection is secured', ['encryption' => 'weird']], ['sender address is not a valid', ['from_email' => 'nope']], ['Enter the sender address', ['from_email' => '']], ['site address must look like', ['base_url' => 'javascript:alert(1)']], ['site address must look like', ['base_url' => 'https://a.test/?x=1']]] as [$msg, $bad]) {
        $em($bad + $good);
        $page = http('GET', "$base/settings/settings.php?tab=email", ['jar' => $admin])['body'];
        same((int) $pdo->query('SELECT COUNT(*) FROM mail_settings')->fetchColumn(), 0, 'validation refuses: ' . $msg);
    }
    $em($good);
    $c = $pdo->query('SELECT * FROM mail_settings WHERE id = 1')->fetch();
    check($c && $c['host'] === '127.0.0.1' && (int) $c['port'] === $smtpPort && $c['username'] === 'mailer-user' && $c['from_email'] === 'no-reply@example.test', 'valid settings are saved');
    check($c['password_enc'] !== null && !str_contains((string) $c['password_enc'], 'S3cret') && secret_decrypt((string) $c['password_enc']) === 'S3cret-Mail-Pw', 'the SMTP password is stored encrypted and decrypts to what was typed');
    $page = http('GET', "$base/settings/settings.php?tab=email", ['jar' => $admin])['body'];
    check(!str_contains($page, 'S3cret-Mail-Pw') && str_contains($page, '(saved, leave empty to keep)') && str_contains($page, 'value="127.0.0.1"'), 'the page never shows the password, only that one is saved');
    $enc1 = $c['password_enc'];
    $em(['password' => ''] + $good);
    same($pdo->query('SELECT password_enc FROM mail_settings WHERE id = 1')->fetchColumn(), $enc1, 'saving with an empty password keeps the saved one');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Email settings saved' AND target LIKE '127.0.0.1%'")->fetchColumn() >= 1 && !str_contains((string) $pdo->query("SELECT GROUP_CONCAT(CONCAT(action, target)) FROM activity_logs WHERE action LIKE 'Email%'")->fetchColumn(), 'S3cret'), 'saving is logged, without the password');

    // sending through the mock server
    $n0 = $smtpCount();
    $r = $post($admin, 'settings/settings.php?tab=email', ['tab' => 'email', 'action' => 'test'], 'settings/settings.php?tab=email');
    $got = $smtpLast();
    check($smtpCount() === $n0 + 1 && $got && $got['user'] === 'mailer-user' && $got['pass'] === 'S3cret-Mail-Pw' && $got['from'] === 'no-reply@example.test' && $got['rcpt'] === ['admin@telecomlijn.test'], 'a test email goes through the SMTP server: login, sender and recipient are right');
    preg_match('/\r\n\r\n(.*)$/s', (string) ($got['data'] ?? ''), $bm);
    check(str_contains((string) base64_decode(str_replace("\r\n", '', $bm[1] ?? '')), 'If you can read this, the email settings work.') && str_contains((string) $got['data'], 'Content-Type: text/plain; charset=UTF-8') && str_contains((string) $got['data'], 'Subject: Test email from Telecomlijn Admin'), 'the message has the right subject, text/plain UTF-8 and a base64 body that reads correctly');
    same($pdo->query("SELECT status FROM mail_outbox WHERE kind = 'test' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'sent', 'the outbox records it as sent');
    check(str_contains(http('GET', "$base/settings/settings.php?tab=email", ['jar' => $admin])['body'], 'Test email from Telecomlijn Admin'), 'the Outbox section lists it');
    // refusals
    file_put_contents($smtpMode, 'authfail');
    [$st, , $note] = mail_send($pdo, 'someone@example.test', 'Subject', 'Body', 'other');
    check($st === 'failed' && str_contains($note, 'refused') && !str_contains($note, 'S3cret'), 'a refused login is reported as failed, without the password in the message', $note);
    file_put_contents($smtpMode, 'rcptfail');
    [$st, , $note] = mail_send($pdo, 'someone@example.test', 'Subject', 'Body', 'other');
    check($st === 'failed' && str_contains($note, 'No such user'), 'a refused recipient is reported as failed with the server\'s reason');
    @unlink($smtpMode);
    $pdo->prepare("UPDATE mail_settings SET port = 3197 WHERE id = 1")->execute();
    [$st, , $note] = mail_send($pdo, 'someone@example.test', 'Subject', 'Body', 'other');
    check($st === 'failed' && str_contains($note, 'Could not connect'), 'a closed port is reported as failed');
    $pdo->prepare("UPDATE mail_settings SET port = ? WHERE id = 1")->execute([$smtpPort]);
    same($pdo->query("SELECT status FROM mail_outbox ORDER BY id DESC LIMIT 1")->fetchColumn(), 'failed', 'failed messages stay in the outbox with status failed');
    // injection and validation
    $n0 = $smtpCount();
    foreach (["a@b.test\r\nBcc: evil@x.test", "a@b.test\nBcc: evil@x.test", 'not-an-email', '', str_repeat('a', 200) . '@b.test'] as $badTo) {
        [$st] = mail_send($pdo, $badTo, 'Hi', 'Body', 'other');
        if ($st !== 'failed') { check(false, 'an invalid recipient is refused: ' . json_encode($badTo)); }
    }
    same($smtpCount(), $n0, 'recipients with line breaks, nonsense or over-long addresses are refused before anything is sent');
    mail_send($pdo, 'inj@example.test', "Hello\r\nBcc: evil@x.test", 'Body', 'other');
    $got = $smtpLast();
    preg_match('/^(.*?)\r\n\r\n/s', (string) $got['data'], $hm);
    check(!str_contains($hm[1] ?? '', "\r\nBcc:") && str_contains($hm[1], 'Subject: Hello Bcc: evil@x.test'), 'a line break in the subject can not inject a header');
    mail_send($pdo, 'uni@example.test', "Café résumé ✓", "Body ünïcode", 'other');
    $got = $smtpLast();
    check(str_contains((string) $got['data'], 'Subject: =?UTF-8?B?' . base64_encode('Café résumé ✓') . '?='), 'a subject with accents is encoded correctly');
    same($pdo->query("SELECT body FROM mail_outbox WHERE to_email = 'uni@example.test'")->fetchColumn(), 'Body ünïcode', 'the outbox keeps the original text');
    $msg = mail_build('a@b.test', "Evil\r\nBcc: x@y.test", 'c@d.test', 'S', "Line\n.\nLine", 'host');
    check(!str_contains(strtok($msg, "\r\n\r\n"), 'Bcc') && substr_count($msg, "\r\n\r\n") >= 1, 'the sender name can not inject a header either');
    $pdo->exec("INSERT INTO mail_outbox (kind, to_email, subject, body, status) VALUES ('other', 'x@y.test', 'ZZM <b>bold</b>', '<script>alert(1)</script>', 'recorded')");
    $ob = http('GET', "$base/settings/settings.php?tab=email", ['jar' => $admin])['body'];
    check(!str_contains($ob, '<script>alert(1)') && str_contains($ob, '&lt;script&gt;') && !str_contains($ob, '<b>bold</b>'), 'the outbox shows messages as text: markup is escaped');
    // wiping one-time links once delivered
    [$st, $id] = mail_send($pdo, 'wipe@example.test', 'Link', 'Open https://x.test/reset.php?token=SECRETTOKEN', 'reset');
    check($st === 'sent' && !str_contains((string) $pdo->query("SELECT body FROM mail_outbox WHERE id = $id")->fetchColumn(), 'SECRETTOKEN'), 'a delivered message that contained a one-time link loses its text in the outbox');
    [$st, $id] = mail_send($pdo, 'keep@example.test', 'Plain', 'No link here', 'other');
    same($pdo->query("SELECT body FROM mail_outbox WHERE id = $id")->fetchColumn(), 'No link here', 'other delivered messages keep their text');
    // clearing
    $post($user, 'settings/settings.php?tab=email', ['tab' => 'email', 'action' => 'clear_outbox'], 'settings/settings.php');
    check((int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn() > 0, 'a normal account can not empty the outbox');
    // notifications by email (stage 6)
    $pdo->exec("DELETE FROM app_meta WHERE k = 'notify_email'");
    $pdo->exec("INSERT INTO app_meta (k, v) VALUES ('notify_email', 'alerts@example.test')");
    notifications_refresh($pdo, true);   // absorb existing ones
    $pdo->exec("UPDATE mail_settings SET base_url = 'https://admin.example.test' WHERE id = 1");   // links in emails need a site address
    $n0 = $smtpCount();
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZM-T-1', 'ZZM Server room on fire', 'x', 'urgent', 'open', NOW(), NOW())");
    notifications_refresh($pdo, true);
    $got = $smtpLast();
    check($smtpCount() === $n0 + 1 && $got['rcpt'] === ['alerts@example.test'], 'a new notification is emailed to the notification address');
    $dec = (string) base64_decode(str_replace("\r\n", '', preg_replace('/^.*?\r\n\r\n/s', '', (string) $got['data'])));
    check(str_contains((string) $got['data'], 'Subject: Telecomlijn Admin: Urgent ticket ZZM-T-1') && str_contains($dec, 'ZZM Server room on fire') && str_contains($dec, 'https://admin.example.test/tickets/ticket.php?id='), 'the email names the event and links to it');
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE emailed_at IS NULL")->fetchColumn(), 0, 'every notification is marked as handled');
    notifications_refresh($pdo, true);
    same($smtpCount(), $n0 + 1, 'the same event is never emailed twice');
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZM-T-2', 'ZZM Second fire', 'x', 'urgent', 'open', NOW(), NOW()), ('ZZM-T-3', 'ZZM Third fire', 'x', 'urgent', 'open', NOW(), NOW())");
    notifications_refresh($pdo, true);
    $got = $smtpLast();
    check($smtpCount() === $n0 + 2 && str_contains((string) $got['data'], 'Subject: Telecomlijn Admin: 2 new notifications'), 'several new events at once are sent as one email');
    $pdo->exec("INSERT INTO app_meta (k, v) VALUES ('notify_email', '') ON DUPLICATE KEY UPDATE v = ''");
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZM-T-4', 'ZZM Quiet fire', 'x', 'urgent', 'open', NOW(), NOW())");
    notifications_refresh($pdo, true);
    check($smtpCount() === $n0 + 2 && (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE emailed_at IS NULL")->fetchColumn() === 0, 'with the address emptied nothing is sent, and the backlog is not mailed later');
    $pdo->exec("INSERT INTO app_meta (k, v) VALUES ('notify_email', 'alerts@example.test') ON DUPLICATE KEY UPDATE v = 'alerts@example.test'");
    $pdo->exec("INSERT INTO notifications (kind, dedupe_key, title, body, is_demo, created_at) VALUES ('ticket_urgent', 'zzm-demo', 'ZZM demo event', 'x', 1, NOW())");
    notifications_refresh($pdo, true);
    check($smtpCount() === $n0 + 2 && (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'zzm-demo' AND emailed_at IS NOT NULL")->fetchColumn() === 1, 'demo-data events are never emailed to the real address');
    // the old outbox is tidied
    $pdo->exec("INSERT INTO mail_outbox (kind, to_email, subject, body, status, created_at) VALUES ('other', 'old@y.test', 'ZZM old', 'x', 'sent', NOW() - INTERVAL 40 DAY)");
    mail_purge($pdo);
    same((int) $pdo->query("SELECT COUNT(*) FROM mail_outbox WHERE subject = 'ZZM old'")->fetchColumn(), 0, 'outbox entries older than 30 days are removed');
    $post($admin, 'settings/settings.php?tab=email', ['tab' => 'email', 'action' => 'clear_password'], 'settings/settings.php?tab=email');
    same($pdo->query('SELECT password_enc FROM mail_settings WHERE id = 1')->fetchColumn(), null, 'the saved password can be removed');
    $pdo->exec('DELETE FROM mail_settings');   // from here on: no SMTP, like a fresh install on localhost

    // =========================================================
    section('24. Password reset');
    $pdo->exec('DELETE FROM rate_limits');
    $oldPw = 'Old-Password-12345'; $newPw = 'Brand-New-Passw0rd!';
    $pdo->prepare("INSERT INTO admins (username, email, name, password_hash, role, perm_integrations, must_change_password) VALUES ('zz.rst', 'zz.rst@example.test', 'Reset Tester', ?, 'user', 'none', 0), ('zz.rst.off', 'zz.rst.off@example.test', 'Disabled Tester', ?, 'user', 'none', 0)")->execute([hash_password($oldPw), hash_password($oldPw)]);
    $pdo->exec("UPDATE admins SET is_active = 0 WHERE username = 'zz.rst.off'");
    $rid = (int) $pdo->query("SELECT id FROM admins WHERE username = 'zz.rst'")->fetchColumn();
    check(str_contains(http('GET', "$base/auth/login.php")['body'], 'href="auth/forgot.php"'), 'the login page has a Forgot password? link');
    $forgot = function (string $who, ?string $jar = null) use ($base, $pdo, $newJar) {
        $pdo->exec('DELETE FROM rate_limits');
        $jar ??= $newJar();
        $g = http('GET', "$base/auth/forgot.php", ['jar' => $jar]);
        return http('POST', "$base/auth/forgot.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($g['body']), 'who' => $who])]);
    };
    $statusText = fn(array $r) => preg_match('/role="status"[^>]*>([^<]+)</', $r['body'], $m) ? trim($m[1]) : '';
    $linkFor = function (string $email) use ($pdo) { $b = (string) $pdo->query('SELECT body FROM mail_outbox WHERE to_email = ' . $pdo->quote($email) . " AND kind = 'reset' ORDER BY id DESC LIMIT 1")->fetchColumn(); return preg_match('/reset\.php\?token=([a-f0-9]{64})/', $b, $m) ? $m[1] : ''; };
    same(http('GET', "$base/auth/forgot.php")['code'], 200, 'the forgot page opens without signing in');
    same(http('POST', "$base/auth/forgot.php", ['body' => 'who=zz.rst'])['code'], 403, 'a reset request without a CSRF token is 403');
    $known = $forgot('zz.rst');
    $unknown = $forgot('nobody-here-at-all');
    $byMail = $forgot('ZZ.RST@example.test');
    check($known['code'] === 200 && $statusText($known) !== '' && $statusText($known) === $statusText($unknown) && $statusText($known) === $statusText($forgot('zz.rst.off')), 'the page says exactly the same for a real account, an unknown one and a disabled one');
    check(strip_tags(preg_replace('/<script.*?<\/script>|<style.*?<\/style>/s', '', $known['body'])) === strip_tags(preg_replace('/<script.*?<\/script>|<style.*?<\/style>/s', '', $unknown['body'])) , 'and the whole visible page is identical');
    $tok = $linkFor('zz.rst@example.test');
    check(strlen($tok) === 64, 'the real account got an email with a 64-character random token link (read from the outbox on localhost)');
    same((int) $pdo->query("SELECT COUNT(*) FROM mail_outbox WHERE to_email IN ('nobody-here-at-all', 'zz.rst.off@example.test')")->fetchColumn(), 0, 'no email is made for an unknown or disabled account');
    same((int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE admin_id = (SELECT id FROM admins WHERE username = 'zz.rst.off')")->fetchColumn(), 0, 'and no reset record either');
    $row = $pdo->query("SELECT * FROM password_resets WHERE admin_id = $rid ORDER BY id DESC LIMIT 1")->fetch();
    check($row['token_hash'] === hash('sha256', $tok) && !str_contains(json_encode($row), $tok) && $row['used_at'] === null, 'only the SHA-256 hash of the token is stored');
    $mins = (int) $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) FROM password_resets WHERE id = " . (int) $row['id'])->fetchColumn();
    check($mins >= 58 && $mins <= 60, 'it is valid for one hour', (string) $mins);
    check(str_contains(http('GET', "$base/auth/reset.php?token=$tok")['body'], 'name="confirm"') && str_contains(http('GET', "$base/auth/reset.php?token=$tok")['body'], 'zz.rst'), 'the link opens a form for that account');
    $badOpened = [];
    foreach (['', 'abc', strrev($tok), substr($tok, 0, 63), $tok . 'a', strtoupper($tok), substr($tok, 0, 63) . ($tok[63] === 'a' ? 'b' : 'a'), "$tok' OR '1'='1", 'x'] as $badTok) {
        $b = http('GET', "$base/auth/reset.php?token=" . urlencode($badTok))['body'];
        if (!str_contains($b, 'This link does not work') || str_contains($b, 'name="confirm"')) { $badOpened[] = $badTok; }
    }
    check(!$badOpened, 'tampered, truncated, reversed, upper-cased, extended and SQL-looking tokens all show "This link does not work"', json_encode($badOpened));
    $pdo->exec("DELETE FROM login_attempts WHERE username = 'tok:link'");
    // the form
    $rp = function (string $t, string $new, string $conf, ?string $jar = null) use ($base, $pdo, $newJar) {
        $pdo->exec('DELETE FROM rate_limits'); $pdo->exec("DELETE FROM login_attempts WHERE username = 'tok:link'");
        $jar ??= $newJar();
        $g = http('GET', "$base/auth/reset.php?token=$t", ['jar' => $jar]);
        return http('POST', "$base/auth/reset.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($g['body']), 'token' => $t, 'new' => $new, 'confirm' => $conf])]);
    };
    same(http('POST', "$base/auth/reset.php", ['body' => http_build_query(['token' => $tok, 'new' => $newPw, 'confirm' => $newPw])])['code'], 403, 'saving a new password without a CSRF token is 403');
    $r = $rp($tok, 'short', 'short');
    check($r['code'] === 200 && str_contains($r['body'], 'between 12 and 256'), 'a password under 12 characters is refused');
    $r = $rp($tok, $newPw, $newPw . 'x');
    check($r['code'] === 200 && str_contains($r['body'], 'do not match'), 'a mismatching confirmation is refused');
    $r = $rp($tok, 'xx-zz.rst-xx-12345', 'xx-zz.rst-xx-12345');
    check($r['code'] === 200 && str_contains($r['body'], 'can not contain your user name'), 'a password containing the user name is refused');
    check(str_contains(http('GET', "$base/auth/reset.php?token=$tok")['body'], 'name="confirm"'), 'after refused attempts the link still works');
    // an open session that must end
    $oldSession = $newJar(); $pwStep($oldSession, 'zz.rst', $oldPw);
    check($signedIn($oldSession), 'before the reset the person is signed in somewhere (an old session)');
    $r = $rp($tok, $newPw, $newPw);
    check($r['code'] === 302 && str_contains($r['headers'], 'auth/login.php?reset=1'), 'a valid new password is saved and the person is sent to the login page');
    check(str_contains(http('GET', "$base/auth/login.php?reset=1")['body'], 'Your password was changed'), 'the login page confirms it');
    $j = $newJar(); $r = $pwStep($j, 'zz.rst', $newPw);
    check($r['code'] === 302 && str_contains($r['headers'], 'index.php') && $signedIn($j), 'the new password signs in');
    $j2 = $newJar(); $r = $pwStep($j2, 'zz.rst', $oldPw);
    check($r['code'] === 200 && !$signedIn($j2), 'the old password no longer works');
    check(!$signedIn($oldSession), 'every session that was open before the reset has ended');
    check($signedIn($j), 'while a session started after the reset works');
    $b = http('GET', "$base/auth/reset.php?token=$tok")['body'];
    check(str_contains($b, 'This link does not work'), 'the link can not be used a second time');
    $r = $rp($tok, 'Another-Passw0rd-1', 'Another-Passw0rd-1');
    check(!str_contains($r['headers'], 'auth/login.php?reset=1') && $pwStep($newJar(), 'zz.rst', 'Another-Passw0rd-1')['code'] === 200, 'posting to a used link changes nothing');
    same((int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE admin_id = $rid")->fetchColumn(), 0, 'the account\'s reset records are removed after a successful reset');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Password reset completed' AND target = 'zz.rst'")->fetchColumn() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Password reset requested' AND target = 'zz.rst'")->fetchColumn() >= 1, 'the request and the completed reset are in the activity log');
    // expiry
    $forgot('zz.rst'); $tok2 = $linkFor('zz.rst@example.test');
    $pdo->exec("UPDATE password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = '" . hash('sha256', $tok2) . "'");
    check(str_contains(http('GET', "$base/auth/reset.php?token=$tok2")['body'], 'This link does not work'), 'a link older than one hour no longer works');
    $r = $rp($tok2, $newPw, $newPw);
    check(!str_contains($r['headers'] ?? '', 'auth/login.php?reset=1'), 'and an expired link can not set a password');
    // two simultaneous uses: the claim is atomic
    $forgot('zz.rst'); $tok3 = $linkFor('zz.rst@example.test');
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', $tok3)]);
    check(str_contains(http('GET', "$base/auth/reset.php?token=$tok3")['body'], 'This link does not work'), 'a link marked as used is refused');
    // limits
    $pdo->exec("DELETE FROM password_resets WHERE admin_id = $rid");
    for ($i = 0; $i < 5; $i++) { $forgot('zz.rst'); }
    same((int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE admin_id = $rid")->fetchColumn(), 3, 'one account can have at most 3 reset requests per hour; more are ignored silently');
    fresh_window();
    $pdo->exec('DELETE FROM rate_limits');
    $codes = [];
    for ($i = 0; $i < 7; $i++) { $g = http('GET', "$base/auth/forgot.php", ['jar' => ($jj = $newJar())]); $codes[] = http('POST', "$base/auth/forgot.php", ['jar' => $jj, 'body' => http_build_query(['csrf' => csrf_from($g['body']), 'who' => 'nobody'])])['code']; }
    check(count(array_filter($codes, fn($c) => $c === 429)) >= 2 && $codes[0] === 200, 'requests for reset links are rate limited per address (429 after 5 a minute)', json_encode(array_count_values($codes)));
    $pdo->exec('DELETE FROM rate_limits');
    // the link address is never taken from the request
    $pdo->exec('DELETE FROM mail_settings');
    $_SERVER['HTTP_HOST'] = 'evil.example'; $_SERVER['SCRIPT_NAME'] = '/x/forgot.php';
    same(mail_base_url($pdo), null, 'on a real server with no site address set, no link address is guessed from the request (a forged Host header gets nothing)');
    $pdo->exec("INSERT INTO mail_settings (id, base_url) VALUES (1, 'https://admin.example.test/app/')");
    same(mail_base_url($pdo), 'https://admin.example.test/app', 'the saved site address wins over whatever the request says, trailing slash removed');
    unset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME']);
    $pdo->exec("DELETE FROM password_resets WHERE admin_id = $rid");
    $forgot('zz.rst'); $b = (string) $pdo->query("SELECT body FROM mail_outbox WHERE kind = 'reset' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check(str_contains($b, 'https://admin.example.test/app/auth/reset.php?token='), 'a reset email links to the saved site address');
    $pdo->exec('DELETE FROM mail_settings');

    // =========================================================
    section('25. Invitations');
    $pdo->exec('DELETE FROM rate_limits');
    $inv = fn(array $d) => $post($admin, 'settings/settings.php?tab=team', ['tab' => 'team', 'action' => 'invite'] + $d, 'settings/settings.php?tab=team');
    $base1 = ['name' => 'Invited Person', 'username' => 'zz.inv1', 'email' => 'zz.inv1@example.test', 'role' => 'user', 'perm' => 'view'];
    $ilink = function (string $email) use ($pdo) { $b = (string) $pdo->query('SELECT body FROM mail_outbox WHERE to_email = ' . $pdo->quote($email) . " AND kind = 'invite' ORDER BY id DESC LIMIT 1")->fetchColumn(); return preg_match('/invite\.php\?token=([a-f0-9]{64})/', $b, $m) ? $m[1] : ''; };
    $team = http('GET', "$base/settings/settings.php?tab=team", ['jar' => $admin])['body'];
    check(str_contains($team, 'Invite by email') && str_contains($team, 'name="action" value="invite"'), 'Settings > Team has an "Invite by email" form');
    same($post($user, 'settings/settings.php?tab=team', ['tab' => 'team', 'action' => 'invite'] + $base1, 'settings/settings.php')['code'], 403, 'a normal account can not invite (403)');
    same((int) $pdo->query("SELECT COUNT(*) FROM invitations WHERE username = 'zz.inv1'")->fetchColumn(), 0, 'and nothing was created');
    foreach ([['username needs 3 to 30', ['username' => 'a!']], ['valid email', ['email' => 'nope']], ['Enter the person', ['name' => '']], ['already in use', ['username' => 'admin']], ['already in use', ['email' => 'admin@telecomlijn.test']]] as [$msg, $bad]) {
        $inv($bad + $base1);
        same((int) $pdo->query("SELECT COUNT(*) FROM invitations WHERE email LIKE 'zz.inv%'")->fetchColumn(), 0, 'validation refuses: ' . $msg);
    }
    $inv($base1);
    $iv = $pdo->query("SELECT * FROM invitations WHERE username = 'zz.inv1'")->fetch();
    check($iv && $iv['email'] === 'zz.inv1@example.test' && $iv['role'] === 'user' && $iv['perm_integrations'] === 'view' && $iv['invited_by'] === 'admin' && $iv['used_at'] === null, 'a valid invitation is stored with its role, permission and who invited');
    $itok = $ilink('zz.inv1@example.test');
    check(strlen($itok) === 64 && $iv['token_hash'] === hash('sha256', $itok) && !str_contains(json_encode($iv), $itok), 'the email holds a 64-character token and only its hash is stored');
    $days = (int) $pdo->query("SELECT TIMESTAMPDIFF(HOUR, NOW(), expires_at) FROM invitations WHERE id = " . (int) $iv['id'])->fetchColumn();
    check($days >= 70 && $days <= 72, 'it is valid for three days', (string) $days);
    $ob = (string) $pdo->query("SELECT body FROM mail_outbox WHERE to_email = 'zz.inv1@example.test' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check(str_contains($ob, 'Invited Person') && str_contains($ob, 'zz.inv1') && str_contains($ob, '/invite.php?token='), 'the email greets the person and holds the link');
    check(str_contains(http('GET', "$base/settings/settings.php?tab=team", ['jar' => $admin])['body'], 'Waiting to be accepted') && str_contains(http('GET', "$base/settings/settings.php?tab=team", ['jar' => $admin])['body'], 'zz.inv1@example.test'), 'the pending invitation is listed with a Withdraw button');
    $b = http('GET', "$base/auth/invite.php?token=$itok")['body'];
    check(str_contains($b, 'Join Telecomlijn Admin') && str_contains($b, 'zz.inv1') && str_contains($b, 'Invited Person'), 'the link opens the sign-up form');
    $badInv = [];
    foreach (['', 'abc', strrev($itok), substr($itok, 0, 63), strtoupper($itok), "$itok' OR 1=1"] as $badTok) {
        if (!str_contains(http('GET', "$base/auth/invite.php?token=" . urlencode($badTok))['body'], 'This invitation does not work')) { $badInv[] = $badTok; }
    }
    check(!$badInv, 'tampered, truncated and SQL-looking invitation tokens show "This invitation does not work"', json_encode($badInv));
    $pdo->exec("DELETE FROM login_attempts WHERE username = 'tok:link'");
    $acc = function (string $t, string $new, string $conf, ?string $jar = null) use ($base, $pdo, $newJar) {
        $pdo->exec('DELETE FROM rate_limits'); $pdo->exec("DELETE FROM login_attempts WHERE username = 'tok:link'");
        $jar ??= $newJar();
        $g = http('GET', "$base/auth/invite.php?token=$t", ['jar' => $jar]);
        return [http('POST', "$base/auth/invite.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($g['body']), 'token' => $t, 'new' => $new, 'confirm' => $conf])]), $jar];
    };
    same(http('POST', "$base/auth/invite.php", ['body' => http_build_query(['token' => $itok, 'new' => $newPw, 'confirm' => $newPw])])['code'], 403, 'accepting without a CSRF token is 403');
    [$r] = $acc($itok, 'short', 'short');
    check($r['code'] === 200 && str_contains($r['body'], 'between 12 and 256') && (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE username = 'zz.inv1'")->fetchColumn() === 0, 'a short password is refused and no account is made');
    [$r] = $acc($itok, $newPw, 'different');
    check($r['code'] === 200 && str_contains($r['body'], 'do not match') && (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE username = 'zz.inv1'")->fetchColumn() === 0, 'a mismatching confirmation is refused');
    [$r, $ji] = $acc($itok, $newPw, $newPw);
    check($r['code'] === 302 && str_contains($r['headers'], 'settings/settings.php?tab=account'), 'accepting creates the account, signs the person in and sends them to the Account tab (to add two-step if they like)');
    $a = $pdo->query("SELECT * FROM admins WHERE username = 'zz.inv1'")->fetch();
    check($a && $a['role'] === 'user' && $a['perm_integrations'] === 'view' && $a['email'] === 'zz.inv1@example.test' && $a['name'] === 'Invited Person' && (int) $a['must_change_password'] === 0 && password_verify($newPw, $a['password_hash']), 'the account has the invited role and permission and the chosen password, with no forced password change');
    check($signedIn($ji) && str_contains(http('GET', "$base/settings/settings.php?tab=account", ['jar' => $ji])['body'], 'Set up two-step sign-in'), 'they are signed in and can turn on two-step from the Account tab');
    check($pwStep($newJar(), 'zz.inv1', $newPw)['code'] === 302, 'they can sign in again later with the password');
    check(str_contains(http('GET', "$base/auth/invite.php?token=$itok")['body'], 'This invitation does not work'), 'the invitation can not be used a second time');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Invitation accepted' AND target = 'zz.inv1'")->fetchColumn() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Invitation sent' AND target LIKE 'zz.inv1%'")->fetchColumn() === 1, 'sending and accepting are in the activity log');
    // replace, withdraw, expire, admin role, race with an existing account
    $b2 = ['name' => 'Second Person', 'username' => 'zz.inv2', 'email' => 'zz.inv2@example.test', 'role' => 'user', 'perm' => 'none'];
    $inv($b2); $t1 = $ilink('zz.inv2@example.test');
    $inv($b2); $t2 = $ilink('zz.inv2@example.test');
    check($t1 !== $t2 && str_contains(http('GET', "$base/auth/invite.php?token=$t1")['body'], 'This invitation does not work') && str_contains(http('GET', "$base/auth/invite.php?token=$t2")['body'], 'Join Telecomlijn Admin'), 'a new invitation for the same person replaces the old one: the old link stops working');
    same((int) $pdo->query("SELECT COUNT(*) FROM invitations WHERE email = 'zz.inv2@example.test'")->fetchColumn(), 1, 'only one open invitation per person');
    $ivid = (int) $pdo->query("SELECT id FROM invitations WHERE email = 'zz.inv2@example.test'")->fetchColumn();
    same($post($user, 'settings/settings.php?tab=team', ['tab' => 'team', 'action' => 'revoke_invite', 'id' => (string) $ivid], 'settings/settings.php')['code'], 403, 'a normal account can not withdraw an invitation (403)');
    $post($admin, 'settings/settings.php?tab=team', ['tab' => 'team', 'action' => 'revoke_invite', 'id' => (string) $ivid], 'settings/settings.php?tab=team');
    check(str_contains(http('GET', "$base/auth/invite.php?token=$t2")['body'], 'This invitation does not work'), 'a withdrawn invitation no longer works');
    $b3 = ['name' => 'Third Person', 'username' => 'zz.inv3', 'email' => 'zz.inv3@example.test', 'role' => 'admin', 'perm' => 'none'];
    $inv($b3); $t3 = $ilink('zz.inv3@example.test');
    $pdo->exec("UPDATE invitations SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE username = 'zz.inv3'");
    check(str_contains(http('GET', "$base/auth/invite.php?token=$t3")['body'], 'This invitation does not work'), 'an expired invitation no longer works');
    [$r] = $acc($t3, $newPw, $newPw);
    same((int) $pdo->query("SELECT COUNT(*) FROM admins WHERE username = 'zz.inv3'")->fetchColumn(), 0, 'and an expired invitation can not create an account');
    $inv($b3); $t3 = $ilink('zz.inv3@example.test');
    [$r] = $acc($t3, $newPw, $newPw);
    $a3 = $pdo->query("SELECT role, perm_integrations FROM admins WHERE username = 'zz.inv3'")->fetch();
    check($a3 && $a3['role'] === 'admin' && $a3['perm_integrations'] === 'manage', 'an invited admin gets the admin role and full integrations access');
    $b4 = ['name' => 'Fourth Person', 'username' => 'zz.inv4', 'email' => 'zz.inv4@example.test', 'role' => 'user', 'perm' => 'none'];
    $inv($b4); $t4 = $ilink('zz.inv4@example.test');
    $pdo->prepare("INSERT INTO admins (username, email, name, password_hash, role, perm_integrations, must_change_password) VALUES ('zz.inv4', 'other@example.test', 'Taken', ?, 'user', 'none', 0)")->execute([hash_password($oldPw)]);
    [$r] = $acc($t4, $newPw, $newPw);
    check($r['code'] === 200 && str_contains($r['body'], 'already exists') && (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE username = 'zz.inv4'")->fetchColumn() === 1, 'if the username was taken in the meantime, accepting is refused and no second account appears');
    // XSS in an invitation name
    $inv(['name' => '<script>alert(1)</script>', 'username' => 'zz.inv5', 'email' => 'zz.inv5@example.test'] + $b2);
    $xs = http('GET', "$base/settings/settings.php?tab=team", ['jar' => $admin])['body'] . http('GET', "$base/auth/invite.php?token=" . $ilink('zz.inv5@example.test'))['body'];
    check(!str_contains($xs, '<script>alert(1)') && str_contains($xs, '&lt;script&gt;'), 'markup in an invited name is escaped on the Team tab and on the invitation page');
    fresh_window();
    $pdo->exec('DELETE FROM rate_limits');
    $codes = [];
    for ($i = 0; $i < 12; $i++) { $jj = $newJar(); $g = http('GET', "$base/auth/invite.php?token=$itok", ['jar' => $jj]); $codes[] = http('POST', "$base/auth/invite.php", ['jar' => $jj, 'body' => http_build_query(['csrf' => csrf_from($g['body']), 'token' => $itok, 'new' => 'x', 'confirm' => 'x'])])['code']; }
    check(count(array_filter($codes, fn($c) => $c === 429)) >= 1, 'guessing at invitation links is rate limited (429)', json_encode(array_count_values($codes)));
    // too many wrong links lock link checking for that address
    $pdo->exec("DELETE FROM login_attempts WHERE username = 'tok:link'"); $pdo->exec('DELETE FROM rate_limits');
    for ($i = 0; $i < 10; $i++) { http('GET', "$base/auth/reset.php?token=" . bin2hex(random_bytes(32))); }
    $b = http('GET', "$base/auth/reset.php?token=" . bin2hex(random_bytes(32)))['body'];
    check(str_contains($b, 'Too many wrong links') && (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE username = 'tok:link'")->fetchColumn() === 10, 'ten wrong reset links in a row lock link checking for that address (and the counter stops growing)');
    $pdo->exec("DELETE FROM password_resets WHERE admin_id = $rid"); $pdo->exec("DELETE FROM login_attempts WHERE username = 'tok:link'");
    $forgot('zz.rst'); $good = $linkFor('zz.rst@example.test');
    for ($i = 0; $i < 10; $i++) { http('GET', "$base/auth/invite.php?token=" . bin2hex(random_bytes(32))); }
    check(str_contains(http('GET', "$base/auth/reset.php?token=$good")['body'], 'Too many wrong links') && str_contains(http('GET', "$base/auth/invite.php?token=" . bin2hex(random_bytes(32)))['body'], 'Too many wrong links'), 'the lock covers reset and invitation links together, and even a correct link is refused while it lasts');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Link guessing locked'")->fetchColumn() >= 1, 'a lock is written to the activity log');
    $pdo->exec("DELETE FROM login_attempts WHERE username = 'tok:link'"); $pdo->exec("DELETE FROM activity_logs WHERE action = 'Link guessing locked' AND is_demo = 0");
    check(str_contains(http('GET', "$base/auth/reset.php?token=$good")['body'], 'name="confirm"'), 'after the lock is cleared a correct link works again');
    } finally {
        $cleanMail();
        if (is_resource($smtpProc)) { proc_terminate($smtpProc); proc_close($smtpProc); }
        @unlink($smtpLog); @unlink($smtpMode);
    }
