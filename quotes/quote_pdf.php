<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/quotes.php';
require __DIR__ . '/../includes/modules/pdf.php';
require_login();
$pdo = db();

$q = quote_find($pdo, (int) ($_GET['id'] ?? 0));
if (!$q) {
    http_response_code(404);
    exit('That quote does not exist.');
}
$co = require __DIR__ . '/../includes/modules/company.php';

$bytes = invoice_pdf($q, quote_items($pdo, (int) $q['id']), $co, true);
log_activity('info', 'Quote PDF downloaded', $q['number']);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="quote-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $q['number']) . '.pdf"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $bytes;
