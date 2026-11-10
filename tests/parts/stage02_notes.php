<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 2 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 2: notes and timeline
    // =========================================================
    $cleanNotes = function () use ($pdo) {
        $pdo->exec("DELETE FROM notes WHERE body LIKE 'ZZ Test%' OR body LIKE '%ZZ-NOTE%'");
        $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer LIKE 'ZZ Test%')");
        $pdo->exec("DELETE FROM invoices WHERE customer LIKE 'ZZ Test%'");
        $pdo->exec("DELETE FROM users WHERE email = 'zz.notes.test@example.test'");
        $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZ Test%'");
        $pdo->exec("DELETE FROM activity_logs WHERE action = 'Note added' AND is_demo = 0 AND actor IN ('admin', 'kuma.tester') AND created_at > NOW() - INTERVAL 1 HOUR");
    };
    $cleanNotes();
    section('14. Notes and timeline');
    $pdo->prepare("INSERT INTO companies (name) VALUES ('ZZ Test Notes Co')")->execute();
    $ncid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (name, email, country, last_seen, created_at) VALUES ('ZZ Test Notes User', 'zz.notes.test@example.test', 'Netherlands', NOW(), NOW())")->execute();
    $nuid = (int) $pdo->lastInsertId();

    same(http('POST', "$base/company.php", ['body' => "id=$ncid&action=note&kind=note&body=ZZ+Test+anon"])['code'], 302, 'signed out: adding a note is refused');
    same((int) $pdo->query("SELECT COUNT(*) FROM notes WHERE body = 'ZZ Test anon'")->fetchColumn(), 0, 'and nothing is stored');
    $r = http('POST', "$base/company.php", ['jar' => $admin, 'body' => http_build_query(['id' => $ncid, 'action' => 'note', 'kind' => 'note', 'body' => 'ZZ Test no token'])]);
    same($r['code'], 403, 'a note without a CSRF token is 403');

    $r = $post($admin, 'company.php', ['id' => (string) $ncid, 'action' => 'note', 'kind' => 'call', 'body' => 'ZZ Test first call'], "company.php?id=$ncid");
    same($r['code'], 302, 'adding a note on a company redirects back');
    $row = $pdo->query("SELECT * FROM notes WHERE body = 'ZZ Test first call'")->fetch();
    check($row && $row['about_type'] === 'company' && (int) $row['about_id'] === $ncid && $row['kind'] === 'call' && $row['author'] === 'admin', 'it is stored for that company, with the kind and the signed-in account as author');
    $r = $post($admin, 'company.php', ['id' => (string) $ncid, 'action' => 'note', 'kind' => 'note', 'body' => 'ZZ Test second', 'author' => 'someone-else'], "company.php?id=$ncid");
    same($pdo->query("SELECT author FROM notes WHERE body = 'ZZ Test second'")->fetchColumn(), 'admin', 'the author can not be chosen by the request');
    $page = http('GET', "$base/company.php?id=$ncid", ['jar' => $admin])['body'];
    check(str_contains($page, 'ZZ Test first call') && str_contains($page, 'ZZ Test second') && strpos($page, 'ZZ Test second') < strpos($page, 'ZZ Test first call'), 'the timeline lists the notes, newest first');

    $r = $post($admin, 'user.php', ['id' => (string) $nuid, 'action' => 'note', 'kind' => 'meeting', 'body' => "ZZ Test <script>alert(1)</script>\nsecond line"], "user.php?id=$nuid");
    $up = http('GET', "$base/user.php?id=$nuid", ['jar' => $admin])['body'];
    check(str_contains($up, 'ZZ Test &lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($up, '<script>alert(1)'), 'a note is shown as text: markup is escaped');
    check(str_contains($up, 'Meeting') && str_contains($up, 'second line'), 'the kind and line breaks are kept');
    check(!str_contains($page, 'ZZ Test &lt;script'), 'a note on a user does not appear on a company');

    $before = (int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn();
    foreach ([['id' => '999999', 'kind' => 'note', 'body' => 'ZZ Test ghost'], ['id' => (string) $nuid, 'kind' => 'bogus', 'body' => 'ZZ Test bad kind'], ['id' => (string) $nuid, 'kind' => 'note', 'body' => '   ']] as $bad) {
        $post($admin, 'user.php', ['action' => 'note'] + $bad, "user.php?id=$nuid");
    }
    same((int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn(), $before, 'a note on a missing user, with a bad kind or empty is not saved');
    $post($admin, 'user.php', ['id' => (string) $nuid, 'action' => 'note', 'kind' => 'note', 'body' => 'ZZ Test long ' . str_repeat('x', 5000)], "user.php?id=$nuid");
    same((int) $pdo->query("SELECT MAX(CHAR_LENGTH(body)) FROM notes WHERE body LIKE 'ZZ Test long%'")->fetchColumn(), 2000, 'a note longer than 2000 characters is capped at 2000, never stored in full');
    same(http('POST', "$base/user.php", ['jar' => $admin, 'body' => 'id=999999&action=note'])['code'], 404, 'a note on a missing user answers 404');
    $post($admin, 'user.php', ['id' => (string) $nuid, 'action' => 'note', 'kind' => 'note', 'body' => 'ZZ Test ' . str_repeat('y', 1992)], "user.php?id=$nuid");
    same((int) $pdo->query("SELECT COUNT(*) FROM notes WHERE body LIKE 'ZZ Test yyy%'")->fetchColumn(), 1, 'a note of exactly 2000 characters is accepted');
    $msg = note_add($pdo, 'company', 999999, 'note', 'ZZ Test ghost');
    check($msg !== null && str_contains($msg, 'does not exist'), 'note_add refuses a missing company and says why');
    check(note_add($pdo, 'invoice', $ncid, 'note', 'ZZ Test ghost') !== null, 'note_add refuses an unknown target type');

    // company timeline also shows invoices issued
    $pdo->prepare("INSERT INTO invoices (number, company_id, customer, email, customer_address, issue_date, due_date, subtotal_cents, vat_cents, total_cents, created_at) VALUES ('ZZ-TL-1', ?, 'ZZ Test Notes Co', 'a@b.test', 'x', CURDATE(), CURDATE(), 1000, 210, 1210, NOW())")->execute([$ncid]);
    $cp = http('GET', "$base/company.php?id=$ncid", ['jar' => $admin])['body'];
    check(str_contains($cp, 'Invoice issued') && str_contains($cp, 'ZZ-TL-1'), 'the company timeline includes invoices issued');
    $pdo->exec("DELETE FROM invoices WHERE number = 'ZZ-TL-1'");
    check(!str_contains(http('GET', "$base/user.php?id=$nuid", ['jar' => $admin])['body'], 'Invoice issued'), 'the user timeline has notes only');

    // deleting a company removes its notes; demo notes
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$nuid]);
    $post($admin, 'company_edit.php', ['id' => (string) $ncid, 'action' => 'delete'], "company_edit.php?id=$ncid");
    same((int) $pdo->query("SELECT COUNT(*) FROM notes WHERE about_type = 'company' AND about_id = $ncid")->fetchColumn(), 0, 'deleting a company also deletes its notes');
    if (demo_loaded($pdo)) {
        check(demo_counts($pdo, 1)['notes'] > 20, 'demo data includes notes on companies and users');
        same((int) $pdo->query("SELECT COUNT(*) FROM notes n WHERE n.is_demo = 1 AND ((n.about_type = 'company' AND NOT EXISTS (SELECT 1 FROM companies c WHERE c.id = n.about_id)) OR (n.about_type = 'user' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = n.about_id)))")->fetchColumn(), 0, 'every demo note points at an existing demo company or user');
    }
    $cleanNotes();
