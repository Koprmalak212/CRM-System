<?php
declare(strict_types=1);
require_once __DIR__ . '/../monitor.php';

/** Reads and validates the add/edit form. Returns [clean values, error messages]. */
function read_endpoint_form(bool $isEdit, ?array $existing): array
{
    $err = [];
    $name = trim((string) ($_POST['name'] ?? ''));
    $url = trim((string) ($_POST['url'] ?? ''));
    $method = ($_POST['method'] ?? 'GET') === 'HEAD' ? 'HEAD' : 'GET';
    $expected = (int) ($_POST['expected_status'] ?? 200);
    $timeout = (int) ($_POST['timeout_s'] ?? 5);
    $slow = (int) ($_POST['slow_ms'] ?? 1500);
    $interval = (int) ($_POST['interval_min'] ?? 5);
    $hdr = trim((string) ($_POST['auth_header'] ?? ''));
    $secret = (string) ($_POST['auth_secret'] ?? '');
    $removeSecret = ($_POST['remove_secret'] ?? '') === '1';
    $enabled = ($_POST['enabled'] ?? '') === '1' ? 1 : 0;

    if ($name === '' || mb_strlen($name) > 80) { $err[] = 'Give the endpoint a name of up to 80 characters.'; }
    [$ok, $msg] = validate_target($url);
    if (!$ok) { $err[] = $msg; }
    if ($expected < 100 || $expected > 599) { $err[] = 'The expected status must be between 100 and 599.'; }
    if ($timeout < 1 || $timeout > 10) { $err[] = 'The timeout must be between 1 and 10 seconds.'; }
    if ($slow < 100 || $slow > 30000) { $err[] = '"Slow above" must be between 100 and 30000 ms.'; }
    if ($interval < 1 || $interval > 1440) { $err[] = 'The interval must be between 1 and 1440 minutes.'; }

    $enc = $existing['auth_secret_enc'] ?? null;
    if ($hdr === '' && $secret === '') {
        if ($removeSecret || !$isEdit) { $enc = null; $hdr = ''; }
        else { $hdr = (string) ($existing['auth_header'] ?? ''); }   // untouched on edit
    } else {
        if ($secret === '' && $isEdit && !empty($existing['auth_secret_enc'])) {
            $e2 = validate_header($hdr, 'x');   // header name changed, keep the stored secret
            if ($e2 !== null) { $err[] = $e2; }
        } else {
            $e2 = validate_header($hdr, $secret);
            if ($e2 !== null) { $err[] = $e2; }
            elseif (!$err) { $enc = secret_encrypt($secret); }
        }
    }
    return [[
        'name' => $name, 'url' => $url, 'method' => $method, 'expected_status' => $expected, 'timeout_s' => $timeout,
        'slow_ms' => $slow, 'interval_min' => $interval, 'auth_header' => $hdr, 'auth_secret_enc' => $enc, 'enabled' => $enabled,
    ], $err];
}

function tab_handle(PDO $pdo, array $admin): void
{
    $action = (string) ($_POST['action'] ?? '');
    $level = integrations_level();
    $manage = ['add', 'edit', 'delete', 'toggle', 'key_create', 'key_revoke'];
    if (in_array($action, $manage, true) && $level !== 'manage') { deny_access('integrations:' . $action); }
    if (in_array($action, ['check', 'check_all'], true) && $level === 'none') { deny_access('integrations:' . $action); }

    $id = (int) ($_POST['id'] ?? 0);
    $load = static function (PDO $pdo, int $id): ?array {
        $s = $pdo->prepare('SELECT * FROM monitored_endpoints WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    };

    switch ($action) {
        case 'check':
        case 'check_all':
            [$ok] = rate_hit('check', 20, 60);   // checks make outbound requests, so they get their own limit
            if (!$ok) { flash('Too many checks in a minute. Wait a moment and try again.', 'bad'); return; }
            if ($action === 'check') {
                $ep = $load($pdo, $id);
                if (!$ep) { flash('That endpoint no longer exists.', 'bad'); return; }
                $r = run_check($ep);
                record_check($pdo, $ep, $r);
                flash($ep['name'] . ': ' . strtolower(state_label($r['state'])) . ($r['latency'] !== null ? ' in ' . $r['latency'] . ' ms' : '') . ($r['error'] !== '' ? '. ' . $r['error'] : '.'), $r['state'] === 'down' ? 'bad' : 'ok');
            } else {
                $list = $pdo->query('SELECT * FROM monitored_endpoints WHERE enabled = 1 ORDER BY id LIMIT 10')->fetchAll();
                $up = 0;
                foreach ($list as $ep) { $r = run_check($ep); record_check($pdo, $ep, $r); if ($r['state'] !== 'down') { $up++; } }
                flash('Checked ' . count($list) . ' endpoint' . (count($list) === 1 ? '' : 's') . ': ' . $up . ' responding, ' . (count($list) - $up) . ' not.', $up === count($list) ? 'ok' : 'bad');
            }
            return;

        case 'add':
        case 'edit':
            $existing = $action === 'edit' ? $load($pdo, $id) : null;
            if ($action === 'edit' && !$existing) { flash('That endpoint no longer exists.', 'bad'); return; }
            if ($action === 'add' && (int) $pdo->query('SELECT COUNT(*) FROM monitored_endpoints')->fetchColumn() >= MAX_ENDPOINTS) {
                flash('The limit of ' . MAX_ENDPOINTS . ' endpoints is reached. Remove one first.', 'bad'); return;
            }
            [$d, $errors] = read_endpoint_form($action === 'edit', $existing);
            if ($errors) {
                $_SESSION['ep_form'] = ['id' => $id, 'values' => $d + ['secret_set' => !empty($existing['auth_secret_enc'])], 'errors' => $errors];
                $GLOBALS['tab_redirect'] = 'settings.php?tab=integrations&' . ($action === 'edit' ? 'edit=' . $id : 'add=1') . '#endpoint-form';
                return;
            }
            if ($action === 'add') {
                $pdo->prepare('INSERT INTO monitored_endpoints (name, url, method, expected_status, timeout_s, slow_ms, interval_min, auth_header, auth_secret_enc, enabled, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$d['name'], $d['url'], $d['method'], $d['expected_status'], $d['timeout_s'], $d['slow_ms'], $d['interval_min'], $d['auth_header'], $d['auth_secret_enc'], $d['enabled'], $admin['id']]);
                log_activity('info', 'Endpoint added', $d['name']);
                flash('Endpoint added. Run a check to see how it responds.');
            } else {
                $pdo->prepare('UPDATE monitored_endpoints SET name=?, url=?, method=?, expected_status=?, timeout_s=?, slow_ms=?, interval_min=?, auth_header=?, auth_secret_enc=?, enabled=? WHERE id=?')
                    ->execute([$d['name'], $d['url'], $d['method'], $d['expected_status'], $d['timeout_s'], $d['slow_ms'], $d['interval_min'], $d['auth_header'], $d['auth_secret_enc'], $d['enabled'], $id]);
                log_activity('info', 'Endpoint updated', $d['name']);
                flash('Endpoint updated.');
            }
            return;

        case 'toggle':
            $ep = $load($pdo, $id);
            if ($ep) {
                $pdo->prepare('UPDATE monitored_endpoints SET enabled = 1 - enabled WHERE id = ?')->execute([$id]);
                log_activity('info', $ep['enabled'] ? 'Endpoint paused' : 'Endpoint resumed', $ep['name']);
                flash($ep['name'] . ($ep['enabled'] ? ' is paused.' : ' is active again.'));
            }
            return;

        case 'delete':
            $ep = $load($pdo, $id);
            if ($ep) {
                $pdo->prepare('DELETE FROM monitored_endpoints WHERE id = ?')->execute([$id]);
                log_activity('warning', 'Endpoint deleted', $ep['name']);
                flash($ep['name'] . ' and its history were deleted.');
            }
            return;

        case 'key_create':
            $name = trim((string) ($_POST['key_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80) { flash('Name the key after the tool that will use it, up to 80 characters.', 'bad'); return; }
            if ((int) $pdo->query('SELECT COUNT(*) FROM api_keys WHERE revoked_at IS NULL')->fetchColumn() >= 20) { flash('There are already 20 active keys. Revoke one first.', 'bad'); return; }
            [$plain, $prefix, $hash] = generate_api_key();
            try { $enc = secret_encrypt($plain); }
            catch (RuntimeException $e) { flash('The encryption key is not configured on the server (app_key in includes/config.local.php), so no key was created.', 'bad'); return; }
            // key_hash is what incoming calls are checked against; key_enc lets an admin show the key again later.
            $pdo->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, key_enc, scope, created_by) VALUES (?, ?, ?, ?, \'health:read\', ?)')->execute([$name, $prefix, $hash, $enc, $admin['id']]);
            log_activity('warning', 'API key created', $name);
            flash('API key created. It is saved: use Show key any time to see it again.');
            return;

        case 'key_revoke':
            $s = $pdo->prepare('SELECT name FROM api_keys WHERE id = ? AND revoked_at IS NULL');
            $s->execute([$id]);
            if ($n = $s->fetchColumn()) {
                $pdo->prepare('UPDATE api_keys SET revoked_at = NOW() WHERE id = ?')->execute([$id]);
                log_activity('warning', 'API key revoked', (string) $n);
                flash('Key "' . $n . '" was revoked and stops working immediately.');
            }
            return;
    }
}

function tab_render(PDO $pdo, array $admin): void
{
    $level = integrations_level();
    $manage = $level === 'manage';
    $field = 'mt-1.5 h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
    $btn = 'pressable inline-flex h-9 items-center gap-1.5 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-white/5';

    $eps = $pdo->query('SELECT * FROM monitored_endpoints ORDER BY name')->fetchAll();
    $uptime = $pdo->query("SELECT endpoint_id, SUM(state <> 'down') AS ok, COUNT(*) AS n FROM endpoint_checks WHERE checked_at >= NOW() - INTERVAL 24 HOUR GROUP BY endpoint_id")->fetchAll(PDO::FETCH_UNIQUE);
    $ribbon = [];
    foreach ($pdo->query('SELECT endpoint_id, state, latency_ms, checked_at FROM endpoint_checks WHERE checked_at >= NOW() - INTERVAL 24 HOUR ORDER BY checked_at DESC LIMIT 4000') as $c) {
        $ribbon[(int) $c['endpoint_id']][] = $c;
    }
    $keys = $pdo->query('SELECT * FROM api_keys ORDER BY revoked_at IS NOT NULL, created_at DESC')->fetchAll();

    $form = $_SESSION['ep_form'] ?? null;
    unset($_SESSION['ep_form']);

    $editId = (int) ($_GET['edit'] ?? 0);
    $editing = null;
    if ($manage && $editId) { foreach ($eps as $e) { if ((int) $e['id'] === $editId) { $editing = $e; } } }
    $showForm = $manage && ($editing || isset($_GET['add']) || $form);
    $v = $form['values'] ?? ($editing ?? ['name' => '', 'url' => '', 'method' => 'GET', 'expected_status' => 200, 'timeout_s' => 5, 'slow_ms' => 1500, 'interval_min' => 5, 'auth_header' => '', 'enabled' => 1]);
    $isEdit = $form ? (int) ($form['id'] ?? 0) > 0 : (bool) $editing;
    $formId = $form ? (int) ($form['id'] ?? 0) : (int) ($editing['id'] ?? 0);
    $secretSet = $form ? !empty($form['values']['secret_set']) : !empty($editing['auth_secret_enc']);

    $scheme = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
    $host = preg_match('/^[A-Za-z0-9.\-:]{1,100}$/', (string) ($_SERVER['HTTP_HOST'] ?? '')) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $apiUrl = $scheme . '://' . $host . rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/') . '/api/health.php';
    ?>
<?php if (!$manage): ?>
  <p role="note" class="rise mb-6 rounded-xl border border-line bg-panel px-4 py-3 text-sm text-soft">You have <strong class="text-ink">view only</strong> access. You can see the results and run checks. Ask an admin for "Can manage" to add endpoints or create API keys.</p>
<?php endif; ?>

<section class="rise rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="ep-h">
  <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line p-5 sm:p-6">
    <div>
      <h2 id="ep-h" class="text-lg font-bold tracking-tight">Monitored endpoints</h2>
      <p class="mt-1 max-w-2xl text-sm text-soft">The server calls each address and records status and response time. These are real requests, not demo data. Only public addresses can be checked; internal networks are blocked.</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <?php if ($eps): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="integrations"><input type="hidden" name="action" value="check_all"><button class="<?= $btn ?>"><?= icon('activity', 'h-4 w-4') ?>Check all now</button></form><?php endif; ?>
      <?php if ($manage): ?><a href="settings.php?tab=integrations&amp;add=1#endpoint-form" class="pressable inline-flex h-9 items-center gap-1.5 rounded-lg bg-lime px-3 text-sm font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?>Add endpoint</a><?php endif; ?>
    </div>
  </div>

  <?php if (!$eps): ?>
    <p class="p-6 text-sm text-soft">No endpoints yet.<?= $manage ? ' Add the first one to start monitoring.' : '' ?></p>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[860px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <th scope="col" class="px-4 py-3 font-semibold">Endpoint</th><th scope="col" class="px-4 py-3 font-semibold">State</th>
        <th scope="col" class="px-4 py-3 text-right font-semibold">Response</th><th scope="col" class="px-4 py-3 font-semibold">Last 24 h</th>
        <th scope="col" class="px-4 py-3 font-semibold">Checked</th><th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th>
      </tr></thead>
      <tbody class="divide-y divide-line">
      <?php foreach ($eps as $ep): $id = (int) $ep['id']; $u = $uptime[$id] ?? null; $bars = array_reverse(array_slice($ribbon[$id] ?? [], 0, 24)); ?>
        <tr class="hover:bg-butter-50 <?= $ep['enabled'] ? '' : 'bg-ink/5' ?>">
          <td class="px-4 py-3"><p class="font-semibold"><?= e($ep['name']) ?><?= $ep['enabled'] ? '' : ' <span class="text-xs font-medium text-soft">(paused)</span>' ?></p><p class="max-w-xs truncate text-soft notranslate" title="<?= e($ep['url']) ?>"><?= e($ep['method']) ?> · <?= e($ep['url']) ?></p></td>
          <td class="px-4 py-3"><?= badge($ep['last_state']) ?><?php if ($ep['last_error'] !== ''): ?><p class="mt-1 max-w-[14rem] text-xs text-[#FF9C8C]"><?= e($ep['last_error']) ?></p><?php endif; ?></td>
          <td class="px-4 py-3 text-right tabular-nums notranslate"><?= $ep['last_latency_ms'] !== null ? e((string) $ep['last_latency_ms']) . ' ms' : '—' ?><?= $ep['last_status_code'] ? '<p class="text-xs text-soft">HTTP ' . (int) $ep['last_status_code'] . '</p>' : '' ?></td>
          <td class="px-4 py-3">
            <?php if ($bars): ?>
              <p class="flex h-6 items-end gap-[2px]" role="img" aria-label="<?= $u ? e(number_format((float) $u['ok'] / max(1, (int) $u['n']) * 100, 1)) . '% of the last ' . (int) $u['n'] . ' checks responded' : 'No checks' ?>">
                <?php foreach ($bars as $b): ?><span class="block h-6 w-1.5 rounded-[2px] <?= $b['state'] === 'up' ? 'bg-lime' : ($b['state'] === 'slow' ? 'bg-butter' : 'bg-coral') ?>" title="<?= e(state_label($b['state']) . ' · ' . $b['checked_at']) ?>"></span><?php endforeach; ?>
              </p>
              <p class="mt-1 text-xs text-soft tabular-nums"><?= $u ? e(number_format((float) $u['ok'] / max(1, (int) $u['n']) * 100, 1)) . '% up' : '' ?></p>
            <?php else: ?><span class="text-soft">No history</span><?php endif; ?>
          </td>
          <td class="px-4 py-3 text-soft"><?= $ep['last_checked_at'] ? e(ago($ep['last_checked_at'])) : 'Never' ?><p class="text-xs">every <?= (int) $ep['interval_min'] ?> min</p></td>
          <td class="px-4 py-3">
            <div class="flex flex-wrap justify-end gap-2">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="integrations"><input type="hidden" name="action" value="check"><input type="hidden" name="id" value="<?= $id ?>"><button class="<?= $btn ?> hover:bg-lime hover:text-navy">Check now</button></form>
              <?php if ($manage): ?>
                <a href="settings.php?tab=integrations&amp;edit=<?= $id ?>#endpoint-form" class="<?= $btn ?>">Edit</a>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="integrations"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $id ?>"><button class="<?= $btn ?>"><?= $ep['enabled'] ? 'Pause' : 'Resume' ?></button></form>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="integrations"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="<?= $btn ?> hover:bg-[#FFD3CC] hover:text-[#7E160A]" aria-label="Delete <?= e($ep['name']) ?>">Delete</button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="border-t border-line px-5 py-3 text-xs text-soft sm:px-6">Checks run when you press "Check now", or on a schedule if you run <code class="rounded bg-white/10 px-1.5 py-0.5 text-ink">check_endpoints.php</code> from Windows Task Scheduler every minute. History is kept for <?= CHECK_HISTORY_DAYS ?> days.</p>
  <?php endif; ?>
</section>

<?php if ($showForm): ?>
<section id="endpoint-form" class="rise mt-6 rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="form-h">
  <h2 id="form-h" class="text-lg font-bold tracking-tight"><?= $isEdit ? 'Edit endpoint' : 'Add an endpoint' ?></h2>
  <?php if (!empty($form['errors'])): ?>
    <div role="alert" class="mt-4 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]"><p class="font-semibold">It can not be saved yet:</p><ul class="mt-1 list-disc pl-5"><?php foreach ($form['errors'] as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate><?= csrf_field() ?><input type="hidden" name="tab" value="integrations"><input type="hidden" name="action" value="<?= $isEdit ? 'edit' : 'add' ?>"><input type="hidden" name="id" value="<?= $formId ?>">
    <div><label for="f-name" class="text-sm font-semibold">Name</label><input id="f-name" name="name" maxlength="80" value="<?= e($v['name']) ?>" required class="<?= $field ?>"></div>
    <div><label for="f-url" class="text-sm font-semibold">URL to check</label><input id="f-url" name="url" type="url" maxlength="500" value="<?= e($v['url']) ?>" placeholder="https://status.example.com/health" required class="<?= $field ?> notranslate"><p class="mt-1 text-xs text-soft">Public https:// or http:// address. Ports 80, 443, 8080 and 8443.</p></div>
    <div><label for="f-method" class="text-sm font-semibold">Method</label><select id="f-method" name="method" class="<?= $field ?>"><option <?= $v['method'] === 'GET' ? 'selected' : '' ?>>GET</option><option <?= $v['method'] === 'HEAD' ? 'selected' : '' ?>>HEAD</option></select></div>
    <div><label for="f-exp" class="text-sm font-semibold">Expected status code</label><input id="f-exp" name="expected_status" inputmode="numeric" value="<?= e((string) $v['expected_status']) ?>" class="<?= $field ?> tabular-nums"></div>
    <div><label for="f-to" class="text-sm font-semibold">Timeout (seconds, 1 to 10)</label><input id="f-to" name="timeout_s" inputmode="numeric" value="<?= e((string) $v['timeout_s']) ?>" class="<?= $field ?> tabular-nums"></div>
    <div><label for="f-slow" class="text-sm font-semibold">Mark as slow above (ms)</label><input id="f-slow" name="slow_ms" inputmode="numeric" value="<?= e((string) $v['slow_ms']) ?>" class="<?= $field ?> tabular-nums"></div>
    <div><label for="f-int" class="text-sm font-semibold">Check every (minutes)</label><input id="f-int" name="interval_min" inputmode="numeric" value="<?= e((string) $v['interval_min']) ?>" class="<?= $field ?> tabular-nums"><p class="mt-1 text-xs text-soft">Used by the scheduled job.</p></div>
    <div class="flex items-end pb-1"><label class="flex items-center gap-3 text-sm font-semibold"><input type="checkbox" name="enabled" value="1" <?= !empty($v['enabled']) ? 'checked' : '' ?> class="h-5 w-5 rounded border-line accent-lime">Active (include in scheduled checks)</label></div>
    <fieldset class="rounded-xl border border-line p-4 sm:col-span-2"><legend class="px-2 text-sm font-semibold">Secret header (optional)</legend>
      <p class="text-xs text-soft">For endpoints that need a token, for example <span class="notranslate">Authorization</span>. The value is encrypted in the database and is never shown again.</p>
      <div class="mt-3 grid gap-4 sm:grid-cols-2">
        <div><label for="f-hn" class="text-sm font-semibold">Header name</label><input id="f-hn" name="auth_header" maxlength="60" value="<?= e($v['auth_header']) ?>" placeholder="Authorization" class="<?= $field ?> notranslate"></div>
        <div><label for="f-hv" class="text-sm font-semibold">Header value <?= $secretSet ? '<span class="font-normal text-soft">(leave empty to keep the current secret)</span>' : '' ?></label><input id="f-hv" name="auth_secret" type="password" autocomplete="off" maxlength="300" placeholder="<?= $secretSet ? '•••••••• stored' : 'Bearer …' ?>" class="<?= $field ?> notranslate"></div>
      </div>
      <?php if ($secretSet): ?><label class="mt-3 flex items-center gap-3 text-sm"><input type="checkbox" name="remove_secret" value="1" class="h-5 w-5 rounded border-line accent-lime">Remove the stored secret</label><?php endif; ?>
    </fieldset>
    <div class="flex flex-wrap gap-3 sm:col-span-2">
      <button class="pressable h-11 rounded-xl bg-lime px-6 text-sm font-bold text-navy hover:brightness-95"><?= $isEdit ? 'Save changes' : 'Add endpoint' ?></button>
      <a href="settings.php?tab=integrations" class="pressable inline-flex h-11 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-white/5">Cancel</a>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="rise mt-6 rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.05s" aria-labelledby="key-h">
  <div class="border-b border-line p-5 sm:p-6">
    <h2 id="key-h" class="text-lg font-bold tracking-tight">Health API for other tools</h2>
    <p class="mt-1 max-w-2xl text-sm text-soft">A read-only JSON feed of the health status. Tools such as a status page or uptime monitor call it with an API key. Keys are saved (encrypted), so you never have to create a new one just to see it again: press Show key. Incoming calls are checked against a separate hash.</p>
    <p class="mt-3 text-sm"><span class="text-soft">Address:</span> <code class="notranslate break-all rounded bg-white/10 px-1.5 py-0.5 text-ink"><?= e($apiUrl) ?></code></p>
    <pre class="notranslate mt-2 overflow-x-auto rounded-xl bg-deep p-3 text-xs text-soft"><code>curl -H "Authorization: Bearer YOUR_KEY" <?= e($apiUrl) ?></code></pre>
  </div>



  <?php if ($manage): ?>
  <form method="post" class="flex flex-wrap items-end gap-3 border-b border-line p-5 sm:p-6"><?= csrf_field() ?><input type="hidden" name="tab" value="integrations"><input type="hidden" name="action" value="key_create">
    <div class="min-w-64 flex-1"><label for="k-name" class="text-sm font-semibold">Key name</label><input id="k-name" name="key_name" maxlength="80" placeholder="For example: Status page" class="<?= $field ?>"></div>
    <button class="pressable inline-flex h-11 items-center gap-2 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95"><?= icon('plus', 'h-4 w-4') ?>Create key</button>
  </form>
  <?php endif; ?>

  <?php if (!$keys): ?><p class="p-6 text-sm text-soft">No API keys yet.</p>
  <?php else: ?>
  <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-left text-sm">
    <thead class="bg-butter-50 text-xs text-soft"><tr><th scope="col" class="px-4 py-3 font-semibold">Name</th><th scope="col" class="px-4 py-3 font-semibold">Key</th><th scope="col" class="px-4 py-3 font-semibold">Created</th><th scope="col" class="px-4 py-3 font-semibold">Last used</th><th scope="col" class="px-4 py-3 font-semibold">Status</th><?php if ($manage): ?><th scope="col" class="px-4 py-3 text-right font-semibold">Action</th><?php endif; ?></tr></thead>
    <tbody class="divide-y divide-line">
    <?php foreach ($keys as $k): $rev = $k['revoked_at'] !== null; ?>
      <tr class="<?= $rev ? 'bg-ink/5' : 'hover:bg-butter-50' ?>">
        <td class="px-4 py-3 font-semibold"><?= e($k['name']) ?></td>
        <td class="px-4 py-3 notranslate">
          <span class="tabular-nums text-soft"><?= e($k['key_prefix']) ?>…</span>
          <?php if (!$rev && $manage && !empty($k['key_enc'])): ?>
            <button type="button" class="key-show <?= $btn ?> ml-2" data-id="<?= (int) $k['id'] ?>" aria-label="Show key <?= e($k['name']) ?>">Show key</button>
            <span class="key-out mt-2 hidden items-center gap-2"><code class="break-all rounded-lg bg-deep px-2.5 py-1.5 text-xs text-ink select-all"></code><button type="button" class="key-copy <?= $btn ?>">Copy</button></span>
          <?php elseif (!$rev && empty($k['key_enc'])): ?>
            <p class="mt-1 max-w-[16rem] text-xs text-soft">Created before keys were saved, so it can not be shown. Create a new key once and it will be kept.</p>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-soft"><?= e(ago($k['created_at'])) ?></td>
        <td class="px-4 py-3 text-soft"><?= $k['last_used_at'] ? e(ago($k['last_used_at'])) : 'Never' ?></td>
        <td class="px-4 py-3"><?= $rev ? '<span class="inline-flex rounded-full bg-[#FFD3CC] px-2.5 py-0.5 text-xs font-semibold text-[#7E160A]">Revoked</span>' : badge('active') ?></td>
        <?php if ($manage): ?><td class="px-4 py-3 text-right"><?php if (!$rev): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="integrations"><input type="hidden" name="action" value="key_revoke"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>"><button class="<?= $btn ?> hover:bg-[#FFD3CC] hover:text-[#7E160A]">Revoke</button></form>
        <?php endif; ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var tok = document.querySelector('input[name=csrf]');
  if (!tok) return;
  document.querySelectorAll('.key-show').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var out = btn.parentNode.querySelector('.key-out'), code = out.querySelector('code'), timer = btn.dataset.timer;
      if (!out.classList.contains('hidden')) { out.classList.add('hidden'); out.classList.remove('flex'); code.textContent = ''; btn.textContent = 'Show key'; return; }
      btn.disabled = true; btn.textContent = 'Loading…';
      fetch('api_key_reveal.php', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': tok.value, 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'id=' + encodeURIComponent(btn.dataset.id) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (r) {
          if (!r.ok || !r.j.key) { btn.textContent = 'Could not show'; return; }
          code.textContent = r.j.key; out.classList.remove('hidden'); out.classList.add('flex'); btn.textContent = 'Hide key';
          clearTimeout(timer);
          btn.dataset.timer = setTimeout(function () { out.classList.add('hidden'); out.classList.remove('flex'); code.textContent = ''; btn.textContent = 'Show key'; }, 30000);   // hides itself after 30 s
        })
        .catch(function () { btn.textContent = 'Could not show'; })
        .finally(function () { btn.disabled = false; });
    });
  });
  document.querySelectorAll('.key-copy').forEach(function (c) {
    c.addEventListener('click', function () {
      var t = c.parentNode.querySelector('code').textContent;
      if (!t) return;
      (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { c.textContent = 'Copied'; }).catch(function () {
        var r = document.createRange(); r.selectNodeContents(c.parentNode.querySelector('code')); var sel = getSelection(); sel.removeAllRanges(); sel.addRange(r);
        var done = false; try { done = document.execCommand('copy'); } catch (e) { /* older browsers */ }
        c.textContent = done ? 'Copied' : 'Press Ctrl+C';   // the key stays selected either way
      }).finally(function () { setTimeout(function () { c.textContent = 'Copy'; }, 1800); });
    });
  });
})();
</script>
<?php
}
