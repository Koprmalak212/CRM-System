<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/core/layout.php';
require __DIR__ . '/../includes/modules/comm.php';
require_login();
$pdo = db();

[$where, $args, $f] = comm_filters($_GET);
$sorts = ['time' => 'c.started_at', 'duration' => 'c.duration_s', 'status' => 'c.status', 'service' => 'c.provider'];
$sortKey = array_key_exists((string) ($_GET['sort'] ?? ''), $sorts) ? (string) $_GET['sort'] : 'time';
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
$base = $f + ['sort' => $sortKey, 'dir' => $dir];
$base['sort'] = $sortKey === 'time' ? '' : $sortKey;   // the default sort is left out of the links
$base['dir'] = ($sortKey === 'time' && $dir === 'desc') ? '' : $dir;

$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = 25;
$cnt = $pdo->prepare("SELECT COUNT(*) FROM comm_log c $where");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$st = $pdo->prepare("SELECT c.*, u.name AS user_name, co.name AS company_name FROM comm_log c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN companies co ON co.id = c.company_id
    $where ORDER BY {$sorts[$sortKey]} $dir, c.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll();

$calls30 = (int) $pdo->query("SELECT COUNT(*) FROM comm_log WHERE kind = 'call' AND started_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
$missed30 = (int) $pdo->query("SELECT COUNT(*) FROM comm_log WHERE kind = 'call' AND direction = 'in' AND status = 'missed' AND started_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
$sms30 = (int) $pdo->query("SELECT COUNT(*) FROM comm_log WHERE kind = 'sms' AND started_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
$companies = $pdo->query('SELECT DISTINCT co.id, co.name FROM companies co JOIN comm_log c ON c.company_id = co.id ORDER BY co.name LIMIT 500')->fetchAll();
$people = $pdo->query('SELECT DISTINCT u.id, u.name FROM users u JOIN comm_log c ON c.user_id = u.id ORDER BY u.name LIMIT 500')->fetchAll();
$staffList = $pdo->query("SELECT DISTINCT staff_name FROM comm_log WHERE staff_name <> '' ORDER BY staff_name LIMIT 300")->fetchAll(PDO::FETCH_COLUMN);
$connected = (int) $pdo->query("SELECT COUNT(*) FROM connectors WHERE enabled = 1 AND provider IN ('threecx','yeastar','twilio')")->fetchColumn();
$filtered = $f['kind'] . $f['service'] . $f['direction'] . $f['status'] . $f['company'] . $f['person'] . $f['staff'] . $f['from'] . $f['to'] . $f['q'] !== '';

$field = 'mt-1.5 h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
$opt = static fn(array $list, string $cur, string $all) => '<option value="">' . e($all) . '</option>' . implode('', array_map(static fn($k, $v) => '<option value="' . e((string) $k) . '"' . ((string) $k === $cur ? ' selected' : '') . '>' . e((string) $v) . '</option>', array_keys($list), $list));
$coOpts = ['none' => 'Unmatched numbers'] + array_column($companies, 'name', 'id');
$peOpts = array_column($people, 'name', 'id');
if ($f['company'] !== '' && $f['company'] !== 'none' && !isset($coOpts[$f['company']])) { $coOpts[$f['company']] = 'Company #' . $f['company']; }
if ($f['person'] !== '' && !isset($peOpts[$f['person']])) { $peOpts[$f['person']] = 'Person #' . $f['person']; }
$stOpts = $staffList ? array_combine($staffList, $staffList) : [];
if ($f['staff'] !== '' && !isset($stOpts[$f['staff']])) { $stOpts[$f['staff']] = $f['staff']; }

page_start('Calls', 'calls', 'Calls and text messages from your phone systems and Twilio, matched to people and companies by phone number.');
?>
<dl class="rise mb-6 grid grid-cols-3 divide-x divide-line overflow-hidden rounded-2xl border border-line bg-panel shadow-panel">
  <div class="p-4 sm:p-5"><dt class="text-sm font-medium text-soft">Calls, 30 days</dt><dd class="mt-1 text-xl font-bold tabular-nums sm:text-2xl"><?= number_format($calls30) ?></dd></div>
  <div class="p-4 sm:p-5"><dt class="text-sm font-medium text-soft">Missed incoming</dt><dd class="mt-1 text-xl font-bold tabular-nums sm:text-2xl <?= $missed30 ? 'text-[#FF9C8C]' : '' ?>"><?= number_format($missed30) ?></dd></div>
  <div class="p-4 sm:p-5"><dt class="text-sm font-medium text-soft">Messages, 30 days</dt><dd class="mt-1 text-xl font-bold tabular-nums sm:text-2xl"><?= number_format($sms30) ?></dd></div>
</dl>

<form method="get" action="calls/calls.php" class="rise mb-4 grid gap-3 rounded-2xl border border-line bg-panel p-4 shadow-panel sm:grid-cols-2 lg:grid-cols-4 sm:p-5" aria-label="Filter calls and messages">
  <div><label for="f-kind" class="text-sm font-semibold">Type</label><select id="f-kind" name="kind" class="<?= e($field) ?>"><?= $opt(COMM_KINDS, $f['kind'], 'Calls and messages') ?></select></div>
  <div><label for="f-service" class="text-sm font-semibold">Service</label><select id="f-service" name="service" class="<?= e($field) ?>"><?= $opt(COMM_SERVICES, $f['service'], 'All services') ?></select></div>
  <div><label for="f-company" class="text-sm font-semibold">Company</label><select id="f-company" name="company" class="<?= e($field) ?>"><?= $opt($coOpts, $f['company'], 'All companies') ?></select></div>
  <div><label for="f-person" class="text-sm font-semibold">Person</label><select id="f-person" name="person" class="<?= e($field) ?>"><?= $opt($peOpts, $f['person'], 'Everyone') ?></select><?php if (!$peOpts): ?><p class="mt-1 text-xs text-soft">Nobody in the CRM is matched to a call yet. Give people and companies their phone numbers, or use Staff and the number box.</p><?php endif; ?></div>
  <div><label for="f-staff" class="text-sm font-semibold">Staff</label><select id="f-staff" name="staff" class="<?= e($field) ?>"><?= $opt($stOpts, $f['staff'], 'All colleagues') ?></select></div>
  <div><label for="f-direction" class="text-sm font-semibold">Direction</label><select id="f-direction" name="direction" class="<?= e($field) ?>"><?= $opt(COMM_DIRECTIONS, $f['direction'], 'Any direction') ?></select></div>
  <div><label for="f-status" class="text-sm font-semibold">Status</label><select id="f-status" name="status" class="<?= e($field) ?>"><?= $opt(COMM_STATUSES, $f['status'], 'Any status') ?></select></div>
  <div><label for="f-from" class="text-sm font-semibold">From date</label><input id="f-from" name="from" type="date" value="<?= e($f['from']) ?>" class="<?= e($field) ?>"></div>
  <div><label for="f-to" class="text-sm font-semibold">To date</label><input id="f-to" name="to" type="date" value="<?= e($f['to']) ?>" class="<?= e($field) ?>"></div>
  <div class="sm:col-span-2"><label for="f-q" class="text-sm font-semibold">Number or message text</label><input id="f-q" name="q" type="search" maxlength="60" placeholder="+31 6 1234 5678" value="<?= e($f['q']) ?>" class="<?= e($field) ?>"></div>
  <div class="flex items-end gap-3 sm:col-span-2">
    <button class="pressable h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Apply</button>
    <?php if ($filtered): ?><a href="calls/calls.php" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
  </div>
</form>

<div class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.05s">
  <?php if (!$rows): ?>
    <div class="p-6 text-sm">
      <p class="font-semibold"><?= $filtered ? 'Nothing matches these filters.' : 'No calls or messages yet.' ?></p>
      <p class="mt-1 max-w-2xl text-soft"><?php if ($filtered): ?>Change or clear the filters.<?php elseif ($connected === 0): ?>Nothing is connected yet. <?= is_admin() ? '<a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="settings/settings.php?tab=connectors">Connect 3CX, Yeastar or Twilio</a> in Settings.' : 'Ask an admin to connect 3CX, Yeastar or Twilio under Settings, Connectors.' ?><?php else: ?>The connected services have not delivered anything yet. They are synced every few minutes; an admin can press Sync now under Settings, Connectors.<?php endif; ?></p>
    </div>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[980px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <?= th_sort('When', 'time', $sortKey, $dir, $base) ?>
        <?= th_sort('Service', 'service', $sortKey, $dir, $base) ?>
        <th scope="col" class="px-4 py-3 font-semibold">What</th>
        <th scope="col" class="px-4 py-3 font-semibold">From / to</th>
        <th scope="col" class="px-4 py-3 font-semibold">Person</th>
        <th scope="col" class="px-4 py-3 font-semibold">Staff</th>
        <th scope="col" class="px-4 py-3 font-semibold">Company</th>
        <?= th_sort('Length', 'duration', $sortKey, $dir, $base, 'right') ?>
        <?= th_sort('Status', 'status', $sortKey, $dir, $base) ?>
      </tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $r): $out = $r['direction'] === 'out'; ?>
          <tr class="hover:bg-butter-50 <?= $r['status'] === 'missed' && $r['direction'] === 'in' ? 'bg-coral/10' : '' ?>">
            <td class="px-4 py-3 text-soft"><span class="tabular-nums"><?= e(date('j M Y H:i', strtotime((string) $r['started_at']))) ?></span></td>
            <td class="px-4 py-3"><?= service_badge((string) $r['provider']) ?></td>
            <td class="px-4 py-3"><span class="font-semibold"><?= e((COMM_DIRECTIONS[$r['direction']] ?? $r['direction']) . ' ' . ($r['kind'] === 'sms' ? 'message' : 'call')) ?></span>
              <?php if ($r['kind'] === 'sms' && $r['body'] !== ''): ?><p class="mt-0.5 max-w-xs truncate text-xs text-soft"><?= e(mb_substr((string) $r['body'], 0, 120)) ?></p><?php endif; ?></td>
            <td class="px-4 py-3 tabular-nums text-soft"><?= e((string) $r['from_number']) ?> <span aria-hidden="true">→</span><span class="sr-only">to</span> <?= e((string) $r['to_number']) ?></td>
            <td class="px-4 py-3"><?= $r['user_id'] ? '<a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="users/user.php?id=' . (int) $r['user_id'] . '">' . e((string) $r['user_name']) . '</a>' : ($r['remote_name'] !== '' ? '<span title="The name the phone system has for this number">' . e((string) $r['remote_name']) . '</span> <span class="text-xs text-soft">(phone system)</span>' : '<span class="text-soft">—</span>') ?></td>
            <td class="px-4 py-3"><?= $r['staff_name'] !== '' ? e((string) $r['staff_name']) : '<span class="text-soft">—</span>' ?></td>
            <td class="px-4 py-3"><?= $r['company_id'] ? '<a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="companies/company.php?id=' . (int) $r['company_id'] . '">' . e((string) $r['company_name']) . '</a>' : '<span class="text-soft">Unmatched</span>' ?></td>
            <td class="px-4 py-3 text-right tabular-nums"><?= $r['kind'] === 'call' && (int) $r['duration_s'] > 0 ? e(duration((int) $r['duration_s'])) : '—' ?></td>
            <td class="px-4 py-3"><?= badge((string) $r['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $base) ?>
  <?php endif; ?>
</div>
<?php page_end(); ?>
