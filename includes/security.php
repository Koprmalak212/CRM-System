<?php
declare(strict_types=1);

/*
 * Security baseline, loaded first by bootstrap.php.
 * Configuration, error handling, input hygiene, sessions, response headers, CSRF and login guard.
 */

// ---------- Configuration: credentials live in includes/config.local.php (git-ignored), never in code ----------
$__cfg = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];
define('DB_HOST', (string) ($__cfg['db_host'] ?? '127.0.0.1'));
define('DB_NAME', (string) ($__cfg['db_name'] ?? 'ops_console'));
define('DB_USER', (string) ($__cfg['db_user'] ?? 'ops_console_app'));
define('DB_PASS', (string) ($__cfg['db_pass'] ?? ''));
define('APP_KEY', base64_decode((string) ($__cfg['app_key'] ?? '')));                  // 32 bytes, encrypts stored API secrets
define('ALLOW_PRIVATE_TARGETS', (bool) ($__cfg['allow_private_targets'] ?? false));    // SSRF guard: public addresses only unless set
unset($__cfg);

// ---------- Errors: log them, never show them ----------
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
$__logDir = dirname(__DIR__) . '/storage';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0750, true); }
ini_set('error_log', $__logDir . '/php-error.log');
unset($__logDir);
header_remove('X-Powered-By');

set_exception_handler(static function (Throwable $e): void {
    $ref = bin2hex(random_bytes(4));
    error_log('[' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Something went wrong</title>'
        . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#0A1226;color:#EEF2FA;font:16px system-ui,sans-serif">'
        . '<main style="max-width:28rem;padding:2rem"><h1 style="font-size:1.5rem;margin:0 0 .5rem">Something went wrong</h1>'
        . '<p style="color:#A5B2CF;line-height:1.5">The page could not be shown. Nothing was changed by this request. Try again in a moment, and quote reference <strong>' . $ref . '</strong> if it keeps happening.</p>'
        . '<p><a href="index.php" style="color:#C7F36B">Back to the dashboard</a></p></main></body></html>';
    exit;
});

// ---------- Input hygiene: no unexpected arrays in query strings or forms ----------
foreach ($_GET as $k => $v) {
    if (is_array($v)) { $_GET[$k] = ''; }
}
$__arrayFields = ['desc', 'qty', 'price'];   // invoice lines are the only legitimate array fields
foreach ($_POST as $k => $v) {
    if (is_array($v) && !in_array($k, $__arrayFields, true)) { $_POST[$k] = ''; }
}
foreach ($__arrayFields as $k) {
    if (isset($_POST[$k]) && is_array($_POST[$k])) {
        $_POST[$k] = array_map(static fn($x) => is_scalar($x) ? (string) $x : '', array_slice($_POST[$k], 0, 100));
    }
}
unset($__arrayFields);
// Cap string length so oversized values cannot be used to burn memory or fill the database.
foreach (['_GET', '_POST'] as $__src) {
    foreach ($GLOBALS[$__src] as $k => $v) {
        if (is_string($v) && strlen($v) > 2000) { $GLOBALS[$__src][$k] = substr($v, 0, 2000); }
    }
}
unset($__src);

// ---------- Sessions ----------
$GLOBALS['is_https'] = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.cookie_httponly', '1');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $GLOBALS['is_https'], 'httponly' => true, 'samesite' => 'Lax']);
session_name($GLOBALS['is_https'] ? '__Host-ops_console' : 'ops_console');
// The inbound API is stateless: it must not create sessions or cookies.
if (!defined('API_REQUEST')) {
    session_start();
}

const SESSION_IDLE_SECONDS = 1800;   // 30 minutes without activity
const SESSION_MAX_SECONDS = 28800;   // 8 hours in total
if (!empty($_SESSION['admin'])) {
    $__now = time();
    $__last = (int) ($_SESSION['last_seen'] ?? $__now);
    $__start = (int) ($_SESSION['started'] ?? $__now);
    if ($__now - $__last > SESSION_IDLE_SECONDS || $__now - $__start > SESSION_MAX_SECONDS) {
        $_SESSION = ['expired' => true];
        session_regenerate_id(true);
    } else {
        $_SESSION['last_seen'] = $__now;
        $_SESSION['started'] = $__start;
    }
    unset($__now, $__last, $__start);
}

// ---------- Response headers ----------
$GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));
function csp_nonce(): string
{
    return $GLOBALS['csp_nonce'];
}
$__n = $GLOBALS['csp_nonce'];
// Google Translate (the language toggle) is the only third-party script source; everything else is self-hosted.
header("Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'nonce-$__n' https://translate.google.com https://translate.googleapis.com https://translate-pa.googleapis.com https://www.gstatic.com; "
    . "style-src 'self' 'unsafe-inline' https://translate.googleapis.com https://translate-pa.googleapis.com https://www.gstatic.com; "
    . "img-src 'self' data: https://*.google.com https://*.gstatic.com https://translate.googleapis.com https://translate-pa.googleapis.com; "
    . "font-src 'self'; connect-src 'self' https://translate.googleapis.com https://translate-pa.googleapis.com https://translate.google.com; "
    . "frame-src 'self' https://translate.google.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
    . ($GLOBALS['is_https'] ? '; upgrade-insecure-requests' : ''));
unset($__n);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
if ($GLOBALS['is_https']) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ---------- Database ----------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database unavailable');   // generic: never leaks host or user
        }
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

// ---------- Passwords ----------
function hash_password(string $plain): string
{
    return defined('PASSWORD_ARGON2ID')
        ? password_hash($plain, PASSWORD_ARGON2ID)
        : password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
}

function password_algo(): string|int
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

// ---------- CSRF and access ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        try {
            log_activity('warning', 'CSRF check failed', basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), $_SESSION['admin']['username'] ?? 'anonymous');
        } catch (Throwable $e) { /* logging must never block the response */ }
        http_response_code(403);
        exit('Session expired. Go back, refresh the page and try again.');
    }
}

function require_login(): array
{
    if (empty($_SESSION['admin'])) {
        header('Location: login.php');
        exit;
    }
    // Role and permissions are read fresh on every request, so an admin's change takes effect immediately
    // and a disabled account is signed out at once.
    $s = db()->prepare('SELECT id, username, email, name, role, perm_integrations, is_active, must_change_password, avatar FROM admins WHERE id = ?');
    $s->execute([$_SESSION['admin']['id'] ?? 0]);
    $row = $s->fetch();
    if (!$row || !(int) $row['is_active']) {
        $_SESSION = ['expired' => true];
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    }
    $_SESSION['admin'] = [
        'id' => (int) $row['id'], 'username' => $row['username'], 'email' => $row['email'], 'name' => $row['name'],
        'role' => $row['role'], 'perm' => $row['perm_integrations'], 'avatar' => $row['avatar'], 'must_change' => (bool) $row['must_change_password'],
    ];
    // A temporary or default password must be replaced before anything else can be used.
    if ($_SESSION['admin']['must_change'] && !in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['change_password.php', 'logout.php'], true)) {
        header('Location: change_password.php');
        exit;
    }
    return $_SESSION['admin'];
}

function is_admin(): bool
{
    return ($_SESSION['admin']['role'] ?? '') === 'admin';
}

/** none | view | manage. Admins always manage; normal users only what an admin granted. */
function integrations_level(): string
{
    if (is_admin()) { return 'manage'; }
    $p = (string) ($_SESSION['admin']['perm'] ?? 'none');
    return in_array($p, ['view', 'manage'], true) ? $p : 'none';
}

/** Stops the request with a 403 page and records the attempt. Use after require_login(). */
function deny_access(string $what): never
{
    try { log_activity('warning', 'Access denied', $what); } catch (Throwable $e) { /* never block the response */ }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>No access</title>'
        . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#0A1226;color:#EEF2FA;font:16px system-ui,sans-serif">'
        . '<main style="max-width:28rem;padding:2rem"><h1 style="font-size:1.5rem;margin:0 0 .5rem">You do not have access to this page</h1>'
        . '<p style="color:#A5B2CF;line-height:1.5">Your account is not allowed to open this. If you need it, ask an admin to grant the permission.</p>'
        . '<p><a href="index.php" style="color:#C7F36B">Back to the dashboard</a></p></main></body></html>';
    exit;
}

/** Only allow a return-to query string made of safe characters, never a full URL. */
function safe_back(mixed $raw): string
{
    return (is_string($raw) && preg_match('/^\?[A-Za-z0-9_=&%.+\-]{0,300}$/', $raw)) ? $raw : '';
}

// ---------- Rate limiting (denial-of-service protection) ----------
/** The real peer address. X-Forwarded-For is deliberately ignored: it is client-controlled unless a trusted proxy sets it. */
function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** Fixed-window counter per IP and bucket. Returns [allowed, seconds until the window resets, hits]. */
function rate_hit(string $bucket, int $limit, int $windowSeconds): array
{
    $now = time();
    $start = $now - ($now % $windowSeconds);
    $pdo = db();
    $ip = client_ip();
    $pdo->prepare('INSERT INTO rate_limits (ip, bucket, window_start, hits) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')
        ->execute([$ip, $bucket, $start]);
    $s = $pdo->prepare('SELECT hits FROM rate_limits WHERE ip = ? AND bucket = ? AND window_start = ?');
    $s->execute([$ip, $bucket, $start]);
    $hits = (int) $s->fetchColumn();
    if (random_int(1, 150) === 1) {   // housekeeping: expired windows, old login attempts and logs past their retention period
        purge_expired_data($pdo);
    }
    return [$hits <= $limit, max(1, $start + $windowSeconds - $now), $hits];
}

/**
 * Per-IP limits applied to every PHP request:
 *   all requests      240 per minute   (a person clicking around uses far less)
 *   POST requests      40 per minute   (form submissions and actions)
 *   CSV exports        15 per minute
 *   login attempts     20 per minute   (on top of the failed-login lockout in login.php)
 * Static files (CSS, JS, images) are served by Apache and are not counted here.
 * If the counter store is unavailable the request is allowed (fail open, logged), so an outage never locks everyone out.
 */
function enforce_rate_limit(): void
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $rules = [['all', 240, 60]];
    if ($isPost) { $rules[] = ['post', 40, 60]; }
    if ($script === 'search.php') { $rules[] = ['search', 60, 60]; }   // the heaviest read page: several LIKE queries per request
    if ($script === 'report_download.php') { $rules[] = ['export', 15, 60]; }
    if ($script === 'login.php' && $isPost) { $rules[] = ['login', 20, 60]; }

    foreach ($rules as [$bucket, $limit, $window]) {
        try {
            [$ok, $retry, $hits] = rate_hit($bucket, $limit, $window);
        } catch (Throwable $e) {
            error_log('Rate limiter unavailable: ' . $e->getMessage());
            return;
        }
        if ($ok) { continue; }
        if ($hits === $limit + 1) {   // log once per window so an attack cannot also flood the log
            try { log_activity('warning', 'Rate limit exceeded', $bucket . ' · ' . $script, 'anonymous'); } catch (Throwable $e) { /* ignore */ }
        }
        http_response_code(429);
        header('Retry-After: ' . $retry);
        if (defined('API_REQUEST')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Too many requests.']);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Too many requests</title>'
            . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#0A1226;color:#EEF2FA;font:16px system-ui,sans-serif">'
            . '<main style="max-width:28rem;padding:2rem"><h1 style="font-size:1.5rem;margin:0 0 .5rem">Slow down a moment</h1>'
            . '<p style="color:#A5B2CF;line-height:1.5">There have been too many requests from your connection. Wait ' . $retry . ' seconds and try again.</p></main></body></html>';
        exit;
    }
}

// ---------- Data retention (GDPR art. 5(1)(e): keep personal data no longer than needed) ----------
const LOG_RETENTION_MONTHS = 12;   // activity logs hold IP addresses and account names

/** Deletes expired short-lived records. Returns the number of activity-log rows removed. Safe to run any time. */
function purge_expired_data(PDO $pdo): int
{
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
    $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([time() - 3600]);
    $s = $pdo->prepare('DELETE FROM activity_logs WHERE created_at < ?');
    $s->execute([date('Y-m-d H:i:s', strtotime('-' . LOG_RETENTION_MONTHS . ' months'))]);
    $removed = $s->rowCount();
    $pdo->prepare('DELETE FROM kuma_heartbeats WHERE at < ?')->execute([date('Y-m-d H:i:s', strtotime('-30 days'))]);
    $pdo->prepare('DELETE FROM endpoint_checks WHERE checked_at < ?')->execute([date('Y-m-d H:i:s', strtotime('-30 days'))]);
    // Financial records: statutory retention is 7 years (fiscale bewaarplicht). After that the customer data goes too.
    $pdo->prepare('DELETE FROM invoices WHERE issue_date < ?')->execute([date('Y-m-d', strtotime('-7 years'))]);
    return $removed;
}
