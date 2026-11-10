<?php
declare(strict_types=1);
require_once __DIR__ . '/monitor.php';   // secret_encrypt() / secret_decrypt() (AES-256-GCM with the app key)

/*
 * Uptime Kuma integration.
 * Kuma has no REST API, but it publishes Prometheus metrics at {KUMA_URL}/metrics behind HTTP Basic auth
 * (empty user name, API key as the password). This module stores the connection, fetches that endpoint, parses
 * it and caches the result so dashboard traffic never reaches Kuma more often than the poll interval.
 *
 * The API key is encrypted at rest, is never returned to the browser, and never appears in logs or error messages.
 */

const KUMA_POLL_DEFAULT = 30;
const KUMA_POLL_MIN = 10;
const KUMA_POLL_MAX = 300;
const KUMA_TIMEOUT_S = 5;
const KUMA_MAX_BODY = 2 * 1024 * 1024;   // a /metrics page is a few KB per monitor; refuse anything absurd
const KUMA_CERT_WARN_DAYS = 14;

// ---------- Settings storage ----------
/** @return array{url: string, poll: int, has_key: bool, last4: ?string, key_enc: ?string, updated_at: ?string} */
function kuma_settings(PDO $pdo): array
{
    $row = $pdo->query('SELECT kuma_url, api_key_enc, key_last4, poll_interval_s, updated_at FROM kuma_settings WHERE id = 1')->fetch();
    if (!$row) {
        return ['url' => '', 'poll' => KUMA_POLL_DEFAULT, 'has_key' => false, 'last4' => null, 'key_enc' => null, 'updated_at' => null];
    }
    return [
        'url' => (string) $row['kuma_url'], 'poll' => (int) $row['poll_interval_s'],
        'has_key' => !empty($row['api_key_enc']), 'last4' => $row['key_last4'], 'key_enc' => $row['api_key_enc'], 'updated_at' => $row['updated_at'],
    ];
}

/** What the browser may see. The key itself never leaves the server. */
function kuma_public_view(array $s): array
{
    return ['kumaUrl' => $s['url'], 'pollIntervalSeconds' => $s['poll'], 'hasApiKey' => $s['has_key'], 'keyLast4' => $s['has_key'] ? $s['last4'] : null];
}

/**
 * Validates and saves. A blank $apiKey keeps the stored key; a value replaces it.
 * @return array{0: bool, 1: array<string,string>} ok, errors by field
 */
function kuma_save(PDO $pdo, string $url, string $apiKey, int|string $poll, int $adminId): array
{
    $errors = [];
    [$ok, $msg, $norm] = kuma_validate_url($url);
    if (!$ok) { $errors['kumaUrl'] = $msg; }
    if (!is_numeric($poll) || (int) $poll < KUMA_POLL_MIN || (int) $poll > KUMA_POLL_MAX || (string) (int) $poll !== trim((string) $poll)) {
        $errors['pollIntervalSeconds'] = 'Enter a whole number of seconds from ' . KUMA_POLL_MIN . ' to ' . KUMA_POLL_MAX . '.';
    }
    $current = kuma_settings($pdo);
    $apiKey = trim($apiKey);
    if ($apiKey === '' && !$current['has_key']) { $errors['apiKey'] = 'Enter the API key.'; }
    if (strlen($apiKey) > 500 || preg_match('/[\x00-\x1f\x7f]/', $apiKey)) { $errors['apiKey'] = 'The API key looks invalid (too long or contains control characters).'; }
    if ($errors) { return [false, $errors]; }

    $enc = $current['key_enc'];
    $last4 = $current['last4'];
    if ($apiKey !== '') {
        try { $enc = secret_encrypt($apiKey); }
        catch (RuntimeException $e) { return [false, ['apiKey' => 'The encryption key is not configured on the server (app_key in includes/config.local.php), so the API key was not stored.']]; }
        $last4 = substr($apiKey, -4);
    }
    $pdo->prepare('INSERT INTO kuma_settings (id, kuma_url, api_key_enc, key_last4, poll_interval_s, updated_by, updated_at) VALUES (1, ?, ?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE kuma_url = VALUES(kuma_url), api_key_enc = VALUES(api_key_enc), key_last4 = VALUES(key_last4), poll_interval_s = VALUES(poll_interval_s), updated_by = VALUES(updated_by), updated_at = NOW()')
        ->execute([$norm, $enc, $last4, (int) $poll, $adminId]);
    kuma_cache_clear();
    return [true, []];
}

function kuma_remove_key(PDO $pdo, int $adminId): void
{
    $pdo->prepare('UPDATE kuma_settings SET api_key_enc = NULL, key_last4 = NULL, updated_by = ?, updated_at = NOW() WHERE id = 1')->execute([$adminId]);
    kuma_cache_clear();
}

// ---------- URL rules ----------
/** Kuma usually lives on the internal network, so private and loopback addresses are allowed here (admin-only setting).
 *  Link-local (cloud metadata), unspecified, multicast and broadcast addresses are not. */
function kuma_ip_blocked(string $ip): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) { return true; }
    if (strlen($bin) === 16) {
        $embedded = [];
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || substr($bin, 0, 12) === str_repeat("\0", 12)) { $embedded[] = substr($bin, 12, 4); }
        if (substr($bin, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) { $embedded[] = substr($bin, 12, 4); }
        foreach ($embedded as $v4) { if (kuma_ip_blocked((string) inet_ntop($v4))) { return true; } }
        if ($bin === str_repeat("\0", 16)) { return true; }                      // ::
        if ((ord($bin[0]) & 0xFE) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) { return true; }   // fe80::/10 link-local
        if (ord($bin[0]) === 0xFF) { return true; }                              // ff00::/8 multicast
        return false;
    }
    $n = ip2long($ip);
    foreach (['0.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
        [$net, $bits] = explode('/', $cidr);
        $mask = (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;
        if (($n & $mask) === (ip2long($net) & $mask)) { return true; }
    }
    return false;
}

/** @return array{0: bool, 1: string, 2: string} ok, message, normalised URL without trailing slash */
function kuma_validate_url(string $url): array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 300) { return [false, 'Enter the Kuma address, for example http://kuma.internal:3001', '']; }
    if (preg_match('/[\x00-\x20\x7f]/', $url)) { return [false, 'The address can not contain spaces or control characters.', '']; }
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) { return [false, 'Enter a full address that starts with http:// or https://', '']; }
    $scheme = strtolower((string) $p['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) { return [false, 'Only http:// and https:// addresses are supported.', '']; }
    if (isset($p['user']) || isset($p['pass'])) { return [false, 'Do not put a user name or password in the address. The API key has its own field.', '']; }
    if (isset($p['query']) || isset($p['fragment'])) { return [false, 'Remove everything after the path (no ? or #).', '']; }
    $port = isset($p['port']) ? (int) $p['port'] : 0;
    if (isset($p['port']) && ($port < 1 || $port > 65535)) { return [false, 'The port must be between 1 and 65535.', '']; }
    $host = strtolower(trim((string) $p['host'], '[]'));
    if (!preg_match('/^[a-z0-9.\-:]+$/', $host)) { return [false, 'The host name contains characters that are not allowed.', '']; }
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
    foreach ($ips as $ip) {
        if (kuma_ip_blocked((string) $ip)) { return [false, 'That address points to a reserved network (link-local, multicast or unspecified), which is not allowed.', '']; }
    }
    $path = rtrim((string) ($p['path'] ?? ''), '/');
    $norm = $scheme . '://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ($port ? ':' . $port : '') . $path;
    return [true, '', $norm];
}

// ---------- Fetching /metrics ----------
/**
 * @return array{state: string, body: string}  state: ok | unauthorized | unreachable | invalid
 * Never includes the key or upstream error text.
 */
function kuma_fetch(string $url, string $apiKey, int $timeout = KUMA_TIMEOUT_S): array
{
    [$ok, , $norm] = kuma_validate_url($url);
    if (!$ok) { return ['state' => 'unreachable', 'body' => '']; }
    if (!function_exists('curl_init')) { return ['state' => 'unreachable', 'body' => '']; }
    $p = parse_url($norm);
    $host = strtolower(trim((string) $p['host'], '[]'));
    $port = (int) ($p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80));

    $opts = [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => 0, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min($timeout, 3),
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => ':' . $apiKey,   // empty user name, key as the password
        CURLOPT_USERAGENT => 'TelecomlijnAdmin-KumaPoller/1.0', CURLOPT_HTTPHEADER => ['Accept: text/plain'],
    ];
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        // Resolve once, validate, and connect to exactly that address (no second lookup that could be rebound).
        $ips = @gethostbynamel($host) ?: [];
        if (!$ips) { return ['state' => 'unreachable', 'body' => '']; }
        foreach ($ips as $ip) { if (kuma_ip_blocked((string) $ip)) { return ['state' => 'unreachable', 'body' => '']; } }
        $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $ips[0]];
    }
    $body = '';
    $opts[CURLOPT_WRITEFUNCTION] = static function ($c, string $chunk) use (&$body): int {
        $body .= $chunk;
        return strlen($body) > KUMA_MAX_BODY ? 0 : strlen($chunk);
    };
    $ch = curl_init($norm . '/metrics');
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) { return ['state' => 'unreachable', 'body' => '']; }          // timeout, refused, DNS, TLS
    if ($status === 401 || $status === 403) { return ['state' => 'unauthorized', 'body' => '']; }
    if ($status >= 500 || $status === 0) { return ['state' => 'unreachable', 'body' => '']; }
    if ($status !== 200 || !kuma_looks_like_metrics($body)) { return ['state' => 'invalid', 'body' => '']; }
    return ['state' => 'ok', 'body' => $body];
}

/** Prometheus text has HELP/TYPE comments or "name value" samples. An HTML login page does not. */
function kuma_looks_like_metrics(string $body): bool
{
    $t = ltrim($body);
    if ($t === '' || $t[0] === '<') { return false; }
    return (bool) preg_match('/^(# (HELP|TYPE) [a-zA-Z_:]|[a-zA-Z_:][a-zA-Z0-9_:]*(\{[^\n]*\})?\s+\S+)/m', $body);
}

// ---------- Parsing the Prometheus text format ----------
/** @return ?array{name: string, labels: array<string,string>, value: float} */
function kuma_parse_sample(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || $line[0] === '#') { return null; }
    if (!preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)/', $line, $m)) { return null; }
    $name = $m[1];
    $i = strlen($name);
    $len = strlen($line);
    $labels = [];
    if ($i < $len && $line[$i] === '{') {
        $i++;
        while ($i < $len && $line[$i] !== '}') {
            while ($i < $len && ($line[$i] === ',' || $line[$i] === ' ')) { $i++; }
            if ($i < $len && $line[$i] === '}') { break; }
            if (!preg_match('/\G([a-zA-Z_][a-zA-Z0-9_]*)="/', $line, $km, 0, $i)) { return null; }
            $i += strlen($km[0]);
            $val = '';
            while ($i < $len && $line[$i] !== '"') {
                if ($line[$i] === '\\' && $i + 1 < $len) {
                    $n = $line[$i + 1];
                    $val .= $n === 'n' ? "\n" : $n;   // \\ and \" become \ and "
                    $i += 2;
                } else { $val .= $line[$i++]; }
            }
            if ($i >= $len) { return null; }   // unterminated value
            $i++;                              // closing quote
            $labels[$km[1]] = $val;
        }
        if ($i >= $len) { return null; }
        $i++;                                  // closing brace
    }
    $rest = trim(substr($line, $i));
    if ($rest === '') { return null; }
    $token = explode(' ', preg_replace('/\s+/', ' ', $rest))[0];   // an optional timestamp may follow
    if (!is_numeric($token)) {
        $lower = strtolower($token);
        if (!in_array($lower, ['nan', '+inf', '-inf', 'inf'], true)) { return null; }
        return ['name' => $name, 'labels' => $labels, 'value' => NAN];
    }
    return ['name' => $name, 'labels' => $labels, 'value' => (float) $token];
}

const KUMA_STATUS_LABELS = [1 => 'up', 0 => 'down', 2 => 'pending', 3 => 'maintenance'];
const KUMA_STATUS_ORDER = ['down' => 0, 'pending' => 1, 'maintenance' => 2, 'up' => 3, 'unknown' => 4];

/** @return list<array{name: string, type: string, url: string, status: ?int, statusLabel: string, responseTimeMs: ?int, certDaysRemaining: ?int, certValid: ?bool}> */
function kuma_parse_metrics(string $text): array
{
    $by = [];
    foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
        $s = kuma_parse_sample($line);
        if ($s === null || !in_array($s['name'], ['monitor_status', 'monitor_response_time', 'monitor_cert_days_remaining', 'monitor_cert_is_valid'], true)) { continue; }
        $l = $s['labels'];
        $clean = static fn(string $k): string => (($v = $l[$k] ?? '') === 'null') ? '' : $v;
        $name = $clean('monitor_name');
        $type = $clean('monitor_type');
        $url = $clean('monitor_url');
        $hostname = $clean('monitor_hostname');
        $port = $clean('monitor_port');
        $key = $name . "\0" . $type . "\0" . $url . "\0" . $hostname . "\0" . $port;
        $by[$key] ??= ['id' => substr(sha1($key), 0, 16), 'name' => $name, 'type' => $type, 'url' => $url !== '' ? $url : ($hostname !== '' ? $hostname . ($port !== '' ? ':' . $port : '') : ''), 'status' => null, 'responseTimeMs' => null, 'certDaysRemaining' => null, 'certValid' => null];
        $v = is_finite($s['value']) ? $s['value'] : null;
        switch ($s['name']) {
            case 'monitor_status': $by[$key]['status'] = $v === null ? null : (int) $v; break;
            case 'monitor_response_time': $by[$key]['responseTimeMs'] = ($v === null || $v < 0) ? null : (int) round($v); break;   // Kuma reports -1 while down
            case 'monitor_cert_days_remaining': $by[$key]['certDaysRemaining'] = $v === null ? null : (int) $v; break;
            case 'monitor_cert_is_valid': $by[$key]['certValid'] = $v === null ? null : $v >= 1; break;
        }
    }
    $out = [];
    foreach ($by as $m) {
        $out[] = [   // the documented field order, plus a stable id for linking and history
            'id' => $m['id'],
            'name' => $m['name'], 'type' => $m['type'], 'url' => $m['url'], 'status' => $m['status'],
            'statusLabel' => KUMA_STATUS_LABELS[$m['status']] ?? 'unknown',
            'responseTimeMs' => $m['responseTimeMs'], 'certDaysRemaining' => $m['certDaysRemaining'], 'certValid' => $m['certValid'],
        ];
    }
    usort($out, static fn($a, $b) => (KUMA_STATUS_ORDER[$a['statusLabel']] <=> KUMA_STATUS_ORDER[$b['statusLabel']]) ?: strcasecmp($a['name'], $b['name']));
    return $out;
}

function kuma_summarize(array $monitors): array
{
    $s = ['up' => 0, 'down' => 0, 'pending' => 0, 'maintenance' => 0, 'total' => count($monitors)];
    foreach ($monitors as $m) { if (isset($s[$m['statusLabel']])) { $s[$m['statusLabel']]++; } }
    return $s;
}

// ---------- Cache (one small file; the poll interval decides how often Kuma is really called) ----------
function kuma_cache_path(): string { return dirname(__DIR__, 2) . '/storage/kuma-cache.json'; }
function kuma_cache_clear(): void { $f = kuma_cache_path(); if (is_file($f)) { @unlink($f); } }
function kuma_cache_read(): ?array
{
    $f = kuma_cache_path();
    if (!is_file($f)) { return null; }
    $j = json_decode((string) @file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

/**
 * The payload for GET monitoring/monitoring_uptime.php.
 * Errors: not_configured | kuma_unreachable | kuma_unauthorized | kuma_invalid_response.
 * On an upstream error the last good data is returned too, flagged stale.
 */
function kuma_uptime(PDO $pdo, ?callable $fetcher = null): array
{
    $s = kuma_settings($pdo);
    if ($s['url'] === '' || !$s['has_key']) { return ['error' => 'not_configured']; }
    $interval = max(KUMA_POLL_MIN, min(KUMA_POLL_MAX, $s['poll']));
    $sig = hash('sha256', $s['url'] . '|' . ($s['updated_at'] ?? ''));
    $cache = kuma_cache_read();
    if ($cache && ($cache['sig'] ?? '') !== $sig) { $cache = null; }
    $now = time();

    $respond = static function (?array $c, int $interval) use ($pdo): array {
        $out = ['pollIntervalSeconds' => $interval];
        if (!empty($c['data'])) {
            $out += ['updatedAt' => $c['data']['updatedAt'], 'summary' => $c['data']['summary'], 'monitors' => kuma_with_history($pdo, $c['data']['monitors'])];
        }
        if (!empty($c['error'])) { $out['error'] = $c['error']; $out['stale'] = !empty($c['data']); }
        return $out;
    };

    if ($cache && ($now - (int) ($cache['attemptedAt'] ?? 0)) < $interval) { return $respond($cache, $interval); }

    // Only one request at a time talks to Kuma; the others get the last known answer.
    $lock = @fopen(dirname(__DIR__, 2) . '/storage/kuma.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return $cache ? $respond($cache, $interval) : ['error' => 'kuma_unreachable', 'pollIntervalSeconds' => $interval];
    }
    try {
        $key = secret_decrypt($s['key_enc']);
        $r = $key === '' ? ['state' => 'unreachable', 'body' => ''] : ($fetcher ? $fetcher($s['url'], $key) : kuma_fetch($s['url'], $key));
        $new = ['sig' => $sig, 'attemptedAt' => $now, 'data' => $cache['data'] ?? null, 'error' => null, 'hb' => $cache['hb'] ?? []];
        if ($r['state'] === 'ok') {
            $monitors = kuma_parse_metrics($r['body']);
            $new['data'] = ['updatedAt' => gmdate('c'), 'summary' => kuma_summarize($monitors), 'monitors' => $monitors];
            $new['hb'] = kuma_record_heartbeats($pdo, $monitors, $cache['hb'] ?? [], $now);
        } else {
            $new['error'] = ['unauthorized' => 'kuma_unauthorized', 'invalid' => 'kuma_invalid_response'][$r['state']] ?? 'kuma_unreachable';
        }
        @file_put_contents(kuma_cache_path(), json_encode($new));
        return $respond($new, $interval);
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}


// ---------- History: heartbeats, uptime percentages, events ----------
const KUMA_HISTORY_DAYS = 30;
const KUMA_BEAT_SPACING_S = 60;   // one sample per monitor per minute, plus one at every status change
const KUMA_STATUS_TEXT = [1 => 'Up', 0 => 'Down', 2 => 'Pending', 3 => 'Maintenance'];

/** Stores a heartbeat when a minute has passed or the status changed. Returns the small state kept in the cache file. */
function kuma_record_heartbeats(PDO $pdo, array $monitors, array $last, int $now): array
{
    $ins = $pdo->prepare('INSERT INTO kuma_heartbeats (monitor_key, monitor_name, status, response_ms, important, at) VALUES (?,?,?,?,?,?)');
    $when = date('Y-m-d H:i:s', $now);
    $pdo->beginTransaction();
    try {
        foreach ($monitors as $m) {
            if ($m['status'] === null) { continue; }
            $prev = $last[$m['id']] ?? null;
            $changed = $prev === null || (int) $prev['s'] !== (int) $m['status'];
            if (!$changed && $now - (int) $prev['t'] < KUMA_BEAT_SPACING_S) { continue; }
            $ins->execute([$m['id'], mb_substr($m['name'], 0, 160), $m['status'], $m['responseTimeMs'], $changed ? 1 : 0, $when]);
            $last[$m['id']] = ['s' => (int) $m['status'], 't' => $now];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Kuma history not recorded: ' . get_class($e));   // history is a bonus: never break the page for it
    }
    return $last;
}

/** Adds heartbeats (last 40), 24 h and 30 day uptime and the 24 h average response to every monitor. */
function kuma_with_history(PDO $pdo, array $monitors): array
{
    if (!$monitors) { return $monitors; }
    $ids = array_column($monitors, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stats = [];
    $beats = [];
    try {
        $q = $pdo->prepare("SELECT monitor_key,
              SUM(at >= NOW() - INTERVAL 24 HOUR AND status = 1) up24, SUM(at >= NOW() - INTERVAL 24 HOUR AND status IN (0,1,2)) n24,
              SUM(status = 1) up30, SUM(status IN (0,1,2)) n30,
              AVG(CASE WHEN at >= NOW() - INTERVAL 24 HOUR AND status = 1 THEN response_ms END) avg24
            FROM kuma_heartbeats WHERE monitor_key IN ($in) AND at >= NOW() - INTERVAL " . KUMA_HISTORY_DAYS . " DAY GROUP BY monitor_key");
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) { $stats[$r['monitor_key']] = $r; }
        $b = $pdo->prepare("SELECT monitor_key, status, response_ms, at FROM kuma_heartbeats WHERE monitor_key IN ($in) AND at >= NOW() - INTERVAL 24 HOUR ORDER BY at DESC LIMIT " . (count($ids) * 40));
        $b->execute($ids);
        foreach ($b->fetchAll() as $r) {
            if (count($beats[$r['monitor_key']] ?? []) < 40) {
                $beats[$r['monitor_key']][] = [(int) $r['status'], $r['response_ms'] === null ? null : (int) $r['response_ms'], date('c', strtotime($r['at']))];
            }
        }
    } catch (Throwable $e) {
        error_log('Kuma history unavailable: ' . get_class($e));
        $stats = [];
        $beats = [];
    }
    $pct = static fn($up, $n) => ((float) $n > 0) ? round((float) $up / (float) $n * 100, 2) : null;
    foreach ($monitors as &$m) {
        $s = $stats[$m['id']] ?? null;
        $m['uptime24h'] = $s ? $pct($s['up24'], $s['n24']) : null;
        $m['uptime30d'] = $s ? $pct($s['up30'], $s['n30']) : null;
        $m['avgResponse24hMs'] = ($s && $s['avg24'] !== null) ? (int) round((float) $s['avg24']) : null;
        $m['heartbeats'] = array_reverse($beats[$m['id']] ?? []);   // oldest first, for the bar
    }
    unset($m);
    return $monitors;
}

/** One monitor in detail: response series for the last 24 h (averaged into at most 120 points) and its status changes. */
function kuma_monitor_detail(PDO $pdo, string $id): array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) { return ['error' => 'not_found']; }
    $rows = $pdo->prepare('SELECT status, response_ms, at FROM kuma_heartbeats WHERE monitor_key = ? AND at >= NOW() - INTERVAL 24 HOUR ORDER BY at');
    $rows->execute([$id]);
    $all = $rows->fetchAll();
    $beats = array_map(static fn($r) => [(int) $r['status'], $r['response_ms'] === null ? null : (int) $r['response_ms'], date('c', strtotime($r['at']))], array_slice($all, -100));
    $series = [];
    $n = count($all);
    if ($n > 0) {
        $bucket = max(1, (int) ceil($n / 120));
        for ($i = 0; $i < $n; $i += $bucket) {
            $chunk = array_slice($all, $i, $bucket);
            $vals = [];
            foreach ($chunk as $r) { if ((int) $r['status'] === 1 && $r['response_ms'] !== null) { $vals[] = (int) $r['response_ms']; } }
            $down = false;
            foreach ($chunk as $r) { if ((int) $r['status'] === 0) { $down = true; } }
            $series[] = [date('c', strtotime($chunk[0]['at'])), $vals ? (int) round(array_sum($vals) / count($vals)) : null, $down ? 0 : 1];
        }
    }
    $ev = $pdo->prepare('SELECT monitor_key, monitor_name, status, at FROM kuma_heartbeats WHERE monitor_key = ? AND important = 1 ORDER BY at DESC LIMIT 25');
    $ev->execute([$id]);
    return ['id' => $id, 'heartbeats' => $beats, 'series' => $series, 'events' => kuma_events_out($ev->fetchAll())];
}

function kuma_events_out(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $st = (int) $r['status'];
        $out[] = [
            'id' => $r['monitor_key'], 'name' => $r['monitor_name'], 'status' => $st,
            'statusLabel' => strtolower(KUMA_STATUS_TEXT[$st] ?? 'unknown'), 'at' => date('c', strtotime($r['at'])),
            'message' => KUMA_STATUS_TEXT[$st] ?? 'Unknown',
        ];
    }
    return $out;
}

/** The latest status changes across all monitors (the Dashboard tab). */
function kuma_recent_events(PDO $pdo): array
{
    return kuma_events_out($pdo->query('SELECT monitor_key, monitor_name, status, at FROM kuma_heartbeats WHERE important = 1 ORDER BY at DESC LIMIT 30')->fetchAll());
}
