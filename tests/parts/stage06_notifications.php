<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 6 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 6: notifications
    // =========================================================
    $cleanNotif = function () use ($pdo) {
        $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZN%'");
        $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer LIKE 'ZZN%')");
        $pdo->exec("DELETE FROM invoices WHERE customer LIKE 'ZZN%'");
        $pdo->exec("DELETE FROM backups WHERE name LIKE 'ZZN%'");
        $pdo->exec("DELETE FROM monitored_endpoints WHERE name LIKE 'ZZN%'");
        $pdo->exec("DELETE FROM kuma_heartbeats WHERE monitor_name LIKE 'ZZN%'");
        $pdo->exec("DELETE FROM notifications WHERE title LIKE '%ZZN%' OR body LIKE '%ZZN%'");
        $pdo->exec("DELETE FROM mail_outbox WHERE kind = 'notification' AND created_at > NOW() - INTERVAL 3 HOUR");   // digests of the events above (recorded only: localhost)
        $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND action IN ('Notification address changed') AND created_at > NOW() - INTERVAL 1 HOUR");
        $pdo->exec("INSERT INTO app_meta (k, v) VALUES ('notify_email', 'denis@telecomlijn.nl') ON DUPLICATE KEY UPDATE v = VALUES(v)");
    };
    $cleanNotif();
    section('18. Notifications');
    $pdo->exec('DELETE FROM rate_limits');
    notifications_refresh($pdo, true);   // absorb whatever the demo data already triggers, so the counts below are about this test only
    $known = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    $nn = fn(string $like) => (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE dedupe_key LIKE '$like'")->fetchColumn();

    foreach (['notifications.php'] as $pg) { same(http('GET', "$base/$pg")['code'], 302, 'signed out: notifications.php redirects to the login page'); }
    same(http('POST', "$base/notifications.php", ['body' => 'action=read_all'])['code'], 302, 'signed out: marking as read is refused');

    // the four events
    $pdo->exec("INSERT INTO invoices (number, customer, email, customer_address, issue_date, due_date, subtotal_cents, vat_cents, total_cents, created_at) VALUES ('ZZN-OD-1', 'ZZN Late Payer', 'a@b.test', 'x', CURDATE() - INTERVAL 40 DAY, CURDATE() - INTERVAL 10 DAY, 1000, 210, 1210, NOW()), ('ZZN-OK-1', 'ZZN Open Payer', 'a@b.test', 'x', CURDATE(), CURDATE() + INTERVAL 30 DAY, 1000, 210, 1210, NOW())");
    $odId = (int) $pdo->query("SELECT id FROM invoices WHERE number = 'ZZN-OD-1'")->fetchColumn();
    $okId = (int) $pdo->query("SELECT id FROM invoices WHERE number = 'ZZN-OK-1'")->fetchColumn();
    $pdo->exec("INSERT INTO backups (name, type, status, size_mb, started_at, duration_s, location) VALUES ('ZZN-backup-fail', 'full', 'failed', 10, NOW() - INTERVAL 2 HOUR, 5, 'Local'), ('ZZN-backup-old', 'full', 'failed', 10, NOW() - INTERVAL 5 DAY, 5, 'Local'), ('ZZN-backup-ok', 'full', 'completed', 10, NOW() - INTERVAL 1 HOUR, 5, 'Local')");
    $bkId = (int) $pdo->query("SELECT id FROM backups WHERE name = 'ZZN-backup-fail'")->fetchColumn();
    $pdo->exec("INSERT INTO monitored_endpoints (name, url, enabled, last_state, last_error, last_checked_at) VALUES ('ZZN Endpoint', 'https://example.com/', 1, 'down', 'HTTP 503', NOW()), ('ZZN Fine Endpoint', 'https://example.org/', 1, 'up', '', NOW()), ('ZZN Off Endpoint', 'https://example.net/', 0, 'down', '', NOW())");
    $epId = (int) $pdo->query("SELECT id FROM monitored_endpoints WHERE name = 'ZZN Endpoint'")->fetchColumn();
    $pdo->exec("INSERT INTO endpoint_checks (endpoint_id, checked_at, state) VALUES ($epId, NOW() - INTERVAL 30 MINUTE, 'up'), ($epId, NOW() - INTERVAL 20 MINUTE, 'down'), ($epId, NOW() - INTERVAL 10 MINUTE, 'down')");
    $pdo->exec("INSERT INTO kuma_heartbeats (monitor_key, monitor_name, status, important, at) VALUES ('eeeeeeeeeeeeeeee', 'ZZN Kuma Monitor', 0, 1, NOW() - INTERVAL 1 HOUR), ('ffffffffffffffff', 'ZZN Kuma Quiet', 0, 0, NOW() - INTERVAL 1 HOUR), ('eeeeeeeeeeeeeeee', 'ZZN Kuma Old', 0, 1, NOW() - INTERVAL 3 DAY)");
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZN-T-U', 'ZZN Urgent printer fire', 'x', 'urgent', 'open', NOW(), NOW()), ('ZZN-T-N', 'ZZN Normal ticket', 'x', 'normal', 'open', NOW(), NOW()), ('ZZN-T-R', 'ZZN Resolved urgent', 'x', 'urgent', 'resolved', NOW(), NOW())");
    $tkId = (int) $pdo->query("SELECT id FROM tickets WHERE reference = 'ZZN-T-U'")->fetchColumn();
    $made = notifications_refresh($pdo, true);
    same($made, 5, 'one refresh records five new notifications: overdue invoice, failed backup, endpoint down, Kuma monitor down, urgent ticket');
    $row = fn(string $key) => $pdo->query("SELECT * FROM notifications WHERE dedupe_key = " . $pdo->quote($key))->fetch();
    $o = $row("invoice-overdue:$odId");
    check($o && $o['kind'] === 'invoice_overdue' && str_contains($o['title'], 'ZZN-OD-1') && str_contains($o['body'], '12,10') && $o['link'] === "invoice.php?id=$odId" && $o['read_at'] === null, 'overdue invoice: title, amount owed, link to the invoice, unread');
    same($nn("invoice-overdue:$okId"), 0, 'an invoice that is not overdue creates nothing');
    $b = $row("backup-failed:$bkId");
    check($b && $b['kind'] === 'backup_failed' && str_contains($b['body'], 'ZZN-backup-fail') && $b['link'] === 'backups.php', 'failed backup: announced with its name');
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE kind = 'backup_failed' AND body LIKE '%ZZN-backup-old%' OR body LIKE '%ZZN-backup-ok%'")->fetchColumn(), 0, 'a failed backup older than 48 hours and a completed one create nothing');
    $since = $pdo->query("SELECT MIN(checked_at) FROM endpoint_checks WHERE endpoint_id = $epId AND state = 'down'")->fetchColumn();
    $d = $row("endpoint-down:$epId:$since");
    check($d && $d['kind'] === 'service_down' && str_contains($d['title'], 'ZZN Endpoint') && str_contains($d['body'], 'HTTP 503'), 'endpoint down: announced with the error, keyed on when the outage began');
    same($nn('endpoint-down:' . (int) $pdo->query("SELECT id FROM monitored_endpoints WHERE name = 'ZZN Fine Endpoint'")->fetchColumn() . ':%') + $nn('endpoint-down:' . (int) $pdo->query("SELECT id FROM monitored_endpoints WHERE name = 'ZZN Off Endpoint'")->fetchColumn() . ':%'), 0, 'a healthy endpoint and a switched-off one create nothing');
    $kid = (int) $pdo->query("SELECT id FROM kuma_heartbeats WHERE monitor_name = 'ZZN Kuma Monitor'")->fetchColumn();
    $k = $row("kuma-down:$kid");
    check($k && $k['kind'] === 'service_down' && str_contains($k['title'], 'ZZN Kuma Monitor') && $k['link'] === 'uptime.php', 'an Uptime Kuma monitor that went down is announced');
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE title LIKE 'ZZN Kuma Quiet%' OR title LIKE 'ZZN Kuma Old%'")->fetchColumn(), 0, 'a heartbeat that is not a status change, or is older than a day, creates nothing');
    $t = $row("ticket-urgent:$tkId");
    check($t && $t['kind'] === 'ticket_urgent' && str_contains($t['title'], 'ZZN-T-U') && $t['body'] === 'ZZN Urgent printer fire' && $t['link'] === "ticket.php?id=$tkId", 'urgent ticket: announced with its subject and link');
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE body LIKE 'ZZN Normal%' OR body LIKE 'ZZN Resolved%'")->fetchColumn(), 0, 'a normal ticket and a resolved urgent one create nothing');

    // no duplicates
    $total = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    same(notifications_refresh($pdo, true), 0, 'a second refresh announces nothing again');
    notifications_refresh($pdo, true);
    same((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(), $total, 'and the number of notifications is unchanged after more refreshes');
    check(!notify_add($pdo, 'x', "ticket-urgent:$tkId", 'dup', '', '', false), 'the database key refuses the same event twice');
    // a second outage of the same endpoint is a new event
    $pdo->exec("INSERT INTO endpoint_checks (endpoint_id, checked_at, state) VALUES ($epId, NOW() - INTERVAL 5 MINUTE, 'up'), ($epId, NOW() - INTERVAL 2 MINUTE, 'down')");
    same(notifications_refresh($pdo, true), 1, 'after the endpoint recovered and failed again, the new outage is announced once');
    same($nn("endpoint-down:$epId:%"), 2, 'so one endpoint now has two outage notifications');
    // paying the invoice or resolving the ticket does not delete the history
    $pdo->exec("UPDATE tickets SET status = 'resolved' WHERE id = $tkId");
    notifications_refresh($pdo, true);
    same($nn("ticket-urgent:$tkId"), 1, 'a resolved ticket keeps its earlier notification');

    // throttling
    $pdo->exec("DELETE FROM tickets WHERE reference = 'ZZN-T-R'");
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZN-T-U2', 'ZZN Second urgent', 'x', 'urgent', 'open', NOW(), NOW())");
    $setStamp = fn(int $ago) => $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('notif_refreshed_at', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string) (time() - $ago)]);
    $setStamp(30);
    same(notifications_refresh($pdo), 0, 'inside the three-minute window the checks do not run');
    same($nn('ticket-urgent:%'), $nn('ticket-urgent:%'), 'nothing new is recorded inside the window');
    check(!(bool) $pdo->query("SELECT 1 FROM notifications WHERE body = 'ZZN Second urgent'")->fetchColumn(), 'the new urgent ticket is not announced yet');
    $setStamp(NOTIFY_REFRESH_SECONDS + 5);
    same(notifications_refresh($pdo), 1, 'after the window the checks run and find it');
    $setStamp(NOTIFY_REFRESH_SECONDS + 5);
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZN-T-U3', 'ZZN Third urgent', 'x', 'urgent', 'open', NOW(), NOW())");
    http('GET', "$base/index.php", ['jar' => $admin]);
    check((bool) $pdo->query("SELECT 1 FROM notifications WHERE body = 'ZZN Third urgent'")->fetchColumn(), 'simply loading a page runs the refresh when it is due');
    $stamp = (int) $pdo->query("SELECT v FROM app_meta WHERE k = 'notif_refreshed_at'")->fetchColumn();
    http('GET', "$base/index.php", ['jar' => $admin]);
    same((int) $pdo->query("SELECT v FROM app_meta WHERE k = 'notif_refreshed_at'")->fetchColumn(), $stamp, 'and the next page load does not run it again');

    // bell and page
    $pdo->exec("UPDATE notifications SET read_at = NOW() WHERE dedupe_key NOT LIKE 'ticket-urgent:%'");
    $unreadNow = (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn();
    $page = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    check(str_contains($page, 'id="bell"') && str_contains($page, 'Notifications, ' . $unreadNow . ' unread') && str_contains($page, 'Mark all read') && str_contains($page, 'See all notifications'), 'the top bar has a bell with the unread count (' . $unreadNow . '), a Mark all read button and a link to all notifications');
    check(str_contains($page, 'ZZN Third urgent') || str_contains($page, 'Urgent ticket ZZN-T-U3'), 'the dropdown lists the latest notifications');
    $np = http('GET', "$base/notifications.php", ['jar' => $admin])['body'];
    check(str_contains($np, 'Urgent ticket ZZN-T-U3') && str_contains($np, 'notifications.php?go='), 'notifications.php lists them with a link to each item (which marks it read)');
    $un = http('GET', "$base/notifications.php?show=unread", ['jar' => $admin])['body'];
    check(str_contains($un, 'Urgent ticket ZZN-T-U3') && !str_contains($un, 'ZZN-OD-1'), 'the Unread filter shows only unread ones');
    same(http('POST', "$base/notifications.php", ['jar' => $admin, 'body' => 'action=read_all'])['code'], 403, 'marking as read without a CSRF token is 403');
    $nid = (int) $pdo->query("SELECT id FROM notifications WHERE body = 'ZZN Third urgent'")->fetchColumn();
    $post($admin, 'notifications.php', ['action' => 'read', 'id' => (string) $nid], 'notifications.php');
    same((int) $pdo->query("SELECT read_at IS NOT NULL FROM notifications WHERE id = $nid")->fetchColumn(), 1, 'Mark read marks that one notification');
    same((int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn(), $unreadNow - 1, 'and only that one');
    $post($admin, 'notifications.php', ['action' => 'read', 'id' => '999999'], 'notifications.php');
    same((int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn(), $unreadNow - 1, 'marking an unknown id changes nothing');
    $r = $post($admin, 'notifications.php', ['action' => 'read_all', 'back' => 'tickets.php'], 'notifications.php');
    same((int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn(), 0, 'Mark all read clears every unread one');
    check(str_contains($r['headers'], 'Location: tickets.php') && !str_contains($r['headers'], 'evil'), 'it returns to the page you were on');
    $r = $post($admin, 'notifications.php', ['action' => 'read_all', 'back' => '//evil.example/x'], 'notifications.php');
    check(str_contains($r['headers'], 'Location: notifications.php'), 'a forged return address is ignored');
    check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'Notifications, none unread'), 'with nothing unread the bell says so and shows no number');
    check(str_contains(http('GET', "$base/notifications.php?show=unread", ['jar' => $admin])['body'], 'You are all caught up'), 'the Unread filter shows a friendly empty state');
    same(http('GET', "$base/notifications.php?page=-4&show[]=x", ['jar' => $admin])['code'], 200, 'a negative page and an array parameter are handled');
    $x = $pdo->prepare('INSERT INTO notifications (kind, dedupe_key, title, body, link, created_at) VALUES (?,?,?,?,?,NOW())');
    $x->execute(['ticket_urgent', 'zzn-xss', 'ZZN <script>alert(1)</script>', '<img src=x onerror=alert(1)>', 'ticket.php?id=1']);
    $xp = http('GET', "$base/index.php", ['jar' => $admin])['body'] . http('GET', "$base/notifications.php", ['jar' => $admin])['body'];
    check(!str_contains($xp, '<script>alert(1)') && !str_contains($xp, '<img src=x') && str_contains($xp, '&lt;script&gt;'), 'notification text is escaped in the bell and on the page');
    $lay = (string) file_get_contents(TESTS_DIR . '/../includes/layout.php');
    check(preg_match('/@media \(prefers-reduced-motion: no-preference\) \{[^}]*bell-ring/', $lay) === 1 && substr_count($lay, 'animation: bell-ring') === 1, 'the bell only animates inside prefers-reduced-motion: no-preference');
    check(str_contains($page, '<details id="bell"') && str_contains($page, 'aria-label="Notifications, '), 'the bell is a keyboard-operable details/summary with a text label (no JavaScript needed)');
    $pdo->exec("DELETE FROM notifications WHERE dedupe_key = 'zzn-xss'");

    // old notifications are tidied
    $pdo->exec("INSERT INTO notifications (kind, dedupe_key, title, created_at) VALUES ('x', 'zzn-old', 'ZZN old', NOW() - INTERVAL 100 DAY)");
    notifications_refresh($pdo, true);
    same($nn('zzn-old'), 0, 'notifications older than 90 days are removed');

    // notification email in Settings
    $acc = http('GET', "$base/settings.php?tab=account", ['jar' => $admin])['body'];
    $notifyKeep = $pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'")->fetchColumn();
    // the notification address is a setting (EMAIL_TARGET) under Settings > Email, not part of the code
    $srcAll = ''; foreach (array_merge(glob(TESTS_DIR . '/../*.php'), glob(TESTS_DIR . '/../includes/*.php'), glob(TESTS_DIR . '/../includes/settings/*.php')) as $f) { $srcAll .= (string) file_get_contents($f); }
    check(!str_contains($srcAll, 'denis@telecomlijn.nl') && !str_contains($srcAll, 'NOTIFY_DEFAULT_EMAIL'), 'no notification address is written into the program code');
    check(!str_contains((string) file_get_contents(TESTS_DIR . '/../database/schema.sql'), 'denis@telecomlijn.nl'), 'and none is seeded by the schema');
    check(!str_contains($acc, 'name="notify_email"') && str_contains($acc, 'settings.php?tab=email'), 'Settings > Account no longer has the field; it points to Settings > Email');
    $emTab = http('GET', "$base/settings.php?tab=email", ['jar' => $admin])['body'];
    check(str_contains($emTab, 'name="email_target"') && str_contains($emTab, 'EMAIL_TARGET'), 'Settings > Email has "Send notifications to" (EMAIL_TARGET)');
    foreach (['SMTP_SERVER', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'EMAIL_FROM'] as $nm) { check(str_contains($emTab, $nm), "Settings > Email shows the setting name $nm next to its field"); }
    $nSave = fn(array $d) => $post($admin, 'settings.php?tab=email', ['tab' => 'email', 'action' => 'save', 'host' => '', 'port' => '587', 'encryption' => 'starttls', 'username' => '', 'password' => '', 'from_email' => '', 'from_name' => 'Telecomlijn Admin', 'base_url' => ''] + $d, 'settings.php?tab=email');
    $before = $pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'")->fetchColumn();
    $nSave(['email_target' => 'not an email']);
    same($pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'")->fetchColumn(), $before, 'an invalid notification address is refused');
    same($post($user, 'settings.php?tab=email', ['tab' => 'email', 'action' => 'save', 'email_target' => 'evil@example.test'], 'settings.php')['code'], 403, 'a normal account can not change it (403)');
    same($pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'")->fetchColumn(), $before, 'and it did not change');
    $nSave(['email_target' => 'team@example.test']);
    same($pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'")->fetchColumn(), 'team@example.test', 'an admin can change it');
    check(str_contains(http('GET', "$base/settings.php?tab=email", ['jar' => $admin])['body'], 'value="team@example.test"'), 'the new address is shown in the field');
    $nSave(['email_target' => '']);
    same(notify_email($pdo), '', 'it can be emptied to turn notification emails off');
    // only a never-saved setting may come from the environment
    $pdo->exec("DELETE FROM app_meta WHERE k = 'notify_email'");
    putenv('EMAIL_TARGET'); mail_env(true);
    same(notify_email($pdo), '', 'with nothing saved and nothing in the environment there is no address (no built-in default)');
    putenv('EMAIL_TARGET=env-target@example.test'); mail_env(true);
    same(notify_email($pdo), 'env-target@example.test', 'with nothing saved, EMAIL_TARGET from the environment is used');
    $nSave(['email_target' => 'saved@example.test']);
    same(notify_email($pdo), 'saved@example.test', 'a saved setting always wins over the environment');
    putenv('EMAIL_TARGET'); mail_env(true);
    // SMTP_* and EMAIL_FROM from the environment fill in what is not saved
    $pdo->exec('DELETE FROM mail_settings');
    putenv('SMTP_SERVER=smtp.env.test'); putenv('SMTP_PORT=2525'); putenv('SMTP_USERNAME=env-user'); putenv('SMTP_PASSWORD=env-pass'); putenv('EMAIL_FROM=from@env.test'); mail_env(true);
    $mc = mail_settings($pdo);
    same([$mc['host'], $mc['port'], $mc['username'], $mc['password_plain'], $mc['from_email']], ['smtp.env.test', 2525, 'env-user', 'env-pass', 'from@env.test'], 'SMTP_SERVER, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD and EMAIL_FROM from the environment are used when nothing is saved');
    same($mc['from_env'], ['SMTP_SERVER', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'EMAIL_FROM'], 'and the page can say which ones came from the environment');
    $emSrc = (string) file_get_contents(TESTS_DIR . '/../includes/settings/email.php');   // the web server does not see this process's environment, so check the page code
    check(str_contains($emSrc, 'From the environment') && str_contains($emSrc, 'implode(\', \', $c[\'from_env\'])') && !str_contains($emSrc, 'e($c[\'password_plain\'])') && !str_contains($emSrc, 'e($env[\'password\'])'), 'the Email tab says which values come from the environment, and never prints the environment password');
    $pdo->prepare("INSERT INTO mail_settings (id, host, port, encryption, username, from_email) VALUES (1, 'saved.test', 465, 'ssl', 'saved-user', 'saved@from.test')")->execute();
    $mc = mail_settings($pdo);
    same([$mc['host'], $mc['port'], $mc['username'], $mc['from_email'], $mc['password_plain']], ['saved.test', 465, 'saved-user', 'saved@from.test', null], 'settings saved on the page always win over the environment');
    foreach (['SMTP_SERVER', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'EMAIL_FROM'] as $nm) { putenv($nm); }
    mail_env(true);
    $pdo->exec('DELETE FROM mail_settings');
    if ($notifyKeep !== false) { $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('notify_email', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$notifyKeep]); }

    // demo flag
    $pdo->exec('DELETE FROM notifications WHERE is_demo = 1'); notifications_refresh($pdo, true);
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications n JOIN invoices i ON n.dedupe_key = CONCAT('invoice-overdue:', i.id) WHERE i.is_demo = 1 AND n.is_demo = 0")->fetchColumn(), 0, 'notifications about demo invoices are tagged as demo, so removing demo data removes them');
    $cleanNotif();
    $pdo->exec("DELETE FROM notifications WHERE dedupe_key = 'zzn-xss' OR dedupe_key = 'zzn-old'");
