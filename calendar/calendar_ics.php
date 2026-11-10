<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/appointments.php';
require_login();
$pdo = db();

// ?id= one appointment, or ?company= every appointment of that company from 30 days ago on
if (isset($_GET['id'])) {
    $one = appointment_find($pdo, (int) $_GET['id']);
    if (!$one) { http_response_code(404); exit('That appointment does not exist.'); }
    $rows = [$one]; $name = $one['title']; $file = 'appointment-' . (int) $one['id'];
} else {
    $cid = (int) ($_GET['company'] ?? 0);
    $c = $pdo->prepare('SELECT name FROM companies WHERE id = ?'); $c->execute([$cid]);
    $cn = $c->fetchColumn();
    if ($cn === false) { http_response_code(404); exit('That company does not exist.'); }
    $rows = appointments_between($pdo, date('Y-m-d', strtotime('-30 days')) . ' 00:00:00', '2101-01-01 00:00:00', $cid);
    $name = $cn . ' appointments'; $file = 'appointments-' . $cid;
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '.ics"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo appointments_ics($rows, $name);
