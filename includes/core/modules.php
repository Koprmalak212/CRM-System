<?php
declare(strict_types=1);

/*
 * Modules: the features a client can turn on or off (Settings > Modules, admins only).
 *
 * - A module that is off: its pages answer 404, its menu item, panels, search results, notifications and background jobs disappear.
 *   Its data is kept, so turning it on again brings everything back.
 * - Modules do not depend on each other: any combination works. Users, companies, notes, search, notifications and settings are
 *   the core and are always on.
 * - The state is one row per module in app_meta ("module_<key>" = 1 or 0). No row means on, so an existing install changes nothing.
 *
 * In code:   if (module_enabled('tickets')) { ... }        around anything that belongs to a module but lives on a shared page
 *            'paths' below                                 closes the module's own pages; nothing has to be added to those pages
 */
const MODULES = [
    'calendar' => ['label' => 'Calendar', 'desc' => 'Appointments per company and person, month grid, agenda and iCalendar download.',
        'paths' => ['calendar/']],
    'tickets' => ['label' => 'Tickets', 'desc' => 'Support tickets with status, priority, owner and comments.',
        'paths' => ['tickets/']],
    'invoices' => ['label' => 'Invoices', 'desc' => 'Invoices, payments, PDF and recurring invoices.',
        'paths' => ['invoices/', 'bin/run_recurring.php']],
    'calls' => ['label' => 'Calls & messages', 'desc' => 'Calls and messages per customer from 3CX, Yeastar and Twilio.',
        'paths' => ['calls/']],
    'links' => ['label' => 'Short links', 'desc' => 'Short links per company from Short.io.',
        'paths' => ['links/']],
    'connections' => ['label' => 'Connections', 'desc' => 'The list of customer connections and their status.',
        'paths' => ['monitoring/connections.php']],
    'uptime' => ['label' => 'Uptime', 'desc' => 'The Uptime page and the Uptime Kuma connection.',
        'paths' => ['monitoring/uptime.php', 'monitoring/monitoring_uptime.php', 'api/kuma_api.php', 'bin/kuma_poll.php']],
    'health' => ['label' => 'Health checks & API', 'desc' => 'Your own endpoint checks, the health API and its API keys (Settings > Integrations).',
        'paths' => ['api/health.php', 'api/api_key_reveal.php', 'bin/check_endpoints.php']],
    'reports' => ['label' => 'Reports', 'desc' => 'Generated reports and their downloads.',
        'paths' => ['system/reports.php', 'system/report_download.php']],
    'backups' => ['label' => 'Backups', 'desc' => 'The backups page.',
        'paths' => ['system/backups.php']],
    'logs' => ['label' => 'Activity logs', 'desc' => 'The page that shows the audit trail. Activity is still recorded while this is off.',
        'paths' => ['system/logs.php']],
];

/** key => on/off for every module, read once per request. A database problem (for example before setup) counts as "everything on". */
function module_states(bool $fresh = false): array
{
    static $states = null;
    if ($states === null || $fresh) {
        $states = array_fill_keys(array_keys(MODULES), true);
        try {
            foreach (db()->query("SELECT k, v FROM app_meta WHERE k LIKE 'module\\_%'") as $r) {
                $key = substr((string) $r['k'], 7);
                if (isset($states[$key])) { $states[$key] = $r['v'] !== '0'; }
            }
        } catch (Throwable $e) { error_log('Module states unavailable: ' . $e->getMessage()); }
    }
    return $states;
}

/** Whether a module is on. A key that is not a module (overview, users, companies, settings) is core and always on. */
function module_enabled(string $key): bool
{
    return module_states()[$key] ?? true;
}

/** True when at least one of the given modules is on. */
function module_any(string ...$keys): bool
{
    foreach ($keys as $k) { if (module_enabled($k)) { return true; } }
    return false;
}

function module_set(PDO $pdo, string $key, bool $on): void
{
    if (!isset(MODULES[$key])) { return; }
    $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute(['module_' . $key, $on ? '1' : '0']);
    module_states(true);
}

/** The module a page belongs to ("tickets/ticket.php" => "tickets"), or null for a core page. */
function module_for_page(string $page): ?string
{
    foreach (MODULES as $key => $m) {
        foreach ($m['paths'] as $p) {
            if ($page === $p || (str_ends_with($p, '/') && str_starts_with($page, $p))) { return $key; }
        }
    }
    return null;
}

/** Stops the request when the module is off: a 404 page in the browser, a short line on the command line. */
function require_module(string $key): void
{
    if (module_enabled($key)) { return; }
    $label = MODULES[$key]['label'] ?? $key;
    if (PHP_SAPI === 'cli') { echo 'The ' . $label . " module is turned off (Settings, Modules).\n"; exit(0); }
    http_response_code(404);
    if (str_starts_with(current_page(), 'api/')) { header('Content-Type: application/json'); echo json_encode(['error' => 'module_off', 'module' => $key]); exit; }
    header('Content-Type: text/html; charset=utf-8');
    echo plain_page('Not available', 'This part is turned off', 'The ' . htmlspecialchars($label, ENT_QUOTES) . ' module is not in use here. An admin can turn it on under Settings, Modules.', '<p><a href="index.php">Back to the dashboard</a></p>');
    exit;
}

/** Called once by bootstrap.php: closes the running page when it belongs to a module that is off. */
function module_guard(): void
{
    $key = module_for_page(current_page());
    if ($key !== null) { require_module($key); }
}
