<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT c.ip, u.name FROM connections c JOIN users u ON u.id = c.user_id WHERE c.id = ?');
    $stmt->execute([$id]);
    if ($c = $stmt->fetch()) {
        $pdo->prepare('DELETE FROM connections WHERE id = ?')->execute([$id]);
        log_activity('warning', 'Session terminated', $c['name'] . ' · ' . $c['ip']);
        flash('Session for ' . $c['name'] . ' (' . $c['ip'] . ') was terminated.');
    }
    redirect('connections.php' . safe_back($_POST['back'] ?? ''));
}

$status = in_array($_GET['status'] ?? '', ['active', 'idle', 'flagged'], true) ? $_GET['status'] : '';
$sorts = ['name' => 'u.name', 'ip' => 'c.ip', 'status' => 'c.status', 'started_at' => 'c.started_at', 'traffic' => '(c.bytes_in + c.bytes_out)'];
$sortKey = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'started_at';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = 15;

$w = $status !== '' ? 'WHERE c.status = ?' : '';
$args = $status !== '' ? [$status] : [];
$cnt = $pdo->prepare("SELECT COUNT(*) FROM connections c $w");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$rows = $pdo->prepare("SELECT c.*, u.name, u.email FROM connections c JOIN users u ON u.id = c.user_id $w ORDER BY {$sorts[$sortKey]} $dir, c.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$rows->execute($args);
$rows = $rows->fetchAll();
$counts = $pdo->query('SELECT status, COUNT(*) n FROM connections GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$base = ['status' => $status, 'sort' => $sortKey, 'dir' => $dir];

page_start('Connections', 'connections', 'Open sessions right now. Flagged ones come from addresses or clients we do not recognise.');
?>
<nav class="rise mb-4 flex flex-wrap gap-2" aria-label="Status filter">
  <?php foreach (['' => 'All', 'active' => 'Active', 'idle' => 'Idle', 'flagged' => 'Flagged'] as $k => $label): $on = $status === $k; ?>
    <a href="<?= e(qs([], ['status' => $k])) ?>" <?= $on ? 'aria-current="true"' : '' ?>
       class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-lime text-navy' : 'border border-line bg-panel hover:bg-butter-50' ?>">
      <?= e($label) ?><span class="tabular-nums <?= $on ? 'text-navy/70' : 'text-soft' ?>"><?= $k === '' ? array_sum($counts) : (int) ($counts[$k] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<div class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.05s">
  <div class="overflow-x-auto">
    <table class="w-full min-w-[900px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft">
        <tr>
          <?= th_sort('User', 'name', $sortKey, $dir, $base) ?>
          <?= th_sort('IP address', 'ip', $sortKey, $dir, $base) ?>
          <th scope="col" class="px-4 py-3 font-semibold">Device and place</th>
          <th scope="col" class="px-4 py-3 font-semibold">Protocol</th>
          <?= th_sort('Status', 'status', $sortKey, $dir, $base) ?>
          <?= th_sort('Connected', 'started_at', $sortKey, $dir, $base) ?>
          <?= th_sort('Traffic', 'traffic', $sortKey, $dir, $base, 'right') ?>
          <th scope="col" class="px-4 py-3 text-right font-semibold">Action</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $c): ?>
          <tr class="hover:bg-butter-50 <?= $c['status'] === 'flagged' ? 'bg-coral/10' : '' ?>">
            <td class="px-4 py-3"><p class="font-semibold"><?= e($c['name']) ?></p><p class="text-soft"><?= e($c['email']) ?></p></td>
            <td class="px-4 py-3 tabular-nums"><?= e($c['ip']) ?></td>
            <td class="px-4 py-3"><p><?= e($c['device']) ?></p><p class="text-soft"><?= e($c['location']) ?></p></td>
            <td class="px-4 py-3"><?= e($c['protocol']) ?></td>
            <td class="px-4 py-3"><?= badge($c['status']) ?></td>
            <td class="px-4 py-3 text-soft"><?= e(ago($c['started_at'])) ?></td>
            <td class="px-4 py-3 text-right tabular-nums"><?= e(bytes_h((int) $c['bytes_in'] + (int) $c['bytes_out'])) ?></td>
            <td class="px-4 py-3 text-right">
              <form method="post" data-exit><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="back" value="<?= e(qs($base, ['page' => $page])) ?>">
                <button class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]"><?= icon('x', 'h-4 w-4') ?>Terminate</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="px-4 py-14 text-center"><p class="text-base font-semibold">No open sessions here</p><p class="mt-1 text-soft">Nothing matches this filter right now.</p><a href="connections.php" class="mt-4 inline-flex h-10 items-center rounded-xl bg-lime px-4 text-sm font-bold text-navy">Show all sessions</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $base) ?>
</div>
<?php page_end(); ?>
