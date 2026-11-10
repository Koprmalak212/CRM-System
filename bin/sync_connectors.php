<?php
declare(strict_types=1);
// Fetches calls, messages and short links from every connected service (Settings, Connectors) and stores them. Schedule it every 5 minutes:
//   C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\sync_connectors.php          (Linux cron: */5 * * * * php /var/www/crm/bin/sync_connectors.php)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/connectors.php';
$pdo = db();
$any = false;
foreach (array_keys(connector_defs()) as $p) {
    $c = connector_get($pdo, $p);
    if (!$c['enabled'] || !connector_has_secret($c)) { continue; }
    $any = true;
    $r = connector_sync($pdo, $p);
    echo $p . ': ' . $r['state'] . ', ' . $r['count'] . " records\n";
}
if (!$any) { echo "No connector is turned on (Settings, Connectors).\n"; }
