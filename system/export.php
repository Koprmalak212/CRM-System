<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/csv.php';
require_login();
$type = (string) ($_GET['type'] ?? '');
if (!is_admin()) { deny_access('export:' . preg_replace('/[^a-z]/', '', $type)); }   // personal data: admins only
if (!isset(CSV_TYPES[$type])) {
    http_response_code(404);
    exit('Unknown export. Choose users, companies, invoices or tickets.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '-' . date('Ymd') . '.csv"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = fopen('php://output', 'w');
$n = csv_export_write(db(), $type, $out);
fclose($out);
log_activity('info', 'Data exported', $type . ' · ' . $n . ' rows');
