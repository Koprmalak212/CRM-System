<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();

$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$level = in_array($_GET['level'] ?? '', ['info', 'warning', 'error', 'critical'], true) ? $_GET['level'] : '';
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = 20;

$where = [];
$args = [];
if ($q !== '') { $where[] = '(actor LIKE ? OR action LIKE ? OR target LIKE ? OR ip LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($level !== '') { $where[] = 'level = ?'; $args[] = $level; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs $w");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$rows = $pdo->prepare("SELECT * FROM activity_logs $w ORDER BY created_at DESC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$rows->execute($args);
$rows = $rows->fetchAll();
$counts = $pdo->query('SELECT level, COUNT(*) n FROM activity_logs GROUP BY level')->fetchAll(PDO::FETCH_KEY_PAIR);
$base = ['q' => $q, 'level' => $level];

page_start('Activity logs', 'logs', 'Audit trail of sign-ins, changes and system events.');
$field = 'h-11 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<nav class="rise mb-4 flex flex-wrap gap-2" aria-label="Level filter">
  <?php foreach (['' => 'All', 'info' => 'Info', 'warning' => 'Warning', 'error' => 'Error', 'critical' => 'Critical'] as $k => $label): $on = $level === $k; ?>
    <a href="<?= e(qs(['q' => $q], ['level' => $k])) ?>" <?= $on ? 'aria-current="true"' : '' ?>
       class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-lime text-navy' : 'border border-line bg-panel hover:bg-butter-50' ?>">
      <?= e($label) ?><span class="tabular-nums <?= $on ? 'text-navy/70' : 'text-soft' ?>"><?= $k === '' ? array_sum($counts) : (int) ($counts[$k] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<div class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.05s">
  <form method="get" class="flex flex-wrap items-center gap-3 border-b border-line p-4">
    <input type="hidden" name="level" value="<?= e($level) ?>">
    <label class="sr-only" for="q">Search logs</label>
    <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Search actor, action, target or IP" class="<?= $field ?> w-full sm:w-80">
    <button class="h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Search</button>
    <?php if ($q !== '' || $level !== ''): ?><a href="logs.php" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
  </form>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[820px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <th scope="col" class="px-4 py-3 font-semibold">Level</th><th scope="col" class="px-4 py-3 font-semibold">Event</th>
        <th scope="col" class="px-4 py-3 font-semibold">Target</th><th scope="col" class="px-4 py-3 font-semibold">Actor</th>
        <th scope="col" class="px-4 py-3 font-semibold">IP address</th><th scope="col" class="px-4 py-3 text-right font-semibold">Time</th>
      </tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $r): ?>
          <tr class="hover:bg-butter-50">
            <td class="px-4 py-2.5"><?= badge($r['level']) ?></td>
            <td class="px-4 py-2.5 font-semibold"><?= e($r['action']) ?></td>
            <td class="px-4 py-2.5"><?= e($r['target']) ?></td>
            <td class="px-4 py-2.5 text-soft"><?= e($r['actor']) ?></td>
            <td class="px-4 py-2.5 tabular-nums text-soft"><?= e($r['ip']) ?></td>
            <td class="px-4 py-2.5 text-right tabular-nums text-soft" title="<?= e($r['created_at']) ?>"><?= e(ago($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="px-4 py-14 text-center"><p class="text-base font-semibold">No log entries match</p><p class="mt-1 text-soft">Try another search term or level.</p><a href="logs.php" class="mt-4 inline-flex h-10 items-center rounded-xl bg-lime px-4 text-sm font-bold text-navy">Show all logs</a></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $base) ?>
</div>
<?php page_end(); ?>
