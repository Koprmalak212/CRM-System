<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = in_array($_POST['type'] ?? '', ['full', 'incremental', 'database'], true) ? $_POST['type'] : 'incremental';
    $name = date('Y-m-d') . '_' . $type . '_' . date('His');
    $pdo->prepare("INSERT INTO backups (name, type, status, size_mb, started_at, duration_s, location) VALUES (?, ?, 'running', 0, NOW(), 0, 'Local · /var/backups')")
        ->execute([$name, $type]);
    log_activity('info', 'Backup started', $name);
    flash('Backup ' . $name . ' has started.');
    redirect('system/backups.php');
}

$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = 12;
$total = (int) $pdo->query('SELECT COUNT(*) FROM backups')->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$rows = $pdo->query("SELECT * FROM backups ORDER BY started_at DESC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per))->fetchAll();

$ok = $pdo->query("SELECT started_at FROM backups WHERE status = 'completed' ORDER BY started_at DESC LIMIT 1")->fetchColumn();
$fail7 = (int) $pdo->query("SELECT COUNT(*) FROM backups WHERE status = 'failed' AND started_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$size = (int) $pdo->query("SELECT COALESCE(SUM(size_mb),0) FROM backups WHERE status = 'completed'")->fetchColumn();

page_start('Backups', 'backups', 'History of every backup run and where it was stored.');
?>
<div class="rise mb-6 grid gap-4 lg:grid-cols-[1fr_auto]">
  <dl class="grid grid-cols-3 divide-x divide-line overflow-hidden rounded-xl border border-line bg-panel shadow-panel">
    <div class="p-4 sm:p-5"><dt class="text-sm font-semibold text-soft">Last good backup</dt><dd class="mt-1 text-xl font-bold sm:text-2xl"><?= $ok ? e(ago($ok)) : 'None' ?></dd></div>
    <div class="p-4 sm:p-5"><dt class="text-sm font-semibold text-soft">Failed, 7 days</dt><dd class="mt-1 text-xl font-bold sm:text-2xl <?= $fail7 ? 'text-[#FF9C8C]' : '' ?>"><?= $fail7 ?></dd></div>
    <div class="p-4 sm:p-5"><dt class="text-sm font-semibold text-soft">Stored</dt><dd class="mt-1 text-xl font-bold sm:text-2xl"><?= e(bytes_h($size * 1048576)) ?></dd></div>
  </dl>
  <form method="post" class="flex flex-wrap items-end gap-3 rounded-xl bg-butter p-4 text-navy sm:p-5"><?= csrf_field() ?>
    <div>
      <label for="btype" class="block text-sm font-semibold">Run a backup now</label>
      <select id="btype" name="type" class="mt-1.5 h-11 rounded-lg border border-navy/15 bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime">
        <option value="incremental">Incremental</option><option value="database">Database only</option><option value="full">Full</option>
      </select>
    </div>
    <button class="inline-flex h-11 items-center gap-2 rounded-lg bg-navy px-5 text-sm font-bold text-white hover:bg-navy-800"><?= icon('database', 'h-4 w-4') ?>Start</button>
  </form>
</div>

<div class="rise overflow-hidden rounded-xl border border-line bg-panel shadow-panel" style="animation-delay:.05s">
  <div class="overflow-x-auto">
    <table class="w-full min-w-[760px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <th scope="col" class="px-4 py-3 font-semibold">Backup</th><th scope="col" class="px-4 py-3 font-semibold">Type</th>
        <th scope="col" class="px-4 py-3 font-semibold">Status</th><th scope="col" class="px-4 py-3 font-semibold">Started</th>
        <th scope="col" class="px-4 py-3 text-right font-semibold">Duration</th><th scope="col" class="px-4 py-3 text-right font-semibold">Size</th>
        <th scope="col" class="px-4 py-3 font-semibold">Location</th>
      </tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $b): ?>
          <tr class="hover:bg-butter-50 <?= $b['status'] === 'failed' ? 'bg-coral/10' : '' ?>">
            <td class="px-4 py-3 font-semibold tabular-nums"><?= e($b['name']) ?></td>
            <td class="px-4 py-3 capitalize"><?= e($b['type']) ?></td>
            <td class="px-4 py-3"><?= badge($b['status']) ?></td>
            <td class="px-4 py-3 text-soft"><?= e(ago($b['started_at'])) ?></td>
            <td class="px-4 py-3 text-right tabular-nums"><?= $b['status'] === 'completed' || $b['status'] === 'failed' ? e(duration((int) $b['duration_s'])) : '—' ?></td>
            <td class="px-4 py-3 text-right tabular-nums"><?= $b['status'] === 'completed' ? e(bytes_h((int) $b['size_mb'] * 1048576)) : '—' ?></td>
            <td class="px-4 py-3 text-soft"><?= e($b['location']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, []) ?>
</div>
<?php page_end(); ?>
