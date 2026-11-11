<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/contracts.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$c = null;
if ($id > 0) { $c = contract_find($pdo, $id) ?? contract_not_found(); }

$errors = [];
$hours = fn(?int $min): string => $min === null ? '' : rtrim(rtrim(number_format($min / 60, 2, ',', ''), '0'), ',');
if ($c) {
    $old = ['company_id' => (int) $c['company_id'], 'title' => $c['title'], 'status' => $c['status'] === 'draft' ? 'draft' : 'active', 'start_date' => $c['start_date'], 'end_date' => (string) $c['end_date'], 'auto_renew' => (int) $c['auto_renew'],
        'renew_months' => (int) $c['renew_months'], 'notice_days' => (int) $c['notice_days'], 'billing' => $c['billing'], 'vat' => (string) $c['vat_rate'], 'terms' => (string) $c['terms_days'], 'next_invoice_date' => (string) $c['next_invoice_date'],
        'notes' => $c['notes'], 'sla_respond_h' => $hours($c['sla_respond_min'] !== null ? (int) $c['sla_respond_min'] : null), 'sla_resolve_h' => $hours($c['sla_resolve_min'] !== null ? (int) $c['sla_resolve_min'] : null)];
    $lines = lines_from_rows(contract_items($pdo, $id));
} else {
    $old = ['company_id' => (int) ($_GET['company'] ?? 0), 'title' => '', 'status' => 'active', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+1 year -1 day')), 'auto_renew' => 1, 'renew_months' => 12, 'notice_days' => 60,
        'billing' => 'month', 'vat' => '21', 'terms' => '14', 'next_invoice_date' => date('Y-m-d'), 'notes' => '', 'sla_respond_h' => '', 'sla_resolve_h' => ''];
    $lines = [lines_blank()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['title', 'status', 'start_date', 'end_date', 'billing', 'vat', 'terms', 'next_invoice_date', 'notes', 'sla_respond_h', 'sla_resolve_h'] as $f) { $old[$f] = (string) ($_POST[$f] ?? ''); }
    foreach (['company_id', 'renew_months', 'notice_days'] as $f) { $old[$f] = (int) ($_POST[$f] ?? 0); }
    $old['auto_renew'] = !empty($_POST['auto_renew']) ? 1 : 0;
    [$d, $items, $lines, $errors] = contract_validate($pdo, $_POST);
    if (!$errors) {
        $new = $id === 0;
        $id = contract_save($pdo, $id, $d, $items);
        $saved = contract_find($pdo, $id);
        log_activity('info', $new ? 'Contract created' : 'Contract changed', $saved['number'] . ' · ' . $saved['company_name'] . ' · ' . money((int) $saved['monthly_cents']) . ' a month');
        flash($new ? 'Contract ' . $saved['number'] . ' created for ' . $saved['company_name'] . '.' : 'Contract ' . $saved['number'] . ' saved.');
        redirect('contracts/contract.php?id=' . $id);
    }
}

$companies = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
page_start($c ? 'Edit contract' : 'New contract', 'contracts', $c ? $c['number'] . ' · ' . $c['company_name'] : 'The services of one company, what they cost a month, and how long the contract runs.');
$field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<a href="<?= $c ? 'contracts/contract.php?id=' . $id : 'contracts/contracts.php' ?>" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?><?= $c ? 'Back to the contract' : 'All contracts' ?></a>

<?php if ($errors): ?>
  <div role="alert" class="mb-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]">
    <p class="font-semibold">This contract can not be saved yet:</p>
    <ul class="mt-1 list-disc pl-5"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if ($c && $c['status'] === 'ended'): ?><p role="note" class="mb-6 rounded-xl border border-line bg-panel px-4 py-3 text-sm text-soft">This contract has ended. Saving it here reopens it, so check the end date.</p><?php endif; ?>

<form method="post" id="ctrForm" class="grid items-start gap-6 xl:grid-cols-[1.7fr_1fr]" novalidate><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <div class="space-y-6">
    <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="gen-h">
      <h2 id="gen-h" class="text-lg font-bold tracking-tight">Contract</h2>
      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2"><label for="company_id" class="text-sm font-semibold">Company</label>
          <select id="company_id" name="company_id" class="<?= $field ?> mt-1.5"><option value="0">Choose a company</option><?php foreach ($companies as $cid => $cn): ?><option value="<?= (int) $cid ?>" <?= (int) $old['company_id'] === (int) $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></div>
        <div class="sm:col-span-2"><label for="title" class="text-sm font-semibold">Name of the contract</label>
          <input id="title" name="title" maxlength="160" value="<?= e($old['title']) ?>" placeholder="e.g. Fiber and telephony, head office" class="<?= $field ?> mt-1.5"></div>
        <div><label for="start_date" class="text-sm font-semibold">Start date</label>
          <input id="start_date" name="start_date" type="date" value="<?= e($old['start_date']) ?>" class="<?= $field ?> mt-1.5"></div>
        <div><label for="end_date" class="text-sm font-semibold">End date <span class="font-normal text-soft">(empty: until ended)</span></label>
          <input id="end_date" name="end_date" type="date" value="<?= e($old['end_date']) ?>" class="<?= $field ?> mt-1.5"></div>
        <div class="sm:col-span-2"><label for="auto_renew" class="flex cursor-pointer items-start gap-3 rounded-xl border border-line px-4 py-3 hover:bg-butter-50">
          <input id="auto_renew" type="checkbox" name="auto_renew" value="1" <?= $old['auto_renew'] ? 'checked' : '' ?> class="mt-1 h-4 w-4 shrink-0 accent-lime">
          <span><span class="block text-sm font-semibold">Renews automatically</span><span class="block text-sm text-soft">On the end date the contract goes on for the renewal period. Without this it ends that day.</span></span></label></div>
        <div><label for="renew_months" class="text-sm font-semibold">Renewal period (months)</label>
          <input id="renew_months" name="renew_months" inputmode="numeric" maxlength="3" value="<?= (int) $old['renew_months'] ?>" class="<?= $field ?> mt-1.5 tabular-nums"></div>
        <div><label for="notice_days" class="text-sm font-semibold">Renewal alert (days before the end)</label>
          <input id="notice_days" name="notice_days" inputmode="numeric" maxlength="3" value="<?= (int) $old['notice_days'] ?>" class="<?= $field ?> mt-1.5 tabular-nums"></div>
      </div>
    </section>

    <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="lines-h">
      <?= lines_fields($lines, 'Per unit, a month (€)', 'e.g. Business fiber 1 Gbps', CONTRACT_SERVICES) ?>
      <p class="mt-3 text-xs text-soft">Prices are per unit per month, excluding BTW. For seats, the quantity is the number of seats.</p>
      <div class="mt-5"><label for="notes" class="text-sm font-semibold">Notes <span class="font-normal text-soft">(only shown here, not on invoices)</span></label>
        <textarea id="notes" name="notes" rows="3" maxlength="1000" class="mt-1.5 w-full rounded-xl border border-line bg-panel px-3 py-2 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"><?= e($old['notes']) ?></textarea></div>
    </section>

    <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.08s" aria-labelledby="sla-h">
      <h2 id="sla-h" class="text-lg font-bold tracking-tight">SLA of this contract <span class="text-sm font-normal text-soft">(optional)</span></h2>
      <p class="mt-1 text-sm text-soft">Leave empty to use the standard targets per priority. A target entered here applies to every ticket of this company whenever it is shorter than the standard one.</p>
      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div><label for="sla_respond_h" class="text-sm font-semibold">First response within (hours)</label><input id="sla_respond_h" name="sla_respond_h" inputmode="decimal" maxlength="8" value="<?= e($old['sla_respond_h']) ?>" placeholder="e.g. 1" class="<?= $field ?> mt-1.5 tabular-nums"></div>
        <div><label for="sla_resolve_h" class="text-sm font-semibold">Resolved within (hours)</label><input id="sla_resolve_h" name="sla_resolve_h" inputmode="decimal" maxlength="8" value="<?= e($old['sla_resolve_h']) ?>" placeholder="e.g. 8" class="<?= $field ?> mt-1.5 tabular-nums"></div>
      </div>
    </section>
  </div>

  <aside class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6 xl:sticky xl:top-24" style="animation-delay:.1s" aria-labelledby="bill-h">
    <h2 id="bill-h" class="text-lg font-bold tracking-tight">Invoicing</h2>
    <?php if (!module_enabled('invoices')): ?><p class="mt-2 rounded-xl border border-line px-3 py-2 text-xs text-soft">The Invoices module is turned off: these settings are kept, but nothing is invoiced.</p><?php endif; ?>
    <div class="mt-4 space-y-4">
      <div><label for="billing" class="text-sm font-semibold">Invoice</label>
        <select id="billing" name="billing" class="<?= $field ?> mt-1.5"><?php foreach (CONTRACT_BILLING as $k => $l): ?><option value="<?= $k ?>" <?= $old['billing'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label for="next_invoice_date" class="text-sm font-semibold">Next invoice on</label>
        <input id="next_invoice_date" name="next_invoice_date" type="date" value="<?= e($old['next_invoice_date']) ?>" class="<?= $field ?> mt-1.5"><p class="mt-1 text-xs text-soft">Empty: the start date. Later invoices keep this day of the month.</p></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label for="vat" class="text-sm font-semibold">BTW rate</label>
          <select id="vat" name="vat" class="<?= $field ?> mt-1.5"><?php foreach (['21' => '21%', '9' => '9%', '0' => '0%'] as $v => $l): ?><option value="<?= $v ?>" <?= $old['vat'] === (string) $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div><label for="terms" class="text-sm font-semibold">Payment terms</label>
          <select id="terms" name="terms" class="<?= $field ?> mt-1.5"><?php foreach (['0' => 'On receipt', '7' => '7 days', '14' => '14 days', '30' => '30 days', '60' => '60 days'] as $v => $l): ?><option value="<?= $v ?>" <?= $old['terms'] === (string) $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      </div>
      <div><label for="status" class="text-sm font-semibold">Status</label>
        <select id="status" name="status" class="<?= $field ?> mt-1.5"><option value="active" <?= $old['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="draft" <?= $old['status'] === 'draft' ? 'selected' : '' ?>>Draft (no invoices, no alerts)</option></select></div>
    </div>
    <dl class="mt-5 space-y-3 border-t border-line pt-4 text-sm notranslate">
      <div class="flex justify-between"><dt class="text-soft">Per month, excl. BTW</dt><dd id="subOut" class="text-xl font-bold tabular-nums text-lime">€ 0,00</dd></div>
    </dl>
    <button class="pressable mt-6 h-12 w-full rounded-xl bg-lime text-[15px] font-bold text-navy hover:brightness-95"><?= $c ? 'Save contract' : 'Create contract' ?></button>
  </aside>
</form>
<?= lines_script('ctrForm') ?>
<?php page_end(); ?>
