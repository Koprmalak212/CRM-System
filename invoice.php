<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/invoices.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

function load_invoice(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT * FROM (' . invoice_base_sql() . ') t WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

$inv = load_invoice($pdo, $id);
if (!$inv) {
    http_response_code(404);
    page_start('Invoice not found', 'invoices', 'That invoice does not exist. It may have been removed.');
    echo '<a href="invoices.php" class="pressable inline-flex h-11 items-center rounded-xl bg-lime px-5 text-sm font-bold text-navy">Back to invoices</a>';
    page_end();
    exit;
}

$methods = ['iDEAL', 'Bank transfer', 'Credit card', 'Direct debit'];
$payErrors = [];
$form = ['amount' => '', 'method' => 'iDEAL', 'paid_at' => date('Y-m-d'), 'reference' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'pay') {
        $form['amount'] = trim((string) ($_POST['amount'] ?? ''));
        $form['method'] = (string) ($_POST['method'] ?? '');
        $form['paid_at'] = (string) ($_POST['paid_at'] ?? '');
        $form['reference'] = trim((string) ($_POST['reference'] ?? ''));
        $cents = parse_money($form['amount']);
        $balance = (int) $inv['balance_cents'];
        if ($balance <= 0) $payErrors[] = 'This invoice is already paid in full.';
        elseif ($cents === null) $payErrors[] = 'Enter the amount received, for example 149,00.';
        elseif ($cents > $balance) $payErrors[] = 'That is more than the balance of ' . money($balance) . '. Enter the amount actually received up to the balance.';
        if (!in_array($form['method'], $methods, true)) $payErrors[] = 'Choose how it was paid.';
        if (!valid_date($form['paid_at']) || $form['paid_at'] > date('Y-m-d') || $form['paid_at'] < '2000-01-01') $payErrors[] = 'Choose the payment date. It can not be in the future.';
        if (!$payErrors) {
            // Lock the invoice row and re-check the balance inside the transaction, so two simultaneous
            // submissions (double click, replayed request) can never record more than the amount due.
            $ref = $form['reference'] !== '' ? mb_substr($form['reference'], 0, 60) : 'PAY-' . random_int(100000, 999999);
            $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare('SELECT total_cents - COALESCE((SELECT SUM(amount_cents) FROM payments WHERE invoice_id = ?), 0) FROM invoices WHERE id = ? FOR UPDATE');
                $lock->execute([$id, $id]);
                $fresh = (int) $lock->fetchColumn();
                if ($cents > $fresh) {
                    $pdo->rollBack();
                    $payErrors[] = $fresh <= 0 ? 'This invoice was just paid in full, so no payment was added.' : 'The balance changed to ' . money($fresh) . ' while you were submitting. Check the amount and try again.';
                } else {
                    $pdo->prepare('INSERT INTO payments (invoice_id, amount_cents, method, paid_at, reference, created_at) VALUES (?,?,?,?,?,NOW())')
                        ->execute([$id, $cents, $form['method'], $form['paid_at'], $ref]);
                    $pdo->commit();
                    log_activity('info', 'Payment recorded', $inv['number'] . ' · ' . money($cents));
                    $after = $fresh - $cents;
                    flash($after <= 0 ? 'Payment recorded. ' . $inv['number'] . ' is paid in full.' : 'Payment of ' . money($cents) . ' recorded. ' . money($after) . ' still due.');
                    redirect('invoice.php?id=' . $id);
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
        }
    } elseif ($action === 'remind' && in_array($inv['state'], ['overdue', 'due'], true)) {
        log_activity('warning', 'Payment reminder sent', $inv['number'] . ' · ' . $inv['email']);
        flash('Reminder for ' . $inv['number'] . ' sent to ' . $inv['email'] . '.');
        redirect('invoice.php?id=' . $id);
    }
}

$items = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id');
$items->execute([$id]);
$items = $items->fetchAll();
$pays = $pdo->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY paid_at DESC, id DESC');
$pays->execute([$id]);
$pays = $pays->fetchAll();
if ($form['amount'] === '' && (int) $inv['balance_cents'] > 0) {
    $form['amount'] = number_format((int) $inv['balance_cents'] / 100, 2, ',', '');
}
$remaining = (int) $inv['balance_cents'];
$company = require __DIR__ . '/includes/company.php';
$field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';

page_start($inv['number'], 'invoices', $inv['customer']);
?>
<a href="invoices.php" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?>All invoices</a>

<div class="grid items-start gap-6 xl:grid-cols-[1.6fr_1fr]">
  <section class="rise rounded-2xl border border-line bg-panel shadow-panel" aria-labelledby="doc-h">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-line p-5 sm:p-6">
      <div>
        <h2 id="doc-h" class="sr-only">Invoice <?= e($inv['number']) ?></h2>
        <p class="text-sm text-soft">Billed to</p>
        <p class="text-lg font-bold"><?php if ($inv['company_id'] !== null): ?><a href="company.php?id=<?= (int) $inv['company_id'] ?>" class="underline decoration-lime decoration-2 underline-offset-4"><?= e($inv['customer']) ?></a><?php else: ?><?= e($inv['customer']) ?><?php endif; ?></p>
        <p class="text-sm text-soft"><?= e($inv['email']) ?></p>
        <?php if ($inv['customer_address'] !== ''): ?><p class="text-sm text-soft"><?= e($inv['customer_address']) ?></p><?php else: ?><p class="text-sm font-semibold text-[#FF9C8C]">Customer address missing. Dutch invoices must show it.</p><?php endif; ?>
      </div>
      <div class="text-right">
        <?= badge($inv['state']) ?>
        <p class="mt-2"><a href="invoice_pdf.php?id=<?= (int) $inv['id'] ?>" class="pressable inline-flex h-10 items-center gap-2 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy"><?= icon('download', 'h-4 w-4') ?>Download PDF</a></p>
        <p class="mt-2 text-sm font-semibold <?= $inv['state'] === 'overdue' ? 'text-[#FF9C8C]' : 'text-soft' ?>"><?= e(invoice_state_text($inv)) ?></p>
      </div>
    </div>
    <div class="grid gap-4 border-b border-line p-5 text-sm sm:grid-cols-[1fr_auto] sm:p-6">
      <div><p class="text-soft">From</p><p class="font-bold"><?= e($company['name']) ?></p><p class="text-soft"><?= e(implode(', ', $company['address_lines'])) ?></p><p class="text-soft"><?= e($company['email']) ?> · <?= e($company['phone']) ?></p></div>
      <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 notranslate"><dt class="text-soft">KvK</dt><dd class="font-semibold tabular-nums"><?= e($company['kvk']) ?></dd><dt class="text-soft">BTW-id</dt><dd class="font-semibold tabular-nums"><?= $company['btw'] !== '' ? e($company['btw']) : '<span class="font-normal text-soft">not set</span>' ?></dd><dt class="text-soft">IBAN</dt><dd class="font-semibold tabular-nums"><?= $company['iban'] !== '' ? e($company['iban']) : '<span class="font-normal text-soft">not set</span>' ?></dd></dl>
    </div>
    <?php if (!empty($company['btw_is_placeholder'])): ?>
      <p role="alert" class="border-b border-line bg-coral/10 px-5 py-3 text-sm font-semibold text-[#FF9C8C] sm:px-6">The supplier BTW number and/or IBAN are not filled in yet. They are left off the PDF until you enter them under <?= is_admin() ? '<a class="underline" href="settings.php?tab=company">Settings &gt; Company details</a>' : 'Settings &gt; Company details (ask an admin)' ?>.</p>
    <?php endif; ?>
    <dl class="grid grid-cols-2 gap-4 border-b border-line p-5 text-sm sm:grid-cols-5 sm:p-6">
      <div><dt class="text-soft">Invoice</dt><dd class="mt-0.5 font-semibold tabular-nums"><?= e($inv['number']) ?></dd></div>
      <div><dt class="text-soft">Issued</dt><dd class="mt-0.5 font-semibold"><?= e(date('j M Y', strtotime($inv['issue_date']))) ?></dd></div>
      <div><dt class="text-soft">Supply date</dt><dd class="mt-0.5 font-semibold"><?= e(date('j M Y', strtotime($inv['supply_date'] ?? $inv['issue_date']))) ?></dd></div>
      <div><dt class="text-soft">Due</dt><dd class="mt-0.5 font-semibold"><?= e(date('j M Y', strtotime($inv['due_date']))) ?></dd></div>
      <div><dt class="text-soft">BTW</dt><dd class="mt-0.5 font-semibold"><?= (int) $inv['vat_rate'] ?>%</dd></div>
    </dl>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[520px] text-left text-sm">
        <thead class="text-xs text-soft"><tr><th scope="col" class="px-5 py-3 font-semibold sm:px-6">Description</th><th scope="col" class="px-3 py-3 text-right font-semibold">Qty</th><th scope="col" class="px-3 py-3 text-right font-semibold">Unit price</th><th scope="col" class="px-5 py-3 text-right font-semibold sm:px-6">Amount</th></tr></thead>
        <tbody class="divide-y divide-line border-t border-line notranslate">
          <?php foreach ($items as $it): ?>
            <tr><td class="px-5 py-3 sm:px-6"><?= e($it['description']) ?></td>
              <td class="px-3 py-3 text-right tabular-nums"><?= e(rtrim(rtrim(number_format((float) $it['qty'], 2, ',', '.'), '0'), ',')) ?></td>
              <td class="px-3 py-3 text-right tabular-nums"><?= e(money((int) $it['unit_cents'])) ?></td>
              <td class="px-5 py-3 text-right font-semibold tabular-nums sm:px-6"><?= e(money((int) round((float) $it['qty'] * (int) $it['unit_cents']))) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <dl class="ml-auto w-full max-w-xs space-y-2 border-t border-line p-5 text-sm sm:p-6 notranslate">
      <div class="flex justify-between"><dt class="text-soft">Subtotal</dt><dd class="tabular-nums"><?= e(money((int) $inv['subtotal_cents'])) ?></dd></div>
      <div class="flex justify-between"><dt class="text-soft">BTW <?= (int) $inv['vat_rate'] ?>%</dt><dd class="tabular-nums"><?= e(money((int) $inv['vat_cents'])) ?></dd></div>
      <div class="flex justify-between border-t border-line pt-2 text-base font-bold"><dt>Total</dt><dd class="tabular-nums"><?= e(money((int) $inv['total_cents'])) ?></dd></div>
      <div class="flex justify-between"><dt class="text-soft">Paid</dt><dd class="tabular-nums"><?= e(money((int) $inv['paid_cents'])) ?></dd></div>
      <div class="flex justify-between text-base font-bold"><dt>Balance due</dt><dd class="tabular-nums <?= $remaining > 0 ? ($inv['state'] === 'overdue' ? 'text-[#FF9C8C]' : 'text-butter') : 'text-lime' ?>"><?= e(money(max(0, $remaining))) ?></dd></div>
    </dl>
    <p class="border-t border-line p-5 text-sm text-soft sm:p-6 notranslate">Please pay <?= e(money(max(0, $remaining))) ?><?= $company['iban'] !== '' ? ' to IBAN ' . e($company['iban']) . ' in the name of ' : ' to ' ?><?= e($company['name']) ?>, quoting <?= e($inv['number']) ?>, no later than <?= e(date('j F Y', strtotime($inv['due_date']))) ?>.</p>
    <?php if ($inv['notes'] !== ''): ?><p class="border-t border-line p-5 text-sm text-soft sm:p-6"><?= e($inv['notes']) ?></p><?php endif; ?>
  </section>

  <div class="space-y-6">
    <?php if ($remaining > 0): ?>
    <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="pay-h">
      <h2 id="pay-h" class="text-lg font-bold tracking-tight">Record a payment</h2>
      <p class="mt-1 text-sm text-soft">Balance due <span class="font-semibold text-ink notranslate"><?= e(money($remaining)) ?></span></p>
      <?php if ($payErrors): ?>
        <div role="alert" class="mt-4 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]"><?php foreach ($payErrors as $er): ?><p><?= e($er) ?></p><?php endforeach; ?></div>
      <?php endif; ?>
      <form method="post" class="mt-4 space-y-4" novalidate><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="pay">
        <div><label for="amount" class="text-sm font-semibold">Amount received (€)</label>
          <input id="amount" name="amount" inputmode="decimal" value="<?= e($form['amount']) ?>" class="<?= $field ?> mt-1.5 tabular-nums"></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label for="method" class="text-sm font-semibold">Method</label>
            <select id="method" name="method" class="<?= $field ?> mt-1.5"><?php foreach ($methods as $m): ?><option <?= $form['method'] === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
          <div><label for="paid_at" class="text-sm font-semibold">Received on</label>
            <input id="paid_at" name="paid_at" type="date" max="<?= date('Y-m-d') ?>" value="<?= e($form['paid_at']) ?>" class="<?= $field ?> mt-1.5"></div>
        </div>
        <div><label for="reference" class="text-sm font-semibold">Reference <span class="font-normal text-soft">(optional)</span></label>
          <input id="reference" name="reference" maxlength="60" value="<?= e($form['reference']) ?>" class="<?= $field ?> mt-1.5"></div>
        <button class="pressable inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-lime text-[15px] font-bold text-navy hover:brightness-95"><?= icon('check', 'h-4 w-4') ?>Record payment</button>
      </form>
      <?php if (in_array($inv['state'], ['overdue', 'due'], true)): ?>
        <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="remind">
          <button class="pressable inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-line text-sm font-semibold hover:bg-butter-50"><?= icon('send', 'h-4 w-4') ?>Send payment reminder</button></form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="rise rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.1s" aria-labelledby="hist-h">
      <div class="px-5 pb-2 pt-5 sm:px-6"><h2 id="hist-h" class="text-lg font-bold tracking-tight">Payments</h2></div>
      <?php if (!$pays): ?>
        <p class="px-5 pb-5 text-sm text-soft sm:px-6">No payments yet. They will be listed here as you record them.</p>
      <?php else: ?>
        <ul class="divide-y divide-line">
          <?php foreach ($pays as $p): ?>
            <li class="flex items-center justify-between gap-3 px-5 py-3 sm:px-6">
              <div><p class="text-sm font-semibold"><?= e($p['method']) ?></p><p class="text-xs text-soft"><?= e(date('j M Y', strtotime($p['paid_at']))) ?> · <span class="notranslate"><?= e($p['reference']) ?></span></p></div>
              <p class="font-semibold tabular-nums text-lime notranslate"><?= e(money((int) $p['amount_cents'])) ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php page_end(); ?>
