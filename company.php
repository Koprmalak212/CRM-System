<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/invoices.php';
require __DIR__ . '/includes/companies.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
$c = company_find($pdo, $id) ?? company_not_found();

$users = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE company_id = ? ORDER BY name');
$users->execute([$id]);
$users = $users->fetchAll();

$inv = $pdo->prepare('SELECT * FROM (' . invoice_base_sql() . ') t WHERE company_id = ? ORDER BY issue_date DESC, id DESC LIMIT 10');
$inv->execute([$id]);
$inv = $inv->fetchAll();
$tot = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(balance_cents), 0) due FROM (' . invoice_base_sql() . ') t WHERE company_id = ?');
$tot->execute([$id]);
$tot = $tot->fetch();

$none = '<span class="text-soft">Not set</span>';
$fields = [
    'Address' => company_address_line($c) !== '' ? e(company_address_line($c)) : $none,
    'Country' => e($c['country']),
    'KvK number' => $c['kvk'] !== '' ? '<span class="notranslate">' . e($c['kvk']) . '</span>' : $none,
    'VAT number' => $c['vat_number'] !== '' ? '<span class="notranslate">' . e($c['vat_number']) . '</span>' : $none,
    'Email' => $c['email'] !== '' ? '<a class="break-all underline decoration-lime decoration-2 underline-offset-4" href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : $none,
    'Phone' => $c['phone'] !== '' ? '<span class="notranslate">' . e($c['phone']) . '</span>' : $none,
    'Website' => $c['website'] !== '' ? '<a class="break-all underline decoration-lime decoration-2 underline-offset-4" rel="noopener noreferrer" target="_blank" href="' . e($c['website']) . '">' . e($c['website']) . '</a>' : $none,
    'Customer since' => e(date('j F Y', strtotime($c['created_at']))),
];

page_start($c['name'], 'companies', ($c['city'] !== '' ? $c['city'] . ' · ' : '') . count($users) . ' user' . (count($users) === 1 ? '' : 's'));
?>
<nav class="rise mb-4 text-sm" aria-label="Breadcrumb"><a href="companies.php" class="inline-flex items-center gap-1 font-semibold underline decoration-lime decoration-2 underline-offset-4">&larr; All companies</a></nav>

<div class="rise mb-5 flex flex-wrap gap-3">
  <a href="company_edit.php?id=<?= $id ?>" class="pressable inline-flex h-11 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-lime hover:text-navy">Edit company</a>
  <a href="invoice_new.php?company=<?= $id ?>" class="pressable inline-flex h-11 items-center gap-2 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?>New invoice</a>
</div>

<div class="grid gap-5 lg:grid-cols-3">
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel lg:col-span-2" aria-labelledby="det">
    <h2 id="det" class="text-base font-bold">Details</h2>
    <dl class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2">
      <?php foreach ($fields as $label => $html): ?>
        <div class="min-w-0"><dt class="text-xs font-semibold uppercase tracking-wide text-soft"><?= e($label) ?></dt><dd class="mt-1 text-sm font-medium"><?= $html ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <?php if ($c['notes'] !== ''): ?><div class="mt-5 border-t border-line pt-4"><p class="text-xs font-semibold uppercase tracking-wide text-soft">Notes</p><p class="mt-1 whitespace-pre-line text-sm"><?= e($c['notes']) ?></p></div><?php endif; ?>
  </section>

  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="us">
    <h2 id="us" class="text-base font-bold">Users</h2>
    <?php if ($users): ?>
      <ul class="mt-4 divide-y divide-line">
        <?php foreach ($users as $u): ?>
          <li class="flex items-center justify-between gap-3 py-2.5"><a href="user.php?id=<?= (int) $u['id'] ?>" class="min-w-0 truncate text-sm font-semibold hover:underline"><?= e($u['name']) ?></a><?= badge($u['status']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="mt-3 text-sm text-soft">No users belong to this company yet. Assign people from their own user page.</p>
    <?php endif; ?>
  </section>

  <section class="rise rounded-2xl border border-line bg-panel shadow-panel lg:col-span-3" aria-labelledby="iv">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3"><h2 id="iv" class="text-base font-bold">Invoices</h2>
      <span class="text-xs text-soft notranslate"><?= (int) $tot['n'] ?> in total · <?= e(money((int) $tot['due'])) ?> outstanding</span></div>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[620px] text-left text-sm">
        <thead class="bg-butter-50 text-xs text-soft"><tr><th scope="col" class="px-4 py-3 font-semibold">Invoice</th><th scope="col" class="px-4 py-3 font-semibold">Issued</th><th scope="col" class="px-4 py-3 font-semibold">Status</th><th scope="col" class="px-4 py-3 text-right font-semibold">Total</th><th scope="col" class="px-4 py-3 text-right font-semibold">Balance</th></tr></thead>
        <tbody class="divide-y divide-line">
          <?php foreach ($inv as $i): ?>
            <tr class="hover:bg-butter-50"><td class="px-4 py-3"><a href="invoice.php?id=<?= (int) $i['id'] ?>" class="font-semibold underline-offset-4 hover:text-lime hover:underline notranslate"><?= e($i['number']) ?></a></td>
              <td class="px-4 py-3 text-soft"><?= e(date('j M Y', strtotime($i['issue_date']))) ?></td><td class="px-4 py-3"><?= badge($i['state']) ?></td>
              <td class="px-4 py-3 text-right tabular-nums notranslate"><?= e(money((int) $i['total_cents'])) ?></td><td class="px-4 py-3 text-right tabular-nums notranslate"><?= e(money(max(0, (int) $i['balance_cents']))) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$inv): ?><tr><td colspan="5" class="px-4 py-8 text-center text-soft">No invoices for this company yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<?php page_end(); ?>
