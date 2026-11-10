<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/notifications.php';
require __DIR__ . '/includes/layout.php';
require_login();
$pdo = db();

if (isset($_GET['go'])) {   // clicked in the bell or the list: mark it read, then go where it points
    $g = $pdo->prepare('SELECT id, link FROM notifications WHERE id = ?');
    $g->execute([(int) $_GET['go']]);
    if ($n = $g->fetch()) {
        notifications_mark_read($pdo, (int) $n['id']);
        redirect(preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.+-]{0,100})?$/', (string) $n['link']) ? $n['link'] : 'notifications.php');
    }
    redirect('notifications.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'read_all') {
        $n = notifications_mark_read($pdo);
        flash($n > 0 ? $n . ' notification' . ($n === 1 ? '' : 's') . ' marked as read.' : 'Nothing was unread.');
    } elseif ($action === 'read') {
        notifications_mark_read($pdo, (int) ($_POST['id'] ?? 0));
    }
    $back = (string) ($_POST['back'] ?? '');
    redirect(preg_match('/^[a-z_]+\.php$/', $back) && is_file(__DIR__ . '/' . $back) ? $back : 'notifications.php' . safe_back($_POST['qs'] ?? ''));
}

$only = ($_GET['show'] ?? '') === 'unread' ? 'unread' : 'all';
$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = 20;
$w = $only === 'unread' ? 'WHERE read_at IS NULL' : '';
$total = (int) $pdo->query("SELECT COUNT(*) FROM notifications $w")->fetchColumn();
$unread = notifications_unread($pdo);
$page = min($page, max(1, (int) ceil($total / $per)));
$rows = $pdo->query("SELECT * FROM notifications $w ORDER BY created_at DESC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per))->fetchAll();
$base = ['show' => $only === 'unread' ? 'unread' : ''];

page_start('Notifications', 'notifications', 'Things that need attention: overdue invoices, failed backups, services that are down and urgent tickets.');
?>
<div class="rise mb-4 flex flex-wrap items-center gap-2">
  <?php foreach (['all' => 'All', 'unread' => 'Unread'] as $k => $l): $on = $only === $k; ?>
    <a href="notifications.php<?= $k === 'unread' ? '?show=unread' : '' ?>" <?= $on ? 'aria-current="true"' : '' ?> class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold <?= $on ? 'bg-lime text-navy' : 'border border-line bg-panel hover:bg-butter-50' ?>"><?= e($l) ?><?php if ($k === 'unread'): ?><span class="tabular-nums <?= $on ? 'text-navy/70' : 'text-soft' ?>"><?= $unread ?></span><?php endif; ?></a>
  <?php endforeach; ?>
  <?php if ($unread > 0): ?>
    <form method="post" class="ml-auto"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="pressable h-10 rounded-xl border border-line px-4 text-sm font-semibold hover:bg-lime hover:text-navy">Mark all as read</button></form>
  <?php endif; ?>
</div>

<div class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.05s">
  <?php if ($rows): ?>
    <ul class="divide-y divide-line">
      <?php foreach ($rows as $r): $isNew = $r['read_at'] === null; ?>
        <li class="flex flex-wrap items-center gap-3 px-4 py-3.5 sm:px-5 <?= $isNew ? '' : 'bg-ink/[.03]' ?>">
          <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg <?= $isNew ? 'bg-raised text-lime' : 'bg-raised text-soft' ?>"><?= icon(NOTIFY_ICONS[$r['kind']] ?? 'alert') ?></span>
          <div class="min-w-0 flex-1"><p class="font-semibold"><?= $isNew ? '<span class="sr-only">Unread: </span>' : '' ?><?= e($r['title']) ?></p><p class="text-sm text-soft"><?= e($r['body']) ?></p><p class="text-xs text-soft"><?= e(ago($r['created_at'])) ?></p></div>
          <div class="flex gap-2">
            <?php if ($r['link'] !== ''): ?><a href="notifications.php?go=<?= (int) $r['id'] ?>" class="inline-flex h-9 items-center rounded-lg border border-line px-3 text-sm font-semibold hover:bg-butter-50">Open</a><?php endif; ?>
            <?php if ($isNew): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="qs" value="<?= e(qs($base, ['page' => $page])) ?>"><button class="h-9 rounded-lg border border-line px-3 text-sm font-semibold hover:bg-lime hover:text-navy">Mark read</button></form><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="px-4 py-14 text-center"><p class="text-base font-semibold"><?= $only === 'unread' ? 'You are all caught up' : 'No notifications yet' ?></p><p class="mt-1 text-soft">New ones appear when an invoice becomes overdue, a backup fails, a service goes down or an urgent ticket is opened.</p></div>
  <?php endif; ?>
  <?= pagination($total, $per, $page, $base) ?>
</div>
<?php page_end(); ?>
