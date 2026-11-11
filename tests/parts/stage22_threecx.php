<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// 3CX sign-in: an API client, or a Management Console login (a system administrator's own user name and password), with a clear reason
// when both are refused. Uses a stand-in 3CX on port 3198, which must be free. Included by tests/run.php inside its try block.
require_once TESTS_DIR . '/../includes/modules/connectors.php';
$logBackup22 = $pdo->query("SELECT * FROM connector_log WHERE provider = 'threecx'")->fetchAll(PDO::FETCH_ASSOC);   // the real log of this install is put back at the end
$proc22 = proc_open([PHP_BINARY, '-S', '127.0.0.1:3198', TESTS_DIR . '/mock_3cx.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/mock_3cx.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/mock_3cx.log', 'a']], $pipes22);
try {
    for ($i = 0; $i < 30; $i++) { $c = @stream_socket_client('tcp://127.0.0.1:3198', $en, $es, 1); if ($c) { fclose($c); break; } usleep(200000); }
    section('74. 3CX: the sign-in');
    $try22 = function (string $mode, string $id, string $pw): array { return connector_test('threecx', ['base_url' => "http://127.0.0.1:3198/$mode", 'verify_tls' => 1, 'account_ref' => $id, 'provider' => 'threecx'], $pw); };
    same($try22('api', 'apiclient', 'apisecret')[0], 'ok', 'an API client (Client ID and secret) connects');
    [$s, $m] = $try22('admin', 'sysadmin', 'adminpass');
    check($s === 'ok' && str_contains($m, 'Connected to 3CX'), 'a system administrator\'s own user name and password connect, typed in the same two fields');
    [$s, $m] = $try22('api', 'apiclient', 'wrong');
    check($s === 'unauthorized' && str_contains($m, 'refused both') && str_contains($m, 'invalid_client') && str_contains($m, 'API client: HTTP 400') && str_contains($m, 'Login: HTTP 404'), 'a wrong secret says that both ways were refused and shows the codes the PBX gave for each', $m);
    [$s, $m] = $try22('admin', 'sysadmin', 'bad');
    check($s === 'unauthorized' && str_contains($m, 'refused both') && str_contains($m, 'Login: HTTP 200, AuthFailed'), 'a wrong password is refused, and the status word of the login is shown', $m);
    [$s, $m] = $try22('2fa', 'sysadmin', 'adminpass');
    check($s === 'unauthorized' && str_contains($m, 'two-step sign-in'), 'a login that has two-step sign-in turned on is explained, not just "rejected"');
    [$s, $m] = $try22('noperm', 'apiclient', 'apisecret');
    check($s === 'forbidden' && str_contains($m, 'credentials are right') && str_contains($m, 'not allowed to read call history') && !str_contains($m, 'rejected the credentials'), 'a token that works but a call-history request that gets HTTP 403 is "signed in but not allowed", not "rejected the credentials"', $m);
    connector_trace(null, true);
    [$s, $m] = connector_test('threecx', ['base_url' => 'http://127.0.0.1:3198/slow', 'verify_tls' => 1, 'account_ref' => 'apiclient', 'provider' => 'threecx', 'timeout' => 1], 'apisecret');
    check($s === 'unreachable' && str_contains($m, 'Signed in') && str_contains($m, 'did not answer the call-history request in time') && str_contains(implode("\n", connector_trace()), 'curl error 28'), 'a PBX that signs in but is slow to give the call history says exactly that, and the log has the timeout', $m);
    [$s, $m] = connector_test('threecx', ['base_url' => 'http://127.0.0.1:3198/slow', 'verify_tls' => 1, 'account_ref' => 'apiclient', 'provider' => 'threecx', 'timeout' => 8], 'apisecret');
    same($s, 'ok', 'with enough time the same slow PBX connects');
    // a PBX that refuses the question in its first form: the plainer forms are tried, and what it said is in the log
    connector_trace(null, true);
    [$s, $m] = $try22('noorder', 'apiclient', 'apisecret');
    $tr22 = implode("\n", connector_trace());
    check($s === 'ok' && str_contains($tr22, 'HTTP 500: ') && str_contains($tr22, 'Invalid property in orderby') && str_contains($tr22, 'HTTP 200') && str_contains($tr22, 'form 4 of 4') && str_contains($tr22, 'SegmentId, StartTime'), 'when 3CX refuses the first form of the question (HTTP 500), the next form is tried; the log has what 3CX said and the fields of a record', $tr22);
    $res22 = threecx_collect(['base_url' => 'http://127.0.0.1:3198/noorder', 'verify_tls' => 1, 'account_ref' => 'apiclient'], 'apisecret', time() - 35 * 86400);
    same([$res22['state'], count($res22['calls'])], ['ok', 1], 'a sync with that PBX stores the call of last ten minutes and leaves out the one from 90 days ago');
    // the PBX of the owner: the call-history list answers HTTP 500 for a filter, the call-log function of the reports works
    connector_trace(null, true);
    [$s, $m] = $try22('logdata', 'apiclient', 'apisecret');
    $tr22 = implode("\n", connector_trace());
    check($s === 'ok' && str_contains($tr22, 'GetCallLogData(periodFrom=') && str_contains($tr22, 'form 1 of 4') && str_contains($tr22, 'TalkingDuration') && !str_contains($tr22, 'CallHistoryView'), 'the call-log function with a period is tried first and works, without ever asking for the whole history', $tr22);
    connector_trace(null, true);
    $res22 = threecx_collect(['base_url' => 'http://127.0.0.1:3198/logdata', 'verify_tls' => 1, 'account_ref' => 'apiclient'], 'apisecret', time() - 35 * 86400);
    $syncTrace22 = implode("\n", connector_trace());
    same([$res22['state'], count($res22['calls'])], ['ok', 3], 'a sync through it makes five segments into three calls (a call that was queued, answered and rung elsewhere is one call)');
    $byId22 = array_column($res22['calls'], null, 'external_id');
    $c1 = $byId22['mm-1'] ?? [];
    check($c1 && $c1['direction'] === 'in' && $c1['status'] === 'answered' && $c1['duration_s'] === 140 && $c1['from'] === '+31101234567' && $c1['to'] === '100', 'the call: incoming, answered, 140 seconds (waiting and the unanswered ring are not counted), from the caller to the colleague who answered', json_encode($c1));
    check(($c1['remote_name'] ?? '') === 'Jan Jansen' && ($c1['staff_name'] ?? '') === 'Noor Haddad', 'and it carries the names the PBX has: the caller, and the colleague who took the call (not the queue)');
    $c2 = $byId22['mm-2'] ?? [];
    check($c2 && $c2['status'] === 'missed' && $c2['duration_s'] === 0 && $c2['remote_name'] === '' && $c2['staff_name'] === 'Sam Rivera', 'a call nobody answered is missed, with no length, and a "name" that is only the number again is left out');
    $c3 = $byId22['mm-3'] ?? [];
    check($c3 && $c3['direction'] === 'out' && $c3['status'] === 'answered' && $c3['duration_s'] === 30 && $c3['to'] === '+31201112222' && $c3['remote_name'] === 'Piet Bakker' && $c3['staff_name'] === 'Noor Haddad', 'an outgoing call has the colleague who made it and the person that was called');
    $ids22 = array_column($res22['calls'], 'external_id');
    $again22 = threecx_collect(['base_url' => 'http://127.0.0.1:3198/logdata', 'verify_tls' => 1, 'account_ref' => 'apiclient'], 'apisecret', time() - 35 * 86400);
    check(array_column($again22['calls'], 'external_id') === $ids22, 'and the same record gets the same ID at the next sync, so it is updated and not added again');
    check(str_contains($syncTrace22, 'ID fields of the first records: #1 MainCallHistoryId=m-1 CallHistoryId=h-1a') && str_contains($syncTrace22, '5 segments read, made into 3 calls'), 'the log shows the ID fields of the first records, and how many segments became how many calls', $syncTrace22);
    // stored, and shown on the Calls page: the names, the Staff filter, the Person column
    $pdo->exec("DELETE FROM comm_log WHERE provider = 'zz22'");
    comm_store($pdo, array_map(static fn($r) => ['provider' => 'zz22'] + $r, $res22['calls']), false);
    same((string) $pdo->query("SELECT CONCAT(remote_name, '|', staff_name) FROM comm_log WHERE provider = 'zz22' AND external_id = 'mm-1'")->fetchColumn(), 'Jan Jansen|Noor Haddad', 'the names are stored with the call');
    $page22 = http('GET', "$base/calls/calls.php?q=%2B3110", ['jar' => $admin])['body'];
    check(str_contains($page22, 'Jan Jansen') && str_contains($page22, '(phone system)') && str_contains($page22, 'Noor Haddad'), 'the Calls page shows the name from the phone system in the Person column when nobody in the CRM matches, and the colleague under Staff');
    $staff22 = http('GET', "$base/calls/calls.php?staff=" . urlencode('Sam Rivera'), ['jar' => $admin])['body'];
    check(str_contains($staff22, '+31107654321') && !str_contains($staff22, '+31101234567') && str_contains($staff22, 'value="Sam Rivera"'), 'a link to one colleague (?staff=) still shows only that colleague\'s calls, and fills the Person box');
    $who22 = http('GET', "$base/calls/calls.php?q=%2B31", ['jar' => $admin])['body'];
    preg_match('#<script type="application/json" id="f-who-data">(.*?)</script>#s', $who22, $dm);
    $nm22 = json_decode($dm[1] ?? '[]', true) ?: [];
    check(str_contains($who22, 'role="combobox"') && str_contains($who22, 'id="f-who-list"') && in_array('Noor Haddad', $nm22, true) && in_array('Jan Jansen', $nm22, true) && in_array('Piet Bakker', $nm22, true) && count($nm22) === count(array_unique($nm22)), 'the Person box is a search box and its list holds every caller and colleague the phone system named, each once (also those who are both)');
    check(!str_contains($who22, '<datalist') && str_contains($who22, 'aria-label="Clear the name"') && preg_match('/placeholder="Search \d+ names"/', $who22) === 1, 'it has its own styled list (not the one of the browser), a clear button and the number of names in its placeholder');
    $jan22 = http('GET', "$base/calls/calls.php?who=jan+jan", ['jar' => $admin])['body'];
    check(str_contains($jan22, '+31101234567') && !str_contains($jan22, '+31107654321') && str_contains($jan22, 'value="jan jan"'), 'typing a part of a caller name finds the calls of that caller, whatever the capitals');
    $noor22 = http('GET', "$base/calls/calls.php?who=" . urlencode('Noor Haddad'), ['jar' => $admin])['body'];
    check(str_contains($noor22, '+31101234567') && str_contains($noor22, '+31201112222') && !str_contains($noor22, '+31107654321'), 'a colleague\'s name finds the calls they took and the ones they made');
    check(!str_contains(http('GET', "$base/calls/calls.php?who=" . urlencode('%_'), ['jar' => $admin])['body'], '+31101234567'), 'the characters % and _ in the box are searched literally, not as wildcards');
    check(str_contains(http('GET', "$base/calls/calls.php?q=Piet", ['jar' => $admin])['body'], '+31201112222'), 'and the number box also finds a call by the name of the caller or the colleague');
    $pdo->exec("DELETE FROM comm_log WHERE provider = 'zz22'");
    // service badges: a logo when there is a file, otherwise a plain badge; the file name is never taken from outside
    $svcDir22 = TESTS_DIR . '/../assets/img/services';
    $madeDir22 = !is_dir($svcDir22) && @mkdir($svcDir22, 0775, true);
    check(str_contains(service_badge('zzlogo22', 'ZZ Logo'), 'ZZL') && !str_contains(service_badge('zzlogo22', 'ZZ Logo'), '<img'), 'a service without a logo file gets a plain badge with its name');
    file_put_contents($svcDir22 . '/zzlogo22.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><rect width="8" height="8"/></svg>');
    $withLogo22 = service_badge('zzlogo22', 'ZZ Logo');
    check(str_contains($withLogo22, '<img src="assets/img/services/zzlogo22.svg"') && str_contains($withLogo22, 'ZZ Logo'), 'a service with a logo file shows the logo and its name');
    @unlink($svcDir22 . '/zzlogo22.svg');
    if ($madeDir22) { @rmdir($svcDir22); }
    check(!str_contains(service_badge('../../includes/config.local', 'x'), '<img') && !str_contains(service_badge("zz\"><script>", 'x'), '<script>'), 'a service name with path or markup characters never gives a file or a tag');
    check(str_contains(http('GET', "$base/settings/settings.php?tab=connectors", ['jar' => $admin])['body'], '>3CX</span>'), 'the connector cards show the service badge');
    // a sync keeps what is already stored: new calls are added, old ones are left alone, recent ones are refreshed
    $mk22 = static fn(string $id, int $agoDays, int $dur, string $name) => ['provider' => 'zz22', 'kind' => 'call', 'external_id' => $id, 'direction' => 'in', 'from' => '+31100000001', 'to' => '100', 'remote_name' => $name, 'staff_name' => 'Staff',
        'party_key' => '100000001', 'started_at' => date('Y-m-d H:i:s', time() - $agoDays * 86400 - ($agoDays === 0 ? 3600 : 0)), 'duration_s' => $dur, 'status' => 'answered', 'body' => ''];
    $pdo->exec("DELETE FROM comm_log WHERE provider = 'zz22'");
    same(comm_store($pdo, [$mk22('old', 10, 5, 'Old name'), $mk22('fresh', 0, 5, 'Old name')], false), 2, 'two calls are stored');
    same(comm_store_stats(), ['new' => 2, 'refreshed' => 0, 'kept' => 0], 'both are new');
    $pdo->exec("UPDATE comm_log SET user_id = NULL, company_id = NULL WHERE provider = 'zz22'");
    comm_store($pdo, [$mk22('old', 10, 99, 'New name'), $mk22('fresh', 0, 99, 'New name'), $mk22('extra', 3, 7, 'Extra')], false);
    same(comm_store_stats(), ['new' => 1, 'refreshed' => 1, 'kept' => 1], 'at the next sync one call is new, the recent one is updated and the old one is kept');
    same($pdo->query("SELECT CONCAT(duration_s, '|', remote_name) FROM comm_log WHERE provider = 'zz22' AND external_id = 'old'")->fetchColumn(), '5|Old name', 'a call from 10 days ago is left exactly as it was, although the service now says something else');
    same($pdo->query("SELECT CONCAT(duration_s, '|', remote_name) FROM comm_log WHERE provider = 'zz22' AND external_id = 'fresh'")->fetchColumn(), '99|New name', 'a call from today is updated (it can still change)');
    check(str_contains(connector_ok_text('3CX', 3, true), '1 new, 1 updated') && str_contains(connector_ok_text('3CX', 3, true), '1 already stored and kept as they were'), 'the message after a sync says what was new, updated and kept');
    $pdo->exec("DELETE FROM comm_log WHERE provider = 'zz22'");
    $storeRows22 = array_map(fn($r) => $r + ['kind' => 'call'], [['provider' => 'zz22', 'external_id' => 'a', 'direction' => 'in', 'from' => '1', 'to' => '2', 'party_key' => '', 'started_at' => date('Y-m-d H:i:s'), 'duration_s' => 1, 'status' => 'answered', 'body' => ''], ['provider' => 'zz22', 'external_id' => 'a', 'direction' => 'in', 'from' => '3', 'to' => '4', 'party_key' => '', 'started_at' => date('Y-m-d H:i:s'), 'duration_s' => 2, 'status' => 'answered', 'body' => ''], ['provider' => 'zz22', 'external_id' => 'b', 'direction' => 'in', 'from' => '5', 'to' => '6', 'party_key' => '', 'started_at' => date('Y-m-d H:i:s'), 'duration_s' => 3, 'status' => 'answered', 'body' => '']]);
    same(comm_store($pdo, $storeRows22, false), 2, 'records that share an ID are counted once: three records with two different IDs are two stored rows, not three');
    $pdo->exec("DELETE FROM comm_log WHERE provider = 'zz22'");
    same([threecx_seconds(42), threecx_seconds('42'), threecx_seconds('00:01:30'), threecx_seconds('1.00:00:05'), threecx_seconds('PT1M30S'), threecx_seconds('PT2H'), threecx_seconds('PT0.5S'), threecx_seconds('soon'), threecx_seconds(null)], [42, 42, 90, 86405, 90, 7200, 0, 0, 0], 'durations as 3CX writes them (seconds, hh:mm:ss, d.hh:mm:ss, ISO 8601) become whole seconds');
    connector_trace(null, true);
    [$s, $m] = $try22('broken', 'apiclient', 'apisecret');
    $tr22 = implode("\n", connector_trace());
    check($s === 'invalid' && str_contains($m, 'HTTP 500') && str_contains($m, 'send it to the developer') && substr_count($tr22, 'CallHistoryView') >= 3 && str_contains($tr22, 'CallHistoryView exists and has the fields: SegmentId, StartTime, SourceDn') && str_contains($tr22, 'GetCallLogData'), 'when no form works, the log says what the PBX offers: whether the view exists, its fields and other call-log views', $m . ' | ' . $tr22);
    same($try22('noperm', 'apiclient', 'wrong')[0], 'unauthorized', 'a real credentials refusal is still "unauthorized"');
    same($try22('api', 'apiclient', "x\r\nInjected: 1")[0], 'unauthorized', 'a secret with a line break in it is refused and breaks nothing');
    same(connector_test('threecx', ['base_url' => 'http://127.0.0.1:3197', 'verify_tls' => 1, 'account_ref' => 'a', 'provider' => 'threecx'], 'b')[0], 'unreachable', 'a PBX that does not answer is reported as unreachable, with no second try');
    check(!str_contains(json_encode($try22('api', 'apiclient', 'secret-DO-NOT-SHOW')), 'DO-NOT-SHOW'), 'the message never contains the secret');

    section('75. Connector log: what each test and sync left behind');
    $pdo->exec("DELETE FROM connector_log WHERE provider = 'threecx'");
    $saved22 = $pdo->query("SELECT * FROM connectors WHERE provider = 'threecx'")->fetch() ?: null;
    $form22 = ['tab' => 'connectors', 'provider' => 'threecx', 'action' => 'test', 'base_url' => 'http://127.0.0.1:3198/api', 'account_ref' => 'apiclient', 'secret' => 'wrong-SECRET-22'];
    $r = $post($admin, 'settings/settings.php?tab=connectors', $form22, 'settings/settings.php?tab=connectors');
    $e = $pdo->query("SELECT * FROM connector_log WHERE provider = 'threecx' ORDER BY id DESC LIMIT 1")->fetch();
    check($e && $e['action'] === 'test' && $e['state'] === 'unauthorized', 'a test from the settings page is written to the log with its result');
    check(str_contains((string) $e['message'], 'refused both') && str_contains((string) $e['message'], 'invalid_client'), 'with the message that was shown');
    check(str_contains((string) $e['detail'], 'POST 127.0.0.1:3198/api/connect/token -> HTTP 400, invalid_client') && str_contains((string) $e['detail'], 'POST 127.0.0.1:3198/api/webclient/api/Login/GetAccessToken -> HTTP 404'), 'and one line per request: address, HTTP code and the error code of the refusal', (string) $e['detail']);
    check(!str_contains(json_encode($e), 'SECRET-22') && !str_contains(json_encode($e), 'apisecret'), 'the log never holds the secret');
    $page = http('GET', "$base/settings/settings.php?tab=connectors", ['jar' => $admin])['body'];
    check(str_contains($page, 'POST 127.0.0.1:3198/api/connect/token') && str_contains($page, 'invalid_client'), 'the Connectors tab shows the log under the connector');
    check(preg_match('/<details class="mt-4 rounded-xl border border-line" open>/', $page) === 1, 'and opens it by itself when the last result was a failure');
    $post($admin, 'settings/settings.php?tab=connectors', ['secret' => 'apisecret'] + $form22, 'settings/settings.php?tab=connectors');
    $e = $pdo->query("SELECT * FROM connector_log WHERE provider = 'threecx' ORDER BY id DESC LIMIT 1")->fetch();
    check($e['state'] === 'ok' && str_contains((string) $e['detail'], 'xapi/v1/CallHistoryView') && str_contains((string) $e['detail'], 'HTTP 200'), 'a good test is logged too, down to the call-history request');
    $post($admin, 'settings/settings.php?tab=connectors', ['base_url' => 'http://127.0.0.1:3198/noperm', 'secret' => 'apisecret'] + $form22, 'settings/settings.php?tab=connectors');
    $e = $pdo->query("SELECT * FROM connector_log WHERE provider = 'threecx' ORDER BY id DESC LIMIT 1")->fetch();
    check($e['state'] === 'forbidden' && str_contains((string) $e['detail'], 'connect/token -> HTTP 200') && str_contains((string) $e['detail'], '-> HTTP 403'), 'the log shows the token request that worked and the call-history request that was refused', (string) $e['detail']);
    $closed = $form22; $closed['base_url'] = 'http://127.0.0.1:3197';
    $post($admin, 'settings/settings.php?tab=connectors', $closed, 'settings/settings.php?tab=connectors');
    $e = $pdo->query("SELECT * FROM connector_log WHERE provider = 'threecx' ORDER BY id DESC LIMIT 1")->fetch();
    check($e['state'] === 'unreachable' && str_contains((string) $e['detail'], 'no answer') && str_contains((string) $e['detail'], 'curl error'), 'a PBX that does not answer is logged with the connection error', (string) $e['detail']);
    $blocked = $form22; $blocked['base_url'] = 'http://169.254.169.254';
    $post($admin, 'settings/settings.php?tab=connectors', $blocked, 'settings/settings.php?tab=connectors');
    same((int) $pdo->query("SELECT COUNT(*) FROM connector_log WHERE provider = 'threecx'")->fetchColumn(), 4, 'an address that is refused before any test is made adds no entry');
    for ($i = 0; $i < 45; $i++) { connector_trace(null, true); connector_log_add($pdo, 'threecx', 'test', 'ok', 'filler ' . $i, 'tester'); }
    same((int) $pdo->query("SELECT COUNT(*) FROM connector_log WHERE provider = 'threecx'")->fetchColumn(), 40, 'only the newest entries of a connector are kept');
    same(substr_count($page = http('GET', "$base/settings/settings.php?tab=connectors", ['jar' => $admin])['body'], 'filler '), 10, 'and the page shows the latest ten');
    $pdo->exec("UPDATE connector_log SET created_at = NOW() - INTERVAL 100 DAY WHERE provider = 'threecx' AND message = 'filler 0'");
    purge_expired_data($pdo);
    same((int) $pdo->query("SELECT COUNT(*) FROM connector_log WHERE message = 'filler 0'")->fetchColumn(), 0, 'bin/purge.php removes entries older than 90 days');
    same($pdo->query("SELECT CONCAT(enabled, base_url, account_ref) FROM connectors WHERE provider = 'threecx'")->fetchColumn() ?: null, $saved22 ? $saved22['enabled'] . $saved22['base_url'] . $saved22['account_ref'] : null, 'testing never changes the saved connector settings');
    $pdo->exec("DELETE FROM connector_log WHERE provider = 'threecx'");
} finally {
    $pdo->exec("DELETE FROM comm_log WHERE provider = 'zz22'");
    $pdo->exec("DELETE FROM connector_log WHERE provider = 'threecx'");
    foreach ($logBackup22 as $row22) { $cols22 = array_keys($row22); $pdo->prepare('INSERT INTO connector_log (`' . implode('`,`', $cols22) . '`) VALUES (' . implode(',', array_fill(0, count($cols22), '?')) . ')')->execute(array_values($row22)); }
    if (is_resource($proc22)) { proc_terminate($proc22); proc_close($proc22); }
}
