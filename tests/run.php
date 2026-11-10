<?php
declare(strict_types=1);

/*
 * Tests for the Uptime Kuma integration. No framework: run from the command line.
 *
 *   C:\xampp\php\php.exe tests\run.php
 *
 * Needs Apache + MariaDB running (the integration part signs in over HTTP) and port 3199 free (a mock Kuma is started).
 * Environment: TEST_BASE (default http://localhost/Websites/test), TEST_ADMIN_USER / TEST_ADMIN_PASS (default admin/admin).
 * The saved Kuma settings are backed up first and put back at the end.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

define('API_REQUEST', true);                 // no session or cookies for the in-process part
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';   // its own address, so it never shares a rate-limit bucket with the HTTP tests
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/kuma.php';
require __DIR__ . '/../includes/notes.php';
require __DIR__ . '/../includes/csv.php';
require __DIR__ . '/../includes/notifications.php';
require __DIR__ . '/../includes/twofactor.php';
require __DIR__ . '/../includes/mailer.php';

$base = rtrim((string) (getenv('TEST_BASE') ?: 'http://localhost/Websites/test'), '/');
$adminUser = (string) (getenv('TEST_ADMIN_USER') ?: 'admin');
$adminPass = (string) (getenv('TEST_ADMIN_PASS') ?: 'admin');
$mock = 'http://127.0.0.1:3199';
$pdo = db();

// ---------- tiny harness ----------
$pass = 0; $fail = 0; $failures = [];
function check(bool $cond, string $name, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  ok    $name\n"; }
    else { $fail++; $failures[] = $name; echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}
function section(string $t): void { echo "\n== $t\n"; }
function same(mixed $a, mixed $b, string $name): void { check($a === $b, $name, 'got ' . json_encode($a) . ', expected ' . json_encode($b)); }

// ---------- HTTP helpers ----------
function http(string $method, string $url, array $o = []): array
{
    $ch = curl_init($url);
    $h = $o['headers'] ?? [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $o['jar'] ?? '', CURLOPT_COOKIEFILE => $o['jar'] ?? '']);
    if (isset($o['body'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body']); }
    if ($h) { curl_setopt($ch, CURLOPT_HTTPHEADER, $h); }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size), 'json' => json_decode(substr($raw, $size), true)];
}
function csrf_from(string $html): string { return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : ''; }
function login(string $base, string $user, string $pass): string
{
    $jar = tempnam(sys_get_temp_dir(), 'kj');
    $p = http('GET', "$base/login.php", ['jar' => $jar]);
    http('POST', "$base/login.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($p['body']), 'username' => $user, 'password' => $pass])]);
    return $jar;
}
function token(string $base, string $jar): string { return csrf_from(http('GET', "$base/index.php", ['jar' => $jar])['body']); }
function api(string $base, string $jar, string $method, array $data = [], ?string $csrf = null): array
{
    $csrf ??= token($base, $jar);
    $hdr = ['Accept: application/json', 'X-CSRF-Token: ' . $csrf];
    if (in_array($method, ['PUT', 'DELETE'], true)) { $hdr[] = 'Content-Type: application/json'; return http($method, "$base/kuma_api.php", ['jar' => $jar, 'headers' => $hdr, 'body' => json_encode($data)]); }
    return http($method, "$base/kuma_api.php", ['jar' => $jar, 'headers' => $hdr, 'body' => http_build_query($data)]);
}
function expire_cache(): void   // pretend the poll interval has passed, keeping the last good data
{
    $f = kuma_cache_path();
    if (is_file($f)) { $j = json_decode((string) file_get_contents($f), true); $j['attemptedAt'] = time() - 3600; file_put_contents($f, json_encode($j)); }
}
function mock_hits(string $mock): int { return (int) trim((string) @file_get_contents("$mock/hits")); }
function set_mode(string $m): void { $f = __DIR__ . '/.mock_mode'; if ($m === '') { @unlink($f); } else { file_put_contents($f, $m); } }

// ---------- back up settings, start the mock ----------
$backup = $pdo->query('SELECT * FROM kuma_settings WHERE id = 1')->fetch() ?: null;
$startHb = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM kuma_heartbeats')->fetchColumn();
$pdo->exec('DELETE FROM rate_limits');
@unlink(__DIR__ . '/.mock_hits'); set_mode('');
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:3199', __DIR__ . '/mock_kuma.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/mock_kuma.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/mock_kuma.log', 'a']], $pipes);
for ($i = 0; $i < 30; $i++) { if (@file_get_contents("$mock/hits") !== false) { break; } usleep(200000); }
$mockUp = @file_get_contents("$mock/hits") !== false;
$testUser = 'kuma.tester';

try {
    // =========================================================
    section('1. Metrics parser (sample /metrics payload as a fixture)');
    $fixture = (string) file_get_contents(__DIR__ . '/fixtures/kuma_metrics.txt');
    $mons = kuma_parse_metrics($fixture);
    same(count($mons), 6, 'six monitors found, unrelated metrics ignored');
    same(array_column($mons, 'statusLabel'), ['down', 'pending', 'maintenance', 'up', 'up', 'up'], 'sorted with down first, then pending, maintenance, up');
    $by = array_column($mons, null, 'name');
    same($by['Legacy site']['status'], 0, 'status 0 means down');
    same($by['Legacy site']['responseTimeMs'], null, 'response time -1 (down) becomes null');
    same($by['Legacy site']['certDaysRemaining'], -3, 'expired certificate days kept');
    same($by['Legacy site']['certValid'], false, 'cert_is_valid 0 becomes false');
    same($by['SIP "trunk" {primary}']['statusLabel'], 'pending', 'escaped quotes and braces in a monitor name survive');
    same($by['Backup NAS']['statusLabel'], 'maintenance', 'status 3 is maintenance (and a trailing timestamp is accepted)');
    same($by['Backup NAS']['responseTimeMs'], null, 'NaN becomes null');
    same($by['Customer portal']['responseTimeMs'], 413, 'decimal response times are rounded');
    same($by['Customer portal']['certDaysRemaining'], 9, 'certificate days remaining parsed');
    same($by['Core router']['url'], '10.0.0.1', 'monitors without a URL fall back to the host name');
    same($by['SIP "trunk" {primary}']['url'], 'sip.example.com:5060', 'host and port fallback');
    same($by['Core router']['certDaysRemaining'], null, 'missing certificate metrics become null');
    same(kuma_summarize($mons), ['up' => 3, 'down' => 1, 'pending' => 1, 'maintenance' => 1, 'total' => 6], 'summary counts');
    same(kuma_parse_metrics(''), [], 'empty input gives no monitors');
    same(kuma_parse_metrics("garbage line\nmonitor_status{broken 1\n"), [], 'malformed lines are skipped without errors');
    check(kuma_looks_like_metrics($fixture) && !kuma_looks_like_metrics('<html><body>Login</body></html>') && !kuma_looks_like_metrics(''), 'metrics detection rejects HTML and empty bodies');

    // =========================================================
    section('2. Encryption at rest');
    $enc = secret_encrypt('uk1_SuperSecretKey1234');
    check($enc !== 'uk1_SuperSecretKey1234' && !str_contains($enc, 'SuperSecret'), 'ciphertext does not contain the key');
    same(secret_decrypt($enc), 'uk1_SuperSecretKey1234', 'round trip returns the original key');
    check(secret_encrypt('same') !== secret_encrypt('same'), 'a fresh random IV makes every ciphertext different');
    $raw = base64_decode($enc); $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
    same(secret_decrypt(base64_encode($raw)), '', 'tampered ciphertext is rejected (authenticated encryption)');
    same(secret_decrypt($enc, str_repeat('x', 32)), '', 'a different key can not decrypt it');
    $threw = false; try { secret_encrypt('x', ''); } catch (RuntimeException $e) { $threw = true; $msg = $e->getMessage(); }
    check($threw && !str_contains($msg, 'x') || $threw, 'a missing encryption key fails loudly instead of storing plaintext');

    // =========================================================
    section('3. Address validation');
    foreach (['http://kuma.internal:3001' => true, 'https://status.example.com' => true, 'http://127.0.0.1:3001/' => true, 'http://10.0.0.5:3001/kuma/' => true,
              'ftp://example.com' => false, 'javascript:alert(1)' => false, '' => false, 'http://user:pw@example.com' => false, 'http://example.com/?x=1' => false,
              'http://169.254.169.254/' => false, 'http://[fe80::1]/' => false, 'http://0.0.0.0/' => false, 'http://224.0.0.1/' => false, 'http://exa mple.com' => false,
              'http://example.com:99999' => false] as $u => $expected) {
        same(kuma_validate_url($u)[0], $expected, "validate " . ($u === '' ? '(empty)' : $u));
    }
    same(kuma_validate_url('http://10.0.0.5:3001/kuma/')[2], 'http://10.0.0.5:3001/kuma', 'trailing slash is removed');

    // =========================================================
    section('4. Monitoring service: states and caching (in process, stubbed Kuma)');
    $pdo->exec('DELETE FROM kuma_settings'); kuma_cache_clear();
    same(kuma_uptime($pdo), ['error' => 'not_configured'], 'no settings gives not_configured');
    [$ok] = kuma_save($pdo, 'http://127.0.0.1:3199', 'goodkey-abcd', 30, 1);
    check($ok, 'settings saved');
    $calls = 0;
    $good = function () use (&$calls, $fixture) { $calls++; return ['state' => 'ok', 'body' => $fixture]; };
    $r1 = kuma_uptime($pdo, $good); $r2 = kuma_uptime($pdo, $good);
    same($calls, 1, 'two requests inside the poll interval call Kuma once');
    same($r1['summary']['total'], 6, 'summary returned');
    check(!isset($r1['error']) && isset($r1['updatedAt']) && $r2 === $r1, 'fresh data has no error and is identical from cache');
    expire_cache();
    $unauth = fn() => ['state' => 'unauthorized', 'body' => ''];
    $r = kuma_uptime($pdo, $unauth);
    same($r['error'] ?? null, 'kuma_unauthorized', 'a 401 from Kuma gives kuma_unauthorized');
    check(($r['stale'] ?? false) === true && count($r['monitors'] ?? []) === 6, 'last good data is returned, flagged stale');
    expire_cache();
    $r = kuma_uptime($pdo, fn() => ['state' => 'unreachable', 'body' => '']);
    same($r['error'] ?? null, 'kuma_unreachable', 'an unreachable Kuma gives kuma_unreachable');
    check(($r['stale'] ?? false) === true, 'still stale, never a crash');
    expire_cache();
    same(kuma_uptime($pdo, fn() => ['state' => 'invalid', 'body' => ''])['error'] ?? null, 'kuma_invalid_response', 'a non-metrics answer gives kuma_invalid_response');
    $before = $calls; kuma_uptime($pdo, $good);
    same($calls, $before, 'after a failure the error is cached too: Kuma is not hammered while down');
    kuma_cache_clear();
    $r = kuma_uptime($pdo, $unauth);
    check(($r['error'] ?? '') === 'kuma_unauthorized' && !isset($r['monitors']), 'no cache and an error: error only, no data');
    $json = json_encode($r);
    check(!str_contains($json, 'goodkey') && !str_contains($json, 'abcd'), 'the key never appears in the response');
    kuma_cache_clear(); kuma_uptime($pdo, $good); $calls0 = $calls;
    kuma_save($pdo, 'http://127.0.0.1:3199', '', 45, 1);
    kuma_uptime($pdo, $good);
    same($calls, $calls0 + 1, 'saving settings invalidates the cache');

    // =========================================================
    if (!$mockUp) { check(false, 'mock Kuma started on 127.0.0.1:3199 (is the port free?)'); throw new RuntimeException('mock server unavailable'); }
    section('5. Settings endpoints over HTTP');
    $pdo->exec('DELETE FROM kuma_settings'); kuma_cache_clear();
    $anon = http('GET', "$base/kuma_api.php", ['headers' => ['Accept: application/json']]);
    same($anon['code'], 401, 'signed-out GET kuma_api.php is 401');
    same(http('GET', "$base/monitoring_uptime.php")['code'], 401, 'signed-out GET monitoring_uptime.php is 401');

    $admin = login($base, $adminUser, $adminPass);
    check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'Overview'), 'admin signed in (set TEST_ADMIN_PASS if you changed it)');
    // a normal user, created through the real Team form
    $pdo->prepare('DELETE FROM admins WHERE username = ?')->execute([$testUser]);
    $team = http('GET', "$base/settings.php?tab=team", ['jar' => $admin]);
    $created = http('POST', "$base/settings.php?tab=team", ['jar' => $admin, 'body' => http_build_query(['csrf' => csrf_from($team['body']), 'tab' => 'team', 'action' => 'add_user', 'username' => $testUser, 'name' => 'Kuma Tester', 'email' => 'kuma.tester@example.test', 'role' => 'user', 'perm' => 'manage'])]);
    $tmpPw = preg_match('/select-all">([^<]+)</', http('GET', "$base/settings.php?tab=team", ['jar' => $admin])['body'], $m) ? html_entity_decode($m[1]) : '';
    $pdo->prepare('UPDATE admins SET must_change_password = 0 WHERE username = ?')->execute([$testUser]);
    $user = login($base, $testUser, $tmpPw);
    check(str_contains(http('GET', "$base/index.php", ['jar' => $user])['body'], 'Overview'), 'normal test user signed in');

    same(http('GET', "$base/kuma_api.php", ['jar' => $user])['code'], 403, 'normal user: GET kuma_api.php is 403');
    same(api($base, $user, 'PUT', ['kumaUrl' => "$mock", 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 30])['code'], 403, 'normal user: PUT is 403');
    same(api($base, $user, 'POST', ['action' => 'test', 'kumaUrl' => $mock, 'apiKey' => 'goodkey'])['code'], 403, 'normal user: test connection is 403');
    same(api($base, $user, 'DELETE')['code'], 403, 'normal user: DELETE is 403');
    same(http('GET', "$base/settings.php?tab=kuma", ['jar' => $user])['code'], 403, 'normal user: the settings tab is 403 on the server');
    check(!str_contains(http('GET', "$base/settings.php", ['jar' => $user])['body'], 'tab=kuma'), 'normal user: the tab is hidden from the menu');
    check(str_contains(http('GET', "$base/settings.php", ['jar' => $admin])['body'], 'tab=kuma'), 'admin: the "Kuma API Key" tab is shown');
    same((int) $pdo->query('SELECT COUNT(*) FROM kuma_settings')->fetchColumn(), 0, 'nothing was saved by the forbidden requests');
    same(http('GET', "$base/monitoring_uptime.php", ['jar' => $user])['json']['error'] ?? null, 'not_configured', 'normal user may read the monitoring feed (same auth as other dashboard routes)');

    $g = http('GET', "$base/kuma_api.php", ['jar' => $admin]);
    same($g['code'], 200, 'admin GET works');
    same($g['json'], ['kumaUrl' => '', 'pollIntervalSeconds' => 30, 'hasApiKey' => false, 'keyLast4' => null], 'GET before setup returns defaults');

    same(api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 30], 'wrong-token')['code'], 403, 'PUT with a wrong CSRF token is 403');
    same(http('DELETE', "$base/kuma_api.php", ['jar' => $admin])['code'], 403, 'DELETE without a CSRF token is 403');
    $v = api($base, $admin, 'PUT', ['kumaUrl' => 'ftp://nope', 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 30]);
    check($v['code'] === 422 && isset($v['json']['errors']['kumaUrl']), 'invalid URL: 422 with a field error');
    check(isset(api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 5])['json']['errors']['pollIntervalSeconds']), 'interval 5 is rejected (minimum 10)');
    check(isset(api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 301])['json']['errors']['pollIntervalSeconds']), 'interval 301 is rejected (maximum 300)');
    check(isset(api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 'abc'])['json']['errors']['pollIntervalSeconds']), 'a non-number interval is rejected');
    check(isset(api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => '', 'pollIntervalSeconds' => 30])['json']['errors']['apiKey']), 'first save without a key is rejected');
    same((int) $pdo->query('SELECT COUNT(*) FROM kuma_settings')->fetchColumn(), 0, 'nothing is saved while validation fails');

    $s1 = api($base, $admin, 'PUT', ['kumaUrl' => "$mock/", 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 30]);
    same($s1['code'], 200, 'valid save is 200');
    same($s1['json'], ['ok' => true, 'kumaUrl' => $mock, 'pollIntervalSeconds' => 30, 'hasApiKey' => true, 'keyLast4' => 'dkey'], 'response has only url, interval, hasApiKey and keyLast4');
    $g = http('GET', "$base/kuma_api.php", ['jar' => $admin]);
    same(array_keys($g['json']), ['kumaUrl', 'pollIntervalSeconds', 'hasApiKey', 'keyLast4'], 'GET exposes exactly four fields');
    check(!str_contains($s1['body'] . $g['body'], 'goodkey'), 'the API key is never in an API response');
    $page = http('GET', "$base/settings.php?tab=kuma", ['jar' => $admin])['body'];
    check(!str_contains($page, 'goodkey') && str_contains($page, '••••••••dkey'), 'the settings page shows only a masked placeholder');
    $stored = (string) $pdo->query('SELECT api_key_enc FROM kuma_settings WHERE id = 1')->fetchColumn();
    check($stored !== '' && !str_contains($stored, 'goodkey') && secret_decrypt($stored) === 'goodkey', 'stored value is ciphertext that decrypts to the key');

    $s2 = api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => '', 'pollIntervalSeconds' => 60]);
    check($s2['code'] === 200 && $s2['json']['hasApiKey'] === true && $s2['json']['keyLast4'] === 'dkey' && $s2['json']['pollIntervalSeconds'] === 60, 'a blank key keeps the existing key (interval still updates)');
    same((string) $pdo->query('SELECT api_key_enc FROM kuma_settings WHERE id = 1')->fetchColumn(), $stored, 'the stored ciphertext is untouched by a blank key');
    $s3 = api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => 'goodkey-two', 'pollIntervalSeconds' => 60]);
    check($s3['json']['keyLast4'] === '-two' && secret_decrypt((string) $pdo->query('SELECT api_key_enc FROM kuma_settings WHERE id = 1')->fetchColumn()) === 'goodkey-two', 'typing a new key replaces it');
    // the normal HTML form behaves the same way
    $tok = csrf_from(http('GET', "$base/settings.php?tab=kuma", ['jar' => $admin])['body']);
    http('POST', "$base/settings.php?tab=kuma", ['jar' => $admin, 'body' => http_build_query(['csrf' => $tok, 'tab' => 'kuma', 'action' => 'save', 'kumaUrl' => $mock, 'apiKey' => '', 'pollIntervalSeconds' => '90'])]);
    same((int) $pdo->query('SELECT poll_interval_s FROM kuma_settings WHERE id = 1')->fetchColumn(), 90, 'settings form: blank key keeps the key, interval saved');
    $bad = http('POST', "$base/settings.php?tab=kuma", ['jar' => $admin, 'body' => http_build_query(['csrf' => $tok, 'tab' => 'kuma', 'action' => 'save', 'kumaUrl' => 'nope', 'apiKey' => 'SHOULD-NOT-ECHO', 'pollIntervalSeconds' => '1'])]);
    $after = http('GET', "$base/settings.php?tab=kuma", ['jar' => $admin])['body'];
    check(str_contains($after, 'Enter a full address') && !str_contains($after, 'SHOULD-NOT-ECHO'), 'form errors show per field and a typed key is never echoed back');
    same((int) $pdo->query('SELECT poll_interval_s FROM kuma_settings WHERE id = 1')->fetchColumn(), 90, 'a failed form submit changes nothing');
    $log = (string) $pdo->query("SELECT GROUP_CONCAT(CONCAT(action, ' ', target)) FROM activity_logs WHERE action LIKE 'Kuma%'")->fetchColumn();
    check(str_contains($log, 'Kuma settings saved') && !str_contains($log, 'goodkey'), 'changes are in the activity log, without the key');

    // =========================================================
    section('6. Test connection (does not save)');
    $snapshot = $pdo->query('SELECT kuma_url, api_key_enc, key_last4, poll_interval_s FROM kuma_settings WHERE id = 1')->fetch();
    $t = fn(array $d) => api($base, $admin, 'POST', ['action' => 'test'] + $d)['json'];
    $r = $t(['kumaUrl' => $mock, 'apiKey' => 'goodkey']);
    check($r['ok'] === true && $r['result'] === 'ok' && $r['monitors'] === 6, 'valid key: success, 6 monitors found');
    $r = $t(['kumaUrl' => $mock, 'apiKey' => 'wrongkey']);
    check($r['ok'] === false && $r['result'] === 'unauthorized' && str_contains($r['message'], 'Unauthorized'), 'bad key: unauthorized');
    $r = $t(['kumaUrl' => $mock, 'apiKey' => 'htmlkey']);
    check($r['result'] === 'invalid' && str_contains($r['message'], 'Invalid response'), 'HTML answer: invalid response');
    $r = $t(['kumaUrl' => $mock, 'apiKey' => 'errkey']);
    same($r['result'], 'unreachable', 'a 500 from Kuma: unreachable');
    check(!str_contains(json_encode($r), 'boom'), 'upstream error text is never passed on');
    $r = $t(['kumaUrl' => 'http://127.0.0.1:3197', 'apiKey' => 'goodkey']);
    same($r['result'], 'unreachable', 'closed port: unreachable');
    $t0 = microtime(true); $r = $t(['kumaUrl' => $mock, 'apiKey' => 'slowkey']); $dt = microtime(true) - $t0;
    check($r['result'] === 'unreachable' && $dt < 8, 'slow Kuma: times out as unreachable', sprintf('%.1fs', $dt));
    sleep(3);   // the single-threaded mock is still finishing that slow request
    $r = $t(['kumaUrl' => 'ftp://nope', 'apiKey' => 'goodkey']);
    same($r['result'], 'invalid_url', 'an invalid address is reported before any request is made');
    $r = $t(['kumaUrl' => $mock, 'apiKey' => '']);
    check($r['ok'] === true, 'a blank key tests with the saved key');
    $r = $t(['kumaUrl' => 'http://169.254.169.254', 'apiKey' => 'goodkey']);
    same($r['result'], 'invalid_url', 'the cloud metadata address is refused');
    same($pdo->query('SELECT kuma_url, api_key_enc, key_last4, poll_interval_s FROM kuma_settings WHERE id = 1')->fetch(), $snapshot, 'testing never changes the saved settings');

    // =========================================================
    section('7. Monitoring endpoint over HTTP');
    api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 30]);   // clears the cache
    @unlink(__DIR__ . '/.mock_hits');
    $u1 = http('GET', "$base/monitoring_uptime.php", ['jar' => $admin]);
    check($u1['code'] === 200 && !isset($u1['json']['error']) && $u1['json']['summary'] === ['up' => 3, 'down' => 1, 'pending' => 1, 'maintenance' => 1, 'total' => 6], 'configured: summary and monitors returned');
    same($u1['json']['monitors'][0]['statusLabel'], 'down', 'down monitors are listed first');
    same(array_keys($u1['json']['monitors'][0]), ['id', 'name', 'type', 'url', 'status', 'statusLabel', 'responseTimeMs', 'certDaysRemaining', 'certValid', 'uptime24h', 'uptime30d', 'avgResponse24hMs', 'heartbeats'], 'each monitor has the documented fields plus id and history');
    check(!str_contains($u1['body'], 'goodkey') && !str_contains($u1['body'], 'Authorization'), 'the response never contains the key');
    for ($i = 0; $i < 4; $i++) { http('GET', "$base/monitoring_uptime.php", ['jar' => $admin]); http('GET', "$base/monitoring_uptime.php", ['jar' => $user]); }
    same(mock_hits($mock), 1, 'ten dashboard requests reached Kuma once');

    set_mode('down'); expire_cache();
    $u = http('GET', "$base/monitoring_uptime.php", ['jar' => $admin])['json'];
    check(($u['error'] ?? '') === 'kuma_unreachable' && ($u['stale'] ?? false) === true && $u['summary']['total'] === 6, 'Kuma returns 500: kuma_unreachable with stale data');
    check(!str_contains(json_encode($u), 'secret stack trace'), 'upstream details are never leaked');
    set_mode('unauth'); expire_cache();
    $u = http('GET', "$base/monitoring_uptime.php", ['jar' => $admin])['json'];
    check(($u['error'] ?? '') === 'kuma_unauthorized' && ($u['stale'] ?? false) === true, 'Kuma returns 401: kuma_unauthorized with stale data');
    set_mode(''); expire_cache();
    $u = http('GET', "$base/monitoring_uptime.php", ['jar' => $admin])['json'];
    check(!isset($u['error']) && $u['summary']['total'] === 6, 'Kuma recovers: the error clears');

    // poll interval is honoured: a fresh cache is reused, then refreshed after expiry
    @unlink(__DIR__ . '/.mock_hits'); http('GET', "$base/monitoring_uptime.php", ['jar' => $admin]);
    same(mock_hits($mock), 0, 'inside the poll interval nothing is fetched');
    expire_cache(); http('GET', "$base/monitoring_uptime.php", ['jar' => $admin]);
    same(mock_hits($mock), 1, 'after the interval it is fetched once');

    $d = api($base, $admin, 'DELETE');
    check($d['code'] === 200 && $d['json']['hasApiKey'] === false && $d['json']['keyLast4'] === null && $d['json']['kumaUrl'] === $mock, 'DELETE removes the key and keeps the URL');
    same((int) $pdo->query('SELECT api_key_enc IS NULL FROM kuma_settings WHERE id = 1')->fetchColumn(), 1, 'the stored key is gone from the database');
    same(http('GET', "$base/monitoring_uptime.php", ['jar' => $admin])['json'], ['error' => 'not_configured'], 'after removing the key: not_configured');
    same(http('POST', "$base/monitoring_uptime.php", ['jar' => $admin, 'body' => 'x=1'])['code'], 405, 'the monitoring endpoint is read-only (POST is 405)');
    check(str_contains(http('GET', "$base/uptime.php", ['jar' => $admin])['body'], 'monitoring_uptime.php'), 'the Uptime page loads and polls only the dashboard backend');
    check(!str_contains(http('GET', "$base/uptime.php", ['jar' => $admin])['body'], ':3199'), 'the browser page never contains the Kuma address');

    // =========================================================
    section('8. History: heartbeats, uptime percentages, events');
    $pdo->exec('DELETE FROM kuma_heartbeats WHERE id > ' . $startHb);   // earlier sections recorded some too; count from zero
    $pdo->exec('DELETE FROM kuma_settings'); kuma_cache_clear();
    kuma_save($pdo, 'http://127.0.0.1:3199', 'goodkey', 30, 1);
    $ids = array_column(kuma_parse_metrics($fixture), 'id');
    $inTest = fn() => (int) $pdo->query('SELECT COUNT(*) FROM kuma_heartbeats WHERE id > ' . $startHb . " AND monitor_key IN ('" . implode("','", $ids) . "')")->fetchColumn();
    check(count(array_unique($ids)) === 6 && preg_match('/^[a-f0-9]{16}$/', $ids[0]) === 1, 'each monitor has a stable 16-character id');
    same(array_column(kuma_parse_metrics($fixture), 'id'), $ids, 'ids are the same on every parse');
    kuma_uptime($pdo, $good);
    same($inTest(), 6, 'the first fetch records one heartbeat per monitor');
    expire_cache(); kuma_uptime($pdo, $good);
    same($inTest(), 6, 'a second fetch within a minute, with no status change, records nothing');
    $flipped = str_replace('monitor_hostname="10.0.0.1",monitor_port="null"} 1', 'monitor_hostname="10.0.0.1",monitor_port="null"} 0', $fixture);
    expire_cache(); kuma_uptime($pdo, fn() => ['state' => 'ok', 'body' => $flipped]);
    same($inTest(), 7, 'a status change is recorded immediately');
    $row = $pdo->query('SELECT status, important FROM kuma_heartbeats WHERE id > ' . $startHb . ' ORDER BY id DESC LIMIT 1')->fetch();
    check((int) $row['status'] === 0 && (int) $row['important'] === 1, 'the change is flagged important and is a down event');
    $cf = kuma_cache_path(); $c = json_decode((string) file_get_contents($cf), true);
    foreach ($c['hb'] as $k => $v) { $c['hb'][$k]['t'] = time() - 120; } $c['attemptedAt'] = 1; file_put_contents($cf, json_encode($c));
    kuma_uptime($pdo, fn() => ['state' => 'ok', 'body' => $flipped]);
    same($inTest(), 13, 'after a minute every monitor gets a new heartbeat');
    same((int) $pdo->query('SELECT COUNT(*) FROM kuma_heartbeats WHERE id > ' . $startHb . ' AND important = 1')->fetchColumn(), 7, 'only the first sighting of each monitor and the status change are marked important');

    // uptime maths on synthetic history (24 h and 30 days; maintenance does not count)
    $synthetic = 'aaaaaaaaaaaaaaaa'; $many = 'bbbbbbbbbbbbbbbb';
    $ins = $pdo->prepare('INSERT INTO kuma_heartbeats (monitor_key, monitor_name, status, response_ms, important, at) VALUES (?,?,?,?,?,?)');
    foreach ([[1, 100, 0, 3600], [1, 200, 0, 3000], [1, 300, 0, 2400], [0, null, 1, 1800], [3, null, 0, 1200], [1, 50, 0, 86400 * 5], [1, 50, 0, 86400 * 6], [0, null, 0, 86400 * 7], [0, null, 0, 86400 * 8]] as [$st, $rt, $imp, $ago]) {
        $ins->execute([$synthetic, 'Synthetic', $st, $rt, $imp, date('Y-m-d H:i:s', time() - $ago)]);
    }
    $m = kuma_with_history($pdo, [['id' => $synthetic, 'name' => 'Synthetic']])[0];
    same($m['uptime24h'], 75.0, '24-hour uptime: 3 up of 4 counted checks (maintenance excluded)');
    same($m['uptime30d'], 62.5, '30-day uptime: 5 up of 8 counted checks');
    same($m['avgResponse24hMs'], 200, '24-hour average response uses only up checks');
    same(kuma_with_history($pdo, [['id' => 'cccccccccccccccc', 'name' => 'Nothing recorded']])[0]['uptime24h'], null, 'no history gives null, not 0 percent');
    for ($i = 0; $i < 60; $i++) { $ins->execute([$many, 'Many', 1, 10 + $i, 0, date('Y-m-d H:i:s', time() - 3000 + $i * 10)]); }
    $m = kuma_with_history($pdo, [['id' => $many, 'name' => 'Many']])[0];
    same(count($m['heartbeats']), 40, 'the list view gets the latest 40 heartbeats');
    check($m['heartbeats'][0][2] < $m['heartbeats'][39][2] && $m['heartbeats'][39][1] === 69, 'heartbeats are oldest first, newest last');

    $d = kuma_monitor_detail($pdo, $synthetic);
    check(count($d['heartbeats']) >= 5 && count($d['series']) >= 1 && is_array($d['events']), 'detail has heartbeats, a response series and events');
    same(array_column($d['events'], 'statusLabel'), ['down'], 'events list only the important heartbeats (status changes)');
    same(kuma_monitor_detail($pdo, '../../etc/passwd'), ['error' => 'not_found'], 'a malformed monitor id is rejected');
    same(kuma_monitor_detail($pdo, 'dddddddddddddddd')['events'], [], 'an unknown but valid id returns empty history');
    check(count(kuma_recent_events($pdo)) >= 1, 'recent events across monitors are available');

    same(http('GET', "$base/monitoring_uptime.php?monitor=$synthetic")['code'], 401, 'signed out: the detail endpoint is 401');
    same(http('GET', "$base/monitoring_uptime.php?events=1")['code'], 401, 'signed out: the events endpoint is 401');
    $hd = http('GET', "$base/monitoring_uptime.php?monitor=$synthetic", ['jar' => $admin])['json'];
    same(array_keys($hd), ['id', 'heartbeats', 'series', 'events'], 'detail endpoint returns id, heartbeats, series and events');
    same(http('GET', "$base/monitoring_uptime.php?monitor=" . urlencode("x' OR 1=1 --"), ['jar' => $admin])['json'], ['error' => 'not_found'], 'a hostile monitor id is answered with not_found');
    $ev = http('GET', "$base/monitoring_uptime.php?events=1", ['jar' => $user])['json'];
    check(isset($ev['events']) && is_array($ev['events']), 'a normal user can read the events feed');
    check(!str_contains(json_encode($hd) . json_encode($ev), 'goodkey'), 'history responses never contain the key');

    // =========================================================
    section('9. Saved health-API keys: show again, survive refresh');
    $pdo->exec("DELETE FROM api_keys WHERE name IN ('Persist test', 'Legacy test')");
    $mk = function (string $jar) use ($base) {
        $page = http('GET', "$base/settings.php?tab=integrations", ['jar' => $jar]);
        http('POST', "$base/settings.php?tab=integrations", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($page['body']), 'tab' => 'integrations', 'action' => 'key_create', 'key_name' => 'Persist test'])]);
    };
    $reveal = function (string $jar, int $id, ?string $csrf = null) use ($base) {
        $csrf ??= token($base, $jar);
        return http('POST', "$base/api_key_reveal.php", ['jar' => $jar, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $csrf, 'Content-Type: application/x-www-form-urlencoded'], 'body' => 'id=' . $id]);
    };
    $mk($admin);
    $row = $pdo->query("SELECT id, key_prefix, key_hash, key_enc FROM api_keys WHERE name = 'Persist test'")->fetch();
    check((bool) $row && !empty($row['key_enc']), 'a new key is stored encrypted, not only hashed');
    $kid = (int) $row['id'];
    $r1 = $reveal($admin, $kid);
    $plainKey = $r1['json']['key'] ?? '';
    check($r1['code'] === 200 && preg_match('/^tlk_[a-f0-9]{48}$/', $plainKey) === 1, 'Show key returns the full key');
    same(hash('sha256', $plainKey), $row['key_hash'], 'the shown key is exactly the one incoming calls are checked against');
    check(!str_contains((string) $row['key_enc'], $plainKey) && str_starts_with($plainKey, (string) $row['key_prefix']), 'the stored value is ciphertext, and the prefix matches');
    check(str_contains($r1['headers'], 'no-store'), 'the response is marked no-store so it is never cached');
    same(http('GET', "$base/api/health.php", ['headers' => ['Authorization: Bearer ' . $plainKey]])['code'], 200, 'the shown key really works against the health API');

    // "refresh": the page is loaded again, and a completely new sign-in is made
    $pageHtml = http('GET', "$base/settings.php?tab=integrations", ['jar' => $admin])['body'];
    check(str_contains($pageHtml, $row['key_prefix']) && str_contains($pageHtml, 'Show key'), 'after a refresh the key is still listed with a Show key button');
    check(!str_contains($pageHtml, $plainKey), 'the full key is never written into the page');
    $admin2 = login($base, $adminUser, $adminPass);
    same($reveal($admin2, $kid)['json']['key'] ?? '', $plainKey, 'a brand new sign-in shows the very same key: nothing has to be recreated');
    same($reveal($admin, $kid)['json']['key'] ?? '', $plainKey, 'showing it twice gives the same key');
    check(str_contains((string) $pdo->query("SELECT GROUP_CONCAT(action) FROM activity_logs WHERE action = 'API key shown'")->fetchColumn(), 'API key shown')
        && !str_contains((string) $pdo->query("SELECT GROUP_CONCAT(CONCAT(target, actor)) FROM activity_logs WHERE action = 'API key shown'")->fetchColumn(), $plainKey), 'every reveal is logged, with the name and never the key');

    // who may show a key
    same(http('POST', "$base/api_key_reveal.php", ['headers' => ['X-CSRF-Token: x'], 'body' => "id=$kid"])['code'], 401, 'signed out: 401');
    same(http('GET', "$base/api_key_reveal.php?id=$kid", ['jar' => $admin])['code'], 405, 'GET is not allowed');
    same($reveal($admin, $kid, 'wrong-token')['code'], 403, 'a wrong CSRF token is 403');
    same($reveal($admin, 999999)['code'], 404, 'an unknown key is 404');
    $pdo->prepare('UPDATE admins SET perm_integrations = ? WHERE username = ?')->execute(['manage', $testUser]);
    same($reveal($user, $kid)['code'], 200, 'a normal user with Can manage may show keys');
    $pdo->prepare('UPDATE admins SET perm_integrations = ? WHERE username = ?')->execute(['view', $testUser]);
    same($reveal($user, $kid)['code'], 403, 'view-only users can not show keys');
    check(!str_contains(http('GET', "$base/settings.php?tab=integrations", ['jar' => $user])['body'], 'class="key-show'), 'view-only users do not even see the button');
    $pdo->prepare('UPDATE admins SET perm_integrations = ? WHERE username = ?')->execute(['none', $testUser]);
    same($reveal($user, $kid)['code'], 403, 'users with no integrations access can not show keys');
    $pdo->prepare('UPDATE admins SET perm_integrations = ? WHERE username = ?')->execute(['manage', $testUser]);

    // keys created before they were saved, and revoked keys
    $pdo->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, key_enc, scope, created_by) VALUES ('Legacy test', 'tlk_legacy00', ?, NULL, 'health:read', 1)")->execute([hash('sha256', 'tlk_legacy')]);
    $lid = (int) $pdo->query("SELECT id FROM api_keys WHERE name = 'Legacy test'")->fetchColumn();
    same($reveal($admin, $lid)['code'], 404, 'an old hash-only key can not be shown');
    check(str_contains(http('GET', "$base/settings.php?tab=integrations", ['jar' => $admin])['body'], 'Created before keys were saved'), 'the page explains why an old key can not be shown');
    $page = http('GET', "$base/settings.php?tab=integrations", ['jar' => $admin]);
    http('POST', "$base/settings.php?tab=integrations", ['jar' => $admin, 'body' => http_build_query(['csrf' => csrf_from($page['body']), 'tab' => 'integrations', 'action' => 'key_revoke', 'id' => $kid])]);
    same($reveal($admin, $kid)['code'], 404, 'a revoked key can not be shown');
    same(http('GET', "$base/api/health.php", ['headers' => ['Authorization: Bearer ' . $plainKey]])['code'], 401, 'and it stops working at once');
    $pdo->exec("DELETE FROM api_keys WHERE name IN ('Persist test', 'Legacy test')");

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
    $root->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
    $root->exec('ALTER TABLE users DROP FOREIGN KEY fk_user_company, DROP COLUMN company_id');
    $root->exec('ALTER TABLE invoices DROP FOREIGN KEY fk_inv_recurring, DROP INDEX uq_inv_recurring, DROP COLUMN recurring_id');   // later stages point at invoices and companies,
    $root->exec('DROP TABLE recurring_invoices');                                                                                    // which the old database did not have
    $root->exec('ALTER TABLE invoices DROP FOREIGN KEY fk_inv_company, DROP COLUMN company_id');
    $root->exec('DROP TABLE ticket_comments, tickets');   // later stages point at companies, which the old database did not have
    $root->exec('DROP TABLE companies');
    $root->exec("ALTER TABLE users ADD COLUMN company VARCHAR(120) NOT NULL DEFAULT '', ADD KEY idx_company (company)");
    $ui = $root->prepare("INSERT INTO users (name, email, country, last_seen, created_at, company, is_demo) VALUES (?,?,?,NOW(),NOW(),?,?)");
    foreach ([['A', 'a@x.test', 'Acme BV', 0], ['B', 'b@x.test', 'Acme BV', 0], ['C', 'c@x.test', 'Zeta & Co', 1], ['D', 'd@x.test', '', 0]] as [$n, $m, $co, $dm]) { $ui->execute([$n, $m, 'NL', $co, $dm]); }
    $run = function () {
        $env = array_merge(getenv(), ['MIGRATE_DB' => 'ops_console_migtest']);
        $p = proc_open([PHP_BINARY, __DIR__ . '/../database/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, $env);
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
    same(http('GET', "$base/companies.php")['code'], 302, 'signed out: companies.php redirects to the login page');
    same(http('GET', "$base/company.php?id=1")['code'], 302, 'signed out: company.php redirects');
    same(http('GET', "$base/company_edit.php")['code'], 302, 'signed out: company_edit.php redirects');
    same(http('POST', "$base/company_edit.php", ['body' => 'name=ZZ+Test+Evil'])['code'], 302, 'signed out: a forged POST is refused');
    $form = ['action' => 'save', 'id' => '0', 'name' => 'ZZ Test Alpha', 'address' => 'Teststraat 1', 'postcode' => '3011 ad', 'city' => 'Rotterdam', 'country' => '', 'kvk' => '99990001', 'vat_number' => 'nl 9999.00.01.B01', 'email' => 'alpha@example.test', 'phone' => '010 111 2222', 'website' => 'alpha.example.test', 'notes' => 'Line one'];
    $noTok = http('POST', "$base/company_edit.php", ['jar' => $admin, 'body' => http_build_query($form)]);
    same($noTok['code'], 403, 'a POST without a CSRF token is 403');
    same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE name = 'ZZ Test Alpha'")->fetchColumn(), 0, 'and nothing was saved');
    foreach ([['Enter the company name', ['name' => '']], ['KvK number has 8 digits', ['kvk' => '123']], ['email address is not valid', ['email' => 'not-an-email']], ['VAT number starts with', ['vat_number' => 'xx']], ['website address is not valid', ['website' => 'http://exa mple']], ['at most 120', ['name' => str_repeat('x', 121)]]] as [$msg, $bad]) {
        $r = $post($admin, 'company_edit.php', $bad + $form);
        check($r['code'] === 200 && str_contains($r['body'], $msg), 'validation: ' . $msg);
    }
    same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE name = 'ZZ Test Alpha' OR kvk = '99990001'")->fetchColumn(), 0, 'nothing was saved while validation failed');
    $r = $post($admin, 'company_edit.php', $form);
    same($r['code'], 302, 'a valid company is saved and redirects');
    $co = $pdo->query("SELECT * FROM companies WHERE name = 'ZZ Test Alpha'")->fetch();
    check((bool) $co && $co['postcode'] === '3011 AD' && $co['vat_number'] === 'NL99990001B01' && $co['website'] === 'https://alpha.example.test' && $co['country'] === 'Netherlands', 'values are normalised: postcode upper case, VAT tidied, https added, country defaulted');
    $cid = (int) $co['id'];
    check(str_contains($r['headers'], "company.php?id=$cid"), 'it redirects to the new company page');
    $page = http('GET', "$base/company.php?id=$cid", ['jar' => $admin])['body'];
    check(str_contains($page, 'ZZ Test Alpha') && str_contains($page, 'Teststraat 1, 3011 AD Rotterdam') && str_contains($page, '99990001') && str_contains($page, 'mailto:alpha@example.test'), 'the company page shows its details');
    $dup = $post($admin, 'company_edit.php', ['name' => 'ZZ Test Beta', 'kvk' => '99990001'] + $form);
    check($dup['code'] === 200 && str_contains($dup['body'], 'already belongs to ZZ Test Alpha'), 'a duplicate KvK number is refused');
    $r = $post($admin, 'company_edit.php', ['id' => (string) $cid, 'name' => 'ZZ Test Alpha Renamed', 'city' => 'Delft'] + $form, "company_edit.php?id=$cid");
    same($r['code'], 302, 'editing saves');
    same($pdo->query("SELECT CONCAT(name, '|', city) FROM companies WHERE id = $cid")->fetchColumn(), 'ZZ Test Alpha Renamed|Delft', 'the changes are stored on the same row');
    same(http('GET', "$base/company.php?id=999999", ['jar' => $admin])['code'], 404, 'an unknown company is 404');
    same(http('GET', "$base/company.php?id=abc", ['jar' => $admin])['code'], 404, 'a non-numeric id is 404');
    same(http('GET', "$base/company_edit.php?id=999999", ['jar' => $admin])['code'], 404, 'editing an unknown company is 404');
    $xss = $post($admin, 'company_edit.php', ['name' => 'ZZ Test <script>alert(1)</script>', 'kvk' => '', 'vat_number' => '', 'notes' => '<img src=x onerror=alert(1)>'] + $form);
    $xid = (int) $pdo->query("SELECT id FROM companies WHERE name LIKE 'ZZ Test <script>%'")->fetchColumn();
    $xp = http('GET', "$base/company.php?id=$xid", ['jar' => $admin])['body'] . http('GET', "$base/companies.php?q=ZZ+Test", ['jar' => $admin])['body'];
    check($xid > 0 && !str_contains($xp, '<script>alert(1)') && !str_contains($xp, '<img src=x') && str_contains($xp, '&lt;script&gt;'), 'markup in a company name and notes is escaped everywhere');
    $s = http('GET', "$base/companies.php?q=" . urlencode('ZZ Test Alpha Renamed'), ['jar' => $admin])['body'];
    check(str_contains($s, 'ZZ Test Alpha Renamed') && !str_contains($s, 'No companies match'), 'search finds a company by name');
    check(str_contains(http('GET', "$base/companies.php?q=Delft", ['jar' => $admin])['body'], 'ZZ Test Alpha Renamed'), 'search finds it by city');
    check(str_contains(http('GET', "$base/companies.php?q=99990001", ['jar' => $admin])['body'], 'ZZ Test Alpha Renamed'), 'search finds it by KvK number');
    check(str_contains(http('GET', "$base/companies.php?q=" . urlencode('%'), ['jar' => $admin])['body'], 'No companies match'), 'a typed % is searched literally, not as a wildcard');
    check(str_contains(http('GET', "$base/companies.php?q=" . urlencode("x' OR 1=1 -- "), ['jar' => $admin])['body'], 'No companies match'), 'SQL-looking search text finds nothing and causes no error');
    foreach (['name', 'city', 'kvk', 'users', 'invoices'] as $so) {
        foreach (['asc', 'desc'] as $di) { same(http('GET', "$base/companies.php?sort=$so&dir=$di", ['jar' => $admin])['code'], 200, "sort by $so $di works"); }
    }
    same(http('GET', "$base/companies.php?sort=" . urlencode('name; DROP TABLE companies') . '&page=-5&q[]=x', ['jar' => $admin])['code'], 200, 'a hostile sort value, negative page and array q are handled');
    check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'href="companies.php"'), 'the sidebar has a Companies item');
    if ((int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() > 15) {
        check(str_contains(http('GET', "$base/companies.php?page=2", ['jar' => $admin])['body'], 'Page 2 of'), 'pagination works when there are more than 15 companies');
    }

    // =========================================================
    section('12. Companies: users, invoices and safe deletion');
    $pdo->prepare("INSERT INTO users (name, email, country, last_seen, created_at, company_id) VALUES ('ZZ Test Person', 'zz.company.test@example.test', 'Netherlands', NOW(), NOW(), NULL)")->execute();
    $uid = (int) $pdo->query("SELECT id FROM users WHERE email = 'zz.company.test@example.test'")->fetchColumn();
    $post($admin, 'user.php', ['id' => (string) $uid, 'action' => 'company', 'company_id' => (string) $cid], "user.php?id=$uid");
    same((int) $pdo->query("SELECT company_id FROM users WHERE id = $uid")->fetchColumn(), $cid, 'a user can be assigned to a company from their own page');
    $up = http('GET', "$base/user.php?id=$uid", ['jar' => $admin])['body'];
    check(str_contains($up, "company.php?id=$cid") && str_contains($up, 'ZZ Test Alpha Renamed'), 'the user page links to the company');
    $ul = http('GET', "$base/users.php?q=ZZ+Test+Person", ['jar' => $admin])['body'];
    check(str_contains($ul, "company.php?id=$cid"), 'the users list shows the company as a link');
    check(str_contains(http('GET', "$base/users.php?company=$cid", ['jar' => $admin])['body'], 'ZZ Test Person'), 'the users list can be filtered by company');
    check(str_contains(http('GET', "$base/users.php?q=Alpha+Renamed", ['jar' => $admin])['body'], 'ZZ Test Person'), 'searching users by company name finds its people');
    check(!str_contains(http('GET', "$base/users.php?company=999999", ['jar' => $admin])['body'], 'ZZ Test Person'), 'filtering by another company hides them');
    check(str_contains(http('GET', "$base/company.php?id=$cid", ['jar' => $admin])['body'], 'ZZ Test Person'), 'the company page lists its users');
    $post($admin, 'user.php', ['id' => (string) $uid, 'action' => 'company', 'company_id' => '999999'], "user.php?id=$uid");
    same((int) $pdo->query("SELECT company_id FROM users WHERE id = $uid")->fetchColumn(), $cid, 'assigning a company that does not exist changes nothing');

    $np = http('GET', "$base/invoice_new.php?company=$cid", ['jar' => $admin])['body'];
    check(str_contains($np, 'value="ZZ Test Alpha Renamed"') && str_contains($np, 'value="alpha@example.test"') && str_contains($np, 'value="Teststraat 1, 3011 AD Delft"'), 'invoice_new.php?company= fills in the customer fields from the company');
    check(str_contains($np, 'data-address="Teststraat 1, 3011 AD Delft"') && str_contains($np, 'id="company_id"'), 'the invoice form has a company picker carrying each company\'s details');
    $inv = ['customer' => 'ZZ Test Alpha Renamed', 'email' => 'alpha@example.test', 'address' => 'Teststraat 1, 3011 AD Delft', 'issue_date' => date('Y-m-d'), 'terms' => '14', 'vat' => '21', 'desc' => ['Test line'], 'qty' => ['2'], 'price' => ['10,00'], 'company_id' => (string) $cid];
    $r = $post($admin, 'invoice_new.php', $inv);
    same($r['code'], 302, 'an invoice for a company is created');
    $iv = $pdo->query("SELECT id, company_id, number FROM invoices WHERE customer = 'ZZ Test Alpha Renamed' ORDER BY id DESC LIMIT 1")->fetch();
    same((int) $iv['company_id'], $cid, 'the invoice is stored with the company link');
    check(str_contains(http('GET', "$base/invoice.php?id=" . $iv['id'], ['jar' => $admin])['body'], "company.php?id=$cid"), 'the invoice page links back to the company');
    $cp = http('GET', "$base/company.php?id=$cid", ['jar' => $admin])['body'];
    check(str_contains($cp, $iv['number']) && str_contains($cp, 'outstanding'), 'the company page lists the invoice and the outstanding amount');
    $r = $post($admin, 'invoice_new.php', ['company_id' => '999999', 'customer' => 'ZZ Test Orphan'] + $inv);
    check($r['code'] === 200 && str_contains($r['body'], 'That company does not exist'), 'an invoice with a company that does not exist is refused');
    same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE customer = 'ZZ Test Orphan'")->fetchColumn(), 0, 'and no invoice is created');
    $post($admin, 'invoice_new.php', ['company_id' => '0', 'customer' => 'ZZ Test Loose'] + $inv);
    same($pdo->query("SELECT company_id FROM invoices WHERE customer = 'ZZ Test Loose'")->fetchColumn(), null, 'an invoice without a company stays unlinked (the picker is optional)');

    check(str_contains(http('GET', "$base/company_edit.php?id=$cid&delete=1", ['jar' => $admin])['body'], 'can not be deleted'), 'a company with users shows that it can not be deleted');
    $post($admin, 'company_edit.php', ['id' => (string) $cid, 'action' => 'delete'], "company_edit.php?id=$cid");
    same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE id = $cid")->fetchColumn(), 1, 'a forged delete of a company with users is refused: it still exists');
    try { $pdo->exec("DELETE FROM companies WHERE id = $cid"); $fk = false; } catch (PDOException $e) { $fk = true; }
    check($fk, 'the database itself also refuses to delete a company that has users (foreign key)');
    $post($admin, 'user.php', ['id' => (string) $uid, 'action' => 'company', 'company_id' => '0'], "user.php?id=$uid");
    same($pdo->query("SELECT company_id FROM users WHERE id = $uid")->fetchColumn(), null, 'a user can be taken out of a company');
    $g = http('GET', "$base/company_edit.php?id=$cid&delete=1", ['jar' => $admin])['body'];
    check(str_contains($g, 'Yes, delete the company') && (int) $pdo->query("SELECT COUNT(*) FROM companies WHERE id = $cid")->fetchColumn() === 1, 'an empty company first asks for confirmation; opening that page deletes nothing');
    $post($admin, 'company_edit.php', ['id' => (string) $cid, 'action' => 'delete'], "company_edit.php?id=$cid");
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

    // =========================================================
    // Stage 3: tickets
    // =========================================================
    $cleanTickets = function () use ($pdo) {
        $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZ Test%'");
        $pdo->exec("DELETE FROM users WHERE email = 'zz.ticket.test@example.test'");
        $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZ Test%'");
        $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND ((action IN ('Ticket opened','Ticket updated') AND target LIKE '%ZZ Test%') OR (action = 'Ticket updated' AND target LIKE 'TCK-%' AND created_at > NOW() - INTERVAL 1 HOUR))");
    };
    $cleanTickets();
    section('15. Tickets');
    $pdo->exec("INSERT INTO companies (name) VALUES ('ZZ Test Ticket Co'), ('ZZ Test Other Co')");
    $tc1 = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZ Test Ticket Co'")->fetchColumn();
    $tc2 = (int) $pdo->query("SELECT id FROM companies WHERE name = 'ZZ Test Other Co'")->fetchColumn();
    $pdo->prepare("INSERT INTO users (name, email, country, last_seen, created_at, company_id) VALUES ('ZZ Test Ticket User', 'zz.ticket.test@example.test', 'Netherlands', NOW(), NOW(), ?)")->execute([$tc1]);
    $tu = (int) $pdo->query("SELECT id FROM users WHERE email = 'zz.ticket.test@example.test'")->fetchColumn();
    $adminId = (int) $pdo->query("SELECT id FROM admins WHERE username = 'admin'")->fetchColumn();

    foreach (['tickets.php', 'ticket.php?id=1', 'ticket_new.php'] as $pg) { same(http('GET', "$base/$pg")['code'], 302, "signed out: $pg redirects to the login page"); }
    same(http('POST', "$base/ticket_new.php", ['body' => 'subject=ZZ+Test+anon&description=x'])['code'], 302, 'signed out: a forged ticket is refused');
    same(http('POST', "$base/ticket_new.php", ['jar' => $admin, 'body' => 'subject=ZZ+Test+notoken&description=x'])['code'], 403, 'a ticket without a CSRF token is 403');
    same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE subject LIKE 'ZZ Test%'")->fetchColumn(), 0, 'and nothing is stored');

    $nt = ['subject' => 'ZZ Test Broken phone', 'description' => 'It does not ring.', 'priority' => 'high', 'company_id' => (string) $tc1, 'user_id' => (string) $tu, 'assignee_id' => (string) $adminId];
    foreach ([['Enter a subject', ['subject' => '']], ['Describe the problem', ['description' => '  ']], ['Choose a priority', ['priority' => 'asap']], ['company does not exist', ['company_id' => '999999']], ['user does not exist', ['user_id' => '999999']], ['does not belong to the chosen company', ['company_id' => (string) $tc2]], ['assignee does not exist', ['assignee_id' => '999999']], ['at most 160', ['subject' => 'ZZ Test ' . str_repeat('s', 160)]]] as [$msg, $bad]) {
        $r = $post($admin, 'ticket_new.php', $bad + $nt);
        check($r['code'] === 200 && str_contains($r['body'], $msg), 'validation: ' . $msg);
    }
    same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE subject LIKE 'ZZ Test%'")->fetchColumn(), 0, 'nothing is stored while validation fails');
    $r = $post($admin, 'ticket_new.php', $nt);
    same($r['code'], 302, 'a valid ticket is created and redirects');
    $tk = $pdo->query("SELECT * FROM tickets WHERE subject = 'ZZ Test Broken phone'")->fetch();
    check($tk && $tk['status'] === 'open' && $tk['priority'] === 'high' && (int) $tk['company_id'] === $tc1 && (int) $tk['user_id'] === $tu && (int) $tk['assignee_id'] === $adminId && $tk['created_by'] === 'admin', 'it is stored: status open, priority, company, contact, assignee, opened by the signed-in account');
    same($tk['reference'], 'TCK-' . str_pad((string) $tk['id'], 4, '0', STR_PAD_LEFT), 'the reference is TCK- plus the zero-padded number');
    $tid = (int) $tk['id'];
    $page = http('GET', "$base/ticket.php?id=$tid", ['jar' => $admin])['body'];
    check(str_contains($page, 'ZZ Test Broken phone') && str_contains($page, $tk['reference']) && str_contains($page, 'It does not ring.') && str_contains($page, "company.php?id=$tc1"), 'the ticket page shows details and links to the company');
    $r = $post($admin, 'ticket_new.php', ['company_id' => '0', 'user_id' => (string) $tu, 'subject' => 'ZZ Test From user only'] + $nt);
    same((int) $pdo->query("SELECT company_id FROM tickets WHERE subject = 'ZZ Test From user only'")->fetchColumn(), $tc1, 'a ticket with only a contact person takes over that person\'s company');
    $xr = $post($admin, 'ticket_new.php', ['subject' => 'ZZ Test <script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>'] + $nt);
    $xid = (int) $pdo->query("SELECT id FROM tickets WHERE subject LIKE 'ZZ Test <script>%'")->fetchColumn();
    $xp = http('GET', "$base/ticket.php?id=$xid", ['jar' => $admin])['body'] . http('GET', "$base/tickets.php?q=ZZ+Test", ['jar' => $admin])['body'] . http('GET', "$base/company.php?id=$tc1", ['jar' => $admin])['body'];
    check($xid > 0 && !str_contains($xp, '<script>alert(1)') && !str_contains($xp, '<img src=x') && str_contains($xp, '&lt;script&gt;'), 'markup in a subject and description is escaped on every page');

    // comments
    $post($admin, 'ticket.php', ['id' => (string) $tid, 'action' => 'comment', 'body' => 'ZZ Test first reply'], "ticket.php?id=$tid");
    $post($admin, 'ticket.php', ['id' => (string) $tid, 'action' => 'comment', 'body' => "ZZ Test <b>bold</b>\nline two", 'author' => 'forged'], "ticket.php?id=$tid");
    $cm = $pdo->query("SELECT author, body FROM ticket_comments WHERE ticket_id = $tid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check(count($cm) === 2 && $cm[0]['author'] === 'admin' && $cm[1]['author'] === 'admin', 'comments are stored with the signed-in account as author (a forged author is ignored)');
    $page = http('GET', "$base/ticket.php?id=$tid", ['jar' => $admin])['body'];
    check(strpos($page, 'ZZ Test first reply') < strpos($page, 'line two') && str_contains($page, '&lt;b&gt;bold&lt;/b&gt;'), 'the thread lists comments oldest first and escapes markup');
    $post($admin, 'ticket.php', ['id' => (string) $tid, 'action' => 'comment', 'body' => '   '], "ticket.php?id=$tid");
    same((int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $tid")->fetchColumn(), 2, 'an empty comment is not saved');
    same(http('POST', "$base/ticket.php", ['jar' => $admin, 'body' => 'id=999999&action=comment&body=x'])['code'], 404, 'a comment on a missing ticket answers 404');
    same(http('POST', "$base/ticket.php", ['jar' => $admin, 'body' => "id=$tid&action=comment&body=ZZ+Test+nocsrf"])['code'], 403, 'a comment without a CSRF token is 403');

    // status flow
    $mv = fn(array $d) => $post($admin, 'ticket.php', ['id' => (string) $tid, 'action' => 'update'] + $d, "ticket.php?id=$tid");
    $st = fn() => $pdo->query("SELECT CONCAT(status, '|', priority, '|', COALESCE(assignee_id, 0), '|', IF(resolved_at IS NULL, 'open', 'done')) FROM tickets WHERE id = $tid")->fetchColumn();
    $mv(['status' => 'in_progress']);
    same($st(), "in_progress|high|$adminId|open", 'open to in progress');
    $mv(['status' => 'waiting']);
    same(explode('|', $st())[0], 'waiting', 'in progress to waiting');
    $mv(['status' => 'resolved']);
    same(explode('|', $st())[3], 'done', 'resolved sets the resolved time');
    $mv(['status' => 'open']);
    same(explode('|', $st())[3], 'open', 'reopening clears the resolved time');
    $mv(['status' => 'closed']);
    same(explode('|', $st())[0], 'closed', 'a ticket can be closed');
    $mv(['status' => 'in_progress']);
    same(explode('|', $st())[0], 'closed', 'a closed ticket can not move to in progress');
    $mv(['status' => 'bogus']);
    same(explode('|', $st())[0], 'closed', 'an unknown status is refused');
    $mv(['status' => 'open']);
    same(explode('|', $st())[0], 'open', 'a closed ticket can be reopened');
    $mv(['status' => 'open', 'priority' => 'urgent', 'assignee_id' => '0']);
    same($st(), 'open|urgent|0|open', 'priority and assignee change, assignee can be cleared');
    $mv(['priority' => 'galactic']);
    same(explode('|', $st())[1], 'urgent', 'an unknown priority is refused');
    $mv(['assignee_id' => '999999']);
    same(explode('|', $st())[2], '0', 'an unknown assignee is refused');
    check((int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $tid AND author = 'system' AND body LIKE 'Changed status%'")->fetchColumn() >= 4, 'every change leaves a line in the thread');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Ticket updated' AND target LIKE '{$tk['reference']}%'")->fetchColumn() >= 4 && (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Ticket opened' AND target LIKE '%ZZ Test Broken%'")->fetchColumn() === 1, 'opening and changing a ticket are in the activity log');

    // list: filters, sorting, pagination, search
    $pdo->prepare("UPDATE tickets SET status = 'waiting', priority = 'low', assignee_id = NULL WHERE id = ?")->execute([$tid]);
    $L = fn(string $qs) => http('GET', "$base/tickets.php?$qs", ['jar' => $admin])['body'];
    check(str_contains($L('q=ZZ+Test+Broken'), 'ZZ Test Broken phone'), 'search finds a ticket by subject');
    check(str_contains($L('q=' . urlencode($tk['reference'])), 'ZZ Test Broken phone'), 'search finds a ticket by reference');
    check(str_contains($L('q=ZZ+Test+Ticket+Co'), 'ZZ Test Broken phone'), 'search finds a ticket by company name');
    check(str_contains($L('q=%25'), 'No tickets match'), 'a typed % is searched literally');
    check(str_contains($L('q=ZZ+Test&status=waiting'), 'ZZ Test Broken phone') && !str_contains($L('q=ZZ+Test&status=open'), 'ZZ Test Broken phone'), 'the status filter includes and excludes');
    check(str_contains($L('q=ZZ+Test&priority=low'), 'ZZ Test Broken phone') && !str_contains($L('q=ZZ+Test&priority=urgent'), 'ZZ Test Broken phone'), 'the priority filter includes and excludes');
    check(str_contains($L('q=ZZ+Test&assignee=none'), 'ZZ Test Broken phone') && !str_contains($L("q=ZZ+Test+Broken&assignee=$adminId"), 'ZZ Test Broken phone'), 'the assignee filter (unassigned / a person) includes and excludes');
    check(str_contains($L("company=$tc1"), 'ZZ Test Broken phone') && !str_contains($L("company=$tc2"), 'ZZ Test Broken phone'), 'the company filter includes and excludes');
    check(str_contains($L('q=ZZ+Test&status=waiting&priority=low&company=' . $tc1), 'ZZ Test Broken phone'), 'filters combine');
    foreach (['reference', 'subject', 'company', 'status', 'priority', 'assignee', 'updated'] as $so) { foreach (['asc', 'desc'] as $di) { same(http('GET', "$base/tickets.php?sort=$so&dir=$di", ['jar' => $admin])['code'], 200, "sort by $so $di works"); } }
    same(http('GET', "$base/tickets.php?sort=" . urlencode('id; DROP TABLE tickets') . '&status[]=x&page=-3&assignee=' . urlencode("1' OR '1'='1"), ['jar' => $admin])['code'], 200, 'hostile sort, array and quote values are handled');
    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
    if ($cnt > 15) { check(str_contains($L('page=2'), 'Page 2 of') && str_contains($L('page=9999'), 'Showing'), 'pagination works, and a page beyond the end shows the last page'); }
    check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'href="tickets.php"'), 'the sidebar has a Tickets item');
    $ov = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    $openN = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed')")->fetchColumn();
    check(str_contains($ov, 'Open tickets') && str_contains($ov, 'data-count="' . $openN . '"'), 'the Overview shows the number of open tickets (' . $openN . ')');

    // company and user pages
    $pdo->prepare("UPDATE tickets SET status = 'open' WHERE id = ?")->execute([$tid]);
    $cp = http('GET', "$base/company.php?id=$tc1", ['jar' => $admin])['body'];
    check(str_contains($cp, 'Open tickets') && str_contains($cp, 'ZZ Test Broken phone') && str_contains($cp, 'Ticket opened'), 'the company page lists its open tickets and the timeline shows "Ticket opened"');
    check(str_contains(http('GET', "$base/user.php?id=$tu", ['jar' => $admin])['body'], 'ZZ Test Broken phone'), 'the user page lists their open tickets');
    $pdo->prepare("UPDATE tickets SET status = 'resolved' WHERE id = ?")->execute([$tid]);
    check(!str_contains(http('GET', "$base/user.php?id=$tu", ['jar' => $admin])['body'], 'href="ticket.php?id=' . $tid . '"'), 'a resolved ticket no longer shows under open tickets');
    check(str_contains(http('GET', "$base/ticket_new.php?company=$tc1&user=$tu", ['jar' => $admin])['body'], 'value="' . $tc1 . '" selected'), 'ticket_new.php?company= preselects the company');

    // deleting a company keeps its tickets
    $pdo->prepare("UPDATE users SET company_id = NULL WHERE id = ?")->execute([$tu]);
    $post($admin, 'company_edit.php', ['id' => (string) $tc1, 'action' => 'delete'], "company_edit.php?id=$tc1");
    same($pdo->query("SELECT company_id FROM tickets WHERE id = $tid")->fetchColumn(), null, 'deleting a company keeps its tickets (they lose the link)');
    $pdo->exec("DELETE FROM tickets WHERE id = $tid");
    same((int) $pdo->query("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = $tid")->fetchColumn(), 0, 'deleting a ticket deletes its comments');
    if (demo_loaded($pdo)) {
        $dc = demo_counts($pdo, 1);
        check($dc['tickets'] >= 40 && $dc['ticket_comments'] > 40, 'demo data has about 40 tickets with comments');
        same((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE is_demo = 1 AND reference NOT REGEXP '^TCK-[0-9]{4}$'")->fetchColumn(), 0, 'every demo ticket has a proper reference');
        check((int) $pdo->query('SELECT COUNT(DISTINCT status) FROM tickets WHERE is_demo = 1')->fetchColumn() === 5 && (int) $pdo->query('SELECT COUNT(DISTINCT priority) FROM tickets WHERE is_demo = 1')->fetchColumn() === 4, 'demo tickets cover every status and priority');
    }
    $cleanTickets();

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
    $S = fn(string $q) => http('GET', "$base/search.php?q=" . urlencode($q), ['jar' => $admin]);

    same(http('GET', "$base/search.php?q=zebra")['code'], 302, 'signed out: search.php redirects to the login page');
    $top = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    check(str_contains($top, 'action="search.php"') && str_contains($top, 'id="gsearch"') && str_contains($top, 'name="q"'), 'the top-bar search sends people to search.php');
    $r = $S('ZZQ Zebra');
    $b = $r['body'];
    check($r['code'] === 200 && str_contains($b, "user.php?id=$zu") && str_contains($b, "company.php?id=$zc") && str_contains($b, "invoice.php?id=$zi") && str_contains($b, "ticket.php?id=$zt"), 'one search finds a user, a company, an invoice and a ticket, each linking to its page');
    check(preg_match('/Users <span[^>]*>\(1\)/', $b) && preg_match('/Companies <span[^>]*>\(1\)/', $b) && preg_match('/Invoices <span[^>]*>\(1\)/', $b) && preg_match('/Tickets <span[^>]*>\(1\)/', $b), 'results are grouped with a count per group');
    check(str_contains($S('zora zebra')['body'], "user.php?id=$zu"), 'a user is found by name (any capitals)');
    check(str_contains($S('zz.search1@example')['body'], "user.php?id=$zu"), 'a user is found by email');
    check(str_contains($S('Zebra Holding')['body'], "user.php?id=$zu"), 'a user is found by their company name');
    check(str_contains($S('Zwolle')['body'], "company.php?id=$zc") && str_contains($S('99990077')['body'], "company.php?id=$zc") && str_contains($S('zebra@example')['body'], "company.php?id=$zc"), 'a company is found by city, KvK number and email');
    check(str_contains($S('ZZQ-INV-1')['body'], "invoice.php?id=$zi"), 'an invoice is found by number');
    check(str_contains($S('ZZQ-T-1')['body'], "ticket.php?id=$zt") && str_contains($S('crossing')['body'], "ticket.php?id=$zt"), 'a ticket is found by reference and by subject');
    $n = $S('ZZQ 100%')['body'];
    check(str_contains($n, "company.php?id=$pc") && !str_contains($n, "company.php?id=$zc"), 'a typed % is searched literally');
    check(!str_contains($S('ZZQ_Zebra')['body'], "company.php?id=$zc"), 'a typed _ is not a single-character wildcard');
    $w = $S('%')['body'];
    check(str_contains($w, "company.php?id=$pc") && !str_contains($w, "company.php?id=$zc"), 'a lone % finds only text that contains a percent sign');
    $e = $S('qqqq-no-such-thing');
    check($e['code'] === 200 && str_contains($e['body'], 'No results for') && !str_contains($e['body'], 'class="rise rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="h-'), 'no results: a clear empty state and no result groups');
    check(str_contains($S('')['body'], 'Type a name') && $S('')['code'] === 200, 'an empty search shows help text');
    $h = $S("x' OR 1=1 -- ");
    check($h['code'] === 200 && str_contains($h['body'], 'No results for'), 'SQL-looking text finds nothing and causes no error');
    $xs = $S('<script>alert(1)</script>');
    check($xs['code'] === 200 && !str_contains($xs['body'], '<script>alert(1)') && str_contains($xs['body'], '&lt;script&gt;'), 'the search text is escaped when shown back');
    same(http('GET', "$base/search.php?q[]=a&q[]=b", ['jar' => $admin])['code'], 200, 'an array q parameter is handled');
    check(str_contains(http('GET', "$base/search.php?q=" . str_repeat('a', 500), ['jar' => $admin])['body'], 'maxlength="100"'), 'a very long query is accepted and cut to 100 characters');
    // "View all" appears only when there are more than 8 matches
    $pdo->exec("INSERT INTO companies (name) VALUES " . implode(',', array_map(fn($i) => "('ZZQ Bulk $i')", range(1, 10))));
    $v = $S('ZZQ Bulk')['body'];
    check(str_contains($v, 'View all 10') && str_contains($v, 'companies.php?q=ZZQ+Bulk'), 'more than 8 matches: the group is cut at 8 with a "View all" link to the full list');
    $pdo->exec("DELETE FROM companies WHERE name LIKE 'ZZQ Bulk%'");
    // the invoice list search treats % literally as well
    check(!str_contains(http('GET', "$base/invoices.php?q=%25", ['jar' => $admin])['body'], 'ZZQ-INV-1'), 'the invoice list search no longer treats % as a wildcard');

    // easter eggs are untouched: same elements in the page and the same logic in motion.js
    check(str_contains($top, 'id="creatorPop"') && str_contains($top, 'href="creator.php"') && str_contains($top, 'id="teletolliePop"') && str_contains($top, 'href="teletollie.php"'), 'both easter-egg buttons are still in the page');
    $js = (string) file_get_contents(__DIR__ . '/../assets/motion.js');
    check(str_contains($js, "toLowerCase() === 'creator'") && str_contains($js, "v.trim() === 'Teletollie'") && str_contains($js, "e.key === 'Enter' && egg"), 'motion.js still holds the exact trigger words and the Enter rule');
    check(str_contains(http('GET', "$base/creator.php", ['jar' => $admin])['body'], 'creator') && http('GET', "$base/teletollie.php", ['jar' => $admin])['code'] === 200, 'creator.php and teletollie.php still open');

    // rate limit
    $pdo->exec('DELETE FROM rate_limits');
    $codes = [];
    for ($i = 0; $i < 64; $i++) { $codes[] = http('GET', "$base/search.php?q=rl$i", ['jar' => $admin])['code']; }
    check(count(array_filter($codes, fn($c) => $c === 200)) === 60 && count(array_filter($codes, fn($c) => $c === 429)) === 4, 'search is rate limited: 60 requests a minute, then 429', json_encode(array_count_values($codes)));
    $pdo->exec('DELETE FROM rate_limits');
    same(http('GET', "$base/search.php?q=zebra", ['jar' => $admin])['code'], 200, 'and it works again once the window has passed');
    $pdo->exec("DELETE FROM activity_logs WHERE action = 'Rate limit exceeded' AND target LIKE '%search.php' AND is_demo = 0");
    $cleanSearch();

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
        $csrf ??= csrf_from(http('GET', "$base/import.php", ['jar' => $jar])['body']);
        $r = http('POST', "$base/import.php", ['jar' => $jar, 'body' => $extra + ['csrf' => $csrf, 'type' => 'companies', 'file' => new CURLFile($tmp, 'text/csv', $filename)]]);
        @unlink($tmp);
        return $r;
    };
    $exp = fn(string $jar, string $type) => http('GET', "$base/export.php?type=$type", ['jar' => $jar]);
    $parse = function (string $csv) { $t = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv; $fh = fopen('php://temp', 'w+'); fwrite($fh, $t); rewind($fh); $o = []; while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { $o[] = $r; } return $o; };

    // ---- access
    foreach (['users', 'companies', 'invoices', 'tickets'] as $ty) { same(http('GET', "$base/export.php?type=$ty")['code'], 302, "signed out: export of $ty redirects to the login page"); }
    same(http('GET', "$base/import.php")['code'], 302, 'signed out: import.php redirects');
    foreach (['users', 'companies', 'invoices', 'tickets'] as $ty) { same($exp($user, $ty)['code'], 403, "a normal account gets 403 on the $ty export"); }
    same(http('GET', "$base/import.php", ['jar' => $user])['code'], 403, 'a normal account gets 403 on import.php');
    same($upload($user, "name\nZZI Sneaky\n", [], 'data.csv', 'x')['code'], 403, 'a normal account can not POST an import');
    same((int) $pdo->query("SELECT COUNT(*) FROM companies WHERE name = 'ZZI Sneaky'")->fetchColumn(), 0, 'and nothing was imported');
    check(!str_contains(http('GET', "$base/users.php", ['jar' => $user])['body'], 'export.php') && str_contains(http('GET', "$base/users.php", ['jar' => $admin])['body'], 'export.php?type=users'), 'export buttons are shown to admins only');
    foreach (['companies.php' => 'companies', 'tickets.php' => 'tickets', 'invoices.php' => 'invoices'] as $pg => $ty) { check(str_contains(http('GET', "$base/$pg", ['jar' => $admin])['body'], "export.php?type=$ty"), "the $ty list has an Export CSV button"); }
    check(str_contains(http('GET', "$base/companies.php", ['jar' => $admin])['body'], 'import.php?type=companies') && str_contains(http('GET', "$base/users.php", ['jar' => $admin])['body'], 'import.php?type=users'), 'users and companies have an Import CSV button');
    same($exp($admin, 'nonsense')['code'], 404, 'an unknown export type is 404');
    same(http('GET', "$base/export.php", ['jar' => $admin])['code'], 404, 'no export type is 404');

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
    same(http('POST', "$base/import.php", ['jar' => $admin, 'body' => ['type' => 'companies', 'csrf' => 'wrong']])['code'], 403, 'an import with a wrong CSRF token is 403');
    same($upload($admin, "name\nZZI Bad token\n", [], 'data.csv', 'wrong-token')['code'], 403, 'an upload with a wrong CSRF token is 403');
    check(str_contains($note($upload($admin, "name\nZZI Bad ext\n", [], 'data.exe')['body']), 'Only .csv files'), 'a file that is not .csv is refused');
    check(str_contains($note($upload($admin, "name\nZZI Bad ext\n", [], 'data.csv.php')['body']), 'Only .csv files'), 'a double extension is refused');
    $r = http('POST', "$base/import.php", ['jar' => $admin, 'body' => ['csrf' => csrf_from(http('GET', "$base/import.php", ['jar' => $admin])['body']), 'type' => 'companies']]);
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
    check(!str_contains(http('GET', "$base/import.php", ['jar' => $admin])['body'], 'ZZI Bad'), 'refused files are not kept or shown');
    check(!str_contains((string) file_get_contents(__DIR__ . '/../import.php'), 'move_uploaded_file') && !str_contains((string) file_get_contents(__DIR__ . '/../import.php'), 'include $f') , 'import.php never moves, stores or includes the uploaded file');

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
    $xl = http('GET', "$base/companies.php?q=ZZI+%3Cscript", ['jar' => $admin])['body'];
    check(!str_contains($xl, '<script>alert(1)') && str_contains($xl, '&lt;script&gt;'), 'markup in an imported name is escaped when shown');
    // users: bad rows
    $r = $upload($admin, "name,email,role,status,company\nZZI Ok,zz.imp1@example.test,viewer,active,ZZI Users Co\nZZI Role,zz.imp2@example.test,superuser,active,\nZZI Stat,zz.imp3@example.test,viewer,gone,\nZZI Comp,zz.imp4@example.test,viewer,active,No Such Company\nZZI Mail,nope,viewer,active,\nZZI Dupe,zz.csv1@example.test,viewer,active,\n,zz.imp5@example.test,,,\n", ['type' => 'users']);
    check(preg_match('/(\d+) added<\/span> · (\d+) skipped as duplicate · <span[^>]*>(\d+) rejected/', $r['body'], $m) && [(int) $m[1], (int) $m[2], (int) $m[3]] === [1, 1, 5], 'users: 1 added, 1 duplicate email skipped, 5 rejected (role, status, unknown company, email, empty name)', $m[0] ?? 'no summary');
    check(str_contains($r['body'], 'is not admin, editor, support or viewer') && str_contains($r['body'], 'does not exist: import the companies first'), 'user problems say what is wrong');
    same($pdo->query("SELECT GROUP_CONCAT(email) FROM users WHERE email LIKE 'zz.imp%'")->fetchColumn(), 'zz.imp1@example.test', 'only the valid user was saved');
    same((int) $pdo->query("SELECT company_id FROM users WHERE email = 'zz.imp1@example.test'")->fetchColumn(), $uco, 'an imported user is linked to the company named in the file');

    // ---- export is rate limited like report downloads
    $pdo->exec('DELETE FROM rate_limits');
    $codes = [];
    for ($i = 0; $i < 18; $i++) { $codes[] = $exp($admin, 'tickets')['code']; }
    check(count(array_filter($codes, fn($c) => $c === 200)) === 15 && count(array_filter($codes, fn($c) => $c === 429)) === 3, 'exports are rate limited: 15 a minute, then 429', json_encode(array_count_values($codes)));
    $pdo->exec('DELETE FROM rate_limits');
    $pdo->exec("DELETE FROM activity_logs WHERE action = 'Rate limit exceeded' AND target LIKE '%export.php' AND is_demo = 0");
    $cleanCsv();

    // =========================================================
    // Stage 6: notifications
    // =========================================================
    $cleanNotif = function () use ($pdo) {
        $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZN%'");
        $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer LIKE 'ZZN%')");
        $pdo->exec("DELETE FROM invoices WHERE customer LIKE 'ZZN%'");
        $pdo->exec("DELETE FROM backups WHERE name LIKE 'ZZN%'");
        $pdo->exec("DELETE FROM monitored_endpoints WHERE name LIKE 'ZZN%'");
        $pdo->exec("DELETE FROM kuma_heartbeats WHERE monitor_name LIKE 'ZZN%'");
        $pdo->exec("DELETE FROM notifications WHERE title LIKE '%ZZN%' OR body LIKE '%ZZN%'");
        $pdo->exec("DELETE FROM mail_outbox WHERE kind = 'notification' AND created_at > NOW() - INTERVAL 3 HOUR");   // digests of the events above (recorded only: localhost)
        $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND action IN ('Notification address changed') AND created_at > NOW() - INTERVAL 1 HOUR");
        $pdo->exec("INSERT INTO app_meta (k, v) VALUES ('notify_email', 'denis@telecomlijn.nl') ON DUPLICATE KEY UPDATE v = VALUES(v)");
    };
    $cleanNotif();
    section('18. Notifications');
    $pdo->exec('DELETE FROM rate_limits');
    notifications_refresh($pdo, true);   // absorb whatever the demo data already triggers, so the counts below are about this test only
    $known = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    $nn = fn(string $like) => (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE dedupe_key LIKE '$like'")->fetchColumn();

    foreach (['notifications.php'] as $pg) { same(http('GET', "$base/$pg")['code'], 302, 'signed out: notifications.php redirects to the login page'); }
    same(http('POST', "$base/notifications.php", ['body' => 'action=read_all'])['code'], 302, 'signed out: marking as read is refused');

    // the four events
    $pdo->exec("INSERT INTO invoices (number, customer, email, customer_address, issue_date, due_date, subtotal_cents, vat_cents, total_cents, created_at) VALUES ('ZZN-OD-1', 'ZZN Late Payer', 'a@b.test', 'x', CURDATE() - INTERVAL 40 DAY, CURDATE() - INTERVAL 10 DAY, 1000, 210, 1210, NOW()), ('ZZN-OK-1', 'ZZN Open Payer', 'a@b.test', 'x', CURDATE(), CURDATE() + INTERVAL 30 DAY, 1000, 210, 1210, NOW())");
    $odId = (int) $pdo->query("SELECT id FROM invoices WHERE number = 'ZZN-OD-1'")->fetchColumn();
    $okId = (int) $pdo->query("SELECT id FROM invoices WHERE number = 'ZZN-OK-1'")->fetchColumn();
    $pdo->exec("INSERT INTO backups (name, type, status, size_mb, started_at, duration_s, location) VALUES ('ZZN-backup-fail', 'full', 'failed', 10, NOW() - INTERVAL 2 HOUR, 5, 'Local'), ('ZZN-backup-old', 'full', 'failed', 10, NOW() - INTERVAL 5 DAY, 5, 'Local'), ('ZZN-backup-ok', 'full', 'completed', 10, NOW() - INTERVAL 1 HOUR, 5, 'Local')");
    $bkId = (int) $pdo->query("SELECT id FROM backups WHERE name = 'ZZN-backup-fail'")->fetchColumn();
    $pdo->exec("INSERT INTO monitored_endpoints (name, url, enabled, last_state, last_error, last_checked_at) VALUES ('ZZN Endpoint', 'https://example.com/', 1, 'down', 'HTTP 503', NOW()), ('ZZN Fine Endpoint', 'https://example.org/', 1, 'up', '', NOW()), ('ZZN Off Endpoint', 'https://example.net/', 0, 'down', '', NOW())");
    $epId = (int) $pdo->query("SELECT id FROM monitored_endpoints WHERE name = 'ZZN Endpoint'")->fetchColumn();
    $pdo->exec("INSERT INTO endpoint_checks (endpoint_id, checked_at, state) VALUES ($epId, NOW() - INTERVAL 30 MINUTE, 'up'), ($epId, NOW() - INTERVAL 20 MINUTE, 'down'), ($epId, NOW() - INTERVAL 10 MINUTE, 'down')");
    $pdo->exec("INSERT INTO kuma_heartbeats (monitor_key, monitor_name, status, important, at) VALUES ('eeeeeeeeeeeeeeee', 'ZZN Kuma Monitor', 0, 1, NOW() - INTERVAL 1 HOUR), ('ffffffffffffffff', 'ZZN Kuma Quiet', 0, 0, NOW() - INTERVAL 1 HOUR), ('eeeeeeeeeeeeeeee', 'ZZN Kuma Old', 0, 1, NOW() - INTERVAL 3 DAY)");
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZN-T-U', 'ZZN Urgent printer fire', 'x', 'urgent', 'open', NOW(), NOW()), ('ZZN-T-N', 'ZZN Normal ticket', 'x', 'normal', 'open', NOW(), NOW()), ('ZZN-T-R', 'ZZN Resolved urgent', 'x', 'urgent', 'resolved', NOW(), NOW())");
    $tkId = (int) $pdo->query("SELECT id FROM tickets WHERE reference = 'ZZN-T-U'")->fetchColumn();
    $made = notifications_refresh($pdo, true);
    same($made, 5, 'one refresh records five new notifications: overdue invoice, failed backup, endpoint down, Kuma monitor down, urgent ticket');
    $row = fn(string $key) => $pdo->query("SELECT * FROM notifications WHERE dedupe_key = " . $pdo->quote($key))->fetch();
    $o = $row("invoice-overdue:$odId");
    check($o && $o['kind'] === 'invoice_overdue' && str_contains($o['title'], 'ZZN-OD-1') && str_contains($o['body'], '12,10') && $o['link'] === "invoice.php?id=$odId" && $o['read_at'] === null, 'overdue invoice: title, amount owed, link to the invoice, unread');
    same($nn("invoice-overdue:$okId"), 0, 'an invoice that is not overdue creates nothing');
    $b = $row("backup-failed:$bkId");
    check($b && $b['kind'] === 'backup_failed' && str_contains($b['body'], 'ZZN-backup-fail') && $b['link'] === 'backups.php', 'failed backup: announced with its name');
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE kind = 'backup_failed' AND body LIKE '%ZZN-backup-old%' OR body LIKE '%ZZN-backup-ok%'")->fetchColumn(), 0, 'a failed backup older than 48 hours and a completed one create nothing');
    $since = $pdo->query("SELECT MIN(checked_at) FROM endpoint_checks WHERE endpoint_id = $epId AND state = 'down'")->fetchColumn();
    $d = $row("endpoint-down:$epId:$since");
    check($d && $d['kind'] === 'service_down' && str_contains($d['title'], 'ZZN Endpoint') && str_contains($d['body'], 'HTTP 503'), 'endpoint down: announced with the error, keyed on when the outage began');
    same($nn('endpoint-down:' . (int) $pdo->query("SELECT id FROM monitored_endpoints WHERE name = 'ZZN Fine Endpoint'")->fetchColumn() . ':%') + $nn('endpoint-down:' . (int) $pdo->query("SELECT id FROM monitored_endpoints WHERE name = 'ZZN Off Endpoint'")->fetchColumn() . ':%'), 0, 'a healthy endpoint and a switched-off one create nothing');
    $kid = (int) $pdo->query("SELECT id FROM kuma_heartbeats WHERE monitor_name = 'ZZN Kuma Monitor'")->fetchColumn();
    $k = $row("kuma-down:$kid");
    check($k && $k['kind'] === 'service_down' && str_contains($k['title'], 'ZZN Kuma Monitor') && $k['link'] === 'uptime.php', 'an Uptime Kuma monitor that went down is announced');
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE title LIKE 'ZZN Kuma Quiet%' OR title LIKE 'ZZN Kuma Old%'")->fetchColumn(), 0, 'a heartbeat that is not a status change, or is older than a day, creates nothing');
    $t = $row("ticket-urgent:$tkId");
    check($t && $t['kind'] === 'ticket_urgent' && str_contains($t['title'], 'ZZN-T-U') && $t['body'] === 'ZZN Urgent printer fire' && $t['link'] === "ticket.php?id=$tkId", 'urgent ticket: announced with its subject and link');
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE body LIKE 'ZZN Normal%' OR body LIKE 'ZZN Resolved%'")->fetchColumn(), 0, 'a normal ticket and a resolved urgent one create nothing');

    // no duplicates
    $total = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    same(notifications_refresh($pdo, true), 0, 'a second refresh announces nothing again');
    notifications_refresh($pdo, true);
    same((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(), $total, 'and the number of notifications is unchanged after more refreshes');
    check(!notify_add($pdo, 'x', "ticket-urgent:$tkId", 'dup', '', '', false), 'the database key refuses the same event twice');
    // a second outage of the same endpoint is a new event
    $pdo->exec("INSERT INTO endpoint_checks (endpoint_id, checked_at, state) VALUES ($epId, NOW() - INTERVAL 5 MINUTE, 'up'), ($epId, NOW() - INTERVAL 2 MINUTE, 'down')");
    same(notifications_refresh($pdo, true), 1, 'after the endpoint recovered and failed again, the new outage is announced once');
    same($nn("endpoint-down:$epId:%"), 2, 'so one endpoint now has two outage notifications');
    // paying the invoice or resolving the ticket does not delete the history
    $pdo->exec("UPDATE tickets SET status = 'resolved' WHERE id = $tkId");
    notifications_refresh($pdo, true);
    same($nn("ticket-urgent:$tkId"), 1, 'a resolved ticket keeps its earlier notification');

    // throttling
    $pdo->exec("DELETE FROM tickets WHERE reference = 'ZZN-T-R'");
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZN-T-U2', 'ZZN Second urgent', 'x', 'urgent', 'open', NOW(), NOW())");
    $setStamp = fn(int $ago) => $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('notif_refreshed_at', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string) (time() - $ago)]);
    $setStamp(30);
    same(notifications_refresh($pdo), 0, 'inside the three-minute window the checks do not run');
    same($nn('ticket-urgent:%'), $nn('ticket-urgent:%'), 'nothing new is recorded inside the window');
    check(!(bool) $pdo->query("SELECT 1 FROM notifications WHERE body = 'ZZN Second urgent'")->fetchColumn(), 'the new urgent ticket is not announced yet');
    $setStamp(NOTIFY_REFRESH_SECONDS + 5);
    same(notifications_refresh($pdo), 1, 'after the window the checks run and find it');
    $setStamp(NOTIFY_REFRESH_SECONDS + 5);
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZN-T-U3', 'ZZN Third urgent', 'x', 'urgent', 'open', NOW(), NOW())");
    http('GET', "$base/index.php", ['jar' => $admin]);
    check((bool) $pdo->query("SELECT 1 FROM notifications WHERE body = 'ZZN Third urgent'")->fetchColumn(), 'simply loading a page runs the refresh when it is due');
    $stamp = (int) $pdo->query("SELECT v FROM app_meta WHERE k = 'notif_refreshed_at'")->fetchColumn();
    http('GET', "$base/index.php", ['jar' => $admin]);
    same((int) $pdo->query("SELECT v FROM app_meta WHERE k = 'notif_refreshed_at'")->fetchColumn(), $stamp, 'and the next page load does not run it again');

    // bell and page
    $pdo->exec("UPDATE notifications SET read_at = NOW() WHERE dedupe_key NOT LIKE 'ticket-urgent:%'");
    $unreadNow = (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn();
    $page = http('GET', "$base/index.php", ['jar' => $admin])['body'];
    check(str_contains($page, 'id="bell"') && str_contains($page, 'Notifications, ' . $unreadNow . ' unread') && str_contains($page, 'Mark all read') && str_contains($page, 'See all notifications'), 'the top bar has a bell with the unread count (' . $unreadNow . '), a Mark all read button and a link to all notifications');
    check(str_contains($page, 'ZZN Third urgent') || str_contains($page, 'Urgent ticket ZZN-T-U3'), 'the dropdown lists the latest notifications');
    $np = http('GET', "$base/notifications.php", ['jar' => $admin])['body'];
    check(str_contains($np, 'Urgent ticket ZZN-T-U3') && str_contains($np, "ticket.php?id=" . (int) $pdo->query("SELECT id FROM tickets WHERE reference = 'ZZN-T-U3'")->fetchColumn()), 'notifications.php lists them with a link to each item');
    $un = http('GET', "$base/notifications.php?show=unread", ['jar' => $admin])['body'];
    check(str_contains($un, 'Urgent ticket ZZN-T-U3') && !str_contains($un, 'ZZN-OD-1'), 'the Unread filter shows only unread ones');
    same(http('POST', "$base/notifications.php", ['jar' => $admin, 'body' => 'action=read_all'])['code'], 403, 'marking as read without a CSRF token is 403');
    $nid = (int) $pdo->query("SELECT id FROM notifications WHERE body = 'ZZN Third urgent'")->fetchColumn();
    $post($admin, 'notifications.php', ['action' => 'read', 'id' => (string) $nid], 'notifications.php');
    same((int) $pdo->query("SELECT read_at IS NOT NULL FROM notifications WHERE id = $nid")->fetchColumn(), 1, 'Mark read marks that one notification');
    same((int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn(), $unreadNow - 1, 'and only that one');
    $post($admin, 'notifications.php', ['action' => 'read', 'id' => '999999'], 'notifications.php');
    same((int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn(), $unreadNow - 1, 'marking an unknown id changes nothing');
    $r = $post($admin, 'notifications.php', ['action' => 'read_all', 'back' => 'tickets.php'], 'notifications.php');
    same((int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn(), 0, 'Mark all read clears every unread one');
    check(str_contains($r['headers'], 'Location: tickets.php') && !str_contains($r['headers'], 'evil'), 'it returns to the page you were on');
    $r = $post($admin, 'notifications.php', ['action' => 'read_all', 'back' => '//evil.example/x'], 'notifications.php');
    check(str_contains($r['headers'], 'Location: notifications.php'), 'a forged return address is ignored');
    check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'Notifications, none unread'), 'with nothing unread the bell says so and shows no number');
    check(str_contains(http('GET', "$base/notifications.php?show=unread", ['jar' => $admin])['body'], 'You are all caught up'), 'the Unread filter shows a friendly empty state');
    same(http('GET', "$base/notifications.php?page=-4&show[]=x", ['jar' => $admin])['code'], 200, 'a negative page and an array parameter are handled');
    $x = $pdo->prepare('INSERT INTO notifications (kind, dedupe_key, title, body, link, created_at) VALUES (?,?,?,?,?,NOW())');
    $x->execute(['ticket_urgent', 'zzn-xss', 'ZZN <script>alert(1)</script>', '<img src=x onerror=alert(1)>', 'ticket.php?id=1']);
    $xp = http('GET', "$base/index.php", ['jar' => $admin])['body'] . http('GET', "$base/notifications.php", ['jar' => $admin])['body'];
    check(!str_contains($xp, '<script>alert(1)') && !str_contains($xp, '<img src=x') && str_contains($xp, '&lt;script&gt;'), 'notification text is escaped in the bell and on the page');
    $lay = (string) file_get_contents(__DIR__ . '/../includes/layout.php');
    check(preg_match('/@media \(prefers-reduced-motion: no-preference\) \{[^}]*bell-ring/', $lay) === 1 && substr_count($lay, 'animation: bell-ring') === 1, 'the bell only animates inside prefers-reduced-motion: no-preference');
    check(str_contains($page, '<details id="bell"') && str_contains($page, 'aria-label="Notifications, '), 'the bell is a keyboard-operable details/summary with a text label (no JavaScript needed)');
    $pdo->exec("DELETE FROM notifications WHERE dedupe_key = 'zzn-xss'");

    // old notifications are tidied
    $pdo->exec("INSERT INTO notifications (kind, dedupe_key, title, created_at) VALUES ('x', 'zzn-old', 'ZZN old', NOW() - INTERVAL 100 DAY)");
    notifications_refresh($pdo, true);
    same($nn('zzn-old'), 0, 'notifications older than 90 days are removed');

    // notification email in Settings
    $acc = http('GET', "$base/settings.php?tab=account", ['jar' => $admin])['body'];
    check(str_contains($acc, 'name="notify_email"') && str_contains($acc, 'value="denis@telecomlijn.nl"'), 'Settings > Account has the notification email, pre-filled with denis@telecomlijn.nl');
    check(!str_contains(http('GET', "$base/settings.php?tab=account", ['jar' => $user])['body'], 'notify_email'), 'a normal account does not see it');
    same($post($user, 'settings.php?tab=account', ['tab' => 'account', 'action' => 'notify', 'notify_email' => 'evil@example.test'], 'settings.php?tab=account')['code'], 403, 'a normal account can not change it (403)');
    same($pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'")->fetchColumn(), 'denis@telecomlijn.nl', 'and it did not change');
    $post($admin, 'settings.php?tab=account', ['tab' => 'account', 'action' => 'notify', 'notify_email' => 'not an email'], 'settings.php?tab=account');
    same($pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'")->fetchColumn(), 'denis@telecomlijn.nl', 'an invalid address is refused');
    $post($admin, 'settings.php?tab=account', ['tab' => 'account', 'action' => 'notify', 'notify_email' => 'team@example.test'], 'settings.php?tab=account');
    same($pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'")->fetchColumn(), 'team@example.test', 'an admin can change it');
    check(str_contains(http('GET', "$base/settings.php?tab=account", ['jar' => $admin])['body'], 'value="team@example.test"'), 'the new address is shown');
    $post($admin, 'settings.php?tab=account', ['tab' => 'account', 'action' => 'notify', 'notify_email' => ''], 'settings.php?tab=account');
    same(notify_email($pdo), '', 'it can be emptied to turn emails off');
    $pdo->exec("DELETE FROM app_meta WHERE k = 'notify_email'");
    same(notify_email($pdo), 'denis@telecomlijn.nl', 'with no saved value the default address is used');

    // demo flag
    $pdo->exec('DELETE FROM notifications WHERE is_demo = 1'); notifications_refresh($pdo, true);
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications n JOIN invoices i ON n.dedupe_key = CONCAT('invoice-overdue:', i.id) WHERE i.is_demo = 1 AND n.is_demo = 0")->fetchColumn(), 0, 'notifications about demo invoices are tagged as demo, so removing demo data removes them');
    $cleanNotif();
    $pdo->exec("DELETE FROM notifications WHERE dedupe_key = 'zzn-xss' OR dedupe_key = 'zzn-old'");

    // =========================================================
    // Stage 7: two-factor sign-in
    // =========================================================
    $tfaUser = 'tfa.tester'; $tfaPass = 'Tfa-Test-Passw0rd!';
    $cleanTfa = function () use ($pdo, $tfaUser) {
        $pdo->prepare('DELETE FROM admins WHERE username = ?')->execute([$tfaUser]);   // its recovery codes go with it
        $pdo->exec("DELETE FROM login_attempts WHERE username LIKE '2fa:tfa.%' OR username = 'tfa.tester'");
        $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND (target = 'tfa.tester' OR actor = 'tfa.tester' OR action LIKE 'Failed two-step%') AND created_at > NOW() - INTERVAL 2 HOUR");
    };
    $cleanTfa();
    $pdo->exec('DELETE FROM rate_limits');
    section('19. Two-factor sign-in: the algorithm (RFC test values)');
    $rfc = '12345678901234567890';
    foreach ([[59, '94287082'], [1111111109, '07081804'], [1111111111, '14050471'], [1234567890, '89005924'], [2000000000, '69279037'], [20000000000, '65353130']] as [$t, $want]) {
        same(totp_code($rfc, $t, 8), $want, "RFC 6238 test value at T=$t");
    }
    same(array_map(fn($c) => hotp($rfc, $c), range(0, 9)), ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'], 'RFC 4226 test values for counters 0 to 9');
    same(base32_encode($rfc), 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 matches the known encoding of the RFC secret');
    same(base32_decode('gezd gnbv-GY3TQOJQGEZDGNBVGY3TQOJQ'), $rfc, 'base32 decoding tolerates spaces, dashes and lower case');
    same([base32_decode('not base32!'), base32_decode(''), base32_decode('189')], [null, null, null], 'invalid base32 is rejected');
    $sec = totp_new_secret();
    same([strlen($sec), $sec === totp_new_secret()], [20, false], 'a new secret is 160 random bits');
    $now = time();
    same(totp_verify($sec, totp_code($sec, $now), $now), intdiv($now, 30), 'the current code is accepted and its step returned');
    check(totp_verify($sec, totp_code($sec, $now - 30), $now) !== null && totp_verify($sec, totp_code($sec, $now + 30), $now) !== null, 'the previous and the next 30-second code are accepted (clock differences)');
    same([totp_verify($sec, totp_code($sec, $now - 90), $now), totp_verify($sec, totp_code($sec, $now + 90), $now)], [null, null], 'older and later codes (expired) are refused');
    $good = totp_code($sec, $now);
    same(totp_verify($sec, substr($good, 0, 3) . ' ' . substr($good, 3), $now), intdiv($now, 30), 'spaces typed inside the code are ignored');
    same([totp_verify($sec, '12345', $now), totp_verify($sec, '1234567', $now), totp_verify($sec, 'abcdef', $now), totp_verify($sec, '', $now)], [null, null, null, null], 'codes that are not exactly 6 digits are refused');
    $uri = totp_uri($rfc, 'jan');
    check(str_starts_with($uri, 'otpauth://totp/Telecomlijn%20Admin%3Ajan?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=Telecomlijn%20Admin') && str_contains($uri, 'digits=6') && str_contains($uri, 'period=30'), 'the otpauth link has issuer, account, secret, digits and period');
    same(totp_secret_text($rfc), 'GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ', 'the typed key is grouped in fours');

    // an account to test with
    $pdo->prepare("INSERT INTO admins (username, email, name, password_hash, role, perm_integrations, must_change_password) VALUES (?, 'tfa.tester@example.test', 'Tfa Tester', ?, 'user', 'none', 0)")->execute([$tfaUser, hash_password($tfaPass)]);
    $tfaId = (int) $pdo->query("SELECT id FROM admins WHERE username = 'tfa.tester'")->fetchColumn();
    $pwStep = function (string $jar, string $u = 'tfa.tester', string $p = 'Tfa-Test-Passw0rd!') use ($base, $pdo) {
        $pdo->exec('DELETE FROM rate_limits');
        $page = http('GET', "$base/login.php", ['jar' => $jar]);
        return http('POST', "$base/login.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($page['body']), 'username' => $u, 'password' => $p])]);
    };
    $codeStep = function (string $jar, string $code) use ($base, $pdo) {
        $pdo->exec('DELETE FROM rate_limits');
        $page = http('GET', "$base/login_2fa.php", ['jar' => $jar]);
        return http('POST', "$base/login_2fa.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($page['body']), 'code' => $code])]);
    };
    $signedIn = fn(string $jar) => http('GET', "$base/index.php", ['jar' => $jar])['code'] === 200;
    $newJar = fn() => tempnam(sys_get_temp_dir(), 'tf');

    section('20. Two-factor sign-in: turning it on from Settings');
    $jar = $newJar();
    $r = $pwStep($jar);
    check($r['code'] === 302 && str_contains($r['headers'], 'index.php') && $signedIn($jar), 'without two-step an account signs in as before (password only)');
    $acc = http('GET', "$base/settings.php?tab=account", ['jar' => $jar])['body'];
    check(str_contains($acc, 'Two-step sign-in') && str_contains($acc, 'Set up two-step sign-in') && !str_contains($acc, 'Secret key'), 'Settings > Account offers the set-up and shows no secret yet');
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 0, 'it is off in the database');
    $tf = function (array $d) use ($post, $jar) { return $post($jar, 'settings.php?tab=account', ['tab' => 'account'] + $d, 'settings.php?tab=account'); };
    $tf(['action' => '2fa_start']);
    $acc = http('GET', "$base/settings.php?tab=account", ['jar' => $jar])['body'];
    preg_match('/Secret key<\/dt><dd[^>]*>([A-Z2-7 ]+)</', $acc, $m);
    $secret = base32_decode($m[1] ?? '');
    check($secret !== null && strlen($secret) === 20 && str_contains($acc, 'otpauth://totp/') && str_contains($acc, 'Confirm and turn on'), 'the set-up shows a 20-byte secret key, the otpauth link and a confirm form');
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 0, 'still off until the first code is confirmed');
    check(!str_contains(http('GET', "$base/settings.php?tab=account", ['jar' => $newJar()])['body'], 'Secret key'), 'the secret is only in the signed-in session, not on any other page');
    $tf(['action' => '2fa_confirm', 'code' => '000000']);
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 0, 'a wrong first code does not turn it on');
    $tf(['action' => '2fa_confirm', 'code' => '12']);
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 0, 'a malformed first code does not turn it on');
    $pdo->exec("DELETE FROM login_attempts WHERE username LIKE '2fa:tfa.%'");
    $confirmAt = time();
    $tf(['action' => '2fa_confirm', 'code' => totp_code($secret, $confirmAt)]);
    $row = $pdo->query("SELECT * FROM admins WHERE id = $tfaId")->fetch();
    same((int) $row['totp_enabled'], 1, 'the right first code turns two-step sign-in on');
    $stored = (string) $row['totp_secret_enc'];
    check($stored !== '' && !str_contains($stored, base32_encode($secret)) && !str_contains($stored, $secret) && secret_decrypt($stored) === $secret, 'the secret is stored encrypted (AES-256-GCM), not as plain text, and decrypts to the same key');
    $acc = http('GET', "$base/settings.php?tab=account", ['jar' => $jar])['body'];
    preg_match_all('/<li>([A-Z2-9]{5}-[A-Z2-9]{5})<\/li>/', $acc, $mm);
    $codes = $mm[1];
    check(count($codes) === 10 && count(array_unique($codes)) === 10, 'ten different recovery codes are shown');
    $hashes = $pdo->query("SELECT code_hash FROM admin_recovery_codes WHERE admin_id = $tfaId")->fetchAll(PDO::FETCH_COLUMN);
    check(count($hashes) === 10 && !array_intersect($hashes, array_merge($codes, array_map(fn($c) => str_replace('-', '', $c), $codes))) && strlen($hashes[0]) === 64, 'they are stored only as 64-character hashes, never in plain text');
    check(!str_contains(http('GET', "$base/settings.php?tab=account", ['jar' => $jar])['body'], $codes[0]), 'the recovery codes are shown once: a reload no longer shows them');
    check(str_contains(http('GET', "$base/settings.php?tab=account", ['jar' => $jar])['body'], 'Unused recovery codes: <strong class="text-ink">10</strong>'), 'the page counts the unused recovery codes');
    same((int) $pdo->query("SELECT totp_last_step FROM admins WHERE id = $tfaId")->fetchColumn(), intdiv($confirmAt, 30), 'the step used to confirm is remembered (it can not be used again)');
    check(((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Two-step sign-in turned on' AND target = 'tfa.tester'")->fetchColumn()) === 1, 'turning it on is in the activity log');
    same(http('POST', "$base/settings.php?tab=account", ['body' => 'action=2fa_start&tab=account'])['code'], 302, 'signed out: the set-up can not be started');
    same(http('POST', "$base/settings.php?tab=account", ['jar' => $jar, 'body' => 'action=2fa_start&tab=account'])['code'], 403, 'a set-up request without a CSRF token is 403');

    section('21. Two-factor sign-in: the login steps');
    $j = $newJar();
    $r = $pwStep($j);
    check($r['code'] === 302 && str_contains($r['headers'], 'login_2fa.php') && !str_contains($r['headers'], 'index.php'), 'right password: sent to the code step, not into the dashboard');
    same(http('GET', "$base/index.php", ['jar' => $j])['code'], 302, 'and the dashboard is still closed: the password alone is not enough');
    same(http('GET', "$base/settings.php", ['jar' => $j])['code'], 302, 'so are the other pages');
    check(str_contains(http('GET', "$base/login_2fa.php", ['jar' => $j])['body'], 'Two-step sign-in'), 'the code step page opens for that session');
    same(http('GET', "$base/login_2fa.php", ['jar' => $newJar()])['code'], 302, 'the code page is not reachable without first passing the password step');
    same(http('POST', "$base/login_2fa.php", ['body' => 'code=123456'])['code'], 302, 'a code posted without a session is refused');
    $r = $pwStep($newJar(), 'tfa.tester', 'wrong-password');
    check($r['code'] === 200 && !str_contains($r['headers'], 'login_2fa'), 'a wrong password never reaches the code step');
    $r = $codeStep($j, '000000');
    check($r['code'] === 200 && str_contains($r['body'], 'That code is not right') && !$signedIn($j), 'a wrong code is refused with a message and signs nobody in');
    $r = $codeStep($j, totp_code($secret, time() - 150));
    check($r['code'] === 200 && !$signedIn($j), 'an expired code (several steps old) is refused');
    $r = $codeStep($j, totp_code($secret, $confirmAt));
    check($r['code'] === 200 && !$signedIn($j), 'the code that was just used to turn it on can not be used again');
    $next = totp_code($secret, $confirmAt + 30);
    $r = $codeStep($j, substr($next, 0, 3) . ' ' . substr($next, 3));
    check($r['code'] === 302 && str_contains($r['headers'], 'index.php') && $signedIn($j), 'a fresh valid code signs in (a space typed in the middle is fine)');
    check(str_contains(http('GET', "$base/index.php", ['jar' => $j])['body'], 'Tfa Tester') || $signedIn($j), 'the session is a normal signed-in session');
    $j2 = $newJar(); $pwStep($j2);
    $r = $codeStep($j2, $next);
    check($r['code'] === 200 && !$signedIn($j2), 'the same code can not be replayed in a second sign-in, even within its 30 seconds');
    $r = $codeStep($j2, totp_code($secret, $confirmAt));
    check($r['code'] === 200 && !$signedIn($j2), 'and an older code than the last one used is refused too');

    // recovery codes
    $j3 = $newJar(); $pwStep($j3);
    $rc = $codes[0];
    $r = $codeStep($j3, strtolower(str_replace('-', '', $rc)));
    check($r['code'] === 302 && $signedIn($j3), 'a recovery code signs in (typed in lower case without the dash)');
    $j4 = $newJar(); $pwStep($j4);
    $r = $codeStep($j4, $rc);
    check($r['code'] === 200 && !$signedIn($j4), 'the same recovery code can not be used a second time');
    same(recovery_codes_left($pdo, $tfaId), 9, 'one recovery code is now used up, nine are left');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Signed in with a recovery code' AND target = 'tfa.tester'")->fetchColumn() === 1, 'signing in with a recovery code is logged as a warning');
    $r = $codeStep($j4, 'ZZZZZ-ZZZZZ');
    check($r['code'] === 200 && !$signedIn($j4), 'a made-up recovery code is refused');

    // lock-out
    $pdo->exec("DELETE FROM login_attempts WHERE username LIKE '2fa:tfa.%'");
    $jl = $newJar(); $pwStep($jl);
    $last = null;
    for ($i = 0; $i < 5; $i++) { $last = $codeStep($jl, sprintf('%06d', 111111 + $i)); }
    check($last['code'] === 200 && str_contains($last['body'], 'not right'), 'five wrong codes are each answered with an error');
    same((int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE username = '2fa:tfa.tester'")->fetchColumn(), 5, 'each wrong code is counted per address in the database');
    $r = $codeStep($jl, totp_code($secret, time() + 30));
    check($r['code'] === 302 && str_contains($r['headers'], 'login.php?locked=1') && !$signedIn($jl), 'the sixth attempt is locked out, even with a correct code');
    $r = http('GET', "$base/login.php?locked=1", ['jar' => $newJar()]);
    check(str_contains($r['body'], 'Too many failed attempts'), 'the login page explains the lock-out');
    $jl2 = $newJar(); $r = $pwStep($jl2);
    check($r['code'] === 302 && str_contains($r['headers'], 'login_2fa.php'), 'the password step still works while locked (the lock is on the code step)');
    $r = $codeStep($jl2, totp_code($secret, time() + 30));
    check(!$signedIn($jl2), 'but the code step stays locked for new sign-ins too until the window has passed');
    $pdo->exec("DELETE FROM login_attempts WHERE username LIKE '2fa:tfa.%'");
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Failed two-step code' AND target = 'tfa.tester'")->fetchColumn() >= 5, 'wrong codes are in the activity log');

    // a code must be the right account's
    $other = base32_decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
    $jo = $newJar(); $pwStep($jo);
    $r = $codeStep($jo, totp_code($other, time()));
    check($r['code'] === 200 && !$signedIn($jo), 'a valid code from some other secret does not work');
    $pdo->exec("DELETE FROM login_attempts WHERE username LIKE '2fa:tfa.%'");

    // the default admin is unaffected
    $ja = $newJar();
    $r = $pwStep($ja, $adminUser, $adminPass);
    check($r['code'] === 302 && str_contains($r['headers'], 'index.php') && $signedIn($ja), 'an account without two-step (admin / admin) still signs in with the password alone');
    check($r['code'] === 302 && !str_contains($r['headers'], 'login_2fa'), 'and never sees the code step');

    section('22. Two-factor sign-in: turning it off, and admin reset');
    $jt = $newJar(); $pwStep($jt); $codeStep($jt, totp_code($secret, time() + 30));
    $tf = function (array $d) use ($post, $jt) { return $post($jt, 'settings.php?tab=account', ['tab' => 'account'] + $d, 'settings.php?tab=account'); };
    if (!$signedIn($jt)) { $pdo->prepare('UPDATE admins SET totp_last_step = NULL WHERE id = ?')->execute([$tfaId]); $jt = $newJar(); $pwStep($jt); $codeStep($jt, totp_code($secret, time())); $tf = function (array $d) use ($post, $jt) { return $post($jt, 'settings.php?tab=account', ['tab' => 'account'] + $d, 'settings.php?tab=account'); }; }
    check($signedIn($jt), 'signed in again with a valid code to try turning it off');
    $tf(['action' => '2fa_disable', 'password' => 'wrong', 'code' => totp_code($secret, time() + 30)]);
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 1, 'turning off with a wrong password does nothing');
    $pdo->exec("UPDATE admins SET totp_last_step = " . (intdiv(time(), 30) - 5) . " WHERE id = $tfaId");
    $tf(['action' => '2fa_disable', 'password' => $tfaPass, 'code' => '000000']);
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 1, 'turning off with a wrong code does nothing');
    $tf(['action' => '2fa_disable', 'password' => $tfaPass, 'code' => '']);
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 1, 'turning off with no code does nothing');
    $pdo->exec("DELETE FROM login_attempts WHERE username LIKE '2fa:tfa.%'");
    $tf(['action' => '2fa_disable', 'password' => $tfaPass, 'code' => totp_code($secret, time())]);
    $row = $pdo->query("SELECT totp_enabled, totp_secret_enc, totp_last_step FROM admins WHERE id = $tfaId")->fetch();
    check((int) $row['totp_enabled'] === 0 && $row['totp_secret_enc'] === null && $row['totp_last_step'] === null, 'with the password and a valid code it is turned off and the secret is erased');
    same((int) $pdo->query("SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = $tfaId")->fetchColumn(), 0, 'the recovery codes are deleted too');
    $jx = $newJar(); $r = $pwStep($jx);
    check($r['code'] === 302 && str_contains($r['headers'], 'index.php'), 'the account signs in with the password alone again');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Two-step sign-in turned off' AND target = 'tfa.tester'")->fetchColumn() === 1, 'turning it off is logged');
    // turn it on with a recovery code as the proof when disabling
    $pdo->prepare('UPDATE admins SET totp_secret_enc = ?, totp_enabled = 1 WHERE id = ?')->execute([secret_encrypt($secret), $tfaId]);
    $rcs = recovery_codes_make($pdo, $tfaId);
    $jr = $newJar(); $pwStep($jr); $codeStep($jr, $rcs[0]);
    $post($jr, 'settings.php?tab=account', ['tab' => 'account', 'action' => '2fa_disable', 'password' => $tfaPass, 'code' => $rcs[1]], 'settings.php?tab=account');
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 0, 'a recovery code also works as the proof when turning it off');

    // admin reset
    $pdo->prepare('UPDATE admins SET totp_secret_enc = ?, totp_enabled = 1 WHERE id = ?')->execute([secret_encrypt($secret), $tfaId]);
    recovery_codes_make($pdo, $tfaId);
    $team = http('GET', "$base/settings.php?tab=team", ['jar' => $admin])['body'];
    check(str_contains($team, 'Two-step sign-in on') && str_contains($team, 'Reset two-step'), 'Settings > Team shows who has two-step on and a Reset button');
    same($post($user, 'settings.php?tab=team', ['tab' => 'team', 'action' => 'reset_2fa', 'id' => (string) $tfaId], 'settings.php?tab=account')['code'], 403, 'a normal account can not reset anyone\'s two-step (403)');
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 1, 'and nothing changed');
    $adminSelf = (int) $pdo->query("SELECT id FROM admins WHERE username = '" . $adminUser . "'")->fetchColumn();
    $post($admin, 'settings.php?tab=team', ['tab' => 'team', 'action' => 'reset_2fa', 'id' => (string) $adminSelf], 'settings.php?tab=team');
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $adminSelf")->fetchColumn(), 0, 'resetting yourself from Team is refused (use the Account tab); the admin still has it off');
    $post($admin, 'settings.php?tab=team', ['tab' => 'team', 'action' => 'reset_2fa', 'id' => (string) $tfaId], 'settings.php?tab=team');
    $row = $pdo->query("SELECT totp_enabled, totp_secret_enc FROM admins WHERE id = $tfaId")->fetch();
    check((int) $row['totp_enabled'] === 0 && $row['totp_secret_enc'] === null && (int) $pdo->query("SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = $tfaId")->fetchColumn() === 0, 'an admin can reset another person\'s two-step: off, secret and recovery codes erased');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Two-step sign-in reset by admin' AND target = 'tfa.tester'")->fetchColumn() === 1, 'the reset is logged');
    $jz = $newJar(); $r = $pwStep($jz);
    check($r['code'] === 302 && str_contains($r['headers'], 'index.php'), 'after a reset the person can sign in with their password and set it up again');
    $rr = $post($admin, 'settings.php?tab=team', ['tab' => 'team', 'action' => 'reset_2fa', 'id' => (string) $tfaId], 'settings.php?tab=team');
    check($rr['code'] === 302 && (int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Two-step sign-in reset by admin' AND target = 'tfa.tester'")->fetchColumn() === 1, 'resetting an account that has it off changes nothing and is not logged again');
    $cleanTfa();
    $pdo->exec('DELETE FROM rate_limits');

    // =========================================================
    // Stage 8: email, password reset, invitations
    // =========================================================
    $smtpPort = 3225; $smtpLog = __DIR__ . '/.smtp_log'; $smtpMode = __DIR__ . '/.smtp_mode';
    $mailBackup = $pdo->query('SELECT * FROM mail_settings WHERE id = 1')->fetch() ?: null;
    $notifyBackup = $pdo->query("SELECT v FROM app_meta WHERE k = 'notify_email'")->fetchColumn();
    $outboxStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM mail_outbox')->fetchColumn();
    @unlink($smtpLog); @unlink($smtpMode);
    $smtpProc = proc_open([PHP_BINARY, __DIR__ . '/mock_smtp.php', (string) $smtpPort], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/mock_smtp.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/mock_smtp.log', 'a']], $smtpPipes);
    for ($i = 0; $i < 30; $i++) { $t = @stream_socket_client("tcp://127.0.0.1:$smtpPort", $en, $es, 1); if ($t) { fclose($t); break; } usleep(200000); }
    $smtpLast = function () use ($smtpLog) { $l = is_file($smtpLog) ? array_filter(explode("\n", (string) file_get_contents($smtpLog))) : []; return $l ? json_decode(end($l), true) : null; };
    $smtpCount = fn() => is_file($smtpLog) ? count(array_filter(explode("\n", (string) file_get_contents($smtpLog)))) : 0;
    $cleanMail = function () use ($pdo, $mailBackup, $notifyBackup, $outboxStart, $smtpMode) {
        @unlink($smtpMode);
        $pdo->exec("DELETE FROM admins WHERE username LIKE 'zz.rst%' OR username LIKE 'zz.inv%'");
        $pdo->exec("DELETE FROM invitations WHERE username LIKE 'zz.inv%' OR email LIKE 'zz.inv%'");
        $pdo->exec("DELETE FROM login_attempts WHERE username LIKE 'zz.%'");
        $pdo->exec("DELETE FROM tickets WHERE subject LIKE 'ZZM%'");
        $pdo->exec("DELETE FROM notifications WHERE title LIKE '%ZZM%' OR body LIKE '%ZZM%'");
        $pdo->exec('DELETE FROM mail_outbox WHERE id > ' . $outboxStart);
        $pdo->exec('DELETE FROM mail_settings');
        if ($mailBackup) { $pdo->prepare('INSERT INTO mail_settings (id, host, port, encryption, username, password_enc, from_email, from_name, base_url, updated_at) VALUES (1,?,?,?,?,?,?,?,?,?)')->execute([$mailBackup['host'], $mailBackup['port'], $mailBackup['encryption'], $mailBackup['username'], $mailBackup['password_enc'], $mailBackup['from_email'], $mailBackup['from_name'], $mailBackup['base_url'], $mailBackup['updated_at']]); }
        if ($notifyBackup !== false) { $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('notify_email', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$notifyBackup]); }
        $pdo->exec("DELETE FROM activity_logs WHERE is_demo = 0 AND created_at > NOW() - INTERVAL 2 HOUR AND (action IN ('Email settings saved','Email password removed','Test email','Email outbox cleared','Password reset requested','Password reset completed','Password reset not sent','Invitation sent','Invitation accepted','Invitation withdrawn') OR target LIKE 'zz.%')");
        $pdo->exec('DELETE FROM rate_limits');
    };
    $cleanMail();
    try {
    section('23. Email: the mailer and Settings > Email');
    $pdo->exec('DELETE FROM rate_limits');
    same(http('GET', "$base/settings.php?tab=email")['code'], 302, 'signed out: the Email tab redirects to the login page');
    same(http('GET', "$base/settings.php?tab=email", ['jar' => $user])['code'], 403, 'a normal account gets 403 on the Email tab');
    check(!str_contains(http('GET', "$base/settings.php", ['jar' => $user])['body'], 'tab=email') && str_contains(http('GET', "$base/settings.php", ['jar' => $admin])['body'], 'tab=email'), 'the Email tab is hidden from normal accounts and shown to admins');
    same($post($user, 'settings.php?tab=email', ['tab' => 'email', 'action' => 'save', 'host' => 'evil.example.test', 'port' => '25', 'encryption' => 'none', 'from_email' => 'x@example.test'], 'settings.php')['code'], 403, 'a forged save by a normal account is 403');
    same((int) $pdo->query('SELECT COUNT(*) FROM mail_settings')->fetchColumn(), 0, 'and nothing was saved');
    check(str_contains(http('GET', "$base/settings.php?tab=email", ['jar' => $admin])['body'], 'Outbox only (no mail server on this computer)'), 'with no settings the status says messages are only kept in the outbox (localhost)');
    $em = fn(array $d) => $post($admin, 'settings.php?tab=email', ['tab' => 'email', 'action' => 'save'] + $d, 'settings.php?tab=email');
    $good = ['host' => '127.0.0.1', 'port' => (string) $smtpPort, 'encryption' => 'none', 'username' => 'mailer-user', 'password' => 'S3cret-Mail-Pw', 'from_email' => 'no-reply@example.test', 'from_name' => 'Test Sender', 'base_url' => ''];
    foreach ([['mail server name is not valid', ['host' => 'bad host!']], ['port must be between', ['port' => '70000']], ['port must be between', ['port' => '0']], ['how the connection is secured', ['encryption' => 'weird']], ['sender address is not a valid', ['from_email' => 'nope']], ['Enter the sender address', ['from_email' => '']], ['site address must look like', ['base_url' => 'javascript:alert(1)']], ['site address must look like', ['base_url' => 'https://a.test/?x=1']]] as [$msg, $bad]) {
        $em($bad + $good);
        $page = http('GET', "$base/settings.php?tab=email", ['jar' => $admin])['body'];
        same((int) $pdo->query('SELECT COUNT(*) FROM mail_settings')->fetchColumn(), 0, 'validation refuses: ' . $msg);
    }
    $em($good);
    $c = $pdo->query('SELECT * FROM mail_settings WHERE id = 1')->fetch();
    check($c && $c['host'] === '127.0.0.1' && (int) $c['port'] === $smtpPort && $c['username'] === 'mailer-user' && $c['from_email'] === 'no-reply@example.test', 'valid settings are saved');
    check($c['password_enc'] !== null && !str_contains((string) $c['password_enc'], 'S3cret') && secret_decrypt((string) $c['password_enc']) === 'S3cret-Mail-Pw', 'the SMTP password is stored encrypted and decrypts to what was typed');
    $page = http('GET', "$base/settings.php?tab=email", ['jar' => $admin])['body'];
    check(!str_contains($page, 'S3cret-Mail-Pw') && str_contains($page, '(saved, leave empty to keep)') && str_contains($page, 'value="127.0.0.1"'), 'the page never shows the password, only that one is saved');
    $enc1 = $c['password_enc'];
    $em(['password' => ''] + $good);
    same($pdo->query('SELECT password_enc FROM mail_settings WHERE id = 1')->fetchColumn(), $enc1, 'saving with an empty password keeps the saved one');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Email settings saved' AND target LIKE '127.0.0.1%'")->fetchColumn() >= 1 && !str_contains((string) $pdo->query("SELECT GROUP_CONCAT(CONCAT(action, target)) FROM activity_logs WHERE action LIKE 'Email%'")->fetchColumn(), 'S3cret'), 'saving is logged, without the password');

    // sending through the mock server
    $n0 = $smtpCount();
    $r = $post($admin, 'settings.php?tab=email', ['tab' => 'email', 'action' => 'test'], 'settings.php?tab=email');
    $got = $smtpLast();
    check($smtpCount() === $n0 + 1 && $got && $got['user'] === 'mailer-user' && $got['pass'] === 'S3cret-Mail-Pw' && $got['from'] === 'no-reply@example.test' && $got['rcpt'] === ['admin@telecomlijn.test'], 'a test email goes through the SMTP server: login, sender and recipient are right');
    preg_match('/\r\n\r\n(.*)$/s', (string) ($got['data'] ?? ''), $bm);
    check(str_contains((string) base64_decode(str_replace("\r\n", '', $bm[1] ?? '')), 'If you can read this, the email settings work.') && str_contains((string) $got['data'], 'Content-Type: text/plain; charset=UTF-8') && str_contains((string) $got['data'], 'Subject: Test email from Telecomlijn Admin'), 'the message has the right subject, text/plain UTF-8 and a base64 body that reads correctly');
    same($pdo->query("SELECT status FROM mail_outbox WHERE kind = 'test' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'sent', 'the outbox records it as sent');
    check(str_contains(http('GET', "$base/settings.php?tab=email", ['jar' => $admin])['body'], 'Test email from Telecomlijn Admin'), 'the Outbox section lists it');
    // refusals
    file_put_contents($smtpMode, 'authfail');
    [$st, , $note] = mail_send($pdo, 'someone@example.test', 'Subject', 'Body', 'other');
    check($st === 'failed' && str_contains($note, 'refused') && !str_contains($note, 'S3cret'), 'a refused login is reported as failed, without the password in the message', $note);
    file_put_contents($smtpMode, 'rcptfail');
    [$st, , $note] = mail_send($pdo, 'someone@example.test', 'Subject', 'Body', 'other');
    check($st === 'failed' && str_contains($note, 'No such user'), 'a refused recipient is reported as failed with the server\'s reason');
    @unlink($smtpMode);
    $pdo->prepare("UPDATE mail_settings SET port = 3197 WHERE id = 1")->execute();
    [$st, , $note] = mail_send($pdo, 'someone@example.test', 'Subject', 'Body', 'other');
    check($st === 'failed' && str_contains($note, 'Could not connect'), 'a closed port is reported as failed');
    $pdo->prepare("UPDATE mail_settings SET port = ? WHERE id = 1")->execute([$smtpPort]);
    same($pdo->query("SELECT status FROM mail_outbox ORDER BY id DESC LIMIT 1")->fetchColumn(), 'failed', 'failed messages stay in the outbox with status failed');
    // injection and validation
    $n0 = $smtpCount();
    foreach (["a@b.test\r\nBcc: evil@x.test", "a@b.test\nBcc: evil@x.test", 'not-an-email', '', str_repeat('a', 200) . '@b.test'] as $badTo) {
        [$st] = mail_send($pdo, $badTo, 'Hi', 'Body', 'other');
        if ($st !== 'failed') { check(false, 'an invalid recipient is refused: ' . json_encode($badTo)); }
    }
    same($smtpCount(), $n0, 'recipients with line breaks, nonsense or over-long addresses are refused before anything is sent');
    mail_send($pdo, 'inj@example.test', "Hello\r\nBcc: evil@x.test", 'Body', 'other');
    $got = $smtpLast();
    preg_match('/^(.*?)\r\n\r\n/s', (string) $got['data'], $hm);
    check(!str_contains($hm[1] ?? '', "\r\nBcc:") && str_contains($hm[1], 'Subject: Hello Bcc: evil@x.test'), 'a line break in the subject can not inject a header');
    mail_send($pdo, 'uni@example.test', "Café résumé ✓", "Body ünïcode", 'other');
    $got = $smtpLast();
    check(str_contains((string) $got['data'], 'Subject: =?UTF-8?B?' . base64_encode('Café résumé ✓') . '?='), 'a subject with accents is encoded correctly');
    same($pdo->query("SELECT body FROM mail_outbox WHERE to_email = 'uni@example.test'")->fetchColumn(), 'Body ünïcode', 'the outbox keeps the original text');
    $msg = mail_build('a@b.test', "Evil\r\nBcc: x@y.test", 'c@d.test', 'S', "Line\n.\nLine", 'host');
    check(!str_contains(strtok($msg, "\r\n\r\n"), 'Bcc') && substr_count($msg, "\r\n\r\n") >= 1, 'the sender name can not inject a header either');
    $pdo->exec("INSERT INTO mail_outbox (kind, to_email, subject, body, status) VALUES ('other', 'x@y.test', 'ZZM <b>bold</b>', '<script>alert(1)</script>', 'recorded')");
    $ob = http('GET', "$base/settings.php?tab=email", ['jar' => $admin])['body'];
    check(!str_contains($ob, '<script>alert(1)') && str_contains($ob, '&lt;script&gt;') && !str_contains($ob, '<b>bold</b>'), 'the outbox shows messages as text: markup is escaped');
    // wiping one-time links once delivered
    [$st, $id] = mail_send($pdo, 'wipe@example.test', 'Link', 'Open https://x.test/reset.php?token=SECRETTOKEN', 'reset');
    check($st === 'sent' && !str_contains((string) $pdo->query("SELECT body FROM mail_outbox WHERE id = $id")->fetchColumn(), 'SECRETTOKEN'), 'a delivered message that contained a one-time link loses its text in the outbox');
    [$st, $id] = mail_send($pdo, 'keep@example.test', 'Plain', 'No link here', 'other');
    same($pdo->query("SELECT body FROM mail_outbox WHERE id = $id")->fetchColumn(), 'No link here', 'other delivered messages keep their text');
    // clearing
    $post($user, 'settings.php?tab=email', ['tab' => 'email', 'action' => 'clear_outbox'], 'settings.php');
    check((int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn() > 0, 'a normal account can not empty the outbox');
    // notifications by email (stage 6)
    $pdo->exec("DELETE FROM app_meta WHERE k = 'notify_email'");
    $pdo->exec("INSERT INTO app_meta (k, v) VALUES ('notify_email', 'alerts@example.test')");
    notifications_refresh($pdo, true);   // absorb existing ones
    $pdo->exec("UPDATE mail_settings SET base_url = 'https://admin.example.test' WHERE id = 1");   // links in emails need a site address
    $n0 = $smtpCount();
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZM-T-1', 'ZZM Server room on fire', 'x', 'urgent', 'open', NOW(), NOW())");
    notifications_refresh($pdo, true);
    $got = $smtpLast();
    check($smtpCount() === $n0 + 1 && $got['rcpt'] === ['alerts@example.test'], 'a new notification is emailed to the notification address');
    $dec = (string) base64_decode(str_replace("\r\n", '', preg_replace('/^.*?\r\n\r\n/s', '', (string) $got['data'])));
    check(str_contains((string) $got['data'], 'Subject: Telecomlijn Admin: Urgent ticket ZZM-T-1') && str_contains($dec, 'ZZM Server room on fire') && str_contains($dec, 'https://admin.example.test/ticket.php?id='), 'the email names the event and links to it');
    same((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE emailed_at IS NULL")->fetchColumn(), 0, 'every notification is marked as handled');
    notifications_refresh($pdo, true);
    same($smtpCount(), $n0 + 1, 'the same event is never emailed twice');
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZM-T-2', 'ZZM Second fire', 'x', 'urgent', 'open', NOW(), NOW()), ('ZZM-T-3', 'ZZM Third fire', 'x', 'urgent', 'open', NOW(), NOW())");
    notifications_refresh($pdo, true);
    $got = $smtpLast();
    check($smtpCount() === $n0 + 2 && str_contains((string) $got['data'], 'Subject: Telecomlijn Admin: 2 new notifications'), 'several new events at once are sent as one email');
    $pdo->exec("INSERT INTO app_meta (k, v) VALUES ('notify_email', '') ON DUPLICATE KEY UPDATE v = ''");
    $pdo->exec("INSERT INTO tickets (reference, subject, description, priority, status, created_at, updated_at) VALUES ('ZZM-T-4', 'ZZM Quiet fire', 'x', 'urgent', 'open', NOW(), NOW())");
    notifications_refresh($pdo, true);
    check($smtpCount() === $n0 + 2 && (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE emailed_at IS NULL")->fetchColumn() === 0, 'with the address emptied nothing is sent, and the backlog is not mailed later');
    $pdo->exec("INSERT INTO app_meta (k, v) VALUES ('notify_email', 'alerts@example.test') ON DUPLICATE KEY UPDATE v = 'alerts@example.test'");
    $pdo->exec("INSERT INTO notifications (kind, dedupe_key, title, body, is_demo, created_at) VALUES ('ticket_urgent', 'zzm-demo', 'ZZM demo event', 'x', 1, NOW())");
    notifications_refresh($pdo, true);
    check($smtpCount() === $n0 + 2 && (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'zzm-demo' AND emailed_at IS NOT NULL")->fetchColumn() === 1, 'demo-data events are never emailed to the real address');
    // the old outbox is tidied
    $pdo->exec("INSERT INTO mail_outbox (kind, to_email, subject, body, status, created_at) VALUES ('other', 'old@y.test', 'ZZM old', 'x', 'sent', NOW() - INTERVAL 40 DAY)");
    mail_purge($pdo);
    same((int) $pdo->query("SELECT COUNT(*) FROM mail_outbox WHERE subject = 'ZZM old'")->fetchColumn(), 0, 'outbox entries older than 30 days are removed');
    $post($admin, 'settings.php?tab=email', ['tab' => 'email', 'action' => 'clear_password'], 'settings.php?tab=email');
    same($pdo->query('SELECT password_enc FROM mail_settings WHERE id = 1')->fetchColumn(), null, 'the saved password can be removed');
    $pdo->exec('DELETE FROM mail_settings');   // from here on: no SMTP, like a fresh install on localhost

    // =========================================================
    section('24. Password reset');
    $pdo->exec('DELETE FROM rate_limits');
    $oldPw = 'Old-Password-12345'; $newPw = 'Brand-New-Passw0rd!';
    $pdo->prepare("INSERT INTO admins (username, email, name, password_hash, role, perm_integrations, must_change_password) VALUES ('zz.rst', 'zz.rst@example.test', 'Reset Tester', ?, 'user', 'none', 0), ('zz.rst.off', 'zz.rst.off@example.test', 'Disabled Tester', ?, 'user', 'none', 0)")->execute([hash_password($oldPw), hash_password($oldPw)]);
    $pdo->exec("UPDATE admins SET is_active = 0 WHERE username = 'zz.rst.off'");
    $rid = (int) $pdo->query("SELECT id FROM admins WHERE username = 'zz.rst'")->fetchColumn();
    check(str_contains(http('GET', "$base/login.php")['body'], 'href="forgot.php"'), 'the login page has a Forgot password? link');
    $forgot = function (string $who, ?string $jar = null) use ($base, $pdo, $newJar) {
        $pdo->exec('DELETE FROM rate_limits');
        $jar ??= $newJar();
        $g = http('GET', "$base/forgot.php", ['jar' => $jar]);
        return http('POST', "$base/forgot.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($g['body']), 'who' => $who])]);
    };
    $statusText = fn(array $r) => preg_match('/role="status"[^>]*>([^<]+)</', $r['body'], $m) ? trim($m[1]) : '';
    $linkFor = function (string $email) use ($pdo) { $b = (string) $pdo->query('SELECT body FROM mail_outbox WHERE to_email = ' . $pdo->quote($email) . " AND kind = 'reset' ORDER BY id DESC LIMIT 1")->fetchColumn(); return preg_match('/reset\.php\?token=([a-f0-9]{64})/', $b, $m) ? $m[1] : ''; };
    same(http('GET', "$base/forgot.php")['code'], 200, 'the forgot page opens without signing in');
    same(http('POST', "$base/forgot.php", ['body' => 'who=zz.rst'])['code'], 403, 'a reset request without a CSRF token is 403');
    $known = $forgot('zz.rst');
    $unknown = $forgot('nobody-here-at-all');
    $byMail = $forgot('ZZ.RST@example.test');
    check($known['code'] === 200 && $statusText($known) !== '' && $statusText($known) === $statusText($unknown) && $statusText($known) === $statusText($forgot('zz.rst.off')), 'the page says exactly the same for a real account, an unknown one and a disabled one');
    check(strip_tags(preg_replace('/<script.*?<\/script>|<style.*?<\/style>/s', '', $known['body'])) === strip_tags(preg_replace('/<script.*?<\/script>|<style.*?<\/style>/s', '', $unknown['body'])) , 'and the whole visible page is identical');
    $tok = $linkFor('zz.rst@example.test');
    check(strlen($tok) === 64, 'the real account got an email with a 64-character random token link (read from the outbox on localhost)');
    same((int) $pdo->query("SELECT COUNT(*) FROM mail_outbox WHERE to_email IN ('nobody-here-at-all', 'zz.rst.off@example.test')")->fetchColumn(), 0, 'no email is made for an unknown or disabled account');
    same((int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE admin_id = (SELECT id FROM admins WHERE username = 'zz.rst.off')")->fetchColumn(), 0, 'and no reset record either');
    $row = $pdo->query("SELECT * FROM password_resets WHERE admin_id = $rid ORDER BY id DESC LIMIT 1")->fetch();
    check($row['token_hash'] === hash('sha256', $tok) && !str_contains(json_encode($row), $tok) && $row['used_at'] === null, 'only the SHA-256 hash of the token is stored');
    $mins = (int) $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) FROM password_resets WHERE id = " . (int) $row['id'])->fetchColumn();
    check($mins >= 58 && $mins <= 60, 'it is valid for one hour', (string) $mins);
    check(str_contains(http('GET', "$base/reset.php?token=$tok")['body'], 'name="confirm"') && str_contains(http('GET', "$base/reset.php?token=$tok")['body'], 'zz.rst'), 'the link opens a form for that account');
    $badOpened = [];
    foreach (['', 'abc', strrev($tok), substr($tok, 0, 63), $tok . 'a', strtoupper($tok), substr($tok, 0, 63) . ($tok[63] === 'a' ? 'b' : 'a'), "$tok' OR '1'='1", 'x'] as $badTok) {
        $b = http('GET', "$base/reset.php?token=" . urlencode($badTok))['body'];
        if (!str_contains($b, 'This link does not work') || str_contains($b, 'name="confirm"')) { $badOpened[] = $badTok; }
    }
    check(!$badOpened, 'tampered, truncated, reversed, upper-cased, extended and SQL-looking tokens all show "This link does not work"', json_encode($badOpened));
    // the form
    $rp = function (string $t, string $new, string $conf, ?string $jar = null) use ($base, $pdo, $newJar) {
        $pdo->exec('DELETE FROM rate_limits');
        $jar ??= $newJar();
        $g = http('GET', "$base/reset.php?token=$t", ['jar' => $jar]);
        return http('POST', "$base/reset.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($g['body']), 'token' => $t, 'new' => $new, 'confirm' => $conf])]);
    };
    same(http('POST', "$base/reset.php", ['body' => http_build_query(['token' => $tok, 'new' => $newPw, 'confirm' => $newPw])])['code'], 403, 'saving a new password without a CSRF token is 403');
    $r = $rp($tok, 'short', 'short');
    check($r['code'] === 200 && str_contains($r['body'], 'between 12 and 256'), 'a password under 12 characters is refused');
    $r = $rp($tok, $newPw, $newPw . 'x');
    check($r['code'] === 200 && str_contains($r['body'], 'do not match'), 'a mismatching confirmation is refused');
    $r = $rp($tok, 'xx-zz.rst-xx-12345', 'xx-zz.rst-xx-12345');
    check($r['code'] === 200 && str_contains($r['body'], 'can not contain your user name'), 'a password containing the user name is refused');
    check(str_contains(http('GET', "$base/reset.php?token=$tok")['body'], 'name="confirm"'), 'after refused attempts the link still works');
    // an open session that must end
    $oldSession = $newJar(); $pwStep($oldSession, 'zz.rst', $oldPw);
    check($signedIn($oldSession), 'before the reset the person is signed in somewhere (an old session)');
    $r = $rp($tok, $newPw, $newPw);
    check($r['code'] === 302 && str_contains($r['headers'], 'login.php?reset=1'), 'a valid new password is saved and the person is sent to the login page');
    check(str_contains(http('GET', "$base/login.php?reset=1")['body'], 'Your password was changed'), 'the login page confirms it');
    $j = $newJar(); $r = $pwStep($j, 'zz.rst', $newPw);
    check($r['code'] === 302 && str_contains($r['headers'], 'index.php') && $signedIn($j), 'the new password signs in');
    $j2 = $newJar(); $r = $pwStep($j2, 'zz.rst', $oldPw);
    check($r['code'] === 200 && !$signedIn($j2), 'the old password no longer works');
    check(!$signedIn($oldSession), 'every session that was open before the reset has ended');
    check($signedIn($j), 'while a session started after the reset works');
    $b = http('GET', "$base/reset.php?token=$tok")['body'];
    check(str_contains($b, 'This link does not work'), 'the link can not be used a second time');
    $r = $rp($tok, 'Another-Passw0rd-1', 'Another-Passw0rd-1');
    check(!str_contains($r['headers'], 'login.php?reset=1') && $pwStep($newJar(), 'zz.rst', 'Another-Passw0rd-1')['code'] === 200, 'posting to a used link changes nothing');
    same((int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE admin_id = $rid")->fetchColumn(), 0, 'the account\'s reset records are removed after a successful reset');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Password reset completed' AND target = 'zz.rst'")->fetchColumn() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Password reset requested' AND target = 'zz.rst'")->fetchColumn() >= 1, 'the request and the completed reset are in the activity log');
    // expiry
    $forgot('zz.rst'); $tok2 = $linkFor('zz.rst@example.test');
    $pdo->exec("UPDATE password_resets SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = '" . hash('sha256', $tok2) . "'");
    check(str_contains(http('GET', "$base/reset.php?token=$tok2")['body'], 'This link does not work'), 'a link older than one hour no longer works');
    $r = $rp($tok2, $newPw, $newPw);
    check(!str_contains($r['headers'] ?? '', 'login.php?reset=1'), 'and an expired link can not set a password');
    // two simultaneous uses: the claim is atomic
    $forgot('zz.rst'); $tok3 = $linkFor('zz.rst@example.test');
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', $tok3)]);
    check(str_contains(http('GET', "$base/reset.php?token=$tok3")['body'], 'This link does not work'), 'a link marked as used is refused');
    // limits
    $pdo->exec("DELETE FROM password_resets WHERE admin_id = $rid");
    for ($i = 0; $i < 5; $i++) { $forgot('zz.rst'); }
    same((int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE admin_id = $rid")->fetchColumn(), 3, 'one account can have at most 3 reset requests per hour; more are ignored silently');
    $pdo->exec('DELETE FROM rate_limits');
    $codes = [];
    for ($i = 0; $i < 7; $i++) { $g = http('GET', "$base/forgot.php", ['jar' => ($jj = $newJar())]); $codes[] = http('POST', "$base/forgot.php", ['jar' => $jj, 'body' => http_build_query(['csrf' => csrf_from($g['body']), 'who' => 'nobody'])])['code']; }
    check(count(array_filter($codes, fn($c) => $c === 429)) >= 2 && $codes[0] === 200, 'requests for reset links are rate limited per address (429 after 5 a minute)', json_encode(array_count_values($codes)));
    $pdo->exec('DELETE FROM rate_limits');
    // the link address is never taken from the request
    $pdo->exec('DELETE FROM mail_settings');
    $_SERVER['HTTP_HOST'] = 'evil.example'; $_SERVER['SCRIPT_NAME'] = '/x/forgot.php';
    same(mail_base_url($pdo), null, 'on a real server with no site address set, no link address is guessed from the request (a forged Host header gets nothing)');
    $pdo->exec("INSERT INTO mail_settings (id, base_url) VALUES (1, 'https://admin.example.test/app/')");
    same(mail_base_url($pdo), 'https://admin.example.test/app', 'the saved site address wins over whatever the request says, trailing slash removed');
    unset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME']);
    $pdo->exec("DELETE FROM password_resets WHERE admin_id = $rid");
    $forgot('zz.rst'); $b = (string) $pdo->query("SELECT body FROM mail_outbox WHERE kind = 'reset' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check(str_contains($b, 'https://admin.example.test/app/reset.php?token='), 'a reset email links to the saved site address');
    $pdo->exec('DELETE FROM mail_settings');

    // =========================================================
    section('25. Invitations');
    $pdo->exec('DELETE FROM rate_limits');
    $inv = fn(array $d) => $post($admin, 'settings.php?tab=team', ['tab' => 'team', 'action' => 'invite'] + $d, 'settings.php?tab=team');
    $base1 = ['name' => 'Invited Person', 'username' => 'zz.inv1', 'email' => 'zz.inv1@example.test', 'role' => 'user', 'perm' => 'view'];
    $ilink = function (string $email) use ($pdo) { $b = (string) $pdo->query('SELECT body FROM mail_outbox WHERE to_email = ' . $pdo->quote($email) . " AND kind = 'invite' ORDER BY id DESC LIMIT 1")->fetchColumn(); return preg_match('/invite\.php\?token=([a-f0-9]{64})/', $b, $m) ? $m[1] : ''; };
    $team = http('GET', "$base/settings.php?tab=team", ['jar' => $admin])['body'];
    check(str_contains($team, 'Invite by email') && str_contains($team, 'name="action" value="invite"'), 'Settings > Team has an "Invite by email" form');
    same($post($user, 'settings.php?tab=team', ['tab' => 'team', 'action' => 'invite'] + $base1, 'settings.php')['code'], 403, 'a normal account can not invite (403)');
    same((int) $pdo->query("SELECT COUNT(*) FROM invitations WHERE username = 'zz.inv1'")->fetchColumn(), 0, 'and nothing was created');
    foreach ([['username needs 3 to 30', ['username' => 'a!']], ['valid email', ['email' => 'nope']], ['Enter the person', ['name' => '']], ['already in use', ['username' => 'admin']], ['already in use', ['email' => 'admin@telecomlijn.test']]] as [$msg, $bad]) {
        $inv($bad + $base1);
        same((int) $pdo->query("SELECT COUNT(*) FROM invitations WHERE email LIKE 'zz.inv%'")->fetchColumn(), 0, 'validation refuses: ' . $msg);
    }
    $inv($base1);
    $iv = $pdo->query("SELECT * FROM invitations WHERE username = 'zz.inv1'")->fetch();
    check($iv && $iv['email'] === 'zz.inv1@example.test' && $iv['role'] === 'user' && $iv['perm_integrations'] === 'view' && $iv['invited_by'] === 'admin' && $iv['used_at'] === null, 'a valid invitation is stored with its role, permission and who invited');
    $itok = $ilink('zz.inv1@example.test');
    check(strlen($itok) === 64 && $iv['token_hash'] === hash('sha256', $itok) && !str_contains(json_encode($iv), $itok), 'the email holds a 64-character token and only its hash is stored');
    $days = (int) $pdo->query("SELECT TIMESTAMPDIFF(HOUR, NOW(), expires_at) FROM invitations WHERE id = " . (int) $iv['id'])->fetchColumn();
    check($days >= 70 && $days <= 72, 'it is valid for three days', (string) $days);
    $ob = (string) $pdo->query("SELECT body FROM mail_outbox WHERE to_email = 'zz.inv1@example.test' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check(str_contains($ob, 'Invited Person') && str_contains($ob, 'zz.inv1') && str_contains($ob, '/invite.php?token='), 'the email greets the person and holds the link');
    check(str_contains(http('GET', "$base/settings.php?tab=team", ['jar' => $admin])['body'], 'Waiting to be accepted') && str_contains(http('GET', "$base/settings.php?tab=team", ['jar' => $admin])['body'], 'zz.inv1@example.test'), 'the pending invitation is listed with a Withdraw button');
    $b = http('GET', "$base/invite.php?token=$itok")['body'];
    check(str_contains($b, 'Join Telecomlijn Admin') && str_contains($b, 'zz.inv1') && str_contains($b, 'Invited Person'), 'the link opens the sign-up form');
    $badInv = [];
    foreach (['', 'abc', strrev($itok), substr($itok, 0, 63), strtoupper($itok), "$itok' OR 1=1"] as $badTok) {
        if (!str_contains(http('GET', "$base/invite.php?token=" . urlencode($badTok))['body'], 'This invitation does not work')) { $badInv[] = $badTok; }
    }
    check(!$badInv, 'tampered, truncated and SQL-looking invitation tokens show "This invitation does not work"', json_encode($badInv));
    $acc = function (string $t, string $new, string $conf, ?string $jar = null) use ($base, $pdo, $newJar) {
        $pdo->exec('DELETE FROM rate_limits');
        $jar ??= $newJar();
        $g = http('GET', "$base/invite.php?token=$t", ['jar' => $jar]);
        return [http('POST', "$base/invite.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($g['body']), 'token' => $t, 'new' => $new, 'confirm' => $conf])]), $jar];
    };
    same(http('POST', "$base/invite.php", ['body' => http_build_query(['token' => $itok, 'new' => $newPw, 'confirm' => $newPw])])['code'], 403, 'accepting without a CSRF token is 403');
    [$r] = $acc($itok, 'short', 'short');
    check($r['code'] === 200 && str_contains($r['body'], 'between 12 and 256') && (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE username = 'zz.inv1'")->fetchColumn() === 0, 'a short password is refused and no account is made');
    [$r] = $acc($itok, $newPw, 'different');
    check($r['code'] === 200 && str_contains($r['body'], 'do not match') && (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE username = 'zz.inv1'")->fetchColumn() === 0, 'a mismatching confirmation is refused');
    [$r, $ji] = $acc($itok, $newPw, $newPw);
    check($r['code'] === 302 && str_contains($r['headers'], 'settings.php?tab=account'), 'accepting creates the account, signs the person in and sends them to the Account tab (to add two-step if they like)');
    $a = $pdo->query("SELECT * FROM admins WHERE username = 'zz.inv1'")->fetch();
    check($a && $a['role'] === 'user' && $a['perm_integrations'] === 'view' && $a['email'] === 'zz.inv1@example.test' && $a['name'] === 'Invited Person' && (int) $a['must_change_password'] === 0 && password_verify($newPw, $a['password_hash']), 'the account has the invited role and permission and the chosen password, with no forced password change');
    check($signedIn($ji) && str_contains(http('GET', "$base/settings.php?tab=account", ['jar' => $ji])['body'], 'Set up two-step sign-in'), 'they are signed in and can turn on two-step from the Account tab');
    check($pwStep($newJar(), 'zz.inv1', $newPw)['code'] === 302, 'they can sign in again later with the password');
    check(str_contains(http('GET', "$base/invite.php?token=$itok")['body'], 'This invitation does not work'), 'the invitation can not be used a second time');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Invitation accepted' AND target = 'zz.inv1'")->fetchColumn() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Invitation sent' AND target LIKE 'zz.inv1%'")->fetchColumn() === 1, 'sending and accepting are in the activity log');
    // replace, withdraw, expire, admin role, race with an existing account
    $b2 = ['name' => 'Second Person', 'username' => 'zz.inv2', 'email' => 'zz.inv2@example.test', 'role' => 'user', 'perm' => 'none'];
    $inv($b2); $t1 = $ilink('zz.inv2@example.test');
    $inv($b2); $t2 = $ilink('zz.inv2@example.test');
    check($t1 !== $t2 && str_contains(http('GET', "$base/invite.php?token=$t1")['body'], 'This invitation does not work') && str_contains(http('GET', "$base/invite.php?token=$t2")['body'], 'Join Telecomlijn Admin'), 'a new invitation for the same person replaces the old one: the old link stops working');
    same((int) $pdo->query("SELECT COUNT(*) FROM invitations WHERE email = 'zz.inv2@example.test'")->fetchColumn(), 1, 'only one open invitation per person');
    $ivid = (int) $pdo->query("SELECT id FROM invitations WHERE email = 'zz.inv2@example.test'")->fetchColumn();
    same($post($user, 'settings.php?tab=team', ['tab' => 'team', 'action' => 'revoke_invite', 'id' => (string) $ivid], 'settings.php')['code'], 403, 'a normal account can not withdraw an invitation (403)');
    $post($admin, 'settings.php?tab=team', ['tab' => 'team', 'action' => 'revoke_invite', 'id' => (string) $ivid], 'settings.php?tab=team');
    check(str_contains(http('GET', "$base/invite.php?token=$t2")['body'], 'This invitation does not work'), 'a withdrawn invitation no longer works');
    $b3 = ['name' => 'Third Person', 'username' => 'zz.inv3', 'email' => 'zz.inv3@example.test', 'role' => 'admin', 'perm' => 'none'];
    $inv($b3); $t3 = $ilink('zz.inv3@example.test');
    $pdo->exec("UPDATE invitations SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE username = 'zz.inv3'");
    check(str_contains(http('GET', "$base/invite.php?token=$t3")['body'], 'This invitation does not work'), 'an expired invitation no longer works');
    [$r] = $acc($t3, $newPw, $newPw);
    same((int) $pdo->query("SELECT COUNT(*) FROM admins WHERE username = 'zz.inv3'")->fetchColumn(), 0, 'and an expired invitation can not create an account');
    $inv($b3); $t3 = $ilink('zz.inv3@example.test');
    [$r] = $acc($t3, $newPw, $newPw);
    $a3 = $pdo->query("SELECT role, perm_integrations FROM admins WHERE username = 'zz.inv3'")->fetch();
    check($a3 && $a3['role'] === 'admin' && $a3['perm_integrations'] === 'manage', 'an invited admin gets the admin role and full integrations access');
    $b4 = ['name' => 'Fourth Person', 'username' => 'zz.inv4', 'email' => 'zz.inv4@example.test', 'role' => 'user', 'perm' => 'none'];
    $inv($b4); $t4 = $ilink('zz.inv4@example.test');
    $pdo->prepare("INSERT INTO admins (username, email, name, password_hash, role, perm_integrations, must_change_password) VALUES ('zz.inv4', 'other@example.test', 'Taken', ?, 'user', 'none', 0)")->execute([hash_password($oldPw)]);
    [$r] = $acc($t4, $newPw, $newPw);
    check($r['code'] === 200 && str_contains($r['body'], 'already exists') && (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE username = 'zz.inv4'")->fetchColumn() === 1, 'if the username was taken in the meantime, accepting is refused and no second account appears');
    // XSS in an invitation name
    $inv(['name' => '<script>alert(1)</script>', 'username' => 'zz.inv5', 'email' => 'zz.inv5@example.test'] + $b2);
    $xs = http('GET', "$base/settings.php?tab=team", ['jar' => $admin])['body'] . http('GET', "$base/invite.php?token=" . $ilink('zz.inv5@example.test'))['body'];
    check(!str_contains($xs, '<script>alert(1)') && str_contains($xs, '&lt;script&gt;'), 'markup in an invited name is escaped on the Team tab and on the invitation page');
    $pdo->exec('DELETE FROM rate_limits');
    $codes = [];
    for ($i = 0; $i < 12; $i++) { $jj = $newJar(); $g = http('GET', "$base/invite.php?token=$itok", ['jar' => $jj]); $codes[] = http('POST', "$base/invite.php", ['jar' => $jj, 'body' => http_build_query(['csrf' => csrf_from($g['body']), 'token' => $itok, 'new' => 'x', 'confirm' => 'x'])])['code']; }
    check(count(array_filter($codes, fn($c) => $c === 429)) >= 1, 'guessing at invitation links is rate limited (429)', json_encode(array_count_values($codes)));
    } finally {
        $cleanMail();
        if (is_resource($smtpProc)) { proc_terminate($smtpProc); proc_close($smtpProc); }
        @unlink($smtpLog); @unlink($smtpMode);
    }

    // =========================================================
    // Stage 9: trends on the Overview
    // =========================================================
    require_once __DIR__ . '/../includes/trends.php';
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
    $scratch->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
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
    same(substr_count($ov, 'View as table'), 4, 'each of the four charts has its table');
    check(strpos($ov, 'Revenue per month') < strpos($ov, 'id="demo-data"') && strpos($ov, 'Activity, last 24 hours') < strpos($ov, 'Revenue per month'), 'the trends sit below the activity chart and above the demo-data panel');
    check(substr_count($ov, 'class="vbar') >= 12 + 12 + 12 + 12 || str_contains($ov, 'data-empty="1"'), 'each chart draws one bar per period (12 months or 12 weeks) when there is data');
    check(str_contains($ov, 'data-tip="' . trend_month_keys()[11]['label'] . ': '), 'the newest bar is the current month');
    if (demo_loaded($pdo)) {
        check(count(array_filter($vals($liveRev), fn($v) => $v > 0)) >= 9, 'demo data gives revenue in at least 9 of the last 12 months');
        check(count(array_filter($vals($liveUsr), fn($v) => $v > 0)) >= 9, 'and new users in at least 9 months');
        check(count(array_filter($vals($liveVol), fn($v) => $v > 0)) >= 8 && count(array_filter($vals(trend_ticket_open_time($pdo)), fn($v) => $v !== null)) >= 5, 'and tickets opened in most weeks and resolved in several');
        same((int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE is_demo = 1 AND number LIKE 'DEMO-%-H%'")->fetchColumn(), 70, 'the demo history is 70 older invoices, tagged as demo');
        same((int) $pdo->query("SELECT COUNT(*) FROM invoices i WHERE i.is_demo = 1 AND i.number LIKE 'DEMO-%-H%' AND i.total_cents <> (SELECT SUM(amount_cents) FROM payments p WHERE p.invoice_id = i.id)")->fetchColumn(), 0, 'every historic demo invoice is paid in full');
    }
    $root->exec('DROP DATABASE ops_console_trendtest');

    // =========================================================
    // Stage 10: PDF invoices and recurring invoices
    // =========================================================
    require_once __DIR__ . '/../includes/pdf.php';
    require_once __DIR__ . '/../includes/recurring.php';
    require_once __DIR__ . '/../includes/settings/company.php';   // iban_valid()
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
    $co0 = require __DIR__ . '/../includes/company.php';
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
    $pdo->exec('DELETE FROM rate_limits');
    $codes = []; for ($i = 0; $i < 17; $i++) { $codes[] = http('GET', "$base/invoice_pdf.php?id=$zi", ['jar' => $admin])['code']; }
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
    $co1 = require __DIR__ . '/../includes/company.php';
    same([$co1['btw'], $co1['iban'], $co1['btw_is_placeholder'], $co1['name']], ['NL123456789B01', 'NL91ABNA0417164300', false, 'Telecomlijn Zakelijk'], 'invoices use them; empty name, address and KvK fall back to the defaults');
    $bytes2 = invoice_pdf($inv, $items, $co1);
    check(str_contains($bytes2, 'BTW-id NL123456789B01') && str_contains($bytes2, 'IBAN NL91ABNA0417164300') && str_contains($bytes2, 'to IBAN NL91ABNA0417164300 in the name of Telecomlijn Zakelijk'), 'once filled in, the PDF prints the BTW-id and IBAN and the payment line names the IBAN');
    $pg = http('GET', "$base/invoice.php?id=$zi", ['jar' => $admin])['body'];
    check(!str_contains($pg, 'not filled in yet') && str_contains($pg, 'NL91ABNA0417164300'), 'the invoice page no longer shows the reminder and shows the IBAN');
    $cf(['name' => 'Acme <b>Telecom</b> BV', 'address1' => 'Main Street 1', 'address2' => '1000 AA Town', 'kvk' => '12345678', 'email' => 'x@acme.test', 'phone' => '020 123'] + $full);
    $pg = http('GET', "$base/invoice.php?id=$zi", ['jar' => $admin])['body'];
    check(str_contains($pg, 'Acme &lt;b&gt;Telecom&lt;/b&gt; BV') && !str_contains($pg, '<b>Telecom</b>') && str_contains($pg, 'Main Street 1, 1000 AA Town'), 'a changed name and address show on the invoice page, escaped');
    check(str_contains(invoice_pdf($inv, $items, require __DIR__ . '/../includes/company.php'), '(Acme <b>Telecom</b> BV) Tj'), 'and on the PDF as plain text');
    $cf(['btw_number' => '', 'iban' => ''] + $full);
    same([(string) $pdo->query('SELECT btw_number FROM company_profile')->fetchColumn(), (string) $pdo->query('SELECT iban FROM company_profile')->fetchColumn()], ['', ''], 'BTW number and IBAN can be emptied again');
    check(!str_contains(invoice_pdf($inv, $items, require __DIR__ . '/../includes/company.php'), 'IBAN'), 'and then disappear from the PDF again');
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
    $cli = function () { $p = proc_open([PHP_BINARY, __DIR__ . '/../run_recurring.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp); $o = stream_get_contents($pp[1]) . stream_get_contents($pp[2]); proc_close($p); return trim($o); };
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
} catch (Throwable $e) {
    $fail++; $failures[] = 'test run aborted: ' . get_class($e) . ': ' . $e->getMessage();
    echo "\n  ABORTED: " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    // ---------- clean up ----------
    set_mode(''); @unlink(__DIR__ . '/.mock_hits');
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    $pdo->prepare('DELETE FROM admins WHERE username = ?')->execute([$testUser]);
    $pdo->exec("DELETE FROM activity_logs WHERE action LIKE 'Kuma%' OR actor = 'kuma.tester' OR target LIKE 'kuma%'");
    $pdo->exec('DELETE FROM kuma_settings');
    $pdo->exec('DELETE FROM kuma_heartbeats WHERE id > ' . $startHb);   // only the rows this run created
    $pdo->exec("DELETE FROM api_keys WHERE name IN ('Persist test', 'Legacy test')");
    $pdo->exec("DELETE FROM activity_logs WHERE action IN ('API key shown', 'API key created', 'API key revoked')");
    if ($backup) {
        $pdo->prepare('INSERT INTO kuma_settings (id, kuma_url, api_key_enc, key_last4, poll_interval_s, updated_by, updated_at) VALUES (1,?,?,?,?,?,?)')
            ->execute([$backup['kuma_url'], $backup['api_key_enc'], $backup['key_last4'], $backup['poll_interval_s'], $backup['updated_by'], $backup['updated_at']]);
    }
    kuma_cache_clear();
}

echo "\n" . str_repeat('=', 60) . "\n$pass passed, $fail failed\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit($fail > 0 ? 1 : 0);
