<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Settings > Tests > Automated checks: the report the runner writes and the view that shows it. Included by tests/run.php inside its try block.
// The real report of the last run is put aside first and put back at the end (the runner writes a new one when the whole run is over).
require_once TESTS_DIR . '/../includes/modules/test_report.php';
$real23 = tests_report_path();
$backup23 = is_file($real23) ? (string) file_get_contents($real23) : null;
$fixture23 = ['run_at' => date('Y-m-d H:i:s', time() - 600), 'seconds' => 125, 'aborted' => '', 'sections' => [
    ['title' => 'S1 Alpha section', 'checks' => [['first check works', 1], ['broken <b>check</b>', 0, 'got 1, expected 2'], ['third check works', 1]]],
    ['title' => 'S2 Beta section', 'checks' => [['beta works', 1], ['beta also works', 1]]],
]];
try {
    section('76. Automated checks: the report and the view');
    $tmp23 = tempnam(sys_get_temp_dir(), 'rep');
    file_put_contents($tmp23, json_encode($fixture23));
    $r = tests_report_load($tmp23);
    same([$r['passed'], $r['failed'], count($r['sections']), $r['seconds']], [4, 1, 2, 125], 'the report is read: 5 checks in 2 sections, 4 passed and 1 failed, 125 seconds');
    same($r['sections'][0]['checks'][1], ['broken <b>check</b>', false, 'got 1, expected 2'], 'a failed check keeps what it said');
    file_put_contents($tmp23, 'this is not json');
    same(tests_report_load($tmp23), null, 'a file that is not a report gives no report');
    file_put_contents($tmp23, json_encode(['sections' => [['title' => 5, 'checks' => 'x'], 'junk', ['title' => 'ok', 'checks' => [['fine', 1], [7, 1], 'junk', ['long ' . str_repeat('x', 900), 0, str_repeat('d', 900)]]]]]));
    $r = tests_report_load($tmp23);
    same([count($r['sections']), count($r['sections'][0]['checks']), mb_strlen($r['sections'][0]['checks'][1][0]), mb_strlen($r['sections'][0]['checks'][1][2])], [1, 2, 300, 300], 'a damaged report is cleaned: wrong parts are skipped and long texts are cut to 300 characters');
    @unlink($tmp23);
    same(tests_report_load(sys_get_temp_dir() . '/no-such-report-23.json'), null, 'a missing file gives no report');

    // the page, with the fixture in the place of the real report
    $pdo->exec('DELETE FROM rate_limits');
    file_put_contents($real23, json_encode($fixture23));
    $page = http('GET', "$base/settings/settings.php?tab=tests&view=checks", ['jar' => $admin]);
    $b = $page['body'];
    check($page['code'] === 200 && str_contains($b, 'Documented tests') && str_contains($b, 'Automated checks') && str_contains($b, 'aria-current="page"'), 'the tab has two views, Documented tests and Automated checks');
    check(str_contains($b, '>5</dd>') && str_contains($b, 'in 2 sections') && str_contains($b, '>4</dd>') && str_contains($b, '>1</dd>') && str_contains($b, '2 min 5 s'), 'it shows the number of checks, passed and failed, the sections and how long the run took');
    check(str_contains($b, 'S1 Alpha section') && str_contains($b, 'first check works') && str_contains($b, 'beta works') && str_contains($b, '1 failed') && str_contains($b, 'all passed'), 'every section and check is listed, with the result of each section');
    check(str_contains($b, 'got 1, expected 2') && str_contains($b, 'broken &lt;b&gt;check&lt;/b&gt;') && !str_contains($b, 'broken <b>check</b>'), 'a failed check shows what it said, and markup in a check name is shown as text');
    check(preg_match('/<details open>\s*<summary[^>]*><span class="font-semibold">S1 Alpha/', $b) === 1 && preg_match('/<details >\s*<summary[^>]*><span class="font-semibold">S2 Beta/', $b) === 1, 'the section with a failure is open and the one that passed is closed');
    $s = http('GET', "$base/settings/settings.php?tab=tests&view=checks&cq=beta", ['jar' => $admin])['body'];
    check(str_contains($s, 'beta works') && !str_contains($s, 'first check works') && str_contains($s, '2</span> checks in 1 section'), 'searching narrows the list to the checks that match, and says how many');
    $s = http('GET', "$base/settings/settings.php?tab=tests&view=checks&cq=alpha", ['jar' => $admin])['body'];
    check(str_contains($s, 'first check works') && str_contains($s, 'third check works') && !str_contains($s, 'beta works'), 'a search that matches the name of a section shows all its checks');
    $s = http('GET', "$base/settings/settings.php?tab=tests&view=checks&cf=failed", ['jar' => $admin])['body'];
    check(str_contains($s, 'broken &lt;b&gt;') && !str_contains($s, 'first check works') && !str_contains($s, 'beta works') && str_contains($s, 'checked'), '"Only failed" shows the failed check and nothing else');
    check(str_contains(http('GET', "$base/settings/settings.php?tab=tests&view=checks&cq=zzzz-nothing", ['jar' => $admin])['body'], 'No check matches.'), 'a search without a match says so');
    check(!str_contains(http('GET', "$base/settings/settings.php?tab=tests", ['jar' => $admin])['body'], 'S1 Alpha section') && str_contains(http('GET', "$base/settings/settings.php?tab=tests", ['jar' => $admin])['body'], 'Test log'), 'the Documented tests view is still the one that opens first');
    file_put_contents($real23, json_encode(['aborted' => 'test run aborted: Error: boom'] + $fixture23));
    check(str_contains(http('GET', "$base/settings/settings.php?tab=tests&view=checks", ['jar' => $admin])['body'], 'The run stopped early: test run aborted: Error: boom'), 'a run that stopped early says so');
    @unlink($real23);
    $none = http('GET', "$base/settings/settings.php?tab=tests&view=checks", ['jar' => $admin]);
    check($none['code'] === 200 && str_contains($none['body'], 'No run has been saved') && str_contains($none['body'], 'tests\run.php'), 'without a report the page says how to make one');
    same(http('GET', "$base/settings/settings.php?tab=tests&view=checks")['code'], 302, 'signed out: the view redirects to the sign-in page');
    same(http('GET', "$base/settings/settings.php?tab=tests&view=checks", ['jar' => $user])['code'], 403, 'a normal account gets 403 on it');
    check(is_file(TESTS_DIR . '/../storage/.htaccess') && http('GET', "$base/storage/test_report.json")['code'] === 403, 'the report can not be fetched from the web (the storage folder is closed)');
} finally {
    @unlink($real23);
    if ($backup23 !== null) { file_put_contents($real23, $backup23); }
    @unlink($tmp23 ?? '');
}
