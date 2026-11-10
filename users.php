<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $to = ($_POST['to'] ?? '') === 'suspended' ? 'suspended' : 'active';
    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$id]);
    if ($u = $stmt->fetch()) {
        $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$to, $id]);
        log_activity($to === 'suspended' ? 'warning' : 'info', $to === 'suspended' ? 'Suspended account' : 'Reactivated account', $u['name']);
        flash($u['name'] . ($to === 'suspended' ? ' was suspended.' : ' is active again.'));
    }
    redirect('users.php' . safe_back($_POST['back'] ?? ''));
}

$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$role = in_array($_GET['role'] ?? '', ['admin', 'editor', 'support', 'viewer'], true) ? $_GET['role'] : '';
$status = in_array($_GET['status'] ?? '', ['active', 'pending', 'suspended'], true) ? $_GET['status'] : '';
$company = max(0, (int) ($_GET['company'] ?? 0));
$companyQs = $company > 0 ? $company : '';
$sorts = ['name' => 'u.name', 'company' => 'c.name', 'role' => 'u.role', 'status' => 'u.status', 'last_seen' => 'u.last_seen', 'logins' => 'u.logins'];
$sortKey = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'last_seen';
$sort = $sortKey;
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = 15;

$where = [];
$args = [];
if ($q !== '') { $like = '%' . addcslashes($q, '\%_') . '%'; $where[] = '(u.name LIKE ? OR u.email LIKE ? OR c.name LIKE ?)'; $args[] = $like; $args[] = $like; $args[] = $like; }
if ($company > 0) { $where[] = 'u.company_id = ?'; $args[] = $company; }
if ($role !== '') { $where[] = 'u.role = ?'; $args[] = $role; }
if ($status !== '') { $where[] = 'u.status = ?'; $args[] = $status; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN companies c ON c.id = u.company_id $w");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$rows = $pdo->prepare("SELECT u.*, c.name AS company_name FROM users u LEFT JOIN companies c ON c.id = u.company_id $w ORDER BY {$sorts[$sortKey]} $dir, u.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$rows->execute($args);
$rows = $rows->fetchAll();

$companyList = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$base = ['q' => $q, 'role' => $role, 'status' => $status, 'company' => $companyQs, 'sort' => $sort, 'dir' => $dir];
$counts = $pdo->query('SELECT status, COUNT(*) n FROM users GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

page_start('Users', 'users', 'Every account on the platform. Search, filter and suspend from here.');
$field = 'h-11 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<nav class="rise mb-4 flex flex-wrap gap-2" aria-label="Status filter">
  <?php foreach (['' => 'All', 'active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended'] as $k => $label): $on = $status === $k; ?>
    <a href="<?= e(qs(['q' => $q, 'role' => $role, 'company' => $companyQs], ['status' => $k])) ?>" <?= $on ? 'aria-current="true"' : '' ?>
       class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-lime text-navy' : 'border border-line bg-panel hover:bg-butter-50' ?>">
      <?= e($label) ?><span class="tabular-nums <?= $on ? 'text-navy/70' : 'text-soft' ?>"><?= $k === '' ? array_sum($counts) : (int) ($counts[$k] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<div class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.05s">
  <form method="get" class="flex flex-wrap items-center gap-3 border-b border-line p-4">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <label class="sr-only" for="q">Search</label>
    <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Name, email or company" class="<?= $field ?> w-full sm:w-72">
    <label class="sr-only" for="role">Role</label>
    <select id="role" name="role" class="<?= $field ?>">
      <option value="">All roles</option>
      <?php foreach (['admin', 'editor', 'support', 'viewer'] as $r): ?><option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="company">Company</label>
    <select id="company" name="company" class="<?= $field ?> max-w-[16rem]">
      <option value="">All companies</option>
      <?php foreach ($companyList as $cid => $cname): ?><option value="<?= (int) $cid ?>" <?= $company === (int) $cid ? 'selected' : '' ?>><?= e($cname) ?></option><?php endforeach; ?>
    </select>
    <button class="h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Apply</button>
    <?php if ($q !== '' || $role !== '' || $status !== '' || $company > 0): ?><a href="users.php" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
  </form>

  <div class="overflow-x-auto">
    <table class="w-full min-w-[900px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft">
        <tr>
          <?= th_sort('User', 'name', $sort, $dir, $base) ?>
          <?= th_sort('Company', 'company', $sort, $dir, $base) ?>
          <?= th_sort('Role', 'role', $sort, $dir, $base) ?>
          <?= th_sort('Status', 'status', $sort, $dir, $base) ?>
          <?= th_sort('Last seen', 'last_seen', $sort, $dir, $base) ?>
          <?= th_sort('Sign-ins', 'logins', $sort, $dir, $base, 'right') ?>
          <th scope="col" class="px-4 py-3 text-right font-semibold">Action</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $u): ?>
          <tr class="hover:bg-butter-50">
            <td class="px-4 py-3">
              <div class="flex items-center gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-raised text-xs font-bold text-lime"><?= e(initials($u['name'])) ?></span>
                <div class="min-w-0"><a href="user.php?id=<?= (int) $u['id'] ?>" class="block truncate font-semibold underline-offset-4 hover:text-lime hover:underline focus-visible:underline"><?= e($u['name']) ?></a><p class="truncate text-soft"><?= e($u['email']) ?></p></div>
              </div>
            </td>
            <td class="px-4 py-3"><?php if ($u['company_id'] !== null): ?><a href="company.php?id=<?= (int) $u['company_id'] ?>" class="underline-offset-4 hover:underline"><?= e($u['company_name']) ?></a><?php else: ?><span class="text-soft">Not set</span><?php endif; ?></td>
            <td class="px-4 py-3 capitalize"><?= e($u['role']) ?></td>
            <td class="px-4 py-3"><?= badge($u['status']) ?></td>
            <td class="px-4 py-3 text-soft"><?= e(ago($u['last_seen'])) ?></td>
            <td class="px-4 py-3 text-right tabular-nums"><?= number_format((int) $u['logins']) ?></td>
            <td class="px-4 py-3 text-right">
              <form method="post" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <input type="hidden" name="back" value="<?= e(qs($base, ['page' => $page])) ?>">
                <?php if ($u['status'] === 'suspended'): ?>
                  <input type="hidden" name="to" value="active">
                  <button class="h-9 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-lime hover:text-navy">Reactivate</button>
                <?php else: ?>
                  <input type="hidden" name="to" value="suspended">
                  <button class="h-9 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Suspend</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="px-4 py-14 text-center"><p class="text-base font-semibold">No users match these filters</p><p class="mt-1 text-soft">Try a different name or clear the filters.</p><a href="users.php" class="mt-4 inline-flex h-10 items-center rounded-xl bg-lime px-4 text-sm font-bold text-navy">Show all users</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $base) ?>
</div>
<?php page_end(); ?>
