<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/kuma.php';

function tab_handle(PDO $pdo, array $admin): void
{
    if (!is_admin()) { deny_access('kuma:handle'); }   // second check: never rely only on the tab being hidden
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'remove') {
        kuma_remove_key($pdo, (int) $admin['id']);
        log_activity('warning', 'Kuma API key removed', '');
        flash('The API key was removed. Uptime monitoring is paused until you save a new key.');
        return;
    }

    $url = (string) ($_POST['kumaUrl'] ?? '');
    $poll = (string) ($_POST['pollIntervalSeconds'] ?? '');
    [$ok, $errors] = kuma_save($pdo, $url, (string) ($_POST['apiKey'] ?? ''), $poll, (int) $admin['id']);
    if ($ok) {
        log_activity('warning', 'Kuma settings saved', 'url and interval');   // never the key
        flash('Kuma settings saved.');
        return;
    }
    // Keep what was typed, except the key: it is never sent back to the browser, so it has to be typed again.
    $_SESSION['kuma_form'] = ['errors' => $errors, 'url' => mb_substr($url, 0, 300), 'poll' => mb_substr($poll, 0, 10)];
    flash('The settings were not saved. Fix the fields marked below.', 'bad');
}

function tab_render(PDO $pdo, array $admin): void
{
    $s = kuma_settings($pdo);
    $form = $_SESSION['kuma_form'] ?? null;
    unset($_SESSION['kuma_form']);
    $errors = $form['errors'] ?? [];
    $url = $form['url'] ?? $s['url'];
    $poll = $form['poll'] ?? (string) $s['poll'];
    $mask = $s['has_key'] ? '••••••••' . ($s['last4'] ?? '') : '';
    $field = 'mt-1.5 h-11 w-full rounded-xl border bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $err = static fn(string $k) => isset($errors[$k]) ? '<p id="err-' . e($k) . '" class="mt-1.5 text-sm font-semibold text-[#FF9C8C]">' . e($errors[$k]) . '</p>' : '';
    $border = static fn(string $k) => isset($errors[$k]) ? 'border-[#FF9C8C]' : 'border-line';
    ?>
<section class="rise rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="kuma-h">
  <div class="border-b border-line p-5 sm:p-6">
    <h2 id="kuma-h" class="text-lg font-bold tracking-tight">Uptime Kuma connection</h2>
    <p class="mt-1 max-w-2xl text-sm text-soft">The Uptime page reads your Kuma monitors from <code class="rounded bg-white/10 px-1.5 py-0.5 text-ink notranslate">/metrics</code>. In Kuma, create an API key under <strong class="text-ink">Settings &rarr; API Keys</strong> and paste it here. The key is encrypted on the server and is never shown again.</p>
  </div>

  <form method="post" class="grid gap-5 p-5 sm:p-6" novalidate id="kumaForm"><?= csrf_field() ?><input type="hidden" name="tab" value="kuma"><input type="hidden" name="action" value="save">
    <div class="max-w-xl">
      <label for="kumaUrl" class="text-sm font-semibold">Kuma URL</label>
      <input id="kumaUrl" name="kumaUrl" type="url" inputmode="url" maxlength="300" value="<?= e($url) ?>" placeholder="http://kuma.internal:3001" autocomplete="off" required
             aria-describedby="err-kumaUrl" <?= isset($errors['kumaUrl']) ? 'aria-invalid="true"' : '' ?> class="<?= $field ?> <?= $border('kumaUrl') ?> notranslate">
      <?= $err('kumaUrl') ?>
    </div>
    <div class="max-w-xl">
      <label for="apiKey" class="text-sm font-semibold">API key</label>
      <input id="apiKey" name="apiKey" type="password" maxlength="500" autocomplete="new-password" spellcheck="false" value=""
             placeholder="<?= $s['has_key'] ? e($mask) : 'Paste the API key' ?>" aria-describedby="keyHelp err-apiKey" <?= isset($errors['apiKey']) ? 'aria-invalid="true"' : '' ?>
             class="<?= $field ?> <?= $border('apiKey') ?> notranslate">
      <p id="keyHelp" class="mt-1.5 text-xs text-soft"><?= $s['has_key'] ? 'A key is saved. Leave this empty to keep it, or type a new one to replace it.' : 'No key is saved yet.' ?></p>
      <?= $err('apiKey') ?>
    </div>
    <div class="max-w-xs">
      <label for="pollIntervalSeconds" class="text-sm font-semibold">Poll interval (seconds)</label>
      <input id="pollIntervalSeconds" name="pollIntervalSeconds" inputmode="numeric" value="<?= e($poll) ?>" required aria-describedby="pollHelp err-pollIntervalSeconds"
             <?= isset($errors['pollIntervalSeconds']) ? 'aria-invalid="true"' : '' ?> class="<?= $field ?> <?= $border('pollIntervalSeconds') ?> tabular-nums">
      <p id="pollHelp" class="mt-1.5 text-xs text-soft">From <?= KUMA_POLL_MIN ?> to <?= KUMA_POLL_MAX ?>. Kuma is contacted at most this often, however many people have the dashboard open.</p>
      <?= $err('pollIntervalSeconds') ?>
    </div>

    <div id="testResult" role="status" aria-live="polite" class="max-w-xl text-sm font-semibold" hidden></div>

    <div class="flex flex-wrap items-center gap-3">
      <button class="pressable h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Save</button>
      <button type="button" id="kumaTest" class="pressable h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-white/5">Test connection</button>
    </div>
  </form>

  <?php if ($s['has_key']): ?>
  <div class="border-t border-line p-5 sm:p-6">
    <form method="post" class="flex flex-wrap items-center gap-4"><?= csrf_field() ?><input type="hidden" name="tab" value="kuma"><input type="hidden" name="action" value="remove">
      <button class="pressable h-10 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Remove key</button>
      <p class="text-sm text-soft">Deletes the saved key. The Uptime page stops until a new key is saved.</p>
    </form>
  </div>
  <?php endif; ?>
</section>

<script nonce="<?= csp_nonce() ?>">
(function () {
  var btn = document.getElementById('kumaTest'), out = document.getElementById('testResult');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var f = document.getElementById('kumaForm');
    var body = new URLSearchParams({
      action: 'test', csrf: f.csrf.value,
      kumaUrl: f.kumaUrl.value, apiKey: f.apiKey.value
    });
    btn.disabled = true; btn.textContent = 'Testing…';
    out.hidden = false; out.className = 'max-w-xl text-sm font-semibold text-soft'; out.textContent = 'Contacting Kuma…';
    fetch('api/kuma_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': f.csrf.value }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        out.textContent = j.message || 'The test did not return a result.';
        out.className = 'max-w-xl rounded-xl px-4 py-3 text-sm font-semibold ' + (j.ok ? 'bg-lime text-navy' : 'bg-[#FFD3CC] text-[#7E160A]');
      })
      .catch(function () {
        out.textContent = 'The test could not be completed. Reload the page and try again.';
        out.className = 'max-w-xl rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-semibold text-[#7E160A]';
      })
      .finally(function () { btn.disabled = false; btn.textContent = 'Test connection'; });
  });
})();
</script>
<?php
}
