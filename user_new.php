<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();

$errors = [];
$v = ['name' => '', 'email' => '', 'role' => 'viewer', 'status' => 'active', 'country' => 'Netherlands', 'company_id' => (int) ($_GET['company'] ?? 0), 'job_title' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['name', 'email', 'role', 'status', 'country', 'job_title', 'phone'] as $f) { $v[$f] = trim((string) ($_POST[$f] ?? '')); }
    $v['email'] = strtolower($v['email']);
    $v['company_id'] = (int) ($_POST['company_id'] ?? 0);
    if ($v['name'] === '' || mb_strlen($v['name']) > 120) { $errors[] = 'Enter a name of up to 120 characters.'; }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['email']) > 190) { $errors[] = 'Enter a valid email address.'; }
    if (!in_array($v['role'], ['admin', 'editor', 'support', 'viewer'], true)) { $errors[] = 'Choose a role.'; }
    if (!in_array($v['status'], ['active', 'pending', 'suspended'], true)) { $errors[] = 'Choose a status.'; }
    if ($v['country'] === '' || mb_strlen($v['country']) > 60) { $errors[] = 'Enter a country of up to 60 characters.'; }
    if (mb_strlen($v['job_title']) > 80) { $errors[] = 'The job title can be at most 80 characters.'; }
    if (mb_strlen($v['phone']) > 40) { $errors[] = 'The phone number can be at most 40 characters.'; }
    if ($v['company_id'] > 0) { $c = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE id = ?'); $c->execute([$v['company_id']]); if (!(int) $c->fetchColumn()) { $errors[] = 'That company does not exist.'; } }
    if (!$errors) {
        $d = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?'); $d->execute([$v['email']]);
        if ((int) $d->fetchColumn() > 0) { $errors[] = 'A user with that email address already exists.'; }
    }
    if (!$errors) {
        $pdo->prepare('INSERT INTO users (name, email, role, status, country, company_id, job_title, phone, logins, last_seen, created_at) VALUES (?,?,?,?,?,?,?,?,0,NOW(),NOW())')
            ->execute([$v['name'], $v['email'], $v['role'], $v['status'], $v['country'], $v['company_id'] > 0 ? $v['company_id'] : null, $v['job_title'], $v['phone']]);
        $id = (int) $pdo->lastInsertId();
        log_activity('info', 'User added', $v['name'] . ' · ' . $v['role']);
        flash($v['name'] . ' was added.');
        redirect('user.php?id=' . $id);
    }
}

$companies = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
page_start('Add user', 'users', 'A person who belongs to a customer. This is not a login for the dashboard: those are under Settings > Team.');
$field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<a href="users.php" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?>All users</a>
<?php if ($errors): ?>
  <div role="alert" class="mb-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]"><p class="font-semibold">This user can not be saved yet:</p><ul class="mt-1 list-disc pl-5"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form method="post" class="rise max-w-3xl rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" novalidate><?= csrf_field() ?>
  <div class="grid gap-4 sm:grid-cols-2">
    <div><label for="name" class="text-sm font-semibold">Full name</label><input id="name" name="name" maxlength="120" required value="<?= e($v['name']) ?>" class="<?= $field ?> mt-1.5"></div>
    <div><label for="email" class="text-sm font-semibold">Email</label><input id="email" name="email" type="email" maxlength="190" required value="<?= e($v['email']) ?>" class="<?= $field ?> mt-1.5"></div>
    <div><label for="company_id" class="text-sm font-semibold">Company</label><select id="company_id" name="company_id" class="<?= $field ?> mt-1.5"><option value="0">No company</option><?php foreach ($companies as $cid => $cn): ?><option value="<?= (int) $cid ?>" <?= $v['company_id'] === (int) $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></div>
    <div><label for="job_title" class="text-sm font-semibold">Job title</label><input id="job_title" name="job_title" maxlength="80" value="<?= e($v['job_title']) ?>" class="<?= $field ?> mt-1.5"></div>
    <div><label for="role" class="text-sm font-semibold">Role</label><select id="role" name="role" class="<?= $field ?> mt-1.5"><?php foreach (['viewer', 'support', 'editor', 'admin'] as $r): ?><option value="<?= $r ?>" <?= $v['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></div>
    <div><label for="status" class="text-sm font-semibold">Status</label><select id="status" name="status" class="<?= $field ?> mt-1.5"><?php foreach (['active', 'pending', 'suspended'] as $r): ?><option value="<?= $r ?>" <?= $v['status'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></div>
    <div><label for="country" class="text-sm font-semibold">Country</label><input id="country" name="country" maxlength="60" value="<?= e($v['country']) ?>" class="<?= $field ?> mt-1.5"></div>
    <div><label for="phone" class="text-sm font-semibold">Phone</label><input id="phone" name="phone" maxlength="40" value="<?= e($v['phone']) ?>" class="<?= $field ?> mt-1.5"></div>
  </div>
  <div class="mt-6 flex flex-wrap gap-3"><button class="pressable h-12 rounded-xl bg-lime px-6 text-[15px] font-bold text-navy hover:brightness-95">Add user</button><a href="users.php" class="inline-flex h-12 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-butter-50">Cancel</a></div>
</form>
<?php page_end(); ?>
