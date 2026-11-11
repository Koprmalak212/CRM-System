<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 1 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 1: companies
    // =========================================================
    $root = new PDO('mysql:host=127.0.0.1;dbname=ops_console;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $post = function (string $jar, string $page, array $data, ?string $formPage = null) use ($base, $pdo) {
        $pdo->exec('DELETE FROM rate_limits');
        $tok = csrf_from(http('GET', "$base/" . ($formPage ?? $page), ['jar' => $jar])['body']);
        return http('POST', "$base/$page", ['jar' => $jar, 'body' => http_build_query($data + ['csrf' => $tok])]);
    };
    $cleanCompanies = function () use ($pdo) {
        $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer LIKE 'ZZ Test%')");
        $pdo->exec("DELETE FROM invoices WHERE customer LIKE 'ZZ Test%'");
        $pdo->exec("DELETE FROM users WHERE email = 'zz.company.test@example.test'");
        $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZ Test%' OR kvk IN ('99990001','99990002')");
        $pdo->exec("DELETE FROM activity_logs WHERE (action LIKE 'Company %' AND target LIKE 'ZZ Test%') OR (action LIKE 'User %company%' AND target LIKE 'ZZ Test%') OR (action = 'Invoice created' AND target LIKE '%ZZ Test%')");
    };
    $pdo->exec("DELETE FROM users WHERE email LIKE 'zz.%@example.test'");   // leftovers of an earlier aborted run
    $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZ Test%'");
    $cleanCompanies();
    $pdo->exec('DELETE FROM rate_limits');

    section('10. Companies: database, migration of old data');
    $colExists = fn(string $t, string $c) => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$t' AND column_name = '$c'")->fetchColumn() === 1;
    check($colExists('users', 'company_id') && !$colExists('users', 'company') && $colExists('invoices', 'company_id') && $colExists('companies', 'kvk') && $colExists('companies', 'is_demo'), 'users.company_id, invoices.company_id and the companies table exist; the old text column is gone');
    // an OLD-style database (users.company as text) in a scratch database, then the real migrate.php on it
    $root->exec('DROP DATABASE IF EXISTS ops_console_migtest'); $root->exec('CREATE DATABASE ops_console_migtest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $root->exec('USE ops_console_migtest');
    $root->exec((string) file_get_contents(TESTS_DIR . '/../database/schema.sql'));
    $root->exec('ALTER TABLE users DROP FOREIGN KEY fk_user_company, DROP COLUMN company_id');
    $root->exec('ALTER TABLE invoices DROP FOREIGN KEY fk_inv_recurring, DROP INDEX uq_inv_recurring, DROP COLUMN recurring_id');   // later stages point at invoices and companies,
    $root->exec('DROP TABLE recurring_invoices');                                                                                    // which the old database did not have
    $root->exec('ALTER TABLE invoices DROP FOREIGN KEY fk_inv_company, DROP COLUMN company_id');
    $root->exec('DROP TABLE comm_log, short_links, ticket_comments, tickets, appointments');   // later stages point at companies, which the old database did not have
    $root->exec('DROP TABLE companies');
    $root->exec("ALTER TABLE users ADD COLUMN company VARCHAR(120) NOT NULL DEFAULT '', ADD KEY idx_company (company)");
    $ui = $root->prepare("INSERT INTO users (name, email, country, last_seen, created_at, company, is_demo) VALUES (?,?,?,NOW(),NOW(),?,?)");
    foreach ([['A', 'a@x.test', 'Acme BV', 0], ['B', 'b@x.test', 'Acme BV', 0], ['C', 'c@x.test', 'Zeta & Co', 1], ['D', 'd@x.test', '', 0]] as [$n, $m, $co, $dm]) { $ui->execute([$n, $m, 'NL', $co, $dm]); }
    $run = function () {
        $env = array_merge(getenv(), ['MIGRATE_DB' => 'ops_console_migtest']);
        $p = proc_open([PHP_BINARY, TESTS_DIR . '/../database/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, $env);
        $o = stream_get_contents($pp[1]) . stream_get_contents($pp[2]);
        proc_close($p);
        return $o;
    };
    $out = $run();
    check(str_contains($out, 'Database is up to date.') && str_contains($out, '2 companies created'), 'migrate.php on an old database creates one company per distinct name', trim($out));
    $mm = $root->query('SELECT u.name, c.name co FROM users u LEFT JOIN companies c ON c.id = u.company_id ORDER BY u.name')->fetchAll(PDO::FETCH_KEY_PAIR);
    same($mm, ['A' => 'Acme BV', 'B' => 'Acme BV', 'C' => 'Zeta & Co', 'D' => null], 'every user keeps their company; a user without one stays without');
    same((int) $root->query('SELECT COUNT(*) FROM companies WHERE name = "Acme BV"')->fetchColumn(), 1, 'two users of one company share a single company row');
    same((int) $root->query('SELECT is_demo FROM companies WHERE name = "Zeta & Co"')->fetchColumn(), 1, 'the demo tag follows the users');
    check(!(int) $root->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='ops_console_migtest' AND table_name='users' AND column_name='company'")->fetchColumn(), 'the old text column is dropped after migrating');
    $out2 = $run();
    check(str_contains($out2, 'Database is up to date.') && !str_contains($out2, 'migrated') && (int) $root->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 2, 'running migrate.php again changes nothing');
    $root->exec('DROP DATABASE ops_console_migtest'); $root->exec('USE ops_console');

    // =========================================================
    section('11. Companies: add, validate, edit, search');
    same(http('GET', "$base/companies/companies.php")['code'], 302, 'signed out: companies/companies.php redirects to the login page');
    same(http('GET', "$base/companies/company.php?id=1")['code'], 302, 'signed out: companies/company.php redirects');
    same(http('GET', "$base/companies/company_edit.php")['code'], 302, 'signed out: companies/company_edit.php redirects');
    same(http('POST', "$base/companies/company_edit.php", ['body' => 'name=ZZ+Test+Evil'])['code'], 302, 'signed out: a forged POST is refused');
    $form = ['action' => 'save', 'id' => '0', 'name' => 'ZZ Test Alpha', 'address' => 'Teststraat 1', 'postcode' => '3011 ad', 'city' => 'Rotterdam', 'country' => '', 'kvk' => '99990001', 'vat_number' => 'nl 9999.00.01.B01', 'email' => 'alpha@example.test', 'phone' => '010 111 2222', 'website' => 'alpha.example.test', 'notes' => 'Line one'];
    $noTok = http('POST', "$base/companies/company_edit.php", ['jar' => $admin, 'body' => http_build_query($form)]);
    same($noTok['code'], 403, 'a POST without a CSRF token is 403');
    same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE name = 'ZZ Test Alpha'")->fetchColumn(), 0, 'and nothing was saved');
    foreach ([['Enter the company name', ['name' => '']], ['KvK number has 8 digits', ['kvk' => '123']], ['email address is not valid', ['email' => 'not-an-email']], ['VAT number starts with', ['vat_number' => 'xx']], ['website address is not valid', ['website' => 'http://exa mple']], ['at most 120', ['name' => str_repeat('x', 121)]]] as [$msg, $bad]) {
        $r = $post($admin, 'companies/company_edit.php', $bad + $form);
        check($r['code'] === 200 && str_contains($r['body'], $msg), 'validation: ' . $msg);
    }
    same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE name = 'ZZ Test Alpha' OR kvk = '99990001'")->fetchColumn(), 0, 'nothing was saved while validation failed');
    $r = $post($admin, 'companies/company_edit.php', $form);
    same($r['code'], 302, 'a valid company is saved and redirects');
    $co = $pdo->query("SELECT * FROM companies WHERE name = 'ZZ Test Alpha'")->fetch();
    check((bool) $co && $co['postcode'] === '3011 AD' && $co['vat_number'] === 'NL99990001B01' && $co['website'] === 'https://alpha.example.test' && $co['country'] === 'Netherlands', 'values are normalised: postcode upper case, VAT tidied, https added, country defaulted');
    $cid = (int) $co['id'];
    check(str_contains($r['headers'], "companies/company.php?id=$cid"), 'it redirects to the new company page');
    $page = http('GET', "$base/companies/company.php?id=$cid", ['jar' => $admin])['body'];
    check(str_contains($page, 'ZZ Test Alpha') && str_contains($page, 'Teststraat 1, 3011 AD Rotterdam') && str_contains($page, '99990001') && str_contains($page, 'mailto:alpha@example.test'), 'the company page shows its details');
    $dup = $post($admin, 'companies/company_edit.php', ['name' => 'ZZ Test Beta', 'kvk' => '99990001'] + $form);
    check($dup['code'] === 200 && str_contains($dup['body'], 'already belongs to ZZ Test Alpha'), 'a duplicate KvK number is refused');
    $r = $post($admin, 'companies/company_edit.php', ['id' => (string) $cid, 'name' => 'ZZ Test Alpha Renamed', 'city' => 'Delft'] + $form, "companies/company_edit.php?id=$cid");
    same($r['code'], 302, 'editing saves');
    same($pdo->query("SELECT CONCAT(name, '|', city) FROM companies WHERE id = $cid")->fetchColumn(), 'ZZ Test Alpha Renamed|Delft', 'the changes are stored on the same row');
    same(http('GET', "$base/companies/company.php?id=999999", ['jar' => $admin])['code'], 404, 'an unknown company is 404');
    same(http('GET', "$base/companies/company.php?id=abc", ['jar' => $admin])['code'], 404, 'a non-numeric id is 404');
    same(http('GET', "$base/companies/company_edit.php?id=999999", ['jar' => $admin])['code'], 404, 'editing an unknown company is 404');
    $xss = $post($admin, 'companies/company_edit.php', ['name' => 'ZZ Test <script>alert(1)</script>', 'kvk' => '', 'vat_number' => '', 'notes' => '<img src=x onerror=alert(1)>'] + $form);
    $xid = (int) $pdo->query("SELECT id FROM companies WHERE name LIKE 'ZZ Test <script>%'")->fetchColumn();
    $xp = http('GET', "$base/companies/company.php?id=$xid", ['jar' => $admin])['body'] . http('GET', "$base/companies/companies.php?q=ZZ+Test", ['jar' => $admin])['body'];
    check($xid > 0 && !str_contains($xp, '<script>alert(1)') && !str_contains($xp, '<img src=x') && str_contains($xp, '&lt;script&gt;'), 'markup in a company name and notes is escaped everywhere');
    $s = http('GET', "$base/companies/companies.php?q=" . urlencode('ZZ Test Alpha Renamed'), ['jar' => $admin])['body'];
    check(str_contains($s, 'ZZ Test Alpha Renamed') && !str_contains($s, 'No companies match'), 'search finds a company by name');
    check(str_contains(http('GET', "$base/companies/companies.php?q=Delft", ['jar' => $admin])['body'], 'ZZ Test Alpha Renamed'), 'search finds it by city');
    check(str_contains(http('GET', "$base/companies/companies.php?q=99990001", ['jar' => $admin])['body'], 'ZZ Test Alpha Renamed'), 'search finds it by KvK number');
    check(str_contains(http('GET', "$base/companies/companies.php?q=" . urlencode('%'), ['jar' => $admin])['body'], 'No companies match'), 'a typed % is searched literally, not as a wildcard');
    check(str_contains(http('GET', "$base/companies/companies.php?q=" . urlencode("x' OR 1=1 -- "), ['jar' => $admin])['body'], 'No companies match'), 'SQL-looking search text finds nothing and causes no error');
    foreach (['name', 'city', 'kvk', 'users', 'invoices'] as $so) {
        foreach (['asc', 'desc'] as $di) { same(http('GET', "$base/companies/companies.php?sort=$so&dir=$di", ['jar' => $admin])['code'], 200, "sort by $so $di works"); }
    }
    same(http('GET', "$base/companies/companies.php?sort=" . urlencode('name; DROP TABLE companies') . '&page=-5&q[]=x', ['jar' => $admin])['code'], 200, 'a hostile sort value, negative page and array q are handled');
    check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'href="companies/companies.php"'), 'the sidebar has a Companies item');
    if ((int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() > 15) {
        check(str_contains(http('GET', "$base/companies/companies.php?page=2", ['jar' => $admin])['body'], 'Page 2 of'), 'pagination works when there are more than 15 companies');
    }

    // =========================================================
    section('12. Companies: users, invoices and safe deletion');
    $pdo->prepare("INSERT INTO users (name, email, country, last_seen, created_at, company_id) VALUES ('ZZ Test Person', 'zz.company.test@example.test', 'Netherlands', NOW(), NOW(), NULL)")->execute();
    $uid = (int) $pdo->query("SELECT id FROM users WHERE email = 'zz.company.test@example.test'")->fetchColumn();
    $post($admin, 'users/user.php', ['id' => (string) $uid, 'action' => 'company', 'company_id' => (string) $cid], "users/user.php?id=$uid");
    same((int) $pdo->query("SELECT company_id FROM users WHERE id = $uid")->fetchColumn(), $cid, 'a user can be assigned to a company from their own page');
    $up = http('GET', "$base/users/user.php?id=$uid", ['jar' => $admin])['body'];
    check(str_contains($up, "companies/company.php?id=$cid") && str_contains($up, 'ZZ Test Alpha Renamed'), 'the user page links to the company');
    $ul = http('GET', "$base/users/users.php?q=ZZ+Test+Person", ['jar' => $admin])['body'];
    check(str_contains($ul, "companies/company.php?id=$cid"), 'the users list shows the company as a link');
    check(str_contains(http('GET', "$base/users/users.php?company=$cid", ['jar' => $admin])['body'], 'ZZ Test Person'), 'the users list can be filtered by company');
    check(str_contains(http('GET', "$base/users/users.php?q=Alpha+Renamed", ['jar' => $admin])['body'], 'ZZ Test Person'), 'searching users by company name finds its people');
    check(!str_contains(http('GET', "$base/users/users.php?company=999999", ['jar' => $admin])['body'], 'ZZ Test Person'), 'filtering by another company hides them');
    check(str_contains(http('GET', "$base/companies/company.php?id=$cid", ['jar' => $admin])['body'], 'ZZ Test Person'), 'the company page lists its users');
    $post($admin, 'users/user.php', ['id' => (string) $uid, 'action' => 'company', 'company_id' => '999999'], "users/user.php?id=$uid");
    same((int) $pdo->query("SELECT company_id FROM users WHERE id = $uid")->fetchColumn(), $cid, 'assigning a company that does not exist changes nothing');

    $np = http('GET', "$base/invoices/invoice_new.php?company=$cid", ['jar' => $admin])['body'];
    check(str_contains($np, 'value="ZZ Test Alpha Renamed"') && str_contains($np, 'value="alpha@example.test"') && str_contains($np, 'value="Teststraat 1, 3011 AD Delft"'), 'invoices/invoice_new.php?company= fills in the customer fields from the company');
    check(str_contains($np, 'data-address="Teststraat 1, 3011 AD Delft"') && str_contains($np, 'id="company_id"'), 'the invoice form has a company picker carrying each company\'s details');
    $inv = ['customer' => 'ZZ Test Alpha Renamed', 'email' => 'alpha@example.test', 'address' => 'Teststraat 1, 3011 AD Delft', 'issue_date' => date('Y-m-d'), 'terms' => '14', 'vat' => '21', 'desc' => ['Test line'], 'qty' => ['2'], 'price' => ['10,00'], 'company_id' => (string) $cid];
    $r = $post($admin, 'invoices/invoice_new.php', $inv);
    same($r['code'], 302, 'an invoice for a company is created');
    $iv = $pdo->query("SELECT id, company_id, number FROM invoices WHERE customer = 'ZZ Test Alpha Renamed' ORDER BY id DESC LIMIT 1")->fetch();
    same((int) $iv['company_id'], $cid, 'the invoice is stored with the company link');
    check(str_contains(http('GET', "$base/invoices/invoice.php?id=" . $iv['id'], ['jar' => $admin])['body'], "companies/company.php?id=$cid"), 'the invoice page links back to the company');
    $cp = http('GET', "$base/companies/company.php?id=$cid", ['jar' => $admin])['body'];
    check(str_contains($cp, $iv['number']) && str_contains($cp, 'outstanding'), 'the company page lists the invoice and the outstanding amount');
    $r = $post($admin, 'invoices/invoice_new.php', ['company_id' => '999999', 'customer' => 'ZZ Test Orphan'] + $inv);
    check($r['code'] === 200 && str_contains($r['body'], 'That company does not exist'), 'an invoice with a company that does not exist is refused');
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE customer = 'ZZ Test Orphan'")->fetchColumn(), 0, 'and no invoice is created');
    $post($admin, 'invoices/invoice_new.php', ['company_id' => '0', 'customer' => 'ZZ Test Loose'] + $inv);
    same($pdo->query("SELECT company_id FROM invoices WHERE customer = 'ZZ Test Loose'")->fetchColumn(), null, 'an invoice without a company stays unlinked (the picker is optional)');

    check(str_contains(http('GET', "$base/companies/company_edit.php?id=$cid&delete=1", ['jar' => $admin])['body'], 'can not be deleted'), 'a company with users shows that it can not be deleted');
    $post($admin, 'companies/company_edit.php', ['id' => (string) $cid, 'action' => 'delete'], "companies/company_edit.php?id=$cid");
    same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE id = $cid")->fetchColumn(), 1, 'a forged delete of a company with users is refused: it still exists');
    try { $pdo->exec("DELETE FROM companies WHERE id = $cid"); $fk = false; } catch (PDOException $e) { $fk = true; }
    check($fk, 'the database itself also refuses to delete a company that has users (foreign key)');
    $post($admin, 'users/user.php', ['id' => (string) $uid, 'action' => 'company', 'company_id' => '0'], "users/user.php?id=$uid");
    same($pdo->query("SELECT company_id FROM users WHERE id = $uid")->fetchColumn(), null, 'a user can be taken out of a company');
    $g = http('GET', "$base/companies/company_edit.php?id=$cid&delete=1", ['jar' => $admin])['body'];
    check(str_contains($g, 'Yes, delete the company') && (int) $pdo->query("SELECT COUNT(*) FROM companies WHERE id = $cid")->fetchColumn() === 1, 'an empty company first asks for confirmation; opening that page deletes nothing');
    $post($admin, 'companies/company_edit.php', ['id' => (string) $cid, 'action' => 'delete'], "companies/company_edit.php?id=$cid");
    same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE id = $cid")->fetchColumn(), 0, 'after confirming, the company is deleted');
    same($pdo->query('SELECT company_id FROM invoices WHERE id = ' . (int) $iv['id'])->fetchColumn(), null, 'its invoice is kept and only loses the link');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('Company added','Company updated','Company deleted','User moved to company')")->fetchColumn() >= 4, 'adding, editing, moving a user and deleting are in the activity log');

    section('13. Companies: demo data');
    if (demo_loaded($pdo)) {
        same(demo_counts($pdo, 1)['companies'], 14, 'with demo data loaded there are 14 demo companies');
        same((int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_demo = 1 AND company_id IS NULL')->fetchColumn(), 0, 'every demo user belongs to a company');
        same((int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE is_demo = 1 AND company_id IS NULL')->fetchColumn(), 0, 'every demo invoice is linked to a company');
        same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE is_demo = 1 AND (address = '' OR kvk = '' OR vat_number = '' OR email = '')")->fetchColumn(), 0, 'demo companies have an address, KvK number, VAT number and email');
        same((int) $pdo->query('SELECT COUNT(*) FROM invoices i JOIN companies c ON c.id = i.company_id WHERE i.is_demo = 1 AND i.customer <> c.name')->fetchColumn(), 0, 'demo invoices are billed to the company they link to');
    }
    $cleanCompanies();
