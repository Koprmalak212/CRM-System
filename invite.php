<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/twofactor.php';
require __DIR__ . '/includes/public_page.php';

if (!empty($_SESSION['admin'])) redirect('index.php');
$pdo = db();

/** The invitation for a token, or null when it is unknown, used or expired. */
function invite_lookup(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) { return null; }
    $s = $pdo->prepare('SELECT * FROM invitations WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
    $s->execute([hash('sha256', $token)]);
    return $s->fetch() ?: null;
}

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$inv = invite_lookup($pdo, $token);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inv) {
    csrf_check();
    $new = substr((string) ($_POST['new'] ?? ''), 0, 1024);
    $error = password_problem($new, substr((string) ($_POST['confirm'] ?? ''), 0, 1024), (string) $inv['username']) ?? '';
    if ($error === '') {
        $pdo->beginTransaction();
        try {
            $claim = $pdo->prepare('UPDATE invitations SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
            $claim->execute([$inv['id']]);
            if ($claim->rowCount() !== 1) { $pdo->rollBack(); redirect('invite.php?token=used'); }
            $taken = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ? OR email = ?');
            $taken->execute([$inv['username'], $inv['email']]);
            if ((int) $taken->fetchColumn() > 0) { $pdo->rollBack(); $error = 'An account with this username or email address already exists. Ask the admin for a new invitation.'; }
            else {
                $pdo->prepare('INSERT INTO admins (username, email, name, password_hash, role, perm_integrations, must_change_password) VALUES (?,?,?,?,?,?,0)')
                    ->execute([$inv['username'], $inv['email'], $inv['name'], hash_password($new), $inv['role'], $inv['role'] === 'admin' ? 'manage' : $inv['perm_integrations']]);
                $adminId = (int) $pdo->lastInsertId();
                $pdo->commit();
                log_activity('warning', 'Invitation accepted', (string) $inv['username'], (string) $inv['username']);
                $row = $pdo->prepare('SELECT * FROM admins WHERE id = ?'); $row->execute([$adminId]);
                flash('Welcome, ' . $inv['name'] . '! Your account is ready. You can add a second sign-in step below if you like.');
                login_complete($pdo, $row->fetch(), 'settings.php?tab=account');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
}

public_head('Join the team');
?>
<?php if (!$inv): ?>
    <h1 class="text-3xl font-bold tracking-tight">This invitation does not work</h1>
    <p role="alert" class="mt-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]">The invitation is not valid, has expired or was already used. Ask an admin to send a new one.</p>
    <p class="mt-6 text-sm"><a href="login.php" class="font-semibold underline decoration-lime decoration-2 underline-offset-4">Sign in</a></p>
<?php else: ?>
    <h1 class="text-3xl font-bold tracking-tight">Join Telecomlijn Admin</h1>
    <p class="mt-2 text-[15px] text-soft">Hello <?= e($inv['name']) ?>, you were invited as <strong class="text-ink notranslate"><?= e($inv['username']) ?></strong> (<?= $inv['role'] === 'admin' ? 'admin' : 'user' ?>). Choose a password of at least 12 characters.</p>
    <?php if ($error): ?><p role="alert" class="mt-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="mt-6" novalidate><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <label for="new" class="block text-sm font-semibold">Password</label>
      <input id="new" name="new" type="password" autocomplete="new-password" required minlength="12" autofocus class="<?= PUBLIC_FIELD ?>">
      <label for="confirm" class="mt-4 block text-sm font-semibold">Confirm password</label>
      <input id="confirm" name="confirm" type="password" autocomplete="new-password" required class="<?= PUBLIC_FIELD ?>">
      <button class="mt-6 h-12 w-full rounded-xl bg-lime text-[15px] font-bold text-navy hover:brightness-95">Create my account</button>
    </form>
<?php endif; ?>
<?php public_foot();
