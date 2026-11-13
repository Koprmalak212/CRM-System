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

section('51. Hardening: CI workflow and a portable pre-commit hook');
$wf = (string) file_get_contents($root16 . '/.github/workflows/tests.yml');
check($wf !== '' && str_contains($wf, 'name: tests') && str_contains($wf, 'pull_request') && str_contains($wf, 'branches: [main]'), 'a GitHub Actions workflow runs on pushes to main and on pull requests');
check(str_contains($wf, 'php8.2') && str_contains($wf, 'php8.2-mysql') && str_contains($wf, 'openssl') && str_contains($wf, 'rewrite'), 'it installs PHP 8.2 with pdo_mysql, checks for openssl, and enables mod_rewrite');
check(str_contains($wf, 'image: mariadb:') && str_contains($wf, 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD'), 'it starts a MariaDB service');
check(str_contains($wf, 'bin/setup.php') && str_contains($wf, 'tests/run.php'), 'it runs bin/setup.php and then tests/run.php');
check(str_contains($wf, '" 0 failed"') && str_contains($wf, 'exit 1'), 'it fails unless the output contains " 0 failed"');
check(!preg_match('/^\t/m', $wf) && substr_count($wf, "\n") > 20, 'the workflow file is indented with spaces (YAML does not allow tabs)');
$hook = (string) file_get_contents($root16 . '/.githooks/pre-commit');
check(!str_contains($hook, 'xampp') && !str_contains($hook, 'C:/') && str_contains($hook, 'PHP_BIN') && str_contains($hook, 'tests/run.php') && str_contains($hook, ' 0 failed'), 'the pre-commit hook has no hard-coded XAMPP path: it uses php from the PATH or PHP_BIN, and still demands " 0 failed"');
$p = proc_open(['sh', '-n', $root16 . '/.githooks/pre-commit'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
$err = stream_get_contents($pp[2]); $rc = proc_close($p);
check($rc === 0 || $rc === -1 && $err === '', 'the hook is valid shell (sh -n)', $err);
$rd = (string) file_get_contents($root16 . '/README.md');
check(!str_contains($rd, 'img.shields.io/badge/tests-') && !str_contains($rd, 'workflows/tests.yml') && str_contains($rd, 'lines%20of%20code'), 'README has no test badge (a private repository can not show one to everyone) and shows the lines-of-code badge');
check(is_file($root16 . '/bin/count_lines.php') && str_contains((string) file_get_contents($root16 . '/bin/count_lines.php'), "PHP_SAPI !== 'cli'"), 'bin/count_lines.php exists and refuses to run from a browser');
check(!preg_match('/\b1,126\b/', $rd), 'and no longer states a fixed number of checks');

section('52. Hardening: Tailwind is compiled, not run in the browser');
$css52 = $root16 . '/assets/css/app.css';
check(is_file($css52) && filesize($css52) > 10000 && filesize($css52) < 400000, 'assets/css/app.css exists and is a compiled stylesheet of a sensible size (' . (is_file($css52) ? round(filesize($css52) / 1024) . ' KB' : 'missing') . ')');
check(!is_file($root16 . '/assets/vendor/tailwind-3.4.17.js') && !is_dir($root16 . '/assets/vendor'), 'the in-browser Tailwind script is gone from the project');
$bad52 = []; $nolink = [];
foreach (app_source_files() as $f) {
    $t = (string) file_get_contents($f);
    if (str_contains($t, 'tailwind-3.4') || str_contains($t, 'tailwind.config=') || str_contains($t, 'theme_tailwind_script')) { $bad52[] = basename($f); }
}
same($bad52, [], 'no page loads the Tailwind script or configures it inline');
foreach (['auth/login.php', 'auth/login_2fa.php', 'index.php', 'privacy/privacybeleid.php', 'settings/settings.php?tab=account'] as $pg) {
    $r = http('GET', "$base/$pg", ['jar' => str_starts_with($pg, 'auth/login') || str_contains($pg, 'privacybeleid') ? '' : $admin]);
    if ($r['code'] === 302) { $r = http('GET', "$base/auth/login.php", ['jar' => '']); }
    if (!preg_match('~<link rel="stylesheet" href="assets/css/app\.css\?v=\d+">~', $r['body']) || str_contains($r['body'], 'tailwind-3.4')) { $nolink[] = $pg; }
}
same($nolink, [], 'the pages link assets/css/app.css and nothing else Tailwind');
$h52 = http('GET', "$base/assets/css/app.css");
check($h52['code'] === 200 && stripos($h52['headers'], 'text/css') !== false && str_contains($h52['body'], 'rgb(var(--c-ground)'), 'app.css is served as text/css and carries the themed colour variables');
// the link is the last thing in the head, as the in-browser Tailwind used to be: utilities win over the inline page rules
$lay = http('GET', "$base/index.php", ['jar' => $admin])['body'];
check(preg_match('~<link rel="stylesheet" href="assets/css/app\.css\?v=\d+">\s*</head>~', $lay) === 1, 'the stylesheet link is the last element of the head, so utilities keep their place in the cascade');
$cfg52 = (string) file_get_contents($root16 . '/tailwind.config.js');
check(str_contains($cfg52, './includes/**/*.php') && str_contains($cfg52, './assets/js/*.js') && str_contains($cfg52, 'search,notifications,settings,privacy'), 'tailwind.config.js scans the pages, the includes and the scripts');
$pkg = json_decode((string) file_get_contents($root16 . '/package.json'), true);
check(($pkg['devDependencies']['tailwindcss'] ?? '') === '3.4.17' && str_contains((string) ($pkg['scripts']['build:css'] ?? ''), 'assets/css/app.css'), 'package.json pins Tailwind 3.4.17 and has the build:css script');
check(is_file($root16 . '/package-lock.json'), 'package-lock.json is committed (CI installs exactly the same version)');
check(!str_contains((string) file_get_contents($root16 . '/.gitignore'), 'package-lock') && str_contains((string) file_get_contents($root16 . '/.gitignore'), 'node_modules'), 'node_modules is ignored, the lock file is not');
check(str_contains((string) file_get_contents($root16 . '/.github/workflows/tests.yml'), 'npm run build:css') && str_contains((string) file_get_contents($root16 . '/docs/INSTALL.md'), 'npm run build:css'), 'the build command is in the CI workflow and in docs/INSTALL.md');
// the committed stylesheet is current: rebuild into a temp file and compare (skipped where Node is not installed)
$bin52 = $root16 . '/node_modules/.bin/' . (DIRECTORY_SEPARATOR === chr(92) ? 'tailwindcss.cmd' : 'tailwindcss');
if (is_file($bin52)) {
    $tmp52 = sys_get_temp_dir() . '/app-css-check-' . getmypid() . '.css';
    $cmd = escapeshellarg($bin52) . ' -c tailwind.config.js -i assets/css/tailwind.src.css -o ' . escapeshellarg($tmp52) . ' --minify';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $root16);
    stream_get_contents($pp[1]); stream_get_contents($pp[2]); proc_close($p);
    check(is_file($tmp52) && trim((string) file_get_contents($tmp52)) === trim((string) file_get_contents($css52)), 'a fresh Tailwind build gives exactly the committed app.css (run npm run build:css after changing classes)');
    @unlink($tmp52);
} else {
    check(true, 'Node tooling is not installed here: the app.css freshness check is skipped (CI builds it)');
}
check(str_contains((string) file_get_contents($root16 . '/includes/core/security.php'), "style-src 'self' 'unsafe-inline'") && str_contains((string) file_get_contents($root16 . '/includes/core/security.php'), 'script-src \'self\' \'nonce-'), 'the policy is documented as it is: scripts need the nonce, style-src keeps unsafe-inline because style attributes remain');

section('53. Hardening: production documentation and the decisions behind it');
$inst53 = (string) file_get_contents($root16 . '/docs/INSTALL.md');
foreach (['Linux, Apache and MariaDB (production)', 'sudo a2enmod rewrite headers', 'php8.2-mysql', 'AllowOverride All', 'chown -R www-data:www-data /var/www/crm/storage', 'chmod 640', 'certbot --apache',
          'Behind a reverse proxy', 'mod_remoteip', 'RemoteIPHeader X-Forwarded-For', 'RemoteIPInternalProxy', 'share one rate-limit bucket', 'SetEnvIf X-Forwarded-Proto', 'crontab -u www-data'] as $needle) {
    check(str_contains($inst53, $needle), "docs/INSTALL.md covers: $needle");
}
$kuma53 = (string) file_get_contents($root16 . '/includes/modules/kuma.php');
check(str_contains($kuma53, 'DECISION, on purpose') && str_contains($kuma53, 'private') && str_contains($kuma53, 'loopback') && str_contains($kuma53, 'admin-only') && str_contains($kuma53, '169.254.169.254'), 'kuma_ip_blocked() carries a comment: private and loopback are allowed on purpose, the feature is admin-only, link-local stays blocked');
// the claim made in the proxy note: the program ignores X-Forwarded-For
$pdo->exec("DELETE FROM login_attempts WHERE username = 'zz.xff53'"); $pdo->exec('DELETE FROM rate_limits');
$jar53 = tempnam(sys_get_temp_dir(), 'x5');
$pg = http('GET', "$base/auth/login.php", ['jar' => $jar53]);
http('POST', "$base/auth/login.php", ['jar' => $jar53, 'headers' => ['X-Forwarded-For: 203.0.113.99'], 'body' => http_build_query(['csrf' => csrf_from($pg['body']), 'username' => 'zz.xff53', 'password' => 'not-the-password-1'])]);
$ip53 = $pdo->query("SELECT ip FROM login_attempts WHERE username = 'zz.xff53' ORDER BY id DESC LIMIT 1")->fetchColumn();
check($ip53 !== false && $ip53 !== '203.0.113.99', 'the program ignores a client-supplied X-Forwarded-For (the recorded address is ' . var_export($ip53, true) . ')');
$pdo->exec("DELETE FROM login_attempts WHERE username = 'zz.xff53'"); @unlink($jar53);
// database administrator from the environment: the override is really used
$envRun = function (array $extra) use ($root16) {
    $p = proc_open([PHP_BINARY, $root16 . '/database/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $root16, array_merge(getenv(), $extra));
    $o = stream_get_contents($pp[1]) . stream_get_contents($pp[2]);
    return [proc_close($p), $o];
};
[$rc, $o] = $envRun([]);
check($rc === 0 && str_contains($o, 'up to date'), 'database/migrate.php runs with the XAMPP defaults (root, no password)', trim(substr($o, -150)));
[$rc, $o] = $envRun(['MIGRATE_DB_USER' => 'no_such_admin_53', 'MIGRATE_DB_PASS' => 'x']);
check($rc !== 0, 'and uses MIGRATE_DB_USER / MIGRATE_DB_PASS when they are set (a wrong account is refused)', (string) $rc);
$su53 = (string) file_get_contents($root16 . '/bin/setup.php');
check(str_contains($su53, "getenv('SETUP_DB_USER')") && str_contains($su53, "getenv('SETUP_DB_PASS')") && str_contains($su53, "getenv('SETUP_DB_HOST')"), 'bin/setup.php reads SETUP_DB_USER, SETUP_DB_PASS and SETUP_DB_HOST');
check(str_contains($inst53, 'SETUP_DB_USER=installer') && str_contains($inst53, 'MIGRATE_DB_USER'), 'and the install guide shows how to use them');
