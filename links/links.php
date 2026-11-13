<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/core/layout.php';
require __DIR__ . '/../includes/modules/connectors.php';
$admin = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    [$allowed] = rate_hit('shortlink', 10, 60);
    if (!$allowed) { flash('Too many requests. Wait a minute and try again.', 'bad'); redirect('links/links.php'); }
    if ($action === 'sync') {
        if (!is_admin()) { deny_access('links:sync'); }
        $r = connector_sync($pdo, 'shortio', true);
        flash($r['message'], $r['state'] === 'ok' ? 'ok' : 'bad');
        redirect('links/links.php');
    }
    if ($action === 'create') {
        $url = is_string($_POST['url'] ?? null) ? trim($_POST['url']) : '';
        $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $co = is_string($_POST['company'] ?? null) ? $_POST['company'] : '';
        $cid = null;
        if ($url === '' || strlen($url) > 2000 || !preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            flash('Enter the full address to shorten, starting with http:// or https://', 'bad'); redirect('links/links.php');
        }
        if (mb_strlen($title) > 190) { flash('The title can be at most 190 characters.', 'bad'); redirect('links/links.php'); }
        if ($co !== '') {
            $chk = $pdo->prepare('SELECT id FROM companies WHERE id = ?');
            $chk->execute([(int) $co]);
            $cid = ctype_digit($co) && $chk->fetchColumn() !== false ? (int) $co : null;
            if ($cid === null) { flash('That company does not exist.', 'bad'); redirect('links/links.php'); }
        }
        [$ok, $msg, $row] = shortio_create($pdo, $url, $title, $cid, (string) $admin['username']);
        if ($ok) { log_activity('info', 'Short link created', (string) $row['short_url']); }
        flash($msg, $ok ? 'ok' : 'bad');
        redirect('links/links.php');
    }
    redirect('links/links.php');
}

// filters
$w = []; $a = []; $f = ['company' => '', 'q' => '', 'from' => '', 'to' => ''];
$gs = static fn(string $k): string => is_string($_GET[$k] ?? null) ? $_GET[$k] : '';
$co = $gs('company');
if ($co === 'none') { $w[] = 's.company_id IS NULL'; $f['company'] = 'none'; }
elseif (ctype_digit($co) && (int) $co > 0) { $w[] = 's.company_id = ?'; $a[] = (int) $co; $f['company'] = $co; }
foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
    $d = $gs($k);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false && date('Y-m-d', strtotime($d)) === $d) { $w[] = 'COALESCE(s.created_remote, s.synced_at) ' . $op . ' ?'; $a[] = $d . ($k === 'from' ? ' 00:00:00' : ' 23:59:59'); $f[$k] = $d; }
}
$q = mb_substr(trim($gs('q')), 0, 80);
if ($q !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $w[] = '(s.title LIKE ? OR s.short_url LIKE ? OR s.original_url LIKE ?)'; array_push($a, $like, $like, $like); $f['q'] = $q; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$page = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$per = page_size(20);
$cnt = $pdo->prepare("SELECT COUNT(*) FROM short_links s $where");
$cnt->execute($a);
$total = (int) $cnt->fetchColumn();
$page = min($page, max(1, (int) ceil($total / $per)));
$st = $pdo->prepare("SELECT s.*, co.name AS company_name FROM short_links s LEFT JOIN companies co ON co.id = s.company_id $where ORDER BY COALESCE(s.created_remote, s.synced_at) DESC, s.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($a);
$rows = $st->fetchAll();
$companies = $pdo->query('SELECT id, name FROM companies ORDER BY name LIMIT 500')->fetchAll();
$conn = connector_get($pdo, 'shortio');
$connected = $conn['enabled'] && connector_has_secret($conn);
$filtered = implode('', $f) !== '';
$field = 'mt-1.5 h-11 w-full rounded-lg border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
$coOpt = static function (string $cur, string $all, bool $none) use ($companies): string {
    $h = '<option value="">' . e($all) . '</option>' . ($none ? '<option value="none"' . ($cur === 'none' ? ' selected' : '') . '>No company</option>' : '');
    foreach ($companies as $c) { $h .= '<option value="' . (int) $c['id'] . '"' . ((string) $c['id'] === $cur ? ' selected' : '') . '>' . e((string) $c['name']) . '</option>'; }
    return $h;
};
$base = $f;

page_start('Short links', 'links', 'Short links made with Short.io, kept per company.');
?>
<?php if (!$connected): ?>
  <p role="status" class="rise mb-6 rounded-lg bg-butter-100 px-4 py-3 text-sm"><strong class="text-butter">Short.io is not connected.</strong>
    <?= is_admin() ? '<a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="settings/settings.php?tab=connectors#c-shortio">Connect it in Settings</a> with an API key to make and list links.' : 'Ask an admin to connect it under Settings, Connectors.' ?></p>
<?php endif; ?>

<div class="rise mb-6 grid gap-4 lg:grid-cols-[1fr_auto]">
  <form method="post" class="grid gap-3 rounded-xl bg-butter p-4 text-navy sm:grid-cols-2 sm:p-5"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="sm:col-span-2"><label for="n-url" class="block text-sm font-semibold">Address to shorten</label>
      <input id="n-url" name="url" type="url" required maxlength="2000" placeholder="https://www.example.com/a/long/address" class="mt-1.5 h-11 w-full rounded-lg border border-navy/15 bg-panel px-3 text-sm text-ink focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"></div>
    <div><label for="n-title" class="block text-sm font-semibold">Title (optional)</label>
      <input id="n-title" name="title" maxlength="190" class="mt-1.5 h-11 w-full rounded-lg border border-navy/15 bg-panel px-3 text-sm text-ink focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"></div>
    <div><label for="n-co" class="block text-sm font-semibold">Company (optional)</label>
      <select id="n-co" name="company" class="mt-1.5 h-11 w-full rounded-lg border border-navy/15 bg-panel px-3 text-sm text-ink focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime"><?= $coOpt('', 'No company', false) ?></select></div>
    <div class="sm:col-span-2"><button class="inline-flex h-11 items-center gap-2 rounded-lg bg-navy px-5 text-sm font-bold text-white hover:bg-navy-800" <?= $connected ? '' : 'disabled aria-disabled="true"' ?>><?= icon('plus', 'h-4 w-4') ?>Make short link</button></div>
  </form>
  <?php if (is_admin() && $connected): ?>
    <form method="post" class="flex items-end rounded-xl border border-line bg-panel p-4 sm:p-5"><?= csrf_field() ?><input type="hidden" name="action" value="sync">
      <button class="pressable h-11 rounded-lg border border-line px-5 text-sm font-semibold hover:bg-butter-50">Sync now</button></form>
  <?php endif; ?>
</div>

<form method="get" action="links/links.php" data-auto class="rise mb-4 grid gap-3 rounded-xl border border-line bg-panel p-4 shadow-panel sm:grid-cols-2 lg:grid-cols-4 sm:p-5" aria-label="Filter short links">
  <div><label for="l-co" class="text-sm font-semibold">Company</label><select id="l-co" name="company" class="<?= e($field) ?>"><?= $coOpt($f['company'], 'All companies', true) ?></select></div>
  <div><label for="l-from" class="text-sm font-semibold">Created from</label><input id="l-from" name="from" type="date" value="<?= e($f['from']) ?>" class="<?= e($field) ?>"></div>
  <div><label for="l-to" class="text-sm font-semibold">Created to</label><input id="l-to" name="to" type="date" value="<?= e($f['to']) ?>" class="<?= e($field) ?>"></div>
  <div><label for="l-q" class="text-sm font-semibold">Title or address</label><input id="l-q" name="q" type="search" maxlength="80" value="<?= e($f['q']) ?>" class="<?= e($field) ?>"></div>
  <div class="flex items-end gap-3 sm:col-span-2">
    <button class="h-11 rounded-lg border border-line bg-panel px-5 text-sm font-semibold hover:bg-butter-50">Apply</button>
    <?php if ($filtered): ?><a href="links/links.php" class="text-sm font-semibold underline decoration-lime decoration-2 underline-offset-4">Clear filters</a><?php endif; ?>
  </div>
</form>

<div class="rise overflow-hidden rounded-xl border border-line bg-panel shadow-panel" style="animation-delay:.05s">
  <?php if (!$rows): ?>
    <div class="p-6 text-sm"><p class="font-semibold"><?= $filtered ? 'Nothing matches these filters.' : 'No short links yet.' ?></p>
      <p class="mt-1 text-soft"><?= $filtered ? 'Change or clear the filters.' : ($connected ? 'Make one above, or press Sync now to fetch the ones you already have.' : 'Connect Short.io first.') ?></p></div>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[760px] text-left text-sm">
      <thead class="bg-butter-50 text-xs text-soft"><tr>
        <th scope="col" class="px-4 py-3 font-semibold">Short link</th><th scope="col" class="px-4 py-3 font-semibold">Goes to</th>
        <th scope="col" class="px-4 py-3 font-semibold">Title</th><th scope="col" class="px-4 py-3 font-semibold">Company</th><th scope="col" class="px-4 py-3 font-semibold">Created</th>
      </tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($rows as $r): ?>
          <tr class="hover:bg-butter-50">
            <td class="px-4 py-3 font-semibold"><a class="underline decoration-lime decoration-2 underline-offset-4" href="<?= e((string) $r['short_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(preg_replace('~^https?://~i', '', (string) $r['short_url'])) ?></a></td>
            <td class="max-w-xs truncate px-4 py-3 text-soft" title="<?= e((string) $r['original_url']) ?>"><?= e(mb_substr((string) $r['original_url'], 0, 80)) ?></td>
            <td class="px-4 py-3"><?= $r['title'] !== '' ? e((string) $r['title']) : '<span class="text-soft">—</span>' ?></td>
            <td class="px-4 py-3"><?= $r['company_id'] ? '<a class="font-semibold underline decoration-lime decoration-2 underline-offset-4" href="companies/company.php?id=' . (int) $r['company_id'] . '">' . e((string) $r['company_name']) . '</a>' : '<span class="text-soft">—</span>' ?></td>
            <td class="px-4 py-3 text-soft"><?= e(ago((string) ($r['created_remote'] ?? $r['synced_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $per, $page, $base, true) ?>
  <?php endif; ?>
</div>
<?php page_end(); ?>
