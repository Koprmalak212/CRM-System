<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/invoices.php';
require __DIR__ . '/includes/tickets.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();

$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$like = '%' . addcslashes($q, '\\%_') . '%';   // what the person typed is searched literally: % and _ are not wildcards
$per = 8;

$groups = [];
if ($q !== '') {
    // returns [total matches, the first rows]; $n is how many placeholders the query has (all take the same pattern)
    $one = function (string $countSql, string $rowSql, int $n) use ($pdo, $like, $per): array {
        $c = $pdo->prepare($countSql); $c->execute(array_fill(0, $n, $like));
        $r = $pdo->prepare($rowSql . " LIMIT $per"); $r->execute(array_fill(0, $n, $like));
        return [(int) $c->fetchColumn(), $r->fetchAll()];
    };
    [$n, $rows] = $one('SELECT COUNT(*) FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.name LIKE ? OR u.email LIKE ? OR c.name LIKE ?',
        'SELECT u.id, u.name, u.email, u.status, c.name AS company FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.name LIKE ? OR u.email LIKE ? OR c.name LIKE ? ORDER BY u.name', 3);
    $groups['users'] = ['Users', $n, array_map(fn($r) => ['user.php?id=' . $r['id'], $r['name'], $r['email'] . ($r['company'] !== null ? ' · ' . $r['company'] : ''), badge($r['status'])], $rows), 'users.php' . qs(['q' => $q])];

    [$n, $rows] = $one('SELECT COUNT(*) FROM companies WHERE name LIKE ? OR city LIKE ? OR kvk LIKE ? OR email LIKE ?', 'SELECT id, name, city, kvk FROM companies WHERE name LIKE ? OR city LIKE ? OR kvk LIKE ? OR email LIKE ? ORDER BY name', 4);
    $groups['companies'] = ['Companies', $n, array_map(fn($r) => ['company.php?id=' . $r['id'], $r['name'], trim($r['city'] . ($r['kvk'] !== '' ? ' · KvK ' . $r['kvk'] : ''), ' ·'), ''], $rows), 'companies.php' . qs(['q' => $q])];

    [$n, $rows] = $one('SELECT COUNT(*) FROM invoices WHERE number LIKE ? OR customer LIKE ? OR email LIKE ?',
        'SELECT * FROM (' . invoice_base_sql() . ') t WHERE number LIKE ? OR customer LIKE ? OR email LIKE ? ORDER BY issue_date DESC, id DESC', 3);
    $groups['invoices'] = ['Invoices', $n, array_map(fn($r) => ['invoice.php?id=' . $r['id'], $r['number'], $r['customer'] . ' · ' . money((int) $r['total_cents']), badge($r['state'])], $rows), 'invoices.php' . qs(['q' => $q])];

    [$n, $rows] = $one('SELECT COUNT(*) FROM tickets t LEFT JOIN companies c ON c.id = t.company_id WHERE t.reference LIKE ? OR t.subject LIKE ? OR c.name LIKE ?',
        'SELECT t.id, t.reference, t.subject, t.status, c.name AS company FROM tickets t LEFT JOIN companies c ON c.id = t.company_id WHERE t.reference LIKE ? OR t.subject LIKE ? OR c.name LIKE ? ORDER BY t.updated_at DESC', 3);
    $groups['tickets'] = ['Tickets', $n, array_map(fn($r) => ['ticket.php?id=' . $r['id'], $r['subject'], $r['reference'] . ($r['company'] !== null ? ' · ' . $r['company'] : ''), badge($r['status'])], $rows), 'tickets.php' . qs(['q' => $q])];
}
$total = array_sum(array_map(fn($g) => $g[1], $groups));

page_start('Search', 'search', $q === '' ? 'Search users, companies, invoices and tickets in one go.' : ($total === 0 ? 'Nothing found.' : number_format($total) . ' result' . ($total === 1 ? '' : 's') . ' for “' . $q . '”.'));
$field = 'h-11 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<form method="get" role="search" class="rise mb-6 flex flex-wrap gap-3">
  <label class="sr-only" for="sq">Search everything</label>
  <input id="sq" name="q" type="search" value="<?= e($q) ?>" maxlength="100" placeholder="Name, email, company, KvK, invoice number or ticket" class="<?= $field ?> w-full sm:w-[28rem]">
  <button class="h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Search</button>
</form>

<?php if ($q === ''): ?>
  <p class="rise text-sm text-soft">Type a name, an email address, a company, a KvK number, an invoice number such as INV-2026-1001 or a ticket reference such as TCK-0001.</p>
<?php elseif ($total === 0): ?>
  <div class="rise rounded-2xl border border-line bg-panel px-4 py-14 text-center shadow-panel"><p class="text-base font-semibold">No results for “<?= e($q) ?>”</p><p class="mt-1 text-soft">Check the spelling, or try fewer words.</p></div>
<?php else: ?>
  <nav class="rise mb-5 flex flex-wrap gap-2" aria-label="Jump to a result group">
    <?php foreach ($groups as $key => [$label, $n]): ?><a href="#res-<?= $key ?>" class="inline-flex h-10 items-center gap-2 rounded-full border border-line bg-panel px-4 text-sm font-semibold hover:bg-butter-50"><?= e($label) ?><span class="tabular-nums text-soft"><?= $n ?></span></a><?php endforeach; ?>
  </nav>
  <div class="space-y-5">
    <?php foreach ($groups as $key => [$label, $n, $rows, $all]): if ($n === 0) continue; ?>
      <section id="res-<?= $key ?>" class="rise rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="h-<?= $key ?>">
        <div class="flex items-center justify-between gap-3 px-5 pt-5"><h2 id="h-<?= $key ?>" class="text-base font-bold"><?= e($label) ?> <span class="font-normal text-soft tabular-nums">(<?= $n ?>)</span></h2>
          <?php if ($n > count($rows)): ?><a href="<?= e($all) ?>" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">View all <?= $n ?></a><?php endif; ?></div>
        <ul class="mt-2 divide-y divide-line">
          <?php foreach ($rows as [$href, $title, $sub, $extra]): ?>
            <li><a href="<?= e($href) ?>" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-butter-50"><span class="min-w-0"><span class="block truncate font-semibold"><?= e($title) ?></span><span class="block truncate text-sm text-soft"><?= e($sub) ?></span></span><?= $extra ?></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_end(); ?>
