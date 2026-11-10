<?php
declare(strict_types=1);

// Creates the recurring invoices that are due. For a scheduled task on a real server (once a day is plenty):
//   C:\xampp\php\php.exe run_recurring.php
// It is safe to run as often as you like: a period is never invoiced twice. Not available from the web.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('API_REQUEST', true);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/recurring.php';

$r = recurring_run_due(db());
echo count($r['created']) . " invoice(s) created" . ($r['created'] ? ': ' . implode(', ', array_column($r['created'], 1)) : '') . ".\n";
