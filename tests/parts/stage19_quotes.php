<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Quotes: the form, the outcome and win rate, the PDF, and turning a quote into an invoice or a contract.
// Included by tests/run.php inside its try block (uses $base, $admin, $pdo, $post, http(), check(), same(), section()).
require_once TESTS_DIR . '/../includes/modules/quotes.php';
require_once TESTS_DIR . '/../includes/modules/contracts.php';
require_once TESTS_DIR . '/../includes/modules/pdf.php';
$modSaved19 = $pdo->query("SELECT k, v FROM app_meta WHERE k IN ('module_invoices', 'module_contracts', 'module_quotes')")->fetchAll(PDO::FETCH_KEY_PAIR);
$clean19 = function () use ($pdo) {
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer LIKE 'ZZQ %')");
    $pdo->exec("DELETE FROM invoices WHERE customer LIKE 'ZZQ %'");
    $pdo->exec("DELETE FROM quotes WHERE customer LIKE 'ZZQ %'");
    $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZQ %'");
    $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND ((action LIKE 'Quote %' OR action LIKE 'Contract %' OR action = 'Invoice created') AND target LIKE '%ZZQ %')");
    $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND action IN ('Quote became an invoice', 'Quote PDF downloaded') AND target LIKE 'OFF-%'");
    $pdo->exec('DELETE FROM rate_limits');
};
$clean19();
try {
    foreach (['invoices', 'contracts', 'quotes'] as $m19) { module_set($pdo, $m19, true); }
    section('62. Quotes: pages and form');
    foreach (['quotes/quotes.php', 'quotes/quote.php?id=1', 'quotes/quote_edit.php', 'quotes/quote_pdf.php?id=1'] as $pg) { same(http('GET', "$base/$pg")['code'], 302, "signed out: $pg redirects to the login page"); }
    $pdo->exec("INSERT INTO companies (name, address, postcode, city, email) VALUES ('ZZQ Quote Co', 'Offertelaan 9', '3012 BB', 'Rotterdam', 'zzq@example.test')");
    $co19 = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZQ Quote Co'")->fetchColumn();
    $form19 = ['id' => '0', 'company_id' => (string) $co19, 'customer' => 'ZZQ Quote Co', 'email' => 'zzq@example.test', 'address' => 'Offertelaan 9, 3012 BB Rotterdam', 'title' => 'Move to 3CX', 'issue_date' => date('Y-m-d'),
        'valid_until' => date('Y-m-d', strtotime('+30 days')), 'vat' => '21', 'notes' => '', 'desc' => ['VoIP seat, 3CX', 'Yealink desk phone'], 'qty' => ['10', '2,5'], 'price' => ['9,50', '9,38']];
    same(http('POST', "$base/quotes/quote_edit.php", ['jar' => $admin, 'body' => http_build_query($form19)])['code'], 403, 'a POST without a CSRF token is 403');
    foreach ([['Enter the customer name', ['customer' => '']], ['email address is not valid', ['email' => 'nope']], ['Choose the date until which', ['valid_until' => 'x']], ['before its own date', ['valid_until' => '2001-01-01']],
        ['State the reason for 0% BTW', ['vat' => '0']], ['Add at least one line', ['desc' => ['', ''], 'price' => ['', '']]], ['That company does not exist', ['company_id' => '99999999']]] as [$msg, $bad]) {
        $r = $post($admin, 'quotes/quote_edit.php', $bad + $form19);
        check($r['code'] === 200 && str_contains($r['body'], $msg), 'validation: ' . $msg);
    }
    same((int) $pdo->query("SELECT COUNT(*) FROM quotes WHERE customer = 'ZZQ Quote Co'")->fetchColumn(), 0, 'nothing was saved while validation failed');
    $before19 = quotes_stats($pdo);
    $r = $post($admin, 'quotes/quote_edit.php', $form19);
    $q19 = $pdo->query("SELECT * FROM (" . quote_base_sql() . ") t WHERE customer = 'ZZQ Quote Co'")->fetch();
    check($r['code'] === 302 && $q19 && preg_match('/^OFF-\d{4}-\d{4}$/', $q19['number']) === 1 && $q19['state'] === 'draft', 'a valid quote is saved as a draft with an OFF number');
    $qid = (int) $q19['id'];
    same([(int) $q19['subtotal_cents'], (int) $q19['vat_cents'], (int) $q19['total_cents']], [11845, 2487, 14332], 'subtotal, BTW and total are right (10 x 9,50 + 2,5 x 9,38 = 118,45)');
    $pg = http('GET', "$base/quotes/quote.php?id=$qid", ['jar' => $admin])['body'];
    check(str_contains($pg, 'Move to 3CX') && str_contains($pg, '€ 143,32') && str_contains($pg, 'Mark as sent'), 'the quote page shows the lines, the total and the next step');
    check(str_contains(http('GET', "$base/search/search.php?q=" . urlencode($q19['number']), ['jar' => $admin])['body'], "quotes/quote.php?id=$qid"), 'search finds the quote by its number');
    check(str_contains(http('GET', "$base/companies/company.php?id=$co19", ['jar' => $admin])['body'], 'id="qt-h"'), 'the company page has a Quotes panel');
    // edit keeps the number
    $r = $post($admin, 'quotes/quote_edit.php', ['id' => (string) $qid, 'title' => 'Move to 3CX, 10 seats'] + $form19, "quotes/quote_edit.php?id=$qid");
    same((string) $pdo->query("SELECT CONCAT(number, '|', title) FROM quotes WHERE id = $qid")->fetchColumn(), $q19['number'] . '|Move to 3CX, 10 seats', 'editing changes the quote and keeps its number');
    same((int) $pdo->query("SELECT COUNT(*) FROM quote_items WHERE quote_id = $qid")->fetchColumn(), 2, 'and replaces its lines instead of adding to them');

    section('63. Quotes: the PDF');
    $bytes = invoice_pdf(quote_find($pdo, $qid), quote_items($pdo, $qid), require TESTS_DIR . '/../includes/modules/company.php', true);
    $eur = "\x80 ";
    check(str_starts_with($bytes, '%PDF-1.4') && str_contains($bytes, '(QUOTE) Tj') && str_contains($bytes, '(' . $q19['number'] . ') Tj') && str_contains($bytes, '(ZZQ Quote Co) Tj'), 'the PDF is a quote with its number and the customer');
    check(str_contains($bytes, '(Valid until) Tj') && str_contains($bytes, "({$eur}143,32) Tj") && str_contains($bytes, '(2,5) Tj'), 'it shows "valid until", the total and the quantities');
    check(!str_contains($bytes, 'Balance due') && !str_contains($bytes, 'Please pay') && !str_contains($bytes, '(INVOICE)') && !str_contains($bytes, 'Due date'), 'it has no payment lines: nothing is owed on a quote');
    $r = http('GET', "$base/quotes/quote_pdf.php?id=$qid", ['jar' => $admin]);
    check($r['code'] === 200 && stripos($r['headers'], 'application/pdf') !== false && str_starts_with($r['body'], '%PDF-1.4') && stripos($r['headers'], 'quote-' . $q19['number'] . '.pdf') !== false, 'the PDF downloads with the quote number as its file name');
    same(http('GET', "$base/quotes/quote_pdf.php?id=99999999", ['jar' => $admin])['code'], 404, 'the PDF of an unknown quote is 404');

    section('64. Quotes: outcome and win rate');
    $act19 = fn(int $id, array $d) => $post($admin, 'quotes/quote.php', ['id' => (string) $id] + $d, "quotes/quote.php?id=$id");
    $act19($qid, ['action' => 'sent']);
    same((string) $pdo->query("SELECT status FROM quotes WHERE id = $qid")->fetchColumn(), 'sent', 'Mark as sent');
    // three more quotes straight in the table: one declined, one expired, one open
    $mk19 = function (string $status, string $valid, int $total) use ($pdo, $co19): int {
        $pdo->prepare("INSERT INTO quotes (number, company_id, customer, email, customer_address, issue_date, valid_until, vat_rate, subtotal_cents, vat_cents, total_cents, status, created_at) VALUES (?, ?, 'ZZQ Quote Co', 'zzq@example.test', 'Offertelaan 9', CURDATE() - INTERVAL 40 DAY, ?, 21, ?, 0, ?, ?, NOW())")
            ->execute(['ZZQ-' . bin2hex(random_bytes(4)), $co19, $valid, $total, $total, $status]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO quote_items (quote_id, description, qty, unit_cents) VALUES (?, 'Line', 1, ?)")->execute([$id, $total]);
        return $id;
    };
    $qLost = $mk19('sent', date('Y-m-d', strtotime('+5 days')), 20000);
    $qExp = $mk19('sent', date('Y-m-d', strtotime('-2 days')), 30000);
    $qOpen = $mk19('draft', date('Y-m-d', strtotime('+5 days')), 5000);
    same(quote_find($pdo, $qExp)['state'], 'expired', 'a sent quote past its "valid until" date counts as expired');
    $act19($qLost, ['action' => 'declined', 'reason' => 'Too expensive']);
    same((string) $pdo->query("SELECT CONCAT(status, '|', lost_reason) FROM quotes WHERE id = $qLost")->fetchColumn(), 'declined|Too expensive', 'Declined stores the reason it was lost');
    $act19($qExp, ['action' => 'accepted']);
    same((string) $pdo->query("SELECT status FROM quotes WHERE id = $qExp")->fetchColumn(), 'sent', 'an expired quote can not be accepted until it gets a new date');
    $act19($qid, ['action' => 'accepted']);
    $st = quotes_stats($pdo);
    same([(int) $st['won_n'] - (int) $before19['won_n'], (int) $st['lost_n'] - (int) $before19['lost_n'], (int) $st['open_n'] - (int) $before19['open_n']], [1, 2, 1], 'won, lost (declined and expired) and open are counted');
    same((int) $st['won_sum'] - (int) $before19['won_sum'], 14332, 'the won amount is the total of the accepted quote');
    // the rate itself is checked on a scratch copy that holds only these four quotes
    $root19 = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $root19->exec('DROP DATABASE IF EXISTS ops_console_quotetest'); $root19->exec('CREATE DATABASE ops_console_quotetest'); $root19->exec('USE ops_console_quotetest');
    $root19->exec('CREATE TABLE quotes LIKE ops_console.quotes');
    $root19->exec("INSERT INTO quotes SELECT * FROM ops_console.quotes WHERE customer = 'ZZQ Quote Co'");
    same(quotes_stats($root19)['win_rate'], 33.3, 'the win rate is accepted out of all quotes with an outcome (1 of 3 = 33,3%)');
    $root19->exec('DELETE FROM quotes');
    same(quotes_stats($root19)['win_rate'], null, 'without any outcome there is no win rate (not 0%)');
    $root19->exec('DROP DATABASE ops_console_quotetest');
    check(str_contains(http('GET', "$base/quotes/quotes.php", ['jar' => $admin])['body'], 'Win rate'), 'the quotes page shows the win rate');
    check(str_contains(http('GET', "$base/quotes/quotes.php?state=expired", ['jar' => $admin])['body'], 'quotes/quote.php?id=' . $qExp), 'the Expired filter lists the expired quote');

    section('65. Quotes: one click to an invoice or a contract');
    $r = $act19($qid, ['action' => 'to_invoice']);
    $q = quote_find($pdo, $qid);
    $inv = $pdo->query('SELECT * FROM invoices WHERE id = ' . (int) $q['invoice_id'])->fetch();
    check($r['code'] === 302 && $inv && preg_match('/invoices\/invoice\.php\?id=' . (int) $inv['id'] . '/', $r['headers']) === 1, 'the button makes an invoice and opens it');
    same([(int) $inv['subtotal_cents'], (int) $inv['vat_cents'], (int) $inv['total_cents'], $inv['customer'], (int) $inv['company_id']], [11845, 2487, 14332, 'ZZQ Quote Co', $co19], 'the invoice has the amounts, the customer and the company of the quote');
    same([$inv['issue_date'], $inv['due_date']], [date('Y-m-d'), date('Y-m-d', strtotime('+14 days'))], 'it is dated today and due in 14 days');
    same((int) $pdo->query('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ' . (int) $inv['id'])->fetchColumn(), 2, 'with the same two lines');
    check(str_contains($inv['notes'], $q['number']), 'the invoice note names the quote');
    [$again, $err] = quote_to_invoice($pdo, $qid);
    check($again === 0 && str_contains((string) $err, 'already became an invoice'), 'a quote becomes an invoice only once');
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE customer = 'ZZQ Quote Co'")->fetchColumn(), 1, 'and no second invoice exists');
    same(quote_set_status($pdo, $q, 'draft') !== null, true, 'a quote that became an invoice can not be reopened');
    [$none, $err] = quote_to_invoice($pdo, $qLost);
    check($none === 0 && str_contains((string) $err, 'declined'), 'a declined quote can not be invoiced');
    $r = $act19($qOpen, ['action' => 'to_contract']);
    $q = quote_find($pdo, $qOpen);
    $ct = $q['contract_id'] ? contract_find($pdo, (int) $q['contract_id']) : null;
    check($r['code'] === 302 && $ct && $ct['status'] === 'draft' && (int) $ct['company_id'] === $co19 && (int) $ct['quote_id'] === $qOpen, 'the other button makes a DRAFT contract for the same company');
    same([(int) $ct['monthly_cents'], $q['status']], [5000, 'accepted'], 'the lines become the monthly price, and the quote is marked accepted');
    same(count(contracts_run_due($pdo)['invoices']), 0, 'a contract made from a quote invoices nothing until someone makes it active');
    [$again, $err] = quote_to_contract($pdo, $qOpen);
    check($again === 0 && str_contains((string) $err, 'already became a contract'), 'a quote becomes a contract only once');
    $pdo->exec("UPDATE quotes SET company_id = NULL WHERE id = $qExp");
    check(str_contains((string) quote_to_contract($pdo, $qExp)[1], 'belongs to a company'), 'a quote without a company can not become a contract');
    module_set($pdo, 'invoices', false); module_set($pdo, 'contracts', false);
    $pg = http('GET', "$base/quotes/quote.php?id=$qExp", ['jar' => $admin]);
    check($pg['code'] === 200 && !str_contains($pg['body'], 'value="to_invoice"') && !str_contains($pg['body'], 'value="to_contract"'), 'with Invoices and Contracts off the quote page still works and offers neither step');
    $act19($qExp, ['action' => 'to_invoice']);
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE customer = 'ZZQ Quote Co'")->fetchColumn(), 1, 'and a forged request for the step does nothing');
    module_set($pdo, 'quotes', false);
    same(http('GET', "$base/quotes/quotes.php", ['jar' => $admin])['code'], 404, 'quotes off: the page is 404');
    same(http('GET', "$base/companies/company.php?id=$co19", ['jar' => $admin])['code'], 200, 'quotes off: the company page still opens');
} finally {
    $pdo->exec("DELETE FROM app_meta WHERE k IN ('module_invoices', 'module_contracts', 'module_quotes')");
    foreach ($modSaved19 as $k => $v) { $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?)')->execute([$k, $v]); }
    module_states(true);
    try { (new PDO('mysql:host=127.0.0.1', 'root', ''))->exec('DROP DATABASE IF EXISTS ops_console_quotetest'); } catch (Throwable $e) {}
    $clean19();
}
