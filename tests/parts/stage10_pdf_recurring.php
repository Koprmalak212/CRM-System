<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 10 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 10: PDF invoices and recurring invoices
    // =========================================================
    require_once TESTS_DIR . '/../includes/pdf.php';
    require_once TESTS_DIR . '/../includes/recurring.php';
    require_once TESTS_DIR . '/../includes/settings/company.php';   // iban_valid()
    $cpBackup = $pdo->query('SELECT * FROM company_profile WHERE id = 1')->fetch() ?: null;
    $cleanPdf = function () use ($pdo, $cpBackup) {
        $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer LIKE 'ZZR%')");
        $pdo->exec("DELETE FROM invoices WHERE customer LIKE 'ZZR%'");
        $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZR%'");   // its schedules go with it
        $pdo->exec('DELETE FROM company_profile');
        if ($cpBackup) { $pdo->prepare('INSERT INTO company_profile (id, name, address1, address2, address3, kvk, btw_number, iban, email, phone, updated_at) VALUES (1,?,?,?,?,?,?,?,?,?,?)')->execute([$cpBackup['name'], $cpBackup['address1'], $cpBackup['address2'], $cpBackup['address3'], $cpBackup['kvk'], $cpBackup['btw_number'], $cpBackup['iban'], $cpBackup['email'], $cpBackup['phone'], $cpBackup['updated_at']]); }
        $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND (action IN ('Company details saved','Invoice PDF downloaded','Recurring invoice added','Recurring invoice paused','Recurring invoice resumed','Recurring invoice deleted') OR target LIKE '%ZZR%')");
        $pdo->exec('DELETE FROM rate_limits');
    };
    $cleanPdf();
    $pdo->exec('DELETE FROM rate_limits');

    section('28. PDF invoices: the writer');
    same(round(Pdf::width('Hello', 10), 2), 22.78, 'text width follows the Helvetica table (Hello at 10 pt = 22.78 pt)');
    check(Pdf::width('Hello', 10, true) > Pdf::width('Hello', 10, false), 'bold text is wider than regular text');
    same(bin2hex(Pdf::encode("€ 5é")), '802035e9', 'euro sign and accents are converted to the Windows-1252 bytes the PDF font uses');
    $p = new Pdf('Test (doc)'); $p->addPage(); $p->text(50, 100, 'Parens (a) and back\\slash', 12); $p->addPage(); $p->text(50, 100, 'Page two', 12, true, 'right');
    $raw = $p->output();
    check(str_starts_with($raw, '%PDF-1.4') && str_ends_with(trim($raw), '%%EOF'), 'the file starts with a PDF header and ends with %%EOF');
    check(str_contains($raw, '(Parens \\(a\\) and back\\\\slash) Tj') && str_contains($raw, '/Title (Test \\(doc\\))'), 'parentheses and backslashes in text and title are escaped');
    preg_match('/startxref\n(\d+)\n/', $raw, $m);
    check(isset($m[1]) && substr($raw, (int) $m[1], 4) === 'xref', 'startxref points at the cross-reference table');
    preg_match_all('/^(\d{10}) 00000 n $/m', $raw, $xo);
    $okOffsets = true; foreach ($xo[1] as $i => $off) { if (substr($raw, (int) $off, strlen((string) ($i + 1)) + 6) !== ($i + 1) . ' 0 obj') { $okOffsets = false; } }
    check(count($xo[1]) >= 8 && $okOffsets, 'every cross-reference offset points at its object (' . count($xo[1]) . ' objects)');
    preg_match_all('/\/Length (\d+) >>\nstream\n(.*?)endstream/s', $raw, $st, PREG_SET_ORDER);
    check(count($st) === 2 && array_sum(array_map(fn($x) => (int) ($x[1] === (string) strlen($x[2])), $st)) === 2, 'both page streams declare exactly their own length');
    check(str_contains($raw, '/Count 2') && substr_count($raw, '/Type /Page ') === 2, 'two pages are declared and present');

    section('29. PDF invoices: contents and download');
    $pdo->exec("INSERT INTO companies (name, address, postcode, city, email) VALUES ('ZZR Pdf Co', 'Pdfstraat 5', '1234 AB', 'Delft', 'pdf@example.test')");
    $zco = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZR Pdf Co'")->fetchColumn();
    $pdo->prepare("INSERT INTO invoices (number, company_id, customer, email, customer_address, issue_date, due_date, vat_rate, subtotal_cents, vat_cents, total_cents, notes, created_at) VALUES ('ZZR-PDF-1', ?, 'ZZR Pdf Co', 'pdf@example.test', 'Pdfstraat 5, 1234 AB Delft', CURDATE(), CURDATE() + INTERVAL 14 DAY, 21, 12345, 2592, 14937, 'Thank (you) \\\\ ok', NOW())")->execute([$zco]);
    $zi = (int) $pdo->query("SELECT id FROM invoices WHERE number = 'ZZR-PDF-1'")->fetchColumn();
    $pdo->exec("INSERT INTO invoice_items (invoice_id, description, qty, unit_cents) VALUES ($zi, 'Business fiber 1 Gbps (monthly) with a rather long description that has to wrap onto a second line of the table', 1, 10000), ($zi, 'Yealink desk phone', 2.5, 938)");
    $pdo->exec("INSERT INTO payments (invoice_id, amount_cents, method, paid_at, created_at) VALUES ($zi, 4937, 'iDEAL', CURDATE(), NOW())");
    $load = function (int $id) use ($pdo) { $s = $pdo->prepare('SELECT * FROM (' . invoice_base_sql() . ') t WHERE id = ?'); $s->execute([$id]); $inv = $s->fetch(); $it = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id'); $it->execute([$id]); return [$inv, $it->fetchAll()]; };
    $co0 = require TESTS_DIR . '/../includes/company.php';
    same([$co0['btw'], $co0['iban'], $co0['btw_is_placeholder']], ['', '', true], 'BTW number and IBAN start empty (no made-up numbers), and the invoice page treats that as still to fill in');
    [$inv, $items] = $load($zi);
    $bytes = invoice_pdf($inv, $items, $co0);
    $eur = "\x80 ";
    check(str_starts_with($bytes, '%PDF-1.4') && str_contains($bytes, '(INVOICE) Tj') && str_contains($bytes, '(ZZR-PDF-1) Tj') && str_contains($bytes, '(ZZR Pdf Co) Tj') && str_contains($bytes, '(Pdfstraat 5, 1234 AB Delft) Tj'), 'the PDF has the invoice number, the customer and their address');
    foreach (["{$eur}123,45" => 'subtotal', "{$eur}25,92" => 'BTW', "{$eur}149,37" => 'total', "{$eur}49,37" => 'paid', "{$eur}100,00" => 'balance due and first line', "{$eur}9,38" => 'unit price', "{$eur}23,45" => 'line total of 2,5 x 9,38'] as $needle => $what) {
        check(str_contains($bytes, '(' . $needle . ') Tj'), 'the PDF shows the right amount: ' . $what . ' (' . str_replace("\x80", '€', $needle) . ')');
    }
    check(str_contains($bytes, '(2,5) Tj') && str_contains($bytes, '(BTW 21%) Tj') && str_contains($bytes, '(Balance due) Tj'), 'quantities and the BTW rate are shown');
    same((int) round($inv['balance_cents'] / 100), 100, 'the balance (total minus payments) is 100,00 in the data the PDF is built from');
    check(str_contains($bytes, 'Note: Thank \\(you\\) \\\\ ok'), 'a note with parentheses and a backslash is escaped, not breaking the file');
    check(!str_contains($bytes, 'IBAN') && !str_contains($bytes, 'BTW-id') && !str_contains($bytes, 'NL00') && !str_contains($bytes, 'B01'), 'while BTW number and IBAN are empty they are left off the PDF entirely');
    check(str_contains($bytes, 'KvK 65586492') && str_contains($bytes, '(Telecomlijn Zakelijk)'), 'the supplier name and KvK number are printed');
    check(str_contains($bytes, 'to Telecomlijn Zakelijk, quoting ZZR-PDF-1') && !str_contains($bytes, 'to IBAN'), 'the payment line names the supplier and does not mention an IBAN that is not set');
    $oneLine = false; foreach (explode("\n", $bytes) as $l) { if (str_contains($l, 'Business fiber') && str_contains($l, 'table')) { $oneLine = true; } }
    check(str_contains($bytes, 'Business fiber 1 Gbps') && str_contains($bytes, 'table') && !$oneLine, 'a long description is wrapped over several lines instead of running off the page');
    check(str_contains($bytes, '(Page 1 of 1) Tj'), 'there is a page number');
    // many lines: second page
    $many = array_map(fn($i) => ['description' => "Line item number $i", 'qty' => 1, 'unit_cents' => 100 + $i], range(1, 70));
    $big = invoice_pdf($inv, $many, $co0);
    $np = substr_count($big, '/Type /Page ');
    check($np >= 2 && str_contains($big, "(Page 1 of $np) Tj") && str_contains($big, "(Page $np of $np) Tj") && str_contains($big, '(Invoice ZZR-PDF-1 \\(continued\\)) Tj') && str_contains($big, '(Line item number 70) Tj'), '70 lines continue on further pages with a repeated header and numbered pages ("Page 1 of N" ... "Page N of N")');
    $paid = $inv; $paid['balance_cents'] = 0; $paid['paid_cents'] = $paid['total_cents'];
    check(str_contains(invoice_pdf($paid, $items, $co0), 'paid in full'), 'a paid invoice says it is paid in full instead of asking for payment');
    $odd = $inv; $odd['customer'] = 'Café “Zoë” – 日本'; $odd['customer_address'] = '';
    check(str_contains(invoice_pdf($odd, $items, $co0), 'Caf') && str_starts_with(invoice_pdf($odd, $items, $co0), '%PDF'), 'accents and characters the font lacks do not break the file');

    // download over HTTP
    same(http('GET', "$base/invoice_pdf.php?id=$zi")['code'], 302, 'signed out: the PDF redirects to the login page');
    same(http('GET', "$base/invoice_pdf.php?id=999999", ['jar' => $admin])['code'], 404, 'an unknown invoice is 404');
    same(http('GET', "$base/invoice_pdf.php?id=abc", ['jar' => $admin])['code'], 404, 'a non-numeric id is 404');
    $r = http('GET', "$base/invoice_pdf.php?id=$zi", ['jar' => $admin]);
    check($r['code'] === 200 && str_contains($r['headers'], 'application/pdf') && str_contains($r['headers'], 'attachment; filename="invoice-ZZR-PDF-1.pdf"') && str_contains($r['headers'], 'no-store') && str_starts_with($r['body'], '%PDF-1.4'), 'the download is a PDF attachment named invoice-NUMBER.pdf and is not cached');
    check(str_contains($r['body'], "(\x80 149,37) Tj") && (int) preg_match('/Content-Length: (\d+)/i', $r['headers'], $cl) === 1 && (int) $cl[1] === strlen($r['body']), 'the downloaded file has the total and a correct Content-Length');
    check(str_contains(http('GET', "$base/invoice.php?id=$zi", ['jar' => $admin])['body'], "invoice_pdf.php?id=$zi") && str_contains(http('GET', "$base/invoice.php?id=$zi", ['jar' => $admin])['body'], 'Download PDF'), 'every invoice page has a Download PDF button');
    check(str_contains(http('GET', "$base/invoice.php?id=$zi", ['jar' => $admin])['body'], 'not filled in yet') && str_contains(http('GET', "$base/invoice.php?id=$zi", ['jar' => $admin])['body'], 'not set'), 'the invoice page reminds you that BTW number and IBAN are still empty');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Invoice PDF downloaded' AND target = 'ZZR-PDF-1'")->fetchColumn() === 1, 'a download is written to the activity log');
    fresh_window();
    $pdo->exec('DELETE FROM rate_limits');
    fresh_window(); $pdo->exec('DELETE FROM rate_limits'); $codes = []; for ($i = 0; $i < 17; $i++) { $codes[] = http('GET', "$base/invoice_pdf.php?id=$zi", ['jar' => $admin])['code']; }
    check(count(array_filter($codes, fn($c) => $c === 429)) >= 2, 'PDF downloads share the 15-a-minute download limit (429 after that)', json_encode(array_count_values($codes)));
    $pdo->exec('DELETE FROM rate_limits');

    section('30. Company details in Settings');
    foreach (['NL91ABNA0417164300', 'DE89370400440532013000', 'GB82WEST12345698765432', 'BE68539007547034'] as $ib) { check(iban_valid($ib), "IBAN check accepts $ib"); }
    foreach (['NL91ABNA0417164301', 'NL91ABNA041716430', 'nl91abna0417164300', 'XX', '', 'NL91 ABNA 0417 1643 00', 'NL00BANK0000000000'] as $ib) { check(!iban_valid($ib), 'IBAN check refuses ' . json_encode($ib)); }
    same(http('GET', "$base/settings.php?tab=company")['code'], 302, 'signed out: the Company details tab redirects');
    same(http('GET', "$base/settings.php?tab=company", ['jar' => $user])['code'], 403, 'a normal account gets 403 on the tab');
    check(!str_contains(http('GET', "$base/settings.php", ['jar' => $user])['body'], 'tab=company') && str_contains(http('GET', "$base/settings.php", ['jar' => $admin])['body'], 'tab=company'), 'the tab is shown to admins only');
    $cf = fn(array $d) => $post($admin, 'settings.php?tab=company', ['tab' => 'company'] + $d, 'settings.php?tab=company');
    same($post($user, 'settings.php?tab=company', ['tab' => 'company', 'iban' => 'NL91ABNA0417164300', 'btw_number' => 'NL123456789B01'], 'settings.php')['code'], 403, 'a forged save by a normal account is 403');
    same((int) $pdo->query('SELECT COUNT(*) FROM company_profile')->fetchColumn(), $cpBackup ? 1 : 0, 'and nothing changed');
    $full = ['name' => '', 'address1' => '', 'address2' => '', 'address3' => '', 'kvk' => '', 'btw_number' => 'nl 1234.56.789.b01', 'iban' => 'nl91 abna 0417 1643 00', 'email' => '', 'phone' => ''];
    foreach ([['IBAN is not valid', ['iban' => 'NL91ABNA0417164301']], ['Dutch BTW number looks like', ['btw_number' => 'NL12345']], ['BTW number starts with a country code', ['btw_number' => '12345678']], ['KvK number has 8 digits', ['kvk' => '123']], ['email address is not valid', ['email' => 'nope']]] as [$msg, $bad]) {
        $cf($bad + $full);
        same((string) $pdo->query("SELECT COALESCE(MAX(iban), '') FROM company_profile")->fetchColumn(), (string) ($cpBackup['iban'] ?? ''), 'validation refuses: ' . $msg);
    }
    $cf($full);
    $row = $pdo->query('SELECT * FROM company_profile WHERE id = 1')->fetch();
    same([$row['btw_number'], $row['iban']], ['NL123456789B01', 'NL91ABNA0417164300'], 'a valid BTW number and IBAN are saved in a normalised form (capitals, no spaces)');
    $co1 = require TESTS_DIR . '/../includes/company.php';
    same([$co1['btw'], $co1['iban'], $co1['btw_is_placeholder'], $co1['name']], ['NL123456789B01', 'NL91ABNA0417164300', false, 'Telecomlijn Zakelijk'], 'invoices use them; empty name, address and KvK fall back to the defaults');
    $bytes2 = invoice_pdf($inv, $items, $co1);
    check(str_contains($bytes2, 'BTW-id NL123456789B01') && str_contains($bytes2, 'IBAN NL91ABNA0417164300') && str_contains($bytes2, 'to IBAN NL91ABNA0417164300 in the name of Telecomlijn Zakelijk'), 'once filled in, the PDF prints the BTW-id and IBAN and the payment line names the IBAN');
    $pg = http('GET', "$base/invoice.php?id=$zi", ['jar' => $admin])['body'];
    check(!str_contains($pg, 'not filled in yet') && str_contains($pg, 'NL91ABNA0417164300'), 'the invoice page no longer shows the reminder and shows the IBAN');
    $cf(['name' => 'Acme <b>Telecom</b> BV', 'address1' => 'Main Street 1', 'address2' => '1000 AA Town', 'kvk' => '12345678', 'email' => 'x@acme.test', 'phone' => '020 123'] + $full);
    $pg = http('GET', "$base/invoice.php?id=$zi", ['jar' => $admin])['body'];
    check(str_contains($pg, 'Acme &lt;b&gt;Telecom&lt;/b&gt; BV') && !str_contains($pg, '<b>Telecom</b>') && str_contains($pg, 'Main Street 1, 1000 AA Town'), 'a changed name and address show on the invoice page, escaped');
    check(str_contains(invoice_pdf($inv, $items, require TESTS_DIR . '/../includes/company.php'), '(Acme <b>Telecom</b> BV) Tj'), 'and on the PDF as plain text');
    $cf(['btw_number' => '', 'iban' => ''] + $full);
    same([(string) $pdo->query('SELECT btw_number FROM company_profile')->fetchColumn(), (string) $pdo->query('SELECT iban FROM company_profile')->fetchColumn()], ['', ''], 'BTW number and IBAN can be emptied again');
    check(!str_contains(invoice_pdf($inv, $items, require TESTS_DIR . '/../includes/company.php'), 'IBAN'), 'and then disappear from the PDF again');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Company details saved'")->fetchColumn() >= 3, 'saving is in the activity log');

    // =========================================================
    section('31. Recurring invoices: dates and the form');
    same(recur_next_date('2026-01-31', 'month', 31), '2026-02-28', 'a monthly schedule from 31 January goes to 28 February');
    same(recur_next_date('2026-02-28', 'month', 31), '2026-03-31', 'and returns to the 31st in March');
    same(recur_next_date('2028-01-31', 'month', 31), '2028-02-29', 'in a leap year it uses 29 February');
    same(recur_next_date('2026-12-15', 'month', 15), '2027-01-15', 'December rolls over into the next year');
    same(recur_next_date('2026-11-30', 'quarter', 30), '2027-02-28', 'a quarterly schedule adds three months (with the month-end cut back)');
    same(recur_next_date('2028-02-29', 'year', 29), '2029-02-28', 'a yearly schedule from 29 February goes to 28 February in a normal year');
    same(recur_next_date('2026-03-10', 'year', 10), '2027-03-10', 'a yearly schedule keeps the day');
    same(recur_next_date('2026-05-05', 'bogus', 5), '2026-06-05', 'an unknown frequency is treated as monthly');
    [$d, $errs] = recurring_validate($pdo, ['company_id' => '0', 'description' => '', 'amount' => '0', 'vat' => '5', 'frequency' => 'week', 'next_date' => 'x', 'terms' => '3']);
    check(count($errs) === 7, 'validation reports every problem at once (company, description, amount, BTW, frequency, date, terms)', (string) count($errs));
    [$d, $errs] = recurring_validate($pdo, ['company_id' => (string) $zco, 'description' => 'Fiber', 'amount' => '1.234,56', 'vat' => '21', 'frequency' => 'quarter', 'next_date' => '2026-12-01', 'terms' => '30']);
    same([$errs, $d['amount_cents'], $d['frequency'], $d['terms_days']], [[], 123456, 'quarter', 30], 'a valid form is accepted and the amount parsed to cents');

    same(http('GET', "$base/recurring.php")['code'], 302, 'signed out: recurring.php redirects');
    same(http('POST', "$base/recurring.php", ['body' => 'action=run'])['code'], 302, 'signed out: a forged run is refused');
    same(http('POST', "$base/recurring.php", ['jar' => $admin, 'body' => 'action=run'])['code'], 403, 'a run without a CSRF token is 403');
    $add = fn(array $d) => $post($admin, 'recurring.php', ['action' => 'add'] + $d, 'recurring.php');
    $form = ['company_id' => (string) $zco, 'description' => 'ZZR Monthly fiber', 'amount' => '100,00', 'vat' => '21', 'frequency' => 'month', 'next_date' => date('Y-m-d', strtotime('+10 days')), 'terms' => '14'];
    $r = $add(['company_id' => '999999'] + $form);
    check($r['code'] === 200 && str_contains($r['body'], 'Choose a company'), 'a company that does not exist is refused');
    $r = $add(['amount' => 'abc'] + $form);
    check($r['code'] === 200 && str_contains($r['body'], 'Enter the amount'), 'a bad amount is refused with a message');
    same((int) $pdo->query("SELECT COUNT(*) FROM recurring_invoices WHERE description LIKE 'ZZR%'")->fetchColumn(), 0, 'nothing is stored while the form has errors');
    $r = $add($form);
    same($r['code'], 302, 'a valid schedule is saved');
    $rec = $pdo->query("SELECT * FROM recurring_invoices WHERE description = 'ZZR Monthly fiber'")->fetch();
    check($rec && (int) $rec['amount_cents'] === 10000 && (int) $rec['active'] === 1 && $rec['frequency'] === 'month' && (int) $rec['anchor_day'] === (int) date('j', strtotime('+10 days')) && $rec['last_run'] === null, 'it is stored active, with the amount in cents and the start day as the anchor');
    $rid = (int) $rec['id'];
    $rp = http('GET', "$base/recurring.php", ['jar' => $admin])['body'];
    check(str_contains($rp, 'ZZR Monthly fiber') && str_contains($rp, 'ZZR Pdf Co') && str_contains($rp, date('j M Y', strtotime('+10 days'))), 'the list shows the schedule with its company and next date');
    $x = $add(['description' => 'ZZR <script>alert(1)</script>'] + $form);
    check(!str_contains(http('GET', "$base/recurring.php", ['jar' => $admin])['body'], '<script>alert(1)') && str_contains(http('GET', "$base/recurring.php", ['jar' => $admin])['body'], '&lt;script&gt;'), 'markup in a description is escaped');
    $pdo->exec("DELETE FROM recurring_invoices WHERE description LIKE 'ZZR <script>%'");

    section('32. Recurring invoices: creating, pausing, deleting');
    $baseCount = (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
    $due = date('Y-m-d', strtotime('-3 days'));
    $pdo->prepare('UPDATE recurring_invoices SET next_date = ?, anchor_day = ? WHERE id = ?')->execute([$due, (int) date('j', strtotime($due)), $rid]);
    $run = recurring_run_due($pdo);
    same(count($run['created']), 1, 'a due schedule creates exactly one invoice');
    $inv1 = $pdo->query("SELECT * FROM invoices WHERE recurring_id = $rid")->fetch();
    check($inv1 && preg_match('/^INV-\d{4}-\d{4}$/', $inv1['number']) && $inv1['issue_date'] === $due && $inv1['due_date'] === date('Y-m-d', strtotime("$due +14 days")) && (int) $inv1['company_id'] === $zco && $inv1['customer'] === 'ZZR Pdf Co', 'the invoice uses the normal numbering, is dated on the scheduled day, and is billed to the company');
    same([(int) $inv1['subtotal_cents'], (int) $inv1['vat_cents'], (int) $inv1['total_cents'], $inv1['customer_address'], $inv1['email']], [10000, 2100, 12100, 'Pdfstraat 5, 1234 AB Delft', 'pdf@example.test'], 'its amounts, address and email come from the schedule and the company');
    $line = $pdo->query("SELECT description, qty, unit_cents FROM invoice_items WHERE invoice_id = " . (int) $inv1['id'])->fetchAll();
    same([count($line), $line[0]['description'], (float) $line[0]['qty'], (int) $line[0]['unit_cents']], [1, 'ZZR Monthly fiber', 1.0, 10000], 'it has one line with the schedule\'s description');
    $after = $pdo->query("SELECT next_date, last_run FROM recurring_invoices WHERE id = $rid")->fetch();
    same([$after['next_date'], $after['last_run']], [recur_next_date($due, 'month', (int) date('j', strtotime($due))), $due], 'the schedule moves on one month and remembers the last run');
    $again = recurring_run_due($pdo);
    same(count($again['created']), 0, 'running again straight away creates nothing');
    recurring_run_due($pdo); recurring_run_due($pdo);
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn(), 1, 'and more runs still leave exactly one invoice for that period');
    check(str_contains(http('GET', "$base/invoice.php?id=" . (int) $inv1['id'], ['jar' => $admin])['body'], 'Recurring invoice'), 'the invoice carries a note that it is a recurring one');
    // the unique key is the last line of defence
    $pdo->prepare('UPDATE recurring_invoices SET next_date = ? WHERE id = ?')->execute([$due, $rid]);
    $dup = recurring_run_due($pdo);
    check(count($dup['created']) === 0 && $dup['already'] === 1 && (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn() === 1, 'a period that already has its invoice is never invoiced twice, even if the schedule is wound back');
    same($pdo->query("SELECT next_date FROM recurring_invoices WHERE id = $rid")->fetchColumn(), recur_next_date($due, 'month', (int) date('j', strtotime($due))), 'and the schedule still moves on');
    try { $pdo->prepare("INSERT INTO invoices (number, recurring_id, customer, email, issue_date, due_date, subtotal_cents, vat_cents, total_cents, created_at) VALUES ('ZZR-DUP', ?, 'ZZR dup', 'x', ?, ?, 1, 0, 1, NOW())")->execute([$rid, $due, $due]); $dupOk = false; } catch (PDOException $e) { $dupOk = true; }
    check($dupOk, 'the database itself refuses a second invoice for the same schedule and date (unique key)');
    // catch-up: one per run
    $pdo->prepare('UPDATE recurring_invoices SET next_date = ?, anchor_day = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-80 days')), (int) date('j', strtotime('-80 days')), $rid]);
    $made = [];
    for ($i = 0; $i < 5; $i++) { $made[] = count(recurring_run_due($pdo)['created']); }
    check(array_sum($made) >= 2 && max($made) === 1 && end($made) === 0, 'a schedule that is far behind catches up one invoice per run and then stops', json_encode($made));
    $nums = $pdo->query("SELECT number FROM invoices WHERE recurring_id = $rid ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    same(count($nums), count(array_unique($nums)), 'every invoice number is unique');
    // 0% BTW and two schedules at once
    $pdo->prepare("INSERT INTO recurring_invoices (company_id, description, amount_cents, vat_rate, frequency, next_date, anchor_day, terms_days, active) VALUES (?, 'ZZR Zero rated', 5000, 0, 'year', ?, ?, 7, 1), (?, 'ZZR Quarterly', 25000, 9, 'quarter', ?, ?, 30, 1)")->execute([$zco, $due, (int) date('j', strtotime($due)), $zco, $due, (int) date('j', strtotime($due))]);
    $pdo->prepare('UPDATE recurring_invoices SET active = 0 WHERE id = ?')->execute([$rid]);
    $two = recurring_run_due($pdo);
    same(count($two['created']), 2, 'two schedules due at once each get their own invoice (a paused one is skipped)');
    $z = $pdo->query("SELECT i.vat_cents, i.total_cents, i.notes, i.due_date FROM invoices i JOIN recurring_invoices r ON r.id = i.recurring_id WHERE r.description = 'ZZR Zero rated'")->fetch();
    check((int) $z['vat_cents'] === 0 && (int) $z['total_cents'] === 5000 && str_contains($z['notes'], 'BTW verlegd') && $z['due_date'] === date('Y-m-d', strtotime("$due +7 days")), 'a 0% schedule has no BTW and a note giving the reason, with its own payment terms');
    $q = $pdo->query("SELECT i.vat_cents, i.total_cents FROM invoices i JOIN recurring_invoices r ON r.id = i.recurring_id WHERE r.description = 'ZZR Quarterly'")->fetch();
    same([(int) $q['vat_cents'], (int) $q['total_cents']], [2250, 27250], 'a 9% schedule rounds its BTW correctly');

    // pausing and resuming through the page
    $pdo->prepare('UPDATE recurring_invoices SET active = 1, next_date = ?, anchor_day = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('+5 days')), (int) date('j', strtotime('+5 days')), $rid]);
    $tg = fn() => $post($admin, 'recurring.php', ['action' => 'toggle', 'id' => (string) $rid], 'recurring.php');
    $tg();
    same((int) $pdo->query("SELECT active FROM recurring_invoices WHERE id = $rid")->fetchColumn(), 0, 'Pause switches a schedule off');
    $pdo->prepare('UPDATE recurring_invoices SET next_date = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-45 days')), $rid]);
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn();
    recurring_run_due($pdo);
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn(), $cnt, 'a paused schedule creates nothing, even when its date has passed');
    check(str_contains(http('GET', "$base/recurring.php", ['jar' => $admin])['body'], 'paused'), 'the list shows it as paused');
    $tg();
    $res = $pdo->query("SELECT active, next_date FROM recurring_invoices WHERE id = $rid")->fetch();
    check((int) $res['active'] === 1 && $res['next_date'] >= date('Y-m-d'), 'Resume switches it on and moves the next date to today or later (the paused months are not billed afterwards)', $res['next_date']);
    same(count(recurring_run_due($pdo, date('Y-m-d', strtotime('-1 day')))['created']), 0, 'resuming did not make an invoice appear by itself');

    // the Run now button, the page load and the command line
    $pdo->prepare('UPDATE recurring_invoices SET next_date = ?, anchor_day = ? WHERE id = ?')->execute([date('Y-m-d'), (int) date('j'), $rid]);
    $c0 = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn();
    $r = $post($admin, 'recurring.php', ['action' => 'run'], 'recurring.php');
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn(), $c0 + 1, 'the Run now button creates what is due');
    check(str_contains(http('GET', "$base/recurring.php", ['jar' => $admin])['body'], 'Nothing is due right now'), 'and the page then says nothing is due');
    $post($admin, 'recurring.php', ['action' => 'run'], 'recurring.php');
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn(), $c0 + 1, 'pressing Run now again changes nothing');
    $pdo->prepare('UPDATE recurring_invoices SET next_date = ?, anchor_day = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-1 day')), (int) date('j', strtotime('-1 day')), $rid]);   // a date this schedule has not invoiced yet
    $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('notif_refreshed_at', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string) (time() - 900)]);
    http('GET', "$base/index.php", ['jar' => $admin]);
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn(), $c0 + 2, 'simply opening the site creates a due invoice (once)');
    http('GET', "$base/index.php", ['jar' => $admin]);
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn(), $c0 + 2, 'and opening it again does not');
    $pdo->prepare('UPDATE recurring_invoices SET next_date = ?, anchor_day = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-2 days')), (int) date('j', strtotime('-2 days')), $rid]);   // a date this schedule has not invoiced yet
    $cli = function () { $p = proc_open([PHP_BINARY, TESTS_DIR . '/../run_recurring.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp); $o = stream_get_contents($pp[1]) . stream_get_contents($pp[2]); proc_close($p); return trim($o); };
    check(preg_match('/^(\d+) invoice\(s\) created/', $cli(), $m) === 1 && (int) $m[1] >= 1, 'the command-line script (for a scheduled task) creates what is due');
    same($cli(), '0 invoice(s) created.', 'and running it again creates nothing');
    same(http('GET', "$base/run_recurring.php")['code'], 403, 'the script can not be started from the web (403)');
    // a second run at the same moment
    $other = new PDO('mysql:host=127.0.0.1;dbname=ops_console;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $other->query("SELECT GET_LOCK('recurring_run', 1)")->fetchColumn();
    $pdo->prepare('UPDATE recurring_invoices SET next_date = ?, anchor_day = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-4 days')), (int) date('j', strtotime('-4 days')), $rid]);   // a date this schedule has not invoiced yet
    $c1 = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn();
    same(count(recurring_run_due($pdo)['created']), 0, 'while another run holds the lock, nothing is created (and nothing is lost)');
    $other->query("SELECT RELEASE_LOCK('recurring_run')")->fetchColumn();
    same(count(recurring_run_due($pdo)['created']), 1, 'once the other run is finished the schedule is still due and is created exactly once');
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn(), $c1 + 1, 'so one invoice more, not two');

    // deleting
    $keep = (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE recurring_id = $rid")->fetchColumn();
    check(str_contains(http('GET', "$base/recurring.php?delete=$rid", ['jar' => $admin])['body'], 'Yes, delete the schedule') && (int) $pdo->query("SELECT COUNT(*) FROM recurring_invoices WHERE id = $rid")->fetchColumn() === 1, 'Delete first asks for confirmation; opening that page deletes nothing');
    $post($admin, 'recurring.php', ['action' => 'delete', 'id' => (string) $rid], 'recurring.php');
    same((int) $pdo->query("SELECT COUNT(*) FROM recurring_invoices WHERE id = $rid")->fetchColumn(), 0, 'after confirming, the schedule is deleted');
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE customer = 'ZZR Pdf Co' AND recurring_id IS NULL AND notes LIKE 'Recurring invoice%'")->fetchColumn(), $keep, 'the invoices it created are kept, only losing the link');
    $gone = $post($admin, 'recurring.php', ['action' => 'delete', 'id' => '999999'], 'recurring.php');
    check($gone['code'] === 302 && str_contains($gone['headers'], 'recurring.php'), 'deleting a schedule that no longer exists is handled calmly (back to the list)');
    // company deleted: schedules go, invoices stay
    $pdo->prepare('UPDATE recurring_invoices SET active = 1 WHERE company_id = ?')->execute([$zco]);
    same((int) $pdo->query("SELECT COUNT(*) FROM recurring_invoices WHERE company_id = $zco")->fetchColumn(), 2, 'the other two test schedules still exist');
    if (demo_loaded($pdo)) {
        same(demo_counts($pdo, 1)['recurring_invoices'], 4, 'demo data has four recurring schedules (one paused)');
        same((int) $pdo->query("SELECT COUNT(*) FROM recurring_invoices WHERE is_demo = 1 AND next_date <= CURDATE()")->fetchColumn(), 0, 'none of them is due, so loading demo data never creates invoices by itself');
        same((int) $pdo->query("SELECT COUNT(*) FROM recurring_invoices WHERE is_demo = 1 AND active = 0")->fetchColumn(), 1, 'one demo schedule is paused');
    }
    $cleanPdf();
