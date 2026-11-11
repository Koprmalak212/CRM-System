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
$startedAt = microtime(true);
$__report = ['sections' => []];   // every check by section, written to storage/test_report.json at the end (shown under Settings > Tests > Automated checks)
const TESTS_DIR = __DIR__;   // the staged parts in tests/parts/ use this, because their own __DIR__ is one level deeper

define('API_REQUEST', true);                 // no session or cookies for the in-process part
$_SERVER['REMOTE_ADDR'] = '198.51.100.77';   // its own address, so it never shares a rate-limit bucket with the HTTP tests
require __DIR__ . '/../includes/core/bootstrap.php';

/** Every PHP file of the program (pages, includes, api, bin) for the source-scanning tests; the tests, database dumps and assets are left out. */
function app_source_files(): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) realpath(__DIR__ . '/..'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if ($f->getExtension() === 'php' && !preg_match('#/(tests|database|assets|storage|node_modules|\.git|\.impeccable)/#', $p)) { $out[] = $p; }
    }
    sort($out);
    return $out;
}

require __DIR__ . '/../includes/modules/kuma.php';
require __DIR__ . '/../includes/modules/notes.php';
require __DIR__ . '/../includes/modules/demo.php';   // no longer loaded by bootstrap
require __DIR__ . '/../includes/modules/csv.php';
require __DIR__ . '/../includes/modules/notifications.php';
require __DIR__ . '/../includes/modules/twofactor.php';
require __DIR__ . '/../includes/modules/mailer.php';

$base = rtrim((string) (getenv('TEST_BASE') ?: 'http://localhost/Websites/test'), '/');
$basePath = rtrim((string) parse_url($base, PHP_URL_PATH), '/');   // /Websites/test: redirects now carry the full path
$adminUser = (string) (getenv('TEST_ADMIN_USER') ?: 'admin');
$adminPass = (string) (getenv('TEST_ADMIN_PASS') ?: 'admin');
$mock = 'http://127.0.0.1:3199';
$pdo = db();

// ---------- tiny harness ----------
$pass = 0; $fail = 0; $failures = [];
function check(bool $cond, string $name, string $detail = ''): void
{
    global $pass, $fail, $failures, $__report;
    if ($cond) { $pass++; echo "  ok    $name\n"; }
    else { $fail++; $failures[] = $name; echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
    if (!$__report['sections']) { $__report['sections'][] = ['title' => 'Before the first section', 'checks' => []]; }
    $__report['sections'][count($__report['sections']) - 1]['checks'][] = $cond ? [mb_substr($name, 0, 300), 1] : [mb_substr($name, 0, 300), 0, mb_substr($detail, 0, 300)];
}
function fresh_window(): void { $r = time() % 60; if ($r > 35) { sleep(61 - $r); } }   // the limiter counts per calendar minute: start a burst early in one
function section(string $t): void { global $__report; echo "\n== $t\n"; $__report['sections'][] = ['title' => mb_substr($t, 0, 200), 'checks' => []]; }
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
    $p = http('GET', "$base/auth/login.php", ['jar' => $jar]);
    http('POST', "$base/auth/login.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($p['body']), 'username' => $user, 'password' => $pass])]);
    return $jar;
}
function token(string $base, string $jar): string { return csrf_from(http('GET', "$base/index.php", ['jar' => $jar])['body']); }
function api(string $base, string $jar, string $method, array $data = [], ?string $csrf = null): array
{
    $csrf ??= token($base, $jar);
    $hdr = ['Accept: application/json', 'X-CSRF-Token: ' . $csrf];
    if (in_array($method, ['PUT', 'DELETE'], true)) { $hdr[] = 'Content-Type: application/json'; return http($method, "$base/api/kuma_api.php", ['jar' => $jar, 'headers' => $hdr, 'body' => json_encode($data)]); }
    return http($method, "$base/api/kuma_api.php", ['jar' => $jar, 'headers' => $hdr, 'body' => http_build_query($data)]);
}
function expire_cache(): void   // pretend the poll interval has passed, keeping the last good data
{
    $f = kuma_cache_path();
    if (is_file($f)) { $j = json_decode((string) file_get_contents($f), true); $j['attemptedAt'] = time() - 3600; file_put_contents($f, json_encode($j)); }
}
function mock_hits(string $mock): int { return (int) trim((string) @file_get_contents("$mock/hits")); }
function set_mode(string $m): void { $f = __DIR__ . '/.mock_mode'; if ($m === '') { @unlink($f); } else { file_put_contents($f, $m); } }

// ---------- back up settings, start the mock ----------
// The Kuma connection of this install is saved to a file before anything changes. A run that was killed or crashed (a fatal error skips the cleanup at
// the end) leaves that file behind: it is put back FIRST, so a run never takes test values for the real ones. See tests/kuma_guard.php.
require __DIR__ . '/kuma_guard.php';
$kumaBackupFile = dirname(__DIR__) . '/storage/test_kuma_backup.json';
if (kuma_guard_restore($pdo, $kumaBackupFile)) { echo "Put back the Kuma connection that an earlier run had left changed.\n"; }
$backup = kuma_guard_save($pdo, $kumaBackupFile);
register_shutdown_function(static function () use ($kumaBackupFile) { if (kuma_guard_restore(db(), $kumaBackupFile)) { fwrite(STDERR, "\nThe run ended early: the Kuma connection of this install was put back.\n"); } });   // runs after a fatal error, when the cleanup at the end does not
$fixtureIds = array_column(kuma_parse_metrics((string) file_get_contents(__DIR__ . '/fixtures/kuma_metrics.txt')), 'id');   // heartbeats of the test monitors that an earlier crashed run may have left
$pdo->exec("DELETE FROM kuma_heartbeats WHERE monitor_key IN ('" . implode("','", array_merge($fixtureIds, ['aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', 'cccccccccccccccc', 'dddddddddddddddd'])) . "')");
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
    $anon = http('GET', "$base/api/kuma_api.php", ['headers' => ['Accept: application/json']]);
    same($anon['code'], 401, 'signed-out GET api/kuma_api.php is 401');
    same(http('GET', "$base/monitoring/monitoring_uptime.php")['code'], 401, 'signed-out GET monitoring/monitoring_uptime.php is 401');

    $admin = login($base, $adminUser, $adminPass);
    check(str_contains(http('GET', "$base/index.php", ['jar' => $admin])['body'], 'Overview'), 'admin signed in (set TEST_ADMIN_PASS if you changed it)');
    // a normal user, created through the real Team form
    $pdo->prepare('DELETE FROM admins WHERE username = ?')->execute([$testUser]);
    $team = http('GET', "$base/settings/settings.php?tab=team", ['jar' => $admin]);
    $created = http('POST', "$base/settings/settings.php?tab=team", ['jar' => $admin, 'body' => http_build_query(['csrf' => csrf_from($team['body']), 'tab' => 'team', 'action' => 'add_user', 'username' => $testUser, 'name' => 'Kuma Tester', 'email' => 'kuma.tester@example.test', 'role' => 'user', 'perm' => 'manage'])]);
    $tmpPw = preg_match('/select-all">([^<]+)</', http('GET', "$base/settings/settings.php?tab=team", ['jar' => $admin])['body'], $m) ? html_entity_decode($m[1]) : '';
    $pdo->prepare('UPDATE admins SET must_change_password = 0 WHERE username = ?')->execute([$testUser]);
    $user = login($base, $testUser, $tmpPw);
    check(str_contains(http('GET', "$base/index.php", ['jar' => $user])['body'], 'Overview'), 'normal test user signed in');

    same(http('GET', "$base/api/kuma_api.php", ['jar' => $user])['code'], 403, 'normal user: GET api/kuma_api.php is 403');
    same(api($base, $user, 'PUT', ['kumaUrl' => "$mock", 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 30])['code'], 403, 'normal user: PUT is 403');
    same(api($base, $user, 'POST', ['action' => 'test', 'kumaUrl' => $mock, 'apiKey' => 'goodkey'])['code'], 403, 'normal user: test connection is 403');
    same(api($base, $user, 'DELETE')['code'], 403, 'normal user: DELETE is 403');
    same(http('GET', "$base/settings/settings.php?tab=kuma", ['jar' => $user])['code'], 403, 'normal user: the settings tab is 403 on the server');
    check(!str_contains(http('GET', "$base/settings/settings.php", ['jar' => $user])['body'], 'tab=kuma'), 'normal user: the tab is hidden from the menu');
    check(str_contains(http('GET', "$base/settings/settings.php", ['jar' => $admin])['body'], 'tab=kuma'), 'admin: the "Kuma API Key" tab is shown');
    same((int) $pdo->query('SELECT COUNT(*) FROM kuma_settings')->fetchColumn(), 0, 'nothing was saved by the forbidden requests');
    same(http('GET', "$base/monitoring/monitoring_uptime.php", ['jar' => $user])['json']['error'] ?? null, 'not_configured', 'normal user may read the monitoring feed (same auth as other dashboard routes)');

    $g = http('GET', "$base/api/kuma_api.php", ['jar' => $admin]);
    same($g['code'], 200, 'admin GET works');
    same($g['json'], ['kumaUrl' => '', 'pollIntervalSeconds' => 30, 'hasApiKey' => false, 'keyLast4' => null], 'GET before setup returns defaults');

    same(api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 30], 'wrong-token')['code'], 403, 'PUT with a wrong CSRF token is 403');
    same(http('DELETE', "$base/api/kuma_api.php", ['jar' => $admin])['code'], 403, 'DELETE without a CSRF token is 403');
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
    $g = http('GET', "$base/api/kuma_api.php", ['jar' => $admin]);
    same(array_keys($g['json']), ['kumaUrl', 'pollIntervalSeconds', 'hasApiKey', 'keyLast4'], 'GET exposes exactly four fields');
    check(!str_contains($s1['body'] . $g['body'], 'goodkey'), 'the API key is never in an API response');
    $page = http('GET', "$base/settings/settings.php?tab=kuma", ['jar' => $admin])['body'];
    check(!str_contains($page, 'goodkey') && str_contains($page, '••••••••dkey'), 'the settings page shows only a masked placeholder');
    $stored = (string) $pdo->query('SELECT api_key_enc FROM kuma_settings WHERE id = 1')->fetchColumn();
    check($stored !== '' && !str_contains($stored, 'goodkey') && secret_decrypt($stored) === 'goodkey', 'stored value is ciphertext that decrypts to the key');

    $s2 = api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => '', 'pollIntervalSeconds' => 60]);
    check($s2['code'] === 200 && $s2['json']['hasApiKey'] === true && $s2['json']['keyLast4'] === 'dkey' && $s2['json']['pollIntervalSeconds'] === 60, 'a blank key keeps the existing key (interval still updates)');
    same((string) $pdo->query('SELECT api_key_enc FROM kuma_settings WHERE id = 1')->fetchColumn(), $stored, 'the stored ciphertext is untouched by a blank key');
    $s3 = api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => 'goodkey-two', 'pollIntervalSeconds' => 60]);
    check($s3['json']['keyLast4'] === '-two' && secret_decrypt((string) $pdo->query('SELECT api_key_enc FROM kuma_settings WHERE id = 1')->fetchColumn()) === 'goodkey-two', 'typing a new key replaces it');
    // the normal HTML form behaves the same way
    $tok = csrf_from(http('GET', "$base/settings/settings.php?tab=kuma", ['jar' => $admin])['body']);
    http('POST', "$base/settings/settings.php?tab=kuma", ['jar' => $admin, 'body' => http_build_query(['csrf' => $tok, 'tab' => 'kuma', 'action' => 'save', 'kumaUrl' => $mock, 'apiKey' => '', 'pollIntervalSeconds' => '90'])]);
    same((int) $pdo->query('SELECT poll_interval_s FROM kuma_settings WHERE id = 1')->fetchColumn(), 90, 'settings form: blank key keeps the key, interval saved');
    $bad = http('POST', "$base/settings/settings.php?tab=kuma", ['jar' => $admin, 'body' => http_build_query(['csrf' => $tok, 'tab' => 'kuma', 'action' => 'save', 'kumaUrl' => 'nope', 'apiKey' => 'SHOULD-NOT-ECHO', 'pollIntervalSeconds' => '1'])]);
    $after = http('GET', "$base/settings/settings.php?tab=kuma", ['jar' => $admin])['body'];
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
    // the saved key is only ever sent to the saved address
    $pdo->exec('DELETE FROM rate_limits');   // the test action allows 10 calls a minute, and the cases above already used most of them
    @unlink(__DIR__ . '/.mock_hits');
    $r = $t(['kumaUrl' => $mock . '/', 'apiKey' => '']);
    check($r['ok'] === true && mock_hits($mock) === 1, 'a blank key still tests the saved address when it is written with a trailing slash');
    @unlink(__DIR__ . '/.mock_hits');
    $r = $t(['kumaUrl' => 'http://127.0.0.1:03199', 'apiKey' => '']);   // the same server, written differently
    check($r['ok'] === false && $r['result'] === 'no_key' && $r['message'] === 'Enter the API key to test.', 'a blank key with a different address is refused: "Enter the API key to test."', json_encode($r));
    same(mock_hits($mock), 0, 'and the saved key was not sent to that address');
    $r = $t(['kumaUrl' => 'http://127.0.0.2:3199', 'apiKey' => '']);
    same($r['result'], 'no_key', 'a blank key with another host is refused too');
    $r = $t(['kumaUrl' => 'http://127.0.0.1:03199', 'apiKey' => 'goodkey']);
    check($r['ok'] === true, 'a key typed in by the admin can be tested against any valid address');
    $r = $t(['kumaUrl' => 'http://169.254.169.254', 'apiKey' => 'goodkey']);
    same($r['result'], 'invalid_url', 'the cloud metadata address is refused');
    same($pdo->query('SELECT kuma_url, api_key_enc, key_last4, poll_interval_s FROM kuma_settings WHERE id = 1')->fetch(), $snapshot, 'testing never changes the saved settings');

    // =========================================================
    section('7. Monitoring endpoint over HTTP');
    api($base, $admin, 'PUT', ['kumaUrl' => $mock, 'apiKey' => 'goodkey', 'pollIntervalSeconds' => 30]);   // clears the cache
    @unlink(__DIR__ . '/.mock_hits');
    $u1 = http('GET', "$base/monitoring/monitoring_uptime.php", ['jar' => $admin]);
    check($u1['code'] === 200 && !isset($u1['json']['error']) && $u1['json']['summary'] === ['up' => 3, 'down' => 1, 'pending' => 1, 'maintenance' => 1, 'total' => 6], 'configured: summary and monitors returned');
    same($u1['json']['monitors'][0]['statusLabel'], 'down', 'down monitors are listed first');
    same(array_keys($u1['json']['monitors'][0]), ['id', 'name', 'type', 'url', 'status', 'statusLabel', 'responseTimeMs', 'certDaysRemaining', 'certValid', 'uptime24h', 'uptime30d', 'avgResponse24hMs', 'heartbeats'], 'each monitor has the documented fields plus id and history');
    check(!str_contains($u1['body'], 'goodkey') && !str_contains($u1['body'], 'Authorization'), 'the response never contains the key');
    for ($i = 0; $i < 4; $i++) { http('GET', "$base/monitoring/monitoring_uptime.php", ['jar' => $admin]); http('GET', "$base/monitoring/monitoring_uptime.php", ['jar' => $user]); }
    same(mock_hits($mock), 1, 'ten dashboard requests reached Kuma once');

    set_mode('down'); expire_cache();
    $u = http('GET', "$base/monitoring/monitoring_uptime.php", ['jar' => $admin])['json'];
    check(($u['error'] ?? '') === 'kuma_unreachable' && ($u['stale'] ?? false) === true && $u['summary']['total'] === 6, 'Kuma returns 500: kuma_unreachable with stale data');
    check(!str_contains(json_encode($u), 'secret stack trace'), 'upstream details are never leaked');
    set_mode('unauth'); expire_cache();
    $u = http('GET', "$base/monitoring/monitoring_uptime.php", ['jar' => $admin])['json'];
    check(($u['error'] ?? '') === 'kuma_unauthorized' && ($u['stale'] ?? false) === true, 'Kuma returns 401: kuma_unauthorized with stale data');
    set_mode(''); expire_cache();
    $u = http('GET', "$base/monitoring/monitoring_uptime.php", ['jar' => $admin])['json'];
    check(!isset($u['error']) && $u['summary']['total'] === 6, 'Kuma recovers: the error clears');

    // poll interval is honoured: a fresh cache is reused, then refreshed after expiry
    @unlink(__DIR__ . '/.mock_hits'); http('GET', "$base/monitoring/monitoring_uptime.php", ['jar' => $admin]);
    same(mock_hits($mock), 0, 'inside the poll interval nothing is fetched');
    expire_cache(); http('GET', "$base/monitoring/monitoring_uptime.php", ['jar' => $admin]);
    same(mock_hits($mock), 1, 'after the interval it is fetched once');

    $d = api($base, $admin, 'DELETE');
    check($d['code'] === 200 && $d['json']['hasApiKey'] === false && $d['json']['keyLast4'] === null && $d['json']['kumaUrl'] === $mock, 'DELETE removes the key and keeps the URL');
    same((int) $pdo->query('SELECT api_key_enc IS NULL FROM kuma_settings WHERE id = 1')->fetchColumn(), 1, 'the stored key is gone from the database');
    same(http('GET', "$base/monitoring/monitoring_uptime.php", ['jar' => $admin])['json'], ['error' => 'not_configured'], 'after removing the key: not_configured');
    same(http('POST', "$base/monitoring/monitoring_uptime.php", ['jar' => $admin, 'body' => 'x=1'])['code'], 405, 'the monitoring endpoint is read-only (POST is 405)');
    check(str_contains(http('GET', "$base/monitoring/uptime.php", ['jar' => $admin])['body'], 'monitoring/monitoring_uptime.php'), 'the Uptime page loads and polls only the dashboard backend');
    check(!str_contains(http('GET', "$base/monitoring/uptime.php", ['jar' => $admin])['body'], ':3199'), 'the browser page never contains the Kuma address');

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

    same(http('GET', "$base/monitoring/monitoring_uptime.php?monitor=$synthetic")['code'], 401, 'signed out: the detail endpoint is 401');
    same(http('GET', "$base/monitoring/monitoring_uptime.php?events=1")['code'], 401, 'signed out: the events endpoint is 401');
    $hd = http('GET', "$base/monitoring/monitoring_uptime.php?monitor=$synthetic", ['jar' => $admin])['json'];
    same(array_keys($hd), ['id', 'heartbeats', 'series', 'events'], 'detail endpoint returns id, heartbeats, series and events');
    same(http('GET', "$base/monitoring/monitoring_uptime.php?monitor=" . urlencode("x' OR 1=1 --"), ['jar' => $admin])['json'], ['error' => 'not_found'], 'a hostile monitor id is answered with not_found');
    $ev = http('GET', "$base/monitoring/monitoring_uptime.php?events=1", ['jar' => $user])['json'];
    check(isset($ev['events']) && is_array($ev['events']), 'a normal user can read the events feed');
    check(!str_contains(json_encode($hd) . json_encode($ev), 'goodkey'), 'history responses never contain the key');

    // =========================================================
    section('9. Saved health-API keys: show again, survive refresh');
    $pdo->exec("DELETE FROM api_keys WHERE name IN ('Persist test', 'Legacy test')");
    $mk = function (string $jar) use ($base) {
        $page = http('GET', "$base/settings/settings.php?tab=integrations", ['jar' => $jar]);
        http('POST', "$base/settings/settings.php?tab=integrations", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($page['body']), 'tab' => 'integrations', 'action' => 'key_create', 'key_name' => 'Persist test'])]);
    };
    $reveal = function (string $jar, int $id, ?string $csrf = null) use ($base) {
        $csrf ??= token($base, $jar);
        return http('POST', "$base/api/api_key_reveal.php", ['jar' => $jar, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $csrf, 'Content-Type: application/x-www-form-urlencoded'], 'body' => 'id=' . $id]);
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
    $pageHtml = http('GET', "$base/settings/settings.php?tab=integrations", ['jar' => $admin])['body'];
    check(str_contains($pageHtml, $row['key_prefix']) && str_contains($pageHtml, 'Show key'), 'after a refresh the key is still listed with a Show key button');
    check(!str_contains($pageHtml, $plainKey), 'the full key is never written into the page');
    $admin2 = login($base, $adminUser, $adminPass);
    same($reveal($admin2, $kid)['json']['key'] ?? '', $plainKey, 'a brand new sign-in shows the very same key: nothing has to be recreated');
    same($reveal($admin, $kid)['json']['key'] ?? '', $plainKey, 'showing it twice gives the same key');
    check(str_contains((string) $pdo->query("SELECT GROUP_CONCAT(action) FROM activity_logs WHERE action = 'API key shown'")->fetchColumn(), 'API key shown')
        && !str_contains((string) $pdo->query("SELECT GROUP_CONCAT(CONCAT(target, actor)) FROM activity_logs WHERE action = 'API key shown'")->fetchColumn(), $plainKey), 'every reveal is logged, with the name and never the key');

    // who may show a key
    same(http('POST', "$base/api/api_key_reveal.php", ['headers' => ['X-CSRF-Token: x'], 'body' => "id=$kid"])['code'], 401, 'signed out: 401');
    same(http('GET', "$base/api/api_key_reveal.php?id=$kid", ['jar' => $admin])['code'], 405, 'GET is not allowed');
    same($reveal($admin, $kid, 'wrong-token')['code'], 403, 'a wrong CSRF token is 403');
    same($reveal($admin, 999999)['code'], 404, 'an unknown key is 404');
    $pdo->prepare('UPDATE admins SET perm_integrations = ? WHERE username = ?')->execute(['manage', $testUser]);
    same($reveal($user, $kid)['code'], 200, 'a normal user with Can manage may show keys');
    $pdo->prepare('UPDATE admins SET perm_integrations = ? WHERE username = ?')->execute(['view', $testUser]);
    same($reveal($user, $kid)['code'], 403, 'view-only users can not show keys');
    check(!str_contains(http('GET', "$base/settings/settings.php?tab=integrations", ['jar' => $user])['body'], 'class="key-show'), 'view-only users do not even see the button');
    $pdo->prepare('UPDATE admins SET perm_integrations = ? WHERE username = ?')->execute(['none', $testUser]);
    same($reveal($user, $kid)['code'], 403, 'users with no integrations access can not show keys');
    $pdo->prepare('UPDATE admins SET perm_integrations = ? WHERE username = ?')->execute(['manage', $testUser]);

    // keys created before they were saved, and revoked keys
    $pdo->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, key_enc, scope, created_by) VALUES ('Legacy test', 'tlk_legacy00', ?, NULL, 'health:read', 1)")->execute([hash('sha256', 'tlk_legacy')]);
    $lid = (int) $pdo->query("SELECT id FROM api_keys WHERE name = 'Legacy test'")->fetchColumn();
    same($reveal($admin, $lid)['code'], 404, 'an old hash-only key can not be shown');
    check(str_contains(http('GET', "$base/settings/settings.php?tab=integrations", ['jar' => $admin])['body'], 'Created before keys were saved'), 'the page explains why an old key can not be shown');
    $page = http('GET', "$base/settings/settings.php?tab=integrations", ['jar' => $admin]);
    http('POST', "$base/settings/settings.php?tab=integrations", ['jar' => $admin, 'body' => http_build_query(['csrf' => csrf_from($page['body']), 'tab' => 'integrations', 'action' => 'key_revoke', 'id' => $kid])]);
    same($reveal($admin, $kid)['code'], 404, 'a revoked key can not be shown');
    same(http('GET', "$base/api/health.php", ['headers' => ['Authorization: Bearer ' . $plainKey]])['code'], 401, 'and it stops working at once');
    $pdo->exec("DELETE FROM api_keys WHERE name IN ('Persist test', 'Legacy test')");

    require TESTS_DIR . '/parts/stage01_companies.php';
    require TESTS_DIR . '/parts/stage02_notes.php';
    require TESTS_DIR . '/parts/stage03_tickets.php';
    require TESTS_DIR . '/parts/stage04_search.php';
    require TESTS_DIR . '/parts/stage05_csv.php';
    require TESTS_DIR . '/parts/stage06_notifications.php';
    require TESTS_DIR . '/parts/stage07_twofactor.php';
    require TESTS_DIR . '/parts/stage08_email.php';
    require TESTS_DIR . '/parts/stage09_trends.php';
    require TESTS_DIR . '/parts/stage10_pdf_recurring.php';
    require TESTS_DIR . '/parts/stage11_theme.php';
    require TESTS_DIR . '/parts/stage12_phone_tables.php';
    require TESTS_DIR . '/parts/stage13_followups.php';
    require TESTS_DIR . '/parts/stage14_calendar.php';
    require TESTS_DIR . '/parts/stage15_structure.php';
    require TESTS_DIR . '/parts/stage16_hardening.php';
    require TESTS_DIR . '/parts/stage17_modules.php';
    require TESTS_DIR . '/parts/stage18_contracts.php';
    require TESTS_DIR . '/parts/stage19_quotes.php';
    require TESTS_DIR . '/parts/stage20_sla.php';
    require TESTS_DIR . '/parts/stage21_inbound.php';
    require TESTS_DIR . '/parts/stage22_threecx.php';
    require TESTS_DIR . '/parts/stage23_testreport.php';

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
    @unlink($kumaBackupFile);   // restored: nothing is left for the guard to do
    kuma_cache_clear();
}

echo "\n" . str_repeat('=', 60) . "\n$pass passed, $fail failed\n";
foreach ($failures as $f) { echo "  - $f\n"; }
// the report for Settings > Tests > Automated checks: written next to the other runtime files, in one go (never half a file)
$aborted = '';
foreach ($failures as $f) { if (str_starts_with($f, 'test run aborted')) { $aborted = $f; } }
$reportFile = dirname(__DIR__) . '/storage/test_report.json';
if (is_dir(dirname($reportFile))) {
    $tmp = $reportFile . '.tmp';
    if (@file_put_contents($tmp, json_encode(['run_at' => date('Y-m-d H:i:s'), 'seconds' => (int) round(microtime(true) - $startedAt), 'aborted' => $aborted, 'sections' => $__report['sections']], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) { @rename($tmp, $reportFile); }
}
exit($fail > 0 ? 1 : 0);
