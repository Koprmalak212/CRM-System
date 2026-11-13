<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 24 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
// =========================================================
// Stage 24: smoother use (stay signed in, search suggestions, quick ticket changes, rows per page, undo, the page frame)
// =========================================================
$clean24 = function () use ($pdo) {
    $pdo->exec("DELETE FROM ticket_comments WHERE ticket_id IN (SELECT id FROM tickets WHERE subject LIKE 'ZZ24 %')");
    $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZ24 %'");
    $pdo->exec("DELETE FROM notes WHERE body LIKE 'ZZ24 %'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'zz24.%@example.test'");
    $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZ24 %'");
    $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND (target LIKE 'ZZ24%' OR action IN ('Note restored'))");
};
$clean24();
try {
    $pdo->exec("INSERT INTO companies (name) VALUES ('ZZ24 Company')");
    $co24 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (name, email, country, last_seen, created_at, company_id) VALUES ('ZZ24 Person', 'zz24.person@example.test', 'NL', NOW(), NOW(), ?)")->execute([$co24]);
    $u24 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tickets (reference, company_id, subject, description, created_by) VALUES ('ZZ24-T1', ?, 'ZZ24 inline change', 'x', 'admin')")->execute([$co24]);
    $t24 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO notes (about_type, about_id, kind, body, author) VALUES ('user', ?, 'note', 'ZZ24 keep me', 'admin')")->execute([$u24]);
    $n24 = (int) $pdo->lastInsertId();

    section('60. Smoother use: staying signed in and search suggestions');
    same(http('GET', "$base/auth/ping.php")['code'], 401, 'signed out: the stay-signed-in call answers 401');
    same(http('GET', "$base/auth/ping.php", ['jar' => $admin])['code'], 204, 'signed in: it answers 204 and counts as activity');
    same(http('GET', "$base/search/suggest.php?q=ZZ24")['code'], 401, 'signed out: no search suggestions (401)');
    $sg = http('GET', "$base/search/suggest.php?q=ZZ24", ['jar' => $admin]);
    $list24 = json_decode($sg['body'], true);
    $kinds24 = is_array($list24) ? array_column($list24, 'kind') : [];
    check($sg['code'] === 200 && stripos($sg['headers'], 'application/json') !== false && in_array('Ticket', $kinds24, true) && in_array('Company', $kinds24, true) && in_array('User', $kinds24, true), 'signed in: suggestions come back as JSON with the ticket, the company and the user that match');
    check(in_array("tickets/ticket.php?id=$t24", array_column($list24 ?: [], 'url'), true), 'and each one carries the address of its record');
    same(json_decode(http('GET', "$base/search/suggest.php?q=Z", ['jar' => $admin])['body'], true), [], 'one letter is too little to suggest anything');
    same(json_decode(http('GET', "$base/search/suggest.php?q=" . urlencode('ZZ24%nothing'), ['jar' => $admin])['body'], true), [], 'a % in the text is searched literally, not as a wildcard');

    section('61. Smoother use: status and assignee straight from the ticket list');
    $lst = http('GET', "$base/tickets/tickets.php?q=ZZ24", ['jar' => $admin])['body'];
    check(substr_count($lst, 'data-quick') >= 2 && str_contains($lst, 'aria-label="Status of ZZ24-T1"') && str_contains($lst, 'aria-label="Assignee of ZZ24-T1"'), 'a row has a labelled status dropdown and assignee dropdown');
    $r24 = $post($admin, 'tickets/tickets.php', ['id' => (string) $t24, 'status' => 'in_progress', 'back' => '?q=ZZ24&status=open'], 'tickets/tickets.php');
    check($r24['code'] === 302 && preg_match('~Location: [^\r\n]*tickets/tickets\.php\?q=ZZ24&status=open\r?\n~i', $r24['headers']) === 1, 'changing the status sends you back to the list exactly as it was');
    same($pdo->query("SELECT status FROM tickets WHERE id = $t24")->fetchColumn(), 'in_progress', 'and the status is stored');
    check((int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $t24 AND body LIKE 'Changed status%'")->fetchColumn() === 1, 'the thread notes who changed what, as on the ticket page');
    $me24 = (int) $pdo->query("SELECT id FROM admins WHERE username = " . $pdo->quote($adminUser))->fetchColumn();
    $post($admin, 'tickets/tickets.php', ['id' => (string) $t24, 'assignee_id' => (string) $me24, 'back' => ''], 'tickets/tickets.php');
    same((int) $pdo->query("SELECT assignee_id FROM tickets WHERE id = $t24")->fetchColumn(), $me24, 'the assignee can be changed the same way');
    same(http('POST', "$base/tickets/tickets.php", ['jar' => $admin, 'body' => "id=$t24&status=open"])['code'], 403, 'a quick change without a CSRF token is 403');
    same(http('POST', "$base/tickets/tickets.php", ['body' => "id=$t24&status=open"])['code'], 302, 'signed out: a quick change is refused');
    $pdo->exec("UPDATE tickets SET status = 'closed' WHERE id = $t24");
    $post($admin, 'tickets/tickets.php', ['id' => (string) $t24, 'status' => 'resolved', 'back' => ''], 'tickets/tickets.php');
    same($pdo->query("SELECT status FROM tickets WHERE id = $t24")->fetchColumn(), 'closed', 'the rules still hold: a closed ticket can only be reopened');
    $ev24 = $post($admin, 'tickets/tickets.php', ['id' => (string) $t24, 'status' => 'open', 'back' => 'https://example.test/x'], 'tickets/tickets.php');
    check(preg_match('~Location: [^\r\n]*tickets/tickets\.php\r?\n~i', $ev24['headers']) === 1 && !str_contains($ev24['headers'], 'example.test'), 'the way back can not be pointed at another site');

    section('62. Smoother use: rows per page');
    $pg50 = http('GET', "$base/system/logs.php?per=50", ['jar' => $admin])['body'];
    check(str_contains($pg50, 'tabular-nums">50</span>') && str_contains($pg50, 'aria-label="100 rows per page"'), 'a list offers 15, 25, 50 and 100 rows and marks the one in use');
    check(str_contains(http('GET', "$base/system/logs.php", ['jar' => $admin])['body'], 'tabular-nums">50</span>'), 'the choice is remembered for that list');
    check(!str_contains(http('GET', "$base/users/users.php?per=7", ['jar' => $admin])['body'], 'tabular-nums">7</span>'), 'a number that is not on offer is ignored');
    http('GET', "$base/system/logs.php?per=25", ['jar' => $admin]);

    section('63. Smoother use: undo for a deleted note');
    $del24 = fn() => $post($admin, 'users/user.php', ['id' => (string) $u24, 'action' => 'note_delete', 'note_id' => (string) $n24], "users/user.php?id=$u24");
    $del24();
    same((int) $pdo->query("SELECT COUNT(*) FROM notes WHERE id = $n24")->fetchColumn(), 0, 'the note is deleted at once');
    $after24 = http('GET', "$base/users/user.php?id=$u24", ['jar' => $admin])['body'];
    check(str_contains($after24, 'action="system/undo.php"') && str_contains($after24, '>Undo</button>'), 'the message that reports it carries an Undo button');
    check(!str_contains(http('GET', "$base/users/user.php?id=$u24", ['jar' => $admin])['body'], 'action="system/undo.php"'), 'the button is offered once, not on every later message');
    same(http('POST', "$base/system/undo.php", ['jar' => $admin, 'body' => ''])['code'], 403, 'undo without a CSRF token is 403');
    $post($user, 'system/undo.php', [], 'index.php');
    same((int) $pdo->query("SELECT COUNT(*) FROM notes WHERE id = $n24")->fetchColumn(), 0, 'someone else can not undo it: the row waits in the session of the person who deleted it');
    $un24 = $post($admin, 'system/undo.php', [], 'index.php');
    $back24 = $pdo->query("SELECT body, author, kind FROM notes WHERE id = $n24")->fetch();
    check($un24['code'] === 302 && str_contains($un24['headers'], "users/user.php?id=$u24") && $back24 && $back24['body'] === 'ZZ24 keep me' && $back24['author'] === 'admin', 'Undo puts the note back under its own id, with its text and author, and returns to the page');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Note restored'")->fetchColumn() >= 1, 'and the restore is in the activity log');
    $post($admin, 'system/undo.php', [], 'index.php');
    same((int) $pdo->query("SELECT COUNT(*) FROM notes WHERE body = 'ZZ24 keep me'")->fetchColumn(), 1, 'undoing twice does nothing the second time');
    $del24();
    $pdo->exec('DELETE FROM rate_limits');
    same(http('GET', "$base/system/undo.php", ['jar' => $admin])['code'], 302, 'opening the undo address by hand restores nothing');
    same((int) $pdo->query("SELECT COUNT(*) FROM notes WHERE id = $n24")->fetchColumn(), 0, 'it needs the button (a POST with the token)');

    section('64. Smoother use: the page frame');
    $ov24 = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    check(str_contains($ov24, 'id="newMenu"') && str_contains($ov24, 'href="tickets/ticket_new.php"') && str_contains($ov24, 'href="companies/company_edit.php"'), 'the top bar has a New menu with the things you can create');
    check(str_contains($ov24, 'id="kbdHelp"') && str_contains($ov24, 'Keyboard shortcuts'), 'the list of keyboard shortcuts is in the page');
    check(str_contains($ov24, 'data-idle="' . SESSION_IDLE_SECONDS . '"') && preg_match('~<script src="assets/js/ux\.js\?v=\d+" defer></script>~', $ov24) === 1, 'the page knows how long a session may be idle and loads the script that warns before it ends');
    check(preg_match('~>Customers</p>.*>Sales</p>.*>System</p>~s', $ov24) === 1, 'the menu is in groups with a heading each');
    $js24 = (string) file_get_contents(TESTS_DIR . '/../assets/js/ux.js');
    check(str_contains($js24, 'sessionStorage') && !preg_match('~https?://(?!["\'])~', preg_replace('~Start with https://~', '', $js24)), 'the script keeps drafts and filters in the tab only and talks to no other site');
    check(str_contains((string) file_get_contents(TESTS_DIR . '/../assets/js/motion.js'), "form[data-auto]") && substr_count(http('GET', "$base/users/users.php", ['jar' => $admin])['body'], 'data-auto') === 1, 'filter forms are marked so they apply themselves');
} finally {
    $clean24();
}
