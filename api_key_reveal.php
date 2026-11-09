<?php
declare(strict_types=1);

/* POST api_key_reveal.php  id=<key id>   (header X-CSRF-Token)
 * Returns a saved health-API key so an admin can copy it again instead of creating a new one.
 * Needs the "Can manage" integrations permission, a CSRF token, and every use is written to the activity log. */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/monitor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function reveal_fail(int $code, string $error): never { http_response_code($code); echo json_encode(['error' => $error]); exit; }

if (empty($_SESSION['admin'])) { reveal_fail(401, 'not_signed_in'); }
require_login();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { reveal_fail(405, 'method_not_allowed'); }
if (integrations_level() !== 'manage') {
    try { log_activity('warning', 'Access denied', 'api_key_reveal.php'); } catch (Throwable $e) { /* never block */ }
    reveal_fail(403, 'forbidden');
}
$sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''));
if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
    try { log_activity('warning', 'CSRF check failed', 'api_key_reveal.php'); } catch (Throwable $e) { /* ignore */ }
    reveal_fail(403, 'csrf');
}
[$ok] = rate_hit('reveal', 20, 60);
if (!$ok) { reveal_fail(429, 'rate_limited'); }

$pdo = db();
$s = $pdo->prepare('SELECT name, key_enc FROM api_keys WHERE id = ? AND revoked_at IS NULL');
$s->execute([(int) ($_POST['id'] ?? 0)]);
$row = $s->fetch();
if (!$row || empty($row['key_enc'])) { reveal_fail(404, 'not_available'); }
$plain = secret_decrypt($row['key_enc']);
if ($plain === '') { error_log('API key could not be decrypted'); reveal_fail(500, 'unavailable'); }
log_activity('warning', 'API key shown', (string) $row['name']);   // the name only, never the key
echo json_encode(['key' => $plain]);
