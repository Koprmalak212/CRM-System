<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 4 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 4: global search
    // =========================================================
    $cleanSearch = function () use ($pdo) {
        $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZQ%'");
        $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer LIKE 'ZZQ%')");
        $pdo->exec("DELETE FROM invoices WHERE customer LIKE 'ZZQ%'");
        $pdo->exec("DELETE FROM users WHERE email LIKE 'zz.search%@example.test'");
        $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZQ%'");
    };
    $cleanSearch();
    section('16. Global search');
    $pdo->exec('DELETE FROM rate_limits');
    $pdo->exec("INSERT INTO companies (name, city, kvk, email) VALUES ('ZZQ Zebra Holding', 'Zwolle', '99990077', 'zebra@example.test'), ('ZZQ 100% Percent_Co', 'Zeist', '', '')");
    $zc = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZQ Zebra Holding'")->fetchColumn();
    $pc = (int) $pdo->query("SELECT id FROM companies WHERE name LIKE 'ZZQ 100%'")->fetchColumn();
    $pdo->prepare("INSERT INTO users (name, email, country, last_seen, created_at, company_id) VALUES ('ZZQ Zora Zebra', 'zz.search1@example.test', 'Netherlands', NOW(), NOW(), ?)")->execute([$zc]);
    $zu = (int) $pdo->query("SELECT id FROM users WHERE email = 'zz.search1@example.test'")->fetchColumn();
    $pdo->prepare("INSERT INTO invoices (number, company_id, customer, email, customer_address, issue_date, due_date, subtotal_cents, vat_cents, total_cents, created_at) VALUES ('ZZQ-INV-1', ?, 'ZZQ Zebra Holding', 'zebra@example.test', 'x', CURDATE(), CURDATE() + INTERVAL 30 DAY, 1000, 210, 1210, NOW())")->execute([$zc]);
    $zi = (int) $pdo->query("SELECT id FROM invoices WHERE number = 'ZZQ-INV-1'")->fetchColumn();
    $pdo->prepare("INSERT INTO tickets (reference, company_id, subject, description, created_at, updated_at) VALUES ('ZZQ-T-1', ?, 'ZZQ Zebra crossing broken', 'x', NOW(), NOW())")->execute([$zc]);
    $zt = (int) $pdo->query("SELECT id FROM tickets WHERE reference = 'ZZQ-T-1'")->fetchColumn();
    $S = fn(string $q) => http('GET', "$base/search/search.php?q=" . urlencode($q), ['jar' => $admin]);

    same(http('GET', "$base/search/search.php?q=zebra")['code'], 302, 'signed out: search/search.php redirects to the login page');
    $top = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    check(str_contains($top, 'action="search/search.php"') && str_contains($top, 'id="gsearch"') && str_contains($top, 'name="q"'), 'the top-bar search sends people to search/search.php');
    $r = $S('ZZQ Zebra');
    $b = $r['body'];
    check($r['code'] === 200 && str_contains($b, "users/user.php?id=$zu") && str_contains($b, "companies/company.php?id=$zc") && str_contains($b, "invoices/invoice.php?id=$zi") && str_contains($b, "tickets/ticket.php?id=$zt"), 'one search finds a user, a company, an invoice and a ticket, each linking to its page');
    check(preg_match('/Users <span[^>]*>\(1\)/', $b) && preg_match('/Companies <span[^>]*>\(1\)/', $b) && preg_match('/Invoices <span[^>]*>\(1\)/', $b) && preg_match('/Tickets <span[^>]*>\(1\)/', $b), 'results are grouped with a count per group');
    check(str_contains($S('zora zebra')['body'], "users/user.php?id=$zu"), 'a user is found by name (any capitals)');
    check(str_contains($S('zz.search1@example')['body'], "users/user.php?id=$zu"), 'a user is found by email');
    check(str_contains($S('Zebra Holding')['body'], "users/user.php?id=$zu"), 'a user is found by their company name');
    check(str_contains($S('Zwolle')['body'], "companies/company.php?id=$zc") && str_contains($S('99990077')['body'], "companies/company.php?id=$zc") && str_contains($S('zebra@example')['body'], "companies/company.php?id=$zc"), 'a company is found by city, KvK number and email');
    check(str_contains($S('ZZQ-INV-1')['body'], "invoices/invoice.php?id=$zi"), 'an invoice is found by number');
    check(str_contains($S('ZZQ-T-1')['body'], "tickets/ticket.php?id=$zt") && str_contains($S('crossing')['body'], "tickets/ticket.php?id=$zt"), 'a ticket is found by reference and by subject');
    $n = $S('ZZQ 100%')['body'];
    check(str_contains($n, "companies/company.php?id=$pc") && !str_contains($n, "companies/company.php?id=$zc"), 'a typed % is searched literally');
    check(!str_contains($S('ZZQ_Zebra')['body'], "companies/company.php?id=$zc"), 'a typed _ is not a single-character wildcard');
    $w = $S('%')['body'];
    check(str_contains($w, "companies/company.php?id=$pc") && !str_contains($w, "companies/company.php?id=$zc"), 'a lone % finds only text that contains a percent sign');
    $e = $S('qqqq-no-such-thing');
    check($e['code'] === 200 && str_contains($e['body'], 'No results for') && !str_contains($e['body'], 'class="rise rounded-xl border border-line bg-panel shadow-panel" aria-labelledby="h-'), 'no results: a clear empty state and no result groups');
    check(str_contains($S('')['body'], 'Type a name') && $S('')['code'] === 200, 'an empty search shows help text');
    $h = $S("x' OR 1=1 -- ");
    check($h['code'] === 200 && str_contains($h['body'], 'No results for'), 'SQL-looking text finds nothing and causes no error');
    $xs = $S('<script>alert(1)</script>');
    check($xs['code'] === 200 && !str_contains($xs['body'], '<script>alert(1)') && str_contains($xs['body'], '&lt;script&gt;'), 'the search text is escaped when shown back');
    same(http('GET', "$base/search/search.php?q[]=a&q[]=b", ['jar' => $admin])['code'], 200, 'an array q parameter is handled');
    check(str_contains(http('GET', "$base/search/search.php?q=" . str_repeat('a', 500), ['jar' => $admin])['body'], 'maxlength="100"'), 'a very long query is accepted and cut to 100 characters');
    // "View all" appears only when there are more than 8 matches
    $pdo->exec("INSERT INTO companies (name) VALUES " . implode(',', array_map(fn($i) => "('ZZQ Bulk $i')", range(1, 10))));
    $v = $S('ZZQ Bulk')['body'];
    check(str_contains($v, 'View all 10') && str_contains($v, 'companies/companies.php?q=ZZQ+Bulk'), 'more than 8 matches: the group is cut at 8 with a "View all" link to the full list');
    $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZQ Bulk%'");
    // the invoice list search treats % literally as well
    check(!str_contains(http('GET', "$base/invoices/invoices.php?q=%25", ['jar' => $admin])['body'], 'ZZQ-INV-1'), 'the invoice list search no longer treats % as a wildcard');

    // easter eggs are untouched: same elements in the page and the same logic in motion.js
    check(str_contains($top, 'id="creatorPop"') && str_contains($top, 'href="extras/creator.php"') && str_contains($top, 'id="teletolliePop"') && str_contains($top, 'href="extras/teletollie.php"'), 'both easter-egg buttons are still in the page');
    $js = (string) file_get_contents(TESTS_DIR . '/../assets/js/motion.js');
    check(str_contains($js, "toLowerCase() === 'creator'") && str_contains($js, "v.trim() === 'Teletollie'") && str_contains($js, "e.key === 'Enter' && egg"), 'motion.js still holds the exact trigger words and the Enter rule');
    check(str_contains(http('GET', "$base/extras/creator.php", ['jar' => $admin])['body'], 'creator') && http('GET', "$base/extras/teletollie.php", ['jar' => $admin])['code'] === 200, 'extras/creator.php and extras/teletollie.php still open');

    // rate limit
    fresh_window();
    $pdo->exec('DELETE FROM rate_limits');
    $codes = [];
    for ($i = 0; $i < 64; $i++) { $codes[] = http('GET', "$base/search/search.php?q=rl$i", ['jar' => $admin])['code']; }
    check(count(array_filter($codes, fn($c) => $c === 200)) === 60 && count(array_filter($codes, fn($c) => $c === 429)) === 4, 'search is rate limited: 60 requests a minute, then 429', json_encode(array_count_values($codes)));
    $pdo->exec('DELETE FROM rate_limits');
    same(http('GET', "$base/search/search.php?q=zebra", ['jar' => $admin])['code'], 200, 'and it works again once the window has passed');
    $pdo->exec("DELETE FROM activity_logs WHERE action = 'Rate limit exceeded' AND target LIKE '%search/search.php' AND is_demo = 0");
    $cleanSearch();
