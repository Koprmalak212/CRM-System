<?php
declare(strict_types=1);

// Contracts: creates the invoices that are due, renews contracts that renew by themselves and ends the ones that are over.
// For a scheduled task on a real server (once a day is plenty):
//   C:\xampp\php\php.exe bin/run_contracts.php
// It is safe to run as often as you like: a period is never invoiced twice. Not available from the web.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('API_REQUEST', true);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/contracts.php';

$r = contracts_run_due(db());
echo count($r['invoices']) . ' invoice(s) created' . ($r['invoices'] ? ': ' . implode(', ', array_column($r['invoices'], 1)) : '') . ', ' . $r['renewed'] . ' contract(s) renewed, ' . $r['ended'] . " ended.\n";
if (!module_enabled('invoices')) { echo "The Invoices module is turned off, so no invoices are made from contracts.\n"; }
