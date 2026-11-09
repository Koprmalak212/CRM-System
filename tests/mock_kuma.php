<?php
declare(strict_types=1);
// A stand-in for Uptime Kuma, used only by the tests:  php -S 127.0.0.1:3199 tests/mock_kuma.php
// The API key (Basic auth password) picks the behaviour, so tests can exercise every outcome.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$mode = is_file(__DIR__ . '/.mock_mode') ? trim((string) file_get_contents(__DIR__ . '/.mock_mode')) : '';
if ($path === '/hits') { header('Content-Type: text/plain'); echo (int) @file_get_contents(__DIR__ . '/.mock_hits'); return; }
if ($path !== '/metrics') { http_response_code(404); echo 'not found'; return; }
file_put_contents(__DIR__ . '/.mock_hits', (string) (1 + (int) @file_get_contents(__DIR__ . '/.mock_hits')));

$user = $_SERVER['PHP_AUTH_USER'] ?? null;
$pass = $_SERVER['PHP_AUTH_PW'] ?? null;
if ($mode === 'down') { http_response_code(500); echo 'internal error: secret stack trace'; return; }
if ($mode === 'unauth' || $user !== '' || $pass === null) { http_response_code(401); header('WWW-Authenticate: Basic realm="Uptime Kuma"'); echo 'unauthorized'; return; }
if ($pass === 'htmlkey') { header('Content-Type: text/html'); echo '<!doctype html><html><body>Login</body></html>'; return; }
if ($pass === 'errkey') { http_response_code(500); echo 'boom'; return; }
if ($pass === 'slowkey') { sleep(6); }
if (!str_starts_with($pass, 'goodkey') && $pass !== 'slowkey') { http_response_code(401); header('WWW-Authenticate: Basic realm="Uptime Kuma"'); echo 'unauthorized'; return; }
header('Content-Type: text/plain; version=0.0.4');
readfile(__DIR__ . '/fixtures/kuma_metrics.txt');
