<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/core/layout.php';
require_login();

$GLOBALS['hide_page_title'] = true;   // the entry brings its own headline
page_start('Teletollie', 'none', '', false);
?>
<article class="mx-auto max-w-3xl py-4 sm:py-10" aria-labelledby="entry-h" lang="nl">
  <p class="rise mb-4 text-sm font-bold text-soft">Woordenboek</p>

  <div class="rise overflow-hidden rounded-2xl border border-line bg-panel shadow-panel" style="animation-delay:.05s">
    <div class="flex items-center justify-between gap-3 bg-raised px-5 py-3 sm:px-8">
      <p class="text-sm font-bold">Definitie</p>
      <span class="inline-flex items-center gap-1.5 rounded-full bg-butter px-3 py-1 text-xs font-bold text-navy"><span class="h-1.5 w-1.5 rounded-full bg-navy"></span>A Broken Dream</span>
    </div>

    <div class="px-5 py-7 sm:px-8 sm:py-9">
      <h1 id="entry-h" class="text-4xl font-bold leading-tight tracking-tight sm:text-5xl"><span class="border-b-[3px] border-lime">Teletollie</span></h1>

      <p class="mt-6 text-[17px] leading-relaxed">Informele bijnaam voor Telecomlijn, een telecombedrijf. Mensen gebruiken de naam vaak half lovend, half spottend, bijvoorbeeld wanneer ze het over het bedrijf of de klantenservice hebben.</p>

      <p class="mt-7 text-xs font-bold text-soft">Voorbeeld</p>
      <blockquote class="mt-2 rounded-xl bg-deep px-5 py-4 text-[17px] italic leading-relaxed text-ink">
        &ldquo;Ik moet nog even bellen met Teletollie over mijn factuur.&rdquo;
      </blockquote>
    </div>
  </div>

  <a href="index.php" class="pressable mt-8 inline-flex h-12 items-center gap-2 rounded-xl bg-lime px-6 text-[15px] font-bold text-navy hover:brightness-95"><?= icon('left', 'h-4 w-4') ?>Back to the dashboard</a>
</article>
<?php page_end(); ?>
