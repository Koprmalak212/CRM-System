<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/appointments.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();

$month = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month < '2000-01' || $month > '2100-12') { $month = date('Y-m'); }
$company = max(0, (int) ($_GET['company'] ?? 0));
$first = new DateTimeImmutable($month . '-01');
$prev = $first->modify('-1 month')->format('Y-m'); $next = $first->modify('+1 month')->format('Y-m');
$weeks = calendar_weeks($month);
$gridFrom = $weeks[0][0]; $gridTo = date('Y-m-d', strtotime(end($weeks)[6] . ' +1 day'));
$rows = appointments_between($pdo, $gridFrom . ' 00:00:00', $gridTo . ' 00:00:00', $company);
$byDay = [];
foreach ($rows as $a) {   // an appointment that spans days appears on each of them
    for ($d = new DateTimeImmutable(date('Y-m-d', strtotime($a['starts_at']))); $d->format('Y-m-d') <= date('Y-m-d', strtotime($a['ends_at'])); $d = $d->modify('+1 day')) { $byDay[$d->format('Y-m-d')][] = $a; }
}
$companies = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$base = ['company' => $company > 0 ? $company : ''];
$today = date('Y-m-d');
$monthRows = array_filter($rows, fn($a) => date('Y-m', strtotime($a['ends_at'])) >= $month && date('Y-m', strtotime($a['starts_at'])) <= $month);

page_start('Calendar', 'calendar', 'Appointments with your companies and their people.');
$field = 'h-11 rounded-lg border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
$tone = ['scheduled' => 'bg-lime text-navy', 'done' => 'bg-ink/10 text-ink', 'cancelled' => 'bg-ink/10 text-soft line-through'];
?>
<div class="rise mb-4 flex flex-wrap items-center gap-3">
  <nav class="flex items-center gap-2" aria-label="Month">
    <a href="<?= e(qs($base, ['month' => $prev])) ?>" class="grid h-11 w-11 place-items-center rounded-lg border border-line hover:bg-butter-50" aria-label="Previous month"><?= icon('left', 'h-4 w-4') ?></a>
    <h2 class="min-w-[9rem] text-center text-lg font-bold tracking-tight"><?= e($first->format('F Y')) ?></h2>
    <a href="<?= e(qs($base, ['month' => $next])) ?>" class="grid h-11 w-11 place-items-center rounded-lg border border-line hover:bg-butter-50" aria-label="Next month"><?= icon('right', 'h-4 w-4') ?></a>
    <a href="<?= e(qs($base, [])) ?>" class="inline-flex h-11 items-center rounded-lg border border-line px-4 text-sm font-semibold hover:bg-butter-50">Today</a>
  </nav>
  <form method="get" data-auto class="flex flex-wrap items-center gap-2"><input type="hidden" name="month" value="<?= e($month) ?>">
    <label class="sr-only" for="company">Company</label>
    <select id="company" name="company" class="<?= $field ?> max-w-[15rem]"><option value="">All companies</option><?php foreach ($companies as $cid => $cn): ?><option value="<?= (int) $cid ?>" <?= $company === (int) $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select>
    <button class="h-11 rounded-lg border border-line bg-panel px-5 text-sm font-semibold hover:bg-butter-50">Show</button>
  </form>
  <div class="ml-auto flex flex-wrap gap-2">
    <?php if ($company > 0): ?><a href="calendar/calendar_ics.php?company=<?= $company ?>" class="pressable inline-flex h-11 items-center gap-2 rounded-lg border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy"><?= icon('download', 'h-4 w-4') ?>Add to my calendar (.ics)</a><?php endif; ?>
    <a href="calendar/appointment_edit.php<?= $company > 0 ? '?company=' . $company : '' ?>" class="pressable inline-flex h-11 items-center gap-2 rounded-lg bg-lime px-5 text-sm font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?>New appointment</a>
  </div>
</div>

<section class="rise hidden overflow-hidden rounded-xl border border-line bg-panel shadow-panel sm:block" aria-label="Month grid" style="animation-delay:.05s">
  <div class="grid grid-cols-7 border-b border-line bg-butter-50 text-center text-xs font-semibold text-soft" aria-hidden="true"><?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dn): ?><div class="py-2"><?= $dn ?></div><?php endforeach; ?></div>
  <?php foreach ($weeks as $w): ?>
    <div class="grid grid-cols-7 divide-x divide-line border-b border-line last:border-b-0">
      <?php foreach ($w as $day): $inMonth = substr($day, 0, 7) === $month; $list = $byDay[$day] ?? []; ?>
        <div class="min-h-[6.5rem] p-1.5 <?= $inMonth ? '' : 'bg-ink/[.03]' ?>">
          <div class="flex items-center justify-between"><a href="calendar/appointment_edit.php?date=<?= e($day) ?><?= $company > 0 ? '&amp;company=' . $company : '' ?>" class="grid h-6 min-w-6 place-items-center rounded-full px-1 text-xs font-semibold tabular-nums <?= $day === $today ? 'bg-lime text-navy' : ($inMonth ? 'text-ink' : 'text-soft') ?> hover:underline" aria-label="New appointment on <?= e(date('j F', strtotime($day))) ?>"><?= (int) substr($day, 8, 2) ?></a></div>
          <ul class="mt-1 space-y-1">
            <?php foreach (array_slice($list, 0, 3) as $a): ?>
              <li><a href="calendar/appointment_edit.php?id=<?= (int) $a['id'] ?>" class="block truncate rounded px-1.5 py-0.5 text-xs font-semibold <?= $tone[$a['status']] ?>" title="<?= e($a['company_name'] . ': ' . $a['title']) ?>"><span class="tabular-nums"><?= e(date('H:i', strtotime($a['starts_at']))) ?></span> <?= e($a['title']) ?></a></li>
            <?php endforeach; ?>
            <?php if (count($list) > 3): ?><li><a href="<?= self_url() ?>#d-<?= e($day) ?>" class="px-1.5 text-xs font-semibold text-soft underline decoration-lime underline-offset-2">+<?= count($list) - 3 ?> more</a></li><?php endif; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</section>

<section class="rise mt-6 rounded-xl border border-line bg-panel shadow-panel" aria-labelledby="ag-h" style="animation-delay:.1s">
  <div class="flex items-center justify-between gap-3 p-5 pb-2"><h2 id="ag-h" class="text-base font-bold">Agenda for <?= e($first->format('F Y')) ?></h2><span class="text-xs text-soft"><?= count($monthRows) ?> appointment<?= count($monthRows) === 1 ? '' : 's' ?></span></div>
  <?php if ($monthRows): $lastDay = ''; ?>
    <ul class="divide-y divide-line">
      <?php foreach ($monthRows as $a): $dd = date('Y-m-d', strtotime($a['starts_at'])); ?>
        <li class="flex flex-wrap items-start gap-x-4 gap-y-1 px-5 py-3" <?= $dd !== $lastDay ? 'id="d-' . e($dd) . '"' : '' ?>>
          <p class="w-28 shrink-0 text-sm font-semibold tabular-nums <?= $dd === $today ? 'text-lime' : '' ?>"><?= $dd !== $lastDay ? e(date('D j M', strtotime($a['starts_at']))) : '' ?></p>
          <div class="min-w-0 flex-1">
            <a href="calendar/appointment_edit.php?id=<?= (int) $a['id'] ?>" class="font-semibold underline-offset-4 hover:underline <?= $a['status'] === 'cancelled' ? 'line-through text-soft' : '' ?>"><?= e($a['title']) ?></a>
            <p class="text-sm text-soft"><span class="notranslate"><?= e(appt_time_range($a)) ?></span> · <a class="underline-offset-4 hover:underline" href="companies/company.php?id=<?= (int) $a['company_id'] ?>"><?= e($a['company_name']) ?></a><?= $a['user_name'] !== null ? ' · <a class="underline-offset-4 hover:underline" href="users/user.php?id=' . (int) $a['user_id'] . '">' . e($a['user_name']) . '</a>' : '' ?><?= $a['location'] !== '' ? ' · ' . e($a['location']) : '' ?></p>
          </div>
          <?= badge($a['status']) ?>
        </li>
        <?php $lastDay = $dd; ?>
      <?php endforeach; ?>
    </ul>
  <?php else: ?><p class="px-5 pb-6 pt-2 text-sm text-soft">No appointments this month<?= $company > 0 ? ' for this company' : '' ?>. Use New appointment, or click a day in the grid.</p><?php endif; ?>
</section>
<?php page_end(); ?>
