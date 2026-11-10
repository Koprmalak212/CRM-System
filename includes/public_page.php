<?php
declare(strict_types=1);

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
<script nonce="<?= csp_nonce() ?>">
tailwind.config = { theme: { extend: {
  fontFamily: { sans: ['"Hanken Grotesk"', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
  colors: { navy: { DEFAULT: '#111D36', 800: '#18284A', 700: '#22355E' }, lime: '#C7F36B', butter: { DEFAULT: '#FFF1A8' }, ground: '#0A1226', panel: '#111D36', raised: '#1B2C50', line: '#233559', ink: '#EEF2FA', soft: '#A5B2CF'
} } };
</script>
<style nonce="<?= csp_nonce() ?>">
  html { color-scheme: dark; background: #0A1226; }
  :focus-visible { outline: 2px solid #C7F36B; outline-offset: 2px; border-radius: 6px; }
  input { caret-color: #C7F36B; color: #EEF2FA; }
</style>
</head>
<body class="h-full bg-ground font-sans text-ink antialiased">
<main class="grid min-h-full place-items-center p-6">
  <div class="w-full max-w-sm">
    <img src="assets/telecomlijn-logo-white.png" alt="Telecomlijn" width="520" height="151" class="mb-8 h-10 w-auto">
<?php
}

function public_foot(): void
{
    ?>
  </div>
</main>
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
