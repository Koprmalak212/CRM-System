<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Togglable modules: the registry, the 404 pages, the settings tabs and the all-off case. Included by tests/run.php inside its try block.
// The module states the install had are remembered first and put back exactly in a finally, so a failing check can not leave a module off.
$root17 = (string) realpath(TESTS_DIR . '/..');
$keys17 = array_keys(MODULES);
$keysNoBackups17 = array_values(array_diff($keys17, ['backups']));
$saved17 = $pdo->query("SELECT k, v FROM app_meta WHERE k LIKE 'module\_%'")->fetchAll(PDO::FETCH_ASSOC);
$logMark17 = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM activity_logs')->fetchColumn();
$get17 = fn(string $pg) => http('GET', "$base/$pg", ['jar' => $admin]);
$pdo->exec('DELETE FROM rate_limits');

try {
    section('54. Modules: the registry and the helpers');
    same(module_for_page('tickets/ticket.php'), 'tickets', 'a page inside a module folder belongs to that module');
    same(module_for_page('monitoring/uptime.php'), 'uptime', 'the Uptime page belongs to uptime');
    same(module_for_page('monitoring/connections.php'), 'connections', 'the connections page belongs to connections');
    same(module_for_page('index.php'), null, 'the dashboard is core (no module)');
    same(module_for_page('companies/company.php'), null, 'a company page is core (no module)');
    same(module_enabled('companies'), true, 'a core key is always on');
    module_set($pdo, 'tickets', false);
    same(module_enabled('tickets'), false, 'module_set() off is seen at once');
    module_set($pdo, 'tickets', true);
    same(module_enabled('tickets'), true, 'module_set() on is seen at once');
    module_set($pdo, 'no_such_module_17', false);
    same((int) $pdo->query("SELECT COUNT(*) FROM app_meta WHERE k = 'module_no_such_module_17'")->fetchColumn(), 0, 'an unknown module key writes no row');
    $badPaths = [];
    foreach (MODULES as $key => $m) {
        foreach ($m['paths'] as $p) {
            $full = $root17 . '/' . $p;
            $ok = str_ends_with($p, '/') ? is_dir($full) : is_file($full);
            if (!$ok) { $badPaths[] = "$key: $p"; }
        }
    }
    same($badPaths, [], 'every module path exists: a file, or a folder where the path ends in /');

    section('55. Modules: a module that is off closes its pages');
    $pdo->exec('DELETE FROM rate_limits');
    module_set($pdo, 'tickets', false);
    $r = $get17('tickets/tickets.php');
    check($r['code'] === 404 && str_contains($r['body'], 'turned off'), 'tickets off: tickets/tickets.php is 404 with the "turned off" page', (string) $r['code']);
    $home = $get17('index.php');
    same($home['code'], 200, 'tickets off: the dashboard still opens');
    check(!str_contains($home['body'], 'href="tickets/tickets.php"'), 'tickets off: the menu has no Tickets item');
    same($get17('calendar/calendar.php')['code'], 200, 'modules are independent: the calendar is still 200 with tickets off');
    module_set($pdo, 'tickets', true);
    same($get17('tickets/tickets.php')['code'], 200, 'tickets on again: tickets/tickets.php is 200');
    check(str_contains($get17('index.php')['body'], 'href="tickets/tickets.php"'), 'tickets on again: the menu item is back');

    section('56. Modules: the settings tabs that belong to a module');
    $pdo->exec('DELETE FROM rate_limits');
    module_set($pdo, 'uptime', false);
    same($get17('settings/settings.php?tab=kuma')['code'], 404, 'uptime off: the Kuma settings tab is 404');
    $api = $get17('api/kuma_api.php');
    check($api['code'] === 404 && ($api['json']['error'] ?? '') === 'module_off', 'uptime off: api/kuma_api.php is 404 with {"error":"module_off"}', (string) $api['code']);
    module_set($pdo, 'uptime', true);
    module_set($pdo, 'calls', false);
    module_set($pdo, 'links', true);
    same($get17('settings/settings.php?tab=connectors')['code'], 200, 'calls off, links on: the Connectors tab is 200');
    module_set($pdo, 'links', false);
    same($get17('settings/settings.php?tab=connectors')['code'], 404, 'calls and links off: the Connectors tab is 404');

    section('57. Modules: the Modules tab and what it saves');
    $save17 = function (array $keys) use ($base, $admin): array {   // a fresh csrf token from the tab, then the save
        db()->exec('DELETE FROM rate_limits');
        $page = http('GET', "$base/settings/settings.php?tab=modules", ['jar' => $admin]);
        return http('POST', "$base/settings/settings.php", ['jar' => $admin, 'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query(['csrf' => csrf_from($page['body']), 'tab' => 'modules', 'modules' => $keys])]);
    };
    $tabPage = $get17('settings/settings.php?tab=modules');
    same($tabPage['code'], 200, 'the Modules tab opens for an admin');
    check(str_contains($tabPage['body'], 'name="modules[]"'), 'and it has a checkbox per module');
    $save17($keysNoBackups17);
    $st17 = module_states(true);
    same($st17['backups'], false, 'saving every module but Backups turns Backups off');
    same(array_keys(array_filter($st17, fn($v, $k) => $k !== 'backups' && $v === false, ARRAY_FILTER_USE_BOTH)), [], 'and every other module stays on');
    same($get17('system/backups.php')['code'], 404, 'Backups is 404 now');
    same((int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Modules changed' AND id > $logMark17")->fetchColumn() >= 1, true, 'the change is in the activity log as "Modules changed"');
    $save17($keys17);
    same(module_states(true)['backups'], true, 'saving every module turns Backups on again');
    same($get17('system/backups.php')['code'], 200, 'and the Backups page is 200 again');

    section('58. Modules: all off at once');
    foreach ($keys17 as $k) { module_set($pdo, $k, false); }
    $pdo->exec('DELETE FROM rate_limits');
    foreach (['index.php', 'companies/companies.php', 'users/users.php', 'search/search.php?q=a', 'settings/settings.php', 'notifications/notifications.php'] as $pg) {
        $r = $get17($pg);
        $phpError = str_contains($r['body'], 'Fatal error') || str_contains($r['body'], 'Warning</b>') || str_contains($r['body'], 'Undefined');
        check($r['code'] === 200 && !$phpError, "all modules off: $pg is 200 with no PHP error", (string) $r['code']);
    }
} finally {
    // put back the states the install had (no rows = on), whatever happened above
    $pdo->exec("DELETE FROM app_meta WHERE k LIKE 'module\_%'");
    $ins17 = $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?)');
    foreach ($saved17 as $row) { $ins17->execute([$row['k'], $row['v']]); }
    module_states(true);
    $pdo->exec("DELETE FROM activity_logs WHERE action = 'Modules changed' AND id > $logMark17");
    $pdo->exec('DELETE FROM rate_limits');
}
