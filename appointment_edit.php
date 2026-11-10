<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/appointments.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$a = null;
if ($id > 0) {
    $a = appointment_find($pdo, $id);
    if (!$a) { http_response_code(404); page_start('Appointment not found', 'calendar', 'That appointment does not exist. It may have been removed.'); echo '<a href="calendar.php" class="pressable inline-flex h-11 items-center rounded-xl bg-lime px-5 text-sm font-bold text-navy">Back to the calendar</a>'; page_end(); exit; }
}

$date = (string) ($_GET['date'] ?? '');
$dflt = valid_date_str($date) ? $date : date('Y-m-d');
$v = $a ? ['company_id' => (int) $a['company_id'], 'user_id' => (int) ($a['user_id'] ?? 0), 'title' => $a['title'], 'location' => $a['location'], 'description' => $a['description'], 'status' => $a['status'], 'starts_at' => date('Y-m-d\TH:i', strtotime($a['starts_at'])), 'ends_at' => date('Y-m-d\TH:i', strtotime($a['ends_at']))]
       : ['company_id' => max(0, (int) ($_GET['company'] ?? 0)), 'user_id' => max(0, (int) ($_GET['user'] ?? 0)), 'title' => '', 'location' => '', 'description' => '', 'status' => 'scheduled', 'starts_at' => $dflt . 'T09:00', 'ends_at' => $dflt . 'T10:00'];
$errors = [];
$confirmDelete = $a && ($_GET['delete'] ?? '') === '1';

function valid_date_str(string $s): bool { $d = DateTimeImmutable::createFromFormat('Y-m-d', $s); return $d !== false && $d->format('Y-m-d') === $s; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete' && $a) {
        $pdo->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
        log_activity('warning', 'Appointment deleted', $a['company_name'] . ' · ' . $a['title']);
        flash('Appointment deleted.');
        redirect('calendar.php?month=' . date('Y-m', strtotime($a['starts_at'])));
    }
    foreach (['title', 'location', 'description', 'status', 'starts_at', 'ends_at'] as $f) { $v[$f] = (string) ($_POST[$f] ?? ''); }
    $v['company_id'] = (int) ($_POST['company_id'] ?? 0); $v['user_id'] = (int) ($_POST['user_id'] ?? 0);
    [$d, $errors] = appointment_clean($pdo, $v);
    if (!$errors) {
        $user = $d['user_id'] > 0 ? $d['user_id'] : null;
        if ($a) {
            $pdo->prepare('UPDATE appointments SET company_id = ?, user_id = ?, title = ?, location = ?, description = ?, status = ?, starts_at = ?, ends_at = ? WHERE id = ?')
                ->execute([$d['company_id'], $user, $d['title'], $d['location'], $d['description'], $d['status'], $d['starts_at'], $d['ends_at'], $id]);
            log_activity('info', 'Appointment updated', $d['title'] . ' · ' . $d['status']);
            flash('Appointment saved.');
        } else {
            $pdo->prepare('INSERT INTO appointments (company_id, user_id, title, location, description, status, starts_at, ends_at, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$d['company_id'], $user, $d['title'], $d['location'], $d['description'], $d['status'], $d['starts_at'], $d['ends_at'], (string) ($_SESSION['admin']['username'] ?? '')]);
            $id = (int) $pdo->lastInsertId();
            log_activity('info', 'Appointment added', $d['title']);
            flash('Appointment added.');
        }
        redirect('calendar.php?month=' . date('Y-m', strtotime($d['starts_at'])) . ($d['company_id'] ? '&company=' . $d['company_id'] : ''));
    }
}

$companies = $pdo->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$users = $pdo->prepare('SELECT id, name FROM users WHERE company_id = ? ORDER BY name LIMIT 500');
$users->execute([$v['company_id']]);
$users = $users->fetchAll(PDO::FETCH_KEY_PAIR);
page_start($a ? 'Edit appointment' : 'New appointment', 'calendar', $a ? $a['company_name'] : 'Choose the company first; the contact person is picked from its people.');
$field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<a href="calendar.php" class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-soft hover:text-ink"><?= icon('left', 'h-4 w-4') ?>Calendar</a>
<?php if ($errors): ?>
  <div role="alert" class="mb-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]"><p class="font-semibold">This appointment can not be saved yet:</p><ul class="mt-1 list-disc pl-5"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form method="post" class="rise max-w-3xl rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" novalidate><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2"><label for="title" class="text-sm font-semibold">Title</label><input id="title" name="title" maxlength="160" required value="<?= e($v['title']) ?>" class="<?= $field ?> mt-1.5"></div>
    <div><label for="company_id" class="text-sm font-semibold">Company</label><select id="company_id" name="company_id" class="<?= $field ?> mt-1.5"><option value="0">Choose a company</option><?php foreach ($companies as $cid => $cn): ?><option value="<?= (int) $cid ?>" <?= $v['company_id'] === (int) $cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select>
      <p class="mt-1 text-xs text-soft">After changing the company, save once to pick a contact from its people.</p></div>
    <div><label for="user_id" class="text-sm font-semibold">Contact person <span class="font-normal text-soft">(optional)</span></label><select id="user_id" name="user_id" class="<?= $field ?> mt-1.5"><option value="0">Nobody in particular</option><?php foreach ($users as $uid => $un): ?><option value="<?= (int) $uid ?>" <?= $v['user_id'] === (int) $uid ? 'selected' : '' ?>><?= e($un) ?></option><?php endforeach; ?></select></div>
    <div><label for="starts_at" class="text-sm font-semibold">Starts</label><input id="starts_at" name="starts_at" type="datetime-local" required value="<?= e($v['starts_at']) ?>" class="<?= $field ?> mt-1.5"></div>
    <div><label for="ends_at" class="text-sm font-semibold">Ends</label><input id="ends_at" name="ends_at" type="datetime-local" required value="<?= e($v['ends_at']) ?>" class="<?= $field ?> mt-1.5"></div>
    <div><label for="location" class="text-sm font-semibold">Location</label><input id="location" name="location" maxlength="160" value="<?= e($v['location']) ?>" placeholder="Office, address or video link" class="<?= $field ?> mt-1.5"></div>
    <div><label for="status" class="text-sm font-semibold">Status</label><select id="status" name="status" class="<?= $field ?> mt-1.5"><?php foreach (APPT_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $v['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="sm:col-span-2"><label for="description" class="text-sm font-semibold">Notes</label><textarea id="description" name="description" rows="3" maxlength="1000" class="mt-1.5 w-full rounded-xl border border-line bg-panel px-3 py-2 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"><?= e($v['description']) ?></textarea></div>
  </div>
  <div class="mt-6 flex flex-wrap gap-3"><button class="pressable h-12 rounded-xl bg-lime px-6 text-[15px] font-bold text-navy hover:brightness-95"><?= $a ? 'Save changes' : 'Add appointment' ?></button><a href="calendar.php" class="inline-flex h-12 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-butter-50">Cancel</a>
    <?php if ($a): ?><a href="calendar_ics.php?id=<?= $id ?>" class="inline-flex h-12 items-center gap-2 rounded-xl border border-line px-5 text-sm font-semibold hover:bg-butter-50"><?= icon('download', 'h-4 w-4') ?>.ics</a><?php endif; ?></div>
</form>
<?php if ($a): ?>
<section class="rise mt-6 max-w-3xl rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="del-h">
  <h2 id="del-h" class="text-base font-bold">Delete this appointment</h2>
  <?php if ($confirmDelete): ?>
    <p role="alert" class="mt-2 text-sm font-semibold text-[#FF9C8C]">Delete “<?= e($a['title']) ?>” for good? To keep it on record, set its status to Cancelled instead.</p>
    <form method="post" class="mt-4 flex flex-wrap gap-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete"><button class="pressable h-11 rounded-xl bg-[#FFD3CC] px-5 text-sm font-bold text-[#7E160A] hover:brightness-95">Yes, delete it</button><a href="appointment_edit.php?id=<?= $id ?>" class="inline-flex h-11 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-butter-50">Keep it</a></form>
  <?php else: ?>
    <p class="mt-2 text-sm text-soft">You will be asked to confirm.</p><a href="appointment_edit.php?id=<?= $id ?>&amp;delete=1" class="mt-4 inline-flex h-11 items-center rounded-xl border border-line px-5 text-sm font-semibold hover:bg-[#FFD3CC] hover:text-[#7E160A]">Delete appointment…</a>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php page_end(); ?>
