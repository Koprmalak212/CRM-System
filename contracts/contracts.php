<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/contracts.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();

$filters = ['' => 'All', 'active' => 'Active', 'renewal' => 'Renewal due', 'draft' => 'Draft', 'ended' => 'Ended'];
$state = isset($filters[$_GET['state'] ?? '']) ? (string) ($_GET['state'] ?? '') : '';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$company = max(0, (int) ($_GET['company'] ?? 0));
$sorts = ['number' => 'number', 'company' => 'company_name', 'monthly' => 'monthly_cents', 'start' => 'start_date', 'end' => 'end_date IS NULL, end_date', 'next' => 'next_invoice_date IS NULL, next_invoice_date'];
$sortKey = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'end';
$dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = page_size(15);

$where = []; $args = [];
if ($q !== '') { $like = '%' . addcslashes($q, '\\%_') . '%'; $where[] = '(number LIKE ? OR title LIKE ? OR company_name LIKE ?)'; array_push($args, $like, $like, $like); }
if ($company > 0) { $where[] = 'company_id = ?'; $args[] = $company; }
$base = contract_base_sql();
$wBase = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$cs = $pdo->prepare("SELECT COALESCE(SUM(status = 'active'), 0) active, COALESCE(SUM(renewal_due = 1), 0) renewal, COALESCE(SUM(status = 'draft'), 0) draft, COALESCE(SUM(status = 'ended'), 0) ended, COUNT(*) total FROM ($base) t $wBase");
$cs->execute($args);
$counts = $cs->fetch();

if ($state === 'renewal') { $where[] = 'renewal_due = 1'; } elseif ($state !== '') { $where[] = 'status = ?'; $args[] = $state; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$cnt = $pdo->prepare("SELECT COUNT(*) FROM ($base) t $w");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$order = str_contains($sorts[$sortKey], ',') ? str_replace(',', " $dir,", $sorts[$sortKey]) . " $dir" : $sorts[$sortKey] . " $dir";
$rows = $pdo->prepare("SELECT * FROM ($base) t $w ORDER BY status = 'ended', $order, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$rows->execute($args);
$rows = $rows->fetchAll();

$st = contracts_stats($pdo);
$companyList = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$companyQs = $company > 0 ? $company : '';
$baseQ = ['state' => $state, 'q' => $q, 'company' => $companyQs, 'sort' => $sortKey, 'dir' => $dir];
$billing = module_enabled('invoices');

page_start('Contracts', 'contracts', 'Which services every company has, what it pays a month, and when the contract ends or renews.');
$field = 'h-11 rounded-lg border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<div class="rise mb-6 flex flex-wrap items-center justify-between gap-3">
  <dl class="grid flex-1 grid-cols-2 divide-line overflow-hidden rounded-xl border border-line bg-panel shadow-panel lg:grid-cols-4 lg:divide-x" style="min-width:min(100%,640px)">
    <?php
    $cards = [
        ['Per month', money((int) $st['mrr']), 'All active contracts, excl. BTW', 'text-lime'],
        ['Per year', money((int) $st['mrr'] * 12), 'The same, times twelve', 'text-ink'],
        ['Active contracts', (string) (int) $st['active'], (int) $st['drafts'] . ' draft' . ((int) $st['drafts'] === 1 ? '' : 's') . ' in preparation', 'text-ink'],
        ['Renewal due', (string) (int) $st['renewing'], money((int) $st['renewing_mrr']) . ' a month inside its notice period', (int) $st['renewing'] > 0 ? 'text-butter' : 'text-ink'],
    ];
    foreach ($cards as $i => [$label, $value, $note, $tone]): ?>
      <div class="p-5 <?= $i > 1 ? 'border-t border-line lg:border-t-0' : '' ?> <?= $i % 2 === 1 ? 'border-l border-line lg:border-l-0' : '' ?>">
        <dt class="text-sm font-semibold text-soft"><?= e($label) ?></dt>
        <dd class="mt-1 text-2xl font-bold leading-none tracking-tight tabular-nums notranslate <?= $tone ?>"><?= e($value) ?></dd>
        <dd class="mt-2 text-sm text-soft"><?= e($note) ?></dd>
      </div>
    <?php endforeach; ?>
  </dl>
  <a href="contracts/contract_edit.php" class="pressable inline-flex h-12 items-center gap-2 rounded-lg bg-lime px-5 text-sm font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?>New contract</a>
</div>

<?php if (!$billing): ?>
  <p role="note" class="rise mb-4 rounded-lg border border-line bg-panel px-4 py-3 text-sm text-soft">The Invoices module is turned off, so contracts are tracked here but no invoices are made from them.</p>
<?php endif; ?>

<nav class="rise mb-4 flex flex-wrap gap-2" aria-label="Contract status" style="animation-delay:.05s">
  <?php foreach ($filters as $k => $label): $on = $state === $k; ?>
    <a href="<?= e(qs($baseQ, ['state' => $k, 'page' => 1])) ?>" <?= $on ? 'aria-current="true"' : '' ?>
       class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-ink text-ground' : 'border border-line bg-panel hover:bg-butter-50' ?>">
      <?= e($label) ?><span class="tabular-nums <?= $on ? 'text-ground/70' : 'text-soft' ?>"><?= (int) $counts[$k === '' ? 'total' : $k] ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<div class="rise overflow-hidden rounded-xl border border-line bg-panel shadow-panel" style="animation-delay:.08s">
  <form method="get" data-auto class="flex flex-wrap items-center gap-3 border-b border-line p-4">
    <input type="hidden" name="state" value="<?= e($state) ?>">
    <label class="sr-only" for="q">Search</label>
    <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Number, name or company" class="<?= $field ?> w-full sm:w-64">
    <label class="sr-only" for="company">Company</label>
    <select id="company" name="company" class="<?= $field ?> max-w-[14rem]"><option value="">All companies</option><?php foreach ($companyList as $cid => $cn): ?><option value="<?= (int) $cid ?>" <?= $company === (int) $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select>
    <button class="h-11 rounded-lg border border-line bg-panel px-5 text-sm font-semibold hover:bg-butter-50">Apply</button>
    <?php if ($q !== '' || $state !== '' || $company > 0): ?><a href="contracts/contracts.php" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
  </form>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[980px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <?= th_sort('Contract', 'number', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Company', 'company', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Per month', 'monthly', $sortKey, $dir, $baseQ, 'right') ?>
        <?= th_sort('Started', 'start', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Ends or renews', 'end', $sortKey, $dir, $baseQ) ?>
        <?php if ($billing): ?><?= th_sort('Next invoice', 'next', $sortKey, $dir, $baseQ) ?><?php endif; ?>
        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
      </tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $r): $due = (int) $r['renewal_due'] === 1; ?>
          <tr class="<?= $r['status'] === 'ended' ? 'bg-ink/5' : 'hover:bg-butter-50' ?>">
            <td class="px-4 py-3"><a href="contracts/contract.php?id=<?= (int) $r['id'] ?>" class="block max-w-[20rem] truncate font-semibold underline-offset-4 hover:text-lime hover:underline"><?= e($r['title']) ?></a><p class="text-soft notranslate"><?= e($r['number']) ?></p></td>
            <td class="px-4 py-3"><a class="underline-offset-4 hover:underline" href="companies/company.php?id=<?= (int) $r['company_id'] ?>"><?= e($r['company_name']) ?></a></td>
            <td class="px-4 py-3 text-right font-semibold tabular-nums notranslate"><?= e(money((int) $r['monthly_cents'])) ?></td>
            <td class="px-4 py-3 tabular-nums text-soft"><?= e(date('j M Y', strtotime($r['start_date']))) ?></td>
            <td class="px-4 py-3"><?php if ($r['end_date'] !== null): ?><p class="tabular-nums"><?= e(date('j M Y', strtotime($r['end_date']))) ?></p><?php endif; ?>
              <p class="text-xs <?= $due ? 'font-semibold text-butter' : 'text-soft' ?>"><?= e(contract_end_text($r)) ?></p></td>
            <?php if ($billing): ?><td class="px-4 py-3 tabular-nums"><?= $r['status'] === 'active' && $r['billing'] !== 'none' && $r['next_invoice_date'] !== null ? e(date('j M Y', strtotime($r['next_invoice_date']))) . '<p class="text-xs text-soft">' . e(strtolower(CONTRACT_BILLING[$r['billing']])) . '</p>' : '<span class="text-soft">None</span>' ?></td><?php endif; ?>
            <td class="px-4 py-3"><?= $due ? badge('due_renewal') : badge($r['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="px-4 py-14 text-center"><p class="text-base font-semibold"><?= (int) $counts['total'] === 0 && $q === '' && $company === 0 ? 'No contracts yet' : 'No contracts match' ?></p><p class="mt-1 text-soft">A contract lists the services of one company and what they cost a month.</p><a href="contracts/contract_edit.php" class="pressable mt-4 inline-flex h-10 items-center rounded-lg border border-line bg-panel px-4 text-sm font-semibold hover:bg-butter-50">New contract</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $baseQ, true) ?>
</div>
<?php page_end(); ?>
