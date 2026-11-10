<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Follow-up round: adding users, editing notes, mark-read on click, per-person notification emails, required two-step,
// session end on password change, themed error pages, recurring preview, log export and clearing.
// Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.

$clean13 = function () use ($pdo) {
    $pdo->exec("DELETE FROM users WHERE email LIKE 'zz.f13%@example.test'");
    $pdo->exec("DELETE FROM notes WHERE body LIKE 'ZZF13%'");
    $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZF13%'");
    $pdo->exec("DELETE FROM notifications WHERE title LIKE '%ZZF13%' OR dedupe_key LIKE 'zzf13%'");
    $pdo->exec("DELETE FROM admins WHERE username = 'zz.ep'");
    $pdo->exec("DELETE FROM activity_logs WHERE action = 'ZZF13 old' OR (is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND action IN ('User added','Note edited','Note deleted','Two-step requirement changed','Notification emails preference','Activity log cleared','Link guessing locked'))");
    $pdo->exec("DELETE FROM recurring_invoices WHERE description LIKE 'ZZF13%'");
    $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZF13%'");
    $pdo->exec("DELETE FROM mail_outbox WHERE subject LIKE '%ZZF13%'");
    $pdo->exec("UPDATE admins SET notify_optin = 0");
    $pdo->exec("INSERT INTO app_meta (k, v) VALUES ('require_2fa_admins', '0') ON DUPLICATE KEY UPDATE v = '0'");
    $pdo->exec('DELETE FROM rate_limits');
};
$clean13();
$pdo->exec('DELETE FROM rate_limits');

section('37. Follow-ups: adding users');
$pdo->exec("INSERT INTO companies (name) VALUES ('ZZF13 Co')");
$fco = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZF13 Co'")->fetchColumn();
same(http('GET', "$base/user_new.php")['code'], 302, 'signed out: user_new.php redirects to the login page');
same(http('POST', "$base/user_new.php", ['jar' => $admin, 'body' => 'name=x&email=zz.f13x@example.test'])['code'], 403, 'a new user without a CSRF token is 403');
check(str_contains(http('GET', "$base/users.php", ['jar' => $admin])['body'], 'user_new.php') && str_contains(http('GET', "$base/company.php?id=$fco", ['jar' => $admin])['body'], "user_new.php?company=$fco"), 'the users list and each company page have an Add user button');
check(str_contains(http('GET', "$base/user_new.php?company=$fco", ['jar' => $admin])['body'], "value=\"$fco\" selected"), 'user_new.php?company= preselects the company');
$nu = ['name' => 'ZZF13 Person', 'email' => 'ZZ.F13A@Example.test', 'role' => 'support', 'status' => 'pending', 'country' => 'Belgium', 'company_id' => (string) $fco, 'job_title' => 'Tester', 'phone' => '010 5'];
foreach ([['Enter a name', ['name' => '']], ['valid email', ['email' => 'nope']], ['Choose a role', ['role' => 'god']], ['Choose a status', ['status' => 'gone']], ['company does not exist', ['company_id' => '999999']], ['country', ['country' => '']], ['at most 80', ['job_title' => str_repeat('j', 81)]]] as [$msg, $bad]) {
    $r = $post($admin, 'user_new.php', $bad + $nu);
    check($r['code'] === 200 && str_contains($r['body'], $msg), 'validation: ' . $msg);
}
same((int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'zz.f13%'")->fetchColumn(), 0, 'nothing is saved while validation fails');
$r = $post($admin, 'user_new.php', $nu);
$nuRow = $pdo->query("SELECT * FROM users WHERE email = 'zz.f13a@example.test'")->fetch();
check($r['code'] === 302 && $nuRow && $nuRow['role'] === 'support' && $nuRow['status'] === 'pending' && (int) $nuRow['company_id'] === $fco && (int) $nuRow['logins'] === 0 && (int) $nuRow['is_demo'] === 0, 'a valid user is saved (email in lower case), linked to the company, as real data (not demo)');
check(str_contains($r['headers'], 'user.php?id=' . $nuRow['id']), 'and the browser goes to their page');
$r = $post($admin, 'user_new.php', $nu);
check($r['code'] === 200 && str_contains($r['body'], 'already exists'), 'a second user with the same email is refused');
$xs = $post($admin, 'user_new.php', ['name' => 'ZZF13 <script>x</script>', 'email' => 'zz.f13b@example.test'] + $nu);
check(!str_contains(http('GET', "$base/users.php?q=ZZF13", ['jar' => $admin])['body'], '<script>x') && str_contains(http('GET', "$base/users.php?q=ZZF13", ['jar' => $admin])['body'], '&lt;script&gt;'), 'markup in a name is escaped');

section('38. Follow-ups: editing and deleting notes');
$uid = (int) $nuRow['id'];
$post($admin, 'user.php', ['id' => (string) $uid, 'action' => 'note', 'kind' => 'note', 'body' => 'ZZF13 by admin'], "user.php?id=$uid");
$post($user, 'user.php', ['id' => (string) $uid, 'action' => 'note', 'kind' => 'call', 'body' => 'ZZF13 by tester'], "user.php?id=$uid");
$nAdmin = (int) $pdo->query("SELECT id FROM notes WHERE body = 'ZZF13 by admin'")->fetchColumn();
$nTest = (int) $pdo->query("SELECT id FROM notes WHERE body = 'ZZF13 by tester'")->fetchColumn();
$page = http('GET', "$base/user.php?id=$uid", ['jar' => $admin])['body'];
check(substr_count($page, 'Delete this note') >= 2 && str_contains($page, '>Edit<'), 'an admin sees Edit (with Delete inside it) on every note');
$pu = http('GET', "$base/user.php?id=$uid", ['jar' => $user])['body'];
check(substr_count($pu, 'Delete this note') === 1, 'a normal account sees it only on its own note');
$ed = fn($jar, int $nid, array $d) => $post($jar, 'user.php', ['id' => (string) $uid, 'action' => 'note_edit', 'note_id' => (string) $nid] + $d, "user.php?id=$uid");
same($ed($user, $nAdmin, ['kind' => 'note', 'body' => 'ZZF13 hijacked'])['code'], 403, 'a normal account can not edit someone else\'s note (403)');
same($pdo->query("SELECT body FROM notes WHERE id = $nAdmin")->fetchColumn(), 'ZZF13 by admin', 'and it is unchanged');
$ed($user, $nTest, ['kind' => 'email', 'body' => "ZZF13 tester <b>edited</b>\nline2"]);
$row = $pdo->query("SELECT * FROM notes WHERE id = $nTest")->fetch();
check($row['kind'] === 'email' && str_contains($row['body'], 'edited') && $row['edited_at'] !== null && $row['author'] === 'kuma.tester', 'an author can edit their own note: text and kind change, the author stays, edited time is set');
$pg = http('GET', "$base/user.php?id=$uid", ['jar' => $admin])['body'];
check(str_contains($pg, '(edited)') && str_contains($pg, '&lt;b&gt;edited&lt;/b&gt;') && !str_contains($pg, '<b>edited</b>'), 'the timeline marks it as edited and escapes the text');
$ed($admin, $nTest, ['kind' => 'note', 'body' => 'ZZF13 admin fixed it']);
same($pdo->query("SELECT body FROM notes WHERE id = $nTest")->fetchColumn(), 'ZZF13 admin fixed it', 'an admin can edit anyone\'s note');
$ed($admin, $nTest, ['kind' => 'note', 'body' => '   ']);
$ed($admin, $nTest, ['kind' => 'bogus', 'body' => 'ZZF13 x']);
same($pdo->query("SELECT body FROM notes WHERE id = $nTest")->fetchColumn(), 'ZZF13 admin fixed it', 'an empty text or an unknown kind is refused');
same($ed($admin, 999999, ['kind' => 'note', 'body' => 'ZZF13 ghost'])['code'], 302, 'editing a note that does not exist is handled calmly');
same($post($admin, 'company.php', ['id' => (string) $fco, 'action' => 'note_edit', 'note_id' => (string) $nTest, 'kind' => 'note', 'body' => 'ZZF13 wrong page'], "company.php?id=$fco")['code'], 302, 'a note can not be edited through another record\'s page');
same($pdo->query("SELECT body FROM notes WHERE id = $nTest")->fetchColumn(), 'ZZF13 admin fixed it', 'and it stayed as it was');
$dl = fn($jar, int $nid) => $post($jar, 'user.php', ['id' => (string) $uid, 'action' => 'note_delete', 'note_id' => (string) $nid], "user.php?id=$uid");
same($dl($user, $nAdmin)['code'], 403, 'a normal account can not delete someone else\'s note (403)');
same((int) $pdo->query("SELECT COUNT(*) FROM notes WHERE id = $nAdmin")->fetchColumn(), 1, 'and it is still there');
$dl($user, $nTest);
check((int) $pdo->query("SELECT COUNT(*) FROM notes WHERE id = $nTest")->fetchColumn() === 0, 'an author can delete their own note, even after an admin edited it (the author stays the same)');
$dl($admin, $nAdmin);
same((int) $pdo->query("SELECT COUNT(*) FROM notes WHERE id = $nAdmin")->fetchColumn(), 0, 'an admin can delete a note');
check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('Note edited','Note deleted')")->fetchColumn() >= 3, 'edits and deletions are in the activity log');
same(http('POST', "$base/user.php", ['jar' => $admin, 'body' => "id=$uid&action=note_delete&note_id=$nTest"])['code'], 403, 'deleting without a CSRF token is 403');

section('39. Follow-ups: notifications');
$pdo->prepare("INSERT INTO notifications (kind, dedupe_key, title, link, created_at) VALUES ('ticket_urgent', 'zzf13-ok', 'ZZF13 ok', 'ticket.php?id=1', NOW()), ('ticket_urgent', 'zzf13-evil', 'ZZF13 evil', '//evil.example/x', NOW()), ('ticket_urgent', 'zzf13-js', 'ZZF13 js', 'javascript:alert(1)', NOW())")->execute();
$nid = fn(string $k) => (int) $pdo->query("SELECT id FROM notifications WHERE dedupe_key = '$k'")->fetchColumn();
check(str_contains(http('GET', "$base/notifications.php", ['jar' => $admin])['body'], 'notifications.php?go=' . $nid('zzf13-ok')), 'the list opens items through ?go=');
$r = http('GET', "$base/notifications.php?go=" . $nid('zzf13-ok'), ['jar' => $admin]);
check($r['code'] === 302 && str_contains($r['headers'], 'Location: ticket.php?id=1') && $pdo->query('SELECT read_at IS NOT NULL FROM notifications WHERE id = ' . $nid('zzf13-ok'))->fetchColumn() == 1, 'clicking a notification marks it read and goes to its page');
$r = http('GET', "$base/notifications.php?go=" . $nid('zzf13-evil'), ['jar' => $admin]);
check(str_contains($r['headers'], 'Location: notifications.php') && !str_contains($r['headers'], 'evil'), 'an off-site link is never followed');
check(str_contains(http('GET', "$base/notifications.php?go=" . $nid('zzf13-js'), ['jar' => $admin])['headers'], 'Location: notifications.php'), 'nor a javascript: link');
check(str_contains(http('GET', "$base/notifications.php?go=999999", ['jar' => $admin])['headers'], 'Location: notifications.php') && http('GET', "$base/notifications.php?go=1")['code'] === 302, 'an unknown id goes back to the list; signed out goes to the login page');
check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'notifications.php?go='), 'the bell links also mark as read');
// per-person emails
$pdo->exec("INSERT INTO app_meta (k, v) VALUES ('notify_email', 'shared@example.test') ON DUPLICATE KEY UPDATE v = VALUES(v)");
$opt = fn($jar, string $on) => $post($jar, 'settings.php?tab=account', ['tab' => 'account', 'action' => 'optin', 'optin' => $on], 'settings.php?tab=account');
check(str_contains(http('GET', "$base/settings.php?tab=account", ['jar' => $admin])['body'], 'Email me notifications'), 'Settings > Account offers "Email me notifications"');
notifications_refresh($pdo, true); $pdo->exec("UPDATE notifications SET emailed_at = NOW()");
$count = fn(string $to) => (int) $pdo->query("SELECT COUNT(*) FROM mail_outbox WHERE kind = 'notification' AND to_email = " . $pdo->quote($to) . " AND subject LIKE '%ZZF13%'")->fetchColumn();
$pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZF13-T1', 'ZZF13 first', 'x', 'urgent', 'open', NOW(), NOW())");
notifications_refresh($pdo, true);
same([$count('shared@example.test'), $count('admin@telecomlijn.test')], [1, 0], 'by default only the shared address is mailed');
$opt($admin, '1');
same((int) $pdo->query("SELECT notify_optin FROM admins WHERE username = 'admin'")->fetchColumn(), 1, 'a person can opt in');
$pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZF13-T2', 'ZZF13 second', 'x', 'urgent', 'open', NOW(), NOW())");
notifications_refresh($pdo, true);
same([$count('shared@example.test'), $count('admin@telecomlijn.test')], [2, 1], 'after opting in they get the email at their own address as well as the shared one');
$pdo->exec("INSERT INTO app_meta (k, v) VALUES ('notify_email', '') ON DUPLICATE KEY UPDATE v = ''");
$pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZF13-T3', 'ZZF13 third', 'x', 'urgent', 'open', NOW(), NOW())");
notifications_refresh($pdo, true);
same([$count('shared@example.test'), $count('admin@telecomlijn.test')], [2, 2], 'with the shared address emptied, opted-in people still get their copy');
$opt($admin, '0');
same((int) $pdo->query("SELECT notify_optin FROM admins WHERE username = 'admin'")->fetchColumn(), 0, 'and they can opt out again');
check(str_contains((string) file_get_contents(TESTS_DIR . '/../includes/settings/account.php'), 'aria-labelledby="op-h"'), 'the preference is a labelled section on the Account tab');
$pdo->exec("DELETE FROM app_meta WHERE k = 'notify_email'");
if ($notifyKeep ?? false) { $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('notify_email', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$notifyKeep]); }

section('40. Follow-ups: two-step required, password change, error pages');
$req = fn(string $on) => $post($admin, 'settings.php?tab=account', ['tab' => 'account', 'action' => 'require2fa', 'require' => $on], 'settings.php?tab=account');
same($post($user, 'settings.php?tab=account', ['tab' => 'account', 'action' => 'require2fa', 'require' => '1'], 'settings.php?tab=account')['code'], 403, 'a normal account can not switch on the requirement (403)');
same((string) $pdo->query("SELECT COALESCE(MAX(v), '0') FROM app_meta WHERE k = 'require_2fa_admins'")->fetchColumn(), '0', 'and nothing changed');
check(str_contains(http('GET', "$base/settings.php?tab=account", ['jar' => $admin])['body'], 'Require two-step for admins'), 'Settings > Account has the "Require two-step for admins" setting');
check(http('GET', "$base/index.php", ['jar' => $admin])['code'] === 200, 'while it is off an admin without two-step uses everything');
$req('1');
same((string) $pdo->query("SELECT v FROM app_meta WHERE k = 'require_2fa_admins'")->fetchColumn(), '1', 'an admin can require two-step for admins');
try {
    $r = http('GET', "$base/index.php", ['jar' => $admin]);
    check($r['code'] === 302 && str_contains($r['headers'], 'settings.php?tab=account'), 'once required, an admin without it is sent to set it up');
    same(http('GET', "$base/users.php", ['jar' => $admin])['code'], 302, 'other pages are closed to them too');
    same(http('GET', "$base/settings.php?tab=email", ['jar' => $admin])['code'], 302, 'including the other settings tabs');
    same(http('GET', "$base/settings.php?tab=account", ['jar' => $admin])['code'], 200, 'but the Account tab (where two-step is set up) opens');
    same(http('GET', "$base/index.php", ['jar' => $user])['code'], 200, 'accounts that are not admins are not affected');
} finally {
    $req('0');   // the Account tab stays reachable, so it can always be switched off again
}
same((string) $pdo->query("SELECT v FROM app_meta WHERE k = 'require_2fa_admins'")->fetchColumn(), '0', 'it can be made optional again from the Account tab');
same(http('GET', "$base/index.php", ['jar' => $admin])['code'], 200, 'and the admin has everything back');
// own password change ends the other sessions
$pdo->prepare("INSERT INTO admins (username, email, name, password_hash, role, perm_integrations, must_change_password) VALUES ('zz.ep', 'zz.ep@example.test', 'Ep Tester', ?, 'user', 'none', 0)")->execute([hash_password('Old-Password-12345')]);
$jA = $newJar(); $jB = $newJar();
$pwStep($jA, 'zz.ep', 'Old-Password-12345'); $pwStep($jB, 'zz.ep', 'Old-Password-12345');
check($signedIn($jA) && $signedIn($jB), 'one account is signed in on two devices');
$post($jA, 'settings.php?tab=account', ['tab' => 'account', 'current' => 'Old-Password-12345', 'new' => 'New-Password-67890!', 'confirm' => 'New-Password-67890!'], 'settings.php?tab=account');
check(password_verify('New-Password-67890!', (string) $pdo->query("SELECT password_hash FROM admins WHERE username = 'zz.ep'")->fetchColumn()), 'the password was changed on the first device');
check($signedIn($jA), 'that device stays signed in');
check(!$signedIn($jB), 'the other device is signed out at its next click');
// the bare error pages follow the system setting
$pp = plain_page('T', 'Heading', 'Text', '<p><a href="x">Link</a></p>');
check(str_contains($pp, 'prefers-color-scheme: light') && str_contains($pp, '#F4F6FB') && str_contains($pp, '<style nonce="'), 'the no-access and too-many-requests pages follow the system light/dark setting');
check(!str_contains((string) file_get_contents(TESTS_DIR . '/../includes/security.php'), "<body style=\"margin:0;min-height:100vh;display:grid;place-items:center;background:#0A1226"), 'no page in security.php still hard-codes the dark body');
check(str_contains(http('GET', "$base/settings.php?tab=team", ['jar' => $user])['body'], 'prefers-color-scheme: light'), 'a real 403 uses it');

section('41. Follow-ups: recurring preview, logs, search shortcut');
$pdo->prepare("INSERT INTO recurring_invoices (company_id, description, amount_cents, vat_rate, frequency, next_date, anchor_day, terms_days, active) VALUES (?, 'ZZF13 monthly', 1000, 21, 'month', '2031-01-31', 31, 14, 1)")->execute([$fco]);
$rp = http('GET', "$base/recurring.php", ['jar' => $admin])['body'];
check(str_contains($rp, '31 Jan 2031') && str_contains($rp, 'then 28 Feb, 31 Mar 2031'), 'a schedule shows its next three invoice dates (with the month-end rule: 31 Jan, 28 Feb, 31 Mar)');
$pdo->exec("DELETE FROM recurring_invoices WHERE description LIKE 'ZZF13%'");
same(http('GET', "$base/export.php?type=logs")['code'], 302, 'signed out: the log export redirects');
same(http('GET', "$base/export.php?type=logs", ['jar' => $user])['code'], 403, 'a normal account gets 403 on the log export');
$lg = http('GET', "$base/export.php?type=logs", ['jar' => $admin]);
check($lg['code'] === 200 && str_contains($lg['headers'], 'logs-' . date('Ymd') . '.csv') && str_contains($lg['body'], "time,level,actor,action,target,ip"), 'an admin can export the activity log as CSV');
$pdo->exec("INSERT INTO activity_logs (level, actor, action, target, ip, created_at) VALUES ('info', 'zz', 'ZZF13 old', 'old', '::1', NOW() - INTERVAL 400 DAY), ('info', 'zz', 'ZZF13 old', 'mid', '::1', NOW() - INTERVAL 100 DAY), ('info', 'zz', 'ZZF13 old', 'new', '::1', NOW() - INTERVAL 2 DAY)");
$clr = fn($jar, array $d) => $post($jar, 'logs.php', $d, 'logs.php');
check(str_contains(http('GET', "$base/logs.php", ['jar' => $admin])['body'], 'Clear older entries') && !str_contains(http('GET', "$base/logs.php", ['jar' => $user])['body'], 'Clear older entries'), 'the clear button is shown to admins only');
same($clr($user, ['days' => '365', 'confirm' => 'yes'])['code'], 403, 'a normal account can not clear the log (403)');
$has = fn(string $t) => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'ZZF13 old' AND target = '$t'")->fetchColumn();   // the 400-day row may also be removed by the automatic 12-month purge at any time
$clr($admin, ['days' => '365']);
$clr($admin, ['days' => '7', 'confirm' => 'yes']);
same([$has('mid'), $has('new')], [1, 1], 'without the confirmation, or with a made-up number of days, nothing is removed');
$clr($admin, ['days' => '365', 'confirm' => 'yes']);
same([$has('old'), $has('mid'), $has('new')], [0, 1, 1], 'clearing "older than 1 year" removes the 400-day-old entry and keeps the newer ones');
$clr($admin, ['days' => '90', 'confirm' => 'yes']);
same([$has('mid'), $has('new')], [0, 1], 'older than 90 days also removes the 100-day-old one and keeps the recent one');
check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Activity log cleared'")->fetchColumn() >= 2, 'the clearing itself is logged and stays');
check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'aria-keyshortcuts="/"') && str_contains((string) file_get_contents(TESTS_DIR . '/../includes/layout.php'), "e.key === '/'"), 'pressing / focuses the search box (announced as a keyboard shortcut)');
check(is_file(TESTS_DIR . '/../.githooks/pre-commit') && str_contains((string) file_get_contents(TESTS_DIR . '/../.githooks/pre-commit'), 'tests/run.php'), 'an optional pre-commit hook that runs this suite is included (git config core.hooksPath .githooks)');
$clean13();
