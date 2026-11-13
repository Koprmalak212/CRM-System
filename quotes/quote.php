<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/quotes.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$q = quote_find($pdo, $id) ?? quote_not_found();
$canInvoice = module_enabled('invoices');
$canContract = module_enabled('contracts');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['sent', 'accepted', 'declined', 'draft'], true)) {
        $err = quote_set_status($pdo, $q, $action, (string) ($_POST['reason'] ?? ''));
        flash($err ?? ['sent' => 'Marked as sent.', 'accepted' => 'Marked as accepted. You can now make the invoice or the contract.', 'declined' => 'Marked as declined.', 'draft' => 'Reopened as a draft.'][$action], $err ? 'error' : 'ok');
    } elseif ($action === 'to_invoice' && $canInvoice) {
        [$iid, $err] = quote_to_invoice($pdo, $id);
        if ($iid > 0) { flash('Invoice created from quote ' . $q['number'] . '.'); redirect('invoices/invoice.php?id=' . $iid); }
        flash($err, 'error');
    } elseif ($action === 'to_contract' && $canContract) {
        [$cid, $err] = quote_to_contract($pdo, $id);
        if ($cid > 0) { flash('A draft contract was made from quote ' . $q['number'] . '. Check the dates and the invoicing, then make it active.'); redirect('contracts/contract_edit.php?id=' . $cid); }
        flash($err, 'error');
    } elseif ($action === 'delete' && $q['invoice_id'] === null && $q['contract_id'] === null) {
        $pdo->prepare('DELETE FROM quotes WHERE id = ?')->execute([$id]);
        log_activity('warning', 'Quote deleted', $q['number'] . ' · ' . $q['customer']);
        flash('Quote ' . $q['number'] . ' was deleted.');
        redirect('quotes/quotes.php');
    } else {
        flash('That is not possible for this quote.', 'error');
    }
    redirect('quotes/quote.php?id=' . $id);
}

$items = quote_items($pdo, $id);
$company = require __DIR__ . '/../includes/modules/company.php';
$converted = $q['invoice_id'] !== null || $q['contract_id'] !== null;
$open = in_array($q['state'], ['draft', 'sent'], true);
$exists = function (string $table, mixed $rid) use ($pdo): bool {   // the invoice or contract may have been removed since
    if ($rid === null || (int) $rid <= 0) { return false; }
    $s = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE id = ?"); $s->execute([(int) $rid]);
    return (int) $s->fetchColumn() === 1;
};
$field = 'h-11 w-full rounded-lg border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
$btn = 'pressable inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg border border-line text-sm font-semibold hover:bg-lime hover:text-navy';
$post = fn(string $action, string $label, string $cls) => '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="' . $action . '"><button class="' . $cls . '">' . $label . '</button></form>';

page_start($q['number'], 'quotes', $q['customer'] . ($q['title'] !== '' ? ' · ' . $q['title'] : ''));
?>
<a href="quotes/quotes.php" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?>All quotes</a>

<div class="grid items-start gap-6 xl:grid-cols-[1.6fr_1fr]">
  <section class="rise rounded-xl border border-line bg-panel shadow-panel" aria-labelledby="doc-h">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-line p-5 sm:p-6">
      <div>
        <h2 id="doc-h" class="sr-only">Quote <?= e($q['number']) ?></h2>
        <p class="text-sm text-soft">Quote for</p>
        <p class="text-lg font-bold"><?php if ($q['company_id'] !== null): ?><a href="companies/company.php?id=<?= (int) $q['company_id'] ?>" class="underline decoration-lime decoration-2 underline-offset-4"><?= e($q['customer']) ?></a><?php else: ?><?= e($q['customer']) ?><?php endif; ?></p>
        <?php if ($q['email'] !== ''): ?><p class="text-sm text-soft"><?= e($q['email']) ?></p><?php endif; ?>
        <?php if ($q['customer_address'] !== ''): ?><p class="text-sm text-soft"><?= e($q['customer_address']) ?></p><?php endif; ?>
      </div>
      <div class="text-right">
        <?= badge($q['state']) ?>
        <p class="mt-2"><a href="quotes/quote_pdf.php?id=<?= $id ?>" class="pressable inline-flex h-10 items-center gap-2 rounded-lg border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy"><?= icon('download', 'h-4 w-4') ?>Download PDF</a></p>
        <p class="mt-2 text-sm font-semibold <?= $q['state'] === 'expired' ? 'text-[#FF9C8C]' : 'text-soft' ?>"><?= e(quote_state_text($q)) ?></p>
      </div>
    </div>
    <dl class="grid grid-cols-2 gap-4 border-b border-line p-5 text-sm sm:grid-cols-4 sm:p-6">
      <div><dt class="text-soft">Quote</dt><dd class="mt-0.5 font-semibold tabular-nums notranslate"><?= e($q['number']) ?></dd></div>
      <div><dt class="text-soft">Date</dt><dd class="mt-0.5 font-semibold"><?= e(date('j M Y', strtotime($q['issue_date']))) ?></dd></div>
      <div><dt class="text-soft">Valid until</dt><dd class="mt-0.5 font-semibold"><?= e(date('j M Y', strtotime($q['valid_until']))) ?></dd></div>
      <div><dt class="text-soft">From</dt><dd class="mt-0.5 font-semibold"><?= e($company['name']) ?></dd></div>
    </dl>
    <?php if ($q['title'] !== ''): ?><p class="border-b border-line px-5 py-3 text-sm sm:px-6"><span class="text-soft">Subject:</span> <span class="font-semibold"><?= e($q['title']) ?></span></p><?php endif; ?>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[520px] text-left text-sm">
        <thead class="text-xs text-soft"><tr><th scope="col" class="px-5 py-3 font-semibold sm:px-6">Description</th><th scope="col" class="px-3 py-3 text-right font-semibold">Qty</th><th scope="col" class="px-3 py-3 text-right font-semibold">Unit price</th><th scope="col" class="px-5 py-3 text-right font-semibold sm:px-6">Amount</th></tr></thead>
        <tbody class="divide-y divide-line border-t border-line notranslate">
          <?php foreach ($items as $it): ?>
            <tr><td class="px-5 py-3 sm:px-6"><?= e($it['description']) ?></td>
              <td class="px-3 py-3 text-right tabular-nums"><?= e(qty_text($it['qty'])) ?></td>
              <td class="px-3 py-3 text-right tabular-nums"><?= e(money((int) $it['unit_cents'])) ?></td>
              <td class="px-5 py-3 text-right font-semibold tabular-nums sm:px-6"><?= e(money((int) round((float) $it['qty'] * (int) $it['unit_cents']))) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <dl class="ml-auto w-full max-w-xs space-y-2 border-t border-line p-5 text-sm sm:p-6 notranslate">
      <div class="flex justify-between"><dt class="text-soft">Subtotal</dt><dd class="tabular-nums"><?= e(money((int) $q['subtotal_cents'])) ?></dd></div>
      <div class="flex justify-between"><dt class="text-soft">BTW <?= (int) $q['vat_rate'] ?>%</dt><dd class="tabular-nums"><?= e(money((int) $q['vat_cents'])) ?></dd></div>
      <div class="flex justify-between border-t border-line pt-2 text-base font-bold"><dt>Total</dt><dd class="tabular-nums"><?= e(money((int) $q['total_cents'])) ?></dd></div>
    </dl>
    <?php if ($q['notes'] !== ''): ?><p class="border-t border-line p-5 text-sm text-soft sm:p-6"><?= e($q['notes']) ?></p><?php endif; ?>
    <?php if ($q['state'] === 'declined' && $q['lost_reason'] !== ''): ?><p class="border-t border-line p-5 text-sm sm:p-6"><span class="text-soft">Reason it was lost:</span> <?= e($q['lost_reason']) ?></p><?php endif; ?>
  </section>

  <div class="space-y-6">
    <section class="rise rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="out-h">
      <h2 id="out-h" class="text-lg font-bold tracking-tight">Outcome</h2>
      <div class="mt-4 space-y-3">
        <?php if ($q['state'] === 'expired'): ?><p class="text-sm text-soft">This quote has expired and counts as lost. Edit it and set a new "valid until" date to offer it again.</p><?php endif; ?>
        <?php if ($q['status'] === 'draft' && $q['state'] !== 'expired'): ?><?= $post('sent', icon('send', 'h-4 w-4') . 'Mark as sent', $btn) ?><?php endif; ?>
        <?php if ($open): ?>
          <?= $post('accepted', icon('check', 'h-4 w-4') . 'Accepted by the customer', 'pressable inline-flex h-12 w-full items-center justify-center gap-2 rounded-lg bg-lime text-sm font-bold text-navy hover:brightness-95') ?>
          <details><summary class="pressable inline-flex h-11 w-full cursor-pointer list-none items-center justify-center rounded-lg border border-line text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Declined by the customer</summary>
            <form method="post" class="mt-3 space-y-3 rounded-lg border border-line bg-deep p-4"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="declined">
              <div><label for="reason" class="text-sm font-semibold">Why was it lost? <span class="font-normal text-soft">(optional)</span></label><input id="reason" name="reason" maxlength="200" placeholder="e.g. price, chose another supplier" class="<?= $field ?> mt-1.5"></div>
              <button class="pressable h-10 rounded-lg bg-[#FFD3CC] px-5 text-sm font-bold text-[#7E160A] hover:brightness-95">Mark as declined</button></form></details>
        <?php endif; ?>
        <?php if (in_array($q['status'], ['accepted', 'declined'], true)): ?>
          <p class="text-sm text-soft"><?= e(quote_state_text($q)) ?>.</p>
          <?php if (!$converted): ?><?= $post('draft', 'Reopen as a draft', $btn) ?><?php endif; ?>
        <?php endif; ?>
        <?php if (quote_editable($q)): ?><a href="quotes/quote_edit.php?id=<?= $id ?>" class="<?= $btn ?>">Edit quote</a><?php endif; ?>
      </div>
    </section>

    <?php if (($canInvoice || $canContract) && $q['status'] !== 'declined'): ?>
    <section class="rise rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.1s" aria-labelledby="next-h">
      <h2 id="next-h" class="text-lg font-bold tracking-tight">Turn it into</h2>
      <p class="mt-1 text-sm text-soft">Either step also marks the quote as accepted.</p>
      <div class="mt-4 space-y-3">
        <?php if ($canInvoice): ?>
          <?php if ($q['invoice_id'] !== null): ?>
            <p class="text-sm"><?= $exists('invoices', $q['invoice_id']) ? '<a href="invoices/invoice.php?id=' . (int) $q['invoice_id'] . '" class="font-semibold underline decoration-lime decoration-2 underline-offset-4">The invoice made from this quote</a>' : '<span class="text-soft">An invoice was made from this quote and has since been removed.</span>' ?></p>
          <?php elseif ($q['state'] !== 'expired'): ?>
            <?= $post('to_invoice', icon('card', 'h-4 w-4') . 'An invoice', $btn) ?><p class="text-xs text-soft">The lines are billed once, dated today, payable in 14 days.</p>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($canContract): ?>
          <?php if ($q['contract_id'] !== null): ?>
            <p class="text-sm"><?= $exists('contracts', $q['contract_id']) ? '<a href="contracts/contract.php?id=' . (int) $q['contract_id'] . '" class="font-semibold underline decoration-lime decoration-2 underline-offset-4">The contract made from this quote</a>' : '<span class="text-soft">A contract was made from this quote and has since been removed.</span>' ?></p>
          <?php elseif ($q['state'] !== 'expired'): ?>
            <?= $post('to_contract', icon('contract', 'h-4 w-4') . 'A contract', $btn) ?><p class="text-xs text-soft">The lines become the monthly services of a draft contract, which you check before it starts.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if (!$converted): ?>
    <details class="rise rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6"><summary class="cursor-pointer text-sm font-semibold text-soft hover:text-ink">Delete this quote</summary>
      <p class="mt-3 text-sm text-soft">This removes the quote and its lines for good.</p>
      <div class="mt-3"><?= $post('delete', 'Yes, delete the quote', 'pressable h-10 rounded-lg bg-[#FFD3CC] px-5 text-sm font-bold text-[#7E160A] hover:brightness-95') ?></div></details>
    <?php endif; ?>
  </div>
</div>
<?php page_end(); ?>
