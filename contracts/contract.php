<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/contracts.php';
require __DIR__ . '/../includes/modules/sla.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$c = contract_find($pdo, $id) ?? contract_not_found();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'activate' && $c['status'] === 'draft') {
        $pdo->prepare("UPDATE contracts SET status = 'active' WHERE id = ? AND status = 'draft'")->execute([$id]);
        log_activity('info', 'Contract activated', $c['number'] . ' · ' . $c['company_name']);
        flash('Contract ' . $c['number'] . ' is active now.');
    } elseif ($action === 'end' && $c['status'] !== 'ended') {
        // ending by hand: the end date becomes today when it was later or open, and nothing more is invoiced
        $pdo->prepare("UPDATE contracts SET status = 'ended', ended_at = NOW(), auto_renew = 0, end_date = LEAST(COALESCE(end_date, CURDATE()), CURDATE()) WHERE id = ?")->execute([$id]);
        log_activity('warning', 'Contract ended', $c['number'] . ' · ' . $c['company_name']);
        flash('Contract ' . $c['number'] . ' was ended. Invoices it already made were kept.');
    } elseif ($action === 'renew' && $c['status'] === 'active' && $c['end_date'] !== null) {
        $end = (new DateTimeImmutable((string) $c['end_date']))->modify('+' . max(1, (int) $c['renew_months']) . ' month')->format('Y-m-d');
        $pdo->prepare('UPDATE contracts SET end_date = ? WHERE id = ?')->execute([$end, $id]);
        log_activity('info', 'Contract renewed', $c['number'] . ' · ' . $c['company_name'] . ' · until ' . $end);
        flash('Renewed until ' . date('j F Y', strtotime($end)) . '.');
    } elseif ($action === 'delete' && $c['status'] !== 'active') {
        $pdo->prepare('DELETE FROM contracts WHERE id = ?')->execute([$id]);   // its invoices stay, without the link
        log_activity('warning', 'Contract deleted', $c['number'] . ' · ' . $c['company_name']);
        flash('Contract ' . $c['number'] . ' was deleted. Invoices it made were kept.');
        redirect('contracts/contracts.php');
    } else {
        flash('That is not possible for this contract.', 'error');
    }
    redirect('contracts/contract.php?id=' . $id);
}

$items = contract_items($pdo, $id);
$invoices = [];
if (module_enabled('invoices')) {
    $s = $pdo->prepare('SELECT * FROM (' . invoice_base_sql() . ') t WHERE contract_id = ? ORDER BY issue_date DESC, id DESC LIMIT 24');
    $s->execute([$id]);
    $invoices = $s->fetchAll();
}
$months = CONTRACT_MONTHS[$c['billing']] ?? 0;
$due = (int) $c['renewal_due'] === 1;
$askDelete = isset($_GET['delete']) && $c['status'] !== 'active';

page_start($c['title'], 'contracts', $c['number'] . ' · ' . $c['company_name']);
?>
<a href="contracts/contracts.php" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?>All contracts</a>

<?php if ($due): ?>
  <div role="status" class="rise mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-butter/30 bg-butter-100 px-5 py-4 text-sm">
    <p><strong class="font-semibold text-butter"><?= (int) $c['auto_renew'] === 1 ? 'This contract renews' : 'This contract ends' ?> on <?= e(date('j F Y', strtotime($c['end_date']))) ?>.</strong> <?= (int) $c['auto_renew'] === 1 ? 'It goes on for another ' . (int) $c['renew_months'] . ' months unless it is ended first.' : 'Agree a renewal with the customer, or it stops that day.' ?></p>
    <?php if ((int) $c['auto_renew'] !== 1): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="renew"><button class="pressable h-10 rounded-lg bg-lime px-4 text-sm font-bold text-navy hover:brightness-95">Renew for <?= (int) $c['renew_months'] ?> months</button></form><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($askDelete): ?>
  <section class="rise mb-5 rounded-xl border border-line bg-panel p-5 shadow-panel" role="alert" aria-labelledby="del-h">
    <h2 id="del-h" class="text-base font-bold">Delete this contract?</h2>
    <p class="mt-1 text-sm text-soft"><?= e($c['title']) ?> for <?= e($c['company_name']) ?>. The <?= count($invoices) ?> invoice<?= count($invoices) === 1 ? '' : 's' ?> it made stay.</p>
    <form method="post" class="mt-4 flex flex-wrap gap-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete">
      <button class="pressable h-11 rounded-lg bg-[#FFD3CC] px-5 text-sm font-bold text-[#7E160A] hover:brightness-95">Yes, delete the contract</button>
      <a href="contracts/contract.php?id=<?= $id ?>" class="inline-flex h-11 items-center rounded-lg border border-line px-5 text-sm font-semibold hover:bg-butter-50">Keep it</a></form>
  </section>
<?php endif; ?>

<div class="grid items-start gap-6 xl:grid-cols-[1.6fr_1fr]">
  <div class="space-y-6">
    <section class="rise rounded-xl border border-line bg-panel shadow-panel" aria-labelledby="svc-h">
      <div class="flex flex-wrap items-start justify-between gap-4 border-b border-line p-5 sm:p-6">
        <div><h2 id="svc-h" class="text-lg font-bold tracking-tight">Services</h2>
          <p class="text-sm text-soft">For <a href="companies/company.php?id=<?= (int) $c['company_id'] ?>" class="font-semibold text-ink underline decoration-lime decoration-2 underline-offset-4"><?= e($c['company_name']) ?></a></p></div>
        <div class="text-right"><?= $due ? badge('due_renewal') : badge($c['status']) ?>
          <p class="mt-2 text-2xl font-bold leading-none tracking-tight tabular-nums notranslate"><?= e(money((int) $c['monthly_cents'])) ?></p><p class="mt-1 text-sm text-soft">a month, excl. BTW</p></div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-left text-sm">
          <thead class="text-xs text-soft"><tr><th scope="col" class="px-5 py-3 font-semibold sm:px-6">Service</th><th scope="col" class="px-3 py-3 font-semibold">Description</th><th scope="col" class="px-3 py-3 text-right font-semibold">Qty</th><th scope="col" class="px-3 py-3 text-right font-semibold">Per unit, a month</th><th scope="col" class="px-5 py-3 text-right font-semibold sm:px-6">A month</th></tr></thead>
          <tbody class="divide-y divide-line border-t border-line">
            <?php foreach ($items as $it): ?>
              <tr><td class="px-5 py-3 text-soft sm:px-6"><?= e(CONTRACT_SERVICES[$it['service']] ?? 'Other') ?></td><td class="px-3 py-3 notranslate"><?= e($it['description']) ?></td>
                <td class="px-3 py-3 text-right tabular-nums"><?= e(qty_text($it['qty'])) ?></td>
                <td class="px-3 py-3 text-right tabular-nums notranslate"><?= e(money((int) $it['unit_cents'])) ?></td>
                <td class="px-5 py-3 text-right font-semibold tabular-nums notranslate sm:px-6"><?= e(money((int) round((float) $it['qty'] * (int) $it['unit_cents']))) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($c['notes'] !== ''): ?><p class="whitespace-pre-line border-t border-line p-5 text-sm text-soft sm:p-6"><?= e($c['notes']) ?></p><?php endif; ?>
    </section>

    <?php if (module_enabled('invoices')): ?>
    <section class="rise rounded-xl border border-line bg-panel shadow-panel" style="animation-delay:.05s" aria-labelledby="inv-h">
      <div class="p-5 pb-3 sm:px-6"><h2 id="inv-h" class="text-lg font-bold tracking-tight">Invoices from this contract</h2></div>
      <?php if (!$invoices): ?><p class="px-5 pb-5 text-sm text-soft sm:px-6"><?= $c['billing'] === 'none' ? 'This contract is not invoiced from here.' : 'None yet. They appear here as they are created.' ?></p>
      <?php else: ?>
        <ul class="divide-y divide-line">
          <?php foreach ($invoices as $i): ?>
            <li class="flex items-center justify-between gap-3 px-5 py-3 sm:px-6"><div><a href="invoices/invoice.php?id=<?= (int) $i['id'] ?>" class="text-sm font-semibold underline decoration-lime/60 decoration-2 underline-offset-4 hover:decoration-lime notranslate"><?= e($i['number']) ?></a><p class="text-xs text-soft"><?= e(date('j M Y', strtotime($i['issue_date']))) ?></p></div>
              <div class="flex items-center gap-3"><span class="tabular-nums notranslate"><?= e(money((int) $i['total_cents'])) ?></span><?= badge($i['state']) ?></div></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>

  <div class="space-y-6">
    <section class="rise rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.05s" aria-labelledby="term-h">
      <h2 id="term-h" class="text-lg font-bold tracking-tight">Term and invoicing</h2>
      <dl class="mt-4 space-y-3 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-soft">Started</dt><dd class="font-semibold"><?= e(date('j M Y', strtotime($c['start_date']))) ?></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-soft"><?= (int) $c['auto_renew'] === 1 ? 'Renews on' : 'Ends on' ?></dt><dd class="text-right font-semibold"><?= $c['end_date'] !== null ? e(date('j M Y', strtotime($c['end_date']))) : 'No end date' ?><span class="block text-xs font-normal text-soft"><?= e(contract_end_text($c)) ?></span></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-soft">Renewal</dt><dd class="text-right font-semibold"><?= (int) $c['auto_renew'] === 1 ? 'By itself, ' . (int) $c['renew_months'] . ' months at a time' : 'Not automatic' ?></dd></div>
        <?php if ($c['end_date'] !== null): ?><div class="flex justify-between gap-4"><dt class="text-soft">Alert</dt><dd class="font-semibold"><?= (int) $c['notice_days'] ?> days before</dd></div><?php endif; ?>
        <div class="flex justify-between gap-4 border-t border-line pt-3"><dt class="text-soft">Invoiced</dt><dd class="text-right font-semibold"><?= e(CONTRACT_BILLING[$c['billing']]) ?></dd></div>
        <?php if ($months > 0): ?>
          <div class="flex justify-between gap-4"><dt class="text-soft">Per invoice</dt><dd class="text-right font-semibold tabular-nums notranslate"><?= e(money((int) $c['monthly_cents'] * $months)) ?><span class="block text-xs font-normal text-soft">+ <?= (int) $c['vat_rate'] ?>% BTW, <?= (int) $c['terms_days'] ?> days to pay</span></dd></div>
          <div class="flex justify-between gap-4"><dt class="text-soft">Next invoice</dt><dd class="font-semibold"><?= $c['status'] === 'active' && $c['next_invoice_date'] !== null ? e(date('j M Y', strtotime($c['next_invoice_date']))) : 'None' ?></dd></div>
        <?php endif; ?>
        <?php if ($c['sla_respond_min'] !== null || $c['sla_resolve_min'] !== null): ?>
          <div class="flex justify-between gap-4 border-t border-line pt-3"><dt class="text-soft">SLA of this contract</dt><dd class="text-right font-semibold"><?= $c['sla_respond_min'] !== null ? 'Respond within ' . e(sla_fmt_minutes((int) $c['sla_respond_min'])) : '' ?><?= $c['sla_respond_min'] !== null && $c['sla_resolve_min'] !== null ? '<br>' : '' ?><?= $c['sla_resolve_min'] !== null ? 'Resolve within ' . e(sla_fmt_minutes((int) $c['sla_resolve_min'])) : '' ?></dd></div>
        <?php endif; ?>
        <?php if ($c['quote_id'] !== null && module_enabled('quotes')): ?><div class="flex justify-between gap-4 border-t border-line pt-3"><dt class="text-soft">Made from</dt><dd><a href="quotes/quote.php?id=<?= (int) $c['quote_id'] ?>" class="font-semibold underline decoration-lime decoration-2 underline-offset-4">the quote</a></dd></div><?php endif; ?>
      </dl>
    </section>

    <section class="rise rounded-xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.1s" aria-labelledby="act-h">
      <h2 id="act-h" class="text-lg font-bold tracking-tight">Actions</h2>
      <div class="mt-4 space-y-3">
        <?php if ($c['status'] === 'draft'): ?>
          <p class="text-sm text-soft">A draft is not invoiced and raises no alerts. Check the dates and the lines, then make it active.</p>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="activate"><button class="pressable inline-flex h-12 w-full items-center justify-center gap-2 rounded-lg bg-lime text-sm font-bold text-navy hover:brightness-95"><?= icon('check', 'h-4 w-4') ?>Make active</button></form>
        <?php endif; ?>
        <a href="contracts/contract_edit.php?id=<?= $id ?>" class="pressable inline-flex h-11 w-full items-center justify-center rounded-lg border border-line text-sm font-semibold hover:bg-lime hover:text-navy"><?= $c['status'] === 'ended' ? 'Edit and reopen' : 'Edit contract' ?></a>
        <?php if ($c['status'] !== 'ended'): ?>
          <details><summary class="pressable inline-flex h-11 w-full cursor-pointer list-none items-center justify-center rounded-lg border border-line text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">End contract</summary>
            <div class="mt-3 rounded-lg border border-line bg-deep p-4"><p class="text-sm">The contract stops today: no more invoices, no renewal. Invoices it already made are kept.</p>
              <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="end"><button class="pressable h-10 rounded-lg bg-[#FFD3CC] px-5 text-sm font-bold text-[#7E160A] hover:brightness-95">Yes, end it today</button></form></div></details>
        <?php endif; ?>
        <?php if ($c['status'] !== 'active'): ?><a href="contracts/contract.php?id=<?= $id ?>&amp;delete=1" class="inline-flex h-11 w-full items-center justify-center rounded-lg border border-line text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Delete</a><?php endif; ?>
      </div>
    </section>
  </div>
</div>
<?php page_end(); ?>
