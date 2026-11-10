<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/tickets.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();

$errors = [];
$old = ['subject' => '', 'description' => '', 'priority' => 'normal', 'company_id' => (int) ($_GET['company'] ?? 0), 'user_id' => (int) ($_GET['user'] ?? 0), 'assignee_id' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['subject', 'description', 'priority'] as $f) { $old[$f] = (string) ($_POST[$f] ?? ''); }
    foreach (['company_id', 'user_id', 'assignee_id'] as $f) { $old[$f] = (int) ($_POST[$f] ?? 0); }
    [$id, $errors] = ticket_create($pdo, $old);
    if ($id > 0) {
        flash('Ticket ' . ticket_reference($id) . ' opened.');
        redirect('ticket.php?id=' . $id);
    }
}

$companies = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$users = $pdo->query('SELECT u.id, u.name, u.company_id, c.name AS company FROM users u LEFT JOIN companies c ON c.id = u.company_id ORDER BY u.name LIMIT 500')->fetchAll();
$assignees = ticket_assignees($pdo);
page_start('New ticket', 'tickets', 'Log a support request. Choose a company, a person, or both.');
$field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<a href="tickets.php" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?>All tickets</a>
<?php if ($errors): ?>
  <div role="alert" class="mb-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]">
    <p class="font-semibold">This ticket can not be saved yet:</p>
    <ul class="mt-1 list-disc pl-5"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" class="rise max-w-3xl rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" novalidate><?= csrf_field() ?>
  <div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2"><label for="subject" class="text-sm font-semibold">Subject</label>
      <input id="subject" name="subject" maxlength="160" required value="<?= e($old['subject']) ?>" class="<?= $field ?> mt-1.5"></div>
    <div class="sm:col-span-2"><label for="description" class="text-sm font-semibold">Description</label>
      <textarea id="description" name="description" rows="5" maxlength="2000" required class="mt-1.5 w-full rounded-xl border border-line bg-panel px-3 py-2 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"><?= e($old['description']) ?></textarea></div>
    <div><label for="company_id" class="text-sm font-semibold">Company</label>
      <select id="company_id" name="company_id" class="<?= $field ?> mt-1.5"><option value="0">No company</option><?php foreach ($companies as $cid => $cn): ?><option value="<?= (int) $cid ?>" <?= $old['company_id'] === (int) $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></div>
    <div><label for="user_id" class="text-sm font-semibold">Contact person <span class="font-normal text-soft">(optional)</span></label>
      <select id="user_id" name="user_id" class="<?= $field ?> mt-1.5"><option value="0">Nobody in particular</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $old['user_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name'] . ($u['company'] !== null ? ' · ' . $u['company'] : '')) ?></option><?php endforeach; ?></select></div>
    <div><label for="priority" class="text-sm font-semibold">Priority</label>
      <select id="priority" name="priority" class="<?= $field ?> mt-1.5"><?php foreach (TICKET_PRIORITIES as $k => $l): ?><option value="<?= $k ?>" <?= $old['priority'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label for="assignee_id" class="text-sm font-semibold">Assign to</label>
      <select id="assignee_id" name="assignee_id" class="<?= $field ?> mt-1.5"><option value="0">Unassigned</option><?php foreach ($assignees as $aid => $an): ?><option value="<?= (int) $aid ?>" <?= $old['assignee_id'] === (int) $aid ? 'selected' : '' ?>><?= e($an) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="mt-6 flex flex-wrap gap-3">
    <button class="pressable h-12 rounded-xl bg-lime px-6 text-[15px] font-bold text-navy hover:brightness-95">Open ticket</button>
    <a href="tickets.php" class="inline-flex h-12 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-butter-50">Cancel</a>
  </div>
</form>
<?php page_end(); ?>
