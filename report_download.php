<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$stmt = db()->prepare("SELECT * FROM reports WHERE id = ? AND status = 'ready'");
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$r = $stmt->fetch();
if (!$r) {
    http_response_code(404);
    exit('That report is not available.');
}

$slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $r['title']) ?? 'report'), '-');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $slug . '.csv"');

$cell = static fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@	") !== false) ? "'" . $v : $v;   // stop spreadsheet formula injection
$out = fopen('php://output', 'w');
fputcsv($out, ['report', 'type', 'created_by', 'generated_at', 'note']);
fputcsv($out, array_map($cell, [$r['title'], $r['type'], $r['author'], $r['generated_at'], 'Demo data. Seeded for display purposes.']));
fputcsv($out, []);
fputcsv($out, ['metric', 'value']);
mt_srand((int) $r['id']);
foreach (['Total accounts', 'Active accounts', 'Failed sign-ins', 'Sessions terminated', 'Backups completed'] as $m) {
    fputcsv($out, [$m, mt_rand(12, 980)]);
}
fclose($out);
