<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_login();

$name = 'Denis Mehmed';
$built = [
    'The overview, with the service-health ribbon that rises bar by bar',
    'Users, connections and activity logs, with search, filters and sorting',
    'Invoices and payments: create, track due and overdue, record payments',
    'Reports and backups, with downloads and one-click runs',
    'Dark mode, the pop-out sidebar and the Disney-principles animation system',
    'A language toggle for 100+ languages, with consent before anything leaves the page',
    'Login protection, rate limiting, privacy tools and the Telecomlijn branding',
];

$GLOBALS['hide_page_title'] = true;   // the page brings its own headline
page_start('Creator', 'none', '', false);
?>
<section class="relative mx-auto max-w-3xl py-6 text-center sm:py-12" aria-labelledby="credit-h">
  <p class="rise text-sm font-semibold text-lime">You found the secret page</p>
  <h1 id="credit-h" class="mt-3 text-4xl font-bold leading-tight tracking-tight sm:text-6xl" aria-label="Built by <?= e($name) ?>">
    <span class="block text-soft" aria-hidden="true">Built by</span>
    <span class="mt-1 block" aria-hidden="true"><?php
      $i = 0;
      foreach (mb_str_split($name) as $ch) {
          if ($ch === ' ') { echo ' '; continue; }
          echo '<span class="credit-letter inline-block" style="--i:' . $i++ . '">' . e($ch) . '</span>';
      }
    ?></span>
  </h1>
  <p class="rise mx-auto mt-6 max-w-xl text-lg leading-relaxed text-soft" style="animation-delay:.2s">Every page, table, animation and safeguard in this dashboard was designed and built by <?= e($name) ?>, in November 2026.</p>

  <div class="mx-auto mt-10 flex max-w-xs justify-center gap-1.5" aria-hidden="true">
    <?php foreach ([1, 1, 1, 1, 2, 1, 1, 1, 0, 1, 1, 1, 1, 2, 1, 1, 1, 1, 1, 1] as $k => $s): ?>
      <span style="--i:<?= $k ?>" class="wave h-9 w-2 rounded-sm <?= $s === 1 ? 'bg-lime' : ($s === 2 ? 'bg-butter' : 'bg-coral') ?>"></span>
    <?php endforeach; ?>
  </div>

  <div class="rise mx-auto mt-12 max-w-2xl rounded-2xl border border-line bg-panel p-6 text-left shadow-panel sm:p-8" style="animation-delay:.3s">
    <h2 class="text-lg font-bold tracking-tight">What was built</h2>
    <ul class="mt-4 space-y-3 text-[15px] leading-relaxed">
      <?php foreach ($built as $line): ?>
        <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-lime"></span><span><?= e($line) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="mt-10 flex flex-wrap items-center justify-center gap-3">
    <a href="https://www.linkedin.com/in/denis-mehmed-951b982b7/" target="_blank" rel="noopener noreferrer"
       class="pressable inline-flex h-12 items-center gap-2.5 rounded-xl bg-[#0A66C2] px-6 text-[15px] font-bold text-white hover:brightness-110">
      <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.03-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 1 1 0-4.12 2.06 2.06 0 0 1 0 4.12zM7.12 20.45H3.56V9h3.56v11.45z"/></svg>
      Connect on LinkedIn<span class="sr-only"> (opens in a new tab)</span>
    </a>
    <a href="index.php" class="pressable inline-flex h-12 items-center gap-2 rounded-xl bg-lime px-6 text-[15px] font-bold text-navy hover:brightness-95"><?= icon('left', 'h-4 w-4') ?>Back to the dashboard</a>
  </div>
</section>

<style nonce="<?= csp_nonce() ?>">
  .credit-letter { animation: credit-letter .7s var(--ease-spring) backwards; animation-delay: calc(var(--i) * 55ms + .15s); }
  @keyframes credit-letter {
    0%   { opacity: 0; transform: translateY(28px) scale(.5) rotate(-8deg); }
    60%  { opacity: 1; transform: translateY(-6px) scale(1.12) rotate(2deg); }
    100% { opacity: 1; transform: none; }
  }
  @media (prefers-reduced-motion: reduce) { .credit-letter { animation: none; } }
</style>
<?php page_end(); ?>
