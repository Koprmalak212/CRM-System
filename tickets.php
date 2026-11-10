<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/tickets.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();

$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$status = isset(TICKET_STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$priority = isset(TICKET_PRIORITIES[$_GET['priority'] ?? '']) ? $_GET['priority'] : '';
$assignee = (string) ($_GET['assignee'] ?? '');   // '' = anyone, 'none' = unassigned, or an account id
$assignee = $assignee === 'none' ? 'none' : ((int) $assignee > 0 ? (string) (int) $assignee : '');
$company = max(0, (int) ($_GET['company'] ?? 0));
$sorts = ['reference' => 't.id', 'subject' => 't.subject', 'company' => 'c.name', 'status' => 't.status', 'priority' => "FIELD(t.priority,'urgent','high','normal','low')", 'assignee' => 'a.name', 'updated' => 't.updated_at'];
$sort = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'updated';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = 15;

$where = []; $args = [];
if ($q !== '') { $like = '%' . addcslashes($q, '\\%_') . '%'; $where[] = '(t.reference LIKE ? OR t.subject LIKE ? OR c.name LIKE ?)'; array_push($args, $like, $like, $like); }
if ($status !== '') { $where[] = 't.status = ?'; $args[] = $status; }
if ($priority !== '') { $where[] = 't.priority = ?'; $args[] = $priority; }
if ($assignee === 'none') { $where[] = 't.assignee_id IS NULL'; } elseif ($assignee !== '') { $where[] = 't.assignee_id = ?'; $args[] = (int) $assignee; }
if ($company > 0) { $where[] = 't.company_id = ?'; $args[] = $company; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$from = 'FROM tickets t LEFT JOIN companies c ON c.id = t.company_id LEFT JOIN admins a ON a.id = t.assignee_id';

$cnt = $pdo->prepare("SELECT COUNT(*) $from $w");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$rows = $pdo->prepare("SELECT t.*, c.name AS company_name, a.name AS assignee_name $from $w ORDER BY {$sorts[$sort]} $dir, t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$rows->execute($args);
$rows = $rows->fetchAll();

$counts = $pdo->query('SELECT status, COUNT(*) n FROM tickets GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$companyList = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$assignees = ticket_assignees($pdo);
$companyQs = $company > 0 ? $company : '';
$base = ['q' => $q, 'status' => $status, 'priority' => $priority, 'assignee' => $assignee, 'company' => $companyQs, 'sort' => $sort, 'dir' => $dir];

page_start('Tickets', 'tickets', 'Support requests from your customers. Filter, assign and follow them up.');
$field = 'h-11 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<nav class="rise mb-4 flex flex-wrap gap-2" aria-label="Status filter">
  <?php foreach (['' => 'All'] + TICKET_STATUSES as $k => $label): $on = $status === $k; ?>
    <a href="<?= e(qs(['q' => $q, 'priority' => $priority, 'assignee' => $assignee, 'company' => $companyQs], ['status' => $k])) ?>" <?= $on ? 'aria-current="true"' : '' ?>
       class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-lime text-navy' : 'border border-line bg-panel hover:bg-butter-50' ?>">
      <?= e($label) ?><span class="tabular-nums <?= $on ? 'text-navy/70' : 'text-soft' ?>"><?= $k === '' ? array_sum($counts) : (int) ($counts[$k] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<div class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.05s">
  <form method="get" class="flex flex-wrap items-center gap-3 border-b border-line p-4">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <label class="sr-only" for="q">Search</label>
    <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Reference, subject or company" class="<?= $field ?> w-full sm:w-64">
    <label class="sr-only" for="priority">Priority</label>
    <select id="priority" name="priority" class="<?= $field ?>"><option value="">All priorities</option><?php foreach (TICKET_PRIORITIES as $k => $l): ?><option value="<?= $k ?>" <?= $priority === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <label class="sr-only" for="assignee">Assignee</label>
    <select id="assignee" name="assignee" class="<?= $field ?>"><option value="">Anyone</option><option value="none" <?= $assignee === 'none' ? 'selected' : '' ?>>Unassigned</option><?php foreach ($assignees as $aid => $an): ?><option value="<?= (int) $aid ?>" <?= $assignee === (string) $aid ? 'selected' : '' ?>><?= e($an) ?></option><?php endforeach; ?></select>
    <label class="sr-only" for="company">Company</label>
    <select id="company" name="company" class="<?= $field ?> max-w-[14rem]"><option value="">All companies</option><?php foreach ($companyList as $cid => $cn): ?><option value="<?= (int) $cid ?>" <?= $company === (int) $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select>
    <button class="h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Apply</button>
    <?php if ($q !== '' || $status !== '' || $priority !== '' || $assignee !== '' || $company > 0): ?><a href="tickets.php" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
    <a href="ticket_new.php" class="pressable ml-auto inline-flex h-11 items-center gap-2 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy"><?= icon('plus', 'h-4 w-4') ?>New ticket</a>
  </form>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[980px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <?= th_sort('Ticket', 'reference', $sort, $dir, $base) ?>
        <?= th_sort('Company', 'company', $sort, $dir, $base) ?>
        <?= th_sort('Status', 'status', $sort, $dir, $base) ?>
        <?= th_sort('Priority', 'priority', $sort, $dir, $base) ?>
        <?= th_sort('Assignee', 'assignee', $sort, $dir, $base) ?>
        <?= th_sort('Updated', 'updated', $sort, $dir, $base) ?>
      </tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $t): ?>
          <tr class="hover:bg-butter-50">
            <td class="px-4 py-3"><a href="ticket.php?id=<?= (int) $t['id'] ?>" class="block max-w-[22rem] truncate font-semibold underline-offset-4 hover:text-lime hover:underline focus-visible:underline"><?= e($t['subject']) ?></a><p class="text-soft notranslate"><?= e($t['reference']) ?></p></td>
            <td class="px-4 py-3"><?= $t['company_id'] !== null ? '<a class="underline-offset-4 hover:underline" href="company.php?id=' . (int) $t['company_id'] . '">' . e($t['company_name']) . '</a>' : '<span class="text-soft">None</span>' ?></td>
            <td class="px-4 py-3"><?= badge($t['status']) ?></td>
            <td class="px-4 py-3"><?= badge($t['priority']) ?></td>
            <td class="px-4 py-3"><?= $t['assignee_id'] !== null ? e($t['assignee_name']) : '<span class="text-soft">Unassigned</span>' ?></td>
            <td class="px-4 py-3 text-soft"><?= e(ago($t['updated_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="px-4 py-14 text-center"><p class="text-base font-semibold">No tickets match these filters</p><p class="mt-1 text-soft">Try different filters, or open a new ticket.</p><a href="tickets.php" class="mt-4 inline-flex h-10 items-center rounded-xl bg-lime px-4 text-sm font-bold text-navy">Show all tickets</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $base) ?>
</div>
<?php page_end(); ?>
