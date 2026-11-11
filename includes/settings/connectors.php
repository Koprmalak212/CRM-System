<?php
declare(strict_types=1);

// Settings, Connectors: 3CX, Yeastar, Twilio and Short.io. Admins only (checked in settings.php and again here).
require_once dirname(__DIR__) . '/modules/connectors.php';
require_once dirname(__DIR__) . '/modules/comm.php';   // service_badge()

function tab_handle(PDO $pdo, array $admin): void
{
    if (!is_admin()) { deny_access('connectors:handle'); }
    $defs = connector_defs();
    $p = is_string($_POST['provider'] ?? null) ? $_POST['provider'] : '';
    if (!isset($defs[$p])) { flash('Unknown service.', 'bad'); return; }
    require_module(connector_module($p));   // the module of this service is off: no save, test or sync
    $d = $defs[$p];
    $GLOBALS['tab_redirect'] = 'settings/settings.php?tab=connectors#c-' . $p;
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'save';

    if ($action === 'save') {
        [$ok, $msg] = connector_save($pdo, $p, $_POST, (string) $admin['username']);
        flash($msg, $ok ? 'ok' : 'bad');
        return;
    }
    if ($action === 'remove') {
        connector_remove($pdo, $p);
        flash($d['name'] . ': the saved credentials were removed and the service is off.');
        return;
    }
    [$allowed] = rate_hit('connector', 10, 60);   // outbound requests have their own limit
    if (!$allowed) { flash('Too many tests or syncs. Wait a minute.', 'bad'); return; }

    if ($action === 'test') {
        [$ok, $msg, $v] = connector_prepare($p, $_POST);
        if (!$ok) { flash($msg, 'bad'); return; }
        $old = connector_get($pdo, $p);
        $secret = connector_effective_secret($old, $v);
        if ($secret === '') { flash('Enter the ' . $d['secret_label'] . ' to test.', 'bad'); return; }
        connector_trace(null, true);
        [$state, $text] = connector_test($p, $v + ['provider' => $p], $secret);
        connector_log_add($pdo, $p, 'test', $state, $text, (string) $admin['username']);
        flash($d['name'] . ': ' . $text, $state === 'ok' ? 'ok' : 'bad');
        return;
    }
    if ($action === 'sync') {
        $r = connector_sync($pdo, $p, true);
        flash($r['message'], $r['state'] === 'ok' ? 'ok' : 'bad');
        return;
    }
    flash('Unknown action.', 'bad');
}

function tab_render(PDO $pdo, array $admin): void
{
    $field = 'mt-1.5 h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $btn = 'pressable h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-butter-50';
    ?>
<?php if (module_any('calls', 'links')): ?>
<section class="rise max-w-3xl" aria-labelledby="cn-h">
  <h2 id="cn-h" class="text-lg font-bold tracking-tight">Connect your phone systems, Twilio and Short.io</h2>
  <p class="mt-1 max-w-2xl text-sm text-soft">Secrets are stored encrypted and are never shown again: leave a secret field empty to keep the saved one. Calls and messages are matched to people and companies by phone number, so keep the phone numbers on people and companies up to date. Data is fetched every few minutes by a scheduled job (<code>bin/sync_connectors.php</code>) or when you press Sync now.</p>
</section>
<?php endif; ?>
<?php foreach (connector_defs() as $p => $d):
    if (!module_enabled(connector_module($p))) { continue; }   // only services whose module is on are listed
    $c = connector_get($pdo, $p);
    $has = connector_has_secret($c);
    $state = (string) $c['last_state'];
    ?>
<section id="c-<?= e($p) ?>" class="rise mt-6 max-w-3xl rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="c-<?= e($p) ?>-h">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <h3 id="c-<?= e($p) ?>-h" class="text-base font-bold"><?= service_badge($p, $d['name']) ?> <span class="ml-1 text-sm font-normal text-soft">gives you <?= e($d['gives']) ?></span></h3>
    <span class="text-sm font-semibold"><?= $c['enabled'] ? 'On' : 'Off' ?></span>
  </div>
  <p class="mt-1 text-sm text-soft"><?= e($d['help']) ?></p>
  <form method="post" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate autocomplete="off"><?= csrf_field() ?>
    <input type="hidden" name="tab" value="connectors"><input type="hidden" name="provider" value="<?= e($p) ?>">
    <?php if ($d['type'] === 'pbx'): ?>
      <div class="sm:col-span-2"><label for="<?= e($p) ?>-url" class="text-sm font-semibold"><?= e($d['url_label']) ?></label>
        <input id="<?= e($p) ?>-url" name="base_url" maxlength="300" inputmode="url" placeholder="<?= e($d['url_hint']) ?>" value="<?= e((string) $c['base_url']) ?>" class="<?= e($field) ?>"></div>
    <?php endif; ?>
    <?php if ($d['id_label'] !== ''): ?>
      <div><label for="<?= e($p) ?>-id" class="text-sm font-semibold"><?= e($d['id_label']) ?></label>
        <input id="<?= e($p) ?>-id" name="account_ref" maxlength="190" autocomplete="off" value="<?= e((string) $c['account_ref']) ?>" class="<?= e($field) ?>"></div>
    <?php endif; ?>
    <div<?= $d['id_label'] === '' ? ' class="sm:col-span-2"' : '' ?>><label for="<?= e($p) ?>-secret" class="text-sm font-semibold"><?= e($d['secret_label']) ?></label>
      <input id="<?= e($p) ?>-secret" name="secret" type="password" maxlength="300" autocomplete="new-password"
             placeholder="<?= $has ? e('Saved, ends in ' . $c['secret_last4'] . ' (leave empty to keep)') : 'Paste it here' ?>" class="<?= e($field) ?>">
      <p class="mt-1 text-xs text-soft"><?= $has ? 'A secret is saved. It is only sent to the address above, and only while that address and the ID stay the same.' : 'No secret saved yet.' ?></p></div>
    <div class="flex flex-col gap-2 sm:col-span-2">
      <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="enabled" value="1" <?= $c['enabled'] ? 'checked' : '' ?> class="mt-0.5 h-5 w-5 rounded border-line accent-lime"><span>Turn <?= e($d['name']) ?> on (it is synced by the scheduled job)</span></label>
      <?php if ($d['type'] === 'pbx'): ?>
        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="self_signed" value="1" <?= !$c['verify_tls'] ? 'checked' : '' ?> class="mt-0.5 h-5 w-5 rounded border-line accent-lime"><span>Accept a self-signed certificate (a phone system on your own network). Leave off when it has a proper certificate.</span></label>
      <?php endif; ?>
      <?php if ($p === 'twilio'): ?>
        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="keep_text" value="1" <?= $c['keep_text'] ? 'checked' : '' ?> class="mt-0.5 h-5 w-5 rounded border-line accent-lime"><span>Keep the text of messages. Off by default: only who, when and the status are stored, so private message content stays at Twilio.</span></label>
      <?php endif; ?>
    </div>
    <div class="flex flex-wrap gap-3 sm:col-span-2">
      <button name="action" value="save" class="pressable h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95">Save</button>
      <button name="action" value="test" class="<?= e($btn) ?>">Test connection</button>
      <button name="action" value="sync" class="<?= e($btn) ?>" <?= $c['enabled'] && $has ? '' : 'disabled aria-disabled="true"' ?>>Sync now</button>
      <?php if ($has): ?><button name="action" value="remove" class="<?= e($btn) ?>">Remove credentials</button><?php endif; ?>
    </div>
  </form>
  <p class="mt-4 text-sm text-soft" role="status">
    <?php if ($c['last_sync_at']): ?>Last sync <?= e(ago((string) $c['last_sync_at'])) ?>: <?= e(connector_state_text($state)) ?> <?= $state === 'ok' ? e((string) (int) $c['last_count']) . ' record' . ((int) $c['last_count'] === 1 ? '' : 's') . '.' : '' ?>
    <?php else: ?>Not synced yet.<?php endif; ?>
  </p>
  <?php $log = connector_log_recent($pdo, $p); ?>
  <details class="mt-4 rounded-xl border border-line" <?= $log && $log[0]['state'] !== 'ok' ? 'open' : '' ?>>
    <summary class="cursor-pointer px-4 py-3 text-sm font-semibold">Log <span class="font-normal text-soft">(<?= count($log) ?> recent: every test and sync, with what the service answered)</span></summary>
    <?php if (!$log): ?><p class="border-t border-line px-4 py-4 text-sm text-soft">Nothing has been tested or synced yet. Press Test connection and the result is written here.</p>
    <?php else: ?><ul class="divide-y divide-line border-t border-line">
      <?php foreach ($log as $e): ?>
        <li class="px-4 py-3 text-sm">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1"><?= badge($e['state'] === 'ok' ? 'ok' : (in_array($e['state'], ['busy', 'forbidden'], true) ? 'warning' : 'failed')) ?><span class="font-semibold"><?= e(['test' => 'Test', 'sync' => 'Sync now', 'scheduled' => 'Scheduled sync'][$e['action']] ?? $e['action']) ?> · <?= e($e['state']) ?></span><span class="text-soft" title="<?= e($e['created_at']) ?>"><?= e(ago($e['created_at'])) ?> · <?= e($e['created_by']) ?></span></div>
          <p class="mt-1 text-soft"><?= e($e['message']) ?></p>
          <?php if ($e['detail'] !== ''): ?><pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-deep p-3 text-xs notranslate"><?= e($e['detail']) ?></pre><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul><?php endif; ?>
  </details>
</section>
<?php endforeach;
}
