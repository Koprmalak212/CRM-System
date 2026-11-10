<?php
declare(strict_types=1);

/*
 * Health-check engine for monitored endpoints.
 *
 * The dangerous part of "the server fetches a URL somebody typed in" is SSRF (server-side request forgery):
 * pointing the check at localhost, the internal network or a cloud metadata address. So every check:
 *   - only allows http/https on a short list of ports, with no credentials inside the URL
 *   - resolves the host itself and rejects private, loopback, link-local and reserved addresses
 *   - pins the connection to the address it just validated (no second DNS lookup, so no DNS rebinding)
 *   - never follows redirects (a redirect could point somewhere internal)
 *   - caps the time and the amount of response it reads
 * Internal targets stay blocked unless 'allow_private_targets' is switched on in includes/config.local.php.
 */

const ALLOWED_PORTS = [80, 443, 8080, 8443];
const MAX_ENDPOINTS = 50;
const CHECK_HISTORY_DAYS = 30;
const FORBIDDEN_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection', 'upgrade', 'te', 'trailer', 'expect', 'cookie', 'set-cookie'];

// ---------- Secrets at rest (AES-256-GCM) ----------
function secret_encrypt(string $plain, ?string $key = null): string
{
    $key ??= APP_KEY;   // the optional key exists so the failure path can be tested
    if (strlen($key) !== 32) { throw new RuntimeException('Encryption key missing'); }
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) { throw new RuntimeException('Encryption failed'); }
    return base64_encode($iv . $tag . $cipher);
}

function secret_decrypt(?string $blob, ?string $key = null): string
{
    $key ??= APP_KEY;
    if ($blob === null || $blob === '' || strlen($key) !== 32) { return ''; }
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) { return ''; }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

// ---------- Target validation ----------
function ip_is_public(string $ip): bool
{
    if (ALLOW_PRIVATE_TARGETS) { return filter_var($ip, FILTER_VALIDATE_IP) !== false; }
    $bin = @inet_pton($ip);
    if ($bin === false) { return false; }
    if (strlen($bin) === 16) {
        // An IPv6 address can wrap an IPv4 one in several spellings. Every wrapped IPv4 address must be public too,
        // otherwise [::ffff:7f00:1] or a NAT64/6to4 form would reach 127.0.0.1 through the back door.
        $embedded = [];
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || substr($bin, 0, 12) === str_repeat("\0", 12)) {
            $embedded[] = substr($bin, 12, 4);                                   // ::ffff:a.b.c.d (mapped) and ::a.b.c.d (compatible)
        }
        if (substr($bin, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) { $embedded[] = substr($bin, 12, 4); }   // 64:ff9b::/96 (NAT64)
        if (substr($bin, 0, 2) === "\x20\x02") { $embedded[] = substr($bin, 2, 4); }                                   // 2002::/16 (6to4)
        if (substr($bin, 0, 4) === "\x20\x01\x00\x00") { $embedded[] = ~substr($bin, 12, 4); }                          // 2001::/32 (Teredo, client address inverted)
        foreach ($embedded as $v4) {
            if (!ip_is_public((string) inet_ntop($v4))) { return false; }
        }
    }
    $text = (string) inet_ntop($bin);
    if (filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) { return false; }
    // PHP's flags miss several special-purpose ranges, so they are listed explicitly.
    if (strlen($bin) === 4) {
        $n = ip2long($text);
        foreach (['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;
            if (($n & $mask) === (ip2long($net) & $mask)) { return false; }
        }
    } else {
        foreach (['2001:db8::/32', '2001::/32', '100::/64', 'ff00::/8', 'fe80::/10', 'fc00::/7'] as $cidr) {   // documentation, Teredo, discard, multicast, link-local, unique-local
            [$net, $bits] = explode('/', $cidr);
            $netBin = (string) inet_pton($net);
            $full = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            if (substr($bin, 0, $full) !== substr($netBin, 0, $full)) { continue; }
            if ($rest === 0 || ((ord($bin[$full]) ^ ord($netBin[$full])) >> (8 - $rest)) === 0) { return false; }
        }
    }
    return true;
}

/** @return array{0: bool, 1: string, 2: array} ok, message, details (host, port, ips, scheme) */
function validate_target(string $url): array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 500) { return [false, 'Enter a URL of up to 500 characters.', []]; }
    if (preg_match('/[\x00-\x20\x7f]/', $url)) { return [false, 'The URL can not contain spaces or control characters.', []]; }
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) { return [false, 'That does not look like a full URL. Start with https://', []]; }
    $scheme = strtolower((string) $p['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) { return [false, 'Only http and https addresses can be monitored.', []]; }
    if (isset($p['user']) || isset($p['pass'])) { return [false, 'Do not put a username or password in the URL. Use the secret header field instead.', []]; }
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, ALLOWED_PORTS, true)) { return [false, 'Port ' . $port . ' is not allowed. Use one of ' . implode(', ', ALLOWED_PORTS) . '.', []]; }
    $host = strtolower(trim((string) $p['host'], '[]'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        if (!ALLOW_PRIVATE_TARGETS) { return [false, 'Internal addresses can not be monitored. Only public addresses are allowed.', []]; }
    }
    if (!preg_match('/^[a-z0-9.\-:]+$/', $host)) { return [false, 'The host name contains characters that are not allowed.', []]; }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        foreach ((@gethostbynamel($host) ?: []) as $ip) { $ips[] = (string) $ip; }   // false (not found) becomes an empty list
        foreach ((@dns_get_record($host, DNS_AAAA) ?: []) as $rec) { if (!empty($rec['ipv6'])) { $ips[] = (string) $rec['ipv6']; } }
    }
    $ips = array_values(array_unique($ips));
    if (!$ips) { return [false, 'The host name could not be found.', []]; }
    foreach ($ips as $ip) {
        if (!ip_is_public($ip)) { return [false, 'That address points to an internal or reserved network. Only public addresses can be monitored.', []]; }
    }
    return [true, '', ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips]];
}

function validate_header(string $name, string $value): ?string
{
    if ($name === '' && $value === '') { return null; }
    if (!preg_match('/^[A-Za-z0-9-]{1,60}$/', $name)) { return 'The header name can only contain letters, digits and dashes.'; }
    if (in_array(strtolower($name), FORBIDDEN_HEADERS, true)) { return 'That header name is not allowed.'; }
    if ($value === '' || strlen($value) > 300 || preg_match('/[\r\n\x00]/', $value)) { return 'The header value must be 1 to 300 characters on one line.'; }
    return null;
}

// ---------- Running a check ----------
/** @return array{state: string, status: ?int, latency: ?int, error: string} */
function run_check(array $ep): array
{
    [$ok, $msg, $t] = validate_target((string) $ep['url']);   // validated again now: DNS may have changed since it was saved
    if (!$ok) { return ['state' => 'down', 'status' => null, 'latency' => null, 'error' => mb_substr($msg, 0, 160)]; }
    if (!function_exists('curl_init')) { return ['state' => 'down', 'status' => null, 'latency' => null, 'error' => 'The curl extension is not available.']; }

    $ip = $t['ips'][0];
    $resolve = $t['host'] . ':' . $t['port'] . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
    $headers = ['Accept: */*'];
    if ($ep['auth_header'] !== '' && !empty($ep['auth_secret_enc'])) {
        $secret = secret_decrypt($ep['auth_secret_enc']);
        if ($secret !== '' && validate_header((string) $ep['auth_header'], $secret) === null) { $headers[] = $ep['auth_header'] . ': ' . $secret; }
    }
    $timeout = max(1, min(10, (int) $ep['timeout_s']));
    $read = 0;
    $ch = curl_init((string) $ep['url']);
    curl_setopt_array($ch, [
        CURLOPT_RESOLVE => [$resolve],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => 0,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'TelecomlijnAdmin-HealthCheck/1.0',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_NOBODY => ($ep['method'] === 'HEAD'),
        CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$read): int {
            $read += strlen($chunk);
            return $read > 65536 ? 0 : strlen($chunk);   // enough to know it answered; never download more
        },
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $latency = (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
    curl_close($ch);

    $writeAborted = ($errno === CURLE_WRITE_ERROR && $status > 0);   // we cut the body short on purpose
    if ($errno !== 0 && !$writeAborted) {
        $err = match (true) {
            $errno === CURLE_OPERATION_TIMEDOUT => 'Timed out after ' . $timeout . ' s',
            $errno === CURLE_COULDNT_CONNECT => 'Connection refused or unreachable',
            $errno === CURLE_COULDNT_RESOLVE_HOST => 'Host name not found',
            in_array($errno, [CURLE_SSL_CONNECT_ERROR, 60, 51, 58, 59, 64, 66, 77, 83], true) => 'TLS certificate or handshake problem',
            default => 'Request failed (code ' . $errno . ')',
        };
        return ['state' => 'down', 'status' => $status ?: null, 'latency' => $latency ?: null, 'error' => $err];
    }
    if ($status !== (int) $ep['expected_status']) {
        return ['state' => 'down', 'status' => $status, 'latency' => $latency, 'error' => 'Expected status ' . (int) $ep['expected_status'] . ', got ' . $status];
    }
    return ['state' => $latency > (int) $ep['slow_ms'] ? 'slow' : 'up', 'status' => $status, 'latency' => $latency, 'error' => ''];
}

function record_check(PDO $pdo, array $ep, array $r): void
{
    $pdo->prepare('INSERT INTO endpoint_checks (endpoint_id, checked_at, state, status_code, latency_ms, error) VALUES (?, NOW(), ?, ?, ?, ?)')
        ->execute([$ep['id'], $r['state'], $r['status'], $r['latency'], $r['error']]);
    $pdo->prepare('UPDATE monitored_endpoints SET last_state = ?, last_status_code = ?, last_latency_ms = ?, last_error = ?, last_checked_at = NOW() WHERE id = ?')
        ->execute([$r['state'], $r['status'], $r['latency'], $r['error'], $ep['id']]);
}

// ---------- Inbound API keys ----------
/** @return array{0: string, 1: string, 2: string} plain key (shown once), display prefix, sha256 hash to store */
function generate_api_key(): array
{
    $plain = 'tlk_' . bin2hex(random_bytes(24));
    return [$plain, substr($plain, 0, 12), hash('sha256', $plain)];
}

function state_label(string $s): string
{
    return ['up' => 'Up', 'slow' => 'Slow', 'down' => 'Down', 'unknown' => 'Not checked yet'][$s] ?? 'Unknown';
}
