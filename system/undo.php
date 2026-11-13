<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require_login();

/*
 * Undo for a deletion: puts back the one row that undo_remember() kept in this person's session, for one minute after it was deleted.
 * Only the tables listed here can be restored, the row comes from the session (never from the request), and it goes back under its own id.
 */
const UNDO_TABLES = ['notes' => 'Note', 'appointments' => 'Appointment'];
const UNDO_SECONDS = 60;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('index.php'); }
csrf_check();
$u = $_SESSION['undo'] ?? null;
unset($_SESSION['undo']);
$back = is_array($u) && is_string($u['back'] ?? null) && preg_match('~^[a-z_]+/[a-z_]+\.php[?#A-Za-z0-9_=&%.+\-]*$~', $u['back']) ? $u['back'] : 'index.php';
if (!is_array($u) || !isset(UNDO_TABLES[$u['table'] ?? '']) || !is_array($u['row'] ?? null) || time() - (int) ($u['t'] ?? 0) > UNDO_SECONDS) {
    flash('That can no longer be undone.', 'error');
    redirect($back);
}
$cols = array_values(array_filter(array_keys($u['row']), fn($c) => is_string($c) && preg_match('/^[a-z_]+$/', $c) === 1));
try {
    db()->prepare('INSERT INTO ' . $u['table'] . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_map(fn($c) => $u['row'][$c], $cols));
    log_activity('info', UNDO_TABLES[$u['table']] . ' restored', $u['table'] . ' #' . (int) ($u['row']['id'] ?? 0));
    flash(UNDO_TABLES[$u['table']] . ' restored.');
} catch (Throwable $e) {
    error_log('Undo failed: ' . $e->getMessage());
    flash('It could not be put back.', 'error');
}
redirect($back);
