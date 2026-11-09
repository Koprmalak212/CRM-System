<?php
declare(strict_types=1);

/*
 * Read-only health API.   GET /api/health.php   Authorization: Bearer tlk_...
 * Stateless: no session, no cookies. Keys come from Settings > Integrations and are stored only as SHA-256 hashes.
 */
define('API_REQUEST', true);
require __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_fail(int $code, string $message, array $extra = []): never
{
    http_response_code($code);
    foreach ($extra as $h) { header($h); }
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    api_fail(405, 'Only GET is supported.', ['Allow: GET, HEAD']);
}

// Keys are only accepted in the Authorization header, never in the URL (URLs end up in logs and browser history).
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('apache_request_headers')) {   // Apache can hide the header from $_SERVER
    foreach (apache_request_headers() as $hn => $hv) { if (strcasecmp((string) $hn, 'Authorization') === 0) { $auth = (string) $hv; } }
}
$key = preg_match('/^Bearer\s+(tlk_[a-f0-9]{48})$/', $auth, $m) ? $m[1] : '';

$pdo = db();
$row = null;
if ($key !== '') {
    $s = $pdo->prepare('SELECT id, scope FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL');
    $s->execute([hash('sha256', $key)]);
    $row = $s->fetch() ?: null;
}

if (!$row || $row['scope'] !== 'health:read') {
    // Failed attempts are throttled harder than normal traffic, and logged once per minute per address.
    [$ok, $retry, $hits] = rate_hit('apiauth', 10, 60);
    if ($hits === 1) {
        try { log_activity('warning', 'API key rejected', $key === '' ? 'missing or malformed key' : 'unknown or revoked key', 'api'); } catch (Throwable $e) { /* ignore */ }
    }
    if (!$ok) { api_fail(429, 'Too many failed attempts.', ['Retry-After: ' . $retry]); }
    api_fail(401, 'A valid API key is required.', ['WWW-Authenticate: Bearer realm="health"']);
}

[$ok, $retry] = rate_hit('k' . (int) $row['id'], 60, 60);   // 60 calls a minute per key and address
if (!$ok) { api_fail(429, 'Rate limit exceeded for this key.', ['Retry-After: ' . $retry]); }
$pdo->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 1 MINUTE)')->execute([$row['id']]);

$endpoints = [];
foreach ($pdo->query('SELECT name, last_state, last_status_code, last_latency_ms, last_checked_at FROM monitored_endpoints WHERE enabled = 1 ORDER BY name') as $e) {
    $endpoints[] = [
        'name' => $e['name'], 'state' => $e['last_state'], 'status_code' => $e['last_status_code'] === null ? null : (int) $e['last_status_code'],
        'latency_ms' => $e['last_latency_ms'] === null ? null : (int) $e['last_latency_ms'],
        'checked_at' => $e['last_checked_at'] ? date('c', strtotime($e['last_checked_at'])) : null,
    ];
}
$services = [];
foreach ($pdo->query('SELECT s.name, c.status FROM services s JOIN service_checks c ON c.service_id = s.id AND c.slot = 35 ORDER BY s.sort_order') as $s) {
    $services[] = ['name' => $s['name'], 'status' => $s['status'] === 'ok' ? 'operational' : $s['status']];
}

$down = count(array_filter($endpoints, fn($e) => $e['state'] === 'down')) + count(array_filter($services, fn($s) => $s['status'] === 'outage'));
$degraded = count(array_filter($endpoints, fn($e) => $e['state'] === 'slow')) + count(array_filter($services, fn($s) => $s['status'] === 'degraded'));

$out = [
    'status' => $down > 0 ? 'down' : ($degraded > 0 ? 'degraded' : 'ok'),
    'generated_at' => date('c'),
    'endpoints' => $endpoints,
    'services' => $services,
];
if (demo_loaded()) { $out['demo_data'] = ['services' => true, 'note' => 'The services list is seeded demo data. Endpoint results come from real checks.']; }
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
