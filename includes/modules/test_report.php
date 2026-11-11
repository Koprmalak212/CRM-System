<?php
declare(strict_types=1);

/*
 * The automated checks as the second view of Settings > Tests: the report that tests/run.php writes (storage/test_report.json), reading it back
 * safely, and showing it. Kept apart from includes/settings/tests.php so the test suite can load it without that tab's own functions.
 */

/** Where the test runner (tests/run.php) leaves the result of its last run. */
function tests_report_path(): string { return dirname(__DIR__, 2) . '/storage/test_report.json'; }

/**
 * The last run of the automated checks, read from the file the runner wrote (or from $file). Null when there is no usable file. Everything in it is
 * length-limited here, so a damaged or edited file can not break the page.
 * @return null|array{run_at: string, seconds: int, passed: int, failed: int, aborted: string, sections: list<array{title: string, checks: list<array{0: string, 1: bool, 2: string}>}>}
 */
function tests_report_load(?string $file = null): ?array
{
    $file ??= tests_report_path();
    if (!is_file($file) || filesize($file) > 16 * 1024 * 1024) { return null; }
    $j = json_decode((string) file_get_contents($file), true);
    if (!is_array($j) || !is_array($j['sections'] ?? null)) { return null; }
    $passed = 0; $failed = 0; $sections = [];
    foreach ($j['sections'] as $s) {
        if (!is_array($s) || !is_string($s['title'] ?? null) || !is_array($s['checks'] ?? null)) { continue; }
        $checks = [];
        foreach ($s['checks'] as $c) {
            if (!is_array($c) || !is_string($c[0] ?? null)) { continue; }
            $ok = !empty($c[1]);
            $checks[] = [mb_substr($c[0], 0, 300), $ok, is_string($c[2] ?? null) ? mb_substr($c[2], 0, 300) : ''];
            $ok ? $passed++ : $failed++;
        }
        $sections[] = ['title' => mb_substr($s['title'], 0, 200), 'checks' => $checks];
    }
    return ['run_at' => is_string($j['run_at'] ?? null) ? $j['run_at'] : '', 'seconds' => max(0, (int) ($j['seconds'] ?? 0)), 'passed' => $passed, 'failed' => $failed,
        'aborted' => is_string($j['aborted'] ?? null) ? mb_substr($j['aborted'], 0, 300) : '', 'sections' => $sections];
}

/** The two views of this tab as pills: the written log of tests and fixes, and the individual automated checks of the last run. */
function tests_views_nav(string $view, int $logCount, ?array $report): string
{
    $pill = static fn(string $key, string $label, int $n): string => '<a href="settings/settings.php?tab=tests' . ($key === 'checks' ? '&amp;view=checks' : '') . '" ' . ($view === $key ? 'aria-current="page" ' : '')
        . 'class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold ' . ($view === $key ? 'bg-lime text-navy' : 'border border-line bg-panel hover:bg-butter-50') . '">' . e($label)
        . '<span class="tabular-nums ' . ($view === $key ? 'text-navy/70' : 'text-soft') . '">' . number_format($n) . '</span></a>';
    return '<nav class="rise mb-4 flex flex-wrap gap-2" aria-label="Test views">' . $pill('log', 'Documented tests', $logCount) . $pill('checks', 'Automated checks', $report ? $report['passed'] + $report['failed'] : 0) . '</nav>';
}

/** The automated checks of the last run, by section, with a search and a "failed only" filter. */
function tests_render_checks(?array $report): void
{
    $field = 'h-11 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    if ($report === null) {
        ?>
<section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="chk-h">
  <h2 id="chk-h" class="text-lg font-bold tracking-tight">Automated checks</h2>
  <p class="mt-2 max-w-2xl text-sm text-soft">No run has been saved on this computer yet. The checks are run from the command line, and the result of the last run then appears here, every check with what it said:</p>
  <pre class="mt-3 max-w-2xl overflow-x-auto rounded-xl bg-deep p-4 text-sm notranslate">C:\xampp\php\php.exe tests\run.php</pre>
  <p class="mt-3 max-w-2xl text-sm text-soft">It needs Apache and MariaDB running and takes several minutes. See the install guide, section Tests.</p>
</section>
<?php
        return;
    }
    $q = mb_substr(trim((string) ($_GET['cq'] ?? '')), 0, 100);
    $onlyFailed = ($_GET['cf'] ?? '') === 'failed';
    $needle = mb_strtolower($q);
    $total = $report['passed'] + $report['failed'];
    $when = $report['run_at'] !== '' && strtotime($report['run_at']) !== false ? strtotime($report['run_at']) : null;
    $shownChecks = 0; $shownSections = [];
    foreach ($report['sections'] as $s) {
        $titleHit = $needle !== '' && str_contains(mb_strtolower($s['title']), $needle);
        $rows = array_values(array_filter($s['checks'], static fn($c) => (!$onlyFailed || !$c[1]) && ($needle === '' || $titleHit || str_contains(mb_strtolower($c[0]), $needle))));
        if ($rows) { $shownSections[] = [$s['title'], $rows, count(array_filter($s['checks'], static fn($c) => !$c[1])), count($s['checks'])]; $shownChecks += count($rows); }
    }
    $filtered = $q !== '' || $onlyFailed;
    ?>
<section class="rise rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="chk-h">
  <div class="border-b border-line p-5 sm:p-6">
    <h2 id="chk-h" class="text-lg font-bold tracking-tight">Automated checks</h2>
    <p class="mt-1 max-w-2xl text-sm text-soft">Every check of the last run of <code class="notranslate">tests\run.php</code> on this computer, in the order it ran. They test the program from the outside, like a visitor would. Run them again from the command line to refresh this list.</p>
  </div>
  <dl class="grid grid-cols-2 divide-line border-b border-line sm:grid-cols-4 sm:divide-x">
    <div class="p-5"><dt class="text-sm font-medium text-soft">Checks</dt><dd class="mt-1 text-2xl font-bold tabular-nums"><?= number_format($total) ?></dd><dd class="mt-1 text-xs text-soft">in <?= count($report['sections']) ?> sections</dd></div>
    <div class="p-5"><dt class="text-sm font-medium text-soft">Passed</dt><dd class="mt-1 text-2xl font-bold tabular-nums text-lime"><?= number_format($report['passed']) ?></dd></div>
    <div class="p-5"><dt class="text-sm font-medium text-soft">Failed</dt><dd class="mt-1 text-2xl font-bold tabular-nums <?= $report['failed'] > 0 ? 'text-[#FF9C8C]' : '' ?>"><?= number_format($report['failed']) ?></dd></div>
    <div class="p-5"><dt class="text-sm font-medium text-soft">Last run</dt><dd class="mt-1 text-lg font-bold"><?= $when ? e(ago(date('Y-m-d H:i:s', $when))) : 'unknown' ?></dd><dd class="mt-1 text-xs text-soft"><?= $when ? e(date('j M Y, H:i', $when)) : '' ?><?= $report['seconds'] > 0 ? ' · ' . e(duration($report['seconds'])) : '' ?></dd></div>
  </dl>
  <?php if ($report['aborted'] !== ''): ?><p role="alert" class="border-b border-line bg-coral/10 px-5 py-3 text-sm font-semibold text-[#FF9C8C] sm:px-6">The run stopped early: <?= e($report['aborted']) ?>. The checks after that point did not run.</p><?php endif; ?>
  <form method="get" class="flex flex-wrap items-end gap-3 border-b border-line p-4">
    <input type="hidden" name="tab" value="tests"><input type="hidden" name="view" value="checks">
    <div class="min-w-56 flex-1"><label for="c-q" class="mb-1 block text-xs font-semibold text-soft">Search the checks</label>
      <input id="c-q" name="cq" type="search" value="<?= e($q) ?>" maxlength="100" placeholder="For example invoice, password, tickets" class="<?= $field ?> w-full"></div>
    <label for="c-f" class="flex h-11 cursor-pointer items-center gap-2 text-sm font-semibold"><input id="c-f" type="checkbox" name="cf" value="failed" <?= $onlyFailed ? 'checked' : '' ?> class="h-4 w-4 accent-lime">Only failed</label>
    <button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Apply</button>
    <?php if ($filtered): ?><a href="settings/settings.php?tab=tests&amp;view=checks" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
  </form>
  <?php if (!$shownSections): ?>
    <p class="px-5 py-12 text-center text-sm text-soft sm:px-6"><?= $onlyFailed && $q === '' ? 'No check failed in the last run.' : 'No check matches.' ?></p>
  <?php else: ?>
    <?php if ($filtered): ?><p class="border-b border-line px-5 py-3 text-sm text-soft sm:px-6"><span class="font-semibold text-ink tabular-nums"><?= number_format($shownChecks) ?></span> check<?= $shownChecks === 1 ? '' : 's' ?> in <?= count($shownSections) ?> section<?= count($shownSections) === 1 ? '' : 's' ?>.</p><?php endif; ?>
    <div class="divide-y divide-line">
      <?php foreach ($shownSections as [$title, $rows, $failedN, $all]): ?>
        <details <?= $filtered || $failedN > 0 ? 'open' : '' ?>>
          <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm hover:bg-butter-50 sm:px-6"><span class="font-semibold"><?= e($title) ?></span>
            <span class="flex items-center gap-2 text-xs"><span class="text-soft tabular-nums"><?= number_format($all) ?> check<?= $all === 1 ? '' : 's' ?></span><?= $failedN > 0 ? '<span class="inline-flex rounded-full bg-[#FFD3CC] px-2.5 py-0.5 font-semibold text-[#7E160A]">' . $failedN . ' failed</span>' : '<span class="inline-flex rounded-full bg-lime px-2.5 py-0.5 font-semibold text-navy">all passed</span>' ?></span></summary>
          <ul class="divide-y divide-line border-t border-line bg-deep/40">
            <?php foreach ($rows as [$name, $ok, $detail]): ?>
              <li class="flex items-start gap-3 px-5 py-2 text-sm sm:px-6"><span class="mt-0.5 shrink-0 <?= $ok ? 'text-lime' : 'text-[#FF9C8C]' ?>"><?= icon($ok ? 'check' : 'x', 'h-4 w-4') ?><span class="sr-only"><?= $ok ? 'Passed' : 'Failed' ?>:</span></span>
                <span class="min-w-0 <?= $ok ? '' : 'font-semibold' ?>"><?= e($name) ?><?php if (!$ok && $detail !== ''): ?><span class="mt-0.5 block text-xs font-normal text-soft notranslate"><?= e($detail) ?></span><?php endif; ?></span></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php
}
