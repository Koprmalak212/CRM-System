<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/invoices.php';
require __DIR__ . '/includes/pdf.php';
require_login();
$pdo = db();

$s = $pdo->prepare('SELECT * FROM (' . invoice_base_sql() . ') t WHERE id = ?');
$s->execute([(int) ($_GET['id'] ?? 0)]);
$inv = $s->fetch();
if (!$inv) {
    http_response_code(404);
    exit('That invoice does not exist.');
}
$items = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id');
$items->execute([$inv['id']]);
$co = require __DIR__ . '/includes/company.php';

$bytes = invoice_pdf($inv, $items->fetchAll(), $co);
log_activity('info', 'Invoice PDF downloaded', $inv['number']);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="invoice-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $inv['number']) . '.pdf"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $bytes;
