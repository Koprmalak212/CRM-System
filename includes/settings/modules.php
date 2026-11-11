<?php
declare(strict_types=1);

/** Settings > Modules: an admin chooses which features this install uses. See includes/core/modules.php. */
function tab_handle(PDO $pdo, array $admin): void
{
    if (!is_admin()) { deny_access('modules:handle'); }
    $want = array_map('strval', (array) ($_POST['modules'] ?? []));
    $changed = [];
    foreach (MODULES as $key => $m) {
        $on = in_array($key, $want, true);
        if ($on !== module_enabled($key)) { module_set($pdo, $key, $on); $changed[] = $m['label'] . ' ' . ($on ? 'on' : 'off'); }
    }
    if (!$changed) { flash('Nothing changed.'); return; }
    log_activity('warning', 'Modules changed', mb_substr(implode(', ', $changed), 0, 120));
    flash('Modules saved: ' . implode(', ', $changed) . '.');
}

function tab_render(PDO $pdo, array $admin): void
{
    ?>
<section class="rise max-w-3xl rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="mod-h">
  <h2 id="mod-h" class="text-lg font-bold tracking-tight">Modules</h2>
  <p class="mt-1 text-sm text-soft">Choose the parts this install uses. A module that is off disappears from the menu, the pages, search and notifications. <strong class="text-ink">Its data is kept</strong>: turn it on again and everything is back. Users, companies and settings are always on.</p>
  <form method="post" class="mt-5" novalidate><?= csrf_field() ?><input type="hidden" name="tab" value="modules">
    <ul class="divide-y divide-line rounded-xl border border-line">
      <?php foreach (MODULES as $key => $m): ?>
      <li>
        <label for="mod-<?= e($key) ?>" class="flex cursor-pointer items-start gap-3 px-4 py-3 hover:bg-butter-50">
          <input id="mod-<?= e($key) ?>" type="checkbox" name="modules[]" value="<?= e($key) ?>" <?= module_enabled($key) ? 'checked' : '' ?> class="mt-1 h-4 w-4 shrink-0 accent-lime">
          <span class="min-w-0"><span class="block text-sm font-semibold"><?= e($m['label']) ?></span><span class="block text-sm text-soft"><?= e($m['desc']) ?></span></span>
        </label>
      </li>
      <?php endforeach; ?>
    </ul>
    <button class="pressable mt-5 h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Save</button>
  </form>
</section>
<?php
}
