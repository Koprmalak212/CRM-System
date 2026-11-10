<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/modules/mailer.php';
require __DIR__ . '/../includes/core/public_page.php';

if (!empty($_SESSION['admin'])) redirect('index.php');

const RESET_VALID_MINUTES = 60;
const RESET_MAX_PER_HOUR = 3;   // per account: more requests than this are ignored silently

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    $who = mb_substr(trim((string) ($_POST['who'] ?? '')), 0, 190);
    $s = $pdo->prepare('SELECT id, username, email, name FROM admins WHERE (username = ? OR email = ?) AND is_active = 1');
    $s->execute([$who, $who]);
    $row = $who !== '' ? $s->fetch() : false;
    $token = bin2hex(random_bytes(32));   // always made, so a request for an unknown account does the same work
    $hash = hash('sha256', $token);
    $base = mail_base_url($pdo);
    if ($row && $base !== null) {
        $recent = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE admin_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
        $recent->execute([$row['id']]);
        if ((int) $recent->fetchColumn() < RESET_MAX_PER_HOUR) {
            $pdo->prepare('DELETE FROM password_resets WHERE admin_id = ? AND (used_at IS NOT NULL OR expires_at < NOW())')->execute([$row['id']]);
            $pdo->prepare('INSERT INTO password_resets (admin_id, token_hash, expires_at, ip, created_at) VALUES (?, ?, NOW() + INTERVAL ' . RESET_VALID_MINUTES . ' MINUTE, ?, NOW())')->execute([$row['id'], $hash, client_ip()]);
            mail_send($pdo, (string) $row['email'], 'Reset your Telecomlijn Admin password',
                "Hello " . $row['name'] . ",\n\nSomeone asked to reset the password for your account (" . $row['username'] . "). Open this link within " . RESET_VALID_MINUTES . " minutes to choose a new password:\n\n"
                . $base . '/auth/reset.php?token=' . $token . "\n\nThe link works once. If you did not ask for this, ignore this email: your password stays as it is.\n", 'reset');
            log_activity('warning', 'Password reset requested', (string) $row['username'], 'anonymous');
        }
    } elseif ($row) {
        log_activity('warning', 'Password reset not sent', 'no site address set in Settings > Email', 'anonymous');
    } else {
        log_activity('info', 'Password reset requested', 'unknown account', 'anonymous');   // the visitor sees exactly the same page either way
    }
    $sent = true;
}

public_head('Forgot password');
?>
    <h1 class="text-3xl font-bold tracking-tight">Forgot your password?</h1>
<?php if ($sent): ?>
    <p role="status" class="mt-6 rounded-xl bg-raised px-4 py-3 text-sm">If an account matches what you entered, an email with a reset link is on its way. The link works for <?= RESET_VALID_MINUTES ?> minutes and only once.</p>
    <?php if (mail_is_local()): ?><p class="mt-3 text-sm text-soft">On localhost no mail server is set up: an admin can read the message in Settings &gt; Email &gt; Outbox.</p><?php endif; ?>
    <p class="mt-6 text-sm"><a href="auth/login.php" class="font-semibold underline decoration-lime decoration-2 underline-offset-4">Back to sign in</a></p>
<?php else: ?>
    <p class="mt-2 text-[15px] text-soft">Enter your username or email address and we will send you a link to choose a new one.</p>
    <form method="post" class="mt-6" novalidate><?= csrf_field() ?>
      <label for="who" class="block text-sm font-semibold">Username or email</label>
      <input id="who" name="who" type="text" autocomplete="username" required autofocus maxlength="190" class="<?= PUBLIC_FIELD ?>">
      <button class="mt-6 h-12 w-full rounded-xl bg-lime text-[15px] font-bold text-navy hover:brightness-95">Send the reset link</button>
    </form>
    <p class="mt-6 text-center text-sm"><a href="auth/login.php" class="font-semibold text-soft underline decoration-lime decoration-2 underline-offset-4 hover:text-ink">Back to sign in</a></p>
<?php endif; ?>
<?php public_foot();
