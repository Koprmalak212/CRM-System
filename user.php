<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/companies.php';
require __DIR__ . '/includes/notes.php';
require __DIR__ . '/includes/tickets.php';
require __DIR__ . '/includes/appointments.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$load = function () use ($pdo, $id): ?array {
    $s = $pdo->prepare('SELECT u.*, c.name AS company FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
};
$u = $load();
if (!$u) {
    http_response_code(404);
    page_start('User not found', 'users', 'That user does not exist. They may have been removed.');
    echo '<a href="users.php" class="pressable inline-flex h-11 items-center rounded-xl bg-lime px-5 text-sm font-bold text-navy">Back to users</a>';
    page_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (str_starts_with((string) ($_POST['action'] ?? ''), 'note')) { notes_handle_post($pdo, 'user', $id, 'user.php?id=' . $id); }
    if (($_POST['action'] ?? '') === 'company') {
        $cid = (int) ($_POST['company_id'] ?? 0);
        $target = $cid > 0 ? company_find($pdo, $cid) : null;
        if ($cid > 0 && !$target) { flash('That company does not exist.', 'error'); redirect('user.php?id=' . $id); }
        $pdo->prepare('UPDATE users SET company_id = ? WHERE id = ?')->execute([$target ? $cid : null, $id]);
        log_activity('info', $target ? 'User moved to company' : 'User removed from company', $u['name'] . ($target ? ' · ' . $target['name'] : ''));
        flash($target ? $u['name'] . ' now belongs to ' . $target['name'] . '.' : $u['name'] . ' no longer has a company.');
        redirect('user.php?id=' . $id);
    }
    $to = ($_POST['to'] ?? '') === 'suspended' ? 'suspended' : 'active';
    $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$to, $id]);
    log_activity($to === 'suspended' ? 'warning' : 'info', $to === 'suspended' ? 'Suspended account' : 'Reactivated account', $u['name']);
    flash($u['name'] . ($to === 'suspended' ? ' was suspended.' : ' is active again.'));
    redirect('user.php?id=' . $id);
}

$conns = $pdo->prepare('SELECT * FROM connections WHERE user_id = ? ORDER BY started_at DESC LIMIT 8');
$conns->execute([$id]);
$conns = $conns->fetchAll();
$connCount = $pdo->prepare('SELECT COUNT(*) FROM connections WHERE user_id = ?');
$connCount->execute([$id]);
$connTotal = (int) $connCount->fetchColumn();

$logs = $pdo->prepare('SELECT level, actor, action, target, created_at FROM activity_logs WHERE actor = ? OR actor = ? OR target = ? ORDER BY created_at DESC, id DESC LIMIT 8');
$logs->execute([$u['email'], $u['name'], $u['name']]);
$logs = $logs->fetchAll();

$mates = [];
if ($u['company_id'] !== null) {
    $m = $pdo->prepare('SELECT id, name, role, status FROM users WHERE company_id = ? AND id <> ? ORDER BY name LIMIT 12');
    $m->execute([$u['company_id'], $id]);
    $mates = $m->fetchAll();
}

$none = '<span class="text-soft">Not set</span>';
$fields = [
    'Full name' => e($u['name']),
    'Email' => '<a class="underline decoration-lime decoration-2 underline-offset-4 break-all" href="mailto:' . e($u['email']) . '">' . e($u['email']) . '</a>',
    'Phone' => $u['phone'] !== '' ? '<span class="notranslate">' . e($u['phone']) . '</span>' : $none,
    'Company' => $u['company_id'] !== null ? '<a class="underline decoration-lime decoration-2 underline-offset-4" href="company.php?id=' . (int) $u['company_id'] . '">' . e($u['company']) . '</a>' : $none,
    'Job title' => $u['job_title'] !== '' ? e($u['job_title']) : $none,
    'Country' => e($u['country']),
    'Role' => '<span class="capitalize">' . e($u['role']) . '</span>',
    'Status' => badge($u['status']),
    'Member since' => e(date('j F Y', strtotime($u['created_at']))) . ' <span class="text-soft">(' . e(ago($u['created_at'])) . ')</span>',
    'Last seen' => $u['logins'] > 0 ? e(date('j M Y, H:i', strtotime($u['last_seen']))) . ' <span class="text-soft">(' . e(ago($u['last_seen'])) . ')</span>' : '<span class="text-soft">Never signed in</span>',
    'Sign-ins' => number_format((int) $u['logins']),
    'Active sessions' => number_format((int) $pdo->query('SELECT COUNT(*) FROM connections WHERE status = \'active\' AND user_id = ' . $id)->fetchColumn()),
    'User ID' => '#' . (int) $u['id'],
];

page_start($u['name'], 'users', ($u['job_title'] !== '' ? $u['job_title'] . ' · ' : '') . ($u['company_id'] !== null ? $u['company'] : 'No company set'));
?>
<nav class="rise mb-4 text-sm" aria-label="Breadcrumb"><a href="users.php" class="inline-flex items-center gap-1 font-semibold underline decoration-lime decoration-2 underline-offset-4">&larr; All users</a></nav>

<section class="rise mb-5 flex flex-wrap items-center gap-5 rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-label="Profile">
  <span class="grid h-20 w-20 shrink-0 place-items-center rounded-full bg-raised text-2xl font-bold text-lime"><?= e(initials($u['name'])) ?></span>
  <div class="min-w-0 flex-1">
    <p class="text-xl font-bold"><?= e($u['name']) ?></p>
    <p class="mt-1 text-soft break-all"><?= e($u['email']) ?></p>
    <div class="mt-3 flex flex-wrap items-center gap-2"><?= badge($u['status']) ?><span class="rounded-full border border-line px-3 py-1 text-xs font-semibold capitalize"><?= e($u['role']) ?></span><?php if ($u['company_id'] !== null): ?><a href="company.php?id=<?= (int) $u['company_id'] ?>" class="rounded-full border border-line px-3 py-1 text-xs font-semibold hover:bg-butter-50"><?= e($u['company']) ?></a><?php endif; ?></div>
  </div>
  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($u['status'] === 'suspended'): ?>
      <input type="hidden" name="to" value="active">
      <button class="h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-lime hover:text-navy">Reactivate account</button>
    <?php else: ?>
      <input type="hidden" name="to" value="suspended">
      <button class="h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Suspend account</button>
    <?php endif; ?>
  </form>
</section>

<div class="grid gap-5 lg:grid-cols-3">
  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel lg:col-span-2" aria-labelledby="det">
    <h2 id="det" class="text-base font-bold">All details</h2>
    <dl class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2">
      <?php foreach ($fields as $label => $html): ?>
        <div class="min-w-0"><dt class="text-xs font-semibold uppercase tracking-wide text-soft"><?= e($label) ?></dt><dd class="mt-1 text-sm font-medium"><?= $html ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>

  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="co">
    <h2 id="co" class="text-base font-bold">Colleagues at <?= $u['company_id'] !== null ? e($u['company']) : 'their company' ?></h2>
    <?php if ($mates): ?>
      <ul class="mt-4 divide-y divide-line">
        <?php foreach ($mates as $m): ?>
          <li class="flex items-center justify-between gap-3 py-2.5"><a href="user.php?id=<?= (int) $m['id'] ?>" class="min-w-0 truncate text-sm font-semibold hover:underline"><?= e($m['name']) ?></a><span class="shrink-0 text-xs capitalize text-soft"><?= e($m['role']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="mt-3 text-sm text-soft"><?= $u['company_id'] !== null ? 'No other users from this company yet.' : 'No company is set for this user.' ?></p>
    <?php endif; ?>
    <form method="post" class="mt-5 border-t border-line pt-4"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="company">
      <label for="company_id" class="text-sm font-semibold">Company</label>
      <div class="mt-1.5 flex gap-2"><select id="company_id" name="company_id" class="h-10 min-w-0 flex-1 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"><option value="0">No company</option><?php foreach ($pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR) as $cid => $cname): ?><option value="<?= (int) $cid ?>" <?= (int) $u['company_id'] === (int) $cid ? 'selected' : '' ?>><?= e($cname) ?></option><?php endforeach; ?></select>
        <button class="h-10 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy">Save</button></div></form>
  </section>

  <section class="rise rounded-2xl border border-line bg-panel shadow-panel lg:col-span-2" aria-labelledby="cn">
    <div class="flex items-center justify-between gap-3 p-5 pb-3"><h2 id="cn" class="text-base font-bold">Recent connections</h2><span class="text-xs text-soft"><?= number_format($connTotal) ?> in total</span></div>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[560px] text-left text-sm">
        <thead class="bg-butter-50 text-xs text-soft"><tr><th scope="col" class="px-4 py-3 font-semibold">Device</th><th scope="col" class="px-4 py-3 font-semibold">Location</th><th scope="col" class="px-4 py-3 font-semibold">Status</th><th scope="col" class="px-4 py-3 font-semibold">Started</th><th scope="col" class="px-4 py-3 text-right font-semibold">Traffic</th></tr></thead>
        <tbody class="divide-y divide-line">
          <?php foreach ($conns as $c): ?>
            <tr class="hover:bg-butter-50"><td class="px-4 py-3"><p class="font-semibold"><?= e($c['device']) ?></p><p class="text-soft notranslate"><?= e($c['ip']) ?> · <?= e($c['protocol']) ?></p></td><td class="px-4 py-3"><?= e($c['location']) ?></td><td class="px-4 py-3"><?= badge($c['status']) ?></td><td class="px-4 py-3 text-soft"><?= e(ago($c['started_at'])) ?></td><td class="px-4 py-3 text-right tabular-nums"><?= e(bytes_h((int) $c['bytes_in'] + (int) $c['bytes_out'])) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$conns): ?><tr><td colspan="5" class="px-4 py-8 text-center text-soft">This user has no recorded connections.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="ac">
    <h2 id="ac" class="text-base font-bold">Recent activity</h2>
    <?php if ($logs): ?>
      <ul class="mt-4 space-y-3">
        <?php foreach ($logs as $l): ?>
          <li class="text-sm"><div class="flex items-center gap-2"><?= badge($l['level']) ?><span class="text-xs text-soft"><?= e(ago($l['created_at'])) ?></span></div><p class="mt-1 font-medium"><?= e($l['action']) ?><?= $l['target'] !== '' ? ' <span class="text-soft">· ' . e($l['target']) . '</span>' : '' ?></p></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="mt-3 text-sm text-soft">Nothing recorded for this user yet.</p>
    <?php endif; ?>
  </section>
  <?= tickets_panel($pdo, 'user_id', $id, 'ticket_new.php?user=' . $id) ?>
  <?php if ($u['company_id'] !== null): ?><?= appointments_panel($pdo, (int) $u['company_id'], 'Appointments of ' . $u['company'], 'appointment_edit.php?company=' . (int) $u['company_id'] . '&user=' . $id, $id) ?><?php endif; ?>
  <?= notes_panel($pdo, 'user', $id, 'user.php?id=' . $id) ?>
</div>
<?php page_end(); ?>
