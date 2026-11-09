<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
$admin = require_login();
if (!is_admin()) { deny_access('privacy.php'); }   // exports and erasures touch personal data: admins only
$pdo = db();

/** Everything held about one email address, grouped by where it lives. */
function collect_subject(PDO $pdo, string $email): array
{
    $u = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $u->execute([$email]);
    $user = $u->fetch() ?: null;

    $conns = [];
    if ($user) {
        $c = $pdo->prepare('SELECT ip, device, location, protocol, status, started_at, last_activity FROM connections WHERE user_id = ?');
        $c->execute([$user['id']]);
        $conns = $c->fetchAll();
    }
    $l = $pdo->prepare('SELECT level, action, target, ip, created_at FROM activity_logs WHERE actor = ? ORDER BY created_at DESC LIMIT 5000');
    $l->execute([$email]);
    $logs = $l->fetchAll();

    $i = $pdo->prepare('SELECT * FROM invoices WHERE email = ? ORDER BY issue_date DESC');
    $i->execute([$email]);
    $invoices = $i->fetchAll();
    foreach ($invoices as &$inv) {
        $it = $pdo->prepare('SELECT description, qty, unit_cents FROM invoice_items WHERE invoice_id = ?');
        $it->execute([$inv['id']]);
        $inv['items'] = $it->fetchAll();
        $p = $pdo->prepare('SELECT amount_cents, method, paid_at, reference FROM payments WHERE invoice_id = ?');
        $p->execute([$inv['id']]);
        $inv['payments'] = $p->fetchAll();
    }
    unset($inv);
    return ['user' => $user, 'connections' => $conns, 'activity_logs' => $logs, 'invoices' => $invoices];
}

$email = mb_substr(trim((string) ($_GET['email'] ?? $_POST['email'] ?? '')), 0, 190);
$validEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validEmail) {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $data = collect_subject($pdo, $email);

    if ($action === 'export') {
        $label = $data['user'] ? 'user-' . $data['user']['id'] : 'contact-' . substr(hash('sha256', strtolower($email)), 0, 8);
        log_activity('info', 'Privacy export created', $label);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="privacy-export-' . $label . '-' . date('Ymd') . '.json"');
        echo json_encode([
            'exported_at' => date('c'),
            'subject_email' => $email,
            'controller' => 'Telecomlijn Zakelijk, Seinhuiswachter 12, 3034 KH Rotterdam, office@telecomlijn.nl',
            'data' => $data,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'erase') {
        if (!$data['user']) {
            flash('No user account exists for that address, so there is nothing to anonymise. Invoice contacts are kept for the legal retention period.', 'bad');
        } elseif (($_POST['confirm'] ?? '') !== 'yes') {
            flash('Tick the confirmation box to anonymise this account.', 'bad');
        } else {
            $id = (int) $data['user']['id'];
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM connections WHERE user_id = ?')->execute([$id]);
                $pdo->prepare("UPDATE activity_logs SET actor = 'anonymised', ip = '0.0.0.0' WHERE actor = ?")->execute([$email]);
                $pdo->prepare("UPDATE users SET name = 'Anonymised user', email = ?, country = '', status = 'suspended' WHERE id = ?")
                    ->execute(['anonymised-' . $id . '@invalid.example', $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            log_activity('warning', 'Privacy erasure completed', 'user #' . $id);
            flash('User #' . $id . ' was anonymised. Sessions, IP addresses and log identifiers were removed. Invoices stay for the legal retention period.');
            redirect('privacy.php');
        }
        redirect('privacy.php?email=' . urlencode($email));
    }
}

$data = $validEmail ? collect_subject($pdo, $email) : null;
$found = $data && ($data['user'] || $data['activity_logs'] || $data['invoices']);

page_start('Privacy requests', 'settings', 'Handle a request under the GDPR (AVG): see what is held about a person, export it, or anonymise their account.');
$field = 'h-11 rounded-xl border border-line bg-panel px-3 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<section class="rise rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" aria-labelledby="find-h">
  <h2 id="find-h" class="text-lg font-bold tracking-tight">Find a person</h2>
  <form method="get" class="mt-4 flex flex-wrap items-end gap-3" novalidate>
    <div class="min-w-64 flex-1"><label for="email" class="mb-1 block text-sm font-semibold">Email address of the person making the request</label>
      <input id="email" name="email" type="email" value="<?= e($email) ?>" required class="<?= $field ?> w-full"></div>
    <button class="pressable h-11 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95">Look up</button>
  </form>
  <?php if ($email !== '' && !$validEmail): ?><p role="alert" class="mt-3 text-sm font-semibold text-[#FF9C8C]">That is not a valid email address.</p><?php endif; ?>
  <p class="mt-3 text-sm text-soft">Confirm the requester's identity first (the policy allows asking for ID). You must respond within 4 weeks.</p>
</section>

<?php if ($validEmail && !$found): ?>
  <section class="rise mt-6 rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" role="status">
    <h2 class="text-lg font-bold tracking-tight">Nothing found</h2>
    <p class="mt-1 text-sm text-soft">No account, log entries or invoices are held for <?= e($email) ?>. Tell the requester so, within the 4-week deadline.</p>
  </section>
<?php elseif ($found): ?>
  <section class="rise mt-6 rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.05s" aria-labelledby="held-h">
    <div class="border-b border-line p-5 sm:p-6"><h2 id="held-h" class="text-lg font-bold tracking-tight">What is held about <?= e($email) ?></h2></div>
    <dl class="divide-y divide-line text-sm">
      <div class="grid gap-1 p-5 sm:grid-cols-[220px_1fr] sm:p-6"><dt class="font-semibold">User account</dt><dd class="text-soft"><?= $data['user'] ? e($data['user']['name'] . ' · ' . $data['user']['role'] . ' · ' . $data['user']['status'] . ' · ' . $data['user']['country']) : 'None' ?></dd></div>
      <div class="grid gap-1 p-5 sm:grid-cols-[220px_1fr] sm:p-6"><dt class="font-semibold">Open sessions (IP addresses)</dt><dd class="text-soft"><?= count($data['connections']) ?></dd></div>
      <div class="grid gap-1 p-5 sm:grid-cols-[220px_1fr] sm:p-6"><dt class="font-semibold">Activity-log entries</dt><dd class="text-soft"><?= count($data['activity_logs']) ?> (deleted automatically after <?= LOG_RETENTION_MONTHS ?> months)</dd></div>
      <div class="grid gap-1 p-5 sm:grid-cols-[220px_1fr] sm:p-6"><dt class="font-semibold">Invoices as contact</dt><dd class="text-soft"><?= count($data['invoices']) ?> <?= $data['invoices'] ? '(kept 7 years: legal obligation, tax records)' : '' ?></dd></div>
    </dl>
    <div class="flex flex-wrap gap-3 border-t border-line p-5 sm:p-6">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="email" value="<?= e($email) ?>"><input type="hidden" name="action" value="export">
        <button class="pressable inline-flex h-11 items-center gap-2 rounded-xl bg-lime px-5 text-sm font-bold text-navy hover:brightness-95"><?= icon('download', 'h-4 w-4') ?>Download export (JSON)</button></form>
    </div>
  </section>

  <?php if ($data['user']): ?>
  <section class="rise mt-6 rounded-2xl border border-line bg-panel p-5 shadow-panel sm:p-6" style="animation-delay:.1s" aria-labelledby="erase-h">
    <h2 id="erase-h" class="text-lg font-bold tracking-tight">Right to erasure</h2>
    <p class="mt-1 max-w-2xl text-sm text-soft">Anonymising removes the name, email and country, deletes open sessions and their IP addresses, and replaces the person's name in the activity log. It can not be undone. Invoices are not erased because the law requires keeping them for 7 years.</p>
    <form method="post" class="mt-4"><?= csrf_field() ?><input type="hidden" name="email" value="<?= e($email) ?>"><input type="hidden" name="action" value="erase">
      <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="confirm" value="yes" class="mt-0.5 h-5 w-5 rounded border-line accent-lime"><span>I have checked the requester's identity and want to anonymise <?= e($data['user']['name']) ?> permanently.</span></label>
      <button class="pressable mt-4 inline-flex h-11 items-center gap-2 rounded-xl border border-[#FFD3CC]/40 px-5 text-sm font-bold text-[#FFD3CC] hover:bg-[#FFD3CC] hover:text-[#7E160A]">Anonymise this user</button>
    </form>
  </section>
  <?php endif; ?>
<?php endif; ?>
<?php page_end(); ?>
