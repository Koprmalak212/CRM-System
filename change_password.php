<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/theme.php';
$admin = require_login();
$pdo = db();
$forced = !empty($admin['must_change']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = substr((string) ($_POST['current'] ?? ''), 0, 1024);
    $new = substr((string) ($_POST['new'] ?? ''), 0, 1024);
    $confirm = substr((string) ($_POST['confirm'] ?? ''), 0, 1024);
    $s = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $s->execute([$admin['id']]);
    $hash = (string) $s->fetchColumn();
    $weak = ['admin', 'password', 'wachtwoord', 'telecomlijn', 'changeme'];
    if (!password_verify($current, $hash)) {
        $error = 'Your current password is not correct.';
    } elseif (strlen($new) < 12 || strlen($new) > 256) {
        $error = 'Use between 12 and 256 characters. A short sentence works well.';
    } elseif (hash_equals($current, $new)) {
        $error = 'Choose a password that is different from the current one.';
    } elseif ($new !== $confirm) {
        $error = 'The new password and its confirmation do not match.';
    } elseif (str_contains(strtolower($new), strtolower((string) $admin['username'])) || in_array(strtolower($new), $weak, true)) {
        $error = 'That password is too easy to guess. Do not use your username or a common word.';
    } else {
        $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([hash_password($new), $admin['id']]);
        session_regenerate_id(true);
        unset($_SESSION['csrf']);
        $_SESSION['admin']['must_change'] = false;
        log_activity('warning', 'Changed password', (string) $admin['username']);
        flash('Password updated. You can now use the dashboard.');
        redirect('index.php');
    }
}
$field = 'mt-1.5 h-12 w-full rounded-xl border border-line bg-panel px-4 text-[15px] focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Choose a new password · Telecomlijn Admin</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230BF002'/%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/fonts/fonts.css">
<script src="assets/vendor/tailwind-3.4.17.js"></script>
<?= theme_head() ?>
<style nonce="<?= csp_nonce() ?>">
</style>
</head>
<body class="min-h-full bg-ground font-sans text-ink antialiased">
<main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
  <img src="assets/telecomlijn-logo-white.png" alt="Telecomlijn" width="520" height="151" class="logo-on-dark mb-8 h-10 w-auto self-start"><img src="assets/telecomlijn-logo-color.png" alt="" aria-hidden="true" width="274" height="79" class="logo-on-light mb-8 h-10 w-auto self-start">
  <h1 class="text-3xl font-bold tracking-tight">Choose a new password</h1>
  <p class="mt-2 text-[15px] text-soft"><?= $forced ? 'You are signed in with a temporary password. Replace it to continue.' : 'Pick a password only you know.' ?></p>
  <?php if ($error): ?><p role="alert" class="mt-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="mt-6" novalidate><?= csrf_field() ?>
    <label for="current" class="block text-sm font-semibold">Current password</label>
    <input id="current" name="current" type="password" autocomplete="current-password" required autofocus class="<?= $field ?>">
    <label for="new" class="mt-4 block text-sm font-semibold">New password</label>
    <input id="new" name="new" type="password" autocomplete="new-password" required minlength="12" aria-describedby="newHelp" class="<?= $field ?>">
    <p id="newHelp" class="mt-1 text-xs text-soft">At least 12 characters. It can not contain your username.</p>
    <label for="confirm" class="mt-4 block text-sm font-semibold">Confirm new password</label>
    <input id="confirm" name="confirm" type="password" autocomplete="new-password" required class="<?= $field ?>">
    <button class="mt-6 h-12 w-full rounded-xl bg-lime text-[15px] font-bold text-navy hover:brightness-95">Save new password</button>
  </form>
  <form method="post" action="logout.php" class="mt-4"><?= csrf_field() ?><button class="text-sm font-semibold text-soft underline decoration-lime decoration-2 underline-offset-4 hover:text-ink">Sign out instead</button></form>
</main>
<div class="fixed bottom-4 right-4 z-20"><?= theme_toggle_button('grid h-10 w-10 place-items-center rounded-xl border border-line bg-panel hover:bg-ink/10') ?></div>
<?= theme_toggle_script() ?>
</body>
</html>
