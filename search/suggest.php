<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';

/*
 * The suggestions under the search bar (assets/js/ux.js): the first few matches per kind as JSON. The full results stay on search/search.php.
 * Same rules as that page: signed-in people only, the text is searched literally, and a module that is off is not searched.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['admin'])) { http_response_code(401); echo '[]'; exit; }
require_login();
$pdo = db();
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$out = [];
if (mb_strlen($q) >= 2) {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $run = function (string $sql, int $n) use ($pdo, $like): array { $s = $pdo->prepare($sql . ' LIMIT 3'); $s->execute(array_fill(0, $n, $like)); return $s->fetchAll(); };
    if (module_enabled('tickets')) {
        foreach ($run('SELECT t.id, t.reference, t.subject, c.name AS company FROM tickets t LEFT JOIN companies c ON c.id = t.company_id WHERE t.reference LIKE ? OR t.subject LIKE ? OR c.name LIKE ? ORDER BY t.updated_at DESC', 3) as $r) {
            $out[] = ['kind' => 'Ticket', 'url' => 'tickets/ticket.php?id=' . (int) $r['id'], 'title' => $r['subject'], 'sub' => $r['reference'] . ($r['company'] !== null ? ' · ' . $r['company'] : '')];
        }
    }
    foreach ($run('SELECT id, name, city FROM companies WHERE name LIKE ? OR city LIKE ? OR kvk LIKE ? OR email LIKE ? ORDER BY name', 4) as $r) {
        $out[] = ['kind' => 'Company', 'url' => 'companies/company.php?id=' . (int) $r['id'], 'title' => $r['name'], 'sub' => (string) $r['city']];
    }
    foreach ($run('SELECT u.id, u.name, u.email FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.name LIKE ? OR u.email LIKE ? OR c.name LIKE ? ORDER BY u.name', 3) as $r) {
        $out[] = ['kind' => 'User', 'url' => 'users/user.php?id=' . (int) $r['id'], 'title' => $r['name'], 'sub' => $r['email']];
    }
    if (module_enabled('invoices')) {
        foreach ($run('SELECT id, number, customer FROM invoices WHERE number LIKE ? OR customer LIKE ? OR email LIKE ? ORDER BY issue_date DESC, id DESC', 3) as $r) {
            $out[] = ['kind' => 'Invoice', 'url' => 'invoices/invoice.php?id=' . (int) $r['id'], 'title' => $r['number'], 'sub' => $r['customer']];
        }
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
