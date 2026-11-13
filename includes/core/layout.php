<?php
declare(strict_types=1);

require_once __DIR__ . '/translate.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/../modules/notifications.php';

function page_start(string $title, string $active, string $lede = '', bool $demoNote = true): void
{
    $admin = $_SESSION['admin'];
    $nav = [
        'overview' => ['index.php', 'Overview', 'dashboard'],
        'users' => ['users/users.php', 'Users', 'users'],
        'companies' => ['companies/companies.php', 'Companies', 'building'],
        'calendar' => ['calendar/calendar.php', 'Calendar', 'calendar'],
        'tickets' => ['tickets/tickets.php', 'Tickets', 'ticket'],
        'calls' => ['calls/calls.php', 'Calls', 'phone'],
        'links' => ['links/links.php', 'Short links', 'link'],
        'connections' => ['monitoring/connections.php', 'Connections', 'network'],
        'uptime' => ['monitoring/uptime.php', 'Uptime', 'gauge'],
        'quotes' => ['quotes/quotes.php', 'Quotes', 'quote'],
        'contracts' => ['contracts/contracts.php', 'Contracts', 'contract'],
        'invoices' => ['invoices/invoices.php', 'Invoices', 'card'],
        'reports' => ['system/reports.php', 'Reports', 'file'],
        'backups' => ['system/backups.php', 'Backups', 'database'],
        'logs' => ['system/logs.php', 'Activity logs', 'activity'],
    ];
    $nav = array_filter($nav, 'module_enabled', ARRAY_FILTER_USE_KEY);   // menu keys are module keys; overview, users and companies are core
    // the menu in groups; a group whose modules are all off is left out together with its heading
    $navGroups = ['' => ['overview'], 'Customers' => ['users', 'companies', 'tickets', 'calls', 'calendar'], 'Sales' => ['quotes', 'contracts', 'invoices'], 'Monitoring' => ['connections', 'uptime', 'links'], 'System' => ['reports', 'backups', 'logs']];
    // what the "New" button in the top bar offers: [page, label, module or '' for the core]
    $newItems = array_filter([['tickets/ticket_new.php', 'Ticket', 'tickets'], ['companies/company_edit.php', 'Company', ''], ['users/user_new.php', 'User', ''], ['invoices/invoice_new.php', 'Invoice', 'invoices'],
        ['quotes/quote_edit.php', 'Quote', 'quotes'], ['contracts/contract_edit.php', 'Contract', 'contracts'], ['calendar/appointment_edit.php', 'Appointment', 'calendar']], fn($i) => $i[2] === '' || module_enabled($i[2]));
    $flag = flash();
    // a deletion that can still be undone (undo_remember() in bootstrap.php): the message that reports it carries the Undo button, once
    $undo = $flag && $flag['k'] === 'ok' && !empty($_SESSION['undo']['offer']) && time() - (int) ($_SESSION['undo']['t'] ?? 0) < 60;
    if (isset($_SESSION['undo'])) { $_SESSION['undo']['offer'] = false; }
    try { notifications_refresh(db()); $bell = notifications_bell(db()); }
    catch (Throwable $e) { $bell = ''; error_log('Notifications unavailable: ' . $e->getMessage()); }   // a problem here must never stop a page from loading
    ?>
<!doctype html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<?= base_tag() ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Telecomlijn Admin</title>
<link rel="icon" type="image/svg+xml" href="<?= asset('assets/img/favicon.svg') ?>">
<link rel="stylesheet" href="<?= asset('assets/fonts/fonts.css') ?>">
<?= theme_head() ?>
<style nonce="<?= csp_nonce() ?>">
  html { font-feature-settings: "tnum" 1; }
  /* Layout safety nets: grid children may shrink below their content, and scroll boxes contain their own hidden labels */
  main .grid > * { min-width: 0; }
  .overflow-x-auto { position: relative; }
  /* Hovering a chart bar uses the cursor-following tooltip from motion.js (it reads data-tip); a keyboard user gets the same text on focus */
  .vbar { position: relative; }
  .vbar:focus-visible { z-index: 5; outline-offset: 0; }
  .vbar:focus-visible::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: #EEF2FA; color: #111D36; padding: 3px 9px; border-radius: 8px; font-size: 12px; font-weight: 600; white-space: nowrap; pointer-events: none; }
  .bell-btn::-webkit-details-marker { display: none; }
  .bell-ic { transform-origin: 50% 0; }
  @keyframes bell-ring { 0%, 100% { transform: rotate(0); } 15% { transform: rotate(14deg); } 30% { transform: rotate(-12deg); } 45% { transform: rotate(8deg); } 60% { transform: rotate(-5deg); } 75% { transform: rotate(2deg); } }
  /* the bell only moves for people who have not asked for reduced motion */
  @media (prefers-reduced-motion: no-preference) { .bell-btn[data-unread] .bell-ic { animation: bell-ring 1.4s ease-in-out .8s 2; } }
</style>
<script nonce="<?= csp_nonce() ?>">document.documentElement.classList.add('js');try{var s=localStorage.getItem('sbPinned');var p=s===null?!matchMedia('(hover:hover)').matches:s==='1';if(p)document.documentElement.dataset.sb='pinned'}catch(e){};try{if(localStorage.getItem('sbSeen'))document.documentElement.dataset.sbseen='1'}catch(e){}setTimeout(function(){if(!window.__motion)document.documentElement.classList.add('reveal-all')},3000);</script>
<link rel="stylesheet" href="<?= asset('assets/css/motion.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/cards.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body class="h-full bg-ground font-sans text-ink antialiased" data-idle="<?= SESSION_IDLE_SECONDS ?>">
<a href="<?= self_url() ?>#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-lime focus:px-4 focus:py-2 focus:font-semibold focus:text-navy">Skip to content</a>

<div class="flex min-h-full">
  <div id="sbEdge" class="hidden lg:block" aria-hidden="true"></div>
  <aside id="sidebar" class="on-navy fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r border-white/5 bg-navy text-[#C3CCE0] lg:flex" aria-label="Main">
    <div class="flex h-16 shrink-0 items-center justify-between px-5">
      <a href="index.php" aria-label="Telecomlijn Admin, overview"><img src="assets/img/telecomlijn-logo-white.png" alt="Telecomlijn" width="520" height="151" class="h-9 w-auto"></a>
      <button id="sbPin" type="button" class="grid h-9 w-9 place-items-center rounded-lg text-[#C3CCE0] hover:bg-white/10 hover:text-white max-lg:hidden" aria-pressed="false" aria-label="Pin sidebar" title="Pin sidebar open">
        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg>
      </button>
    </div>
    <nav class="relative mt-2 min-h-0 flex-1 space-y-0.5 overflow-y-auto px-3 pb-2">
      <span id="navInd" class="nav-ind" aria-hidden="true"></span>
      <?php foreach ($navGroups as $heading => $keys): $keys = array_values(array_filter($keys, fn($k) => isset($nav[$k]))); if (!$keys) { continue; } ?>
        <?php if ($heading !== ''): ?><p class="px-3 pb-1 pt-3 text-xs font-semibold text-[#9AA6C2]"><?= e($heading) ?></p><?php endif; ?>
        <?php foreach ($keys as $key): [$href, $label, $ic] = $nav[$key]; $on = $key === $active; ?>
        <a href="<?= e($href) ?>" <?= $on ? 'aria-current="page"' : '' ?>
           class="nav-item relative z-10 flex h-9 items-center gap-3 rounded-lg px-3 text-sm font-semibold transition-colors <?= $on ? 'text-white' : 'hover:text-white' ?>">
          <span class="<?= $on ? 'text-lime' : '' ?>"><?= icon($ic) ?></span><?= e($label) ?>
        </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="my-2 border-t border-white/10"></div>
      <a href="settings/settings.php" <?= $active === 'settings' ? 'aria-current="page"' : '' ?>
         class="nav-item relative z-10 flex h-9 items-center gap-3 rounded-lg px-3 text-sm font-semibold transition-colors <?= $active === 'settings' ? 'text-white' : 'hover:text-white' ?>">
        <span class="<?= $active === 'settings' ? 'text-lime' : '' ?>"><?= icon('settings') ?></span>Settings
      </a>
    </nav>
    <div class="m-3 flex shrink-0 items-center gap-3 rounded-lg bg-white/5 p-3">
      <?= avatar_html($admin['avatar'] ?? null, $admin['name']) ?>
      <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-semibold text-white"><?= e($admin['name']) ?></p>
        <p class="truncate text-xs text-[#9AA6C2]"><?= e($admin['email']) ?></p>
      </div>
      <form method="post" action="auth/logout.php"><?= csrf_field() ?>
        <button class="grid h-9 w-9 place-items-center rounded-lg text-[#C3CCE0] hover:bg-white/10 hover:text-white" aria-label="Sign out" title="Sign out"><?= icon('logout') ?></button>
      </form>
    </div>
  </aside>

  <div id="shell" class="flex min-w-0 flex-1 flex-col">
    <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-line bg-ground/95 px-4 backdrop-blur sm:px-8">
      <button id="menuLg" type="button" class="h-10 items-center gap-2 rounded-lg border border-line bg-panel px-3 text-sm font-semibold hover:bg-butter-50" aria-label="Open the menu" aria-controls="sidebar">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>Menu
      </button>
      <button id="menuBtn" class="grid h-10 w-10 place-items-center rounded-lg hover:bg-ink/10 lg:hidden" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false"><?= icon('menu', 'h-5 w-5') ?></button>
      <form action="search/search.php" method="get" role="search" class="relative min-w-0 max-w-md flex-1">
        <label for="gsearch" class="sr-only">Search everything</label>
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-soft"><?= icon('search', 'h-4 w-4') ?></span>
        <input id="gsearch" name="q" type="search" placeholder="<?= e('Search users, companies' . (module_enabled('invoices') ? ', invoices' : '') . (module_enabled('tickets') ? ', tickets' : '') . ' (press /)') ?>" aria-keyshortcuts="/" class="h-10 w-full rounded-lg border border-line bg-panel pl-9 pr-1 text-sm placeholder:text-soft/80 sm:pl-10 sm:pr-3 focus:border-lime focus:outline-none focus:ring-2 focus:ring-lime">
        <a id="teletolliePop" href="extras/teletollie.php" hidden class="creator-pop absolute left-0 top-12 z-50 inline-flex items-center gap-2 rounded-lg bg-butter px-4 py-2.5 text-sm font-bold text-navy shadow-[0_14px_30px_-10px_rgba(0,0,0,.8)] hover:brightness-95">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
          Slaat op in het woordenboek
        </a>
        <a id="creatorPop" href="extras/creator.php" hidden class="creator-pop absolute left-0 top-12 z-50 inline-flex items-center gap-2 rounded-lg bg-lime px-4 py-2.5 text-sm font-bold text-navy shadow-[0_14px_30px_-10px_rgba(0,0,0,.8)] hover:brightness-95">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 17l.7 1.8L21.5 19.5l-1.8.7L19 22l-.7-1.8-1.8-.7 1.8-.7z"/></svg>
          Meet the creator
        </a>
      </form>
      <details id="newMenu" data-menu class="relative">
        <summary class="inline-flex h-10 cursor-pointer list-none items-center gap-2 rounded-lg border border-line bg-panel px-3 text-sm font-semibold hover:bg-butter-50" aria-label="New: ticket, company and more" aria-keyshortcuts="n"><?= icon('plus', 'h-4 w-4') ?><span class="max-sm:hidden">New</span></summary>
        <ul class="absolute left-0 top-12 z-50 w-48 overflow-hidden rounded-lg border border-line bg-panel py-1 shadow-[0_14px_30px_-10px_rgba(0,0,0,.8)]">
          <?php foreach ($newItems as [$nHref, $nLabel]): ?><li><a href="<?= e($nHref) ?>" class="block px-4 py-2 text-sm hover:bg-butter-50"><?= e($nLabel) ?></a></li><?php endforeach; ?>
        </ul>
      </details>
      <?= lang_widget('ml-auto') ?>
      <?= $bell ?>
      <?= theme_toggle_button() ?>
    </header>

    <main id="main" class="mx-auto w-full max-w-[1600px] flex-1 px-4 py-8 sm:px-8">
      <?php if (empty($GLOBALS['hide_page_title'])): ?>
      <div class="rise mb-6">
        <h1 class="text-3xl font-bold leading-tight tracking-tight"><?= e($title) ?></h1>
        <?php if ($lede !== ''): ?><p class="mt-1 max-w-2xl text-base text-soft"><?= e($lede) ?></p><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($demoNote && demo_loaded()): ?>
      <div role="note" class="rise mb-6 flex items-start gap-3 rounded-lg border border-butter/30 bg-butter-100 px-4 py-3 text-sm" style="animation-delay:.03s">
        <span class="mt-0.5 text-butter"><?= icon('alert') ?></span>
        <p><strong class="font-semibold text-butter">Demo data is loaded.</strong> Some or all of what you see here is fake, generated for demonstration. Anything you entered yourself is kept apart from it and is never removed with it.</p>
      </div>
      <?php endif; ?>
      <?php if ($flag): ?>
        <div role="status"<?= $undo ? ' data-ms="10000"' : '' ?> class="toast fixed right-4 top-20 z-50 flex max-w-sm cursor-pointer items-center gap-3 rounded-lg px-4 py-3 shadow-[0_14px_30px_-10px_rgba(0,0,0,.7)] text-sm font-semibold <?= $flag['k'] === 'ok' ? 'bg-lime text-navy' : 'bg-[#FFD3CC] text-[#7E160A]' ?>">
          <?= icon($flag['k'] === 'ok' ? 'activity' : 'alert') ?><?= e($flag['m']) ?>
          <?php if ($undo): ?><form method="post" action="system/undo.php" class="ml-1"><?= csrf_field() ?><button class="rounded-lg bg-navy/10 px-3 py-1.5 text-sm font-bold hover:bg-navy/20">Undo</button></form><?php endif; ?>
        </div>
      <?php endif; ?>
<?php
}

function page_end(): void
{
    ?>
    </main>
    <footer class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-line px-4 py-5 text-xs text-soft sm:px-8">
      <p>© <?= date('Y') ?> Telecomlijn Zakelijk · KvK 65586492<?= demo_loaded() ? ' · Demo data seeded for display' : '' ?></p>
      <nav class="flex gap-5 font-semibold" aria-label="Legal"><a href="privacy/privacybeleid.php" class="notranslate text-ink underline decoration-lime decoration-2 underline-offset-4 hover:decoration-white">Privacybeleid</a><?php if (is_admin()): ?><a href="privacy/privacy.php" class="hover:text-ink">Privacy requests</a><?php endif; ?><button type="button" id="kbdOpen" class="font-semibold hover:text-ink max-lg:hidden">Keyboard shortcuts</button><button type="button" id="trWithdraw" hidden class="font-semibold hover:text-ink">Withdraw translation consent</button><a href="mailto:office@telecomlijn.nl" class="hover:text-ink">office@telecomlijn.nl</a></nav>
    </footer>
  </div>
</div>
<script nonce="<?= csp_nonce() ?>">
  const btn = document.getElementById('menuBtn'), sb = document.getElementById('sidebar');
  document.addEventListener('click', e => { const d = document.getElementById('bell'); if (d && d.open && !d.contains(e.target)) d.open = false; });
  document.addEventListener('keydown', e => { if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !/^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '') && !(document.activeElement || {}).isContentEditable) { const q = document.getElementById('gsearch'); if (q) { e.preventDefault(); q.focus(); q.select(); } } });
  document.addEventListener('keydown', e => { const d = document.getElementById('bell'); if (e.key === 'Escape' && d && d.open) { d.open = false; d.querySelector('summary').focus(); } });
  btn?.addEventListener('click', () => {
    const open = sb.classList.toggle('hidden') === false;
    sb.classList.toggle('flex', open);
    btn.setAttribute('aria-expanded', String(open));
  });
</script>
<dialog id="kbdHelp" aria-labelledby="kbdHelpTitle" class="w-[26rem] max-w-[calc(100vw-2rem)] rounded-xl border border-line bg-panel p-6 text-ink shadow-[0_18px_40px_-12px_rgba(0,0,0,.8)] backdrop:bg-black/50">
  <div class="flex items-center justify-between gap-4"><h2 id="kbdHelpTitle" class="text-lg font-bold tracking-tight">Keyboard shortcuts</h2>
    <form method="dialog"><button class="grid h-9 w-9 place-items-center rounded-lg hover:bg-ink/10" aria-label="Close"><?= icon('x', 'h-4 w-4') ?></button></form></div>
  <dl class="mt-4 grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-2 text-sm">
    <?php $kbd = fn(string ...$k) => implode(' <span class="text-soft">then</span> ', array_map(fn($x) => '<kbd class="inline-grid h-6 min-w-6 place-items-center rounded border border-line bg-ground px-1.5 font-sans text-xs font-semibold">' . e($x) . '</kbd>', $k));
    foreach ([[['/'], 'Search'], [['n'], 'New ticket, company, invoice…'], [['g', 'o'], 'Overview'], [['g', 't'], 'Tickets'], [['g', 'c'], 'Companies'], [['g', 'u'], 'Users'], [['g', 'i'], 'Invoices'], [['g', 'a'], 'Calendar'], [['g', 'l'], 'Activity logs'], [['g', 's'], 'Settings'], [['['], 'Pin or hide the menu'], [['?'], 'This list']] as [$keys, $what]): ?>
      <dt class="whitespace-nowrap"><?= $kbd(...$keys) ?></dt><dd class="text-soft"><?= e($what) ?></dd>
    <?php endforeach; ?>
  </dl>
</dialog>
<?= theme_toggle_script() ?>
<script src="<?= asset('assets/js/motion.js') ?>" defer></script>
<script src="<?= asset('assets/js/cards.js') ?>" defer></script>
<script src="<?= asset('assets/js/ux.js') ?>" defer></script>
</body>
</html>
<?php
}
