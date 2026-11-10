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
