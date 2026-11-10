<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/translate.php';
require __DIR__ . '/includes/twofactor.php';

if (!empty($_SESSION['admin'])) redirect('index.php');

// Brute-force protection is stored in the database per IP, so clearing cookies does not reset it.
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_PER_USER = 5;     // failures for one username from one IP
const LOGIN_MAX_PER_IP = 15;      // failures from one IP across any usernames
const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$WjRTTktmdmxua2Jic1gxZQ$2Y/LbzlvVhqHaFctMmqLPXh1xrfIdUYfKACfWc7VIRs';   // equalises timing for unknown users

$error = '';
if (!empty($_GET['locked'])) { $error = 'Too many failed attempts. Wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.'; }
if (!empty($_SESSION['expired'])) {
    $error = 'You were signed out because the session was idle too long. Sign in again to continue.';
    unset($_SESSION['expired']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    $ip = client_ip();
    $user = mb_substr(trim((string) ($_POST['username'] ?? '')), 0, 190);
    $pass = substr((string) ($_POST['password'] ?? ''), 0, 1024);
    $key = mb_substr(strtolower($user), 0, 64);
    $window = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MINUTES * 60);

    $byIp = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
    $byIp->execute([$ip, $window]);
    $byUser = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND attempted_at >= ?');
    $byUser->execute([$ip, $key, $window]);

    if ((int) $byIp->fetchColumn() >= LOGIN_MAX_PER_IP || (int) $byUser->fetchColumn() >= LOGIN_MAX_PER_USER) {
        $error = 'Too many failed attempts. Wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, email, name, password_hash, must_change_password, totp_enabled FROM admins WHERE (username = ? OR email = ?) AND is_active = 1');
        $stmt->execute([$user, $user]);
        $row = $stmt->fetch();
        $ok = password_verify($pass, $row['password_hash'] ?? DUMMY_HASH) && $row;   // always hash-check, so timing does not reveal valid usernames
        if ($ok) {
            if (password_needs_rehash($row['password_hash'], password_algo())) {
                $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([hash_password($pass), $row['id']]);
            }
            $pdo->prepare('DELETE FROM login_attempts WHERE ip = ? AND username = ?')->execute([$ip, $key]);
            if ((int) $row['totp_enabled'] === 1) {
                // right password, but this account has two-step sign-in: nobody is signed in until the code step is done
                session_regenerate_id(true);
                unset($_SESSION['csrf']);
                $_SESSION['pending_2fa'] = ['id' => (int) $row['id'], 't' => time()];
                redirect('login_2fa.php');
            }
            login_complete($pdo, $row);
        }
        $pdo->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, NOW())')->execute([$ip, $key]);
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
        // Log the failure without ever storing what was typed as a password; odd-looking usernames are masked.
        $shown = preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $user) ? $user : '[unusual input]';
        log_activity('warning', 'Failed sign-in', $shown, 'anonymous');
        $error = 'That username and password do not match. Check both and try again.';
    }
}
// The demo credentials are only shown while the default password is still in place.
$showDemo = false;
try {
    $h = db()->query("SELECT password_hash FROM admins WHERE username = 'admin' AND is_active = 1")->fetchColumn();
    $showDemo = $h && password_verify('admin', (string) $h);   // the hint disappears by itself once the demo password is changed
} catch (Throwable $e) { /* hint is optional */ }
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Telecomlijn Admin</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230BF002'/%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/fonts/fonts.css">
<script src="assets/vendor/tailwind-3.4.17.js"></script>
<script nonce="<?= csp_nonce() ?>">
tailwind.config = { theme: { extend: {
  fontFamily: { sans: ['"Hanken Grotesk"', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
  colors: { navy: { DEFAULT: '#111D36', 800: '#18284A', 700: '#22355E' }, lime: '#C7F36B', butter: { DEFAULT: '#FFF1A8' }, ground: '#0A1226', panel: '#111D36', raised: '#1B2C50', line: '#233559', ink: '#EEF2FA', soft: '#A5B2CF', coral: '#F0654F' }
} } };
</script>
<style nonce="<?= csp_nonce() ?>">
  html { color-scheme: dark; background: #0A1226; }
  ::selection { background: #C7F36B; color: #111D36; }
  :focus-visible { outline: 2px solid #C7F36B; outline-offset: 2px; border-radius: 6px; }
  input { caret-color: #C7F36B; color: #EEF2FA; }
  input::placeholder { color: #8493B4; }
  @keyframes hop { 0% { transform: translateX(-10px); opacity: .6; } 55% { transform: translateX(3px); opacity: 1; } 100% { transform: none; } }
  .hop { animation: hop .45s cubic-bezier(.34,1.56,.64,1); }
</style>
<script nonce="<?= csp_nonce() ?>">document.documentElement.classList.add('js');setTimeout(function(){if(!window.__motion)document.documentElement.classList.add('reveal-all')},3000);</script>
<link rel="stylesheet" href="assets/motion.css">
</head>
<body class="h-full bg-ground font-sans text-ink antialiased">
<?= lang_widget('absolute right-4 top-4 z-10 sm:right-8 sm:top-6') ?>
<div class="grid min-h-full lg:grid-cols-[1.05fr_1fr]">
  <section class="relative hidden flex-col justify-between overflow-hidden bg-navy p-12 text-white lg:flex">
    <img src="assets/telecomlijn-logo-white.png" alt="Telecomlijn" width="520" height="151" class="h-11 w-auto self-start">
    <div class="max-w-md">
      <h1 class="text-5xl font-bold leading-[1.05] tracking-tight text-balance">Every line running, <span class="text-lime">at a glance.</span></h1>
      <p class="mt-5 text-lg text-[#B9C4DD]">Users, connections, reports, backups and activity logs for the Telecomlijn platform, in one place.</p>
    </div>
    <div class="flex gap-1.5" aria-hidden="true">
      <?php foreach ([1,1,1,1,1,0,1,1,1,1,1,1,2,1,1,1,1,1,1,1,1,1,1,1] as $k => $s): ?>
        <span style="--i:<?= $k ?>" class="wave h-7 w-2 rounded-sm <?= $s === 1 ? 'bg-lime' : ($s === 2 ? 'bg-butter' : 'bg-coral') ?>"></span>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="relative flex items-center justify-center p-6 pb-20 sm:p-12 sm:pb-20">
    <form method="post" class="rise w-full max-w-sm" novalidate>
      <?= csrf_field() ?>
      <img src="assets/telecomlijn-logo-white.png" alt="Telecomlijn" width="520" height="151" class="mb-8 h-10 w-auto lg:hidden">
      <h2 class="text-3xl font-bold tracking-tight">Sign in</h2>
      <p class="mt-2 text-[15px] text-soft">Use your admin account to continue.</p>

      <?php if ($error): ?>
        <p role="alert" class="mt-6 rounded-xl bg-[#FFD3CC] px-4 py-3 text-sm font-medium text-[#7E160A]"><?= e($error) ?></p>
      <?php endif; ?>

      <label for="username" class="mt-6 block text-sm font-semibold">Username</label>
      <input id="username" name="username" type="text" autocomplete="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>"
             class="mt-1.5 h-12 w-full rounded-xl border border-line bg-panel px-4 text-[15px] focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime">

      <label for="password" class="mt-4 block text-sm font-semibold">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required
             class="mt-1.5 h-12 w-full rounded-xl border border-line bg-panel px-4 text-[15px] focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime">

      <button class="mt-6 h-12 w-full rounded-xl bg-lime text-[15px] font-bold text-navy shadow-[0_6px_18px_-8px_rgba(199,243,107,.6)] transition hover:brightness-95 active:translate-y-px">Sign in</button>

      <?php if ($showDemo): ?>
      <p class="mt-6 rounded-xl bg-butter px-4 py-3 text-sm text-navy">Demo account: <strong class="font-semibold">admin</strong> / <strong class="font-semibold">admin</strong>.</p>
      <?php endif; ?>
    </form>
    <footer class="absolute inset-x-0 bottom-5 px-6 text-center text-xs text-soft">
      <p>© <?= date('Y') ?> Telecomlijn Zakelijk · KvK 65586492 · <a href="privacybeleid.php" class="notranslate font-semibold text-ink underline decoration-lime decoration-2 underline-offset-4 hover:decoration-white">Privacybeleid</a></p>
      <p class="mt-2"><button type="button" id="trWithdraw" hidden class="font-semibold underline decoration-lime decoration-2 underline-offset-4 hover:text-ink">Withdraw translation consent</button></p>
    </footer>
  </section>
</div>
<script src="assets/motion.js" defer></script>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var u = document.getElementById('username'), p = document.getElementById('password');
  function hop(to) {
    to.focus();
    to.classList.remove('hop'); void to.offsetWidth; to.classList.add('hop');
  }
  // Enter moves between the fields; it only submits once both are filled in.
  u.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && u.value.trim() !== '' && p.value === '') { e.preventDefault(); hop(p); }
    else if (e.key === 'Enter' && u.value.trim() === '') { e.preventDefault(); }
  });
  p.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && p.value !== '' && u.value.trim() === '') { e.preventDefault(); hop(u); }
    else if (e.key === 'Enter' && p.value === '') { e.preventDefault(); }
  });
})();
</script>
</body>
</html>
