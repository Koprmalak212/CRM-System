<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/tickets.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$t = ticket_find($pdo, $id) ?? ticket_not_found();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'comment') {
        $err = ticket_comment($pdo, $id, (string) ($_POST['body'] ?? ''));
        flash($err ?? 'Comment added.', $err ? 'error' : 'ok');
    } elseif ($action === 'update') {
        [$changes, $err] = ticket_update($pdo, $t, $_POST);
        if ($err) { flash(implode(' ', $err), 'error'); }
        elseif ($changes) {
            $note = 'Changed ' . implode(', ', $changes) . '.';
            ticket_comment($pdo, $id, $note, 'system');   // so the thread shows who changed what, and when
            flash('Ticket updated.');
        } else { flash('Nothing changed.'); }
    }
    redirect('tickets/ticket.php?id=' . $id . '#thread');
}

$comments = $pdo->prepare('SELECT * FROM ticket_comments WHERE ticket_id = ? ORDER BY created_at ASC, id ASC');
$comments->execute([$id]);
$comments = $comments->fetchAll();
$assignees = ticket_assignees($pdo);
$field = 'h-11 w-full rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';

page_start($t['subject'], 'tickets', $t['reference'] . ' · opened ' . ago($t['created_at']));
?>
<nav class="rise mb-4 text-sm" aria-label="Breadcrumb"><a href="tickets/tickets.php" class="inline-flex items-center gap-1 font-semibold underline decoration-lime decoration-2 underline-offset-4">&larr; All tickets</a></nav>

<div class="grid items-start gap-5 lg:grid-cols-3">
  <div class="space-y-5 lg:col-span-2">
    <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="desc-h">
      <div class="flex flex-wrap items-center gap-2"><?= badge($t['status']) ?><?= badge($t['priority']) ?></div>
      <h2 id="desc-h" class="mt-3 text-base font-bold">Description</h2>
      <p class="mt-2 whitespace-pre-line text-sm"><?= e($t['description']) ?></p>
    </section>

    <section id="thread" class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="th-h">
      <h2 id="th-h" class="text-base font-bold">Comments</h2>
      <?php if ($comments): ?>
        <ol class="mt-4 space-y-4">
          <?php foreach ($comments as $c): $sys = $c['author'] === 'system'; ?>
            <li class="rounded-xl <?= $sys ? 'border border-dashed border-line px-4 py-2.5' : 'bg-raised px-4 py-3' ?>">
              <div class="flex flex-wrap items-center gap-x-2 text-xs text-soft"><span class="font-semibold text-ink"><?= $sys ? 'Update' : e($c['author']) ?></span><span><?= e(ago($c['created_at'])) ?></span></div>
              <p class="mt-1 whitespace-pre-line text-sm <?= $sys ? 'text-soft' : '' ?>"><?= e($c['body']) ?></p>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php else: ?><p class="mt-3 text-sm text-soft">No comments yet.</p><?php endif; ?>
      <form method="post" class="mt-5 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-start"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="comment">
        <div><label for="body" class="sr-only">Write a comment</label>
          <textarea id="body" name="body" rows="3" maxlength="2000" required placeholder="Write a comment" class="w-full rounded-xl border border-line bg-panel px-3 py-2 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"></textarea></div>
        <button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Add comment</button>
      </form>
    </section>
  </div>

  <div class="space-y-5">
    <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="up-h">
      <h2 id="up-h" class="text-base font-bold">Handling</h2>
      <form method="post" class="mt-4 space-y-4"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="update">
        <div><label for="status" class="text-sm font-semibold">Status</label>
          <select id="status" name="status" class="<?= $field ?> mt-1.5"><?php foreach (TICKET_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $t['status'] === $k ? 'selected' : '' ?> <?= $k !== $t['status'] && !ticket_can_move($t['status'], $k) ? 'disabled' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div><label for="priority" class="text-sm font-semibold">Priority</label>
          <select id="priority" name="priority" class="<?= $field ?> mt-1.5"><?php foreach (TICKET_PRIORITIES as $k => $l): ?><option value="<?= $k ?>" <?= $t['priority'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div><label for="assignee_id" class="text-sm font-semibold">Assignee</label>
          <select id="assignee_id" name="assignee_id" class="<?= $field ?> mt-1.5"><option value="0">Unassigned</option><?php foreach ($assignees as $aid => $an): ?><option value="<?= (int) $aid ?>" <?= (int) $t['assignee_id'] === (int) $aid ? 'selected' : '' ?>><?= e($an) ?></option><?php endforeach; ?></select></div>
        <button class="pressable h-11 w-full rounded-xl border border-line text-sm font-semibold hover:bg-lime hover:text-navy">Save changes</button>
      </form>
    </section>

    <section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel" aria-labelledby="in-h">
      <h2 id="in-h" class="text-base font-bold">Details</h2>
      <dl class="mt-4 space-y-3 text-sm">
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-soft">Company</dt><dd class="mt-1 font-medium"><?= $t['company_id'] !== null ? '<a class="underline decoration-lime decoration-2 underline-offset-4" href="companies/company.php?id=' . (int) $t['company_id'] . '">' . e($t['company_name']) . '</a>' : '<span class="text-soft">None</span>' ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-soft">Contact</dt><dd class="mt-1 font-medium"><?= $t['user_id'] !== null ? '<a class="underline decoration-lime decoration-2 underline-offset-4" href="users/user.php?id=' . (int) $t['user_id'] . '">' . e($t['user_name']) . '</a>' : '<span class="text-soft">None</span>' ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-soft">Opened by</dt><dd class="mt-1 font-medium"><?= e($t['created_by'] ?: 'Unknown') ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-soft">Last update</dt><dd class="mt-1 font-medium"><?= e(date('j M Y, H:i', strtotime($t['updated_at']))) ?></dd></div>
        <?php if ($t['resolved_at'] !== null): ?><div><dt class="text-xs font-semibold uppercase tracking-wide text-soft">Resolved</dt><dd class="mt-1 font-medium"><?= e(date('j M Y, H:i', strtotime($t['resolved_at']))) ?></dd></div><?php endif; ?>
      </dl>
    </section>
  </div>
</div>
<?php page_end(); ?>
