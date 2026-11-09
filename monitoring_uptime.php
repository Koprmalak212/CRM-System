<?php
declare(strict_types=1);

/* GET monitoring_uptime.php: Kuma monitor health for the dashboard. Same session auth as the other dashboard pages.
 * The browser only ever talks to this file, never to Kuma. */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/kuma.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['admin'])) { http_response_code(401); echo json_encode(['error' => 'not_signed_in']); exit; }
require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET'); echo json_encode(['error' => 'method_not_allowed']); exit; }

try {
    $pdo = db();
    if (isset($_GET['monitor'])) {   // detail of one monitor: response series and its status changes
        echo json_encode(kuma_monitor_detail($pdo, (string) $_GET['monitor']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (isset($_GET['events'])) {    // latest status changes across all monitors
        echo json_encode(['events' => kuma_recent_events($pdo)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(kuma_uptime($pdo), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Kuma monitoring failed: ' . get_class($e));   // class only: never the message, which could carry upstream details
    echo json_encode(['error' => 'kuma_unreachable']);
}
