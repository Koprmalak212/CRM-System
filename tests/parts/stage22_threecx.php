<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// 3CX sign-in: an API client, or a Management Console login (a system administrator's own user name and password), with a clear reason
// when both are refused. Uses a stand-in 3CX on port 3198, which must be free. Included by tests/run.php inside its try block.
require_once TESTS_DIR . '/../includes/modules/connectors.php';
$proc22 = proc_open([PHP_BINARY, '-S', '127.0.0.1:3198', TESTS_DIR . '/mock_3cx.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/mock_3cx.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/mock_3cx.log', 'a']], $pipes22);
try {
    for ($i = 0; $i < 30; $i++) { $c = @stream_socket_client('tcp://127.0.0.1:3198', $en, $es, 1); if ($c) { fclose($c); break; } usleep(200000); }
    section('74. 3CX: the sign-in');
    $try22 = function (string $mode, string $id, string $pw): array { return connector_test('threecx', ['base_url' => "http://127.0.0.1:3198/$mode", 'verify_tls' => 1, 'account_ref' => $id, 'provider' => 'threecx'], $pw); };
    same($try22('api', 'apiclient', 'apisecret')[0], 'ok', 'an API client (Client ID and secret) connects');
    [$s, $m] = $try22('admin', 'sysadmin', 'adminpass');
    check($s === 'ok' && str_contains($m, 'Connected to 3CX'), 'a system administrator\'s own user name and password connect, typed in the same two fields');
    [$s, $m] = $try22('api', 'apiclient', 'wrong');
    check($s === 'unauthorized' && str_contains($m, 'refused both') && str_contains($m, 'invalid_client'), 'a wrong secret says that both ways were refused and shows the error code the PBX gave', $m);
    [$s, $m] = $try22('admin', 'sysadmin', 'bad');
    check($s === 'unauthorized' && str_contains($m, 'refused both'), 'a wrong password is refused with the same explanation');
    [$s, $m] = $try22('2fa', 'sysadmin', 'adminpass');
    check($s === 'unauthorized' && str_contains($m, 'two-step sign-in'), 'a login that has two-step sign-in turned on is explained, not just "rejected"');
    same($try22('api', 'apiclient', "x\r\nInjected: 1")[0], 'unauthorized', 'a secret with a line break in it is refused and breaks nothing');
    same(connector_test('threecx', ['base_url' => 'http://127.0.0.1:3197', 'verify_tls' => 1, 'account_ref' => 'a', 'provider' => 'threecx'], 'b')[0], 'unreachable', 'a PBX that does not answer is reported as unreachable, with no second try');
    check(!str_contains(json_encode($try22('api', 'apiclient', 'secret-DO-NOT-SHOW')), 'DO-NOT-SHOW'), 'the message never contains the secret');
} finally {
    if (is_resource($proc22)) { proc_terminate($proc22); proc_close($proc22); }
}
