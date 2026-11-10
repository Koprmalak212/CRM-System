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
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<title><?= e($title) ?> · Telecomlijn Admin</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230BF002'/%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/fonts/fonts.css">
<script src="assets/vendor/tailwind-3.4.17.js"></script>
<?= theme_head() ?>
<style nonce="<?= csp_nonce() ?>">
</style>
</head>
<body class="h-full bg-ground font-sans text-ink antialiased">
<main class="grid min-h-full place-items-center p-6">
  <div class="w-full max-w-sm">
    <img src="assets/telecomlijn-logo-white.png" alt="Telecomlijn" width="520" height="151" class="logo-on-dark mb-8 h-10 w-auto"><img src="assets/telecomlijn-logo-color.png" alt="" aria-hidden="true" width="274" height="79" class="logo-on-light mb-8 h-10 w-auto">
<?php
}

function public_foot(): void
{
    ?>
  </div>
</main>
<div class="fixed bottom-4 right-4 z-20"><?= theme_toggle_button('grid h-10 w-10 place-items-center rounded-xl border border-line bg-panel hover:bg-ink/10') ?></div>
<?= theme_toggle_script() ?>
</body>
</html>
<?php
}

/** Input classes shared by the public pages. */
const PUBLIC_FIELD = 'mt-1.5 h-12 w-full rounded-xl border border-line bg-panel px-4 text-[15px] focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime';

/** The new-password rule used everywhere: 12 to 256 characters. Returns an error message or null. */
function password_problem(string $new, string $confirm, string ...$notContaining): ?string
{
    if (strlen($new) < 12 || strlen($new) > 256) { return 'Use between 12 and 256 characters for the password.'; }
    if ($new !== $confirm) { return 'The password and its confirmation do not match.'; }
    foreach ($notContaining as $bad) { if ($bad !== '' && stripos($new, $bad) !== false) { return 'The password can not contain your user name.'; } }
    return null;
}
