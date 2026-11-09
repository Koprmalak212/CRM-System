<?php
declare(strict_types=1);
// Runs the checks that are due. Schedule it every minute with Windows Task Scheduler:
//   C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\check_endpoints.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/monitor.php';

$pdo = db();
$due = $pdo->query("SELECT * FROM monitored_endpoints WHERE enabled = 1 AND (last_checked_at IS NULL OR last_checked_at <= NOW() - INTERVAL interval_min MINUTE) ORDER BY last_checked_at IS NULL DESC, last_checked_at LIMIT 20")->fetchAll();
foreach ($due as $ep) {
    $r = run_check($ep);
    record_check($pdo, $ep, $r);
    echo str_pad($ep['name'], 28) . ' ' . strtoupper($r['state']) . ($r['latency'] !== null ? ' ' . $r['latency'] . ' ms' : '') . ($r['error'] !== '' ? ' (' . $r['error'] . ')' : '') . "\n";
}
echo count($due) . " endpoint(s) checked.\n";
