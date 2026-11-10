<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require_once __DIR__ . '/../includes/core/theme.php';
require __DIR__ . '/../includes/core/translate.php';
require __DIR__ . '/../includes/modules/monitor.php';
require __DIR__ . '/../includes/modules/twofactor.php';

if (!empty($_SESSION['admin'])) redirect('index.php');
$pend = $_SESSION['pending_2fa'] ?? null;
if (!is_array($pend) || time() - (int) ($pend['t'] ?? 0) > TOTP_PENDING_SECONDS) {
    unset($_SESSION['pending_2fa']);
    redirect('auth/login.php');   // nobody gets here without the right password first
}

$pdo = db();
$error = '';
$s = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 AND totp_enabled = 1');
$s->execute([(int) $pend['id']]);
$row = $s->fetch();
if (!$row) { unset($_SESSION['pending_2fa']); redirect('auth/login.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Same lock-out as the password step, stored per address in the database: a wrong code counts like a wrong password.
    if (tfa_locked($pdo, (string) $row['username'])) {
        unset($_SESSION['pending_2fa']);   // locked: signing in with the password again will not help until the window has passed
        redirect('auth/login.php?locked=1');
    }
    $how = twofactor_check($pdo, $row, substr((string) ($_POST['code'] ?? ''), 0, 40));
    if ($how !== null) {
        tfa_clear($pdo, (string) $row['username']);
        if ($how === 'recovery') { log_activity('warning', 'Signed in with a recovery code', (string) $row['username'], (string) $row['username']); }
        login_complete($pdo, $row);
    }
    tfa_fail($pdo, (string) $row['username']);
    $error = 'That code is not right. Check the code in your app (it changes every 30 seconds) and try again.';
}
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<?= base_tag() ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Two-step sign-in · Telecomlijn Admin</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230BF002'/%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/fonts/fonts.css">
<?= theme_head() ?>
<style nonce="<?= csp_nonce() ?>">
</style>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="h-full bg-ground font-sans text-ink antialiased">
<main class="grid min-h-full place-items-center p-6">
  <form method="post" class="w-full max-w-sm" novalidate>
    <?= csrf_field() ?>
    <img src="assets/img/telecomlijn-logo-white.png" alt="Telecomlijn" width="520" height="151" class="logo-on-dark mb-8 h-10 w-auto"><img src="assets/img/telecomlijn-logo-color.png" alt="" aria-hidden="true" width="274" height="79" class="logo-on-light mb-8 h-10 w-auto">
    <h1 class="text-3xl font-bold tracking-tight">Two-step sign-in</h1>
    <p class="mt-2 text-[15px] text-soft">Open your authenticator app and enter the 6-digit code for Telecomlijn Admin. Lost your phone? Enter one of your recovery codes instead.</p>
    <?php if ($error): ?><p role="alert" class="mt-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]"><?= e($error) ?></p><?php endif; ?>
    <label for="code" class="mt-6 block text-sm font-semibold">Code</label>
    <input id="code" name="code" type="text" inputmode="text" autocomplete="one-time-code" autofocus required maxlength="40" spellcheck="false"
           class="mt-1.5 h-12 w-full rounded-xl border border-line bg-panel px-4 text-center text-xl tracking-widest focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime">
    <button class="mt-6 h-12 w-full rounded-xl bg-lime text-[15px] font-bold text-navy hover:brightness-95">Verify and sign in</button>
    <p class="mt-5 text-center text-sm"><a href="auth/login.php" class="font-semibold text-soft underline decoration-lime decoration-2 underline-offset-4 hover:text-ink">Back to the password step</a></p>
  </form>
</main>
<div class="fixed bottom-4 right-4 z-20"><?= theme_toggle_button('grid h-10 w-10 place-items-center rounded-xl border border-line bg-panel hover:bg-ink/10') ?></div>
<?= theme_toggle_script() ?>
</body>
</html>
