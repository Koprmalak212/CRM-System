<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();
$types = ['Security audit', 'Usage summary', 'Access review', 'Storage growth', 'Incident digest', 'Billing export'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = in_array($_POST['type'] ?? '', $types, true) ? $_POST['type'] : '';
    if ($type === '') {
        flash('Choose a report type first.', 'bad');
    } else {
        $pdo->prepare("INSERT INTO reports (title, type, status, size_kb, author, generated_at) VALUES (?, ?, 'running', 0, ?, NOW())")
            ->execute([$type . ' · ' . date('j M Y'), $type, $_SESSION['admin']['name']]);
        log_activity('info', 'Report requested', $type);
        flash($type . ' is being generated.');
    }
    redirect('reports.php');
}

$status = in_array($_GET['status'] ?? '', ['ready', 'running', 'failed', 'scheduled'], true) ? $_GET['status'] : '';
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = 12;
$w = $status !== '' ? 'WHERE status = ?' : '';
$args = $status !== '' ? [$status] : [];
$cnt = $pdo->prepare("SELECT COUNT(*) FROM reports $w");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$rows = $pdo->prepare("SELECT * FROM reports $w ORDER BY generated_at DESC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$rows->execute($args);
$rows = $rows->fetchAll();
$counts = $pdo->query('SELECT status, COUNT(*) n FROM reports GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$base = ['status' => $status];

page_start('Reports', 'reports', 'Generated and scheduled reports. Ready reports download as CSV.');
?>
<div class="rise mb-6 rounded-2xl border border-line bg-panel p-4 shadow-panel sm:p-5">
  <form method="post" class="flex flex-wrap items-end gap-3"><?= csrf_field() ?>
    <div>
      <label for="type" class="block text-sm font-semibold">Generate a report</label>
      <select id="type" name="type" class="mt-1.5 h-11 min-w-56 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime">
        <?php foreach ($types as $t): ?><option><?= e($t) ?></option><?php endforeach; ?>
      </select>
    </div>
    <button class="inline-flex h-11 items-center gap-2 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?>Generate</button>
  </form>
</div>

<nav class="rise mb-4 flex flex-wrap gap-2" aria-label="Status filter" style="animation-delay:.05s">
  <?php foreach (['' => 'All', 'ready' => 'Ready', 'running' => 'Running', 'scheduled' => 'Scheduled', 'failed' => 'Failed'] as $k => $label): $on = $status === $k; ?>
    <a href="<?= e(qs([], ['status' => $k])) ?>" <?= $on ? 'aria-current="true"' : '' ?>
       class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-lime text-navy' : 'border border-line bg-panel hover:bg-butter-50' ?>">
      <?= e($label) ?><span class="tabular-nums <?= $on ? 'text-navy/70' : 'text-soft' ?>"><?= $k === '' ? array_sum($counts) : (int) ($counts[$k] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<div class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.08s">
  <div class="overflow-x-auto">
    <table class="w-full min-w-[720px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <th scope="col" class="px-4 py-3 font-semibold">Report</th><th scope="col" class="px-4 py-3 font-semibold">Type</th>
        <th scope="col" class="px-4 py-3 font-semibold">Status</th><th scope="col" class="px-4 py-3 font-semibold">Created by</th>
        <th scope="col" class="px-4 py-3 font-semibold">When</th><th scope="col" class="px-4 py-3 text-right font-semibold">Size</th>
        <th scope="col" class="px-4 py-3 text-right font-semibold">Action</th>
      </tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $r): ?>
          <tr class="hover:bg-butter-50">
            <td class="px-4 py-3 font-semibold"><?= e($r['title']) ?></td>
            <td class="px-4 py-3"><?= e($r['type']) ?></td>
            <td class="px-4 py-3"><?= badge($r['status']) ?></td>
            <td class="px-4 py-3 text-soft"><?= e($r['author']) ?></td>
            <td class="px-4 py-3 text-soft"><?= e(ago($r['generated_at'])) ?></td>
            <td class="px-4 py-3 text-right tabular-nums"><?= $r['status'] === 'ready' ? e(bytes_h((int) $r['size_kb'] * 1024)) : '—' ?></td>
            <td class="px-4 py-3 text-right">
              <?php if ($r['status'] === 'ready'): ?>
                <a href="report_download.php?id=<?= (int) $r['id'] ?>" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-lime hover:text-navy"><?= icon('download', 'h-4 w-4') ?>CSV</a>
              <?php else: ?><span class="text-soft">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="px-4 py-14 text-center"><p class="text-base font-semibold">No reports with this status</p><a href="reports.php" class="mt-4 inline-flex h-10 items-center rounded-xl bg-lime px-4 text-sm font-bold text-navy">Show all reports</a></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $base) ?>
</div>
<?php page_end(); ?>
