<?php
declare(strict_types=1);

require_once __DIR__ . '/theme.php';

/** Plain page shell for screens shown before sign-in (forgot password, reset, invitation): same look as the login page. */
function public_head(string $title): void
{
    ?>
<!doctype html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<?= base_tag() ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<title><?= e($title) ?> · Telecomlijn Admin</title>
<link rel="icon" type="image/svg+xml" href="<?= asset('assets/img/favicon.svg') ?>">
<link rel="stylesheet" href="<?= asset('assets/fonts/fonts.css') ?>">
<?= theme_head() ?>
<style nonce="<?= csp_nonce() ?>">
</style>
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body class="h-full bg-ground font-sans text-ink antialiased">
<main class="grid min-h-full place-items-center p-6">
  <div class="w-full max-w-sm">
    <img src="assets/img/telecomlijn-logo-white.png" alt="Telecomlijn" width="520" height="151" class="logo-on-dark mb-8 h-10 w-auto"><img src="assets/img/telecomlijn-logo-color.png" alt="" aria-hidden="true" width="274" height="79" class="logo-on-light mb-8 h-10 w-auto">
<?php
}

function public_foot(): void
{
    ?>
  </div>
</main>
<div class="fixed bottom-4 right-4 z-20"><?= theme_toggle_button('grid h-10 w-10 place-items-center rounded-lg border border-line bg-panel hover:bg-ink/10') ?></div>
<?= theme_toggle_script() ?>
</body>
</html>
<?php
}

const TOKEN_MAX_FAILS = 10;       // wrong reset or invitation links from one address ...
const TOKEN_WINDOW_MINUTES = 15;  // ... within this many minutes lock link checking for that address

/** Has this address tried too many wrong reset / invitation links? Counted in the same table as failed sign-ins. */
function token_locked(PDO $pdo): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = 'tok:link' AND attempted_at >= ?");
    $s->execute([client_ip(), date('Y-m-d H:i:s', time() - TOKEN_WINDOW_MINUTES * 60)]);
    return (int) $s->fetchColumn() >= TOKEN_MAX_FAILS;
}

function token_fail(PDO $pdo): void
{
    $pdo->prepare("INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, 'tok:link', NOW())")->execute([client_ip()]);
    if (token_locked($pdo)) { log_activity('warning', 'Link guessing locked', 'reset or invitation links', 'anonymous'); }
}

/** Input classes shared by the public pages. */
const PUBLIC_FIELD = 'mt-1.5 h-12 w-full rounded-lg border border-line bg-panel px-4 text-sm focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';

/** The new-password rule used everywhere: 12 to 256 characters. Returns an error message or null. */
function password_problem(string $new, string $confirm, string ...$notContaining): ?string
{
    if (strlen($new) < 12 || strlen($new) > 256) { return 'Use between 12 and 256 characters for the password.'; }
    if ($new !== $confirm) { return 'The password and its confirmation do not match.'; }
    foreach ($notContaining as $bad) { if ($bad !== '' && stripos($new, $bad) !== false) { return 'The password can not contain your user name.'; } }
    return null;
}
