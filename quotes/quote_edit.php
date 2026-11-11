<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/quotes.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$quote = null;
if ($id > 0) {
    $quote = quote_find($pdo, $id) ?? quote_not_found();
    if (!quote_editable($quote)) { flash('An accepted or declined quote can not be changed. Reopen it first.', 'error'); redirect('quotes/quote.php?id=' . $id); }
}

$errors = [];
if ($quote) {
    $old = ['company_id' => (int) $quote['company_id'], 'customer' => $quote['customer'], 'email' => $quote['email'], 'address' => $quote['customer_address'], 'title' => $quote['title'], 'issue_date' => $quote['issue_date'], 'valid_until' => $quote['valid_until'], 'vat' => (string) $quote['vat_rate'], 'notes' => $quote['notes']];
    $lines = lines_from_rows(quote_items($pdo, $id));
} else {
    $old = ['company_id' => 0, 'customer' => '', 'email' => '', 'address' => '', 'title' => '', 'issue_date' => date('Y-m-d'), 'valid_until' => date('Y-m-d', strtotime('+30 days')), 'vat' => '21', 'notes' => ''];
    $lines = [lines_blank()];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($pre = company_find($pdo, (int) ($_GET['company'] ?? 0)))) {   // opened from a company page
        $old['company_id'] = (int) $pre['id']; $old['customer'] = $pre['name']; $old['email'] = $pre['email']; $old['address'] = company_address_line($pre);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['customer', 'email', 'address', 'title', 'issue_date', 'valid_until', 'vat', 'notes'] as $f) { $old[$f] = trim((string) ($_POST[$f] ?? '')); }
    $old['company_id'] = (int) ($_POST['company_id'] ?? 0);
    [$d, $items, $lines, $errors] = quote_validate($pdo, $_POST);
    if (!$errors) {
        $new = $id === 0;
        $id = quote_save($pdo, $id, $d, $items);
        $saved = quote_find($pdo, $id);
        log_activity('info', $new ? 'Quote created' : 'Quote changed', $saved['number'] . ' · ' . $saved['customer'] . ' · ' . money((int) $saved['total_cents']));
        flash($new ? 'Quote ' . $saved['number'] . ' created as a draft for ' . $saved['customer'] . '.' : 'Quote ' . $saved['number'] . ' saved.');
        redirect('quotes/quote.php?id=' . $id);
    }
}

$companies = $pdo->query('SELECT * FROM companies ORDER BY name')->fetchAll();
page_start($quote ? 'Edit quote' : 'New quote', 'quotes', $quote ? $quote['number'] . ' · ' . $quote['customer'] : 'Add the customer and what you offer. The quote starts as a draft.');
$field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<a href="<?= $quote ? 'quotes/quote.php?id=' . $id : 'quotes/quotes.php' ?>" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?><?= $quote ? 'Back to the quote' : 'All quotes' ?></a>

<?php if ($errors): ?>
  <div role="alert" class="mb-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]">
    <p class="font-semibold">This quote can not be saved yet:</p>
    <ul class="mt-1 list-disc pl-5"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" id="quoteForm" class="grid items-start gap-6 xl:grid-cols-[1.7fr_1fr]" novalidate><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <div class="space-y-6">
    <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="cust-h">
      <h2 id="cust-h" class="text-lg font-bold tracking-tight">Customer</h2>
      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2"><label for="company_id" class="text-sm font-semibold">Company <span class="font-normal text-soft">(optional: fills in the fields below)</span></label>
          <select id="company_id" name="company_id" class="<?= $field ?> mt-1.5"><option value="0">Not linked to a company</option>
            <?php foreach ($companies as $co): ?><option value="<?= (int) $co['id'] ?>" data-name="<?= e($co['name']) ?>" data-email="<?= e($co['email']) ?>" data-address="<?= e(company_address_line($co)) ?>" <?= (int) $old['company_id'] === (int) $co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option><?php endforeach; ?></select></div>
        <div><label for="customer" class="text-sm font-semibold">Customer name</label>
          <input id="customer" name="customer" maxlength="160" value="<?= e($old['customer']) ?>" autocomplete="off" class="<?= $field ?> mt-1.5"></div>
        <div><label for="email" class="text-sm font-semibold">Email <span class="font-normal text-soft">(optional)</span></label>
          <input id="email" name="email" type="email" maxlength="190" value="<?= e($old['email']) ?>" class="<?= $field ?> mt-1.5"></div>
        <div class="sm:col-span-2"><label for="address" class="text-sm font-semibold">Customer address <span class="font-normal text-soft">(optional)</span></label>
          <input id="address" name="address" maxlength="255" value="<?= e($old['address']) ?>" autocomplete="off" placeholder="Street and number, postcode, city" class="<?= $field ?> mt-1.5"></div>
        <div class="sm:col-span-2"><label for="title" class="text-sm font-semibold">Subject <span class="font-normal text-soft">(optional)</span></label>
          <input id="title" name="title" maxlength="160" value="<?= e($old['title']) ?>" placeholder="e.g. Move to 3CX with 12 seats" class="<?= $field ?> mt-1.5"></div>
        <div><label for="issue_date" class="text-sm font-semibold">Quote date</label>
          <input id="issue_date" name="issue_date" type="date" value="<?= e($old['issue_date']) ?>" class="<?= $field ?> mt-1.5"></div>
        <div><label for="valid_until" class="text-sm font-semibold">Valid until</label>
          <input id="valid_until" name="valid_until" type="date" value="<?= e($old['valid_until']) ?>" class="<?= $field ?> mt-1.5"></div>
      </div>
    </section>

    <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="lines-h">
      <?= lines_fields($lines, 'Unit price (€)', 'e.g. VoIP seat, 3CX (per user per month)') ?>
      <div class="mt-5"><label for="notes" class="text-sm font-semibold">Note on the quote <span class="font-normal text-soft">(required when BTW is 0%: state the reason)</span></label>
        <input id="notes" name="notes" maxlength="500" value="<?= e($old['notes']) ?>" class="<?= $field ?> mt-1.5"></div>
    </section>
  </div>

  <aside class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6 xl:sticky xl:top-24" style="animation-delay:.1s" aria-labelledby="sum-h">
    <h2 id="sum-h" class="text-lg font-bold tracking-tight">Summary</h2>
    <div class="mt-4"><label for="vat" class="text-sm font-semibold">BTW rate</label>
      <select id="vat" name="vat" class="<?= $field ?> mt-1.5"><?php foreach (['21' => '21% (standard)', '9' => '9% (reduced)', '0' => '0% (exempt)'] as $v => $l): ?><option value="<?= $v ?>" <?= $old['vat'] === (string) $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <dl class="mt-5 space-y-3 text-sm notranslate">
      <div class="flex justify-between"><dt class="text-soft">Subtotal</dt><dd id="subOut" class="font-semibold tabular-nums">€ 0,00</dd></div>
      <div class="flex justify-between"><dt class="text-soft">BTW <span id="vatPct">21</span>%</dt><dd id="vatOut" class="font-semibold tabular-nums">€ 0,00</dd></div>
      <div class="flex justify-between border-t border-line pt-3 text-base"><dt class="font-bold">Total</dt><dd id="totOut" class="text-xl font-bold tabular-nums text-lime">€ 0,00</dd></div>
    </dl>
    <button class="pressable mt-6 h-12 w-full rounded-xl bg-lime text-[15px] font-bold text-navy hover:brightness-95"><?= $quote ? 'Save quote' : 'Create quote' ?></button>
    <p class="mt-3 text-center text-xs text-soft">Download the PDF from the quote page and mark it as sent.</p>
  </aside>
</form>
<?= lines_script('quoteForm') ?>
<?php page_end(); ?>
