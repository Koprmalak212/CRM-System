<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 9 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
    // =========================================================
    // Stage 9: trends on the Overview
    // =========================================================
    require_once TESTS_DIR . '/../includes/trends.php';
    section('26. Trends: periods and numbers');
    $fixed = new DateTimeImmutable('2026-11-10');
    $mk = trend_month_keys(12, $fixed);
    same([$mk[0]['key'], $mk[0]['label'], $mk[11]['key'], $mk[11]['label'], count($mk)], ['2025-12', 'Dec 25', '2026-11', 'Nov 26', 12], 'the last 12 months run from Dec 25 to Nov 26 (oldest first)');
    same(array_column(trend_month_keys(12, new DateTimeImmutable('2026-03-31')), 'key')[0], '2025-04', 'a month-end date does not skip or repeat a month');
    $wk = trend_week_keys(12, $fixed);
    same([$wk[11]['key'], $wk[11]['label'], $wk[0]['key'], $wk[0]['label']], ['202646', 'Wk 46', '202635', 'Wk 35'], 'the last 12 ISO weeks end with the current week (Wk 46) and start at Wk 35');
    same(trend_week_keys(3, new DateTimeImmutable('2027-01-02'))[2]['key'], '202653', 'across New Year the keys follow ISO weeks (2 Jan 2027 is week 53 of 2026)');
    foreach (['2024-12-30', '2026-01-01', '2026-11-10', '2027-01-03'] as $d) {
        same(trend_week_keys(1, new DateTimeImmutable($d))[0]['key'], $pdo->query("SELECT YEARWEEK(DATE_SUB('$d', INTERVAL WEEKDAY('$d') DAY), 3)")->fetchColumn() . '', "week key for $d equals MySQL YEARWEEK(…, 3)");
    }

    // a clean room: a scratch database with the real schema and no rows
    $root->exec('DROP DATABASE IF EXISTS ops_console_trendtest'); $root->exec('CREATE DATABASE ops_console_trendtest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $scratch = new PDO('mysql:host=127.0.0.1;dbname=ops_console_trendtest;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $scratch->exec("SET time_zone = '" . date('P') . "'");
    $scratch->exec((string) file_get_contents(TESTS_DIR . '/../database/schema.sql'));
    $vals = fn(array $s) => array_column($s, 'value');
    $emptySeries = [trend_revenue($scratch), trend_new_users($scratch), trend_ticket_volume($scratch), trend_ticket_open_time($scratch)];
    check(count($emptySeries[0]) === 12 && count($emptySeries[2]) === 12 && !trend_has_data($emptySeries[0]) && !trend_has_data($emptySeries[1]) && !trend_has_data($emptySeries[2]) && !trend_has_data($emptySeries[3]), 'with no data every series still covers its whole range, with nothing in it');
    check($vals($emptySeries[0]) === array_fill(0, 12, 0.0) && $vals($emptySeries[3]) === array_fill(0, 12, null), 'empty months and weeks are 0, and "no tickets resolved" is null, not 0 hours');
    $emptyHtml = trends_section($scratch);
    check(substr_count($emptyHtml, 'data-empty="1"') === 4 && str_contains($emptyHtml, 'No data yet') && !str_contains($emptyHtml, 'vbar') && !str_contains($emptyHtml, 'View as table'), 'with no data all four charts show an empty state instead of empty bars');

    // known rows
    $firstOfMonth = fn(int $ago) => (new DateTimeImmutable('first day of this month'))->modify("-$ago month");
    $scratch->exec("INSERT INTO invoices (id, number, customer, email, issue_date, due_date, subtotal_cents, vat_cents, total_cents, created_at) VALUES (1, 'T-1', 'x', 'x@y.test', CURDATE(), CURDATE(), 1, 0, 1, NOW())");
    $pay = $scratch->prepare("INSERT INTO payments (invoice_id, amount_cents, method, paid_at, created_at) VALUES (1, ?, 'iDEAL', ?, NOW())");
    foreach ([[10000, $firstOfMonth(0)->format('Y-m-01')], [20000, $firstOfMonth(1)->format('Y-m-03')], [5000, $firstOfMonth(1)->format('Y-m-28')], [7000, $firstOfMonth(11)->format('Y-m-15')], [99999, $firstOfMonth(12)->format('Y-m-20')]] as [$c, $d]) { $pay->execute([$c, $d]); }
    $usr = $scratch->prepare("INSERT INTO users (name, email, country, last_seen, created_at) VALUES (?, ?, 'NL', NOW(), ?)");
    foreach ([[0, 3], [2, 1], [12, 2]] as [$ago, $n]) { for ($i = 0; $i < $n; $i++) { $usr->execute(['u', "u$ago-$i@x.test", $firstOfMonth($ago)->format('Y-m-d') . ' 10:00:00']); } }
    $mon = fn(int $ago) => (new DateTimeImmutable('monday this week'))->modify("-$ago week");
    $at = fn(int $ago, string $mod) => $mon($ago)->modify($mod)->format('Y-m-d H:i:s');
    $tk = $scratch->prepare("INSERT INTO tickets (reference, subject, description, created_at, updated_at, resolved_at, status) VALUES (?, 't', 'd', ?, ?, ?, ?)");
    foreach ([['T1', $at(0, '+2 days 08:00'), $at(0, '+2 days 18:00')], ['T2', $at(0, '+1 day 18:00'), $at(0, '+2 days 18:00')], ['T3', $at(1, '+8 hours'), $at(1, '+1 day 8 hours')], ['T4', $at(12, '+2 days 08:00'), $at(12, '+3 days 08:00')], ['T5', $at(3, '+1 day'), null], ['T6', $at(5, '+4 days 12:00'), $at(4, '+12 hours')]] as [$ref, $c, $r]) {
        $tk->execute([$ref, $c, $c, $r, $r ? 'resolved' : 'open']);
    }
    $rev = trend_revenue($scratch); $nu = trend_new_users($scratch); $tv = trend_ticket_volume($scratch); $ot = trend_ticket_open_time($scratch);
    same($vals($rev), [7000.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 25000.0, 10000.0], 'revenue: payments summed per month (two in one month add up, a payment 12 months back is left out)');
    same($vals($nu), [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 3.0], 'new users: counted by the month they were created (an older one is left out)');
    $exp = array_fill(0, 12, 0.0); $exp[11] = 2.0; $exp[10] = 1.0; $exp[8] = 1.0; $exp[6] = 1.0;   // weeks 0, -1, -3, -5 ago; week -12 is outside the range
    same($vals($tv), $exp, 'ticket volume: 2 this week, 1 last week, 1 three weeks ago, 1 five weeks ago; the ticket from 12 weeks ago is left out');
    $t6 = (strtotime($at(4, '+12 hours')) - strtotime($at(5, '+4 days 12:00'))) / 3600;
    $expOpen = array_fill(0, 12, null); $expOpen[11] = 17.0; $expOpen[10] = 24.0; $expOpen[7] = round($t6, 1);   // weeks 0, -1 and -4 ago
    $got = array_map(fn($v) => $v === null ? null : round($v, 1), $vals($ot));
    same($got, $expOpen, 'average open time: 17 h this week (10 h and 24 h), 24 h last week, and the ticket opened 5 weeks ago counts in the week it was resolved (' . round($t6, 1) . ' h); weeks with none are null');
    same($vals(trend_revenue($scratch, new DateTimeImmutable('first day of next month'))), [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 25000.0, 10000.0, 0.0], 'the range follows the date it is asked for: one month later, the oldest payment drops out and the newest month is empty');

    // against the live database
    $liveRev = trend_revenue($pdo);
    $m = $liveRev[count($liveRev) - 2];
    $direct = (int) $pdo->query("SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE DATE_FORMAT(paid_at, '%Y-%m') = '" . $m['key'] . "'")->fetchColumn();
    same((int) $m['value'], $direct, 'live revenue for last month equals a direct SUM on the payments table');
    $liveUsr = trend_new_users($pdo); $m = $liveUsr[count($liveUsr) - 3];
    same((int) $m['value'], (int) $pdo->query("SELECT COUNT(*) FROM users WHERE DATE_FORMAT(created_at, '%Y-%m') = '" . $m['key'] . "'")->fetchColumn(), 'live new users for a month equal a direct COUNT');
    $liveVol = trend_ticket_volume($pdo);
    $wkStart = (new DateTimeImmutable('monday this week'))->modify('-11 week')->format('Y-m-d');
    same((int) array_sum($vals($liveVol)), (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE created_at >= '$wkStart'")->fetchColumn(), 'live ticket volume over 12 weeks equals a direct COUNT');

    // =========================================================
    section('27. Trends: the charts on the Overview');
    $html = trend_chart('x', 'Test chart', 'Sub title', [['key' => 'a', 'label' => 'Jan', 'value' => 5.0], ['key' => 'b', 'label' => 'Feb', 'value' => 0.0], ['key' => 'c', 'label' => 'Mar', 'value' => null], ['key' => 'd', 'label' => 'Apr', 'value' => 10.0]], fn($v) => (int) $v . ' things', 'Things', 'A summary sentence.');
    check(str_contains($html, 'role="img"') && str_contains($html, 'aria-label="Test chart. A summary sentence."'), 'a chart has a text description with its summary for screen readers');
    check(substr_count($html, 'class="vbar') === 4 && str_contains($html, 'data-tip="Jan: 5 things"') && str_contains($html, 'data-tip="Mar: no data"') && str_contains($html, 'aria-label="Apr: 10 things"'), 'every bar carries its value for hover (data-tip) and for assistive technology (aria-label)');
    check(preg_match('/height:50%/', $html) === 1 && preg_match('/height:100%/', $html) === 1, 'bar heights are relative to the largest value');
    check(str_contains($html, '<details') && str_contains($html, 'View as table') && str_contains($html, '<caption class="sr-only">Test chart</caption>') && substr_count($html, '<th scope="row"') === 4 && str_contains($html, '<th scope="col"'), 'a data table with captions and headers is the fallback for every chart');
    check(str_contains($html, '>–<') && str_contains($html, '5 things'), 'missing values show as a dash in the table');
    check(!str_contains(trend_chart('y', 'T <b>', 'S', [['key' => 'a', 'label' => '<i>x</i>', 'value' => 1.0]], fn($v) => '<u>' . $v, 'V', 'S'), '<i>x</i>') && !str_contains(trend_chart('y', 'T <b>', 'S', [['key' => 'a', 'label' => 'x', 'value' => 1.0]], fn($v) => '<u>' . $v, 'V', 'S'), '<u>1'), 'labels and values are escaped');
    $ov = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    foreach (['Revenue per month', 'New users per month', 'Tickets opened per week', 'Average time to resolve'] as $t) { check(str_contains($ov, $t), "the Overview shows the chart: $t"); }
    $empty = substr_count($ov, 'data-empty="1"');
    same(substr_count($ov, 'View as table') + $empty, 4, 'each of the four charts has its table (or, with no data, its empty state)');
    check(strpos($ov, 'Revenue per month') < strpos($ov, 'id="demo-data"') && strpos($ov, 'Activity, last 24 hours') < strpos($ov, 'Revenue per month'), 'the trends sit below the activity chart and above the demo-data panel');
    check(substr_count($ov, 'class="vbar') >= 12 + 12 + 12 + 12 || str_contains($ov, 'data-empty="1"'), 'each chart draws one bar per period (12 months or 12 weeks) when there is data');
    check($empty === 4 || str_contains($ov, 'data-tip="' . trend_month_keys()[11]['label'] . ': '), 'the newest bar is the current month (when there is data)');
    if (demo_loaded($pdo)) {
        check(count(array_filter($vals($liveRev), fn($v) => $v > 0)) >= 9, 'demo data gives revenue in at least 9 of the last 12 months');
        check(count(array_filter($vals($liveUsr), fn($v) => $v > 0)) >= 9, 'and new users in at least 9 months');
        check(count(array_filter($vals($liveVol), fn($v) => $v > 0)) >= 8 && count(array_filter($vals(trend_ticket_open_time($pdo)), fn($v) => $v !== null)) >= 5, 'and tickets opened in most weeks and resolved in several');
        same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE is_demo = 1 AND number LIKE 'DEMO-%-H%'")->fetchColumn(), 70, 'the demo history is 70 older invoices, tagged as demo');
        same((int) $pdo->query("SELECT COUNT(*) FROM invoices i WHERE i.is_demo = 1 AND i.number LIKE 'DEMO-%-H%' AND i.total_cents <> (SELECT SUM(amount_cents) FROM payments p WHERE p.invoice_id = i.id)")->fetchColumn(), 0, 'every historic demo invoice is paid in full');
    }
    $root->exec('DROP DATABASE ops_console_trendtest');
