<?php
declare(strict_types=1);

/*
 * A tiny SMTP server for tests/run.php: php tests/mock_smtp.php 3225
 * It accepts one connection at a time, speaks just enough SMTP (EHLO, AUTH LOGIN, MAIL, RCPT, DATA, QUIT) and appends what it received
 * to tests/.smtp_log as one JSON line per message. tests/.smtp_mode can hold "authfail" or "rcptfail" to make it refuse.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$port = (int) ($argv[1] ?? 3225);
$log = __DIR__ . '/.smtp_log';
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "cannot listen: $errstr\n"); exit(1); }

while ($c = @stream_socket_accept($srv, 600)) {
    stream_set_timeout($c, 5);
    $mode = is_file(__DIR__ . '/.smtp_mode') ? trim((string) file_get_contents(__DIR__ . '/.smtp_mode')) : '';
    $m = ['user' => null, 'pass' => null, 'from' => null, 'rcpt' => [], 'data' => null];
    $say = fn(string $s) => fwrite($c, $s . "\r\n");
    $say('220 mock ESMTP');
    $state = '';
    while (($line = fgets($c, 8192)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($state === 'user') { $m['user'] = base64_decode($line); $say('334 UGFzc3dvcmQ6'); $state = 'pass'; continue; }
        if ($state === 'pass') { $m['pass'] = base64_decode($line); $state = ''; $say($mode === 'authfail' ? '535 5.7.8 Authentication failed' : '235 2.7.0 Accepted'); continue; }
        if ($state === 'data') {
            $buf = $m['data'] ?? '';
            if ($line === '.') { $state = ''; $say('250 2.0.0 queued'); file_put_contents($log, json_encode($m) . "\n", FILE_APPEND); continue; }
            $m['data'] = $buf . (str_starts_with($line, '..') ? substr($line, 1) : $line) . "\r\n";
            continue;
        }
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') { $say('250-mock'); $say('250-AUTH LOGIN'); $say('250 8BITMIME'); }
        elseif (str_starts_with(strtoupper($line), 'AUTH LOGIN')) { $say('334 VXNlcm5hbWU6'); $state = 'user'; }
        elseif ($cmd === 'MAIL') { $m['from'] = trim(substr($line, 10), '<> '); $say('250 OK'); }
        elseif ($cmd === 'RCPT') { if ($mode === 'rcptfail') { $say('550 5.1.1 No such user'); } else { $m['rcpt'][] = trim(substr($line, 8), '<> '); $say('250 OK'); } }
        elseif ($cmd === 'DATA') { $say('354 go ahead'); $state = 'data'; $m['data'] = ''; }
        elseif ($cmd === 'QUIT') { $say('221 bye'); break; }
        else { $say('500 unknown'); }
    }
    fclose($c);
}
