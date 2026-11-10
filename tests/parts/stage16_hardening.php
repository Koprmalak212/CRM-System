<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Hardening round: first admin password, per-username lockout, purge outside web requests, lazy demo module.
// Included by tests/run.php inside its try block.
$root16 = (string) realpath(TESTS_DIR . '/..');

section('47. Hardening: a random first admin password');
require_once $root16 . '/includes/modules/passwords.php';
require_once $root16 . '/includes/core/public_page.php';   // password_problem()
$seen = []; $badLen = 0; $badKind = 0; $amb = 0; $weak = 0;
for ($i = 0; $i < 300; $i++) {
    $p = random_password();
    $seen[$p] = true;
    $plain = str_replace('-', '', $p);
    if (strlen($plain) < 20) { $badLen++; }
    if (!preg_match('/[A-Z]/', $plain) || !preg_match('/[a-z]/', $plain) || !preg_match('/[0-9]/', $plain) || !preg_match('/[^A-Za-z0-9]/', $plain)) { $badKind++; }
    if (preg_match('/[0O1lI]/', $plain)) { $amb++; }
    if (password_problem($p, $p, 'admin', 'admin@telecomlijn.test') !== null) { $weak++; }
}
same(count($seen), 300, '300 generated passwords are all different');
same($badLen, 0, 'every one has at least 20 characters');
same($badKind, 0, 'every one has a capital, a small letter, a digit and a symbol');
same($amb, 0, 'none has look-alike characters (0 O 1 l I)');
same($weak, 0, 'every one passes the password rules used on the reset page');
check(strlen(random_password(4)) >= 12, 'a too short length request is raised to at least 12 characters');

$setup = (string) file_get_contents($root16 . '/bin/setup.php');
check(str_contains($setup, '$adminPassword = $demo ? DEMO_ADMIN_PASSWORD : random_password();') && str_contains($setup, '$adminMustChange = $demo ? 0 : 1;'), 'bin/setup.php: a real install gets a random password and must_change_password = 1; only --demo keeps admin / admin');
check(!preg_match("/const ADMIN_PASSWORD\\b/", $setup) && !str_contains($setup, 'password_hash(ADMIN_PASSWORD'), 'bin/setup.php no longer hashes a fixed default password');
check(preg_match('/must_change_password\)\s*VALUES[^;]*\?\)"\)/s', $setup) === 1, 'the admin row takes must_change_password from the install type, not a fixed 0');
check(str_contains($setup, 'First sign-in') && str_contains($setup, 'shown only now'), 'the password is printed once, with a note that it is stored only as a hash');
check(str_contains((string) file_get_contents($root16 . '/database/schema.sql'), 'must_change_password TINYINT(1) NOT NULL DEFAULT 1'), 'the schema default for must_change_password is 1');
$inst = (string) file_get_contents($root16 . '/docs/INSTALL.md');
check(!preg_match('/sign in with `admin` \/ `admin`\.\s*\*\*Change/', $inst) && str_contains($inst, 'random first password'), 'docs/INSTALL.md no longer tells a real install to sign in with admin / admin');
// the forced change really happens: an account created with must_change_password = 1 can only reach the change-password page
$pw16 = random_password();
$pdo->prepare("INSERT INTO admins (username, email, name, password_hash, role, perm_integrations, must_change_password) VALUES ('zz.first16', 'zz.first16@example.test', 'First Login', ?, 'admin', 'manage', 1)")->execute([hash_password($pw16)]);
$fl = login($base, 'zz.first16', $pw16);
$r = http('GET', "$base/index.php", ['jar' => $fl]);
check($r['code'] === 302 && str_contains($r['headers'], '/auth/change_password.php'), 'a first sign-in with a generated password is sent to the change-password page before anything else', (string) $r['code']);
$r = http('GET', "$base/auth/change_password.php", ['jar' => $fl]);
check($r['code'] === 200, 'and the change-password page itself opens');
$pdo->exec("DELETE FROM admins WHERE username = 'zz.first16'");
$pdo->exec("DELETE FROM activity_logs WHERE target LIKE '%zz.first16%' OR actor = 'zz.first16'");

section('48. Hardening: sign-in lockout per account, from any address');
check(str_contains((string) file_get_contents($root16 . '/database/schema.sql'), 'KEY idx_user_time (username, attempted_at)'), 'the schema has an index for the per-account count');
$pdo48 = $pdo;
same((int) $pdo48->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'login_attempts' AND index_name = 'idx_user_time'")->fetchColumn() > 0, true, 'and the running database has it (run database/migrate.php after an update)');
$pw48 = random_password();
$pdo->prepare("INSERT INTO admins (username, email, name, password_hash, role, perm_integrations, must_change_password) VALUES ('zz.lock16', 'zz.lock16@example.test', 'Lock Tester', ?, 'user', 'none', 0)")->execute([hash_password($pw48)]);
$spread = function (string $name, int $n, string $when = 'NOW()') use ($pdo) {   // $n failures for $name, each from a different address
    $ins = $pdo->prepare("INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, $when)");
    for ($i = 1; $i <= $n; $i++) { $ins->execute(['203.0.113.' . $i, $name]); }
};
$reset48 = function () use ($pdo) { $pdo->exec('DELETE FROM login_attempts'); $pdo->exec('DELETE FROM rate_limits'); };
$tryLogin = function (string $user, string $pass) use ($base) {   // returns [signed in?, page text]
    $jar = tempnam(sys_get_temp_dir(), 'tl');
    $page = http('GET', "$base/auth/login.php", ['jar' => $jar]);
    $r = http('POST', "$base/auth/login.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($page['body']), 'username' => $user, 'password' => $pass])]);
    $in = $r['code'] === 302 && str_contains($r['headers'], 'index.php');
    @unlink($jar);
    return [$in, $r['body'], $r['code']];
};
$LOCK = 'Too many failed attempts. Wait 15 minutes and try again.';

$reset48(); $spread('zz.lock16', 9);
[$in] = $tryLogin('zz.lock16', $pw48);
check($in, '9 failures from 9 different addresses do not lock the account: the right password still works');

$reset48(); $spread('zz.lock16', 10);
[$in, $body] = $tryLogin('zz.lock16', $pw48);
check(!$in && str_contains($body, $LOCK), '10 failures from 10 different addresses lock the account: even the right password is refused for now', substr(strip_tags($body), 0, 120));

$reset48(); $spread('zz.lock16@example.test', 10);
[$in, $body] = $tryLogin('zz.lock16', $pw48);
check(!$in && str_contains($body, $LOCK), 'failures typed as the email address count against the username too (no way round it by switching)');
$reset48(); $spread('zz.lock16', 5); $spread('zz.lock16@example.test', 5);
$pdo->exec("UPDATE login_attempts SET ip = CONCAT('198.51.100.', id % 200) WHERE ip LIKE '203.0.113.%'");
[$in] = $tryLogin('zz.lock16@example.test', $pw48);
check(!$in, 'five by username and five by email together close the account');

$reset48(); $spread('no.such.account16', 10);
[$in, $body] = $tryLogin('no.such.account16', 'whatever-123');
check(!$in && str_contains($body, $LOCK), 'an unknown name shows exactly the same lockout message, so nothing reveals whether an account exists');

$reset48(); $spread('zz.lock16', 10, 'NOW() - INTERVAL 20 MINUTE');
[$in] = $tryLogin('zz.lock16', $pw48);
check($in, 'failures older than the 15-minute window no longer count: the lockout lifts by itself');

$reset48(); $spread('zz.lock16', 10);
[$in, $body] = $tryLogin('zz.lock16', 'a-wrong-password-1');
check(!$in && str_contains($body, $LOCK), 'a wrong password during the lockout gets the same message');
same((int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE username = 'zz.lock16'")->fetchColumn(), 10, 'attempts made during a lockout are not added to the count, so it does not grow without end');

$reset48(); $spread('zz.lock16', 9);
[$in, $body] = $tryLogin('zz.lock16', 'a-wrong-password-2');
check(!$in && str_contains($body, 'do not match'), 'the 10th failure itself still answers "do not match"');
check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'Sign-in locked for 15 minutes%' AND target = 'zz.lock16' AND created_at > NOW() - INTERVAL 5 MINUTE")->fetchColumn() === 1, 'and that failure writes one activity-log entry saying the account is locked');
[$in] = $tryLogin('zz.lock16', $pw48);
check(!$in, 'from then on the account is locked for the right password too');

$reset48(); $spread('some.other.person', 10);
[$in] = $tryLogin('zz.lock16', $pw48);
check($in, 'a lockout on one account does not affect another');
$reset48();
$pdo->exec("DELETE FROM admins WHERE username = 'zz.lock16'");
$pdo->exec("DELETE FROM activity_logs WHERE target IN ('zz.lock16', 'zz.lock16@example.test') OR actor = 'zz.lock16'");

section('49. Hardening: the retention purge runs from cron, not from web requests');
$sec = (string) file_get_contents($root16 . '/includes/core/security.php');
preg_match('/function rate_hit\(.*?\R}\R/s', $sec, $mm);   // \R: the file may have Windows line endings
check(isset($mm[0]) && !str_contains($mm[0], 'purge_expired_data') && !str_contains($mm[0], 'random_int'), 'rate_hit() no longer calls the purge (and has no random housekeeping)');
check(!str_contains($sec, 'random_int(1, 150)'), 'the one-in-150 purge is gone from security.php');
$pdo->exec("DELETE FROM activity_logs WHERE action = 'ZZH49 old'");
$pdo->exec("INSERT INTO activity_logs (level, actor, action, target, ip, created_at) VALUES ('info', 'zz', 'ZZH49 old', 'old', '::1', NOW() - INTERVAL 400 DAY)");
$pdo->exec("DELETE FROM rate_limits WHERE bucket = 'zz49'");
$pdo->exec("INSERT INTO rate_limits (ip, bucket, window_start, hits) VALUES ('0.0.0.0', 'zz49', " . (time() - 7200) . ", 3)");
$old49 = fn() => (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'ZZH49 old'")->fetchColumn();
$win49 = fn() => (int) $pdo->query("SELECT COUNT(*) FROM rate_limits WHERE bucket = 'zz49' AND window_start < " . (time() - 3600))->fetchColumn();
for ($i = 0; $i < 900; $i++) { rate_hit('zz49b', 100000, 60); }   // before the change roughly 6 of these 900 calls would have purged
check($old49() === 1 && $win49() === 1, '900 rate-limit calls leave expired rows alone: nothing is purged inside a request');
$pdo->exec("DELETE FROM rate_limits WHERE bucket = 'zz49b'");
$p = proc_open([PHP_BINARY, $root16 . '/bin/purge.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
$out49 = stream_get_contents($pp[1]); $code49 = proc_close($p);
check($code49 === 0 && str_contains($out49, 'Removed'), 'bin/purge.php runs from the command line and reports what it removed', trim($out49));
check($old49() === 0 && $win49() === 0, 'and it removes the expired activity-log row and the expired rate-limit window');
same(http('GET', "$base/bin/purge.php")['code'], 403, 'the purge can not be started from the web (403)');
$inst49 = (string) file_get_contents($root16 . '/docs/INSTALL.md');
check(str_contains($inst49, 'bin/purge.php') && str_contains($inst49, '15 3 * * *') && str_contains($inst49, 'only thing that deletes expired data'), 'docs/INSTALL.md documents the cron line and says that nothing else purges');

section('50. Hardening: the demo module is loaded only where it is used');
$boot50 = (string) file_get_contents($root16 . '/includes/core/bootstrap.php');
$loadsDemo = fn(string $src) => preg_match('~(?<![A-Za-z_])(require|require_once|include|include_once)(?![A-Za-z_])[^;]*modules/demo\.php~', $src) === 1;   // a real require, not a comment
check(!$loadsDemo($boot50), 'bootstrap.php no longer requires includes/modules/demo.php');
foreach (['system/demo_data.php', 'bin/setup.php', 'index.php'] as $f) {
    check($loadsDemo((string) file_get_contents($root16 . '/' . $f)), "$f loads the demo module itself");
}
$others = [];
foreach (app_source_files() as $f) {
    $rel = ltrim(str_replace(str_replace(chr(92), '/', $root16), '', $f), '/');
    if ($loadsDemo((string) file_get_contents($f)) && !in_array($rel, ['system/demo_data.php', 'bin/setup.php', 'index.php', 'includes/core/bootstrap.php'], true)) { $others[] = $rel; }
}
same($others, [], 'no other file loads the demo module');
$p = proc_open([PHP_BINARY, '-r', 'require ' . var_export($root16 . '/includes/core/bootstrap.php', true) . '; echo (function_exists("demo_seed") ? "seed" : "noseed"), ",", (function_exists("demo_remove") ? "remove" : "noremove"), ",", (function_exists("demo_loaded") ? "loaded" : "noloaded"), ",", (function_exists("demo_counts") ? "counts" : "nocounts");'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
$o50 = trim(stream_get_contents($pp[1])); proc_close($p);
same($o50, 'noseed,noremove,loaded,nocounts', 'after bootstrap only the cheap demo_loaded() exists: no seed, remove or count code is loaded');
$p = proc_open([PHP_BINARY, '-r', 'require ' . var_export($root16 . '/includes/core/bootstrap.php', true) . '; require ' . var_export($root16 . '/includes/modules/demo.php', true) . '; echo (function_exists("demo_seed") && function_exists("demo_remove") && function_exists("demo_counts") && function_exists("demo_loaded")) ? "all" : "missing";'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
same(trim(stream_get_contents($pp[1])), 'all', 'and including the demo module adds them without a redeclare error'); proc_close($p);
// pages still behave: the banner follows the flag, and the Overview panel and the demo endpoint still work
$flag50 = $pdo->query("SELECT v FROM app_meta WHERE k = 'demo_loaded'")->fetchColumn();
$pdo->exec("INSERT INTO app_meta (k, v) VALUES ('demo_loaded', '1') ON DUPLICATE KEY UPDATE v = '1'");
$html = http('GET', "$base/companies/companies.php", ['jar' => $admin])['body'];
check(str_contains($html, 'Demo data is loaded') && str_contains($html, 'Demo data seeded for display'), 'with the flag set every page still shows the demo banner and the footer note');
$pdo->exec("INSERT INTO app_meta (k, v) VALUES ('demo_loaded', '0') ON DUPLICATE KEY UPDATE v = '0'");
$html = http('GET', "$base/companies/companies.php", ['jar' => $admin])['body'];
check(!str_contains($html, 'Demo data is loaded'), 'and without it neither is shown');
$html = http('GET', "$base/index.php", ['jar' => $admin])['body'];
check(str_contains($html, 'id="demo-data"') && str_contains($html, 'system/demo_data.php'), 'the Overview still shows the demo panel to an admin');
$r = http('GET', "$base/system/demo_data.php", ['jar' => $admin]);
check($r['code'] === 302, 'system/demo_data.php still loads without errors (a GET goes back to the Overview)', (string) $r['code']);
if ($flag50 === false) { $pdo->exec("DELETE FROM app_meta WHERE k = 'demo_loaded'"); } else { $pdo->prepare("UPDATE app_meta SET v = ? WHERE k = 'demo_loaded'")->execute([$flag50]); }
