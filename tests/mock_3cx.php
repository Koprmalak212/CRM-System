<?php
// A stand-in 3CX for tests/run.php: php -S 127.0.0.1:3198 tests/mock_3cx.php   The first part of the path picks the behaviour: /api accepts an API client only, /admin a Management Console login only, /2fa a login that wants a security code, /none neither.
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
preg_match('#^/(api|admin|2fa|none)(/.*)$#', $u, $m); $mode = $m[1] ?? 'none'; $path = $m[2] ?? $u;
$body = (string) file_get_contents('php://input');
header('Content-Type: application/json');
if ($path === '/connect/token') {
    parse_str($body, $f);
    if ($mode === 'api' && ($f['client_id'] ?? '') === 'apiclient' && ($f['client_secret'] ?? '') === 'apisecret') { echo json_encode(['access_token' => 'tok-api', 'token_type' => 'Bearer']); exit; }
    http_response_code(400); echo json_encode(['error' => 'invalid_client']); exit;
}
if ($path === '/webclient/api/Login/GetAccessToken') {
    if ($mode === 'none' || $mode === 'api') { http_response_code(404); exit; }
    $j = json_decode($body, true);
    if ($mode === '2fa') { echo json_encode(['Status' => 'RequireSecurityCode']); exit; }
    if (($j['Username'] ?? '') === 'sysadmin' && ($j['Password'] ?? '') === 'adminpass') { echo json_encode(['Status' => 'AuthSuccess', 'Token' => ['token_type' => 'Bearer', 'access_token' => 'tok-admin']]); exit; }
    echo json_encode(['Status' => 'AuthFailed']); exit;
}
if ($path === '/xapi/v1/CallHistoryView') {
    $ok = in_array($_SERVER['HTTP_AUTHORIZATION'] ?? '', ['Bearer tok-api', 'Bearer tok-admin'], true);
    if (!$ok) { http_response_code(401); exit; }
    echo json_encode(['value' => []]); exit;
}
http_response_code(404);
