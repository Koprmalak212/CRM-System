<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/invoices.php';   // valid_date()

const TEST_RESULTS = [
    'pass' => ['Passed', 'bg-lime text-navy'],
    'fixed' => ['Found and fixed', 'bg-butter text-navy'],
    'open' => ['Open', 'bg-[#FFD3CC] text-[#7E160A]'],
    'info' => ['Note', 'bg-white/10 text-ink'],
];

/** Copies the documented baseline into the database the first time, and again whenever its version is raised. */
function tests_ensure_baseline(PDO $pdo): void
{
    $file = dirname(__DIR__, 2) . '/database/test_log_baseline.php';
    if (!is_file($file)) { return; }
    $base = require $file;
    $s = $pdo->query("SELECT v FROM app_meta WHERE k = 'tests_baseline_version'");
    $have = (int) ($s ? $s->fetchColumn() : 0);
    if ($have >= (int) $base['version']) { return; }
    $ins = $pdo->prepare("INSERT IGNORE INTO test_log (ref, tested_on, area, test, result, details, fix, tester, source) VALUES (?,?,?,?,?,?,?,?, 'baseline')");
    foreach ($base['entries'] as $e) {
        $ins->execute([$e['ref'], $e['date'], $e['area'], $e['test'], $e['result'], $e['details'], $e['fix'], 'Claude Code with Denis Mehmed']);
    }
    $pdo->prepare("INSERT INTO app_meta (k, v) VALUES ('tests_baseline_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string) $base['version']]);
}

/** @return array{0: string, 1: array} WHERE clause and its arguments, from the filters in the query string */
function tests_filter(): array
{
    $where = [];
    $args = [];
    $result = (string) ($_GET['result'] ?? '');
    $area = mb_substr((string) ($_GET['area'] ?? ''), 0, 40);
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
    if (isset(TEST_RESULTS[$result])) { $where[] = 'result = ?'; $args[] = $result; }
    if ($area !== '') { $where[] = 'area = ?'; $args[] = $area; }
    if ($q !== '') { $where[] = '(test LIKE ? OR details LIKE ? OR fix LIKE ? OR ref LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $args];
}

/** CSV export has to send headers before any page output, so it runs before the layout. */
function tab_early(PDO $pdo, array $admin): void
{
    if (($_GET['export'] ?? '') !== 'csv') { return; }
    tests_ensure_baseline($pdo);
    [$w, $args] = tests_filter();
    $s = $pdo->prepare("SELECT ref, tested_on, area, test, result, details, fix, tester FROM test_log $w ORDER BY area, ref, id");
    $s->execute($args);
    log_activity('info', 'Test log exported', 'csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="test-log-' . date('Ymd') . '.csv"');
    $guard = static fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;   // spreadsheet formula injection
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // lets Excel read the accents correctly
    fputcsv($out, ['reference', 'date', 'area', 'test', 'result', 'what was observed', 'fix', 'tested by']);
    foreach ($s as $r) {
        fputcsv($out, array_map($guard, [$r['ref'] ?? '', $r['tested_on'], $r['area'], $r['test'], TEST_RESULTS[$r['result']][0], $r['details'], $r['fix'], $r['tester']]));
    }
    fclose($out);
    exit;
}

function tab_handle(PDO $pdo, array $admin): void
{
    if (!is_admin()) { deny_access('tests:handle'); }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $date = (string) ($_POST['tested_on'] ?? '');
        $area = trim((string) ($_POST['area'] ?? ''));
        $test = trim((string) ($_POST['test'] ?? ''));
        $result = (string) ($_POST['result'] ?? '');
        $details = trim((string) ($_POST['details'] ?? ''));
        $fix = trim((string) ($_POST['fix'] ?? ''));
        $errors = [];
        if (!valid_date($date) || $date > date('Y-m-d') || $date < '2000-01-01') { $errors[] = 'Choose the date the test was done (not in the future).'; }
        if ($area === '' || mb_strlen($area) > 40) { $errors[] = 'Enter an area of up to 40 characters, for example Security.'; }
        if ($test === '' || mb_strlen($test) > 200) { $errors[] = 'Describe the test in up to 200 characters.'; }
        if (!isset(TEST_RESULTS[$result])) { $errors[] = 'Choose a result.'; }
        if ($details === '' || mb_strlen($details) > 2000) { $errors[] = 'Describe what was observed (up to 2000 characters).'; }
        if (mb_strlen($fix) > 2000) { $errors[] = 'The fix can be at most 2000 characters.'; }
        if ((int) $pdo->query('SELECT COUNT(*) FROM test_log')->fetchColumn() >= 2000) { $errors[] = 'The log is full (2000 entries). Export it and remove old entries first.'; }
        if ($errors) { flash(implode(' ', $errors), 'bad'); return; }
        $pdo->prepare("INSERT INTO test_log (ref, tested_on, area, test, result, details, fix, tester, source) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 'manual')")
            ->execute([$date, $area, $test, $result, $details, $fix, mb_substr((string) $admin['name'], 0, 80)]);
        log_activity('info', 'Test log entry added', mb_substr($test, 0, 100));
        flash('Entry added to the test log.');
        return;
    }

    if ($action === 'delete') {
        // Only entries added by hand can be removed; the documented baseline is kept as a record.
        $s = $pdo->prepare("SELECT test FROM test_log WHERE id = ? AND source = 'manual'");
        $s->execute([(int) ($_POST['id'] ?? 0)]);
        if ($t = $s->fetchColumn()) {
            $pdo->prepare("DELETE FROM test_log WHERE id = ? AND source = 'manual'")->execute([(int) $_POST['id']]);
            log_activity('warning', 'Test log entry deleted', mb_substr((string) $t, 0, 100));
            flash('Entry deleted.');
        } else {
            flash('That entry can not be deleted. Baseline entries are kept as the record of the build.', 'bad');
        }
    }
}

function tab_render(PDO $pdo, array $admin): void
{
    tests_ensure_baseline($pdo);
    [$w, $args] = tests_filter();
    $result = isset(TEST_RESULTS[(string) ($_GET['result'] ?? '')]) ? (string) $_GET['result'] : '';
    $area = mb_substr((string) ($_GET['area'] ?? ''), 0, 40);
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
    $page = min(1000, max(1, (int) ($_GET['page'] ?? 1)));
    $per = 25;
    $base = ['tab' => 'tests', 'result' => $result, 'area' => $area, 'q' => $q];

    // Counts per result inside the current area and search (so the chips stay meaningful)
    $cw = []; $ca = [];
    if ($area !== '') { $cw[] = 'area = ?'; $ca[] = $area; }
    if ($q !== '') { $cw[] = '(test LIKE ? OR details LIKE ? OR fix LIKE ? OR ref LIKE ?)'; array_push($ca, "%$q%", "%$q%", "%$q%", "%$q%"); }
    $cs = $pdo->prepare('SELECT result, COUNT(*) FROM test_log ' . ($cw ? 'WHERE ' . implode(' AND ', $cw) : '') . ' GROUP BY result');
    $cs->execute($ca);
    $counts = $cs->fetchAll(PDO::FETCH_KEY_PAIR);

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM test_log $w");
    $cnt->execute($args);
    $total = (int) $cnt->fetchColumn();
    $page = min($page, max(1, (int) ceil($total / $per)));
    $rows = $pdo->prepare("SELECT * FROM test_log $w ORDER BY tested_on DESC, FIELD(result, 'open', 'fixed', 'pass', 'info'), area, ref LIMIT $per OFFSET " . (($page - 1) * $per));
    $rows->execute($args);
    $rows = $rows->fetchAll();
    $areas = $pdo->query('SELECT DISTINCT area FROM test_log ORDER BY area')->fetchAll(PDO::FETCH_COLUMN);

    $field = 'h-11 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    ?>
<section class="rise rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="tests-h">
  <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line p-5 sm:p-6">
    <div>
      <h2 id="tests-h" class="text-lg font-bold tracking-tight">Test log</h2>
      <p class="mt-1 max-w-2xl text-sm text-soft">A record of the tests performed on this dashboard, what each one found, and how it was fixed. It is documentation: nothing here changes how the dashboard behaves.</p>
    </div>
    <a href="<?= e(qs($base, ['export' => 'csv', 'page' => null])) ?>" class="pressable inline-flex h-10 items-center gap-2 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy"><?= icon('download', 'h-4 w-4') ?>Export as CSV</a>
  </div>

  <nav class="flex flex-wrap gap-2 border-b border-line p-4" aria-label="Filter by result">
    <?php $all = array_sum($counts); foreach (['' => ['All', $all]] + array_map(fn($r, $k) => [$r[0], (int) ($counts[$k] ?? 0)], TEST_RESULTS, array_keys(TEST_RESULTS)) as $k => $v):
        $key = is_int($k) ? array_keys(TEST_RESULTS)[$k] : $k; $on = $result === $key; ?>
      <a href="<?= e(qs($base, ['result' => $key, 'page' => 1])) ?>" <?= $on ? 'aria-current="true"' : '' ?>
         class="inline-flex h-9 items-center gap-2 rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-lime text-navy' : 'border border-line hover:bg-butter-50' ?>"><?= e($v[0]) ?><span class="tabular-nums <?= $on ? 'text-navy/70' : 'text-soft' ?>"><?= (int) $v[1] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <form method="get" class="flex flex-wrap items-end gap-3 border-b border-line p-4">
    <input type="hidden" name="tab" value="tests"><input type="hidden" name="result" value="<?= e($result) ?>">
    <div><label for="t-area" class="mb-1 block text-xs font-semibold text-soft">Area</label>
      <select id="t-area" name="area" class="<?= $field ?>"><option value="">All areas</option><?php foreach ($areas as $a): ?><option <?= $area === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
    <div class="min-w-56 flex-1"><label for="t-q" class="mb-1 block text-xs font-semibold text-soft">Search</label>
      <input id="t-q" name="q" type="search" value="<?= e($q) ?>" placeholder="Test, finding, fix or reference" class="<?= $field ?> w-full"></div>
    <button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Apply</button>
    <?php if ($result !== '' || $area !== '' || $q !== ''): ?><a href="settings/settings.php?tab=tests" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
  </form>

  <div class="overflow-x-auto">
    <table class="w-full min-w-[980px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <th scope="col" class="px-4 py-3 font-semibold">Test</th><th scope="col" class="px-4 py-3 font-semibold">Result</th>
        <th scope="col" class="px-4 py-3 font-semibold">What was observed</th><th scope="col" class="px-4 py-3 font-semibold">Fix</th>
        <th scope="col" class="px-4 py-3 font-semibold">Date</th><th scope="col" class="px-4 py-3 text-right font-semibold"><span class="sr-only">Actions</span></th>
      </tr></thead>
      <tbody class="divide-y divide-line align-top">
      <?php foreach ($rows as $r): [$label, $cls] = TEST_RESULTS[$r['result']]; ?>
        <tr class="hover:bg-butter-50">
          <td class="px-4 py-3"><p class="font-semibold"><?= e($r['test']) ?></p><p class="mt-0.5 text-xs text-soft"><span class="notranslate"><?= e($r['ref'] ?? 'manual') ?></span> · <?= e($r['area']) ?></p></td>
          <td class="px-4 py-3"><span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $cls ?>"><?= e($label) ?></span></td>
          <td class="max-w-md px-4 py-3 leading-relaxed text-soft"><?= nl2br(e($r['details'])) ?></td>
          <td class="max-w-sm px-4 py-3 leading-relaxed <?= $r['fix'] === '' ? 'text-soft' : '' ?>"><?= $r['fix'] === '' ? '—' : nl2br(e($r['fix'])) ?></td>
          <td class="whitespace-nowrap px-4 py-3 tabular-nums text-soft"><?= e(date('j M Y', strtotime($r['tested_on']))) ?><p class="mt-0.5 text-xs"><?= e($r['tester']) ?></p></td>
          <td class="px-4 py-3 text-right"><?php if ($r['source'] === 'manual'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="tests"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="pressable inline-flex h-9 items-center rounded-lg border border-line px-3 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]" aria-label="Delete entry: <?= e(mb_substr($r['test'], 0, 60)) ?>">Delete</button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="px-4 py-14 text-center"><p class="text-base font-semibold">No entries match</p><p class="mt-1 text-soft">Try another result, area or search term.</p><a href="settings/settings.php?tab=tests" class="pressable mt-4 inline-flex h-10 items-center rounded-xl bg-lime px-4 text-sm font-bold text-navy">Show all entries</a></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $base) ?>
</section>

<section class="rise mt-6 rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="addtest-h">
  <details>
    <summary class="pressable inline-flex h-11 cursor-pointer list-none items-center gap-2 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?><span id="addtest-h">Add a test result</span></summary>
    <form method="post" class="mt-5 grid gap-4 sm:grid-cols-2" novalidate><?= csrf_field() ?><input type="hidden" name="tab" value="tests"><input type="hidden" name="action" value="add">
      <div><label for="n-date" class="text-sm font-semibold">Date of the test</label><input id="n-date" name="tested_on" type="date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required class="<?= $field ?> mt-1.5 w-full"></div>
      <div><label for="n-area" class="text-sm font-semibold">Area</label><input id="n-area" name="area" list="areas" maxlength="40" required class="<?= $field ?> mt-1.5 w-full"><datalist id="areas"><?php foreach ($areas as $a): ?><option value="<?= e($a) ?>"></option><?php endforeach; ?></datalist></div>
      <div class="sm:col-span-2"><label for="n-test" class="text-sm font-semibold">What was tested</label><input id="n-test" name="test" maxlength="200" required class="<?= $field ?> mt-1.5 w-full"></div>
      <div><label for="n-result" class="text-sm font-semibold">Result</label><select id="n-result" name="result" class="<?= $field ?> mt-1.5 w-full"><?php foreach (TEST_RESULTS as $k => $r): ?><option value="<?= $k ?>"><?= e($r[0]) ?></option><?php endforeach; ?></select></div>
      <div class="sm:col-span-2"><label for="n-details" class="text-sm font-semibold">What was observed</label><textarea id="n-details" name="details" rows="3" maxlength="2000" required class="mt-1.5 w-full rounded-xl border border-line bg-panel px-3 py-2 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"></textarea></div>
      <div class="sm:col-span-2"><label for="n-fix" class="text-sm font-semibold">Fix <span class="font-normal text-soft">(leave empty if nothing was needed)</span></label><textarea id="n-fix" name="fix" rows="2" maxlength="2000" class="mt-1.5 w-full rounded-xl border border-line bg-panel px-3 py-2 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"></textarea></div>
      <div class="sm:col-span-2"><button class="pressable h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Add to the log</button></div>
    </form>
  </details>
</section>
<?php
}
