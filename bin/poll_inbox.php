<?php
declare(strict_types=1);

// Email to ticket: reads the support mailbox (Settings, Tickets) and turns new mail into tickets and replies. Schedule it every 2 to 5 minutes:
//   C:\xampp\php\php.exe C:\xampp\htdocs\Websites\test\bin\poll_inbox.php          (Linux cron: */3 * * * * php /var/www/crm/bin/poll_inbox.php)
// Safe to run as often as you like: a message is never handled twice. Not available from the web.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('API_REQUEST', true);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/inbound.php';

$r = inbox_run(db());
if ($r['state'] === 'off') { echo "Email to ticket is turned off (Settings, Tickets).\n"; exit(0); }
if ($r['state'] === 'busy') { echo "Another run is still busy.\n"; exit(0); }
if ($r['state'] !== 'ok') { echo 'Mailbox not read: ' . $r['state'] . ($r['error'] !== '' ? ' (' . $r['error'] . ')' : '') . "\n"; exit(1); }
echo $r['new'] . ' new ticket(s), ' . $r['replies'] . ' reply(ies), ' . $r['ignored'] . " ignored.\n";
