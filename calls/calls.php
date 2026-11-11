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
$focus = null;   // when the list is about one name: how many calls, answered, missed, talking time, first and last
if ($f['who'] !== '' || $f['person'] !== '' || $f['staff'] !== '') {
    $ss = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(c.status = 'answered'), 0) answered, COALESCE(SUM(c.status = 'missed'), 0) missed, COALESCE(SUM(c.duration_s), 0) talk, MIN(c.started_at) first_at, MAX(c.started_at) last_at FROM comm_log c $where");
    $ss->execute($args);
    $focus = $ss->fetch();
}

$calls30 = (int) $pdo->query("SELECT COUNT(*) FROM comm_log WHERE kind = 'call' AND started_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
$missed30 = (int) $pdo->query("SELECT COUNT(*) FROM comm_log WHERE kind = 'call' AND direction = 'in' AND status = 'missed' AND started_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
$sms30 = (int) $pdo->query("SELECT COUNT(*) FROM comm_log WHERE kind = 'sms' AND started_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
$companies = $pdo->query('SELECT DISTINCT co.id, co.name FROM companies co JOIN comm_log c ON c.company_id = co.id ORDER BY co.name LIMIT 500')->fetchAll();
// every name there is to pick from: people in the CRM who have calls, and the callers and colleagues the phone system names (UNION leaves out doubles)
$names = $pdo->query("SELECT name FROM (SELECT u.name AS name FROM users u JOIN comm_log c ON c.user_id = u.id UNION SELECT remote_name FROM comm_log WHERE remote_name <> '' UNION SELECT staff_name FROM comm_log WHERE staff_name <> '') t WHERE name <> '' ORDER BY name LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN);
$connected = (int) $pdo->query("SELECT COUNT(*) FROM connectors WHERE enabled = 1 AND provider IN ('threecx','yeastar','twilio')")->fetchColumn();
$filtered = $f['kind'] . $f['service'] . $f['direction'] . $f['status'] . $f['company'] . $f['person'] . $f['staff'] . $f['who'] . $f['exact'] . $f['from'] . $f['to'] . $f['q'] !== '';

$field = 'mt-1.5 h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
$opt = static fn(array $list, string $cur, string $all) => '<option value="">' . e($all) . '</option>' . implode('', array_map(static fn($k, $v) => '<option value="' . e((string) $k) . '"' . ((string) $k === $cur ? ' selected' : '') . '>' . e((string) $v) . '</option>', array_keys($list), $list));
$coOpts = ['none' => 'Unmatched numbers'] + array_column($companies, 'name', 'id');

if ($f['company'] !== '' && $f['company'] !== 'none' && !isset($coOpts[$f['company']])) { $coOpts[$f['company']] = 'Company #' . $f['company']; }
$whoShown = $f['who'] !== '' ? $f['who'] : $f['staff'];   // what the box shows: the name typed, or the colleague of a link
if ($whoShown === '' && $f['person'] !== '') { $pn = $pdo->prepare('SELECT name FROM users WHERE id = ?'); $pn->execute([(int) $f['person']]); $whoShown = (string) ($pn->fetchColumn() ?: ''); }   // a link from a person's page

page_start('Calls', 'calls', 'Calls and text messages from your phone systems and Twilio, matched to people and companies by phone number.');
?>
<dl class="rise mb-6 grid grid-cols-3 divide-x divide-line overflow-hidden rounded-2xl border border-line bg-panel shadow-panel">
  <div class="p-4 sm:p-5"><dt class="text-sm font-medium text-soft">Calls, 30 days</dt><dd class="mt-1 text-xl font-bold tabular-nums sm:text-2xl"><?= number_format($calls30) ?></dd></div>
  <div class="p-4 sm:p-5"><dt class="text-sm font-medium text-soft">Missed incoming</dt><dd class="mt-1 text-xl font-bold tabular-nums sm:text-2xl <?= $missed30 ? 'text-[#FF9C8C]' : '' ?>"><?= number_format($missed30) ?></dd></div>
  <div class="p-4 sm:p-5"><dt class="text-sm font-medium text-soft">Messages, 30 days</dt><dd class="mt-1 text-xl font-bold tabular-nums sm:text-2xl"><?= number_format($sms30) ?></dd></div>
</dl>

<form method="get" action="calls/calls.php" class="rise relative z-20 mb-4 grid gap-3 rounded-2xl border border-line bg-panel p-4 shadow-panel sm:grid-cols-2 lg:grid-cols-4 sm:p-5" aria-label="Filter calls and messages">
  <div><label for="f-kind" class="text-sm font-semibold">Type</label><select id="f-kind" name="kind" class="<?= e($field) ?>"><?= $opt(COMM_KINDS, $f['kind'], 'Calls and messages') ?></select></div>
  <div><label for="f-service" class="text-sm font-semibold">Service</label><select id="f-service" name="service" class="<?= e($field) ?>"><?= $opt(COMM_SERVICES, $f['service'], 'All services') ?></select></div>
  <div><label for="f-company" class="text-sm font-semibold">Company</label><select id="f-company" name="company" class="<?= e($field) ?>"><?= $opt($coOpts, $f['company'], 'All companies') ?></select></div>
  <div class="relative"><label for="f-who" class="text-sm font-semibold">Person</label>
    <div class="relative mt-1.5">
      <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-soft"><?= icon('search', 'h-4 w-4') ?></span>
      <input id="f-who" name="who" type="text" role="combobox" aria-expanded="false" aria-controls="f-who-list" aria-autocomplete="list" maxlength="120" autocomplete="off" spellcheck="false"
             placeholder="Search <?= count($names) ?> names" value="<?= e($whoShown) ?>" class="h-11 w-full rounded-xl border border-line bg-panel pl-9 pr-9 text-sm placeholder:text-soft/80 focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime">
      <button type="button" id="f-who-clear" class="absolute right-1.5 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-soft hover:bg-ink/10 hover:text-ink" aria-label="Clear the name" <?= $whoShown === '' ? 'hidden' : '' ?>><?= icon('x', 'h-4 w-4') ?></button>
    </div>
    <ul id="f-who-list" role="listbox" aria-label="Names" hidden class="absolute left-0 right-0 z-30 mt-1 max-h-72 overflow-y-auto rounded-xl border border-line bg-panel py-1 shadow-[0_14px_30px_-10px_rgba(0,0,0,.8)]"></ul>
    <script type="application/json" id="f-who-data"><?= json_encode(array_values($names), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
  </div>
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
<script nonce="<?= csp_nonce() ?>">
// The Person box: type to narrow the names, arrow keys and Enter to pick one, Escape to close. Without JavaScript it is a plain text box.
(function () {
  var box = document.getElementById('f-who'), list = document.getElementById('f-who-list'), clear = document.getElementById('f-who-clear');
  if (!box || !list) return;
  var names = [];
  try { names = JSON.parse(document.getElementById('f-who-data').textContent) || []; } catch (e) {}
  var fold = function (s) { return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
  var shown = [], active = -1, MAX = 50, cls = 'cursor-pointer px-3 py-2 text-sm';
  function open(on) { list.hidden = !on; box.setAttribute('aria-expanded', on ? 'true' : 'false'); if (!on) { box.removeAttribute('aria-activedescendant'); active = -1; } }
  function mark(i) {
    var items = list.querySelectorAll('[role=option]');
    items.forEach(function (li, k) { var on = k === i; li.classList.toggle('bg-butter-50', on); li.classList.toggle('font-semibold', on); li.setAttribute('aria-selected', on ? 'true' : 'false'); });
    active = i;
    if (i >= 0 && items[i]) { box.setAttribute('aria-activedescendant', items[i].id); items[i].scrollIntoView({ block: 'nearest' }); } else { box.removeAttribute('aria-activedescendant'); }
  }
  function note(text) { var li = document.createElement('li'); li.setAttribute('role', 'presentation'); li.className = 'px-3 py-2 text-xs text-soft'; li.textContent = text; list.appendChild(li); }
  function pick(i) { box.value = shown[i]; open(false); clear.hidden = false; }
  function render() {
    var q = fold(box.value.trim()), hits = names.filter(function (n) { return q === '' || fold(n).indexOf(q) !== -1; });
    shown = hits.slice(0, MAX);
    list.innerHTML = '';
    shown.forEach(function (n, i) {
      var li = document.createElement('li');
      li.id = 'f-who-' + i; li.setAttribute('role', 'option'); li.setAttribute('aria-selected', 'false'); li.className = cls + ' hover:bg-butter-50'; li.textContent = n;
      li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });   // mousedown, so the box keeps focus and does not close first
      list.appendChild(li);
    });
    if (hits.length > MAX) note((hits.length - MAX) + ' more: keep typing to narrow it down');
    if (!shown.length) note('No name matches. Apply searches for what you typed.');
    active = -1; open(true);
  }
  box.addEventListener('focus', render);
  box.addEventListener('input', function () { clear.hidden = box.value === ''; render(); });
  box.addEventListener('blur', function () { open(false); });
  box.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (list.hidden) render();
      if (!shown.length) return;
      mark(e.key === 'ArrowDown' ? (active + 1) % shown.length : (active <= 0 ? shown.length - 1 : active - 1));
    } else if (e.key === 'Enter' && !list.hidden && active >= 0) { e.preventDefault(); pick(active); }
    else if (e.key === 'Escape' && !list.hidden) { e.preventDefault(); open(false); }
  });
  clear.addEventListener('click', function () { box.value = ''; clear.hidden = true; box.focus(); render(); });
})();
</script>

<?php if ($focus !== null && $whoShown !== ''): ?>
<section class="rise mb-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-3 rounded-2xl border border-line bg-panel px-5 py-4 shadow-panel" aria-labelledby="focus-h">
  <div class="min-w-0">
    <h2 id="focus-h" class="truncate text-lg font-bold tracking-tight"><?= e($whoShown) ?></h2>
    <p class="mt-0.5 text-sm text-soft"><?php if ((int) $focus['n'] === 0): ?>No calls or messages found for this name.<?php else: ?>
      <span class="font-semibold text-ink tabular-nums"><?= number_format((int) $focus['n']) ?></span> call<?= (int) $focus['n'] === 1 ? '' : 's' ?> and message<?= (int) $focus['n'] === 1 ? '' : 's' ?>
      · <span class="tabular-nums"><?= (int) $focus['answered'] ?></span> answered · <span class="tabular-nums <?= (int) $focus['missed'] > 0 ? 'text-[#FF9C8C]' : '' ?>"><?= (int) $focus['missed'] ?></span> missed
      · <?= (int) $focus['talk'] > 0 ? e(duration((int) $focus['talk'])) . ' talking' : 'no talking time' ?>
      · <?= e(date('j M Y', strtotime((string) $focus['first_at']))) ?><?= substr((string) $focus['first_at'], 0, 10) !== substr((string) $focus['last_at'], 0, 10) ? ' to ' . e(date('j M Y', strtotime((string) $focus['last_at']))) : '' ?><?php endif; ?></p>
  </div>
  <div class="flex flex-wrap gap-2">
    <?php if ($f['person'] !== ''): ?><a href="users/user.php?id=<?= (int) $f['person'] ?>" class="pressable inline-flex h-10 items-center rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy">Open profile</a><?php endif; ?>
    <a href="calls/calls.php" class="pressable inline-flex h-10 items-center rounded-xl border border-line px-4 text-sm font-semibold hover:bg-butter-50">All calls</a>
  </div>
</section>
<?php endif; ?>
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
            <td class="px-4 py-3"><?= $r['user_id'] ? comm_name_link((string) $r['user_name'], 'calls/calls.php?person=' . (int) $r['user_id']) : ($r['remote_name'] !== '' ? comm_name_link((string) $r['remote_name'], comm_name_url((string) $r['remote_name'])) . ' <span class="text-xs text-soft">(phone system)</span>' : '<span class="text-soft">—</span>') ?></td>
            <td class="px-4 py-3"><?= $r['staff_name'] !== '' ? comm_name_link((string) $r['staff_name'], comm_name_url((string) $r['staff_name'])) : '<span class="text-soft">—</span>' ?></td>
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
