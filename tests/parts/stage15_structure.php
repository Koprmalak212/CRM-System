<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// File structure: pages live in feature folders, every link resolves, old addresses redirect, private folders are closed.
// Included by tests/run.php inside its try block (uses $base, $admin, $pdo, http(), check(), same(), section()).
$root15 = (string) realpath(TESTS_DIR . '/..');
$basePath15 = (string) parse_url($base, PHP_URL_PATH);   // for example /Websites/test

section('43. File structure: layout of the project');
$rootPhp = array_map('basename', glob($root15 . '/*.php') ?: []);
same($rootPhp, ['index.php'], 'the only PHP file in the project root is index.php (the Overview)');
foreach (['auth', 'users', 'companies', 'tickets', 'calendar', 'invoices', 'monitoring', 'system', 'search', 'notifications', 'settings', 'privacy', 'api', 'bin', 'includes/core', 'includes/modules', 'includes/settings', 'assets/css', 'assets/js', 'assets/img', 'docs', 'database', 'tests'] as $d) {
    check(is_dir($root15 . '/' . $d), "folder $d exists");
}
check(!glob($root15 . '/assets/*.css') && !glob($root15 . '/assets/*.js') && !glob($root15 . '/assets/*.png'), 'no loose css, js or png files are left in the assets root');
check(!glob($root15 . '/includes/*.php') || array_map('basename', glob($root15 . '/includes/*.php')) === ['config.local.php'], 'includes/ holds only folders (and the local config)');
foreach (glob($root15 . '/bin/*.php') ?: [] as $f) {
    check(str_contains((string) file_get_contents($f), "PHP_SAPI !== 'cli'") || basename($f) === 'setup.php', basename($f) . ' refuses to run from a browser');
}
check(str_contains((string) file_get_contents($root15 . '/bin/setup.php'), "PHP_SAPI"), 'bin/setup.php is command-line only');

section('44. File structure: private folders and old addresses');
foreach (['includes/core/bootstrap.php', 'includes/modules/mailer.php', 'database/schema.sql', 'tests/run.php', 'bin/purge.php', 'storage/php-error.log', 'docs/PRODUCT.md'] as $p) {
    same(http('GET', "$base/$p")['code'], 403, "$p can not be fetched from the web (403)");
}
foreach (['assets/css/motion.css' => 'text/css', 'assets/js/motion.js' => 'javascript', 'assets/img/telecomlijn-logo-white.png' => 'image/png', 'assets/fonts/fonts.css' => 'text/css'] as $p => $type) {
    $r = http('GET', "$base/$p");
    check($r['code'] === 200 && stripos($r['headers'], $type) !== false, "$p is still served ($type)");
}
$legacy = ['login.php' => 'auth/login.php', 'user.php?id=3' => 'users/user.php?id=3', 'invoice_pdf.php?id=2' => 'invoices/invoice_pdf.php?id=2', 'settings.php?tab=email' => 'settings/settings.php?tab=email',
    'reset.php?token=abc' => 'auth/reset.php?token=abc', 'calendar_ics.php?company=4' => 'calendar/calendar_ics.php?company=4', 'kuma_api.php' => 'api/kuma_api.php'];
foreach ($legacy as $old => $new) {
    $r = http('GET', "$base/$old");
    check($r['code'] === 301 && preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) && str_ends_with($m[1], '/' . $new), "the old address $old redirects (301) to $new", $r['code'] . ' ' . ($m[1] ?? ''));
}
same(http('GET', "$base/index.php", ['jar' => ''])['code'], 302, 'the Overview still sends a signed-out visitor to the sign-in page');
$r = http('GET', "$base/index.php", ['jar' => '']);
check(preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) && str_ends_with($m[1], $basePath15 . '/auth/login.php'), 'and the sign-in page address includes the project folder', $m[1] ?? '');
$r = http('GET', "$base/tickets/tickets.php", ['jar' => '']);
check($r['code'] === 302 && preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) && str_ends_with($m[1], $basePath15 . '/auth/login.php'), 'a page inside a folder also sends a signed-out visitor to the right sign-in address', $m[1] ?? '');

section('45. File structure: every link and form in the program resolves');
$demo15 = false;
if ((int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 0) { demo_seed($pdo); $demo15 = true; }   // some pages only show links when there are records
$resolve = function (string $href, string $pageUrl) use ($base): ?string {
    $href = html_entity_decode($href, ENT_QUOTES);
    if ($href === '' || $href[0] === '#' || preg_match('~^(mailto:|tel:|javascript:|data:)~i', $href)) { return null; }
    if (preg_match('~^https?://~i', $href)) { return str_starts_with($href, $base . '/') ? $href : null; }
    $origin = (string) parse_url($base, PHP_URL_SCHEME) . '://' . (string) parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
    if ($href[0] === '/') { return $origin . $href; }
    return $origin . rtrim((string) parse_url($base, PHP_URL_PATH), '/') . '/' . $href;   // every page sets <base href> to the project folder
};
$queue = ["$base/index.php"]; $seen = []; $pages = 0; $bad = []; $noBase = [];
$skip = '~(assets/|[?&]export=|logout\.php|export\.php|invoice_pdf\.php|report_download\.php|calendar_ics\.php|api/|demo_data\.php|notifications\.php\?go=|\?(?:.*&)?delete=|check_endpoints)~';
while ($queue && $pages < 260) {
    $url = array_shift($queue);
    $key = preg_replace('~([?&])(page|id|month|company|user|q|sort|dir|from|to)=[^&]*~', '$1$2=', $url);
    if (isset($seen[$key]) || preg_match($skip, $url)) { continue; }
    $seen[$key] = true; $pages++;
    if ($pages % 100 === 0) { $pdo->exec('DELETE FROM rate_limits'); }   // the crawler is faster than the per-minute limit
    $r = http('GET', $url, ['jar' => $admin]);
    if ($r['code'] >= 400 || preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught )/', $r['body'])) { $bad[] = str_replace($base . '/', '', $url) . ' -> ' . $r['code']; continue; }
    if ($r['code'] >= 300) { continue; }
    if (stripos($r['headers'], 'text/html') === false) { continue; }
    if (!str_contains($r['body'], '<base href="')) { $noBase[] = str_replace($base . '/', '', $url); }
    if (!preg_match_all('/(?:href|action)="([^"]+)"/', $r['body'], $mm)) { continue; }
    foreach ($mm[1] as $h) {
        $abs = $resolve($h, $url);
        if ($abs !== null && !isset($seen[preg_replace('~([?&])(page|id|month|company|user|q|sort|dir|from|to)=[^&]*~', '$1$2=', $abs)])) { $queue[] = $abs; }
    }
}
check($pages > 40, "the crawler visited $pages different pages (more than 40)");
check(!$bad, 'no page, link or form target returns an error or prints a PHP warning', implode('; ', array_slice($bad, 0, 8)));
check(!$noBase, 'every HTML page sets the base address', implode(', ', array_slice($noBase, 0, 8)));
// downloads and file endpoints are fetched once each, with a real record where one is needed
$one = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
$pdo->exec('DELETE FROM rate_limits');
$dl = ["$base/system/export.php?type=users" => 200, "$base/invoices/invoice_pdf.php?id=" . $one('SELECT MIN(id) FROM invoices') => 200, "$base/system/report_download.php?id=" . $one("SELECT MIN(id) FROM reports WHERE status = 'ready'") => 200,
    "$base/calendar/calendar_ics.php?company=" . $one('SELECT MIN(id) FROM companies') => 200, "$base/api/health.php" => [200, 401, 403], "$base/auth/logout.php" => [200, 302, 405]];
foreach ($dl as $u => $want) {
    if ($u === "$base/auth/logout.php") { continue; }   // would end the shared test session
    $r = http('GET', $u, ['jar' => $admin]);
    check(in_array($r['code'], (array) $want, true), 'download or endpoint answers: ' . str_replace($base . '/', '', $u), (string) $r['code']);
}
// link in a notification saved before the move still leads somewhere sensible
$pdo->exec("INSERT INTO notifications (kind, dedupe_key, title, link, created_at) VALUES ('x', 'zz-legacy-15', 'ZZ legacy link', 'ticket.php?id=1', NOW())");
$nid = $one("SELECT id FROM notifications WHERE dedupe_key = 'zz-legacy-15'");
$r = http('GET', "$base/notifications/notifications.php?go=$nid", ['jar' => $admin]);
check($r['code'] === 302 && preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) && str_ends_with($m[1], $basePath15 . '/tickets/ticket.php?id=1'), 'an old notification link (ticket.php?id=1) is translated to tickets/ticket.php?id=1', $m[1] ?? '');
$pdo->exec("DELETE FROM notifications WHERE dedupe_key = 'zz-legacy-15'");

section('46. File structure: links inside a page, redirects and email links');
$html = http('GET', "$base/calendar/calendar.php", ['jar' => $admin])['body'];
check(str_contains($html, '<base href="' . $basePath15 . '/">'), 'the base address is the project folder (' . $basePath15 . '/)');
check(preg_match('/href="calendar\/calendar\.php#/', $html) || !preg_match('/href="#/', $html), 'in-page links keep the page name, so the base address does not send them elsewhere');
check(!preg_match('/href="\?[^"]*"/', $html), 'there are no query-only links that the base address would send to the Overview');
$html = http('GET', "$base/users/users.php", ['jar' => $admin])['body'];
check(preg_match('/href="users\/users\.php\?[^"]*sort=/', $html) === 1, 'sortable column headers point at the page itself with their query');
$html = http('GET', "$base/index.php", ['jar' => $admin])['body'];
check(preg_match('/href="users\/users\.php"/', $html) && preg_match('/href="calendar\/calendar\.php"/', $html), 'the sidebar links use the folder paths');
check(str_contains($html, 'href="' . 'index.php#demo-data"') || str_contains($html, 'href="index.php#demo-data"') || !str_contains($html, '#demo-data"'), 'the Overview jump link names the page');
$tok = token($base, $admin);
$r = http('POST', "$base/notifications/notifications.php", ['jar' => $admin, 'body' => http_build_query(['csrf' => $tok, 'action' => 'read_all', 'back' => 'tickets/tickets.php'])]);
check($r['code'] === 302 && preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) && str_ends_with($m[1], '/tickets/tickets.php'), '"Mark all read" returns to the page it was used on (folder path accepted)', $m[1] ?? '');
$r = http('POST', "$base/notifications/notifications.php", ['jar' => $admin, 'body' => http_build_query(['csrf' => $tok, 'action' => 'read_all', 'back' => '../../etc/passwd'])]);
check($r['code'] === 302 && preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) && !str_contains($m[1], 'passwd'), 'a "back" value that tries to leave the project is refused', $m[1] ?? '');
$r = http('POST', "$base/notifications/notifications.php", ['jar' => $admin, 'body' => http_build_query(['csrf' => $tok, 'action' => 'read_all', 'back' => 'https://evil.example/x.php'])]);
check($r['code'] === 302 && preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) && !str_contains($m[1], 'evil.example'), 'and so is a "back" value that points to another site', $m[1] ?? '');
check(basename(LEGACY_PAGES['ticket.php']) === 'ticket.php' && LEGACY_PAGES['ticket.php'] === 'tickets/ticket.php', 'the table of old page names maps ticket.php to tickets/ticket.php');
foreach (LEGACY_PAGES as $old => $new) { if (!is_file($root15 . '/' . $new)) { check(false, "LEGACY_PAGES points at a missing file: $new"); } }
check(true, 'every entry of the old page name table points at a real file');
// the email links built by the program include the folder
$src = (string) file_get_contents($root15 . '/auth/forgot.php') . (string) file_get_contents($root15 . '/includes/settings/team.php');
check(str_contains($src, "'/auth/reset.php?token='") && str_contains($src, "'/auth/invite.php?token='"), 'reset and invitation emails link to auth/reset.php and auth/invite.php');
if ($demo15) { demo_remove($pdo); }
