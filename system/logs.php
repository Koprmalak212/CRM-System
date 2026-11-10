<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/csv.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_admin()) { deny_access('logs:clear'); }
    $days = (int) ($_POST['days'] ?? 0);
    if (!in_array($days, [30, 90, 180, 365], true)) { flash('Choose how old the entries must be.', 'error'); }
    elseif (($_POST['confirm'] ?? '') !== 'yes') { flash('Tick the box to confirm. Export the log first if you want to keep it.', 'error'); }
    else {
        $d = $pdo->prepare('DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL ' . $days . ' DAY');
        $d->execute();
        $n = $d->rowCount();
        log_activity('warning', 'Activity log cleared', $n . ' entries older than ' . $days . ' days');   // the clearing itself stays on record
        flash($n . ' log entr' . ($n === 1 ? 'y' : 'ies') . ' older than ' . $days . ' days removed.');
    }
    redirect('system/logs.php');
}

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
    <?php if ($q !== '' || $level !== ''): ?><a href="system/logs.php" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
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
        <?php if (!$rows): ?><tr><td colspan="6" class="px-4 py-14 text-center"><p class="text-base font-semibold">No log entries match</p><p class="mt-1 text-soft">Try another search term or level.</p><a href="system/logs.php" class="mt-4 inline-flex h-10 items-center rounded-xl bg-lime px-4 text-sm font-bold text-navy">Show all logs</a></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $base) ?>
</div>
<?php if (is_admin()): ?>
<section class="rise mt-6 rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="clr-h">
  <h2 id="clr-h" class="text-base font-bold">Keep the log tidy</h2>
  <p class="mt-1 text-sm text-soft">The log also clears itself after 12 months. To remove older entries sooner, export first, then clear. The clearing itself is logged.</p>
  <div class="mt-4 flex flex-wrap items-center gap-3">
    <?= csv_buttons('logs') ?>
    <form method="post" class="flex flex-wrap items-center gap-3"><?= csrf_field() ?>
      <label class="sr-only" for="days">Older than</label>
      <select id="days" name="days" class="h-11 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"><?php foreach ([30 => '30 days', 90 => '90 days', 180 => '180 days', 365 => '1 year'] as $d => $l): ?><option value="<?= $d ?>"><?= e('Older than ' . $l) ?></option><?php endforeach; ?></select>
      <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="yes" class="h-4 w-4 accent-[#3F6212]"> I exported what I need</label>
      <button class="pressable h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Clear older entries</button>
    </form>
  </div>
</section>
<?php endif; ?>
<?php page_end(); ?>
