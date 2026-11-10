<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 5 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 5: CSV export and import
    // =========================================================
    $cleanCsv = function () use ($pdo) {
        $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZI%'");
        $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer LIKE 'ZZI%')");
        $pdo->exec("DELETE FROM invoices WHERE customer LIKE 'ZZI%'");
        $pdo->exec("DELETE FROM users WHERE email LIKE 'zz.imp%@example.test' OR email LIKE 'zz.csv%@example.test'");
        $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZI%' OR name LIKE '''%ZZI%' OR kvk LIKE '9998%'");
        $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND action IN ('Data exported','Data imported') AND created_at > NOW() - INTERVAL 1 HOUR");
        $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND action = 'Access denied' AND target IN ('import','export:users') AND created_at > NOW() - INTERVAL 1 HOUR");
    };
    $cleanCsv();
    $pdo->exec('DELETE FROM rate_limits');
    section('17. CSV export and import');

    $upload = function (string $jar, string $content, array $extra = [], string $filename = 'data.csv', ?string $csrf = null) use ($base, $pdo) {
        $pdo->exec('DELETE FROM rate_limits');
        $tmp = tempnam(sys_get_temp_dir(), 'csv'); file_put_contents($tmp, $content);
        $csrf ??= csrf_from(http('GET', "$base/system/import.php", ['jar' => $jar])['body']);
        $r = http('POST', "$base/system/import.php", ['jar' => $jar, 'body' => $extra + ['csrf' => $csrf, 'type' => 'companies', 'file' => new CURLFile($tmp, 'text/csv', $filename)]]);
        @unlink($tmp);
        return $r;
    };
    $exp = fn(string $jar, string $type) => http('GET', "$base/system/export.php?type=$type", ['jar' => $jar]);
    $parse = function (string $csv) { $t = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv; $fh = fopen('php://temp', 'w+'); fwrite($fh, $t); rewind($fh); $o = []; while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { $o[] = $r; } return $o; };

    // ---- access
    foreach (['users', 'companies', 'invoices', 'tickets'] as $ty) { same(http('GET', "$base/system/export.php?type=$ty")['code'], 302, "signed out: export of $ty redirects to the login page"); }
    same(http('GET', "$base/system/import.php")['code'], 302, 'signed out: system/import.php redirects');
    foreach (['users', 'companies', 'invoices', 'tickets'] as $ty) { same($exp($user, $ty)['code'], 403, "a normal account gets 403 on the $ty export"); }
    same(http('GET', "$base/system/import.php", ['jar' => $user])['code'], 403, 'a normal account gets 403 on system/import.php');
    same($upload($user, "name\nZZI Sneaky\n", [], 'data.csv', 'x')['code'], 403, 'a normal account can not POST an import');
    same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE name = 'ZZI Sneaky'")->fetchColumn(), 0, 'and nothing was imported');
    check(!str_contains(http('GET', "$base/users/users.php", ['jar' => $user])['body'], 'system/export.php') && str_contains(http('GET', "$base/users/users.php", ['jar' => $admin])['body'], 'system/export.php?type=users'), 'export buttons are shown to admins only');
    foreach (['companies/companies.php' => 'companies', 'tickets/tickets.php' => 'tickets', 'invoices/invoices.php' => 'invoices'] as $pg => $ty) { check(str_contains(http('GET', "$base/$pg", ['jar' => $admin])['body'], "system/export.php?type=$ty"), "the $ty list has an Export CSV button"); }
    check(str_contains(http('GET', "$base/companies/companies.php", ['jar' => $admin])['body'], 'system/import.php?type=companies') && str_contains(http('GET', "$base/users/users.php", ['jar' => $admin])['body'], 'system/import.php?type=users'), 'users and companies have an Import CSV button');
    same($exp($admin, 'nonsense')['code'], 404, 'an unknown export type is 404');
    same(http('GET', "$base/system/export.php", ['jar' => $admin])['code'], 404, 'no export type is 404');

    // ---- export format
    $pdo->exec("INSERT INTO companies (name, address, city, kvk, email, notes) VALUES ('ZZI =HYPERLINK(\"http://evil.test\")', '+1 Street', '-Minus', '99980001', 'zzi1@example.test', '@SUM(A1)'), ('ZZI Plain; \"quoted\" Co', 'Line 1', 'Delft', '99980002', 'zzi2@example.test', 'two\nlines')");
    $e = $exp($admin, 'companies');
    check($e['code'] === 200 && str_contains($e['headers'], 'text/csv') && str_contains($e['headers'], 'attachment; filename="companies-' . date('Ymd') . '.csv"') && str_contains($e['headers'], 'no-store'), 'the export is a CSV download named companies-DATE.csv and is not cached');
    check(str_starts_with($e['body'], "\xEF\xBB\xBF"), 'it starts with a UTF-8 byte-order mark so Excel reads accents correctly');
    $rows = $parse($e['body']);
    same($rows[0], ['name', 'address', 'postcode', 'city', 'country', 'kvk', 'vat_number', 'email', 'phone', 'website', 'notes'], 'the header row has the documented columns');
    $byKvk = []; foreach ($rows as $r) { $byKvk[$r[5] ?? ''] = $r; }
    same($byKvk['99980001'][0], 'ZZI =HYPERLINK("http://evil.test")', 'a name that contains a formula after other text is left as is (only a leading = + - @ is dangerous)');
    $pdo->exec("INSERT INTO companies (name, kvk) VALUES ('=cmd|\'/c calc\'!A0', '99980003'), ('+441234', '99980004'), ('-5', '99980005'), ('@HYPERLINK(1)', '99980006')");
    $rows = $parse($exp($admin, 'companies')['body']);
    $byKvk = []; foreach ($rows as $r) { $byKvk[$r[5] ?? ''] = $r; }
    same([$byKvk['99980003'][0], $byKvk['99980004'][0], $byKvk['99980005'][0], $byKvk['99980006'][0]], ['\'=cmd|\'/c calc\'!A0', "'+441234", "'-5", "'@HYPERLINK(1)"], 'cells starting with = + - or @ are neutralised with a leading apostrophe');
    same([$byKvk['99980001'][1], $byKvk['99980001'][3], $byKvk['99980001'][10]], ["'+1 Street", "'-Minus", "'@SUM(A1)"], 'the same goes for address, city and notes');
    same([$byKvk['99980002'][0], $byKvk['99980002'][10]], ['ZZI Plain; "quoted" Co', "two\nlines"], 'quotes, semicolons and line breaks inside cells survive');
    same((int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn(), count($rows) - 1, 'one CSV row per company');
    same(csv_cell('=1+1') . csv_cell("	x") . csv_cell('ok') . csv_cell(5), "'=1+1'	xok5", 'csv_cell: formula and tab starts are neutralised, normal text and numbers untouched');
    same(csv_uncell("'=1+1"), '=1+1', 'csv_uncell undoes it');
    same(csv_uncell("'hello"), "'hello", 'but a normal leading apostrophe stays');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Data exported' AND target LIKE 'companies · %' AND is_demo = 0")->fetchColumn() >= 2, 'every export is written to the activity log with its type and row count');

    // ---- round trip: companies
    $before = $pdo->query("SELECT name, address, postcode, city, country, kvk, vat_number, email, phone, website, notes FROM companies WHERE kvk LIKE '9998%' ORDER BY kvk")->fetchAll(PDO::FETCH_ASSOC);
    $csv = $exp($admin, 'companies')['body'];
    $total = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    $pdo->exec("DELETE FROM companies WHERE kvk LIKE '9998%'");
    $r = $upload($admin, $csv);
    check($r['code'] === 200 && preg_match('/(\d+) added<\/span> · (\d+) skipped as duplicate · <span[^>]*>(\d+) rejected/', $r['body'], $m) && (int) $m[1] === 6 && (int) $m[2] === $total - 6 && (int) $m[3] === 0, 'round trip: importing the full export re-adds the 6 deleted companies and skips all others as duplicates', $m[0] ?? 'no summary');
    $after = $pdo->query("SELECT name, address, postcode, city, country, kvk, vat_number, email, phone, website, notes FROM companies WHERE kvk LIKE '9998%' ORDER BY kvk")->fetchAll(PDO::FETCH_ASSOC);
    same($after, $before, 'round trip: every field comes back exactly as it was, including formula-looking names');
    $r = $upload($admin, $csv);
    check(preg_match('/(\d+) added<\/span> · (\d+) skipped/', $r['body'], $m) && (int) $m[1] === 0 && (int) $m[2] === $total, 'importing the same file again adds nothing: everything is skipped as a duplicate');
    same((int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn(), $total, 'and the number of companies is unchanged');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Data imported' AND target LIKE 'companies · 6 added%'")->fetchColumn() === 1, 'the import is in the activity log with its counts');

    // ---- round trip: users
    $pdo->exec("INSERT INTO companies (name) VALUES ('ZZI Users Co')");
    $uco = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZI Users Co'")->fetchColumn();
    $pdo->prepare("INSERT INTO users (name, email, role, status, country, company_id, job_title, phone, last_seen, created_at) VALUES ('ZZI User One', 'zz.csv1@example.test', 'support', 'pending', 'Belgium', ?, 'Boss', '010 1', NOW(), NOW())")->execute([$uco]);
    $csvU = $exp($admin, 'users')['body'];
    check(str_contains($csvU, 'zz.csv1@example.test') && str_contains($csvU, 'ZZI Users Co'), 'the users export has the email and the company name');
    $pdo->exec("DELETE FROM users WHERE email = 'zz.csv1@example.test'");
    $r = $upload($admin, $csvU, ['type' => 'users']);
    check(preg_match('/(\d+) added<\/span> · (\d+) skipped/', $r['body'], $m) && (int) $m[1] === 1, 'round trip: the deleted user is re-added and the others are skipped');
    $u1 = $pdo->query("SELECT role, status, country, company_id, job_title, phone, logins FROM users WHERE email = 'zz.csv1@example.test'")->fetch(PDO::FETCH_ASSOC);
    same(array_map('strval', $u1), ['role' => 'support', 'status' => 'pending', 'country' => 'Belgium', 'company_id' => (string) $uco, 'job_title' => 'Boss', 'phone' => '010 1', 'logins' => '0'], 'the user comes back with role, status, country, company, job title and phone');

    // ---- invoices and tickets export
    $pdo->prepare("INSERT INTO invoices (number, customer, email, customer_address, issue_date, due_date, subtotal_cents, vat_cents, total_cents, created_at) VALUES ('ZZI-1', 'ZZI Customer', 'c@example.test', 'x', CURDATE(), CURDATE() + INTERVAL 30 DAY, 1000, 210, 1210, NOW())")->execute();
    $pdo->exec("INSERT INTO tickets (reference, subject, description, created_at, updated_at) VALUES ('ZZI-T-1', 'ZZI Ticket', 'x', NOW(), NOW())");
    $inv = $parse($exp($admin, 'invoices')['body']);
    $row = array_values(array_filter($inv, fn($r) => ($r[0] ?? '') === 'ZZI-1'))[0] ?? [];
    same([$inv[0][8] ?? '', $row[6] ?? '', $row[7] ?? '', $row[8] ?? '', $row[10] ?? '', $row[11] ?? ''], ['total', '10.00', '2.10', '12.10', '12.10', 'open'], 'the invoices export has subtotal, VAT, total and balance in plain euros, and the state');
    $tk = $parse($exp($admin, 'tickets')['body']);
    check(($tk[0][0] ?? '') === 'reference' && count(array_filter($tk, fn($r) => ($r[0] ?? '') === 'ZZI-T-1' && $r[1] === 'ZZI Ticket')) === 1, 'the tickets export has the reference and subject');

    // ---- import: bad files
    $note = fn(string $body) => (preg_match('/role="alert"[^>]*>([^<]+)</', $body, $m) ? html_entity_decode($m[1]) : '');
    $none = fn() => (int) $pdo->query("SELECT COUNT(*) FROM companies WHERE name LIKE 'ZZI Bad%'")->fetchColumn();
    same(http('POST', "$base/system/import.php", ['jar' => $admin, 'body' => ['type' => 'companies', 'csrf' => 'wrong']])['code'], 403, 'an import with a wrong CSRF token is 403');
    same($upload($admin, "name\nZZI Bad token\n", [], 'data.csv', 'wrong-token')['code'], 403, 'an upload with a wrong CSRF token is 403');
    check(str_contains($note($upload($admin, "name\nZZI Bad ext\n", [], 'data.exe')['body']), 'Only .csv files'), 'a file that is not .csv is refused');
    check(str_contains($note($upload($admin, "name\nZZI Bad ext\n", [], 'data.csv.php')['body']), 'Only .csv files'), 'a double extension is refused');
    $r = http('POST', "$base/system/import.php", ['jar' => $admin, 'body' => ['csrf' => csrf_from(http('GET', "$base/system/import.php", ['jar' => $admin])['body']), 'type' => 'companies']]);
    check(str_contains($note($r['body']), 'Choose a CSV file first'), 'no file chosen: asked to choose one');
    check(str_contains($note($upload($admin, '')['body']), 'The file is empty'), 'an empty file is refused');
    check(str_contains($note($upload($admin, "name,city\n")['body']), 'no rows'), 'a file with only a header is refused');
    check(str_contains($note($upload($admin, "city,email\nDelft,a@b.test\n")['body']), 'needs a "name" column'), 'a file without the required name column is refused and lists the columns it found');
    check(str_contains($note($upload($admin, "name,email\nx\0y,a@b.test\n")['body']), 'UTF-8'), 'a binary file (NUL byte) is refused');
    check(str_contains($note($upload($admin, "name\n\xC9\xE9 caf\xE9 \xFF\n")['body']), 'UTF-8'), 'text that is not valid UTF-8 is refused with a hint to save as CSV UTF-8');
    check(str_contains($note($upload($admin, "name\n" . str_repeat("ZZI Bad row\n", 1001))['body']), 'at most 1000 rows'), 'more than 1000 rows is refused');
    check(str_contains($note($upload($admin, "name\n" . str_repeat('x', 210000))['body']), 'too large'), 'a file over 200 KB is refused with the limit stated');
    $big = $upload($admin, "name\n" . str_repeat('x', 300000));
    check(in_array($big['code'], [413, 200], true) && ($big['code'] === 413 || str_contains($note($big['body']), 'too large')), 'a file over Apache\'s 256 KB request limit is refused (413 or the same message)', 'code ' . $big['code']);
    same($none(), 0, 'none of the refused files created a company');
    check(!str_contains(http('GET', "$base/system/import.php", ['jar' => $admin])['body'], 'ZZI Bad'), 'refused files are not kept or shown');
    check(!str_contains((string) file_get_contents(TESTS_DIR . '/../system/import.php'), 'move_uploaded_file') && !str_contains((string) file_get_contents(TESTS_DIR . '/../system/import.php'), 'include $f') , 'system/import.php never moves, stores or includes the uploaded file');

    // ---- import: mixed good and bad rows, delimiters, quoting
    $mixed = "\xEF\xBB\xBFname;kvk;email;city;website\n"
        . "ZZI Good One;99980011;g1@example.test;Delft;good1.example.test\n"
        . "ZZI Bad Kvk;123;;Delft;\n"
        . ";99980012;;;\n"
        . "ZZI Bad Mail;99980013;not-an-email;;\n"
        . "ZZI Good Two;;g2@example.test;\"Den Haag\";\n"
        . "ZZI Dup Kvk;99980011;;;\n"
        . "ZZI Dup Mail;;G2@EXAMPLE.TEST;;\n"
        . "ZZI Ragged;99980014;;Delft;;extra;cells\n"
        . "ZZI Bad Site;99980015;;;http://exa mple\n"
        . "\n"
        . "ZZI Good Three;99980016;;\"Multi\nline\";\n";
    $r = $upload($admin, $mixed);
    check(preg_match('/(\d+) added<\/span> · (\d+) skipped as duplicate · <span[^>]*>(\d+) rejected/', $r['body'], $m) && [(int) $m[1], (int) $m[2], (int) $m[3]] === [3, 2, 5], 'a mixed semicolon file with a BOM: 3 added, 2 duplicates skipped, 5 rejected', $m[0] ?? 'no summary');
    check(str_contains($r['body'], 'Line 3: ') && str_contains($r['body'], 'Line 4: ') && str_contains($r['body'], 'Line 9: the row has') && str_contains($r['body'], 'KvK number 99980011 already exists') && str_contains($r['body'], 'g2@example.test already belongs'), 'every problem row is listed with its line number and the reason');
    same($pdo->query("SELECT GROUP_CONCAT(name ORDER BY name) FROM companies WHERE name LIKE 'ZZI Good%'")->fetchColumn(), 'ZZI Good One,ZZI Good Three,ZZI Good Two', 'the good rows were added even though other rows failed');
    same($pdo->query("SELECT website FROM companies WHERE name = 'ZZI Good One'")->fetchColumn(), 'https://good1.example.test', 'imported values are validated and normalised like the form does');
    same($pdo->query("SELECT city FROM companies WHERE name = 'ZZI Good Three'")->fetchColumn(), "Multi\nline", 'a quoted cell with a line break is read as one cell');
    same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE name LIKE 'ZZI Bad%' OR name LIKE 'ZZI Dup%' OR name = 'ZZI Ragged'")->fetchColumn(), 0, 'no rejected or duplicate row was saved');
    $xs = $upload($admin, "name\n\"ZZI <script>alert(1)</script>\"\n");
    $xl = http('GET', "$base/companies/companies.php?q=ZZI+%3Cscript", ['jar' => $admin])['body'];
    check(!str_contains($xl, '<script>alert(1)') && str_contains($xl, '&lt;script&gt;'), 'markup in an imported name is escaped when shown');
    // users: bad rows
    $r = $upload($admin, "name,email,role,status,company\nZZI Ok,zz.imp1@example.test,viewer,active,ZZI Users Co\nZZI Role,zz.imp2@example.test,superuser,active,\nZZI Stat,zz.imp3@example.test,viewer,gone,\nZZI Comp,zz.imp4@example.test,viewer,active,No Such Company\nZZI Mail,nope,viewer,active,\nZZI Dupe,zz.csv1@example.test,viewer,active,\n,zz.imp5@example.test,,,\n", ['type' => 'users']);
    check(preg_match('/(\d+) added<\/span> · (\d+) skipped as duplicate · <span[^>]*>(\d+) rejected/', $r['body'], $m) && [(int) $m[1], (int) $m[2], (int) $m[3]] === [1, 1, 5], 'users: 1 added, 1 duplicate email skipped, 5 rejected (role, status, unknown company, email, empty name)', $m[0] ?? 'no summary');
    check(str_contains($r['body'], 'is not admin, editor, support or viewer') && str_contains($r['body'], 'does not exist: import the companies first'), 'user problems say what is wrong');
    same($pdo->query("SELECT GROUP_CONCAT(email) FROM users WHERE email LIKE 'zz.imp%'")->fetchColumn(), 'zz.imp1@example.test', 'only the valid user was saved');
    same((int) $pdo->query("SELECT company_id FROM users WHERE email = 'zz.imp1@example.test'")->fetchColumn(), $uco, 'an imported user is linked to the company named in the file');

    // ---- export is rate limited like report downloads
    fresh_window();
    $pdo->exec('DELETE FROM rate_limits');
    $codes = [];
    for ($i = 0; $i < 18; $i++) { $codes[] = $exp($admin, 'tickets')['code']; }
    check(count(array_filter($codes, fn($c) => $c === 200)) === 15 && count(array_filter($codes, fn($c) => $c === 429)) === 3, 'exports are rate limited: 15 a minute, then 429', json_encode(array_count_values($codes)));
    $pdo->exec('DELETE FROM rate_limits');
    $pdo->exec("DELETE FROM activity_logs WHERE action = 'Rate limit exceeded' AND target LIKE '%system/export.php' AND is_demo = 0");
    $cleanCsv();
