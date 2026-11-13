<?php
declare(strict_types=1);

/*
 * Kuma connection settings, as JSON. Admin only, session authenticated, CSRF protected.
 *   GET     -> { kumaUrl, pollIntervalSeconds, hasApiKey, keyLast4 }          (the key is never returned)
 *   PUT     -> save (blank apiKey keeps the stored key)
 *   DELETE  -> remove the stored key
 *   POST    -> action=test (does not save) | action=save | action=remove     (for clients that can only POST)
 */
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/kuma.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function kuma_json(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['admin'])) { kuma_json(401, ['error' => 'not_signed_in']); }
require_login();                                       // fresh role check on every request
if (!is_admin()) {
    try { log_activity('warning', 'Access denied', 'api/kuma_api.php'); } catch (Throwable $e) { /* never block */ }
    kuma_json(403, ['error' => 'admin_only']);
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = $_POST;
if (in_array($method, ['PUT', 'DELETE'], true) || (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json'))) {
    $raw = (string) file_get_contents('php://input', false, null, 0, 8192);
    $j = json_decode($raw, true);
    if (is_array($j)) { $in = $j; }
    else { parse_str($raw, $in); }
}
$action = $method === 'PUT' ? 'save' : ($method === 'DELETE' ? 'remove' : (string) ($in['action'] ?? ''));

if ($method === 'GET') { kuma_json(200, kuma_public_view(kuma_settings($pdo))); }

// Every state-changing request needs the CSRF token, in a header or in the body.
$sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf'] ?? ''));
if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
    try { log_activity('warning', 'CSRF check failed', 'api/kuma_api.php'); } catch (Throwable $e) { /* ignore */ }
    kuma_json(403, ['error' => 'csrf']);
}

$str = static fn($v): string => is_scalar($v) ? (string) $v : '';

if ($action === 'save') {
    [$ok, $errors] = kuma_save($pdo, $str($in['kumaUrl'] ?? ''), $str($in['apiKey'] ?? ''), $str($in['pollIntervalSeconds'] ?? ''), (int) $_SESSION['admin']['id']);
    if (!$ok) { kuma_json(422, ['error' => 'validation', 'errors' => $errors]); }
    log_activity('info', 'Kuma settings saved', 'url and interval');   // never the key
    kuma_json(200, ['ok' => true] + kuma_public_view(kuma_settings($pdo)));
}

if ($action === 'remove') {
    kuma_remove_key($pdo, (int) $_SESSION['admin']['id']);
    log_activity('warning', 'Kuma API key removed', '');
    kuma_json(200, ['ok' => true] + kuma_public_view(kuma_settings($pdo)));
}

if ($action === 'test') {
    [$allowed] = rate_hit('kumatest', 10, 60);        // outbound requests get their own limit
    if (!$allowed) { kuma_json(429, ['ok' => false, 'result' => 'rate_limited', 'message' => 'Too many tests. Wait a minute.']); }
    $stored = kuma_settings($pdo);
    $url = $str($in['kumaUrl'] ?? '') !== '' ? $str($in['kumaUrl']) : $stored['url'];
    $key = trim($str($in['apiKey'] ?? ''));
    // Blank means "use the saved key", but only for the saved address: otherwise an admin (or a forged request) could name any
    // address and make the server send the stored key to it.
    $sameUrl = $stored['url'] !== '' && rtrim($url, '/') === rtrim((string) $stored['url'], '/');
    if ($key === '' && $stored['has_key'] && $sameUrl) { $key = secret_decrypt($stored['key_enc']); }
    [$okUrl, $urlMsg] = kuma_validate_url($url);
    if (!$okUrl) { kuma_json(200, ['ok' => false, 'result' => 'invalid_url', 'message' => $urlMsg]); }
    if ($key === '') { kuma_json(200, ['ok' => false, 'result' => 'no_key', 'message' => 'Enter the API key to test.']); }

    $r = kuma_fetch($url, $key);
    $messages = [
        'unauthorized' => 'Unauthorized: Kuma rejected the API key.',
        'unreachable' => 'Unreachable: Kuma did not answer in time, or refused the connection.',
        'invalid' => 'Invalid response: the address answered, but not with Kuma metrics. Check the URL.',
    ];
    if ($r['state'] !== 'ok') { kuma_json(200, ['ok' => false, 'result' => $r['state'], 'message' => $messages[$r['state']]]); }
    $n = count(kuma_parse_metrics($r['body']));
    kuma_json(200, ['ok' => true, 'result' => 'ok', 'message' => 'Connected. Found ' . $n . ' monitor' . ($n === 1 ? '' : 's') . '.', 'monitors' => $n]);
}

kuma_json(400, ['error' => 'unknown_action']);
