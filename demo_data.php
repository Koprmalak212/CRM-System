<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';   // also loads includes/demo.php
$admin = require_login();
if (!is_admin()) { deny_access('demo_data.php'); }   // loading or removing sample data is an admin action

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('index.php'); }
csrf_check();
$pdo = db();
[$ok] = rate_hit('demo', 6, 60);
if (!$ok) { flash('Too many attempts. Wait a minute and try again.', 'bad'); redirect('index.php#demo-data'); }

$label = static function (array $counts): string {
    $parts = [];
    foreach (DEMO_TABLES as $t => $name) {
        if (($counts[$t] ?? 0) > 0) { $parts[] = number_format($counts[$t]) . ' ' . strtolower($name); }
    }
    return $parts ? implode(', ', $parts) : 'nothing';
};

$action = (string) ($_POST['action'] ?? '');
try {
    if ($action === 'seed') {
        $counts = demo_seed($pdo);
        log_activity('warning', 'Demo data loaded', number_format(array_sum($counts)) . ' rows');
        flash('Demo data loaded: ' . $label($counts) . '. Everything you entered yourself is unchanged.');
    } elseif ($action === 'remove') {
        if (($_POST['confirm'] ?? '') !== 'yes') { flash('Confirm the removal first.', 'bad'); redirect('index.php#demo-data'); }
        $res = demo_remove($pdo);
        $n = array_sum($res['deleted']);
        log_activity('warning', 'Demo data removed', number_format($n) . ' rows');
        flash('Demo data removed (' . number_format($n) . ' records). Your own data was not touched.'
            . ($res['kept_invoices'] > 0 ? ' ' . $res['kept_invoices'] . ' demo invoice' . ($res['kept_invoices'] === 1 ? ' was' : 's were') . ' kept because you recorded real payments on them.' : ''));
    }
} catch (RuntimeException $e) {
    flash($e->getMessage(), 'bad');   // for example "Demo data is already loaded."
}
redirect('index.php#demo-data');
