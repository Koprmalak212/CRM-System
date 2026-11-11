<?php
declare(strict_types=1);

/*
 * A tiny IMAP server for tests/run.php: php tests/mock_imap.php 3243
 * It serves the files tests/.imap_box/<uid>.eml as the messages of the mailbox, speaks just enough IMAP (LOGIN, SELECT, UID SEARCH UNSEEN,
 * UID FETCH with BODY.PEEK[], UID STORE +FLAGS (\Seen), LOGOUT) and remembers which messages were marked as read in tests/.imap_seen.
 * The only password it accepts is "goodpass".
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$port = (int) ($argv[1] ?? 3243);
$box = __DIR__ . '/.imap_box';
$seenFile = __DIR__ . '/.imap_seen';
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "cannot listen: $errstr\n"); exit(1); }

$unquote = fn(string $s): string => stripcslashes(trim($s, '"'));
while ($c = @stream_socket_accept($srv, 600)) {
    stream_set_timeout($c, 5);
    $say = fn(string $s) => fwrite($c, $s . "\r\n");
    $say('* OK mock IMAP ready');
    $auth = false;
    while (($line = fgets($c, 8192)) !== false) {
        $line = rtrim($line, "\r\n");
        if (!preg_match('/^(\S+) (.+)$/', $line, $m)) { continue; }
        [, $tag, $cmd] = $m;
        $seen = is_file($seenFile) ? (array) json_decode((string) file_get_contents($seenFile), true) : [];
        if (preg_match('/^LOGIN ("(?:[^"\\\\]|\\\\.)*"|\S+) ("(?:[^"\\\\]|\\\\.)*"|\S+)$/i', $cmd, $a)) {
            $auth = $unquote($a[2]) === 'goodpass';
            $say($auth ? "$tag OK LOGIN completed" : "$tag NO [AUTHENTICATIONFAILED] Invalid credentials");
        } elseif (!$auth && stripos($cmd, 'LOGOUT') !== 0) { $say("$tag BAD not signed in");
        } elseif (stripos($cmd, 'SELECT') === 0) {
            $say(str_contains($cmd, 'Missing') ? "$tag NO no such mailbox" : '* ' . count(glob($box . '/*.eml') ?: []) . " EXISTS\r\n$tag OK [READ-WRITE] SELECT completed");
        } elseif (strcasecmp($cmd, 'UID SEARCH UNSEEN') === 0) {
            $uids = [];
            foreach (glob($box . '/*.eml') ?: [] as $f) { $u = (int) basename($f, '.eml'); if (!in_array($u, $seen, true)) { $uids[] = $u; } }
            sort($uids);
            $say('* SEARCH' . ($uids ? ' ' . implode(' ', $uids) : ''));
            $say("$tag OK SEARCH completed");
        } elseif (preg_match('/^UID FETCH (\d+) \(BODY\.PEEK\[\]<0\.(\d+)>\)$/i', $cmd, $a)) {
            $f = $box . '/' . (int) $a[1] . '.eml';
            if (is_file($f)) { $data = substr((string) file_get_contents($f), 0, (int) $a[2]); fwrite($c, '* ' . (int) $a[1] . ' FETCH (UID ' . (int) $a[1] . ' BODY[]<0> {' . strlen($data) . "}\r\n" . $data . ")\r\n"); }
            $say("$tag OK FETCH completed");
        } elseif (preg_match('/^UID STORE (\d+) \+FLAGS \(\\\\Seen\)$/i', $cmd, $a)) {
            $seen[] = (int) $a[1];
            file_put_contents($seenFile, json_encode(array_values(array_unique($seen))));
            $say("$tag OK STORE completed");
        } elseif (stripos($cmd, 'LOGOUT') === 0) { $say('* BYE'); $say("$tag OK LOGOUT completed"); break;
        } else { $say("$tag BAD unknown command"); }
    }
    fclose($c);
}
