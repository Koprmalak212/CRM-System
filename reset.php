<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/public_page.php';

if (!empty($_SESSION['admin'])) redirect('index.php');
$pdo = db();

/** The reset row for a token, or null when it is unknown, used, expired or the account is gone or disabled. */
function reset_lookup(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) { return null; }
    $s = $pdo->prepare('SELECT r.id AS rid, r.admin_id, a.username, a.name FROM password_resets r JOIN admins a ON a.id = r.admin_id
        WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND a.is_active = 1');
    $s->execute([hash('sha256', $token)]);
    return $s->fetch() ?: null;
}

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$row = reset_lookup($pdo, $token);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row) {
    csrf_check();
    $new = substr((string) ($_POST['new'] ?? ''), 0, 1024);
    $error = password_problem($new, substr((string) ($_POST['confirm'] ?? ''), 0, 1024), (string) $row['username']) ?? '';
    if ($error === '') {
        $pdo->beginTransaction();
        try {
            // claim the token first: only one of two simultaneous submissions can win
            $claim = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
            $claim->execute([$row['rid']]);
            if ($claim->rowCount() !== 1) { $pdo->rollBack(); redirect('reset.php?token=used'); }
            $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0, session_epoch = session_epoch + 1 WHERE id = ?')->execute([hash_password($new), $row['admin_id']]);   // the new epoch ends every session that is already open
            $pdo->prepare('DELETE FROM password_resets WHERE admin_id = ?')->execute([$row['admin_id']]);
            $pdo->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([mb_substr(strtolower((string) $row['username']), 0, 64)]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        log_activity('warning', 'Password reset completed', (string) $row['username'], (string) $row['username']);
        redirect('login.php?reset=1');
    }
}

public_head('Choose a new password');
?>
<?php if (!$row): ?>
    <h1 class="text-3xl font-bold tracking-tight">This link does not work</h1>
    <p role="alert" class="mt-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]">The reset link is not valid, has expired or was already used. Ask for a new one.</p>
    <p class="mt-6 text-sm"><a href="forgot.php" class="font-semibold underline decoration-lime decoration-2 underline-offset-4">Send a new reset link</a> · <a href="login.php" class="font-semibold underline decoration-lime decoration-2 underline-offset-4">Sign in</a></p>
<?php else: ?>
    <h1 class="text-3xl font-bold tracking-tight">Choose a new password</h1>
    <p class="mt-2 text-[15px] text-soft">For <strong class="text-ink notranslate"><?= e($row['username']) ?></strong>. Use at least 12 characters. Everywhere you are signed in will be signed out.</p>
    <?php if ($error): ?><p role="alert" class="mt-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="mt-6" novalidate><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <label for="new" class="block text-sm font-semibold">New password</label>
      <input id="new" name="new" type="password" autocomplete="new-password" required minlength="12" autofocus class="<?= PUBLIC_FIELD ?>">
      <label for="confirm" class="mt-4 block text-sm font-semibold">Confirm new password</label>
      <input id="confirm" name="confirm" type="password" autocomplete="new-password" required class="<?= PUBLIC_FIELD ?>">
      <button class="mt-6 h-12 w-full rounded-xl bg-lime text-[15px] font-bold text-navy hover:brightness-95">Save the new password</button>
    </form>
<?php endif; ?>
<?php public_foot();
