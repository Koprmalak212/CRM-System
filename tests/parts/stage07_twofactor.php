<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Stage 7 of the test suite. Included by tests/run.php inside its try block, so it shares the helpers and variables defined there.
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
        $page = http('GET', "$base/auth/login.php", ['jar' => $jar]);
        return http('POST', "$base/auth/login.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($page['body']), 'username' => $u, 'password' => $p])]);
    };
    $codeStep = function (string $jar, string $code) use ($base, $pdo) {
        $pdo->exec('DELETE FROM rate_limits');
        $page = http('GET', "$base/auth/login_2fa.php", ['jar' => $jar]);
        return http('POST', "$base/auth/login_2fa.php", ['jar' => $jar, 'body' => http_build_query(['csrf' => csrf_from($page['body']), 'code' => $code])]);
    };
    $signedIn = fn(string $jar) => http('GET', "$base/index.php", ['jar' => $jar])['code'] === 200;
    $newJar = fn() => tempnam(sys_get_temp_dir(), 'tf');

    section('20. Two-factor sign-in: turning it on from Settings');
    $jar = $newJar();
    $r = $pwStep($jar);
    check($r['code'] === 302 && str_contains($r['headers'], 'index.php') && $signedIn($jar), 'without two-step an account signs in as before (password only)');
    $acc = http('GET', "$base/settings/settings.php?tab=account", ['jar' => $jar])['body'];
    check(str_contains($acc, 'Two-step sign-in') && str_contains($acc, 'Set up two-step sign-in') && !str_contains($acc, 'Secret key'), 'Settings > Account offers the set-up and shows no secret yet');
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 0, 'it is off in the database');
    $tf = function (array $d) use ($post, $jar) { return $post($jar, 'settings/settings.php?tab=account', ['tab' => 'account'] + $d, 'settings/settings.php?tab=account'); };
    $tf(['action' => '2fa_start']);
    $acc = http('GET', "$base/settings/settings.php?tab=account", ['jar' => $jar])['body'];
    preg_match('/Secret key<\/dt><dd[^>]*>([A-Z2-7 ]+)</', $acc, $m);
    $secret = base32_decode($m[1] ?? '');
    check($secret !== null && strlen($secret) === 20 && str_contains($acc, 'otpauth://totp/') && str_contains($acc, 'Confirm and turn on'), 'the set-up shows a 20-byte secret key, the otpauth link and a confirm form');
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 0, 'still off until the first code is confirmed');
    check(!str_contains(http('GET', "$base/settings/settings.php?tab=account", ['jar' => $newJar()])['body'], 'Secret key'), 'the secret is only in the signed-in session, not on any other page');
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
    $acc = http('GET', "$base/settings/settings.php?tab=account", ['jar' => $jar])['body'];
    preg_match_all('/<li>([A-Z2-9]{5}-[A-Z2-9]{5})<\/li>/', $acc, $mm);
    $codes = $mm[1];
    check(count($codes) === 10 && count(array_unique($codes)) === 10, 'ten different recovery codes are shown');
    $hashes = $pdo->query("SELECT code_hash FROM admin_recovery_codes WHERE admin_id = $tfaId")->fetchAll(PDO::FETCH_COLUMN);
    check(count($hashes) === 10 && !array_intersect($hashes, array_merge($codes, array_map(fn($c) => str_replace('-', '', $c), $codes))) && strlen($hashes[0]) === 64, 'they are stored only as 64-character hashes, never in plain text');
    check(!str_contains(http('GET', "$base/settings/settings.php?tab=account", ['jar' => $jar])['body'], $codes[0]), 'the recovery codes are shown once: a reload no longer shows them');
    check(str_contains(http('GET', "$base/settings/settings.php?tab=account", ['jar' => $jar])['body'], 'Unused recovery codes: <strong class="text-ink">10</strong>'), 'the page counts the unused recovery codes');
    same((int) $pdo->query("SELECT totp_last_step FROM admins WHERE id = $tfaId")->fetchColumn(), intdiv($confirmAt, 30), 'the step used to confirm is remembered (it can not be used again)');
    check(((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Two-step sign-in turned on' AND target = 'tfa.tester'")->fetchColumn()) === 1, 'turning it on is in the activity log');
    same(http('POST', "$base/settings/settings.php?tab=account", ['body' => 'action=2fa_start&tab=account'])['code'], 302, 'signed out: the set-up can not be started');
    same(http('POST', "$base/settings/settings.php?tab=account", ['jar' => $jar, 'body' => 'action=2fa_start&tab=account'])['code'], 403, 'a set-up request without a CSRF token is 403');

    section('21. Two-factor sign-in: the login steps');
    $j = $newJar();
    $r = $pwStep($j);
    check($r['code'] === 302 && str_contains($r['headers'], 'auth/login_2fa.php') && !str_contains($r['headers'], 'index.php'), 'right password: sent to the code step, not into the dashboard');
    same(http('GET', "$base/index.php", ['jar' => $j])['code'], 302, 'and the dashboard is still closed: the password alone is not enough');
    same(http('GET', "$base/settings/settings.php", ['jar' => $j])['code'], 302, 'so are the other pages');
    check(str_contains(http('GET', "$base/auth/login_2fa.php", ['jar' => $j])['body'], 'Two-step sign-in'), 'the code step page opens for that session');
    same(http('GET', "$base/auth/login_2fa.php", ['jar' => $newJar()])['code'], 302, 'the code page is not reachable without first passing the password step');
    same(http('POST', "$base/auth/login_2fa.php", ['body' => 'code=123456'])['code'], 302, 'a code posted without a session is refused');
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
    check($r['code'] === 302 && str_contains($r['headers'], 'auth/login.php?locked=1') && !$signedIn($jl), 'the sixth attempt is locked out, even with a correct code');
    $r = http('GET', "$base/auth/login.php?locked=1", ['jar' => $newJar()]);
    check(str_contains($r['body'], 'Too many failed attempts'), 'the login page explains the lock-out');
    $jl2 = $newJar(); $r = $pwStep($jl2);
    check($r['code'] === 302 && str_contains($r['headers'], 'auth/login_2fa.php'), 'the password step still works while locked (the lock is on the code step)');
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
    $tf = function (array $d) use ($post, $jt) { return $post($jt, 'settings/settings.php?tab=account', ['tab' => 'account'] + $d, 'settings/settings.php?tab=account'); };
    if (!$signedIn($jt)) { $pdo->prepare('UPDATE admins SET totp_last_step = NULL WHERE id = ?')->execute([$tfaId]); $jt = $newJar(); $pwStep($jt); $codeStep($jt, totp_code($secret, time())); $tf = function (array $d) use ($post, $jt) { return $post($jt, 'settings/settings.php?tab=account', ['tab' => 'account'] + $d, 'settings/settings.php?tab=account'); }; }
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
    $post($jr, 'settings/settings.php?tab=account', ['tab' => 'account', 'action' => '2fa_disable', 'password' => $tfaPass, 'code' => $rcs[1]], 'settings/settings.php?tab=account');
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 0, 'a recovery code also works as the proof when turning it off');

    // admin reset
    $pdo->prepare('UPDATE admins SET totp_secret_enc = ?, totp_enabled = 1 WHERE id = ?')->execute([secret_encrypt($secret), $tfaId]);
    recovery_codes_make($pdo, $tfaId);
    $team = http('GET', "$base/settings/settings.php?tab=team", ['jar' => $admin])['body'];
    check(str_contains($team, 'Two-step sign-in on') && str_contains($team, 'Reset two-step'), 'Settings > Team shows who has two-step on and a Reset button');
    same($post($user, 'settings/settings.php?tab=team', ['tab' => 'team', 'action' => 'reset_2fa', 'id' => (string) $tfaId], 'settings/settings.php?tab=account')['code'], 403, 'a normal account can not reset anyone\'s two-step (403)');
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn(), 1, 'and nothing changed');
    $adminSelf = (int) $pdo->query("SELECT id FROM admins WHERE username = '" . $adminUser . "'")->fetchColumn();
    $post($admin, 'settings/settings.php?tab=team', ['tab' => 'team', 'action' => 'reset_2fa', 'id' => (string) $adminSelf], 'settings/settings.php?tab=team');
    same((int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $adminSelf")->fetchColumn(), 0, 'resetting yourself from Team is refused (use the Account tab); the admin still has it off');
    $post($admin, 'settings/settings.php?tab=team', ['tab' => 'team', 'action' => 'reset_2fa', 'id' => (string) $tfaId], 'settings/settings.php?tab=team');
    $row = $pdo->query("SELECT totp_enabled, totp_secret_enc FROM admins WHERE id = $tfaId")->fetch();
    check((int) $row['totp_enabled'] === 0 && $row['totp_secret_enc'] === null && (int) $pdo->query("SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = $tfaId")->fetchColumn() === 0, 'an admin can reset another person\'s two-step: off, secret and recovery codes erased');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Two-step sign-in reset by admin' AND target = 'tfa.tester'")->fetchColumn() === 1, 'the reset is logged');
    $jz = $newJar(); $r = $pwStep($jz);
    check($r['code'] === 302 && str_contains($r['headers'], 'index.php'), 'after a reset the person can sign in with their password and set it up again');
    $rr = $post($admin, 'settings/settings.php?tab=team', ['tab' => 'team', 'action' => 'reset_2fa', 'id' => (string) $tfaId], 'settings/settings.php?tab=team');
    check($rr['code'] === 302 && (int) $pdo->query("SELECT totp_enabled FROM admins WHERE id = $tfaId")->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Two-step sign-in reset by admin' AND target = 'tfa.tester'")->fetchColumn() === 1, 'resetting an account that has it off changes nothing and is not logged again');
    $cleanTfa();
    $pdo->exec('DELETE FROM rate_limits');
