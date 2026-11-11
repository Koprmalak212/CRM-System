<?php
declare(strict_types=1);

/*
 * Email (stage 8). Every message is written to the mail_outbox table first, then delivered if a way to deliver exists:
 *   - SMTP settings entered in Settings > Email: the message is sent through that server (own small SMTP client, no libraries)
 *   - no settings and a real server (not localhost): PHP's mail()
 *   - no settings on localhost (XAMPP has no mail server): it stays in the outbox, where an admin can read it and copy a link
 * The SMTP password is stored encrypted. Nothing typed by a visitor ever ends up in a header.
 */

if (!function_exists('secret_decrypt')) { require __DIR__ . '/monitor.php'; }

const MAIL_KEEP_DAYS = 30;
const MAIL_TOKEN_TEXT_KINDS = ['reset', 'invite'];   // messages that contain a one-time link: their text is wiped once they were really sent

/**
 * Optional settings from the environment or from includes/config.local.php, under these names:
 *   SMTP_SERVER, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, EMAIL_FROM, EMAIL_TARGET
 * Whatever is saved in Settings > Email wins; these only fill in what has not been saved there (a server install can keep the
 * password out of the database that way). Returns the values that are set, keyed host, port, username, password, from, target.
 */
function mail_env(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) { return $cache; }
    $file = dirname(__DIR__) . '/config.local.php';
    $cfg = is_file($file) ? (array) (include $file) : [];
    $pick = function (string $name) use ($cfg): ?string {
        $v = getenv($name);
        if ($v === false || $v === '') { $v = $cfg[$name] ?? $cfg[strtolower($name)] ?? null; }
        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    };
    $map = ['host' => 'SMTP_SERVER', 'port' => 'SMTP_PORT', 'username' => 'SMTP_USERNAME', 'password' => 'SMTP_PASSWORD', 'from' => 'EMAIL_FROM', 'target' => 'EMAIL_TARGET'];
    $out = [];
    foreach ($map as $k => $name) { if (($v = $pick($name)) !== null) { $out[$k] = $v; } }
    return $cache = $out;
}

function mail_settings(PDO $pdo): array
{
    $d = ['host' => '', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password_enc' => null, 'from_email' => '', 'from_name' => 'Telecomlijn Admin', 'base_url' => '', 'password_plain' => null, 'from_env' => []];
    try { $r = $pdo->query('SELECT * FROM mail_settings WHERE id = 1')->fetch(); } catch (Throwable $e) { $r = false; }
    $c = $r ? array_merge($d, $r) : $d;
    $env = mail_env();
    if ($c['host'] === '' && isset($env['host'])) {   // no server saved in Settings: use the environment's
        $c['host'] = $env['host']; $c['from_env'][] = 'SMTP_SERVER';
        if (isset($env['port']) && ctype_digit($env['port'])) { $c['port'] = (int) $env['port']; $c['from_env'][] = 'SMTP_PORT'; if ((int) $env['port'] === 465) { $c['encryption'] = 'ssl'; } }
        if ($c['username'] === '' && isset($env['username'])) { $c['username'] = $env['username']; $c['from_env'][] = 'SMTP_USERNAME'; }
        if (empty($c['password_enc']) && isset($env['password'])) { $c['password_plain'] = $env['password']; $c['from_env'][] = 'SMTP_PASSWORD'; }
    }
    if ($c['from_email'] === '' && isset($env['from'])) { $c['from_email'] = $env['from']; $c['from_env'][] = 'EMAIL_FROM'; }
    return $c;
}

function mail_is_local(): bool
{
    if (PHP_SAPI === 'cli') { return true; }
    return (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', (string) ($_SERVER['HTTP_HOST'] ?? ''));
}

/**
 * The address links in emails point to. Never taken from the request on a real server: a forged Host header could otherwise
 * make a password-reset email link to someone else's site. Returns null when no safe address is known.
 */
function mail_base_url(PDO $pdo): ?string
{
    $u = rtrim((string) mail_settings($pdo)['base_url'], '/');
    if ($u !== '') { return $u; }
    if (PHP_SAPI !== 'cli' && mail_is_local()) {
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        return ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(base_path(), '/');
    }
    return null;
}

function mail_clean_line(string $s): string { return trim(preg_replace('/[\r\n\t]+/', ' ', $s) ?? ''); }

function mail_encode_header(string $s): string { return preg_match('/^[\x20-\x7E]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?='; }

/**
 * The full message text (headers and body) as it goes over the wire. $opts can hold 'reply_to' (an address), 'in_reply_to' (the Message-ID
 * of the mail being answered, so mail programs keep the conversation together) and 'auto' (true for an automatic answer).
 * Anything that is not a plain address or message id is left out, so nothing typed by a visitor ends up in a header.
 */
function mail_build(string $from, string $fromName, string $to, string $subject, string $body, string $host, array $opts = []): string
{
    $extra = [];
    $replyTo = (string) ($opts['reply_to'] ?? '');
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) { $extra[] = 'Reply-To: <' . $replyTo . '>'; }
    $irt = (string) ($opts['in_reply_to'] ?? '');
    if (preg_match('/^<[A-Za-z0-9.!#$%&*+=?^_{|}~@-]{3,240}>$/', $irt)) { $extra[] = 'In-Reply-To: ' . $irt; $extra[] = 'References: ' . $irt; }
    if (!empty($opts['auto'])) { $extra[] = 'Auto-Submitted: auto-replied'; }   // other systems must not answer an automatic answer in turn
    $h = [
        'Date: ' . date('r'),
        'From: ' . mail_encode_header(mail_clean_line($fromName)) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . mail_encode_header(mail_clean_line($subject)),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^A-Za-z0-9.-]/', '', $host ?: 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Auto-Response-Suppress: All',
    ];
    return implode("\r\n", array_merge($h, $extra)) . "\r\n\r\n" . chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n");
}

/** Reads one SMTP reply (several lines possible). Returns [code, text]. */
function smtp_read($fp): array
{
    $text = '';
    do {
        $line = fgets($fp, 1024);
        if ($line === false) { return [0, 'no answer from the mail server (timeout)']; }
        $text .= $line;
    } while (isset($line[3]) && $line[3] === '-');
    return [(int) substr($text, 0, 3), trim($text)];
}

function smtp_cmd($fp, string $cmd, array $ok): ?string
{
    fwrite($fp, $cmd . "\r\n");
    [$code, $text] = smtp_read($fp);
    return in_array($code, $ok, true) ? null : mb_substr($text !== '' ? $text : 'unexpected answer', 0, 160);
}

/** Sends one message through an SMTP server. Returns null when accepted, otherwise a short error (never containing the password). */
function smtp_send(array $cfg, string $password, string $from, string $to, string $message): ?string
{
    $port = (int) $cfg['port'];
    $fp = @stream_socket_client(($cfg['encryption'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $port, $errno, $errstr, 5);
    if (!$fp) { return 'Could not connect to ' . $cfg['host'] . ':' . $port . ' (' . mb_substr((string) $errstr, 0, 80) . ')'; }
    stream_set_timeout($fp, 8);
    try {
        [$code, $text] = smtp_read($fp);
        if ($code !== 220) { return 'The server did not greet us: ' . mb_substr($text, 0, 100); }
        if ($e = smtp_cmd($fp, 'EHLO localhost', [250])) { return $e; }
        if ($cfg['encryption'] === 'starttls') {
            if ($e = smtp_cmd($fp, 'STARTTLS', [220])) { return $e; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { return 'The secure (TLS) connection could not be set up.'; }
            if ($e = smtp_cmd($fp, 'EHLO localhost', [250])) { return $e; }
        }
        if ($cfg['username'] !== '') {
            if ($e = smtp_cmd($fp, 'AUTH LOGIN', [334])) { return 'The server does not accept a login: ' . $e; }
            if ($e = smtp_cmd($fp, base64_encode((string) $cfg['username']), [334])) { return $e; }
            if ($e = smtp_cmd($fp, base64_encode($password), [235])) { return 'The user name or password was refused.'; }
        }
        if ($e = smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250])) { return $e; }
        if ($e = smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251])) { return $e; }
        if ($e = smtp_cmd($fp, 'DATA', [354])) { return $e; }
        fwrite($fp, preg_replace('/^\./m', '..', $message) . "\r\n.\r\n");   // a line that is just a dot would end the message early
        [$code, $text] = smtp_read($fp);
        if ($code !== 250) { return mb_substr($text, 0, 160); }
        @smtp_cmd($fp, 'QUIT', [221]);
        return null;
    } finally {
        fclose($fp);
    }
}

/**
 * Records and (if possible) delivers a message. Returns [status, outbox id, note]; status is sent, recorded or failed.
 * $kind lets the outbox show what it was for: reset, invite, notification, test, ticket. $opts: see mail_build().
 */
function mail_send(PDO $pdo, string $to, string $subject, string $body, string $kind = 'other', array $opts = []): array
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || mb_strlen($to) > 190) { return ['failed', 0, 'That is not a valid email address.']; }
    $subject = mb_substr(mail_clean_line($subject), 0, 190);
    $pdo->prepare("INSERT INTO mail_outbox (kind, to_email, subject, body, status, created_at) VALUES (?,?,?,?, 'recorded', NOW())")->execute([mb_substr($kind, 0, 20), $to, $subject, $body]);
    $id = (int) $pdo->lastInsertId();
    $cfg = mail_settings($pdo);
    $from = filter_var((string) $cfg['from_email'], FILTER_VALIDATE_EMAIL) ? (string) $cfg['from_email'] : 'no-reply@' . (preg_replace('/[^A-Za-z0-9.-]/', '', (string) parse_url((string) $cfg['base_url'], PHP_URL_HOST)) ?: 'localhost');
    $status = 'recorded'; $note = 'No mail server is set up on this computer, so the message is only recorded in the outbox.';
    if ($cfg['host'] !== '') {
        $err = smtp_send($cfg, $cfg['password_plain'] ?? secret_decrypt((string) $cfg['password_enc']), $from, $to, mail_build($from, (string) $cfg['from_name'], $to, $subject, $body, (string) $cfg['host'], $opts));
        $status = $err === null ? 'sent' : 'failed'; $note = $err ?? 'Sent.';
    } elseif (!mail_is_local()) {
        $ok = @mail($to, mail_encode_header($subject), str_replace("\n", "\r\n", $body), 'From: ' . $from . "\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0");
        $status = $ok ? 'sent' : 'failed'; $note = $ok ? 'Handed to the server\'s mail system.' : 'The server\'s mail system refused the message. Enter SMTP settings in Settings > Email.';
    }
    $wipe = $status === 'sent' && in_array($kind, MAIL_TOKEN_TEXT_KINDS, true);
    $pdo->prepare('UPDATE mail_outbox SET status = ?, error = ?, sent_at = ?, body = IF(?, ?, body) WHERE id = ?')
        ->execute([$status, $status === 'sent' ? '' : mb_substr($note, 0, 200), $status === 'sent' ? date('Y-m-d H:i:s') : null, $wipe ? 1 : 0, '[Delivered. The text was removed because it contained a one-time link.]', $id]);
    return [$status, $id, $note];
}

/** Tidies old outbox entries. Called from the notification refresh. */
function mail_purge(PDO $pdo): void
{
    $pdo->exec('DELETE FROM mail_outbox WHERE created_at < NOW() - INTERVAL ' . MAIL_KEEP_DAYS . ' DAY');
}

/** Sends one email listing the notifications that were not mailed yet (stage 6 events). Returns how many were included. */
function mail_notifications_digest(PDO $pdo): int
{
    require_once __DIR__ . '/notifications.php';
    $rows = $pdo->query('SELECT id, title, body, link, is_demo FROM notifications WHERE emailed_at IS NULL' . notifications_hidden_sql() . ' ORDER BY id LIMIT 200')->fetchAll();   // not those of modules that are off
    if (!$rows) { return 0; }
    $to = notify_email($pdo);
    $recipients = $to !== '' ? [strtolower($to) => $to] : [];
    foreach ($pdo->query('SELECT email FROM admins WHERE notify_optin = 1 AND is_active = 1')->fetchAll(PDO::FETCH_COLUMN) as $m) { $recipients[strtolower($m)] = $m; }   // people who asked to be mailed too
    $real = array_values(array_filter($rows, fn($r) => !(int) $r['is_demo']));   // demo events are never mailed to a real address
    $base = mail_base_url($pdo);
    if ($recipients && $real) {
        $lines = [];
        foreach (array_slice($real, 0, 25) as $r) { $lines[] = '- ' . $r['title'] . "\n  " . $r['body'] . ($base && $r['link'] !== '' ? "\n  " . $base . '/' . $r['link'] : ''); }
        if (count($real) > 25) { $lines[] = '…and ' . (count($real) - 25) . ' more. Open the notifications page to see them all.'; }
        foreach ($recipients as $addr) { mail_send($pdo, $addr, count($real) === 1 ? 'Telecomlijn Admin: ' . $real[0]['title'] : 'Telecomlijn Admin: ' . count($real) . ' new notifications', "New notifications:\n\n" . implode("\n\n", $lines) . "\n", 'notification'); }
    }
    $ids = array_column($rows, 'id');   // handled, sent or skipped: never offered again
    $pdo->exec('UPDATE notifications SET emailed_at = NOW() WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    return count($real);
}
