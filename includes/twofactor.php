<?php
declare(strict_types=1);

/*
 * Two-factor sign-in (stage 7): time-based one-time codes as in RFC 6238 (HMAC-SHA1, 6 digits, 30 seconds), written without
 * outside libraries. The secret is stored encrypted with the app key; recovery codes are stored only as keyed hashes.
 */

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
const TOTP_WINDOW = 1;           // accept the previous and the next 30-second step too, for small clock differences
const TOTP_RECOVERY_COUNT = 10;
const TOTP_ISSUER = 'Telecomlijn Admin';
const TOTP_PENDING_SECONDS = 300;   // the code step after the password must be finished within five minutes

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) { $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))]; }
    return $out;   // no padding: authenticator apps do not need it
}

/** Returns the raw bytes, or null when the text is not valid base32. Spaces, dashes and lower case are tolerated. */
function base32_decode(string $text): ?string
{
    $text = strtoupper(str_replace([' ', '-', '='], '', $text));
    if ($text === '' || !preg_match('/^[A-Z2-7]+$/', $text)) { return null; }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($text) as $c) { $bits .= str_pad(decbin((int) strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $out .= chr(bindec($byte)); } }
    return $out;
}

/** HOTP, RFC 4226: the code for one counter value. */
function hotp(string $secret, int $counter, int $digits = TOTP_DIGITS): string
{
    $hash = hash_hmac('sha1', pack('J', $counter), $secret, true);
    $o = ord($hash[19]) & 0x0F;
    $bin = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** TOTP, RFC 6238: the code for a moment in time. */
function totp_code(string $secret, ?int $time = null, int $digits = TOTP_DIGITS, int $period = TOTP_PERIOD): string
{
    return hotp($secret, intdiv($time ?? time(), $period), $digits);
}

/**
 * Checks a typed code against the current step and its neighbours. Returns the matching step number, or null.
 * Spaces in the typed code are ignored. Compares in constant time and checks every step, so timing tells nothing.
 */
function totp_verify(string $secret, string $typed, ?int $time = null): ?int
{
    $typed = preg_replace('/\s+/', '', $typed) ?? '';
    if (!preg_match('/^\d{' . TOTP_DIGITS . '}$/', $typed)) { return null; }
    $now = intdiv($time ?? time(), TOTP_PERIOD);
    $hit = null;
    for ($s = $now - TOTP_WINDOW; $s <= $now + TOTP_WINDOW; $s++) {
        if (hash_equals(hotp($secret, $s), $typed)) { $hit = $s; }
    }
    return $hit;
}

/** A new random 160-bit secret, as raw bytes. */
function totp_new_secret(): string { return random_bytes(20); }

/** The text people type into an authenticator app: groups of four. */
function totp_secret_text(string $secret): string { return trim(chunk_split(base32_encode($secret), 4, ' ')); }

/** The otpauth:// link that authenticator apps understand (no QR code: that would need a library). */
function totp_uri(string $secret, string $account): string
{
    return 'otpauth://totp/' . rawurlencode(TOTP_ISSUER . ':' . $account) . '?secret=' . base32_encode($secret) . '&issuer=' . rawurlencode(TOTP_ISSUER) . '&algorithm=SHA1&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}

/** Recovery codes look like ABCDE-FGHJK (no 0/O or 1/I to avoid mix-ups). Returns the plain codes to show once. */
function recovery_codes_make(PDO $pdo, int $adminId): array
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pdo->prepare('DELETE FROM admin_recovery_codes WHERE admin_id = ?')->execute([$adminId]);
    $ins = $pdo->prepare('INSERT INTO admin_recovery_codes (admin_id, code_hash) VALUES (?, ?)');
    $codes = [];
    while (count($codes) < TOTP_RECOVERY_COUNT) {
        $raw = '';
        for ($i = 0; $i < 10; $i++) { $raw .= $alphabet[random_int(0, 31)]; }
        if (isset($codes[$raw])) { continue; }
        $codes[$raw] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        $ins->execute([$adminId, recovery_hash($raw)]);
    }
    return array_values($codes);
}

function recovery_normalise(string $typed): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $typed) ?? ''); }
function recovery_hash(string $normalised): string { return hash_hmac('sha256', $normalised, APP_KEY); }

/** Uses a recovery code once. True only the first time. */
function recovery_code_use(PDO $pdo, int $adminId, string $typed): bool
{
    $n = recovery_normalise($typed);
    if (strlen($n) !== 10) { return false; }
    $s = $pdo->prepare('UPDATE admin_recovery_codes SET used_at = NOW() WHERE admin_id = ? AND code_hash = ? AND used_at IS NULL');
    $s->execute([$adminId, recovery_hash($n)]);
    return $s->rowCount() === 1;
}

function recovery_codes_left(PDO $pdo, int $adminId): int
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL');
    $s->execute([$adminId]);
    return (int) $s->fetchColumn();
}

/**
 * Checks a code for an account that has 2FA on: an authenticator code (each time step usable once) or a recovery code (once).
 * Returns 'totp', 'recovery' or null.
 */
function twofactor_check(PDO $pdo, array $adminRow, string $typed): ?string
{
    $secret = secret_decrypt((string) ($adminRow['totp_secret_enc'] ?? ''));
    if ($secret !== '' && ($step = totp_verify($secret, $typed)) !== null) {
        // A step may be used only once, so a code that was watched or intercepted can not be replayed within its 30 seconds.
        $s = $pdo->prepare('UPDATE admins SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
        $s->execute([$step, (int) $adminRow['id'], $step]);
        if ($s->rowCount() === 1) { return 'totp'; }
        return null;
    }
    return recovery_code_use($pdo, (int) $adminRow['id'], $typed) ? 'recovery' : null;
}

const TFA_WINDOW_MINUTES = 15;
const TFA_MAX_PER_USER = 5;
const TFA_MAX_PER_IP = 15;

function tfa_key(string $username): string { return mb_substr('2fa:' . strtolower($username), 0, 64); }

/** Too many wrong codes from this address (for this account, or in total)? Same limits as the password step. */
function tfa_locked(PDO $pdo, string $username): bool
{
    $window = date('Y-m-d H:i:s', time() - TFA_WINDOW_MINUTES * 60);
    $byIp = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
    $byIp->execute([client_ip(), $window]);
    $byUser = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND attempted_at >= ?');
    $byUser->execute([client_ip(), tfa_key($username), $window]);
    return (int) $byIp->fetchColumn() >= TFA_MAX_PER_IP || (int) $byUser->fetchColumn() >= TFA_MAX_PER_USER;
}

function tfa_fail(PDO $pdo, string $username): void
{
    $pdo->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, NOW())')->execute([client_ip(), tfa_key($username)]);
    log_activity('warning', 'Failed two-step code', $username, 'anonymous');
}

function tfa_clear(PDO $pdo, string $username): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE ip = ? AND username IN (?, ?)')->execute([client_ip(), tfa_key($username), mb_substr(strtolower($username), 0, 64)]);
}

/** Signs the person in: the same session set-up for the password step (no 2FA) and for the code step. */
function login_complete(PDO $pdo, array $row): never
{
    session_regenerate_id(true);
    unset($_SESSION['csrf'], $_SESSION['pending_2fa']);   // fresh token for the new session
    $_SESSION['admin'] = ['id' => (int) $row['id'], 'username' => $row['username'], 'email' => $row['email'], 'name' => $row['name'], 'must_change' => (bool) $row['must_change_password']];
    $_SESSION['started'] = $_SESSION['last_seen'] = time();
    log_activity('info', 'Signed in', 'dashboard');
    redirect($_SESSION['admin']['must_change'] ? 'change_password.php' : 'index.php');
}
