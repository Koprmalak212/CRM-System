<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/invoices.php';
require __DIR__ . '/../includes/modules/companies.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();

$errors = [];
$old = [
    'company_id' => 0, 'customer' => '', 'email' => '', 'address' => '', 'supply_date' => '', 'issue_date' => date('Y-m-d'), 'terms' => '14', 'vat' => '21', 'notes' => '',
    'items' => [['description' => '', 'qty' => '1', 'price' => '']],
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($pre = company_find($pdo, (int) ($_GET['company'] ?? 0)))) {
    // opened from a company page: fill in the customer fields from the company
    $old['company_id'] = (int) $pre['id'];
    $old['customer'] = $pre['name'];
    $old['email'] = $pre['email'];
    $old['address'] = company_address_line($pre);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old['company_id'] = (int) ($_POST['company_id'] ?? 0);
    if ($old['company_id'] > 0 && !company_find($pdo, $old['company_id'])) { $errors[] = 'That company does not exist.'; $old['company_id'] = 0; }
    $old['customer'] = trim((string) ($_POST['customer'] ?? ''));
    $old['email'] = trim((string) ($_POST['email'] ?? ''));
    $old['address'] = trim((string) ($_POST['address'] ?? ''));
    $old['supply_date'] = (string) ($_POST['supply_date'] ?? '');
    $old['issue_date'] = (string) ($_POST['issue_date'] ?? '');
    $old['terms'] = (string) ($_POST['terms'] ?? '14');
    $old['vat'] = (string) ($_POST['vat'] ?? '21');
    $old['notes'] = trim((string) ($_POST['notes'] ?? ''));

    if ($old['customer'] === '' || mb_strlen($old['customer']) > 160) $errors[] = 'Enter the customer name.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address to send the invoice to.';
    if ($old['address'] === '' || mb_strlen($old['address']) > 255) $errors[] = 'Enter the customer address. Dutch invoices must show it.';
    if ($old['supply_date'] !== '' && !valid_date($old['supply_date'])) $errors[] = 'The supply date is not a valid date.';
    if (!valid_date($old['issue_date']) || $old['issue_date'] < '2000-01-01' || $old['issue_date'] > '2100-12-31') $errors[] = 'Choose an issue date between 2000 and 2100.';
    if (!in_array($old['terms'], ['0', '7', '14', '30', '60'], true)) $errors[] = 'Choose payment terms.';
    if (!in_array($old['vat'], ['0', '9', '21'], true)) $errors[] = 'Choose a BTW rate.';
    if ($old['vat'] === '0' && $old['notes'] === '') $errors[] = 'State the reason for 0% BTW in the note, for example "BTW verlegd" or "Intracommunautaire levering".';

    $items = [];
    $old['items'] = [];
    if (count((array) ($_POST['desc'] ?? [])) > 50) { $errors[] = 'An invoice can have at most 50 lines.'; }
    $desc = (array) ($_POST['desc'] ?? []);
    $qty = (array) ($_POST['qty'] ?? []);
    $price = (array) ($_POST['price'] ?? []);
    foreach ($desc as $k => $d) {
        $d = trim((string) $d);
        $qRaw = (string) ($qty[$k] ?? '1');
        $pRaw = (string) ($price[$k] ?? '');
        $old['items'][] = ['description' => $d, 'qty' => $qRaw, 'price' => $pRaw];
        if ($d === '' && trim($pRaw) === '') continue;
        $qn = (float) str_replace(',', '.', $qRaw);
        $pc = parse_money($pRaw);
        if ($d === '' || $pc === null || $pc > 100000000 || $qn <= 0 || $qn > 10000) { $errors[] = 'Line ' . ($k + 1) . ' needs a description, a quantity and a price above zero.'; continue; }
        $items[] = [mb_substr($d, 0, 200), $qn, $pc];
    }
    $lineSum = 0;
    foreach ($items as $it) { $lineSum += $it[1] * $it[2]; }
    if ($lineSum > 1000000000) { $errors[] = 'The invoice total is too large. The limit is € 10.000.000.'; }
    if (!$items && !$errors) $errors[] = 'Add at least one line to the invoice.';
    if (!$old['items']) $old['items'] = [['description' => '', 'qty' => '1', 'price' => '']];

    if (!$errors) {
        $sub = 0;
        foreach ($items as $it) { $sub += (int) round($it[1] * $it[2]); }
        $vatC = (int) round($sub * ((int) $old['vat']) / 100);
        $due = date('Y-m-d', strtotime($old['issue_date'] . ' +' . (int) $old['terms'] . ' days'));
        $number = next_invoice_number($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO invoices (number, company_id, customer, email, customer_address, supply_date, issue_date, due_date, vat_rate, subtotal_cents, vat_cents, total_cents, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$number, $old['company_id'] > 0 ? $old['company_id'] : null, $old['customer'], $old['email'], $old['address'], $old['supply_date'] !== '' ? $old['supply_date'] : $old['issue_date'], $old['issue_date'], $due, (int) $old['vat'], $sub, $vatC, $sub + $vatC, mb_substr($old['notes'], 0, 255)]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, qty, unit_cents) VALUES (?,?,?,?)');
            foreach ($items as $it) { $ins->execute([$id, $it[0], $it[1], $it[2]]); }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        log_activity('info', 'Invoice created', $number . ' · ' . $old['customer']);
        flash('Invoice ' . $number . ' created for ' . $old['customer'] . '.');
        redirect('invoices/invoice.php?id=' . $id);
    }
}

$known = $pdo->query('SELECT customer, email FROM invoices GROUP BY customer, email ORDER BY customer')->fetchAll();
$companies = $pdo->query('SELECT * FROM companies ORDER BY name')->fetchAll();
page_start('New invoice', 'invoices', 'Add the customer and the lines to bill. The due date follows from the payment terms.');
$field = 'h-11 w-full rounded-lg border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<a href="invoices/invoices.php" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?>All invoices</a>

<?php if ($errors): ?>
  <div role="alert" class="mb-6 rounded-lg bg-[#FFD3CC] px-4 py-3 text-sm font-semibold text-[#7E160A]">
    <p class="font-semibold">This invoice can not be saved yet:</p>
    <ul class="mt-1 list-disc pl-5"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" id="invForm" class="grid items-start gap-6 xl:grid-cols-[1.7fr_1fr]" novalidate><?= csrf_field() ?>
  <div class="space-y-6">
    <section class="rise rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="cust-h">
      <h2 id="cust-h" class="text-lg font-bold tracking-tight">Customer</h2>
 <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2"><label for="company_id" class="text-sm font-semibold">Company <span class="font-normal text-soft">(optional: fills in the fields below)</span></label>
          <select id="company_id" name="company_id" class="<?= $field ?> mt-1.5"><option value="0">Not linked to a company</option>
            <?php foreach ($companies as $co): ?><option value="<?= (int) $co['id'] ?>" data-name="<?= e($co['name']) ?>" data-email="<?= e($co['email']) ?>" data-address="<?= e(company_address_line($co)) ?>" <?= $old['company_id'] === (int) $co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option><?php endforeach; ?></select></div>
        <div><label for="customer" class="text-sm font-semibold">Customer name</label>
          <input id="customer" name="customer" list="customers" value="<?= e($old['customer']) ?>" autocomplete="off" required class="<?= $field ?> mt-1.5">
          <datalist id="customers"><?php foreach ($known as $k): ?><option value="<?= e($k['customer']) ?>" data-email="<?= e($k['email']) ?>"></option><?php endforeach; ?></datalist></div>
        <div><label for="email" class="text-sm font-semibold">Send to</label>
          <input id="email" name="email" type="email" value="<?= e($old['email']) ?>" required class="<?= $field ?> mt-1.5"></div>
        <div class="sm:col-span-2"><label for="address" class="text-sm font-semibold">Customer address</label>
          <input id="address" name="address" value="<?= e($old['address']) ?>" autocomplete="off" required placeholder="Street and number, postcode, city" class="<?= $field ?> mt-1.5"></div>
        <div><label for="issue_date" class="text-sm font-semibold">Issue date</label>
          <input id="issue_date" name="issue_date" type="date" value="<?= e($old['issue_date']) ?>" required class="<?= $field ?> mt-1.5"></div>
        <div><label for="supply_date" class="text-sm font-semibold">Supply date <span class="font-normal text-soft">(optional)</span></label>
          <input id="supply_date" name="supply_date" type="date" value="<?= e($old['supply_date']) ?>" class="<?= $field ?> mt-1.5">
          <p class="mt-1 text-xs text-soft">Leave empty when it equals the issue date.</p></div>
        <div><label for="terms" class="text-sm font-semibold">Payment terms</label>
          <select id="terms" name="terms" class="<?= $field ?> mt-1.5">
            <?php foreach (['0' => 'Due on receipt', '7' => '7 days', '14' => '14 days', '30' => '30 days', '60' => '60 days'] as $v => $l): ?><option value="<?= $v ?>" <?= $old['terms'] === (string) $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
          </select>
          <p class="mt-1 text-xs text-soft">Due date: <span id="dueOut" class="font-semibold text-ink notranslate">–</span></p></div>
      </div>
    </section>

    <section class="rise rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="lines-h">
      <div class="flex items-center justify-between gap-3"><h2 id="lines-h" class="text-lg font-bold tracking-tight">Lines</h2>
        <button type="button" id="addLine" class="inline-flex h-10 items-center gap-2 rounded-lg border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy"><?= icon('plus', 'h-4 w-4') ?>Add line</button></div>
      <div class="mt-4 hidden grid-cols-[1fr_88px_120px_110px_36px] gap-3 px-1 text-xs font-semibold text-soft sm:grid"><span>Description</span><span>Qty</span><span>Unit price (€)</span><span class="text-right">Line total</span><span></span></div>
      <div id="lines" class="mt-2 space-y-3">
        <?php foreach ($old['items'] as $it): ?>
          <div class="line grid grid-cols-2 gap-3 rounded-lg sm:grid-cols-[1fr_88px_120px_110px_36px] sm:items-center">
            <input name="desc[]" value="<?= e($it['description']) ?>" placeholder="e.g. Business fiber 1 Gbps (monthly)" aria-label="Description" class="<?= $field ?> col-span-2 sm:col-span-1">
            <input name="qty[]" value="<?= e($it['qty']) ?>" inputmode="decimal" aria-label="Quantity" class="<?= $field ?> tabular-nums">
            <input name="price[]" value="<?= e($it['price']) ?>" inputmode="decimal" placeholder="0,00" aria-label="Unit price in euros" class="<?= $field ?> tabular-nums">
            <p class="lt text-right text-sm font-semibold tabular-nums notranslate">€ 0,00</p>
            <button type="button" class="rm grid h-9 w-9 place-items-center justify-self-end rounded-lg text-soft hover:bg-[#FFD3CC] hover:text-[#7E160A]" aria-label="Remove line"><?= icon('x', 'h-4 w-4') ?></button>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-5"><label for="notes" class="text-sm font-semibold">Note on the invoice <span class="font-normal text-soft">(required when BTW is 0%: state the reason)</span></label>
        <input id="notes" name="notes" maxlength="255" value="<?= e($old['notes']) ?>" class="<?= $field ?> mt-1.5"></div>
    </section>
  </div>

  <aside class="rise rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6 xl:sticky xl:top-24" style="animation-delay:.1s" aria-labelledby="sum-h">
    <h2 id="sum-h" class="text-lg font-bold tracking-tight">Summary</h2>
    <div class="mt-4"><label for="vat" class="text-sm font-semibold">BTW rate</label>
      <select id="vat" name="vat" class="<?= $field ?> mt-1.5"><?php foreach (['21' => '21% (standard)', '9' => '9% (reduced)', '0' => '0% (exempt)'] as $v => $l): ?><option value="<?= $v ?>" <?= $old['vat'] === (string) $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <dl class="mt-5 space-y-3 text-sm notranslate">
      <div class="flex justify-between"><dt class="text-soft">Subtotal</dt><dd id="subOut" class="font-semibold tabular-nums">€ 0,00</dd></div>
      <div class="flex justify-between"><dt class="text-soft">BTW <span id="vatPct">21</span>%</dt><dd id="vatOut" class="font-semibold tabular-nums">€ 0,00</dd></div>
      <div class="flex justify-between border-t border-line pt-3 text-base"><dt class="font-bold">Total</dt><dd id="totOut" class="text-xl font-bold tabular-nums text-lime">€ 0,00</dd></div>
    </dl>
    <button class="pressable mt-6 h-12 w-full rounded-lg bg-lime text-sm font-bold text-navy hover:brightness-95">Create invoice</button>
    <p class="mt-3 text-center text-xs text-soft">The invoice starts as open. Record payments from its page.</p>
  </aside>
</form>

<template id="lineTpl">
  <div class="line grid grid-cols-2 gap-3 rounded-lg sm:grid-cols-[1fr_88px_120px_110px_36px] sm:items-center">
    <input name="desc[]" placeholder="e.g. VoIP seat, 3CX (per user per month)" aria-label="Description" class="<?= $field ?> col-span-2 sm:col-span-1">
    <input name="qty[]" value="1" inputmode="decimal" aria-label="Quantity" class="<?= $field ?> tabular-nums">
    <input name="price[]" inputmode="decimal" placeholder="0,00" aria-label="Unit price in euros" class="<?= $field ?> tabular-nums">
    <p class="lt text-right text-sm font-semibold tabular-nums notranslate">€ 0,00</p>
    <button type="button" class="rm grid h-9 w-9 place-items-center justify-self-end rounded-lg text-soft hover:bg-[#FFD3CC] hover:text-[#7E160A]" aria-label="Remove line"><?= icon('x', 'h-4 w-4') ?></button>
  </div>
</template>

<style nonce="<?= csp_nonce() ?>">
  @keyframes line-in { from { opacity: 0; transform: translateY(-8px) scale(.98); } 60% { opacity: 1; transform: translateY(2px); } to { transform: none; } }
  @keyframes line-out { 30% { transform: translateX(-8px); } to { opacity: 0; transform: translateX(40px); } }
  .line.new { animation: line-in .45s cubic-bezier(.34, 1.56, .64, 1) backwards; }
  .line.gone { animation: line-out .3s ease-in-out forwards; }
  @media (prefers-reduced-motion: reduce) { .line.new, .line.gone { animation-duration: .001ms; } }
</style>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var lines = document.getElementById('lines'), tpl = document.getElementById('lineTpl');
  var fmt = function (c) { return '€ ' + (c / 100).toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var num = function (s) { s = String(s).trim().replace(/[€\s]/g, ''); if (s.indexOf(',') > -1 && s.indexOf('.') > -1) s = s.replace(/\./g, ''); var n = parseFloat(s.replace(',', '.')); return isNaN(n) ? 0 : n; };
  function recalc() {
    var sub = 0;
    lines.querySelectorAll('.line').forEach(function (l) {
      var q = num(l.querySelector('[name="qty[]"]').value), p = num(l.querySelector('[name="price[]"]').value);
      var c = Math.round(q * p * 100); sub += c; l.querySelector('.lt').textContent = fmt(c);
    });
    var rate = parseInt(document.getElementById('vat').value, 10), vat = Math.round(sub * rate / 100);
    document.getElementById('subOut').textContent = fmt(sub);
    document.getElementById('vatPct').textContent = rate;
    document.getElementById('vatOut').textContent = fmt(vat);
    document.getElementById('totOut').textContent = fmt(sub + vat);
  }
  function due() {
    var d = document.getElementById('issue_date').value, t = parseInt(document.getElementById('terms').value, 10), out = document.getElementById('dueOut');
    if (!d) { out.textContent = '–'; return; }
    var dt = new Date(d + 'T00:00:00'); dt.setDate(dt.getDate() + t);
    out.textContent = dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  }
  document.getElementById('addLine').addEventListener('click', function () {
    var n = tpl.content.firstElementChild.cloneNode(true); n.classList.add('new'); lines.appendChild(n); n.querySelector('input').focus(); recalc();
  });
  lines.addEventListener('click', function (e) {
    var b = e.target.closest('.rm'); if (!b) return;
    var l = b.closest('.line');
    if (lines.querySelectorAll('.line').length === 1) { l.querySelectorAll('input').forEach(function (i, k) { i.value = k === 1 ? '1' : ''; }); recalc(); return; }
    l.classList.add('gone'); setTimeout(function () { l.remove(); recalc(); }, 280);
  });
  document.getElementById('invForm').addEventListener('input', function () { recalc(); due(); });
  document.getElementById('customer').addEventListener('change', function (e) {
    var o = document.querySelector('#customers option[value="' + CSS.escape(e.target.value) + '"]'), em = document.getElementById('email');
    if (o && !em.value) em.value = o.dataset.email;
  });
  document.getElementById('company_id').addEventListener('change', function (e) {
    var o = e.target.selectedOptions[0]; if (!o || !o.dataset.name) return;
    document.getElementById('customer').value = o.dataset.name; document.getElementById('email').value = o.dataset.email; document.getElementById('address').value = o.dataset.address;
  });
  recalc(); due();
})();
</script>
<?php page_end(); ?>
