<?php
declare(strict_types=1);

/*
 * Connectors: 3CX and Yeastar (phone systems), Twilio (calls and text messages) and Short.io (short links).
 * Credentials are stored encrypted (AES-256-GCM, see secret_encrypt) and never shown again. Every request goes through connector_http(),
 * which checks the address like the Kuma connector does (link-local, multicast and reserved addresses are refused, DNS is resolved once and
 * pinned, no redirects are followed, the answer is capped). The field names of each service follow its public API documentation as far as
 * known; the mapping functions accept several spellings, and a record that can not be understood is skipped, never stored half.
 * Syncing is done by bin/sync_connectors.php (cron) or the "Sync now" button, never inside an ordinary page view.
 */

require_once __DIR__ . '/kuma.php';   // kuma_validate_url(), kuma_ip_blocked(); it also loads secret_encrypt() / secret_decrypt()
require_once __DIR__ . '/comm.php';

const CONNECTOR_TIMEOUT_S = 10;
const CONNECTOR_SLOW_TIMEOUT_S = 30;     // for a request that asks a phone system for its call history: a large history makes it slow
const CONNECTOR_MAX_BODY = 2097152;      // 2 MB
const CONNECTOR_WINDOW_DAYS = 35;        // how far back a sync looks
const CONNECTOR_MAX_RECORDS = 2000;      // per service and run

/** The services. 'cloud' ones have a built-in address; a 'pbx' is reached at the address the admin enters. */
function connector_defs(): array
{
    return [
        'threecx' => ['name' => '3CX', 'type' => 'pbx', 'gives' => 'calls', 'url_label' => 'Server address', 'url_hint' => 'https://pbx.example.com',
            'id_label' => 'Client ID', 'secret_label' => 'Client secret',
            'help' => 'Needs 3CX version 20. Either add an API client in the Management Console (Integrations, API) and enter its Client ID and secret, or enter the user name and password of a system owner or admin in the same two fields (not possible when that account uses two-step sign-in).'],
        'yeastar' => ['name' => 'Yeastar', 'type' => 'pbx', 'gives' => 'calls', 'url_label' => 'Server address', 'url_hint' => 'https://pbx.example.com:8088',
            'id_label' => 'Client ID', 'secret_label' => 'Client secret',
            'help' => 'In the Yeastar PBX web interface open Integrations, API, turn the API on and create a client ID and secret (OpenAPI, P-Series).'],
        'twilio' => ['name' => 'Twilio', 'type' => 'cloud', 'gives' => 'calls and text messages', 'url_label' => '', 'url_hint' => '',
            'id_label' => 'Account SID', 'secret_label' => 'Auth token',
            'help' => 'Both are on the dashboard of your Twilio console. The Account SID starts with AC.'],
        'shortio' => ['name' => 'Short.io', 'type' => 'cloud', 'gives' => 'short links', 'url_label' => '', 'url_hint' => '',
            'id_label' => '', 'secret_label' => 'API key',
            'help' => 'In Short.io open Integrations and API, and create a secret API key.'],
    ];
}

/** The module a service belongs to: 'calls' (3CX, Yeastar, Twilio) or 'links' (Short.io). */
function connector_module(string $provider): string
{
    return $provider === 'shortio' ? 'links' : 'calls';
}

function connector_default_row(string $p): array
{
    return ['provider' => $p, 'enabled' => 0, 'base_url' => '', 'account_ref' => '', 'secret_enc' => null, 'secret_last4' => '', 'verify_tls' => 1,
        'keep_text' => 0, 'last_sync_at' => null, 'last_state' => 'never', 'last_count' => 0, 'updated_by' => '', 'updated_at' => null];
}

function connector_get(PDO $pdo, string $p): array
{
    $s = $pdo->prepare('SELECT * FROM connectors WHERE provider = ?');
    $s->execute([$p]);
    return $s->fetch() ?: connector_default_row($p);
}

function connector_secret(array $c): string { return secret_decrypt($c['secret_enc'] ?? null); }

function connector_has_secret(array $c): bool { return ($c['secret_enc'] ?? '') !== '' && $c['secret_enc'] !== null; }

/** @return array{0: bool, 1: string, 2: string} ok, message, address without a trailing slash */
function connector_validate_url(string $url): array
{
    $url = trim($url);
    if ($url === '') { return [false, 'Enter the server address of the phone system, for example https://pbx.example.com', '']; }
    [$ok, $msg, $norm] = kuma_validate_url($url);
    return [$ok, str_replace('the Kuma address', 'the server address', $msg), $norm];
}

/**
 * Checks what was typed in the form of one service (nothing is saved). Returns [ok, message, values]; the values are base_url, account_ref,
 * verify_tls, keep_text, enabled and secret (only the secret typed now, which can be empty).
 * @param array<string,mixed> $in the posted form
 * @return array{0: bool, 1: string, 2: array<string,mixed>}
 */
function connector_prepare(string $p, array $in): array
{
    $defs = connector_defs();
    if (!isset($defs[$p])) { return [false, 'Unknown service.', []]; }
    $d = $defs[$p];
    $str = static fn(string $k): string => is_string($in[$k] ?? null) ? trim((string) $in[$k]) : '';   // an array in the form counts as empty
    $url = '';
    if ($d['type'] === 'pbx') {
        [$ok, $msg, $url] = connector_validate_url($str('base_url'));
        if (!$ok) { return [false, $msg, []]; }
    }
    $ref = '';
    if ($d['id_label'] !== '') {
        $ref = $str('account_ref');
        if ($ref === '') { return [false, 'Enter the ' . $d['id_label'] . '.', []]; }
        if ($p === 'twilio' && !preg_match('/^AC[0-9a-fA-F]{32}$/', $ref)) { return [false, 'A Twilio Account SID is "AC" followed by 32 letters and digits.', []]; }
        if ($p !== 'twilio' && !preg_match('/^[A-Za-z0-9._@:\-]{1,190}$/', $ref)) { return [false, 'The ' . $d['id_label'] . ' can only contain letters, digits and . _ @ : -', []]; }
    }
    $secret = $str('secret');
    if ($secret !== '' && (strlen($secret) > 300 || preg_match('/[\x00-\x1f\x7f]/', $secret))) { return [false, 'The ' . $d['secret_label'] . ' looks wrong: it is too long or has control characters.', []]; }
    return [true, '', ['base_url' => $url, 'account_ref' => $ref, 'verify_tls' => ($d['type'] === 'pbx' && !empty($in['self_signed'])) ? 0 : 1,
        'keep_text' => ($p === 'twilio' && !empty($in['keep_text'])) ? 1 : 0, 'enabled' => !empty($in['enabled']) ? 1 : 0, 'secret' => $secret]];
}

/** The secret to use for these values: the one typed now, else the saved one, but only while the address and ID are unchanged. */
function connector_effective_secret(array $old, array $v): string
{
    if ($v['secret'] !== '') { return (string) $v['secret']; }
    $same = $old['base_url'] === $v['base_url'] && $old['account_ref'] === $v['account_ref'];
    return ($same && connector_has_secret($old)) ? connector_secret($old) : '';
}

/**
 * Saves the settings of one service. A blank secret keeps the saved one, but only while the address and the ID are unchanged
 * (otherwise a changed address could receive the saved secret). Returns [ok, message].
 * @param array<string,mixed> $in the posted form
 * @return array{0: bool, 1: string}
 */
function connector_save(PDO $pdo, string $p, array $in, string $by): array
{
    [$ok, $msg, $v] = connector_prepare($p, $in);
    if (!$ok) { return [false, $msg]; }
    $d = connector_defs()[$p];
    $old = connector_get($pdo, $p);
    $sameEndpoint = $old['base_url'] === $v['base_url'] && $old['account_ref'] === $v['account_ref'];
    $enc = $old['secret_enc']; $last4 = (string) $old['secret_last4'];
    if ($v['secret'] !== '') { $enc = secret_encrypt($v['secret']); $last4 = substr($v['secret'], -4); }
    elseif (!$sameEndpoint) { $enc = null; $last4 = ''; }   // the address or ID changed and no new secret was typed: the old secret is not carried over
    if ($v['enabled'] && ($enc === null || $enc === '')) { return [false, 'Enter the ' . $d['secret_label'] . ' before turning ' . $d['name'] . ' on.']; }
    $keepState = $sameEndpoint && $v['secret'] === '';
    $pdo->prepare('INSERT INTO connectors (provider, enabled, base_url, account_ref, secret_enc, secret_last4, verify_tls, keep_text, last_state, updated_by, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), base_url = VALUES(base_url), account_ref = VALUES(account_ref), secret_enc = VALUES(secret_enc),
        secret_last4 = VALUES(secret_last4), verify_tls = VALUES(verify_tls), keep_text = VALUES(keep_text), last_state = IF(?, last_state, VALUES(last_state)),
        updated_by = VALUES(updated_by), updated_at = NOW()')
        ->execute([$p, $v['enabled'], $v['base_url'], $v['account_ref'], $enc, $last4, $v['verify_tls'], $v['keep_text'], 'never', mb_substr($by, 0, 60), $keepState ? 1 : 0]);
    // the secret itself never goes in the log
    log_activity('info', $d['name'] . ' connector saved', ($v['enabled'] ? 'on' : 'off') . ' · ' . ($enc ? 'secret set' : 'no secret') . ($v['secret'] !== '' ? ' · secret replaced' : ''));
    return [true, $d['name'] . ' saved' . ($v['enabled'] ? ' and turned on.' : '. It is off.')];
}

/** Removes the stored secret and turns the service off. */
function connector_remove(PDO $pdo, string $p): void
{
    $pdo->prepare("UPDATE connectors SET enabled = 0, secret_enc = NULL, secret_last4 = '', last_state = 'never', updated_at = NOW() WHERE provider = ?")->execute([$p]);
    log_activity('warning', (connector_defs()[$p]['name'] ?? $p) . ' connector credentials removed', $p);
}

// ---------- Talking to the services ----------

/**
 * The requests of the current test or sync, one short line each, for the log under each connector. connector_trace($line) adds one,
 * connector_trace(null, true) starts a new list. Lines hold the address, the HTTP code and the error code of a refusal: never a secret.
 * @return list<string>
 */
function connector_trace(?string $add = null, bool $reset = false): array
{
    static $lines = [];
    if ($reset) { $lines = []; }
    if ($add !== null && count($lines) < 20) { $lines[] = mb_substr(preg_replace('/[^\x20-\x7E]/', '', $add) ?? '', 0, 260); }
    return $lines;
}

/**
 * One HTTPS (or http) request.
 * @param array{headers?: list<string>, body?: ?string, basic?: ?array{0:string,1:string}, verify_tls?: bool, timeout?: int, unauthorized?: list<int>, forbidden?: bool, max_body?: int, snippet?: bool} $o
 *        'max_body' is the most bytes that are read (default CONNECTOR_MAX_BODY). 'snippet' => true puts the first words of an error answer (HTTP 400 and up)
 *        in the log line: only for requests that carry no secret in their answer (a data request made with a token, never a sign-in).
 *        'forbidden' => true: an HTTP 403 gives the state "forbidden" (signed in, but not allowed to do this) instead of "unauthorized". Use it for a request made with a token that was just accepted.
 * @return array{state: string, code: int, body: string, json: mixed, oauth: string}  state: ok | unauthorized | unreachable | invalid | busy. Never carries the secret or upstream
 *         error text; the one exception is 'oauth': the short error code of a refused sign-in (for example invalid_client), only when it is made of lowercase letters and underscores.
 */
function connector_http(string $method, string $url, array $o = []): array
{
    $o += ['headers' => [], 'body' => null, 'basic' => null, 'verify_tls' => true, 'timeout' => CONNECTOR_TIMEOUT_S, 'unauthorized' => [401, 403], 'max_body' => CONNECTOR_MAX_BODY, 'snippet' => false];
    $fail = static fn(string $s, int $code = 0, string $oauth = ''): array => ['state' => $s, 'code' => $code, 'body' => '', 'json' => null, 'oauth' => $oauth];
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host']) || !in_array(strtolower((string) $p['scheme']), ['http', 'https'], true)) { connector_trace($method . ' not sent: the address is not a valid http or https address'); return $fail('unreachable'); }
    $scheme = strtolower((string) $p['scheme']);
    $host = strtolower(trim((string) $p['host'], '[]'));
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    // address and path only: no query, no user name, no secret. The long argument list of a function call (3CX call log) is shortened to what matters.
    $shownPath = preg_replace("/\\((periodFrom=[^,]*,periodTo=[^,]*),[^)]*\\)/", '($1,...)', (string) ($p['path'] ?? '/')) ?? '/';
    $what = $method . ' ' . $host . (isset($p['port']) ? ':' . $p['port'] : '') . $shownPath;
    [$ok, $vmsg] = kuma_validate_url($scheme . '://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . (isset($p['port']) ? ':' . $p['port'] : ''));
    if (!$ok || !function_exists('curl_init')) { connector_trace($what . ' not sent: ' . ($ok ? 'the PHP curl extension is missing' : 'address not allowed (' . (string) $vmsg . ')')); return $fail('unreachable'); }

    $opts = [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => 0, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $o['timeout'], CURLOPT_CONNECTTIMEOUT => min((int) $o['timeout'], 4),
        CURLOPT_SSL_VERIFYPEER => (bool) $o['verify_tls'], CURLOPT_SSL_VERIFYHOST => $o['verify_tls'] ? 2 : 0,
        CURLOPT_USERAGENT => 'TelecomlijnAdmin-Connector/1.0', CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $o['headers']),
    ];
    if ($o['basic'] !== null) { $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC; $opts[CURLOPT_USERPWD] = $o['basic'][0] . ':' . $o['basic'][1]; }
    if ($o['body'] !== null) { $opts[CURLOPT_POSTFIELDS] = $o['body']; }
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = @gethostbynamel($host) ?: [];   // resolved once, checked, and used for the connection: no second lookup that could be rebound
        if (!$ips) { connector_trace($what . ' not sent: the name ' . $host . ' could not be resolved (DNS)'); return $fail('unreachable'); }
        foreach ($ips as $ip) { if (kuma_ip_blocked((string) $ip)) { connector_trace($what . ' not sent: ' . $host . ' resolves to ' . $ip . ', an address that is not allowed (link-local or similar)'); return $fail('unreachable'); } }
        $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $ips[0]];
    }
    $body = '';
    $limit = (int) $o['max_body'];
    $opts[CURLOPT_WRITEFUNCTION] = static function ($c, string $chunk) use (&$body, $limit): int {
        $body .= $chunk;
        return strlen($body) > $limit ? 0 : strlen($chunk);
    };
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $curlErr = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) { connector_trace($what . ' -> no answer: ' . $curlErr . ' (curl error ' . $errno . ')'); return $fail('unreachable', $code); }
    $err = json_decode($body, true);
    $oauth = is_array($err) && is_string($err['error'] ?? null) && preg_match('/^[a-z_]{3,40}$/', $err['error']) ? $err['error'] : '';
    $snip = !empty($o['snippet']) && $code >= 400 ? ': ' . mb_substr(trim(preg_replace('/[^\x20-\x7E]+/', ' ', $body) ?? ''), 0, 240) : '';
    connector_trace($what . ' -> HTTP ' . $code . ($oauth !== '' ? ', ' . $oauth : '') . $snip . ($code >= 200 && $code <= 299 ? ' (' . strlen($body) . ' bytes, ' . (is_array($err) ? 'JSON' : 'not JSON') . ')' : ''));
    if (!empty($o['forbidden']) && $code === 403) { return $fail('forbidden', $code, $oauth); }
    if (in_array($code, $o['unauthorized'], true)) { return $fail('unauthorized', $code, $oauth); }
    if ($code === 429) { return $fail('busy', $code); }
    if ($code < 200 || $code > 299) { return $fail('invalid', $code, $oauth); }
    $json = json_decode($body, true);
    return ['state' => 'ok', 'code' => $code, 'body' => $body, 'json' => $json, 'oauth' => ''];
}

/** A moment from any of the spellings the services use, as a Unix time (null when it can not be read). */
function connector_time(mixed $v): ?int
{
    if (is_int($v) || (is_string($v) && ctype_digit($v) && strlen($v) >= 9)) {
        $n = (int) $v;
        return $n > 100000000000 ? intdiv($n, 1000) : $n;   // milliseconds or seconds
    }
    if (!is_string($v) || trim($v) === '') { return null; }
    $v = trim($v);
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})[ T](\d{2}):(\d{2}):(\d{2})$~', $v, $m)) { $v = "$m[3]-$m[2]-$m[1] $m[4]:$m[5]:$m[6]"; }   // day/month/year
    $t = strtotime($v);
    return $t === false ? null : $t;
}

/** Seconds from 123, "123", "00:02:03", "02:03" or "PT2M3S". */
function connector_duration(mixed $v): int
{
    if (is_int($v) || is_float($v)) { return max(0, (int) $v); }
    if (!is_string($v) || $v === '') { return 0; }
    if (ctype_digit($v)) { return (int) $v; }
    if (preg_match('/^P(?:T)?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/', $v, $m) && ($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? '') !== '') { return (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0); }
    if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{1,2})(?:\.\d+)?$/', $v, $m)) { return (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0); }
    return 0;
}

/** in, out or internal from the words a service uses. */
function connector_direction(string $d): string
{
    $d = strtolower($d);
    if (str_contains($d, 'inbound') || $d === 'in' || $d === 'incoming' || $d === 'received') { return 'in'; }
    if (str_contains($d, 'outbound') || $d === 'out' || $d === 'outgoing') { return 'out'; }
    return 'internal';
}

/** One normalised record of a call or message, or null when it is unusable. */
function comm_row(string $provider, string $kind, mixed $id, string $dir, mixed $from, mixed $to, mixed $when, mixed $dur, string $status, mixed $body = '', mixed $remote = '', mixed $staff = ''): ?array
{
    $id = trim((string) $id);
    $ts = connector_time($when);
    if ($id === '' || strlen($id) > 100 || $ts === null || $ts < 946684800 || $ts > time() + 86400 * 2) { return null; }   // year 2000 up to two days ahead
    $from = mb_substr(trim((string) $from), 0, 40); $to = mb_substr(trim((string) $to), 0, 40);
    return ['provider' => $provider, 'kind' => $kind, 'external_id' => $id, 'direction' => $dir, 'from' => $from, 'to' => $to,
        'party_key' => $dir === 'in' ? phone_key($from) : ($dir === 'out' ? phone_key($to) : ''), 'started_at' => date('Y-m-d H:i:s', $ts),
        'duration_s' => min(86400 * 7, connector_duration($dur)), 'status' => $status, 'body' => mb_substr((string) $body, 0, 1000), 'ts' => $ts,
        // names from the phone system; a "name" that is only the number again is no name
        'remote_name' => preg_match('/^[\d\s+()\-]*$/', trim((string) $remote)) ? '' : mb_substr(trim((string) $remote), 0, 120),
        'staff_name' => preg_match('/^[\d\s+()\-]*$/', trim((string) $staff)) ? '' : mb_substr(trim((string) $staff), 0, 120)];
}

// ---- Twilio
function twilio_status(string $s, bool $sms): string
{
    $s = strtolower($s);
    if ($sms) { return match ($s) { 'delivered' => 'delivered', 'sent' => 'sent', 'received' => 'received', 'failed', 'undelivered' => 'failed', 'queued', 'sending', 'accepted', 'receiving', 'scheduled' => 'queued', default => 'other' }; }
    return match ($s) { 'completed' => 'answered', 'busy' => 'busy', 'no-answer', 'canceled' => 'missed', 'failed' => 'failed', default => 'other' };
}

/** @return array{state: string, calls: list<array>, sms: list<array>} */
function twilio_collect(array $c, string $secret, int $since): array
{
    $base = rtrim($c['base_url'] !== '' ? (string) $c['base_url'] : 'https://api.twilio.com', '/');
    $sid = (string) $c['account_ref'];
    $auth = ['basic' => [$sid, $secret], 'verify_tls' => true];
    $out = ['state' => 'ok', 'calls' => [], 'sms' => []];
    foreach (['Calls' => ['calls', 'calls', ['start_time', 'date_created']], 'Messages' => ['messages', 'sms', ['date_sent', 'date_created']]] as $res => [$key, $bucket, $dateKeys]) {
        $url = $base . '/2010-04-01/Accounts/' . rawurlencode($sid) . '/' . $res . '.json?PageSize=100';
        for ($page = 0; $page < 5 && $url !== ''; $page++) {
            $r = connector_http('GET', $url, $auth);
            if ($r['state'] !== 'ok' || !is_array($r['json'])) { return ['state' => $r['state'] === 'ok' ? 'invalid' : $r['state'], 'calls' => [], 'sms' => []]; }
            $stop = false;
            foreach ((array) ($r['json'][$key] ?? []) as $it) {
                if (!is_array($it)) { continue; }
                $when = $it[$dateKeys[0]] ?? $it[$dateKeys[1]] ?? null;
                $ts = connector_time($when);
                if ($ts !== null && $ts < $since) { $stop = true; continue; }
                $dir = connector_direction((string) ($it['direction'] ?? ''));
                $row = $bucket === 'calls'
                    ? comm_row('twilio', 'call', $it['sid'] ?? '', $dir, $it['from'] ?? '', $it['to'] ?? '', $when, $it['duration'] ?? 0, twilio_status((string) ($it['status'] ?? ''), false))
                    : comm_row('twilio', 'sms', $it['sid'] ?? '', $dir, $it['from'] ?? '', $it['to'] ?? '', $when, 0, twilio_status((string) ($it['status'] ?? ''), true), $it['body'] ?? '');
                if ($row !== null) { $out[$bucket][] = $row; }
            }
            $next = (string) ($r['json']['next_page_uri'] ?? '');
            $url = (!$stop && $next !== '' && str_starts_with($next, '/')) ? $base . $next : '';   // only a path on the same service is followed
            if (count($out[$bucket]) >= CONNECTOR_MAX_RECORDS) { break; }
        }
    }
    return $out;
}

// ---- 3CX (version 20, XAPI)
/**
 * A token for the XAPI. Two ways in, tried in this order with the same two values:
 *   1. an API client (Management Console, Integrations, API): Client ID and Client secret (OAuth client credentials)
 *   2. a Management Console login: user name and password of a system owner or admin (the sign-in the web client itself uses)
 * The second makes a system administrator's own login work too. It does not work for an account with two-step sign-in turned on.
 * @return array{0: string, 1: string, 2: string} state, token, a short reason when it failed (safe to show)
 */
function threecx_token(array $c, string $secret): array
{
    $base = rtrim((string) $c['base_url'], '/');
    $tls = (bool) $c['verify_tls'];
    $r = connector_http('POST', $base . '/connect/token', ['verify_tls' => $tls, 'unauthorized' => [400, 401, 403],
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body' => http_build_query(['client_id' => $c['account_ref'], 'client_secret' => $secret, 'grant_type' => 'client_credentials'])]);
    if ($r['state'] === 'ok') {
        $t = is_array($r['json']) ? ($r['json']['access_token'] ?? '') : '';
        if (is_string($t) && $t !== '') { return ['ok', $t, '']; }
    }
    if (!in_array($r['state'], ['unauthorized', 'invalid', 'ok'], true)) { return [$r['state'], '', '']; }   // unreachable or busy: a second try would not help
    // the API client was refused (or this address has no token endpoint): try the same values as a Management Console login
    $word = static fn(mixed $v): string => is_string($v) && preg_match('/^[A-Za-z_]{3,40}$/', $v) ? $v : '';   // a status word of the PBX, shown only when it is plain letters
    $l = connector_http('POST', $base . '/webclient/api/Login/GetAccessToken', ['verify_tls' => $tls, 'unauthorized' => [400, 401, 403],
        'headers' => ['Content-Type: application/json'],
        'body' => json_encode(['SecurityCode' => '', 'Password' => $secret, 'Username' => (string) $c['account_ref']])]);
    if ($l['state'] === 'ok' && is_array($l['json'])) {
        $status = is_string($l['json']['Status'] ?? null) ? $l['json']['Status'] : '';
        $t = is_array($l['json']['Token'] ?? null) ? ($l['json']['Token']['access_token'] ?? '') : '';
        if ($status === 'AuthSuccess' && is_string($t) && $t !== '') { return ['ok', $t, '']; }
        $detail = ' (API client: HTTP ' . $r['code'] . ($r['oauth'] !== '' ? ', ' . $r['oauth'] : '') . '. Login: HTTP ' . $l['code'] . ($word($status) !== '' ? ', ' . $word($status) : '') . '.)';
        if (preg_match('/two|factor|security|2fa|code/i', $status)) { return ['unauthorized', '', 'The login works but has two-step sign-in turned on, which can not be used here. Use an API client instead (Integrations, API).']; }
        return ['unauthorized', '', 'The PBX refused both: the API client (Client ID and secret) and a Management Console login with the same values.' . $detail];
    }
    $why = ' (API client: HTTP ' . $r['code'] . ($r['oauth'] !== '' ? ', ' . $r['oauth'] : '') . '. Login: HTTP ' . $l['code'] . '.)';
    if ($r['state'] === 'invalid' && $l['state'] === 'invalid') { return ['invalid', '', 'Neither sign-in address exists on that server' . $why . ' Check the server address and that it is 3CX version 20.']; }   // neither path exists at this address
    return [$l['state'] === 'unreachable' || $l['state'] === 'busy' ? $l['state'] : 'unauthorized', '', 'The PBX refused both: the API client (Client ID and secret) and a Management Console login with the same values.' . $why];
}

/**
 * Asks 3CX for the calls of a period. PBXs differ in what they accept, and a PBX with a large history must never be asked for "everything"
 * (it hangs), so every form is limited to the period. In this order:
 *   1 and 2: the call-log function of the reports (ReportCallLogData, Pbx.GetCallLogData), with and without sorting: made for exactly this
 *   3 and 4: the CallHistoryView list with a start date, with and without sorting
 * The next form is tried only after an answer of the "invalid" kind (a refused question, HTTP 400, 404 or 500): a refusal of the sign-in, a missing
 * permission, no answer and "busy" would not change with another form. Every request is in the log.
 * Returns the connector_http() result plus 'form' (1 to 4) and 'forms' (4).
 */
function threecx_history(array $c, string $token, int $since, int $top, int $skip): array
{
    $base = rtrim((string) $c['base_url'], '/');
    $page = '$top=' . $top . '&$skip=' . $skip;
    $fn = $base . "/xapi/v1/ReportCallLogData/Pbx.GetCallLogData(periodFrom=" . gmdate('Y-m-d\TH:i:s\Z', $since) . ',periodTo=' . gmdate('Y-m-d\TH:i:s\Z', time() + 3600)
        . ",sourceType=0,sourceFilter='',destinationType=0,destinationFilter='',callsType=0,callTimeFilterType=0,callTimeFilterFrom='0:00:0',callTimeFilterTo='0:00:0',hidePcalls=false)";
    $from = '$filter=' . rawurlencode('StartTime ge ' . gmdate('Y-m-d\TH:i:s\Z', $since));
    $forms = [$fn . '?' . $page . '&$orderby=' . rawurlencode('StartTime desc'), $fn . '?' . $page,
        $base . '/xapi/v1/CallHistoryView?' . $page . '&$orderby=' . rawurlencode('StartTime desc') . '&' . $from, $base . '/xapi/v1/CallHistoryView?' . $page . '&' . $from];
    $r = [];
    foreach ($forms as $i => $url) {
        $r = connector_http('GET', $url, ['verify_tls' => (bool) $c['verify_tls'], 'forbidden' => true, 'snippet' => true,
            'timeout' => (int) ($c['timeout'] ?? CONNECTOR_SLOW_TIMEOUT_S), 'headers' => ['Authorization: Bearer ' . $token]]);
        $r['form'] = $i + 1; $r['forms'] = count($forms);
        if ($r['state'] !== 'invalid') { break; }
    }
    return $r;
}

/**
 * The unique ID of one record of the 3CX call history. Not SegmentId on its own: that is the number of the segment WITHIN a call (1, 2, 3 ...), so
 * every call shares it. The ID is made from everything that tells records apart (the ids, the start time, both numbers), so two different segments
 * can never get the same one, and the same segment gets the same one at every sync.
 */
function threecx_external_id(array $it): string
{
    $parts = [];
    foreach (['Id', 'CallHistoryId', 'CallId', 'SegmentId', 'StartTime', 'SourceDn', 'SourceCallerId', 'DestinationDn', 'DestinationCallerId'] as $k) { $parts[] = is_scalar($it[$k] ?? null) ? (string) $it[$k] : ''; }
    return implode('', $parts) === '' ? '' : 'c' . substr(sha1(implode('|', $parts)), 0, 32);
}

/** A length of time as 3CX writes it: 42, "00:01:30", "1.00:00:05" or "PT1M30S" (ISO 8601), as whole seconds. 0 when it can not be read. */
function threecx_seconds(mixed $v): int
{
    if (is_int($v) || is_float($v) || (is_string($v) && ctype_digit($v))) { return max(0, (int) $v); }
    $s = trim((string) $v);
    if (preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:\.\d+)?S)?$/', $s, $m) && $s !== 'P' && $s !== 'PT') { return (int) ($m[1] ?? 0) * 86400 + (int) ($m[2] ?? 0) * 3600 + (int) ($m[3] ?? 0) * 60 + (int) ($m[4] ?? 0); }
    if (preg_match('/^(?:(\d+)\.)?(\d{1,2}):(\d{2}):(\d{2})(?:\.\d+)?$/', $s, $m)) { return (int) $m[1] * 86400 + (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) $m[4]; }
    return 0;
}

/**
 * When the call history can not be read, asks the PBX what it offers (its OData description, /xapi/v1/$metadata) and writes the useful part to the
 * log: whether CallHistoryView exists, which fields it has, and which other call-log views or functions there are. Only names, nothing else.
 */
function threecx_diagnose(array $c, string $token): void
{
    $r = connector_http('GET', rtrim((string) $c['base_url'], '/') . '/xapi/v1/$metadata', ['verify_tls' => (bool) $c['verify_tls'], 'max_body' => 16777216, 'timeout' => (int) ($c['timeout'] ?? CONNECTOR_SLOW_TIMEOUT_S),
        'headers' => ['Authorization: Bearer ' . $token, 'Accept: application/xml']]);
    if ($r['state'] !== 'ok') { connector_trace('3CX: the description of the API (/xapi/v1/$metadata) could not be read'); return; }
    $xml = $r['body'];
    if (preg_match('/<EntityType Name="CallHistoryView"[^>]*>(.*?)<\/EntityType>/s', $xml, $m)) {
        preg_match_all('/<Property Name="([A-Za-z0-9_]{1,40})"/', $m[1], $p);
        connector_trace('3CX: CallHistoryView exists and has the fields: ' . implode(', ', array_slice($p[1], 0, 40)));
    } else {
        connector_trace('3CX: CallHistoryView is NOT part of the API of this PBX');
    }
    preg_match_all('/Name="([A-Za-z0-9_.]*(?:CallLog|CallHistory|CallReport)[A-Za-z0-9_.]*)"/i', $xml, $n);
    $names = array_values(array_unique($n[1]));
    connector_trace('3CX: other call views and functions in the API: ' . ($names ? implode(', ', array_slice($names, 0, 12)) : 'none found'));
}

function threecx_collect(array $c, string $secret, int $since): array
{
    [$st, $token, $why] = threecx_token($c, $secret);
    if ($st !== 'ok') { if ($why !== '') { connector_trace('3CX: ' . $why); } return ['state' => $st, 'calls' => [], 'sms' => []]; }
    $base = rtrim((string) $c['base_url'], '/');
    $calls = [];
    $all = [];   // the segments inside the period
    for ($page = 0; $page < 10; $page++) {
        $r = threecx_history($c, $token, $since, 200, $page * 200);
        if ($r['state'] === 'unreachable') { connector_trace('3CX: signed in, but the PBX did not answer the call-history request in time'); }
        if ($r['state'] !== 'ok' || !is_array($r['json'])) { return ['state' => $r['state'] === 'ok' ? 'invalid' : $r['state'], 'calls' => [], 'sms' => []]; }
        $items = (array) ($r['json']['value'] ?? []);
        if ($page === 0) { connector_trace('3CX: call history read in form ' . $r['form'] . ' of ' . $r['forms'] . '; ' . ($items && is_array($items[0]) ? 'fields of the first record: ' . implode(', ', array_slice(array_keys($items[0]), 0, 30)) : 'no records in the period')); }
        if ($page === 0 && $items) {   // the fields that look like IDs of the first records (numbers only): to see whether they tell records apart
            $ids = [];
            foreach (array_slice($items, 0, 3) as $i => $it) {
                $pairs = [];
                foreach ((array) $it as $k => $v) { if (is_scalar($v) && preg_match('/Id$/', (string) $k) && !str_ends_with((string) $k, 'CallerId')) { $pairs[] = $k . '=' . mb_substr((string) $v, 0, 24); } }
                $ids[] = '#' . ($i + 1) . ' ' . implode(' ', $pairs);
            }
            connector_trace('3CX: ID fields of the first records: ' . implode(' | ', $ids));
        }
        foreach ($items as $it) {
            if (!is_array($it)) { continue; }
            $when = connector_time($it['StartTime'] ?? null);
            if ($when !== null && $when < $since) { continue; }   // the form without a start date returns older records too
            $all[] = $it;
        }
        if (count($items) < 200 || count($all) >= CONNECTOR_MAX_RECORDS) { break; }
    }
    // one call is several segments (queue, the answer, a transfer): they share MainCallHistoryId and become one row
    $groups = [];
    foreach ($all as $it) {
        $main = $it['MainCallHistoryId'] ?? null;
        $groups[is_string($main) && $main !== '' ? 'm' . $main : threecx_external_id($it)][] = $it;
    }
    foreach ($groups as $key => $segs) {
        $row = threecx_call((string) $key, $segs);
        if ($row !== null) { $calls[] = $row; }
    }
    connector_trace('3CX: ' . count($all) . ' segments read, made into ' . count($calls) . ' calls');
    return ['state' => 'ok', 'calls' => $calls, 'sms' => []];
}

/** The first of these values that is not empty, as text. */
function threecx_first(mixed ...$values): string
{
    foreach ($values as $v) { if (is_scalar($v) && trim((string) $v) !== '') { return trim((string) $v); } }
    return '';
}

/**
 * One call from its segments (the records of 3CX that share a MainCallHistoryId, for example a wait in the queue, the answer, a transfer).
 *   when: the start of the first segment. direction: from the first segment. answered: a segment has the status Answered.
 *   length: the talking time of the answered segments (waiting and ringing are not counted).
 *   from/to: the numbers (for an incoming call the colleague who answered, else the one first rung). The names are those the PBX knows:
 *   remote_name is the other party, staff_name the colleague who took (incoming) or made (outgoing, internal) the call.
 * Returns null when the call can not be used (no start time).
 */
function threecx_call(string $key, array $segs): ?array
{
    usort($segs, static fn($a, $b) => strcmp((string) ($a['StartTime'] ?? ''), (string) ($b['StartTime'] ?? '')) ?: ((int) ($a['Indent'] ?? 0) <=> (int) ($b['Indent'] ?? 0)));
    $first = $segs[0];
    $isType = static fn(mixed $t, array $words): bool => (bool) array_filter($words, static fn($w) => stripos((string) $t, $w) !== false);
    $dir = isset($first['Direction']) ? connector_direction((string) $first['Direction'])
        : ($isType($first['SourceType'] ?? '', ['extern', 'line', 'trunk']) ? 'in' : ($isType($first['DestinationType'] ?? '', ['extern', 'line', 'trunk']) ? 'out' : 'internal'));
    $won = array_values(array_filter($segs, static fn($s) => isset($s['Status']) ? strcasecmp((string) $s['Status'], 'Answered') === 0 : !empty($s['Answered'])));
    $talk = 0;
    foreach ($won as $s) { $talk += threecx_seconds($s['TalkingDuration'] ?? $s['Talking'] ?? $s['TotalDuration'] ?? 0); }
    $taker = $won[0] ?? $first;   // the segment of the colleague who answered
    $last = $segs[count($segs) - 1];
    if ($dir === 'in') {
        $from = threecx_first($first['SourceCallerId'] ?? '', $first['SourceDn'] ?? '');
        $to = threecx_first($taker['DestinationCallerId'] ?? '', $taker['DestinationDn'] ?? '');
        $remote = threecx_first($first['SourceDisplayName'] ?? '');
        $staff = threecx_first($taker['DestinationDisplayName'] ?? '');
    } else {
        $from = threecx_first($first['SourceCallerId'] ?? '', $first['SourceDn'] ?? '');
        $to = threecx_first($first['DestinationCallerId'] ?? '', $first['DestinationDn'] ?? '');
        $remote = threecx_first($dir === 'out' ? $last['DestinationDisplayName'] ?? '' : $first['DestinationDisplayName'] ?? '');
        $staff = threecx_first($first['SourceDisplayName'] ?? '');
    }
    return comm_row('threecx', 'call', $key, $dir, $from, $to, $first['StartTime'] ?? null, $talk, $won ? 'answered' : 'missed', '', $remote, $staff);
}

// ---- Yeastar (P-Series OpenAPI 1.0)
function yeastar_token(array $c, string $secret): array
{
    $base = rtrim((string) $c['base_url'], '/');
    $r = connector_http('POST', $base . '/openapi/v1.0/get_token', ['verify_tls' => (bool) $c['verify_tls'], 'headers' => ['Content-Type: application/json'],
        'body' => json_encode(['username' => $c['account_ref'], 'password' => $secret])]);
    if ($r['state'] !== 'ok' || !is_array($r['json'])) { return [$r['state'] === 'ok' ? 'invalid' : $r['state'], '']; }
    if ((int) ($r['json']['errcode'] ?? -1) !== 0) { return ['unauthorized', '']; }
    $t = $r['json']['access_token'] ?? '';
    return is_string($t) && $t !== '' ? ['ok', $t] : ['invalid', ''];
}

function yeastar_status(string $s): string
{
    $s = strtoupper(trim($s));
    return match (true) { str_contains($s, 'ANSWER') && !str_contains($s, 'NO ANSWER') => 'answered', str_contains($s, 'NO ANSWER'), str_contains($s, 'VOICEMAIL') => 'missed', str_contains($s, 'BUSY') => 'busy', str_contains($s, 'FAIL') => 'failed', default => 'other' };
}

function yeastar_collect(array $c, string $secret, int $since): array
{
    [$st, $token] = yeastar_token($c, $secret);
    if ($st !== 'ok') { return ['state' => $st, 'calls' => [], 'sms' => []]; }
    $base = rtrim((string) $c['base_url'], '/');
    $calls = [];
    for ($page = 1; $page <= 5; $page++) {
        $url = $base . '/openapi/v1.0/cdr/list?' . http_build_query(['access_token' => $token, 'page' => $page, 'page_size' => 100, 'sort_by' => 'id', 'order_by' => 'desc']);
        $r = connector_http('GET', $url, ['verify_tls' => (bool) $c['verify_tls']]);
        if ($r['state'] !== 'ok' || !is_array($r['json'])) { return ['state' => $r['state'] === 'ok' ? 'invalid' : $r['state'], 'calls' => [], 'sms' => []]; }
        if ((int) ($r['json']['errcode'] ?? 0) !== 0) { return ['state' => 'unauthorized', 'calls' => [], 'sms' => []]; }
        $items = (array) ($r['json']['data'] ?? []);
        $old = false;
        foreach ($items as $it) {
            if (!is_array($it)) { continue; }
            $ts = connector_time($it['time'] ?? $it['start_time'] ?? null);
            if ($ts !== null && $ts < $since) { $old = true; continue; }
            $row = comm_row('yeastar', 'call', $it['id'] ?? $it['uid'] ?? '', connector_direction((string) ($it['type'] ?? $it['call_type'] ?? '')),
                $it['call_from'] ?? $it['src'] ?? '', $it['call_to'] ?? $it['dst'] ?? '', $it['time'] ?? $it['start_time'] ?? null,
                $it['talk_duration'] ?? $it['call_duration'] ?? 0, yeastar_status((string) ($it['status'] ?? $it['disposition'] ?? '')));
            if ($row !== null) { $calls[] = $row; }
        }
        if (count($items) < 100 || $old || count($calls) >= CONNECTOR_MAX_RECORDS) { break; }
    }
    return ['state' => 'ok', 'calls' => $calls, 'sms' => []];
}

// ---- Short.io
function shortio_headers(string $secret): array { return ['Authorization: ' . $secret, 'Content-Type: application/json']; }

/** @return array{0: string, 1: list<array>} state, domains (id and hostname) */
function shortio_domains(array $c, string $secret): array
{
    $base = rtrim($c['base_url'] !== '' ? (string) $c['base_url'] : 'https://api.short.io', '/');
    $r = connector_http('GET', $base . '/api/domains', ['headers' => shortio_headers($secret)]);
    if ($r['state'] !== 'ok' || !is_array($r['json'])) { return [$r['state'] === 'ok' ? 'invalid' : $r['state'], []]; }
    $list = [];
    foreach ($r['json'] as $d) { if (is_array($d) && isset($d['id'], $d['hostname'])) { $list[] = ['id' => (string) $d['id'], 'hostname' => (string) $d['hostname']]; } }
    return ['ok', $list];
}

function shortio_row(array $it, string $domain): ?array
{
    $id = (string) ($it['idString'] ?? $it['id'] ?? '');
    $short = (string) ($it['secureShortURL'] ?? $it['shortURL'] ?? '');
    $orig = (string) ($it['originalURL'] ?? '');
    if ($id === '' || strlen($id) > 100 || $short === '' || $orig === '' || !preg_match('~^https?://~i', $short) || !preg_match('~^https?://~i', $orig)) { return null; }
    $company = null;
    foreach ((array) ($it['tags'] ?? []) as $tag) { if (is_string($tag) && preg_match('/^company-(\d{1,9})$/', $tag, $m)) { $company = (int) $m[1]; } }
    $ts = connector_time($it['createdAt'] ?? null);
    return ['external_id' => $id, 'domain' => mb_substr($domain, 0, 190), 'short_url' => mb_substr($short, 0, 400), 'original_url' => mb_substr($orig, 0, 2000),
        'title' => mb_substr((string) ($it['title'] ?? ''), 0, 190), 'company_id' => $company, 'created_remote' => $ts !== null ? date('Y-m-d H:i:s', $ts) : null];
}

/** @return array{state: string, links: list<array>} */
function shortio_collect(array $c, string $secret): array
{
    [$st, $domains] = shortio_domains($c, $secret);
    if ($st !== 'ok') { return ['state' => $st, 'links' => []]; }
    $base = rtrim($c['base_url'] !== '' ? (string) $c['base_url'] : 'https://api.short.io', '/');
    $links = [];
    foreach (array_slice($domains, 0, 5) as $d) {
        $token = '';
        for ($page = 0; $page < 3; $page++) {
            $url = $base . '/api/links?domain_id=' . rawurlencode($d['id']) . '&limit=150' . ($token !== '' ? '&pageToken=' . rawurlencode($token) : '');
            $r = connector_http('GET', $url, ['headers' => shortio_headers($secret)]);
            if ($r['state'] !== 'ok' || !is_array($r['json'])) { return ['state' => $r['state'] === 'ok' ? 'invalid' : $r['state'], 'links' => []]; }
            foreach ((array) ($r['json']['links'] ?? []) as $it) {
                $row = is_array($it) ? shortio_row($it, $d['hostname']) : null;
                if ($row !== null) { $links[] = $row; }
            }
            $token = (string) ($r['json']['nextPageToken'] ?? '');
            if ($token === '' || count($links) >= CONNECTOR_MAX_RECORDS) { break; }
        }
    }
    return ['state' => 'ok', 'links' => $links];
}

/** Creates a short link on the first domain of the account. @return array{0: bool, 1: string, 2: ?array} ok, message, the stored row */
function shortio_create(PDO $pdo, string $originalUrl, string $title, ?int $companyId, string $by): array
{
    $c = connector_get($pdo, 'shortio');
    if (!$c['enabled'] || !connector_has_secret($c)) { return [false, 'Short.io is not connected. An admin can connect it under Settings, Connectors.', null]; }
    $secret = connector_secret($c);
    [$st, $domains] = shortio_domains($c, $secret);
    if ($st !== 'ok' || !$domains) { return [false, $st === 'ok' ? 'Your Short.io account has no domain yet.' : connector_state_text($st), null]; }
    $base = rtrim($c['base_url'] !== '' ? (string) $c['base_url'] : 'https://api.short.io', '/');
    $payload = ['domain' => $domains[0]['hostname'], 'originalURL' => $originalUrl];
    if ($title !== '') { $payload['title'] = $title; }
    if ($companyId !== null) { $payload['tags'] = ['company-' . $companyId]; }
    $r = connector_http('POST', $base . '/links', ['headers' => shortio_headers($secret), 'body' => json_encode($payload)]);
    if ($r['state'] !== 'ok' || !is_array($r['json'])) { return [false, $r['state'] === 'ok' ? 'Short.io answered with something unexpected.' : connector_state_text($r['state']), null]; }
    $row = shortio_row($r['json'] + ['originalURL' => $originalUrl], $domains[0]['hostname']);
    if ($row === null) { return [false, 'Short.io answered with something unexpected.', null]; }
    $row['company_id'] = $companyId;
    shortlinks_store($pdo, [$row], $by);
    return [true, 'Short link created: ' . $row['short_url'], $row];
}

function shortlinks_store(PDO $pdo, array $rows, string $by = ''): int
{
    $companies = array_flip(array_map('intval', $pdo->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_COLUMN)));
    $ins = $pdo->prepare('INSERT INTO short_links (external_id, domain, short_url, original_url, title, company_id, created_remote, created_by, synced_at) VALUES (?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE domain = VALUES(domain), short_url = VALUES(short_url), original_url = VALUES(original_url), title = VALUES(title), created_remote = VALUES(created_remote),
        company_id = COALESCE(company_id, VALUES(company_id)), synced_at = NOW()');
    $n = 0;
    foreach ($rows as $r) {
        $co = $r['company_id'] !== null && isset($companies[(int) $r['company_id']]) ? (int) $r['company_id'] : null;
        $ins->execute([$r['external_id'], $r['domain'], $r['short_url'], $r['original_url'], $r['title'], $co, $r['created_remote'], mb_substr($by, 0, 60)]);
        $n++;
    }
    return $n;
}

// ---------- Testing and syncing ----------

function connector_state_text(string $state): string
{
    return match ($state) {
        'ok' => 'Connected.',
        'unauthorized' => 'Unauthorized: the service rejected the credentials.',
        'forbidden' => 'Signed in, but not allowed: the service accepted the credentials and then refused this request (HTTP 403). The account or API client has no permission to read this data. Check its role and permissions at the service.',
        'unreachable' => 'Unreachable: the service did not answer in time, or refused the connection.',
        'busy' => 'The service says it is busy (too many requests). Try again in a minute.',
        'never' => 'Not synced yet.',
        default => 'Invalid response: the address answered, but not the way this service does. Check the address.',
    };
}

/** Writes one entry of the log under a connector: what was done, the result, and the requests behind it (see connector_trace()). */
function connector_log_add(PDO $pdo, string $p, string $action, string $state, string $message, ?string $by = null): void
{
    try {
        $by ??= (string) ($_SESSION['admin']['username'] ?? 'scheduler');
        $pdo->prepare('INSERT INTO connector_log (provider, action, state, message, detail, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$p, mb_substr($action, 0, 10), mb_substr($state, 0, 20), mb_substr($message, 0, 400), mb_substr(implode("\n", connector_trace()), 0, 1500), mb_substr($by, 0, 60)]);
        $pdo->prepare('DELETE FROM connector_log WHERE provider = ? AND id <= COALESCE((SELECT id FROM (SELECT id FROM connector_log WHERE provider = ? ORDER BY id DESC LIMIT 1 OFFSET 40) x), 0)')->execute([$p, $p]);   // the newest 40 stay
    } catch (Throwable $e) { error_log('Connector log failed (run database/migrate.php): ' . $e->getMessage()); }   // the log must never break a test or a sync
}

/** The newest entries of the log of one connector. */
function connector_log_recent(PDO $pdo, string $p, int $n = 10): array
{
    try {
        $s = $pdo->prepare('SELECT * FROM connector_log WHERE provider = ? ORDER BY id DESC LIMIT ' . (int) $n);
        $s->execute([$p]);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Checks the credentials without saving anything. $c holds the settings to try (as in the table) and $secret the secret to use.
 * @return array{0: string, 1: string} state, message
 */
function connector_test(string $p, array $c, string $secret): array
{
    @set_time_limit(120);   // a slow phone system may need most of CONNECTOR_SLOW_TIMEOUT_S for one request, and the web server's own limit is 30 seconds
    $tls = (bool) $c['verify_tls'];
    switch ($p) {
        case 'twilio':
            $base = rtrim($c['base_url'] !== '' ? (string) $c['base_url'] : 'https://api.twilio.com', '/');
            $r = connector_http('GET', $base . '/2010-04-01/Accounts/' . rawurlencode((string) $c['account_ref']) . '.json', ['basic' => [(string) $c['account_ref'], $secret]]);
            if ($r['state'] === 'ok' && is_array($r['json']) && isset($r['json']['sid'])) { return ['ok', 'Connected to the Twilio account' . (isset($r['json']['friendly_name']) ? ' "' . mb_substr((string) $r['json']['friendly_name'], 0, 60) . '"' : '') . '.']; }
            return [$r['state'] === 'ok' ? 'invalid' : $r['state'], connector_state_text($r['state'] === 'ok' ? 'invalid' : $r['state'])];
        case 'shortio':
            [$st, $d] = shortio_domains($c, $secret);
            return $st === 'ok' ? ['ok', 'Connected. Found ' . count($d) . ' domain' . (count($d) === 1 ? '' : 's') . '.'] : [$st, connector_state_text($st)];
        case 'threecx':
            [$st, $token, $why] = threecx_token($c, $secret);
            if ($st !== 'ok') { return [$st, connector_state_text($st) . ($why !== '' ? ' ' . $why : '')]; }
            // only the last day: a question without a period makes a PBX with a large history read all of it
            $r = threecx_history($c, $token, time() - 86400, 1, 0);
            if ($r['state'] === 'ok' && is_array($r['json'])) { $first = (array) ($r['json']['value'] ?? []); connector_trace('3CX: call history read in form ' . $r['form'] . ' of ' . $r['forms'] . ($first && is_array($first[0]) ? '; fields of the first record: ' . implode(', ', array_slice(array_keys($first[0]), 0, 30)) : '; no calls in the last day')); }
            if ($r['state'] === 'invalid') {
                threecx_diagnose($c, $token);
                return ['invalid', 'Signed in (the credentials and the permissions are right), but 3CX would not answer the call-history request' . ($r['code'] > 0 ? ' (HTTP ' . $r['code'] . ')' : '') . ', not even in the plainest form. The log below shows what 3CX said and which call-history views and fields this PBX offers: send it to the developer.'];
            }
            if ($r['state'] === 'unreachable') { return ['unreachable', 'Signed in (the credentials and the permissions are right), but 3CX did not answer the call-history request in time. 3CX can be slow with a large call history. Press Test connection again; if it keeps timing out, the call history of this PBX is too slow to read and your 3CX partner should be asked about the response time of /xapi/v1/CallHistoryView.']; }
            if ($r['state'] === 'forbidden') { return ['forbidden', 'Signed in, but 3CX refused the call history (HTTP 403). The credentials are right: the API client is not allowed to read call history. In the 3CX Management Console open Integrations, API, edit this client, make sure it may use the Configuration API (XAPI) and give it a role that may see call reports (to confirm that this is the cause, try the system owner or admin role first).']; }
            return $r['state'] === 'ok' && is_array($r['json']) ? ['ok', 'Connected to 3CX. The call history can be read.'] : [$r['state'] === 'ok' ? 'invalid' : $r['state'], connector_state_text($r['state'] === 'ok' ? 'invalid' : $r['state'])];
        case 'yeastar':
            [$st, $token] = yeastar_token($c, $secret);
            if ($st !== 'ok') { return [$st, connector_state_text($st)]; }
            $r = connector_http('GET', rtrim((string) $c['base_url'], '/') . '/openapi/v1.0/cdr/list?' . http_build_query(['access_token' => $token, 'page' => 1, 'page_size' => 1]), ['verify_tls' => $tls]);
            return $r['state'] === 'ok' && is_array($r['json']) && (int) ($r['json']['errcode'] ?? -1) === 0 ? ['ok', 'Connected to Yeastar. The call log can be read.'] : [$r['state'] === 'ok' ? 'invalid' : $r['state'], connector_state_text($r['state'] === 'ok' ? 'invalid' : $r['state'])];
    }
    return ['invalid', 'Unknown service.'];
}

const COMM_REFRESH_DAYS = 2;   // a call or message that is already stored is only updated while it is this recent; older ones are kept as they were

/** What the last comm_store() did: new rows, rows refreshed (recent ones that were stored before) and rows kept as they were. */
function comm_store_stats(?array $set = null): array
{
    static $stats = ['new' => 0, 'refreshed' => 0, 'kept' => 0];
    if ($set !== null) { $stats = $set; }
    return $stats;
}

/**
 * Stores normalised calls and messages; returns how many different records there were.
 * A record that is not stored yet is added. One that IS stored is left exactly as it was, with one exception: while it is less than
 * COMM_REFRESH_DAYS old it is updated, because a call can still change then (a transfer adds a segment, a call in progress gets its length).
 * So the history that was synced earlier is never overwritten by a later sync, also when the service answers differently or has dropped the call.
 * The person and company a record was matched to are never touched. See comm_store_stats() for the counts.
 */
function comm_store(PDO $pdo, array $rows, bool $keepText): int
{
    $recent = 'started_at >= NOW() - INTERVAL ' . COMM_REFRESH_DAYS . ' DAY';   // evaluated on the stored row: started_at itself is assigned last
    $keep = static fn(string $col): string => "$col = IF($recent, VALUES($col), $col)";
    $ins = $pdo->prepare('INSERT INTO comm_log (provider, kind, external_id, direction, from_number, to_number, remote_name, staff_name, party_key, started_at, duration_s, status, body, synced_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE ' . implode(', ', array_map($keep, ['direction', 'from_number', 'to_number', 'remote_name', 'staff_name', 'party_key', 'duration_s', 'status', 'body', 'synced_at'])) . ', ' . $keep('started_at'));
    $stats = ['new' => 0, 'refreshed' => 0, 'kept' => 0];
    $seen = [];   // the records that end up as a row: one per (service, kind, ID); records with the same ID replace each other
    foreach (array_slice($rows, 0, CONNECTOR_MAX_RECORDS * 2) as $r) {
        $ins->execute([$r['provider'], $r['kind'], $r['external_id'], $r['direction'], $r['from'], $r['to'], $r['remote_name'] ?? '', $r['staff_name'] ?? '', $r['party_key'], $r['started_at'], $r['duration_s'], $r['status'],
            ($keepText && $r['kind'] === 'sms') ? $r['body'] : '']);
        $id = $r['provider'] . '|' . $r['kind'] . '|' . $r['external_id'];
        if (!isset($seen[$id])) { $n = $ins->rowCount(); $stats[$n === 1 ? 'new' : ($n === 0 ? 'kept' : 'refreshed')]++; }   // 1 = added, 2 = updated, 0 = nothing changed
        $seen[$id] = true;
    }
    comm_store_stats($stats);
    return count($seen);
}

/** The message after a good sync: for calls and messages with what was new and what was left as it was. */
function connector_ok_text(string $name, int $count, bool $hasStats): string
{
    if (!$hasStats) { return $name . ': ' . $count . ' record' . ($count === 1 ? '' : 's') . ' up to date.'; }
    $s = comm_store_stats();
    return $name . ': ' . $count . ' record' . ($count === 1 ? '' : 's') . ' read. ' . $s['new'] . ' new, ' . $s['refreshed'] . ' updated (from the last ' . COMM_REFRESH_DAYS . ' days), ' . $s['kept'] . ' already stored and kept as they were.';
}

/**
 * Fetches and stores the data of one service. Only one sync per service runs at a time.
 * @return array{state: string, count: int, message: string}
 */
function connector_sync(PDO $pdo, string $p, bool $manual = false): array
{
    $defs = connector_defs();
    if (!isset($defs[$p])) { return ['state' => 'invalid', 'count' => 0, 'message' => 'Unknown service.']; }
    $c = connector_get($pdo, $p);
    if (!$c['enabled'] || !connector_has_secret($c)) { return ['state' => 'invalid', 'count' => 0, 'message' => $defs[$p]['name'] . ' is not turned on.']; }
    @set_time_limit(600);   // up to ten requests of CONNECTOR_SLOW_TIMEOUT_S each (a long history is read in pages of 200)
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $lock->execute(['connector_sync_' . $p]);
    if ((int) $lock->fetchColumn() !== 1) { return ['state' => 'busy', 'count' => 0, 'message' => 'A sync of ' . $defs[$p]['name'] . ' is already running.']; }
    try {
        connector_trace(null, true);
        $secret = connector_secret($c);
        $since = time() - CONNECTOR_WINDOW_DAYS * 86400;
        $res = match ($p) {
            'twilio' => twilio_collect($c, $secret, $since),
            'threecx' => threecx_collect($c, $secret, $since),
            'yeastar' => yeastar_collect($c, $secret, $since),
            default => shortio_collect($c, $secret),
        };
        $count = 0;
        if ($res['state'] === 'ok') {
            if ($p === 'shortio') { $count = shortlinks_store($pdo, $res['links']); }
            else {
                $all = array_merge($res['calls'], $res['sms']);
                $count = comm_store($pdo, $all, (bool) $c['keep_text']);
                comm_match($pdo);
                if ($count < count($all)) { connector_trace('WARNING: ' . count($all) . ' records came back but only ' . $count . ' of them have a different ID, so the others replaced each other. The ID of this service is not unique per record: send this log to the developer.'); }
            }
        }
        $pdo->prepare('UPDATE connectors SET last_sync_at = NOW(), last_state = ?, last_count = ? WHERE provider = ?')->execute([$res['state'], $count, $p]);
        if ($res['state'] === 'ok' && $p !== 'shortio') { $st = comm_store_stats(); connector_trace('Stored: ' . $st['new'] . ' new, ' . $st['refreshed'] . ' updated (from the last ' . COMM_REFRESH_DAYS . ' days), ' . $st['kept'] . ' already stored and left as they were.'); }
        if ($manual || $res['state'] !== $c['last_state']) {   // a scheduled run is only written down when the result changed, so the log does not fill up every 5 minutes
            connector_log_add($pdo, $p, $manual ? 'sync' : 'scheduled', $res['state'], $res['state'] === 'ok' ? connector_ok_text($defs[$p]['name'], $count, $p !== 'shortio') : connector_state_text($res['state']));
        }
        if ($manual || $res['state'] !== $c['last_state'] && $res['state'] !== 'ok') {
            log_activity($res['state'] === 'ok' ? 'info' : 'warning', $defs[$p]['name'] . ' sync', $res['state'] . ' · ' . $count . ' records');
        }
        return ['state' => $res['state'], 'count' => $count, 'message' => $res['state'] === 'ok' ? connector_ok_text($defs[$p]['name'], $count, $p !== 'shortio') : connector_state_text($res['state'])];
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['connector_sync_' . $p]);
    }
}
