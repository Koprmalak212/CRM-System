<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/recurring.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();

$errors = [];
$old = ['company_id' => (int) ($_GET['company'] ?? 0), 'description' => '', 'amount' => '', 'vat' => '21', 'frequency' => 'month', 'next_date' => date('Y-m-d', strtotime('+1 month')), 'terms' => '14'];
$deleteId = (int) ($_GET['delete'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $row = null;
    if ($id > 0) { $s = $pdo->prepare('SELECT r.*, c.name AS co_name FROM recurring_invoices r JOIN companies c ON c.id = r.company_id WHERE r.id = ?'); $s->execute([$id]); $row = $s->fetch() ?: null; }

    if ($action === 'add') {
        foreach (['description', 'amount', 'vat', 'frequency', 'next_date', 'terms'] as $f) { $old[$f] = (string) ($_POST[$f] ?? ''); }
        $old['company_id'] = (int) ($_POST['company_id'] ?? 0);
        [$d, $errors] = recurring_validate($pdo, $old);
        if (!$errors) {
            $pdo->prepare('INSERT INTO recurring_invoices (company_id, description, amount_cents, vat_rate, frequency, next_date, anchor_day, terms_days, active, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,1,?,NOW())')
                ->execute([$d['company_id'], $d['description'], $d['amount_cents'], $d['vat_rate'], $d['frequency'], $d['next_date'], (int) date('j', strtotime($d['next_date'])), $d['terms_days'], (string) ($_SESSION['admin']['username'] ?? '')]);
            log_activity('info', 'Recurring invoice added', $d['description'] . ' · ' . money($d['amount_cents']) . ' · ' . $d['frequency']);
            flash('Schedule added. The first invoice is created on ' . date('j F Y', strtotime($d['next_date'])) . '.');
            redirect('invoices/recurring.php');
        }
    } elseif ($action === 'toggle' && $row) {
        $on = (int) $row['active'] === 1;
        if ($on) {
            $pdo->prepare('UPDATE recurring_invoices SET active = 0 WHERE id = ?')->execute([$id]);
            log_activity('info', 'Recurring invoice paused', $row['description'] . ' · ' . $row['co_name']);
            flash('Paused. No invoices are created for it until you resume it.');
        } else {
            $next = (string) $row['next_date'];
            while ($next < date('Y-m-d')) { $next = recur_next_date($next, $row['frequency'], (int) $row['anchor_day']); }   // periods that passed while it was paused are skipped, not back-billed
            $pdo->prepare('UPDATE recurring_invoices SET active = 1, next_date = ? WHERE id = ?')->execute([$next, $id]);
            log_activity('info', 'Recurring invoice resumed', $row['description'] . ' · ' . $row['co_name']);
            flash('Resumed. The next invoice is created on ' . date('j F Y', strtotime($next)) . '.');
        }
        redirect('invoices/recurring.php');
    } elseif ($action === 'delete' && $row) {
        $pdo->prepare('DELETE FROM recurring_invoices WHERE id = ?')->execute([$id]);   // invoices it already created stay
        log_activity('warning', 'Recurring invoice deleted', $row['description'] . ' · ' . $row['co_name']);
        flash('Schedule deleted. The invoices it created were kept.');
        redirect('invoices/recurring.php');
    } elseif ($action === 'run') {
        $r = recurring_run_due($pdo);
        $n = count($r['created']);
        flash($n > 0 ? $n . ' invoice' . ($n === 1 ? '' : 's') . ' created: ' . implode(', ', array_column($r['created'], 1)) . '.' : 'Nothing is due today.');
        redirect('invoices/recurring.php');
    } else {
        flash('That schedule no longer exists.', 'error');
        redirect('invoices/recurring.php');
    }
}

$rows = $pdo->query('SELECT r.*, c.name AS co_name, (SELECT COUNT(*) FROM invoices i WHERE i.recurring_id = r.id) AS made FROM recurring_invoices r JOIN companies c ON c.id = r.company_id ORDER BY r.active DESC, r.next_date, r.id')->fetchAll();
$companies = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$dueNow = (int) $pdo->query("SELECT COUNT(*) FROM recurring_invoices WHERE active = 1 AND next_date <= CURDATE()")->fetchColumn();
page_start('Recurring invoices', 'invoices', 'Invoices that are created by themselves every month, quarter or year.');
$field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<a href="invoices/invoices.php" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?>All invoices</a>

<?php if ($errors): ?>
  <div role="alert" class="mb-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]"><p class="font-semibold">This schedule can not be saved yet:</p><ul class="mt-1 list-disc pl-5"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (module_enabled('contracts')): ?>
  <p role="note" class="rise mb-6 rounded-xl border border-line bg-panel px-4 py-3 text-sm text-soft">Services that belong to a contract do not need a schedule here: a <a href="contracts/contracts.php" class="font-semibold text-ink underline decoration-lime decoration-2 underline-offset-4">contract</a> creates its own invoices, one line per service.</p>
<?php endif; ?>

<section class="rise mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="run-h">
  <div><h2 id="run-h" class="text-base font-bold">Run schedules</h2>
    <p class="text-sm text-soft"><?= $dueNow > 0 ? '<strong class="text-ink">' . $dueNow . '</strong> schedule' . ($dueNow === 1 ? ' is' : 's are') . ' due now.' : 'Nothing is due right now.' ?> Due schedules are also run automatically when the site is opened. On a real server a scheduled task can run <code class="notranslate">php bin/run_recurring.php</code> every day.</p></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run"><button class="pressable h-11 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-lime hover:text-navy">Run now</button></form>
</section>

<?php if ($deleteId && ($del = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $deleteId))[0] ?? null)): ?>
  <section class="rise mb-6 rounded-2xl border border-line bg-panel p-5 shadow-panel" role="alert" aria-labelledby="del-h">
    <h2 id="del-h" class="text-base font-bold">Delete this schedule?</h2>
    <p class="mt-1 text-sm text-soft"><?= e($del['description']) ?> for <?= e($del['co_name']) ?>. The <?= (int) $del['made'] ?> invoice<?= (int) $del['made'] === 1 ? '' : 's' ?> already created stay.</p>
    <form method="post" class="mt-4 flex flex-wrap gap-3"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $del['id'] ?>">
      <button class="pressable h-11 rounded-xl bg-[#FFD3CC] px-5 text-sm font-bold text-[#7E160A] hover:brightness-95">Yes, delete the schedule</button>
      <a href="invoices/recurring.php" class="inline-flex h-11 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-butter-50">Keep it</a></form>
  </section>
<?php endif; ?>

<section class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="list-h">
  <div class="p-5 pb-3"><h2 id="list-h" class="text-base font-bold">Schedules</h2></div>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[980px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr><th scope="col" class="px-4 py-3 font-semibold">Company</th><th scope="col" class="px-4 py-3 font-semibold">Description</th><th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th><th scope="col" class="px-4 py-3 font-semibold">Repeats</th><th scope="col" class="px-4 py-3 font-semibold">Next invoice</th><th scope="col" class="px-4 py-3 font-semibold">Status</th><th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th></tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $r): $on = (int) $r['active'] === 1; ?>
          <tr class="<?= $on ? 'hover:bg-butter-50' : 'bg-ink/5' ?>">
            <td class="px-4 py-3"><a href="companies/company.php?id=<?= (int) $r['company_id'] ?>" class="font-semibold underline-offset-4 hover:underline"><?= e($r['co_name']) ?></a></td>
            <td class="px-4 py-3"><?= e($r['description']) ?><p class="text-xs text-soft"><?= (int) $r['made'] ?> created<?= $r['last_run'] ? ' · last ' . e(date('j M Y', strtotime($r['last_run']))) : '' ?></p></td>
            <td class="px-4 py-3 text-right tabular-nums notranslate"><?= e(money((int) $r['amount_cents'])) ?><p class="text-xs text-soft">+ <?= (int) $r['vat_rate'] ?>% BTW</p></td>
            <td class="px-4 py-3"><?= e(strtolower(RECUR_FREQ[$r['frequency']])) ?></td>
            <td class="px-4 py-3"><?php $n1 = $r['next_date']; $n2 = recur_next_date($n1, $r['frequency'], (int) $r['anchor_day']); $n3 = recur_next_date($n2, $r['frequency'], (int) $r['anchor_day']); ?><?= $on ? e(date('j M Y', strtotime($n1))) : '<span class="text-soft">paused</span>' ?><?= $on && $r['next_date'] <= date('Y-m-d') ? ' <span class="text-xs font-semibold text-butter">due</span>' : '' ?><p class="text-xs text-soft">then <?= e(date('j M', strtotime($n2))) ?>, <?= e(date('j M Y', strtotime($n3))) ?></p></td>
            <td class="px-4 py-3"><?= $on ? badge('active') : badge('suspended') ?></td>
            <td class="px-4 py-3"><div class="flex justify-end gap-2">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="h-9 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-butter-50"><?= $on ? 'Pause' : 'Resume' ?></button></form>
              <a href="invoices/recurring.php?delete=<?= (int) $r['id'] ?>" class="inline-flex h-9 items-center rounded-lg border border-line px-3 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Delete</a></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="px-4 py-12 text-center"><p class="text-base font-semibold">No recurring invoices yet</p><p class="mt-1 text-soft">Add one below, for example a monthly fiber subscription.</p></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="rise mt-6 max-w-3xl rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="add-h">
  <h2 id="add-h" class="text-lg font-bold tracking-tight">Add a schedule</h2>
  <form method="post" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="sm:col-span-2"><label for="company_id" class="text-sm font-semibold">Company</label>
      <select id="company_id" name="company_id" class="<?= $field ?> mt-1.5"><option value="0">Choose a company</option><?php foreach ($companies as $cid => $cn): ?><option value="<?= (int) $cid ?>" <?= (int) $old['company_id'] === (int) $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></div>
    <div class="sm:col-span-2"><label for="description" class="text-sm font-semibold">Description on the invoice</label><input id="description" name="description" maxlength="200" value="<?= e($old['description']) ?>" placeholder="e.g. Business fiber 1 Gbps (monthly)" class="<?= $field ?> mt-1.5"></div>
    <div><label for="amount" class="text-sm font-semibold">Amount per invoice, excl. BTW (€)</label><input id="amount" name="amount" inputmode="decimal" value="<?= e($old['amount']) ?>" placeholder="149,00" class="<?= $field ?> mt-1.5 tabular-nums"></div>
    <div><label for="vat" class="text-sm font-semibold">BTW rate</label><select id="vat" name="vat" class="<?= $field ?> mt-1.5"><?php foreach (['21' => '21% (standard)', '9' => '9% (reduced)', '0' => '0% (exempt)'] as $v => $l): ?><option value="<?= $v ?>" <?= $old['vat'] === (string) $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label for="frequency" class="text-sm font-semibold">Repeats</label><select id="frequency" name="frequency" class="<?= $field ?> mt-1.5"><?php foreach (RECUR_FREQ as $k => $l): ?><option value="<?= $k ?>" <?= $old['frequency'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label for="next_date" class="text-sm font-semibold">First invoice on</label><input id="next_date" name="next_date" type="date" value="<?= e($old['next_date']) ?>" class="<?= $field ?> mt-1.5"></div>
    <div><label for="terms" class="text-sm font-semibold">Payment terms</label><select id="terms" name="terms" class="<?= $field ?> mt-1.5"><?php foreach (['0' => 'Due on receipt', '7' => '7 days', '14' => '14 days', '30' => '30 days', '60' => '60 days'] as $v => $l): ?><option value="<?= $v ?>" <?= $old['terms'] === (string) $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="sm:col-span-2"><button class="pressable h-12 rounded-xl bg-lime px-6 text-[15px] font-bold text-navy hover:brightness-95">Add schedule</button></div>
  </form>
</section>
<?php page_end(); ?>
