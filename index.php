<?php
declare(strict_types=1);
require __DIR__ . '/includes/core/bootstrap.php';
require __DIR__ . '/includes/modules/invoices.php';
require __DIR__ . '/includes/modules/trends.php';
require __DIR__ . '/includes/modules/sla.php';
require __DIR__ . '/includes/modules/contracts.php';
require __DIR__ . '/includes/core/layout.php';
require_login();
$pdo = db();

$usersTotal = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$usersNew = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 30 DAY')->fetchColumn();
// every module block below is skipped when its module is off, and gets safe defaults instead
$ticketsOpen = 0;
$ticketsUrgent = 0;
if (module_enabled('tickets')) {
    $ticketsOpen = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed')")->fetchColumn();
    $ticketsUrgent = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed') AND priority = 'urgent'")->fetchColumn();
}
$sla = module_enabled('tickets') && sla_enabled($pdo) ? sla_stats($pdo) : null;   // null: no SLA tile and no SLA line
$renewing = module_enabled('contracts') ? (int) contracts_stats($pdo)['renewing'] : 0;
$usersPending = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
$conn = ['total' => 0, 'flagged' => 0];
if (module_enabled('connections')) { $conn = $pdo->query("SELECT COUNT(*) total, SUM(status = 'flagged') flagged FROM connections")->fetch(); }
$lastBackup = null;
$failedBackups = [];
if (module_enabled('backups')) {
    $lastBackup = $pdo->query("SELECT started_at FROM backups WHERE status = 'completed' ORDER BY started_at DESC LIMIT 1")->fetchColumn();
    $failedBackups = $pdo->query("SELECT id, name, started_at FROM backups WHERE status = 'failed' AND started_at >= NOW() - INTERVAL 48 HOUR ORDER BY started_at DESC")->fetchAll();
}
$err24 = 0;
$errPrev = 0;
if (module_enabled('logs')) {
    $err24 = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE level IN ('error','critical') AND created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
    $errPrev = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE level IN ('error','critical') AND created_at BETWEEN NOW() - INTERVAL 48 HOUR AND NOW() - INTERVAL 24 HOUR")->fetchColumn();
}

// Service health ribbons and the endpoints that are down (module: health)
$services = [];
$checks = [];
$healthy = 0;
$notHealthy = [];
$epDown = [];
if (module_enabled('health')) {
    $services = $pdo->query('SELECT id, name FROM services ORDER BY sort_order')->fetchAll();
    foreach ($pdo->query('SELECT service_id, slot, status FROM service_checks ORDER BY slot') as $c) {
        $checks[(int) $c['service_id']][(int) $c['slot']] = $c['status'];
    }
    foreach ($services as &$s) {
        $row = $checks[(int) $s['id']] ?? [];
        $s['now'] = $row[35] ?? 'ok';
        $okCount = count(array_filter($row, fn($v) => $v === 'ok'));
        $s['uptime'] = count($row) ? $okCount / count($row) * 100 : 100;
        $s['row'] = $row;
        if ($s['now'] === 'ok') $healthy++; else $notHealthy[] = $s;
    }
    unset($s);
    $epDown = $pdo->query("SELECT name FROM monitored_endpoints WHERE enabled = 1 AND last_state = 'down' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

// Activity per hour, last 24h
$hours = array_fill(0, 24, ['info' => 0, 'warn' => 0, 'bad' => 0]);
foreach ($pdo->query('SELECT TIMESTAMPDIFF(HOUR, created_at, NOW()) h, level, COUNT(*) n FROM activity_logs WHERE created_at >= NOW() - INTERVAL 24 HOUR GROUP BY h, level') as $r) {
    $h = (int) $r['h'];
    if ($h < 0 || $h > 23) continue;
    $k = $r['level'] === 'info' ? 'info' : ($r['level'] === 'warning' ? 'warn' : 'bad');
    $hours[$h][$k] += (int) $r['n'];
}
$hours = array_reverse($hours, true); // oldest first
$maxH = max(1, ...array_map(fn($x) => array_sum($x), $hours));
$recent = $pdo->query("SELECT level, actor, action, target, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 8")->fetchAll();

$od = ['n' => 0, 's' => 0];
if (module_enabled('invoices')) { $od = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(balance_cents), 0) s FROM (" . invoice_base_sql() . ") t WHERE state = 'overdue'")->fetch(); }
$problems = count($notHealthy) + (count($epDown) > 0 ? 1 : 0) + ((int) $od['n'] > 0 ? 1 : 0) + count($failedBackups) + ((int) $conn['flagged'] > 0 ? 1 : 0) + ($sla !== null && $sla['open_breached'] > 0 ? 1 : 0) + ($renewing > 0 ? 1 : 0);
$delta = $err24 - $errPrev;
// the headline names only the parts whose module is on
$headParts = [];
if (module_enabled('health')) { $headParts[] = $services ? $healthy . ' of ' . count($services) . ' services healthy' : 'Nothing is tracked yet'; }
if ($problems > 0) { $headParts[] = $problems . ' item' . ($problems === 1 ? '' : 's') . ' need attention'; }
$headline = $headParts ? implode(', ', $headParts) . '.' : 'Nothing needs attention.';
// stat tiles: only the ones whose module is on, and the grid gets as many columns as there are tiles
$stats = [['Accounts', number_format($usersTotal), '+' . $usersNew . ' in 30 days', 'users/users.php']];
if (module_enabled('tickets')) { $stats[] = ['Open tickets', (string) $ticketsOpen, $ticketsUrgent . ' urgent', 'tickets/tickets.php?status=open']; }
if ($sla !== null) { $stats[] = ['SLA met, 30 days', $sla['pct'] === null ? '–' : rtrim(rtrim(number_format($sla['pct'], 1, ',', ''), '0'), ',') . '%', $sla['pct'] === null ? 'Nothing resolved with a target yet' : $sla['met'] . ' of ' . $sla['total'] . ' resolved in time', 'tickets/tickets.php?sla=breached']; }
if (module_enabled('connections')) { $stats[] = ['Open sessions', (string) (int) $conn['total'], (int) $conn['flagged'] . ' flagged', 'monitoring/connections.php']; }
if (module_enabled('backups')) { $stats[] = ['Last good backup', $lastBackup ? ago($lastBackup) : 'None', count($failedBackups) . ' failed in 48 h', 'system/backups.php']; }
if (module_enabled('logs')) { $stats[] = ['Errors, 24 h', (string) $err24, ($delta === 0 ? 'same as before' : ($delta > 0 ? '+' . $delta . ' vs previous 24 h' : $delta . ' vs previous 24 h')), 'system/logs.php?level=error']; }
$statN = count($stats);
$statCols = ['', '', 'lg:grid-cols-2 lg:divide-x', 'lg:grid-cols-3 lg:divide-x', 'lg:grid-cols-4 lg:divide-x', 'lg:grid-cols-5 lg:divide-x', 'lg:grid-cols-3'][$statN];
// the lines between the tiles: two columns on a phone, one row on a wide screen; six tiles make two rows of three there
$tileCls = function (int $i) use ($statN): string {
    if ($statN === 6) {
        return [0 => '', 1 => 'border-l border-line', 2 => 'border-t border-line lg:border-l lg:border-t-0', 3 => 'border-l border-t border-line lg:border-l-0', 4 => 'border-t border-line lg:border-l', 5 => 'border-l border-t border-line'][$i];
    }
    return ($i > 1 ? 'border-t border-line lg:border-t-0' : '') . ' ' . ($i === $statN - 1 && $statN % 2 === 1 ? ($statN === 1 ? 'col-span-2' : 'col-span-2 lg:col-span-1') : '') . ' ' . ($i % 2 === 1 ? 'border-l border-line lg:border-l-0' : '');
};

page_start('Overview', 'overview', 'Live state of the platform over the last 24 hours.');
?>

<section class="rise rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-label="Status summary">
  <div class="flex flex-wrap items-center justify-between gap-4">
    <div>
      <p class="flex items-center gap-3 text-lg font-bold tracking-tight">
        <span class="h-2.5 w-2.5 shrink-0 rounded-full <?= $problems > 0 ? 'bg-[#E9A23B]' : ($services || !module_enabled('health') ? 'bg-lime' : 'bg-ink/30') ?>" aria-hidden="true"></span><?= $headline ?>
      </p>
      <p class="mt-1 text-sm text-soft">
        <?= $lastBackup ? 'Last good backup ' . e(ago($lastBackup)) . '. ' : '' ?><?= number_format($usersTotal) ?> accounts<?= module_enabled('connections') ? ', ' . (int) $conn['total'] . ' open sessions' : '' ?>.
      </p>
    </div>
    <div class="flex flex-wrap gap-2">
      <?php if (module_enabled('logs') && $err24 > 0): ?><a href="system/logs.php?level=error" class="inline-flex h-10 items-center gap-2 rounded-lg bg-ink px-4 text-sm font-semibold text-ground hover:bg-ink/90">Review <?= $err24 ?> error<?= $err24 === 1 ? '' : 's' ?> <?= icon('arrow', 'h-4 w-4') ?></a><?php endif; ?>
      <?php if (module_enabled('backups')): ?><a href="system/backups.php" class="inline-flex h-10 items-center rounded-lg border border-line px-4 text-sm font-semibold hover:bg-butter-50">Backups</a><?php endif; ?>
      <?php if (is_admin()): ?><a href="<?= self_url() ?>#demo-data" class="pressable inline-flex h-10 items-center gap-2 rounded-lg border border-line px-4 text-sm font-semibold hover:bg-butter-50"><?= icon('database', 'h-4 w-4') ?>Demo data</a><?php endif; ?>
    </div>
  </div>
</section>

<div class="stat-grid rise mt-6 grid grid-cols-2 divide-line overflow-hidden rounded-xl border border-line bg-panel shadow-panel <?= $statCols ?>">
  <?php foreach ($stats as $i => [$label, $value, $note, $href]): ?>
    <a href="<?= e($href) ?>" class="group block p-5 hover:bg-butter-50 <?= $tileCls($i) ?>">
      <dl>
      <dt class="text-sm font-semibold text-soft"><?= e($label) ?></dt>
      <dd class="mt-1 text-3xl font-bold leading-none tracking-tight"<?= ctype_digit($value) ? ' data-count="' . e($value) . '"' : '' ?>><?= e($value) ?></dd>
      <dd class="mt-2 text-sm text-soft"><?= e($note) ?></dd>
      </dl>
    </a>
  <?php endforeach; ?>
</div>

<div class="mt-6 grid items-start gap-6<?= module_enabled('health') ? ' xl:grid-cols-[1.6fr_1fr]' : '' ?>">
  <?php if (module_enabled('health')): ?><!-- Service health: the signature ribbon -->
  <section class="on-navy rise overflow-hidden rounded-xl border border-line bg-deep text-white shadow-panel" aria-labelledby="svc-h">
    <div class="flex items-end justify-between gap-4 px-5 pb-3 pt-5 sm:px-6">
      <div>
        <h2 id="svc-h" class="text-lg font-bold tracking-tight">Service health</h2>
        <p class="text-sm text-[#AEBAD6]">One bar per 40 minutes, last 24 hours</p>
      </div>
      <p class="hidden items-center gap-4 text-xs text-[#C3CCE0] sm:flex">
        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-1.5 rounded-sm bg-lime"></span>Operational</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-1.5 rounded-sm bg-butter"></span>Degraded</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-1.5 rounded-sm bg-coral"></span>Outage</span>
      </p>
    </div>
    <?php if (!$services): ?>
      <div class="px-5 pb-6 pt-2 sm:px-6"><p class="max-w-md text-sm text-[#C3CCE0]">No services are being tracked yet. Add an endpoint in Settings and its health will be recorded here and in the health API.</p>
        <a href="settings/settings.php?tab=integrations" class="pressable mt-4 inline-flex h-10 items-center gap-2 rounded-lg bg-lime px-4 text-sm font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?>Add an endpoint</a></div>
    <?php endif; ?>
    <ul class="divide-y divide-white/10">
      <?php foreach ($services as $si => $s): ?>
        <li class="ribbon-row grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-2 px-5 py-3.5 sm:grid-cols-[190px_1fr_64px] sm:px-6">
          <p class="font-semibold leading-tight"><?= e($s['name']) ?><span class="block pt-0.5 text-xs font-semibold <?= $s['now'] === 'ok' ? 'text-lime' : ($s['now'] === 'degraded' ? 'text-butter' : 'text-[#FF9C8C]') ?>"><?= $s['now'] === 'ok' ? 'Operational' : ucfirst($s['now']) ?></span></p>
          <p class="order-3 col-span-2 flex items-end gap-[3px] sm:order-none sm:col-span-1" role="img" aria-label="<?= e($s['name']) ?> health over 24 hours: <?= number_format($s['uptime'], 1) ?>% operational">
            <?php foreach ($s['row'] as $slot => $st): ?>
              <span class="bar block h-6 min-w-0 flex-1 rounded-[3px] <?= $st === 'ok' ? 'bg-lime' : ($st === 'degraded' ? 'bg-butter' : 'bg-coral') ?>" style="--i:<?= $slot ?>" title="<?= e(($st === 'ok' ? 'Operational' : ucfirst($st)) . ' · ' . date('H:i', time() - (35 - $slot) * 2400)) ?>"></span>
            <?php endforeach; ?>
          </p>
          <p class="text-right text-sm font-semibold tabular-nums" data-count="<?= number_format($s['uptime'], 1, '.', '') ?>" data-decimals="1" data-suffix="%"><?= number_format($s['uptime'], 1) ?>%</p>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php endif; ?><!-- Needs attention -->
  <section class="rise rounded-xl border border-line bg-panel shadow-panel" aria-labelledby="att-h">
    <div class="px-5 pb-2 pt-5"><h2 id="att-h" class="text-lg font-bold tracking-tight">Needs attention</h2></div>
    <?php if ($problems === 0 && $usersPending === 0): ?>
      <p class="px-5 pb-5 text-sm text-soft">Nothing is waiting on you. New issues will appear here.</p>
    <?php else: ?>
    <ul class="divide-y divide-line">
      <?php foreach ($notHealthy as $s): [$o, $c] = module_enabled('logs') ? ['<a href="system/logs.php?q=' . urlencode(strtolower(explode(' ', $s['name'])[0])) . '"', '</a>'] : ['<div', '</div>']; ?>
        <li><?= $o ?> class="flex items-start gap-3 px-5 py-3.5 hover:bg-butter-50">
          <?= badge($s['now']) ?><span class="min-w-0 flex-1 text-sm"><span class="font-semibold"><?= e($s['name']) ?></span> is <?= e($s['now']) ?> right now.</span><?= $c ?></li>
      <?php endforeach; ?>
      <?php foreach ($failedBackups as $b): ?>
        <li><a href="system/backups.php" class="flex items-start gap-3 px-5 py-3.5 hover:bg-butter-50">
          <?= badge('failed') ?><span class="min-w-0 flex-1 text-sm">Backup <span class="font-semibold"><?= e($b['name']) ?></span> failed <?= e(str_replace(' ', "\u{00A0}", ago($b['started_at']))) ?>.</span></a></li>
      <?php endforeach; ?>
      <?php if ($epDown && integrations_level() !== 'none'): ?>
        <li><a href="settings/settings.php?tab=integrations" class="flex items-start gap-3 px-5 py-3.5 hover:bg-butter-50">
          <?= badge('down') ?><span class="min-w-0 flex-1 text-sm"><span class="font-semibold"><?= e(implode(', ', array_slice($epDown, 0, 3))) ?></span> did not respond to the last health check.</span></a></li>
      <?php endif; ?>
      <?php if ($sla !== null && $sla['open_breached'] > 0): ?>
        <li><a href="tickets/tickets.php?sla=breached" class="flex items-start gap-3 px-5 py-3.5 hover:bg-butter-50">
          <?= badge('overdue') ?><span class="min-w-0 flex-1 text-sm"><span class="font-semibold"><?= (int) $sla['open_breached'] ?> ticket<?= $sla['open_breached'] === 1 ? '' : 's' ?></span> <?= $sla['open_breached'] === 1 ? 'is' : 'are' ?> past an SLA target.</span></a></li>
      <?php endif; ?>
      <?php if ($renewing > 0): ?>
        <li><a href="contracts/contracts.php?state=renewal" class="flex items-start gap-3 px-5 py-3.5 hover:bg-butter-50">
          <?= badge('due_renewal') ?><span class="min-w-0 flex-1 text-sm"><span class="font-semibold"><?= $renewing ?> contract<?= $renewing === 1 ? '' : 's' ?></span> <?= $renewing === 1 ? 'ends or renews' : 'end or renew' ?> soon.</span></a></li>
      <?php endif; ?>
      <?php if ((int) $od['n'] > 0): ?>
        <li><a href="invoices/invoices.php?state=overdue" class="flex items-start gap-3 px-5 py-3.5 hover:bg-butter-50">
          <?= badge('overdue') ?><span class="min-w-0 flex-1 text-sm"><span class="font-semibold"><?= (int) $od['n'] ?> invoices</span> are past due, <span class="notranslate"><?= e(money((int) $od['s'])) ?></span> outstanding.</span></a></li>
      <?php endif; ?>
      <?php if ((int) $conn['flagged'] > 0): ?>
        <li><a href="monitoring/connections.php?status=flagged" class="flex items-start gap-3 px-5 py-3.5 hover:bg-butter-50">
          <?= badge('flagged') ?><span class="min-w-0 flex-1 text-sm"><span class="font-semibold"><?= (int) $conn['flagged'] ?> sessions</span> from unfamiliar addresses are open.</span></a></li>
      <?php endif; ?>
      <?php if ($usersPending > 0): ?>
        <li><a href="users/users.php?status=pending" class="flex items-start gap-3 px-5 py-3.5 hover:bg-butter-50">
          <?= badge('pending') ?><span class="min-w-0 flex-1 text-sm"><span class="font-semibold"><?= $usersPending ?> accounts</span> are waiting for approval.</span></a></li>
      <?php endif; ?>
    </ul>
    <?php endif; ?>
  </section>
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-[1.6fr_1fr]">
  <!-- Activity chart -->
  <section class="rise flex flex-col rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="chart-h">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div><h2 id="chart-h" class="text-lg font-bold tracking-tight">Activity, last 24 hours</h2>
        <p class="text-sm text-soft">Log events per hour, newest on the right</p></div>
      <p class="flex items-center gap-4 text-xs text-soft">
        <span class="inline-flex items-center gap-1.5"><span class="chart-key h-2.5 w-2.5 rounded-sm bg-lime"></span>Info</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-[#E9A23B]"></span>Warning</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-coral"></span>Error</span>
      </p>
    </div>
    <div class="chart mt-5 flex min-h-64 flex-1 items-end gap-1.5 border-b border-line" role="img" aria-label="Bar chart of log events per hour over the last 24 hours, peak <?= $maxH ?> events">
      <?php $i = 0; foreach ($hours as $h => $v): $tot = array_sum($v); $i++; ?>
        <div class="col flex h-full min-w-0 flex-1 flex-col justify-end" title="<?= $h === 0 ? 'This hour' : $h . ' h ago' ?>: <?= $tot ?> events">
          <div class="rounded-t-[3px] bg-coral" style="height:<?= $v['bad'] / $maxH * 100 ?>%"></div>
          <div class="bg-[#E9A23B]" style="height:<?= $v['warn'] / $maxH * 100 ?>%"></div>
          <div class="<?= $v['bad'] + $v['warn'] === 0 ? 'rounded-t-[3px]' : '' ?> bg-lime" style="height:<?= $v['info'] / $maxH * 100 ?>%"></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-2 flex justify-between text-xs text-soft"><span>24 h ago</span><span>12 h ago</span><span>Now</span></div>
  </section>

  <!-- Recent activity -->
  <section class="rise rounded-xl border border-line bg-panel shadow-panel" aria-labelledby="rec-h">
    <div class="flex items-center justify-between px-5 pb-2 pt-5">
      <h2 id="rec-h" class="text-lg font-bold tracking-tight">Recent activity</h2>
      <?php if (module_enabled('logs')): ?><a href="system/logs.php" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4 hover:decoration-white">All logs</a><?php endif; ?>
    </div>
    <ul class="divide-y divide-line">
      <?php foreach ($recent as $r): ?>
        <li class="flex items-start gap-3 px-5 py-3">
          <span class="mt-0.5"><?= badge($r['level']) ?></span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold"><?= e($r['action']) ?><span class="font-normal text-soft"> · <?= e($r['target']) ?></span></p>
            <p class="truncate text-sm text-soft"><?= e($r['actor']) ?> · <?= e(ago($r['created_at'])) ?></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>

<?= trends_section($pdo) ?>

<?php if (is_admin()):
  require_once __DIR__ . '/includes/modules/demo.php';   // loaded only for the demo panel (admins)
  $dc = demo_counts($pdo, 1);
  $rc = demo_counts($pdo, 0);
  $demoRows = array_sum($dc);
  $describe = static function (array $counts): string {
      $p = [];
      foreach (DEMO_TABLES as $t => $name) { if (($counts[$t] ?? 0) > 0) { $p[] = number_format($counts[$t]) . ' ' . strtolower($name); } }
      return implode(', ', $p);
  };
  $realText = $describe($rc);
?>
<section id="demo-data" class="rise mt-6 rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="demo-h">
  <h2 id="demo-h" class="text-lg font-bold tracking-tight">Demo data</h2>
  <?php if ($demoRows === 0 && !demo_loaded()): ?>
    <p class="mt-1 max-w-2xl text-sm text-soft">Fill the dashboard with fake sample data to try it out: about 70 users, sessions, services, invoices and payments, reports, backups and a week of activity. Every sample record is tagged as demo, so it can be removed again in one click. Your own data is never changed.</p>
    <?php if ($realText !== ''): ?><p class="mt-2 text-sm text-soft">Your own data stays as it is: <span class="text-ink"><?= e($realText) ?></span>.</p><?php endif; ?>
    <form method="post" action="system/demo_data.php" class="mt-4"><?= csrf_field() ?><input type="hidden" name="action" value="seed">
      <button class="pressable inline-flex h-11 items-center gap-2 rounded-lg bg-lime px-5 text-sm font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?>Load demo data</button>
    </form>
  <?php else: ?>
    <p class="mt-1 max-w-2xl text-sm text-soft">Demo data is loaded: <span class="text-ink"><?= e($describe($dc)) ?></span>.</p>
    <p class="mt-2 max-w-2xl text-sm text-soft"><?= $realText !== '' ? 'Your own data, which is never removed with it: <span class="text-ink">' . e($realText) . '</span>.' : 'You have not entered any data of your own yet.' ?></p>
    <details class="mt-4 max-w-2xl">
      <summary class="pressable inline-flex h-11 cursor-pointer list-none items-center gap-2 rounded-lg border border-line px-5 text-sm font-bold hover:bg-[#FFD3CC] hover:text-[#7E160A]"><?= icon('x', 'h-4 w-4') ?>Remove demo data</summary>
      <div class="mt-3 rounded-lg border border-line bg-deep p-4">
        <p class="text-sm">This permanently deletes the <strong><?= number_format($demoRows) ?></strong> demo records listed above. Only records tagged as demo are removed.<?= $realText !== '' ? ' Your own records stay.' : '' ?> A demo invoice that has a real payment recorded on it is kept.</p>
        <form method="post" action="system/demo_data.php" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="confirm" value="yes">
          <button class="pressable inline-flex h-10 items-center rounded-lg bg-[#FFD3CC] px-5 text-sm font-bold text-[#7E160A] hover:brightness-95">Yes, remove the demo data</button>
        </form>
      </div>
    </details>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php page_end(); ?>
