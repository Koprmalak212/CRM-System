<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Contracts & subscriptions: the form, the monthly price, invoices per period, renewal and ending, the renewal alert.
// Included by tests/run.php inside its try block (uses $base, $admin, $pdo, $post, http(), check(), same(), section()).
require_once TESTS_DIR . '/../includes/modules/contracts.php';
require_once TESTS_DIR . '/../includes/modules/tickets.php';   // sla_targets()
$modSaved18 = $pdo->query("SELECT k, v FROM app_meta WHERE k IN ('module_invoices', 'module_contracts')")->fetchAll(PDO::FETCH_KEY_PAIR);
$clean18 = function () use ($pdo) {
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer LIKE 'ZZC %')");
    $pdo->exec("DELETE FROM invoices WHERE customer LIKE 'ZZC %'");
    $pdo->exec("DELETE FROM notifications WHERE kind = 'contract_renewal' AND body LIKE 'ZZC %'");
    $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZC %'");   // its contracts go with it
    $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND (action LIKE 'Contract %' AND target LIKE '%ZZC %')");
    $pdo->exec('DELETE FROM rate_limits');
};
$clean18();
try {
    section('59. Contracts: pages, form and monthly price');
    foreach (['contracts/contracts.php', 'contracts/contract.php?id=1', 'contracts/contract_edit.php'] as $pg) { same(http('GET', "$base/$pg")['code'], 302, "signed out: $pg redirects to the login page"); }
    same(module_for_page('contracts/contract.php'), 'contracts', 'the contract pages belong to the contracts module');
    same(module_for_page('bin/run_contracts.php'), 'contracts', 'and so does the scheduled job');
    $pdo->exec("INSERT INTO companies (name, address, postcode, city, email) VALUES ('ZZC Contract Co', 'Lijnstraat 1', '3011 AA', 'Rotterdam', 'zzc@example.test')");
    $co18 = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZC Contract Co'")->fetchColumn();
    $form18 = ['id' => '0', 'company_id' => (string) $co18, 'title' => 'ZZC Fiber and seats', 'status' => 'active', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+1 year')), 'auto_renew' => '1', 'renew_months' => '12', 'notice_days' => '60',
        'billing' => 'month', 'vat' => '21', 'terms' => '14', 'next_invoice_date' => date('Y-m-d', strtotime('+10 days')), 'notes' => '', 'sla_respond_h' => '', 'sla_resolve_h' => '',
        'service' => ['fiber', 'voip_3cx'], 'desc' => ['Business fiber 1 Gbps', 'VoIP seat, 3CX'], 'qty' => ['1', '12'], 'price' => ['149,00', '9,50']];
    same(http('POST', "$base/contracts/contract_edit.php", ['jar' => $admin, 'body' => http_build_query($form18)])['code'], 403, 'a POST without a CSRF token is 403');
    foreach ([['Choose a company', ['company_id' => '0']], ['Enter a name for the contract', ['title' => '']], ['end date can not be before the start date', ['end_date' => '2001-01-01']], ['needs an end date', ['end_date' => '']],
        ['between 1 and 120 months', ['renew_months' => '0']], ['between 0 and 365 days', ['notice_days' => '900']], ['Choose how the contract is invoiced', ['billing' => 'weekly']], ['Line 1 needs a description', ['price' => ['0', '9,50']]],
        ['Add at least one line', ['desc' => ['', ''], 'price' => ['', '']]], ['response target must be a number of hours', ['sla_respond_h' => 'soon']]] as [$msg, $bad]) {
        $r = $post($admin, 'contracts/contract_edit.php', $bad + $form18);
        check($r['code'] === 200 && str_contains($r['body'], $msg), 'validation: ' . $msg);
    }
    same((int) $pdo->query("SELECT COUNT(*) FROM contracts WHERE company_id = $co18")->fetchColumn(), 0, 'nothing was saved while validation failed');
    $r = $post($admin, 'contracts/contract_edit.php', ['sla_respond_h' => '1', 'sla_resolve_h' => '0,5'] + $form18);
    $ct = $pdo->query("SELECT * FROM (" . contract_base_sql() . ") t WHERE company_id = $co18")->fetch();
    check($r['code'] === 302 && $ct && preg_match('/^CTR-\d{4}-\d{4}$/', $ct['number']) === 1, 'a valid contract is saved and gets a CTR number', (string) $r['code']);
    $cid18 = (int) $ct['id'];
    same((int) $ct['monthly_cents'], 14900 + 12 * 950, 'the price per month is the sum of quantity times unit price (149,00 + 12 x 9,50)');
    same([(int) $ct['sla_respond_min'], (int) $ct['sla_resolve_min']], [60, 30], 'SLA hours are stored as minutes (1 h and 0,5 h)');
    same((int) $ct['anchor_day'], (int) date('j', strtotime('+10 days')), 'the day of the month of the first invoice is remembered');
    $pg = http('GET', "$base/contracts/contract.php?id=$cid18", ['jar' => $admin])['body'];
    check(str_contains($pg, 'ZZC Fiber and seats') && str_contains($pg, '3CX seats') && str_contains($pg, '€ 263,00'), 'the contract page shows the services and the monthly total');
    check(str_contains(http('GET', "$base/contracts/contracts.php?q=ZZC", ['jar' => $admin])['body'], 'ZZC Fiber and seats'), 'the list finds it by name');
    check(str_contains(http('GET', "$base/companies/company.php?id=$co18", ['jar' => $admin])['body'], 'ZZC Fiber and seats'), 'the company page has a Contracts panel with it');
    check(str_contains(http('GET', "$base/search/search.php?q=ZZC+Fiber", ['jar' => $admin])['body'], 'contracts/contract.php?id=' . $cid18), 'search finds the contract');
    same(http('GET', "$base/contracts/contract.php?id=99999999", ['jar' => $admin])['code'], 404, 'an unknown contract is 404');
    same(sla_targets($pdo, 'urgent', $co18)[2], 'contract', 'a shorter contract target wins over the standard one');
    same(sla_targets($pdo, 'urgent', $co18)[1], 30, 'and gives 30 minutes to resolve instead of the standard 4 hours');
    same(sla_targets($pdo, 'urgent', null)[2], 'standard', 'a ticket without a company keeps the standard targets');

    section('60. Contracts: invoices per period');
    $set18 = fn(string $sql) => $pdo->exec("UPDATE contracts SET $sql WHERE id = $cid18");
    $invs18 = fn() => $pdo->query("SELECT * FROM invoices WHERE contract_id = $cid18 ORDER BY issue_date")->fetchAll();
    module_set($pdo, 'invoices', true);
    same(contracts_run_due($pdo)['invoices'], [], 'nothing is invoiced before the next invoice date');
    $d1 = date('Y-m-d', strtotime('-3 days'));
    $set18("next_invoice_date = '$d1', anchor_day = " . (int) date('j', strtotime($d1)));
    $res = contracts_run_due($pdo);
    $iv = $invs18();
    check(count($res['invoices']) === 1 && count($iv) === 1, 'a due contract gets exactly one invoice');
    same([(int) $iv[0]['subtotal_cents'], (int) $iv[0]['vat_cents'], (int) $iv[0]['total_cents']], [26300, 5523, 31823], 'subtotal, 21% BTW and total are right (263,00 + 55,23)');
    same([$iv[0]['issue_date'], $iv[0]['due_date'], $iv[0]['customer']], [$d1, date('Y-m-d', strtotime($d1 . ' +14 days')), 'ZZC Contract Co'], 'it is dated on the scheduled day, due after the payment terms, billed to the company');
    check(str_contains($iv[0]['notes'], $ct['number']) && str_contains($iv[0]['notes'], 'period'), 'the invoice note names the contract and the period');
    $lines = $pdo->query('SELECT description, qty, unit_cents FROM invoice_items WHERE invoice_id = ' . (int) $iv[0]['id'] . ' ORDER BY id')->fetchAll();
    check(count($lines) === 2 && $lines[1]['description'] === 'VoIP seat, 3CX' && (float) $lines[1]['qty'] === 12.0 && (int) $lines[1]['unit_cents'] === 950, 'the invoice has one line per service with its quantity and price');
    same((string) $pdo->query("SELECT next_invoice_date FROM contracts WHERE id = $cid18")->fetchColumn(), recur_next_date($d1, 'month', (int) date('j', strtotime($d1))), 'the next invoice date moves on one month');
    same(count(contracts_run_due($pdo)['invoices']), 0, 'running again creates nothing: a period is never invoiced twice');
    $set18("next_invoice_date = '$d1'");
    contracts_run_due($pdo);
    same(count($invs18()), 1, 'even when the date is put back, the same period is not invoiced a second time');
    $threw = false;
    try { $pdo->prepare("INSERT INTO invoices (number, contract_id, customer, email, issue_date, due_date, subtotal_cents, vat_cents, total_cents, created_at) VALUES ('ZZC-DUP', ?, 'ZZC dup', 'x@example.test', ?, ?, 1, 0, 1, NOW())")->execute([$cid18, $d1, $d1]); } catch (PDOException $e) { $threw = true; }
    check($threw, 'the database itself refuses a second invoice for the same contract and date');
    // quarterly, and two periods behind
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE contract_id = $cid18)"); $pdo->exec("DELETE FROM invoices WHERE contract_id = $cid18");
    $far = date('Y-m-d', strtotime('first day of this month -4 months'));
    $set18("billing = 'quarter', next_invoice_date = '$far', anchor_day = 1, start_date = '$far'");
    $res = contracts_run_due($pdo);
    $iv = $invs18();
    same(count($iv), 2, 'a contract that is two quarters behind catches up in one run');
    same((int) $iv[0]['subtotal_cents'], 26300 * 3, 'a quarterly invoice is three times the monthly price');
    check(str_contains((string) $pdo->query('SELECT description FROM invoice_items WHERE invoice_id = ' . (int) $iv[0]['id'] . ' LIMIT 1')->fetchColumn(), '(3 months)'), 'and its lines say that they cover 3 months');
    // draft, module off
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE contract_id = $cid18)"); $pdo->exec("DELETE FROM invoices WHERE contract_id = $cid18");
    $set18("billing = 'month', status = 'draft', next_invoice_date = '$d1'");
    contracts_run_due($pdo);
    same(count($invs18()), 0, 'a draft contract is not invoiced');
    $set18("status = 'active'");
    module_set($pdo, 'invoices', false);
    contracts_run_due($pdo);
    same(count($invs18()), 0, 'with the Invoices module off a contract makes no invoices');
    same(http('GET', "$base/contracts/contracts.php", ['jar' => $admin])['code'], 200, 'and the contracts page still opens (the modules do not need each other)');
    module_set($pdo, 'invoices', true);
    contracts_run_due($pdo);
    same(count($invs18()), 1, 'with Invoices on again the invoice that was due is made');

    section('61. Contracts: renewal, ending and the renewal alert');
    $yday = date('Y-m-d', strtotime('-1 day'));
    $set18("end_date = '$yday', auto_renew = 1, renew_months = 12, next_invoice_date = '" . date('Y-m-d', strtotime('+20 days')) . "'");
    $res = contracts_run_due($pdo);
    $row = $pdo->query("SELECT status, end_date FROM contracts WHERE id = $cid18")->fetch();
    check($res['renewed'] === 1 && $row['status'] === 'active' && $row['end_date'] === date('Y-m-d', strtotime($yday . ' +12 months')), 'a contract that renews by itself moves its end date on by the renewal period', json_encode($row));
    $set18("end_date = '$yday', auto_renew = 0");
    $res = contracts_run_due($pdo);
    same([$res['ended'], (string) $pdo->query("SELECT status FROM contracts WHERE id = $cid18")->fetchColumn()], [1, 'ended'], 'without automatic renewal it ends the day after its end date');
    $set18("status = 'active', next_invoice_date = '$d1'");
    contracts_run_due($pdo);
    same((string) $pdo->query("SELECT status FROM contracts WHERE id = $cid18")->fetchColumn(), 'ended', 'an ended contract is not invoiced after its end date');
    // the alert
    $soon = date('Y-m-d', strtotime('+30 days'));
    $set18("status = 'active', end_date = '$soon', notice_days = 60, auto_renew = 0, next_invoice_date = '" . date('Y-m-d', strtotime('+20 days')) . "'");
    same((int) contract_find($pdo, $cid18)['renewal_due'], 1, 'a contract inside its notice period is marked "renewal due"');
    $n1 = contracts_notify($pdo);
    $nt = $pdo->query("SELECT * FROM notifications WHERE dedupe_key = 'contract-renewal:$cid18:$soon'")->fetch();
    check($n1 >= 1 && $nt && str_contains($nt['title'], 'ends on') && $nt['link'] === 'contracts/contract.php?id=' . $cid18, 'the renewal alert is raised with a link to the contract');
    same(notification_module('contract_renewal', (string) $nt['dedupe_key']), 'contracts', 'the alert belongs to the contracts module');
    contracts_notify($pdo);
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE dedupe_key LIKE 'contract-renewal:$cid18:%'")->fetchColumn(), 1, 'the same end date is announced once');
    $set18("notice_days = 10");
    same((int) contract_find($pdo, $cid18)['renewal_due'], 0, 'outside the notice period there is no alert');
    $set18("notice_days = 60");
    check(str_contains(http('GET', "$base/contracts/contracts.php?state=renewal", ['jar' => $admin])['body'], 'ZZC Fiber and seats'), 'the "Renewal due" filter lists it');
    $r = $post($admin, 'contracts/contract.php', ['id' => $cid18, 'action' => 'renew'], "contracts/contract.php?id=$cid18");
    same((string) $pdo->query("SELECT end_date FROM contracts WHERE id = $cid18")->fetchColumn(), date('Y-m-d', strtotime($soon . ' +12 months')), 'the Renew button moves the end date on');
    $r = $post($admin, 'contracts/contract.php', ['id' => $cid18, 'action' => 'end'], "contracts/contract.php?id=$cid18");
    $row = $pdo->query("SELECT status, end_date, auto_renew FROM contracts WHERE id = $cid18")->fetch();
    same([$row['status'], $row['end_date'], (int) $row['auto_renew']], ['ended', date('Y-m-d'), 0], 'End contract stops it today and switches renewal off');
    same(count($invs18()), 1, 'the invoices it made are kept');
    $r = $post($admin, 'contracts/contract.php', ['id' => $cid18, 'action' => 'delete'], "contracts/contract.php?id=$cid18");
    same((int) $pdo->query("SELECT COUNT(*) FROM contracts WHERE id = $cid18")->fetchColumn(), 0, 'an ended contract can be deleted');
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE customer = 'ZZC Contract Co' AND contract_id IS NULL")->fetchColumn(), 1, 'its invoice stays, without the link');
    module_set($pdo, 'contracts', false);
    same(http('GET', "$base/contracts/contracts.php", ['jar' => $admin])['code'], 404, 'contracts off: the page is 404');
    check(!str_contains(http('GET', "$base/companies/company.php?id=$co18", ['jar' => $admin])['body'], 'id="ctr-h"'), 'contracts off: the panel leaves the company page');
    same(sla_targets($pdo, 'urgent', $co18)[2], 'standard', 'contracts off: contract SLA targets are not used');
} finally {
    $pdo->exec("DELETE FROM app_meta WHERE k IN ('module_invoices', 'module_contracts')");
    foreach ($modSaved18 as $k => $v) { $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?)')->execute([$k, $v]); }
    module_states(true);
    $pdo->exec("DELETE FROM invoices WHERE number = 'ZZC-DUP'");
    $clean18();
}
