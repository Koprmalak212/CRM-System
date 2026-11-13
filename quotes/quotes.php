<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/quotes.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();

$state = isset(QUOTE_STATES[$_GET['state'] ?? '']) ? (string) $_GET['state'] : '';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$sorts = ['number' => 'number', 'customer' => 'customer', 'issue_date' => 'issue_date', 'valid_until' => 'valid_until', 'total' => 'total_cents'];
$sortKey = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'issue_date';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = page_size(15);

$where = []; $args = [];
if ($q !== '') { $like = '%' . addcslashes($q, '\\%_') . '%'; $where[] = '(number LIKE ? OR customer LIKE ? OR title LIKE ?)'; array_push($args, $like, $like, $like); }
$base = quote_base_sql();
$wBase = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$cs = $pdo->prepare("SELECT state, COUNT(*) FROM ($base) t $wBase GROUP BY state");
$cs->execute($args);
$counts = $cs->fetchAll(PDO::FETCH_KEY_PAIR);
if ($state !== '') { $where[] = 'state = ?'; $args[] = $state; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$cnt = $pdo->prepare("SELECT COUNT(*) FROM ($base) t $w");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$rows = $pdo->prepare("SELECT * FROM ($base) t $w ORDER BY {$sorts[$sortKey]} $dir, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$rows->execute($args);
$rows = $rows->fetchAll();

$st = quotes_stats($pdo);
$baseQ = ['state' => $state, 'q' => $q, 'sort' => $sortKey, 'dir' => $dir];
$decided = (int) $st['won_n'] + (int) $st['lost_n'];

page_start('Quotes', 'quotes', 'Offers to customers: what is still open, what was won and what was lost.');
$field = 'h-11 rounded-lg border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<div class="rise mb-6 flex flex-wrap items-center justify-between gap-3">
  <dl class="grid flex-1 grid-cols-2 divide-line overflow-hidden rounded-xl border border-line bg-panel shadow-panel lg:grid-cols-4 lg:divide-x" style="min-width:min(100%,640px)">
    <?php
    $cards = [
        ['Open', money((int) $st['open_sum']), (int) $st['open_n'] . ' quote' . ((int) $st['open_n'] === 1 ? '' : 's') . ' waiting for an answer', 'text-ink'],
        ['Won', money((int) $st['won_sum']), (int) $st['won_n'] . ' accepted', 'text-lime'],
        ['Lost', money((int) $st['lost_sum']), (int) $st['lost_n'] . ' declined or expired', 'text-[#FF9C8C]'],
        ['Win rate', $st['win_rate'] === null ? '–' : rtrim(rtrim(number_format((float) $st['win_rate'], 1, ',', ''), '0'), ',') . '%', $decided > 0 ? (int) $st['won_n'] . ' of ' . $decided . ' quotes with an outcome' : 'No quote has an outcome yet', 'text-butter'],
    ];
    foreach ($cards as $i => [$label, $value, $note, $tone]): ?>
      <div class="p-5 <?= $i > 1 ? 'border-t border-line lg:border-t-0' : '' ?> <?= $i % 2 === 1 ? 'border-l border-line lg:border-l-0' : '' ?>">
        <dt class="text-sm font-semibold text-soft"><?= e($label) ?></dt>
        <dd class="mt-1 text-2xl font-bold leading-none tracking-tight tabular-nums notranslate <?= $tone ?>"><?= e($value) ?></dd>
        <dd class="mt-2 text-sm text-soft"><?= e($note) ?></dd>
      </div>
    <?php endforeach; ?>
  </dl>
  <a href="quotes/quote_edit.php" class="pressable inline-flex h-12 items-center gap-2 rounded-lg bg-lime px-5 text-sm font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?>New quote</a>
</div>

<nav class="rise mb-4 flex flex-wrap gap-2" aria-label="Quote status" style="animation-delay:.05s">
  <?php foreach (['' => 'All'] + QUOTE_STATES as $k => $label): $on = $state === $k; ?>
    <a href="<?= e(qs($baseQ, ['state' => $k, 'page' => 1])) ?>" <?= $on ? 'aria-current="true"' : '' ?>
       class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-ink text-ground' : 'border border-line bg-panel hover:bg-butter-50' ?>">
      <?= e($label) ?><span class="tabular-nums <?= $on ? 'text-ground/70' : 'text-soft' ?>"><?= $k === '' ? array_sum($counts) : (int) ($counts[$k] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<div class="rise overflow-hidden rounded-xl border border-line bg-panel shadow-panel" style="animation-delay:.08s">
  <form method="get" data-auto class="flex flex-wrap items-center gap-3 border-b border-line p-4">
    <input type="hidden" name="state" value="<?= e($state) ?>">
    <label class="sr-only" for="q">Search</label>
    <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Number, customer or subject" class="<?= $field ?> w-full sm:w-64">
    <button class="h-11 rounded-lg border border-line bg-panel px-5 text-sm font-semibold hover:bg-butter-50">Apply</button>
    <?php if ($q !== '' || $state !== ''): ?><a href="quotes/quotes.php" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
  </form>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[860px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <?= th_sort('Quote', 'number', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Customer', 'customer', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Date', 'issue_date', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Valid until', 'valid_until', $sortKey, $dir, $baseQ) ?>
        <?= th_sort('Amount', 'total', $sortKey, $dir, $baseQ, 'right') ?>
        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
      </tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $r): ?>
          <tr class="hover:bg-butter-50">
            <td class="px-4 py-3"><a href="quotes/quote.php?id=<?= (int) $r['id'] ?>" class="font-semibold tabular-nums underline decoration-lime/60 decoration-2 underline-offset-4 hover:decoration-lime notranslate"><?= e($r['number']) ?></a><?php if ($r['title'] !== ''): ?><p class="max-w-[18rem] truncate text-soft"><?= e($r['title']) ?></p><?php endif; ?></td>
            <td class="px-4 py-3"><p class="font-semibold"><?= e($r['customer']) ?></p><?php if ($r['email'] !== ''): ?><p class="text-soft"><?= e($r['email']) ?></p><?php endif; ?></td>
            <td class="px-4 py-3 tabular-nums text-soft"><?= e(date('j M Y', strtotime($r['issue_date']))) ?></td>
            <td class="px-4 py-3"><p class="tabular-nums"><?= e(date('j M Y', strtotime($r['valid_until']))) ?></p><p class="text-xs text-soft"><?= e(quote_state_text($r)) ?></p></td>
            <td class="px-4 py-3 text-right tabular-nums notranslate"><?= e(money((int) $r['total_cents'])) ?></td>
            <td class="px-4 py-3"><?= badge($r['state']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="px-4 py-14 text-center"><p class="text-base font-semibold"><?= !$counts && $q === '' ? 'No quotes yet' : 'No quotes match' ?></p><p class="mt-1 text-soft">Write a quote, send the PDF, and record whether it was won or lost.</p><a href="quotes/quote_edit.php" class="pressable mt-4 inline-flex h-10 items-center rounded-lg border border-line bg-panel px-4 text-sm font-semibold hover:bg-butter-50">New quote</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $baseQ, true) ?>
</div>
<?php page_end(); ?>
