<?php
declare(strict_types=1);

/*
 * Email to ticket. A mailbox (for example support@) is read over IMAP, and every unread message becomes a ticket or, when it answers
 * an existing ticket, a comment in that thread. Written in-house like the SMTP client: no library and no PHP extension is needed.
 *
 *   - Which ticket: the reference in the subject ("[TCK-0042]"), or the mail it answers (In-Reply-To / References) when that mail is known.
 *   - A reply is only added to a ticket when it comes from the person who opened it, the contact person, or someone of the same company.
 *     Anyone else quoting a reference gets a new ticket, so a guessed number never writes into somebody else's thread.
 *   - A reply reopens a resolved or closed ticket and takes a waiting ticket out of waiting.
 *   - Automatic mail (out-of-office, bounces, mailing lists, our own address) is ignored, and one sender can open at most
 *     INBOUND_MAX_PER_SENDER tickets an hour, so two mail systems can never keep answering each other.
 *   - Every handled message is remembered by its Message-ID, so it can never become two tickets.
 * Settings: Settings > Tickets. Scheduled job: bin/poll_inbox.php.
 */

if (!function_exists('ticket_create')) { require __DIR__ . '/tickets.php'; }
if (!function_exists('mail_send')) { require __DIR__ . '/mailer.php'; }

const INBOUND_MAX_BYTES = 524288;       // only the first 512 KB of a message is read: enough for the text, attachments are not stored
const INBOUND_MAX_PER_RUN = 50;
const INBOUND_MAX_PER_SENDER = 10;      // new tickets per sender per hour

function inbox_settings(PDO $pdo): array
{
    $d = ['enabled' => 0, 'host' => '', 'port' => 993, 'encryption' => 'ssl', 'username' => '', 'password_enc' => null, 'folder' => 'INBOX', 'address' => '', 'auto_reply' => 1,
        'last_run_at' => null, 'last_state' => 'never', 'last_error' => '', 'last_count' => 0];
    try { $r = $pdo->query('SELECT * FROM inbox_settings WHERE id = 1')->fetch(); } catch (Throwable $e) { $r = false; }
    return $r ? array_merge($d, $r) : $d;
}

// ---------------------------------------------------------------- IMAP

/** Sends one IMAP command and reads the answer up to its tagged line. Returns [ok, status text, untagged lines, literals]. */
function imap_cmd($fp, string $tag, string $cmd): array
{
    fwrite($fp, $tag . ' ' . $cmd . "\r\n");
    $lines = []; $literals = [];
    while (true) {
        $line = fgets($fp, 65536);
        if ($line === false) { return [false, 'no answer from the mail server (timeout)', $lines, $literals]; }
        while (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {   // a literal follows: exactly that many bytes, then the rest of the line
            $need = (int) $m[1]; $buf = '';
            if ($need > INBOUND_MAX_BYTES * 2) { return [false, 'the mail server sent more data than was asked for', $lines, $literals]; }
            while (strlen($buf) < $need) {
                $chunk = fread($fp, $need - strlen($buf));
                if ($chunk === false || $chunk === '') { return [false, 'the connection was lost while reading a message', $lines, $literals]; }
                $buf .= $chunk;
            }
            $literals[] = $buf;
            $rest = fgets($fp, 65536);
            if ($rest === false) { return [false, 'no answer from the mail server (timeout)', $lines, $literals]; }
            $line = $rest;
        }
        $line = rtrim($line, "\r\n");
        if (str_starts_with($line, $tag . ' ')) {
            $status = substr($line, strlen($tag) + 1);
            return [stripos($status, 'OK') === 0, $status, $lines, $literals];
        }
        $lines[] = $line;
    }
}

function imap_quote(string $s): string { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"'; }

/**
 * Reads the unread messages of the mailbox. $handle gets the raw text of each message and returns true when it was dealt with; only
 * then is the message marked as read on the server. $handle = null only tests the connection.
 * Returns ['state' => ok|unauthorized|unreachable|invalid, 'error' => short text (never the password), 'count' => messages handled, 'unread' => n].
 */
function imap_fetch_unread(array $cfg, string $password, ?callable $handle, int $max = INBOUND_MAX_PER_RUN): array
{
    $res = ['state' => 'unreachable', 'error' => '', 'count' => 0, 'unread' => 0];
    if (preg_match('/[\r\n\x00]/', (string) $cfg['username'] . $password . (string) $cfg['folder'])) { return ['state' => 'invalid', 'error' => 'The user name, password or folder contains a character that is not allowed.'] + $res; }
    $port = (int) $cfg['port'];
    $fp = @stream_socket_client(($cfg['encryption'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $port, $errno, $errstr, 6);
    if (!$fp) { return ['error' => 'Could not connect to ' . $cfg['host'] . ':' . $port . ' (' . mb_substr((string) $errstr, 0, 80) . ')'] + $res; }
    stream_set_timeout($fp, 12);
    $n = 0;
    $tag = function () use (&$n): string { return 'A' . (++$n); };
    try {
        $hello = fgets($fp, 4096);
        if ($hello === false || !str_starts_with($hello, '* OK')) { return ['state' => 'invalid', 'error' => 'That address did not answer as an IMAP mail server.'] + $res; }
        if ($cfg['encryption'] === 'starttls') {
            [$ok] = imap_cmd($fp, $tag(), 'STARTTLS');
            if (!$ok || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { return ['error' => 'The secure (TLS) connection could not be set up.'] + $res; }
        }
        [$ok] = imap_cmd($fp, $tag(), 'LOGIN ' . imap_quote((string) $cfg['username']) . ' ' . imap_quote($password));
        if (!$ok) { return ['state' => 'unauthorized', 'error' => 'The user name or password was refused.'] + $res; }
        [$ok, $st] = imap_cmd($fp, $tag(), 'SELECT ' . imap_quote((string) $cfg['folder']));
        if (!$ok) { return ['state' => 'invalid', 'error' => 'The folder could not be opened: ' . mb_substr($st, 0, 100)] + $res; }
        [$ok, $st, $lines] = imap_cmd($fp, $tag(), 'UID SEARCH UNSEEN');
        if (!$ok) { return ['state' => 'invalid', 'error' => 'The mail server refused the search: ' . mb_substr($st, 0, 100)] + $res; }
        $uids = [];
        foreach ($lines as $l) { if (preg_match('/^\* SEARCH(.*)$/i', $l, $m)) { foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $u) { if (ctype_digit($u)) { $uids[] = (int) $u; } } } }
        sort($uids);
        $res['unread'] = count($uids);
        $res['state'] = 'ok';
        if ($handle !== null) {
            foreach (array_slice($uids, 0, $max) as $uid) {
                [$ok, , , $lit] = imap_cmd($fp, $tag(), 'UID FETCH ' . $uid . ' (BODY.PEEK[]<0.' . INBOUND_MAX_BYTES . '>)');
                if (!$ok || !$lit) { continue; }
                $done = false;
                try { $done = (bool) $handle($lit[0]); } catch (Throwable $e) { error_log('Inbound mail failed: ' . $e->getMessage()); }
                if ($done) { imap_cmd($fp, $tag(), 'UID STORE ' . $uid . ' +FLAGS (\\Seen)'); $res['count']++; }
            }
        }
        imap_cmd($fp, $tag(), 'LOGOUT');
        return $res;
    } finally {
        fclose($fp);
    }
}

// ---------------------------------------------------------------- reading a message

/** Splits a message or a part into [headers (lower-case name => first value, unfolded), body]. */
function mime_split(string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    $pos = strpos($raw, "\n\n");
    $head = $pos === false ? $raw : substr($raw, 0, $pos);
    $body = $pos === false ? '' : substr($raw, $pos + 2);
    $head = preg_replace('/\n[ \t]+/', ' ', $head) ?? $head;
    $h = [];
    foreach (explode("\n", $head) as $line) {
        $c = strpos($line, ':');
        if ($c === false || $c === 0) { continue; }
        $name = strtolower(trim(substr($line, 0, $c)));
        if (!isset($h[$name])) { $h[$name] = trim(substr($line, $c + 1)); }
    }
    return [$h, $body];
}

/** Turns an encoded header ("=?UTF-8?B?...?=") into readable UTF-8 on one line. */
function mime_header_text(string $v): string
{
    $out = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if ($out === false) { $out = $v; }
    if (!mb_check_encoding($out, 'UTF-8')) { $out = mb_convert_encoding($out, 'UTF-8', 'ISO-8859-1'); }
    return trim(preg_replace('/\s+/u', ' ', $out) ?? $out);
}

/** A parameter of a header value: mime_param('text/plain; charset="utf-8"', 'charset') gives "utf-8". */
function mime_param(string $value, string $name): string
{
    return preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*("[^"]*"|[^;\s]+)/i', $value, $m) ? trim($m[1], '"') : '';
}

/** The text of one part as UTF-8: undoes base64 or quoted-printable and converts the character set. */
function mime_part_text(array $h, string $body): string
{
    $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
    if ($enc === 'base64') { $body = (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false); }
    elseif ($enc === 'quoted-printable') { $body = quoted_printable_decode($body); }
    $charset = strtoupper(mime_param($h['content-type'] ?? '', 'charset') ?: 'UTF-8');
    if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
        $conv = @iconv($charset, 'UTF-8//IGNORE', $body);
        if ($conv !== false) { $body = $conv; }
    }
    if (!mb_check_encoding($body, 'UTF-8')) { $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1'); }
    return $body;
}

/** Finds the readable text of a message: the first text/plain part, otherwise the first text/html part. Attachments are skipped. */
function mime_find_text(string $raw, int $depth = 0): array
{
    [$h, $body] = mime_split($raw);
    $type = strtolower(trim(explode(';', $h['content-type'] ?? 'text/plain')[0]));
    $found = ['plain' => null, 'html' => null];
    if (str_starts_with($type, 'multipart/')) {
        $b = mime_param($h['content-type'], 'boundary');
        if ($b === '' || $depth > 5) { return $found; }
        $parts = explode("\n--" . $b, "\n" . $body);
        array_shift($parts);   // the text before the first boundary
        foreach ($parts as $p) {
            if (str_starts_with($p, '--')) { break; }   // the closing boundary
            $sub = mime_find_text(ltrim($p, "\n"), $depth + 1);
            $found['plain'] ??= $sub['plain'];
            $found['html'] ??= $sub['html'];
            if ($found['plain'] !== null) { break; }
        }
        return $found;
    }
    if (stripos($h['content-disposition'] ?? '', 'attachment') === 0) { return $found; }
    if ($type === 'text/plain') { $found['plain'] = mime_part_text($h, $body); }
    elseif ($type === 'text/html') { $found['html'] = mime_part_text($h, $body); }
    return $found;
}

/** HTML mail as plain text: line breaks are kept, tags, scripts and styles go. */
function mime_html_to_text(string $html): string
{
    $html = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
    $html = preg_replace('~<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>~i', "\n", $html) ?? $html;
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Cuts the quoted earlier mail off a reply, so a thread shows only what was newly written. */
function mail_strip_quote(string $text): string
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    $keep = [];
    foreach ($lines as $l) {
        $t = trim($l);
        if (preg_match('/^(On .{5,200} wrote:|Op .{5,200} schreef .{0,200}:|-{2,} ?(Original Message|Oorspronkelijk bericht) ?-{2,}|_{10,}|From: .+|Van: .+)$/iu', $t)) { break; }
        $keep[] = rtrim($l);
    }
    while ($keep && (trim(end($keep)) === '' || str_starts_with(ltrim(end($keep)), '>'))) { array_pop($keep); }
    $out = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $keep)) ?? '');
    return $out !== '' ? $out : trim($text);   // a mail that is nothing but a quote is kept whole
}

/**
 * Reads a raw message. Returns from_email (lower case, '' when there is none), from_name, subject, message_id, refs (the ids it answers),
 * auto (true for automatic mail that must not be answered or turned into a ticket) and text.
 */
function mail_parse(string $raw): array
{
    [$h] = mime_split($raw);
    $from = mime_header_text($h['from'] ?? '');
    $email = '';
    if (preg_match('/<\s*([^<>\s]+@[^<>\s]+)\s*>/', $from, $m) || preg_match('/([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $from, $m)) { $email = strtolower($m[1]); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) { $email = ''; }
    $name = trim(preg_replace('/<[^>]*>/', '', $from) ?? '', " \t\"'");
    $ids = [];
    foreach ([$h['in-reply-to'] ?? '', $h['references'] ?? ''] as $v) { if (preg_match_all('/<[^<>\s]{3,250}>/', $v, $mm)) { $ids = array_merge($ids, $mm[0]); } }
    $mid = preg_match('/<[^<>\s]{3,250}>/', $h['message-id'] ?? '', $m) ? $m[0] : '';
    $autoSub = strtolower(trim($h['auto-submitted'] ?? 'no'));
    $prec = strtolower(trim($h['precedence'] ?? ''));
    $local = strtolower(explode('@', $email)[0]);
    $auto = ($autoSub !== 'no' && $autoSub !== '') || in_array($prec, ['bulk', 'junk', 'list', 'auto_reply'], true) || isset($h['list-id']) || isset($h['x-autoreply']) || isset($h['x-autorespond'])
        || in_array($local, ['mailer-daemon', 'postmaster', 'no-reply', 'noreply', 'donotreply', 'do-not-reply', 'bounce', 'bounces'], true);
    $found = mime_find_text($raw);
    $text = $found['plain'] ?? ($found['html'] !== null ? mime_html_to_text($found['html']) : '');
    $text = preg_replace('/[^\P{C}\n\t]/u', '', str_replace(["\r\n", "\r"], "\n", $text)) ?? '';   // control characters out, line breaks stay
    return ['from_email' => $email, 'from_name' => mb_substr($name, 0, 120), 'subject' => mb_substr(mime_header_text($h['subject'] ?? ''), 0, 300), 'message_id' => $mid,
        'refs' => array_values(array_unique($ids)), 'auto' => $auto, 'text' => trim($text)];
}

// ---------------------------------------------------------------- from message to ticket

/** May this sender add to this ticket? The requester, the contact person, or a known person of the same company. */
function inbound_sender_allowed(PDO $pdo, array $t, string $email): bool
{
    if ($email === '') { return false; }
    if (strtolower((string) $t['requester_email']) === $email) { return true; }
    $s = $pdo->prepare('SELECT id, company_id FROM users WHERE email = ? LIMIT 1');
    $s->execute([$email]);
    $u = $s->fetch();
    if (!$u) { return false; }
    return ($t['user_id'] !== null && (int) $t['user_id'] === (int) $u['id']) || ($t['company_id'] !== null && $u['company_id'] !== null && (int) $t['company_id'] === (int) $u['company_id']);
}

/**
 * Handles one message. Returns ['outcome' => new|reply|ignored|duplicate, 'ticket_id' => int, 'note' => why].
 * $cfg is inbox_settings(). Always safe to call twice with the same message.
 */
function inbound_process(PDO $pdo, string $raw, array $cfg): array
{
    $m = mail_parse($raw);
    $mid = mb_substr($m['message_id'] !== '' ? $m['message_id'] : '<no-id-' . sha1($raw) . '>', 0, 255);
    $claim = $pdo->prepare("INSERT IGNORE INTO ticket_emails (message_id, from_email, subject, outcome, note) VALUES (?,?,?, 'ignored', 'being handled')");
    $claim->execute([$mid, $m['from_email'], mb_substr($m['subject'], 0, 190)]);
    if ($claim->rowCount() !== 1) { return ['outcome' => 'duplicate', 'ticket_id' => 0, 'note' => 'already handled']; }
    $rowId = (int) $pdo->lastInsertId();
    $finish = function (string $outcome, int $ticketId, string $note) use ($pdo, $rowId): array {
        $pdo->prepare('UPDATE ticket_emails SET outcome = ?, ticket_id = ?, note = ? WHERE id = ?')->execute([$outcome, $ticketId > 0 ? $ticketId : null, mb_substr($note, 0, 120), $rowId]);
        return ['outcome' => $outcome, 'ticket_id' => $ticketId, 'note' => $note];
    };

    $own = array_filter([strtolower((string) $cfg['address']), strtolower((string) (mail_settings($pdo)['from_email'] ?? ''))]);
    if ($m['from_email'] === '') { return $finish('ignored', 0, 'no sender address'); }
    if (in_array($m['from_email'], $own, true)) { return $finish('ignored', 0, 'sent by this system'); }
    if ($m['auto']) { return $finish('ignored', 0, 'automatic mail'); }

    // which ticket does it answer?
    $t = null;
    if (preg_match('/\[(TCK-\d{4,9})\]/i', $m['subject'], $mm)) {
        $s = $pdo->prepare('SELECT id FROM tickets WHERE reference = ?');
        $s->execute([strtoupper($mm[1])]);
        if ($tid = (int) $s->fetchColumn()) { $t = ticket_find($pdo, $tid); }
    }
    if (!$t && $m['refs']) {
        $s = $pdo->prepare('SELECT ticket_id FROM ticket_emails WHERE ticket_id IS NOT NULL AND message_id IN (' . implode(',', array_fill(0, count($m['refs']), '?')) . ') ORDER BY id DESC LIMIT 1');
        $s->execute($m['refs']);
        if ($tid = (int) $s->fetchColumn()) { $t = ticket_find($pdo, $tid); }
    }

    $text = mail_strip_quote($m['text']);
    if ($t && inbound_sender_allowed($pdo, $t, $m['from_email'])) {
        $body = mb_substr($text !== '' ? $text : '(empty message)', 0, 2000);
        if ($err = ticket_comment($pdo, (int) $t['id'], $body, $m['from_email'], 'email')) { return $finish('ignored', (int) $t['id'], $err); }
        if (in_array($t['status'], ['resolved', 'closed', 'waiting'], true)) {   // the customer answered: back to open, and the SLA clock runs again
            [$changes] = ticket_update($pdo, $t, ['status' => 'open'], true);
            if ($changes) { ticket_comment($pdo, (int) $t['id'], 'Reopened by a reply from the customer (' . implode(', ', $changes) . ').', 'system'); }
        }
        return $finish('reply', (int) $t['id'], 'added to ' . $t['reference']);
    }

    $cap = $pdo->prepare("SELECT COUNT(*) FROM ticket_emails WHERE from_email = ? AND outcome = 'new' AND received_at >= NOW() - INTERVAL 1 HOUR");
    $cap->execute([$m['from_email']]);
    if ((int) $cap->fetchColumn() >= INBOUND_MAX_PER_SENDER) { return $finish('ignored', 0, 'too many tickets from this sender in an hour'); }

    // who is it? a known person brings their company along; otherwise a company with this address
    $in = ['priority' => 'normal', 'requester_email' => $m['from_email']];
    $s = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $s->execute([$m['from_email']]);
    if ($uid = (int) $s->fetchColumn()) { $in['user_id'] = $uid; }
    else {
        $s = $pdo->prepare('SELECT id FROM companies WHERE email = ? ORDER BY id LIMIT 1');
        $s->execute([$m['from_email']]);
        if ($cid = (int) $s->fetchColumn()) { $in['company_id'] = $cid; }
    }
    $subject = trim(preg_replace('/^((re|fw|fwd|aw|antw|doorst)\s*:\s*)+/i', '', preg_replace('/\[TCK-\d+\]/i', '', $m['subject']) ?? '') ?? '');
    $in['subject'] = mb_substr($subject !== '' ? $subject : '(no subject)', 0, 160);
    $full = $m['text'] !== '' ? $m['text'] : '(empty message)';
    $in['description'] = mb_strlen($full) > 2000 ? mb_substr($full, 0, 1900) . "\n[The message was longer and has been cut off here.]" : $full;
    [$id, $err] = ticket_create($pdo, $in, 'email');
    if ($id <= 0) { return $finish('ignored', 0, implode(' ', $err)); }
    if ((int) $cfg['auto_reply'] === 1) {
        $ref = ticket_reference($id);
        try {
            mail_send($pdo, $m['from_email'], '[' . $ref . '] ' . $in['subject'],
                "Thank you for your message. We have opened ticket $ref for it and will get back to you.\n\nYou can add information by replying to this email. Please leave the reference $ref in the subject.\n\n" . ticket_signoff($pdo, ''),
                'ticket', ['reply_to' => (string) $cfg['address'], 'in_reply_to' => $m['message_id'], 'auto' => true]);
        } catch (Throwable $e) { error_log('Ticket confirmation failed: ' . $e->getMessage()); }
    }
    return $finish('new', $id, 'opened ' . ticket_reference($id));
}

/** The closing lines of a mail about a ticket: the name of the person who writes (when there is one) and the company name. */
function ticket_signoff(PDO $pdo, string $staffName): string
{
    $co = require __DIR__ . '/company.php';
    return "Kind regards,\n" . ($staffName !== '' ? $staffName . "\n" : '') . $co['name'] . "\n";
}

/** The address a reply about this ticket goes to: the sender of an emailed ticket, otherwise the contact person. '' when there is none. */
function ticket_reply_address(PDO $pdo, array $t): string
{
    if ((string) ($t['requester_email'] ?? '') !== '') { return (string) $t['requester_email']; }
    if ($t['user_id'] !== null) {
        $s = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $s->execute([(int) $t['user_id']]);
        return (string) ($s->fetchColumn() ?: '');
    }
    return '';
}

/** Mails a staff reply to the customer of a ticket. Returns [status (sent, recorded or failed), note]. */
function ticket_reply_mail(PDO $pdo, array $t, string $body, string $staffName): array
{
    $to = ticket_reply_address($pdo, $t);
    if ($to === '') { return ['failed', 'This ticket has no email address to reply to.']; }
    $s = $pdo->prepare("SELECT message_id FROM ticket_emails WHERE ticket_id = ? AND outcome IN ('new','reply') ORDER BY id DESC LIMIT 1");
    $s->execute([(int) $t['id']]);
    [$status, , $note] = mail_send($pdo, $to, 'Re: [' . $t['reference'] . '] ' . $t['subject'], trim($body) . "\n\n" . ticket_signoff($pdo, $staffName), 'ticket',
        ['reply_to' => (string) inbox_settings($pdo)['address'], 'in_reply_to' => (string) ($s->fetchColumn() ?: '')]);
    return [$status, $note];
}

/**
 * Reads the mailbox once and turns what is new into tickets and comments. Returns ['state', 'error', 'new', 'replies', 'ignored'].
 * Two runs at the same moment are not possible: the second one answers "busy".
 */
function inbox_run(PDO $pdo): array
{
    $out = ['state' => 'off', 'error' => '', 'new' => 0, 'replies' => 0, 'ignored' => 0];
    $cfg = inbox_settings($pdo);
    if (!(int) $cfg['enabled'] || $cfg['host'] === '') { return $out; }
    if ((int) $pdo->query("SELECT GET_LOCK('inbox_run', 1)")->fetchColumn() !== 1) { return ['state' => 'busy'] + $out; }
    try {
        $r = imap_fetch_unread($cfg, secret_decrypt((string) $cfg['password_enc']), function (string $raw) use ($pdo, $cfg, &$out): bool {
            $res = inbound_process($pdo, $raw, $cfg);
            $key = ['new' => 'new', 'reply' => 'replies'][$res['outcome']] ?? 'ignored';
            $out[$key]++;
            return true;
        });
        $out['state'] = $r['state']; $out['error'] = $r['error'];
        $pdo->prepare('UPDATE inbox_settings SET last_run_at = NOW(), last_state = ?, last_error = ?, last_count = ? WHERE id = 1')->execute([$r['state'], mb_substr($r['error'], 0, 200), $out['new'] + $out['replies']]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('inbox_run')")->fetchColumn();
    }
    return $out;
}
