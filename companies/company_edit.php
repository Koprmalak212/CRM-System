<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/companies.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$c = null;
if ($id > 0) { $c = company_find($pdo, $id) ?? company_not_found(); }

$errors = [];
$v = $c ?? ['name' => '', 'address' => '', 'postcode' => '', 'city' => '', 'country' => 'Netherlands', 'kvk' => '', 'vat_number' => '', 'email' => '', 'phone' => '', 'website' => '', 'notes' => ''];
$userCount = 0;
if ($c) {
    $uc = $pdo->prepare('SELECT COUNT(*) FROM users WHERE company_id = ?');
    $uc->execute([$id]);
    $userCount = (int) $uc->fetchColumn();
}
$confirmDelete = $c && ($_GET['delete'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete' && $c) {
        // A company with people attached is never removed by accident: move or remove its users first.
        if ($userCount > 0) {
            flash($c['name'] . ' still has ' . $userCount . ' user' . ($userCount === 1 ? '' : 's') . '. Move or remove them before deleting the company.', 'error');
            redirect('companies/company.php?id=' . $id);
        }
        $pdo->prepare("DELETE FROM notes WHERE about_type = 'company' AND about_id = ?")->execute([$id]);
        $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$id]);   // its invoices stay; they simply lose the link
        log_activity('warning', 'Company deleted', $c['name']);
        flash($c['name'] . ' was deleted. Its invoices were kept.');
        redirect('companies/companies.php');
    }
    [$v, $errors] = company_clean($pdo, $_POST, $id);
    if (!$errors) {
        $cols = ['name', 'address', 'postcode', 'city', 'country', 'kvk', 'vat_number', 'email', 'phone', 'website', 'notes'];
        $vals = array_map(fn($f) => $v[$f], $cols);
        if ($c) {
            $pdo->prepare('UPDATE companies SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?')->execute([...$vals, $id]);
            log_activity('info', 'Company updated', $v['name']);
            flash($v['name'] . ' was saved.');
        } else {
            $pdo->prepare('INSERT INTO companies (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute($vals);
            $id = (int) $pdo->lastInsertId();
            log_activity('info', 'Company added', $v['name']);
            flash($v['name'] . ' was added.');
        }
        redirect('companies/company.php?id=' . $id);
    }
}

page_start($c ? 'Edit ' . $c['name'] : 'Add company', 'companies', $c ? 'Change the details, or delete the company.' : 'Add the business details once; users and invoices can then be linked to it.');
$field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
$in = fn(string $name, string $label, string $extra = '', string $hint = '') => '<div' . ($extra === 'wide' ? ' class="sm:col-span-2"' : '') . '><label for="' . $name . '" class="text-sm font-semibold">' . e($label) . '</label><input id="' . $name . '" name="' . $name . '" value="' . e($v[$name]) . '" class="' . $field . ' mt-1.5"' . ($name === 'name' ? ' required' : '') . ($name === 'email' ? ' type="email"' : '') . '>' . ($hint !== '' ? '<p class="mt-1 text-xs text-soft">' . e($hint) . '</p>' : '') . '</div>';
?>
<a href="<?= $c ? 'companies/company.php?id=' . $id : 'companies/companies.php' ?>" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?><?= $c ? 'Back to ' . e($c['name']) : 'All companies' ?></a>

<?php if ($errors): ?>
  <div role="alert" class="mb-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]">
    <p class="font-semibold">This company can not be saved yet:</p>
    <ul class="mt-1 list-disc pl-5"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" class="rise max-w-3xl rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" novalidate><?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="save">
  <div class="grid gap-4 sm:grid-cols-2">
    <?= $in('name', 'Company name', 'wide') ?>
    <?= $in('address', 'Street and number', 'wide') ?>
    <?= $in('postcode', 'Postcode') ?>
    <?= $in('city', 'City') ?>
    <?= $in('country', 'Country') ?>
    <?= $in('kvk', 'KvK number', '', '8 digits') ?>
    <?= $in('vat_number', 'VAT number', '', 'For example NL123456789B01') ?>
    <?= $in('email', 'Email') ?>
    <?= $in('phone', 'Phone') ?>
    <?= $in('website', 'Website') ?>
    <div class="sm:col-span-2"><label for="notes" class="text-sm font-semibold">Notes</label>
      <textarea id="notes" name="notes" rows="3" maxlength="1000" class="mt-1.5 w-full rounded-xl border border-line bg-panel px-3 py-2 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"><?= e($v['notes']) ?></textarea></div>
  </div>
  <div class="mt-6 flex flex-wrap gap-3">
    <button class="pressable h-12 rounded-xl bg-lime px-6 text-[15px] font-bold text-navy hover:brightness-95"><?= $c ? 'Save changes' : 'Add company' ?></button>
    <a href="<?= $c ? 'companies/company.php?id=' . $id : 'companies/companies.php' ?>" class="inline-flex h-12 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-butter-50">Cancel</a>
  </div>
</form>

<?php if ($c): ?>
<section class="rise mt-6 max-w-3xl rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="del-h">
  <h2 id="del-h" class="text-base font-bold">Delete this company</h2>
  <?php if ($userCount > 0): ?>
    <p class="mt-2 text-sm text-soft">This company still has <?= $userCount ?> user<?= $userCount === 1 ? '' : 's' ?>, so it can not be deleted. Move or remove the users first.</p>
  <?php elseif ($confirmDelete): ?>
    <p role="alert" class="mt-2 text-sm font-semibold text-[#FF9C8C]">Delete <?= e($c['name']) ?> for good? Its invoices are kept, but lose the link to this company.</p>
    <form method="post" class="mt-4 flex flex-wrap gap-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete">
      <button class="pressable h-11 rounded-xl bg-[#FFD3CC] px-5 text-sm font-bold text-[#7E160A] hover:brightness-95">Yes, delete the company</button>
      <a href="companies/company_edit.php?id=<?= $id ?>" class="inline-flex h-11 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-butter-50">Keep it</a></form>
  <?php else: ?>
    <p class="mt-2 text-sm text-soft">Only possible while no users belong to it. You will be asked to confirm.</p>
    <a href="companies/company_edit.php?id=<?= $id ?>&amp;delete=1" class="mt-4 inline-flex h-11 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Delete company…</a>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php page_end(); ?>
