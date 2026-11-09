<?php
declare(strict_types=1);
// Keeps the Uptime history continuous when nobody has the dashboard open. Schedule it every minute with Windows Task Scheduler:
//   C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\kuma_poll.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/kuma.php';
$r = kuma_uptime(db());
echo isset($r['error']) ? 'Kuma: ' . $r['error'] . "\n" : 'Kuma: ' . $r['summary']['total'] . " monitors, " . $r['summary']['down'] . " down\n";
