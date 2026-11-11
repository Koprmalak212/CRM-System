<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/csv.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();

$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$invoicesOn = module_enabled('invoices');
$sorts = ['name' => 'c.name', 'city' => 'c.city', 'kvk' => 'c.kvk', 'users' => 'user_count'];
if ($invoicesOn) { $sorts['invoices'] = 'invoice_count'; }
$sort = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'name';
$dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = 15;

$where = '';
$args = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $where = 'WHERE (c.name LIKE ? OR c.city LIKE ? OR c.kvk LIKE ? OR c.email LIKE ?)';
    $args = [$like, $like, $like, $like];
}
$cnt = $pdo->prepare("SELECT COUNT(*) FROM companies c $where");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$invSub = $invoicesOn ? ', (SELECT COUNT(*) FROM invoices i WHERE i.company_id = c.id) invoice_count' : '';
$rows = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) user_count$invSub
    FROM companies c $where ORDER BY {$sorts[$sort]} $dir, c.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$rows->execute($args);
$rows = $rows->fetchAll();
$base = ['q' => $q, 'sort' => $sort, 'dir' => $dir];

page_start('Companies', 'companies', 'The businesses you serve: their details, people and invoices in one place.');
$field = 'h-11 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<div class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel">
  <form method="get" class="flex flex-wrap items-center gap-3 border-b border-line p-4">
    <label class="sr-only" for="q">Search companies</label>
    <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Name, city, KvK or email" class="<?= $field ?> w-full sm:w-72">
    <button class="h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Search</button>
    <?php if ($q !== ''): ?><a href="companies/companies.php" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear search</a><?php endif; ?>
    <div class="ml-auto flex flex-wrap gap-2"><?= csv_buttons('companies') ?><a href="companies/company_edit.php" class="pressable inline-flex h-11 items-center gap-2 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy"><?= icon('plus', 'h-4 w-4') ?>Add company</a></div>
  </form>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[820px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft">
        <tr>
          <?= th_sort('Company', 'name', $sort, $dir, $base) ?>
          <?= th_sort('City', 'city', $sort, $dir, $base) ?>
          <?= th_sort('KvK', 'kvk', $sort, $dir, $base) ?>
          <?= th_sort('Users', 'users', $sort, $dir, $base, 'right') ?>
          <?php if ($invoicesOn): ?><?= th_sort('Invoices', 'invoices', $sort, $dir, $base, 'right') ?><?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $c): ?>
          <tr class="hover:bg-butter-50">
            <td class="px-4 py-3"><div class="flex items-center gap-3">
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-raised text-xs font-bold text-lime"><?= e(initials($c['name'])) ?></span>
              <div class="min-w-0"><a href="companies/company.php?id=<?= (int) $c['id'] ?>" class="block truncate font-semibold underline-offset-4 hover:text-lime hover:underline focus-visible:underline"><?= e($c['name']) ?></a><p class="truncate text-soft"><?= $c['email'] !== '' ? e($c['email']) : 'No email' ?></p></div></div></td>
            <td class="px-4 py-3"><?= $c['city'] !== '' ? e($c['city']) : '<span class="text-soft">Not set</span>' ?></td>
            <td class="px-4 py-3 tabular-nums notranslate"><?= $c['kvk'] !== '' ? e($c['kvk']) : '<span class="text-soft">Not set</span>' ?></td>
            <td class="px-4 py-3 text-right tabular-nums"><?= $c['user_count'] > 0 ? '<a class="underline-offset-4 hover:underline" href="users/users.php?company=' . (int) $c['id'] . '">' . (int) $c['user_count'] . '</a>' : '0' ?></td>
            <?php if ($invoicesOn): ?><td class="px-4 py-3 text-right tabular-nums"><?= (int) $c['invoice_count'] ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= $invoicesOn ? 5 : 4 ?>" class="px-4 py-14 text-center"><p class="text-base font-semibold"><?= $q !== '' ? 'No companies match this search' : 'No companies yet' ?></p><p class="mt-1 text-soft"><?= $q !== '' ? 'Try a different name or clear the search.' : 'Add the first one, or load demo data from the Overview.' ?></p><a href="<?= $q !== '' ? 'companies/companies.php' : 'companies/company_edit.php' ?>" class="mt-4 inline-flex h-10 items-center rounded-xl bg-lime px-4 text-sm font-bold text-navy"><?= $q !== '' ? 'Show all companies' : 'Add a company' ?></a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $base) ?>
</div>
<?php page_end(); ?>
