<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 3 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 3: tickets
    // =========================================================
    $cleanTickets = function () use ($pdo) {
        $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZ Test%'");
        $pdo->exec("DELETE FROM users WHERE email = 'zz.ticket.test@example.test'");
        $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZ Test%'");
        $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND ((action IN ('Ticket opened','Ticket updated') AND target LIKE '%ZZ Test%') OR (action = 'Ticket updated' AND target LIKE 'TCK-%' AND created_at > NOW() - INTERVAL 1 HOUR))");
    };
    $cleanTickets();
    section('15. Tickets');
    $pdo->exec("INSERT INTO companies (name) VALUES ('ZZ Test Ticket Co'), ('ZZ Test Other Co')");
    $tc1 = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZ Test Ticket Co'")->fetchColumn();
    $tc2 = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZ Test Other Co'")->fetchColumn();
    $pdo->prepare("INSERT INTO users (name, email, country, last_seen, created_at, company_id) VALUES ('ZZ Test Ticket User', 'zz.ticket.test@example.test', 'Netherlands', NOW(), NOW(), ?)")->execute([$tc1]);
    $tu = (int) $pdo->query("SELECT id FROM users WHERE email = 'zz.ticket.test@example.test'")->fetchColumn();
    $adminId = (int) $pdo->query("SELECT id FROM admins WHERE username = 'admin'")->fetchColumn();

    foreach (['tickets/tickets.php', 'tickets/ticket.php?id=1', 'tickets/ticket_new.php'] as $pg) { same(http('GET', "$base/$pg")['code'], 302, "signed out: $pg redirects to the login page"); }
    same(http('POST', "$base/tickets/ticket_new.php", ['body' => 'subject=ZZ+Test+anon&description=x'])['code'], 302, 'signed out: a forged ticket is refused');
    same(http('POST', "$base/tickets/ticket_new.php", ['jar' => $admin, 'body' => 'subject=ZZ+Test+notoken&description=x'])['code'], 403, 'a ticket without a CSRF token is 403');
    same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE subject LIKE 'ZZ Test%'")->fetchColumn(), 0, 'and nothing is stored');

    $nt = ['subject' => 'ZZ Test Broken phone', 'description' => 'It does not ring.', 'priority' => 'high', 'company_id' => (string) $tc1, 'user_id' => (string) $tu, 'assignee_id' => (string) $adminId];
    foreach ([['Enter a subject', ['subject' => '']], ['Describe the problem', ['description' => '  ']], ['Choose a priority', ['priority' => 'asap']], ['company does not exist', ['company_id' => '999999']], ['user does not exist', ['user_id' => '999999']], ['does not belong to the chosen company', ['company_id' => (string) $tc2]], ['assignee does not exist', ['assignee_id' => '999999']], ['at most 160', ['subject' => 'ZZ Test ' . str_repeat('s', 160)]]] as [$msg, $bad]) {
        $r = $post($admin, 'tickets/ticket_new.php', $bad + $nt);
        check($r['code'] === 200 && str_contains($r['body'], $msg), 'validation: ' . $msg);
    }
    same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE subject LIKE 'ZZ Test%'")->fetchColumn(), 0, 'nothing is stored while validation fails');
    $r = $post($admin, 'tickets/ticket_new.php', $nt);
    same($r['code'], 302, 'a valid ticket is created and redirects');
    $tk = $pdo->query("SELECT * FROM tickets WHERE subject = 'ZZ Test Broken phone'")->fetch();
    check($tk && $tk['status'] === 'open' && $tk['priority'] === 'high' && (int) $tk['company_id'] === $tc1 && (int) $tk['user_id'] === $tu && (int) $tk['assignee_id'] === $adminId && $tk['created_by'] === 'admin', 'it is stored: status open, priority, company, contact, assignee, opened by the signed-in account');
    same($tk['reference'], 'TCK-' . str_pad((string) $tk['id'], 4, '0', STR_PAD_LEFT), 'the reference is TCK- plus the zero-padded number');
    $tid = (int) $tk['id'];
    $page = http('GET', "$base/tickets/ticket.php?id=$tid", ['jar' => $admin])['body'];
    check(str_contains($page, 'ZZ Test Broken phone') && str_contains($page, $tk['reference']) && str_contains($page, 'It does not ring.') && str_contains($page, "companies/company.php?id=$tc1"), 'the ticket page shows details and links to the company');
    $r = $post($admin, 'tickets/ticket_new.php', ['company_id' => '0', 'user_id' => (string) $tu, 'subject' => 'ZZ Test From user only'] + $nt);
    same((int) $pdo->query("SELECT company_id FROM tickets WHERE subject = 'ZZ Test From user only'")->fetchColumn(), $tc1, 'a ticket with only a contact person takes over that person\'s company');
    $xr = $post($admin, 'tickets/ticket_new.php', ['subject' => 'ZZ Test <script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>'] + $nt);
    $xid = (int) $pdo->query("SELECT id FROM tickets WHERE subject LIKE 'ZZ Test <script>%'")->fetchColumn();
    $xp = http('GET', "$base/tickets/ticket.php?id=$xid", ['jar' => $admin])['body'] . http('GET', "$base/tickets/tickets.php?q=ZZ+Test", ['jar' => $admin])['body'] . http('GET', "$base/companies/company.php?id=$tc1", ['jar' => $admin])['body'];
    check($xid > 0 && !str_contains($xp, '<script>alert(1)') && !str_contains($xp, '<img src=x') && str_contains($xp, '&lt;script&gt;'), 'markup in a subject and description is escaped on every page');

    // comments
    $post($admin, 'tickets/ticket.php', ['id' => (string) $tid, 'action' => 'comment', 'body' => 'ZZ Test first reply'], "tickets/ticket.php?id=$tid");
    $post($admin, 'tickets/ticket.php', ['id' => (string) $tid, 'action' => 'comment', 'body' => "ZZ Test <b>bold</b>\nline two", 'author' => 'forged'], "tickets/ticket.php?id=$tid");
    $cm = $pdo->query("SELECT author, body FROM ticket_comments WHERE ticket_id = $tid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check(count($cm) === 2 && $cm[0]['author'] === 'admin' && $cm[1]['author'] === 'admin', 'comments are stored with the signed-in account as author (a forged author is ignored)');
    $page = http('GET', "$base/tickets/ticket.php?id=$tid", ['jar' => $admin])['body'];
    check(strpos($page, 'ZZ Test first reply') < strpos($page, 'line two') && str_contains($page, '&lt;b&gt;bold&lt;/b&gt;'), 'the thread lists comments oldest first and escapes markup');
    $post($admin, 'tickets/ticket.php', ['id' => (string) $tid, 'action' => 'comment', 'body' => '   '], "tickets/ticket.php?id=$tid");
    same((int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $tid")->fetchColumn(), 2, 'an empty comment is not saved');
    same(http('POST', "$base/tickets/ticket.php", ['jar' => $admin, 'body' => 'id=999999&action=comment&body=x'])['code'], 404, 'a comment on a missing ticket answers 404');
    same(http('POST', "$base/tickets/ticket.php", ['jar' => $admin, 'body' => "id=$tid&action=comment&body=ZZ+Test+nocsrf"])['code'], 403, 'a comment without a CSRF token is 403');

    // status flow
    $mv = fn(array $d) => $post($admin, 'tickets/ticket.php', ['id' => (string) $tid, 'action' => 'update'] + $d, "tickets/ticket.php?id=$tid");
    $st = fn() => $pdo->query("SELECT CONCAT(status, '|', priority, '|', COALESCE(assignee_id, 0), '|', IF(resolved_at IS NULL, 'open', 'done')) FROM tickets WHERE id = $tid")->fetchColumn();
    $mv(['status' => 'in_progress']);
    same($st(), "in_progress|high|$adminId|open", 'open to in progress');
    $mv(['status' => 'waiting']);
    same(explode('|', $st())[0], 'waiting', 'in progress to waiting');
    $mv(['status' => 'resolved']);
    same(explode('|', $st())[3], 'done', 'resolved sets the resolved time');
    $mv(['status' => 'open']);
    same(explode('|', $st())[3], 'open', 'reopening clears the resolved time');
    $mv(['status' => 'closed']);
    same(explode('|', $st())[0], 'closed', 'a ticket can be closed');
    $mv(['status' => 'in_progress']);
    same(explode('|', $st())[0], 'closed', 'a closed ticket can not move to in progress');
    $mv(['status' => 'bogus']);
    same(explode('|', $st())[0], 'closed', 'an unknown status is refused');
    $mv(['status' => 'open']);
    same(explode('|', $st())[0], 'open', 'a closed ticket can be reopened');
    $mv(['status' => 'open', 'priority' => 'urgent', 'assignee_id' => '0']);
    same($st(), 'open|urgent|0|open', 'priority and assignee change, assignee can be cleared');
    $mv(['priority' => 'galactic']);
    same(explode('|', $st())[1], 'urgent', 'an unknown priority is refused');
    $mv(['assignee_id' => '999999']);
    same(explode('|', $st())[2], '0', 'an unknown assignee is refused');
    check((int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $tid AND author = 'system' AND body LIKE 'Changed status%'")->fetchColumn() >= 4, 'every change leaves a line in the thread');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Ticket updated' AND target LIKE '{$tk['reference']}%'")->fetchColumn() >= 4 && (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Ticket opened' AND target LIKE '%ZZ Test Broken%'")->fetchColumn() === 1, 'opening and changing a ticket are in the activity log');

    // list: filters, sorting, pagination, search
    $pdo->prepare("UPDATE tickets SET status = 'waiting', priority = 'low', assignee_id = NULL WHERE id = ?")->execute([$tid]);
    $L = fn(string $qs) => http('GET', "$base/tickets/tickets.php?$qs", ['jar' => $admin])['body'];
    check(str_contains($L('q=ZZ+Test+Broken'), 'ZZ Test Broken phone'), 'search finds a ticket by subject');
    check(str_contains($L('q=' . urlencode($tk['reference'])), 'ZZ Test Broken phone'), 'search finds a ticket by reference');
    check(str_contains($L('q=ZZ+Test+Ticket+Co'), 'ZZ Test Broken phone'), 'search finds a ticket by company name');
    check(str_contains($L('q=%25'), 'No tickets match'), 'a typed % is searched literally');
    check(str_contains($L('q=ZZ+Test&status=waiting'), 'ZZ Test Broken phone') && !str_contains($L('q=ZZ+Test&status=open'), 'ZZ Test Broken phone'), 'the status filter includes and excludes');
    check(str_contains($L('q=ZZ+Test&priority=low'), 'ZZ Test Broken phone') && !str_contains($L('q=ZZ+Test&priority=urgent'), 'ZZ Test Broken phone'), 'the priority filter includes and excludes');
    check(str_contains($L('q=ZZ+Test&assignee=none'), 'ZZ Test Broken phone') && !str_contains($L("q=ZZ+Test+Broken&assignee=$adminId"), 'ZZ Test Broken phone'), 'the assignee filter (unassigned / a person) includes and excludes');
    check(str_contains($L("company=$tc1"), 'ZZ Test Broken phone') && !str_contains($L("company=$tc2"), 'ZZ Test Broken phone'), 'the company filter includes and excludes');
    check(str_contains($L('q=ZZ+Test&status=waiting&priority=low&company=' . $tc1), 'ZZ Test Broken phone'), 'filters combine');
    foreach (['reference', 'subject', 'company', 'status', 'priority', 'assignee', 'updated'] as $so) { foreach (['asc', 'desc'] as $di) { same(http('GET', "$base/tickets/tickets.php?sort=$so&dir=$di", ['jar' => $admin])['code'], 200, "sort by $so $di works"); } }
    same(http('GET', "$base/tickets/tickets.php?sort=" . urlencode('id; DROP TABLE tickets') . '&status[]=x&page=-3&assignee=' . urlencode("1' OR '1'='1"), ['jar' => $admin])['code'], 200, 'hostile sort, array and quote values are handled');
    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
    if ($cnt > 15) { check(str_contains($L('page=2'), 'Page 2 of') && str_contains($L('page=9999'), 'Showing'), 'pagination works, and a page beyond the end shows the last page'); }
    check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'href="tickets/tickets.php"'), 'the sidebar has a Tickets item');
    $ov = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    $openN = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed')")->fetchColumn();
    check(str_contains($ov, 'Open tickets') && str_contains($ov, 'data-count="' . $openN . '"'), 'the Overview shows the number of open tickets (' . $openN . ')');

    // company and user pages
    $pdo->prepare("UPDATE tickets SET status = 'open' WHERE id = ?")->execute([$tid]);
    $cp = http('GET', "$base/companies/company.php?id=$tc1", ['jar' => $admin])['body'];
    check(str_contains($cp, 'Open tickets') && str_contains($cp, 'ZZ Test Broken phone') && str_contains($cp, 'Ticket opened'), 'the company page lists its open tickets and the timeline shows "Ticket opened"');
    check(str_contains(http('GET', "$base/users/user.php?id=$tu", ['jar' => $admin])['body'], 'ZZ Test Broken phone'), 'the user page lists their open tickets');
    $pdo->prepare("UPDATE tickets SET status = 'resolved' WHERE id = ?")->execute([$tid]);
    check(!str_contains(http('GET', "$base/users/user.php?id=$tu", ['jar' => $admin])['body'], 'href="tickets/ticket.php?id=' . $tid . '"'), 'a resolved ticket no longer shows under open tickets');
    check(str_contains(http('GET', "$base/tickets/ticket_new.php?company=$tc1&user=$tu", ['jar' => $admin])['body'], 'value="' . $tc1 . '" selected'), 'tickets/ticket_new.php?company= preselects the company');

    // deleting a company keeps its tickets
    $pdo->prepare("UPDATE users SET company_id = NULL WHERE id = ?")->execute([$tu]);
    $post($admin, 'companies/company_edit.php', ['id' => (string) $tc1, 'action' => 'delete'], "companies/company_edit.php?id=$tc1");
    same($pdo->query("SELECT company_id FROM tickets WHERE id = $tid")->fetchColumn(), null, 'deleting a company keeps its tickets (they lose the link)');
    $pdo->exec("DELETE FROM tickets WHERE id = $tid");
    same((int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $tid")->fetchColumn(), 0, 'deleting a ticket deletes its comments');
    if (demo_loaded($pdo)) {
        $dc = demo_counts($pdo, 1);
        check($dc['tickets'] >= 40 && $dc['ticket_comments'] > 40, 'demo data has about 40 tickets with comments');
        same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE is_demo = 1 AND reference NOT REGEXP '^TCK-[0-9]{4}$'")->fetchColumn(), 0, 'every demo ticket has a proper reference');
        check((int) $pdo->query('SELECT COUNT(DISTINCT status) FROM tickets WHERE is_demo = 1')->fetchColumn() === 5 && (int) $pdo->query('SELECT COUNT(DISTINCT priority) FROM tickets WHERE is_demo = 1')->fetchColumn() === 4, 'demo tickets cover every status and priority');
    }
    $cleanTickets();
