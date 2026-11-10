<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/invoices.php';
require __DIR__ . '/includes/csv.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();

$state = in_array($_GET['state'] ?? '', ['open', 'due', 'overdue', 'paid'], true) ? $_GET['state'] : '';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$from = valid_date((string) ($_GET['from'] ?? '')) ? $_GET['from'] : '';
$to = valid_date((string) ($_GET['to'] ?? '')) ? $_GET['to'] : '';
$sorts = ['number' => 'number', 'customer' => 'customer', 'issue_date' => 'issue_date', 'due_date' => 'due_date', 'total' => 'total_cents', 'balance' => 'balance_cents'];
$sortKey = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'issue_date';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = 15;

// Date range and search narrow the set; the state chips count inside that range.
$where = [];
$args = [];
if ($q !== '') { $likeQ = '%' . addcslashes($q, '\\%_') . '%'; $where[] = '(number LIKE ? OR customer LIKE ?)'; array_push($args, $likeQ, $likeQ); }
if ($from !== '') { $where[] = 'issue_date >= ?'; $args[] = $from; }
if ($to !== '') { $where[] = 'issue_date <= ?'; $args[] = $to; }
$wBase = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$base = invoice_base_sql();

$cs = $pdo->prepare("SELECT state, COUNT(*) FROM ($base) t $wBase GROUP BY state");
$cs->execute($args);
$counts = $cs->fetchAll(PDO::FETCH_KEY_PAIR);

$w2 = $where;
$a2 = $args;
if ($state !== '') { $w2[] = 'state = ?'; $a2[] = $state; }
$w = $w2 ? 'WHERE ' . implode(' AND ', $w2) : '';
$cnt = $pdo->prepare("SELECT COUNT(*) FROM ($base) t $w");
$cnt->execute($a2);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$rows = $pdo->prepare("SELECT * FROM ($base) t $w ORDER BY {$sorts[$sortKey]} $dir, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$rows->execute($a2);
$rows = $rows->fetchAll();

// Money overview (whole book, not filtered)
$sum = $pdo->query("SELECT
    COALESCE(SUM(CASE WHEN state <> 'paid' THEN balance_cents END), 0) outstanding,
    COALESCE(SUM(CASE WHEN state = 'overdue' THEN balance_cents END), 0) overdue_sum, SUM(state = 'overdue') overdue_n,
    COALESCE(SUM(CASE WHEN state = 'due' THEN balance_cents END), 0) due_sum, SUM(state = 'due') due_n
  FROM ($base) t")->fetch();
$collected = (int) $pdo->query('SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE paid_at >= CURDATE() - INTERVAL 30 DAY')->fetchColumn();

$baseQ = ['state' => $state, 'q' => $q, 'from' => $from, 'to' => $to, 'sort' => $sortKey, 'dir' => $dir];
$presets = [
    'This month' => [date('Y-m-01'), date('Y-m-t')],
    'Last 30 days' => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
    'Last 90 days' => [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')],
];

page_start('Invoices', 'invoices', 'Create invoices, see what is due or overdue, and record payments as they arrive.');
$field = 'h-11 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<div class="rise mb-6 flex flex-wrap items-center justify-between gap-3">
  <dl class="grid flex-1 grid-cols-2 divide-line overflow-hidden rounded-2xl border border-line bg-panel shadow-panel lg:grid-cols-4 lg:divide-x" style="min-width:min(100%,640px)">
    <?php
    $cards = [
        ['Outstanding', money((int) $sum['outstanding']), 'Open, due and overdue balances', 'text-ink'],
        ['Overdue', money((int) $sum['overdue_sum']), (int) $sum['overdue_n'] . ' invoice' . ((int) $sum['overdue_n'] === 1 ? '' : 's') . ' past the due date', 'text-[#FF9C8C]'],
        ['Due within 7 days', money((int) $sum['due_sum']), (int) $sum['due_n'] . ' invoice' . ((int) $sum['due_n'] === 1 ? '' : 's') . ' coming up', 'text-butter'],
        ['Collected, 30 days', money($collected), 'Payments received', 'text-lime'],
    ];
    foreach ($cards as $i => [$label, $value, $note, $tone]): ?>
      <div class="p-5 <?= $i > 1 ? 'border-t border-line lg:border-t-0' : '' ?> <?= $i % 2 === 1 ? 'border-l border-line lg:border-l-0' : '' ?>">
        <dt class="text-sm font-medium text-soft"><?= e($label) ?></dt>
        <dd class="mt-1 text-[26px] font-bold leading-none tracking-tight tabular-nums notranslate <?= $tone ?>"><?= e($value) ?></dd>
        <dd class="mt-2 text-sm text-soft"><?= e($note) ?></dd>
      </div>
    <?php endforeach; ?>
  </dl>
  <div class="flex flex-wrap gap-2"><a href="recurring.php" class="pressable inline-flex h-11 items-center gap-2 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy">Recurring</a><?= csv_buttons('invoices') ?>
  <a href="invoice_new.php" class="pressable inline-flex h-12 items-center gap-2 rounded-xl bg-lime px-5 text-[15px] font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?>New invoice</a></div>
</div>

<nav class="rise mb-4 flex flex-wrap gap-2" aria-label="Invoice status" style="animation-delay:.05s">
  <?php foreach (['' => 'All', 'open' => 'Open', 'due' => 'Due soon', 'overdue' => 'Overdue', 'paid' => 'Paid'] as $k => $label): $on = $state === $k; ?>
    <a href="<?= e(qs($baseQ, ['state' => $k, 'page' => 1])) ?>" <?= $on ? 'aria-current="true"' : '' ?>
       class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-lime text-navy' : 'border border-line bg-panel hover:bg-butter-50' ?>">
      <?= e($label) ?><span class="tabular-nums <?= $on ? 'text-navy/70' : 'text-soft' ?>"><?= $k === '' ? array_sum($counts) : (int) ($counts[$k] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<div class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.08s">
  <form method="get" class="flex flex-wrap items-end gap-3 border-b border-line p-4">
    <input type="hidden" name="state" value="<?= e($state) ?>">
    <div><label class="mb-1 block text-xs font-semibold text-soft" for="q">Search</label>
      <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Number or customer" class="<?= $field ?> w-full sm:w-60"></div>
    <div><label class="mb-1 block text-xs font-semibold text-soft" for="from">Issued from</label>
      <input id="from" name="from" type="date" value="<?= e($from) ?>" class="<?= $field ?>"></div>
    <div><label class="mb-1 block text-xs font-semibold text-soft" for="to">Issued until</label>
      <input id="to" name="to" type="date" value="<?= e($to) ?>" class="<?= $field ?>"></div>
    <button class="h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Apply</button>
    <div class="flex w-full flex-wrap items-center gap-2 pt-1">
      <?php foreach ($presets as $label => [$f, $t]): $on = $from === $f && $to === $t; ?>
        <a href="<?= e(qs($baseQ, ['from' => $f, 'to' => $t, 'page' => 1])) ?>" class="inline-flex h-8 items-center rounded-full px-3 text-xs font-semibold <?= $on ? 'bg-butter text-navy' : 'border border-line hover:bg-butter-50' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if ($q !== '' || $from !== '' || $to !== '' || $state !== ''): ?><a href="invoices.php" class="ml-1 text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
    </div>
  </form>

  <div class="overflow-x-auto">
    <table class="w-full min-w-[920px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <?= th_sort('Invoice', 'number', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Customer', 'customer', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Issued', 'issue_date', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Due', 'due_date', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Amount', 'total', $sortKey, $dir, $baseQ, 'right') ?>
        <?= th_sort('Balance', 'balance', $sortKey, $dir, $baseQ, 'right') ?>
        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
        <th scope="col" class="px-4 py-3 text-right font-semibold">Action</th>
      </tr></thead>
      <tbody class="divide-y divide-line">
        <?php $lastMonth = ''; foreach ($rows as $r):
          $month = date('F Y', strtotime($r[$sortKey === 'due_date' ? 'due_date' : 'issue_date']));
          if (in_array($sortKey, ['issue_date', 'due_date'], true) && $month !== $lastMonth): $lastMonth = $month; ?>
            <tr class="bg-white/[0.03]"><th colspan="8" scope="colgroup" class="px-4 py-2 text-left text-xs font-semibold text-soft"><?= e($month) ?></th></tr>
          <?php endif; ?>
          <tr class="hover:bg-butter-50 <?= $r['state'] === 'overdue' ? 'bg-coral/10' : '' ?>">
            <td class="px-4 py-3 font-semibold tabular-nums"><a href="invoice.php?id=<?= (int) $r['id'] ?>" class="underline decoration-lime/60 decoration-2 underline-offset-4 hover:decoration-lime"><?= e($r['number']) ?></a></td>
            <td class="px-4 py-3"><p class="font-semibold"><?= e($r['customer']) ?></p><p class="text-soft"><?= e($r['email']) ?></p></td>
            <td class="px-4 py-3 tabular-nums text-soft"><?= e(date('j M Y', strtotime($r['issue_date']))) ?></td>
            <td class="px-4 py-3"><p class="tabular-nums"><?= e(date('j M Y', strtotime($r['due_date']))) ?></p>
              <p class="text-xs <?= $r['state'] === 'overdue' ? 'font-semibold text-[#FF9C8C]' : 'text-soft' ?>"><?= e(invoice_state_text($r)) ?></p></td>
            <td class="px-4 py-3 text-right tabular-nums notranslate"><?= e(money((int) $r['total_cents'])) ?></td>
            <td class="px-4 py-3 text-right tabular-nums notranslate <?= (int) $r['balance_cents'] > 0 ? 'font-semibold' : 'text-soft' ?>"><?= e(money(max(0, (int) $r['balance_cents']))) ?></td>
            <td class="px-4 py-3"><?= badge($r['state']) ?><?php if ((int) $r['paid_cents'] > 0 && $r['state'] !== 'paid'): ?><p class="mt-1 text-xs text-soft">Partly paid</p><?php endif; ?></td>
            <td class="px-4 py-3 text-right">
              <a href="invoice.php?id=<?= (int) $r['id'] ?>" class="pressable inline-flex h-9 items-center gap-1.5 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-lime hover:text-navy"><?= $r['state'] === 'paid' ? 'View' : 'Record payment' ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="px-4 py-14 text-center"><p class="text-base font-semibold">No invoices match</p><p class="mt-1 text-soft">Try another date range or clear the filters.</p><a href="invoices.php" class="pressable mt-4 inline-flex h-10 items-center rounded-xl bg-lime px-4 text-sm font-bold text-navy">Show all invoices</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $baseQ) ?>
</div>
<?php page_end(); ?>
