<?php
declare(strict_types=1);
require __DIR__ . '/../includes/core/bootstrap.php';
require __DIR__ . '/../includes/core/layout.php';
require __DIR__ . '/../includes/modules/kuma.php';   // KUMA_CERT_WARN_DAYS
require_login();

page_start('Uptime', 'uptime', 'Health of the monitors in Uptime Kuma. The browser only talks to this dashboard, never to Kuma.', false);
$admin = is_admin();
?>
<style nonce="<?= csp_nonce() ?>">
  /* Kuma's layout, in this dashboard's own colours: navy panels, lime for up, coral for down, butter for pending */
  .kuma { --k-card: #111D36; --k-line: #233559; --k-hover: rgba(255, 241, 168, .06); --k-active: #1B2C50; --k-text: #A5B2CF; --k-head: #EEF2FA;
          --k-up: #C7F36B; --k-down: #F0654F; --k-pend: #FFF1A8; --k-maint: #7FA6FF; --k-unk: #6B7A99; --k-ink: #111D36; color: var(--k-text); }
  html[data-theme=light] .kuma { --k-card: #FFFFFF; --k-line: #D6DEEC; --k-hover: rgba(17, 29, 54, .045); --k-active: #E8EDF7; --k-text: #4D5B7A; --k-head: #111D36; --k-uptext: #3F6212; --k-downtext: #B42318; --k-unk: #5F6B88; }
  .kuma { --k-uptext: var(--k-up); --k-downtext: var(--k-down); --k-pendtext: var(--k-pend); --k-mainttext: #9CC4FF; }
  html[data-theme=light] .kuma { --k-uptext: #3F6212; --k-downtext: #B42318; --k-pendtext: #7A4F00; --k-mainttext: #1E4FA8; }
  html[data-theme=light] .kuma .k-card { box-shadow: 0 1px 2px rgba(17,29,54,.07), 0 10px 24px -14px rgba(17,29,54,.24); }
  html[data-theme=light] .kuma .pill.p-unknown { background: rgba(17,29,54,.1); }
  html[data-theme=light] .kuma input[type=search]::placeholder { color: #5F6B88; }
  .kuma h2, .kuma h3, .kuma .k-head { color: var(--k-head); }
  .kuma .k-card { background: var(--k-card); border: 1px solid var(--k-line); border-radius: 1rem; box-shadow: 0 1px 2px rgba(0,0,0,.35), 0 10px 24px -12px rgba(0,0,0,.55); }
  .kuma .k-bar { display: flex; align-items: center; gap: 3px; height: 30px; }
  .kuma .k-bar i { flex: 1 1 0; min-width: 3px; max-width: 9px; height: 100%; border-radius: 99px; background: var(--k-line); transition: transform .3s var(--ease-spring, ease), opacity .2s; }
  .kuma .k-bar i:hover { transform: scaleY(1.2); opacity: .85; }
  .kuma .k-bar.mini { height: 18px; gap: 2px; }
  .kuma .k-bar.mini i { min-width: 2px; max-width: 4px; }
  .kuma .s-up { background: var(--k-up) !important; } .kuma .s-down { background: var(--k-down) !important; }
  .kuma .s-pending { background: var(--k-pend) !important; } .kuma .s-maintenance { background: var(--k-maint) !important; }
  .kuma .pill { display: inline-flex; align-items: center; justify-content: center; min-width: 3.4rem; border-radius: 99px; padding: .15rem .65rem; font-size: .75rem; font-weight: 700; color: var(--k-ink); }
  .kuma .pill.p-up { background: var(--k-up); } .kuma .pill.p-down { background: var(--k-down); }
  .kuma .pill.p-pending { background: var(--k-pend); } .kuma .pill.p-maintenance { background: #BBD6FF; color: #0E2A52; } .kuma .pill.p-unknown { background: rgba(255,255,255,.12); color: var(--k-head); }
  .kuma .badge-lg { font-size: 1rem; padding: .4rem 1.2rem; min-width: 5.5rem; }
  .kuma .k-tab { height: 2.1rem; border-radius: 99px; padding: 0 .75rem; font-size: .8rem; font-weight: 600; color: var(--k-head); border: 1px solid var(--k-line); background: transparent; transition: background-color .2s, transform .35s var(--ease-spring, ease); }
  .kuma .k-tab:hover { background: var(--k-hover); }
  .kuma .k-tab:active { transform: scale(.96); }
  .kuma .k-tab[aria-pressed="true"] { background: var(--k-up); border-color: var(--k-up); color: var(--k-ink); }
  .kuma .k-tab .n { margin-left: .35rem; opacity: .7; font-variant-numeric: tabular-nums; }
  .kuma .k-item { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; border-bottom: 1px solid var(--k-line); text-decoration: none; color: inherit; transition: background-color .2s; }
  .kuma .k-item:hover { background: var(--k-hover); }
  .kuma .k-item[aria-current="true"] { background: var(--k-active); }
  .kuma .k-stat b { display: block; font-size: 1.5rem; line-height: 1.2; font-weight: 700; color: var(--k-head); font-variant-numeric: tabular-nums; }
  .kuma .k-stat span { font-size: .78rem; }
  .kuma input[type=search] { background: var(--k-card); border: 1px solid var(--k-line); color: var(--k-head); }
  .kuma input[type=search]:focus { border-color: var(--k-up); }
  .kuma input[type=search]::placeholder { color: #8493B4; }
  .kuma thead { background: var(--k-hover); }
  .kuma th { color: var(--k-text); }
  .kuma tbody tr { border-top: 1px solid var(--k-line); transition: background-color .2s; }
  .kuma tbody tr:hover { background: var(--k-hover); }
  .kuma a.k-link { color: var(--k-uptext); text-decoration: underline; text-underline-offset: 3px; }
  @media (prefers-reduced-motion: reduce) { .kuma .k-bar i, .kuma .k-tab { transition: none; } }
</style>

<div id="uptime" data-admin="<?= $admin ? '1' : '0' ?>" data-warn="<?= KUMA_CERT_WARN_DAYS ?>">
  <div id="banner" role="status" aria-live="polite" class="mb-4" hidden></div>

  <section id="state" class="rise rounded-xl border border-line bg-panel p-8 text-center shadow-panel sm:p-12" aria-live="polite">
    <p class="text-base font-semibold">Loading monitors…</p>
  </section>

  <div id="app" class="kuma rise" hidden>
    <div class="grid gap-6 lg:grid-cols-[minmax(300px,380px)_1fr]">
      <!-- Left: monitor list with Kuma-style filter tabs -->
      <section class="k-card flex min-h-[28rem] flex-col overflow-hidden lg:max-h-[78vh]" aria-labelledby="list-h">
        <div class="border-b border-line p-4">
          <h2 id="list-h" class="mb-3 flex items-center justify-between text-base font-bold">Monitors <a href="#" id="homeLink" class="k-link text-xs font-semibold">Dashboard</a></h2>
          <label for="search" class="sr-only">Search monitors</label>
          <input id="search" type="search" placeholder="Search…" autocomplete="off" class="h-11 w-full rounded-lg px-3 text-sm focus:outline-none focus:ring-2 focus:ring-lime">
          <div id="filters" class="mt-3 flex flex-wrap gap-1.5" role="group" aria-label="Filter by status"></div>
        </div>
        <div id="items" class="flex-1 overflow-y-auto"></div>
      </section>

      <!-- Right: dashboard overview, or one monitor in detail -->
      <section id="pane" class="min-w-0" aria-live="polite"></section>
    </div>
    <p class="mt-4 text-xs text-soft">Last updated <span id="updated" class="tabular-nums text-ink">…</span><span id="refreshNote"></span> · Uptime figures come from the checks this dashboard recorded (one per minute, kept 30 days). Run <span class="notranslate">bin/kuma_poll.php</span> on a schedule for a gap-free history.</p>
  </div>
</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
  var root = document.getElementById('uptime'), isAdmin = root.dataset.admin === '1', WARN = parseInt(root.dataset.warn, 10);
  var $ = function (id) { return document.getElementById(id); };
  var S = { monitors: [], filter: 'all', q: '', sel: null, interval: 30, detail: null, events: [], lastOk: 0, busy: false, timer: null };
  var ERR = {
    kuma_unreachable: 'Kuma could not be reached. It may be offline or the address is wrong.',
    kuma_unauthorized: 'Kuma rejected the API key. Check the key in Settings.',
    kuma_invalid_response: 'The Kuma address answered, but not with metrics. Check the URL in Settings.'
  };
  var LABEL = { up: 'Up', down: 'Down', pending: 'Pending', maintenance: 'Maintenance', unknown: 'Unknown' };
  var FILTERS = ['all', 'up', 'down', 'pending', 'maintenance'];

  function h(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function add(parent) { for (var i = 1; i < arguments.length; i++) if (arguments[i]) parent.appendChild(arguments[i]); return parent; }
  function fmtTime(iso) { var d = new Date(iso); return isNaN(d) ? '…' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
  function fmtDate(iso) { var d = new Date(iso); return isNaN(d) ? '…' : d.toLocaleString([], { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
  function pct(v) { return v == null ? '—' : (Math.round(v * 100) / 100) + '%'; }
  function ms(v) { return v == null ? '—' : v + ' ms'; }
  function status(m) { return LABEL[m.statusLabel] ? m.statusLabel : 'unknown'; }

  function banner(kind, msg, link) {
    var b = $('banner'); b.hidden = !msg; b.textContent = '';
    if (!msg) return;
    b.className = 'mb-4 flex flex-wrap items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold ' + (kind === 'error' ? 'bg-[#FFD3CC] text-[#7E160A]' : 'bg-butter text-navy');
    add(b, h('span', null, msg));
    if (link && isAdmin) { var a = h('a', 'underline underline-offset-4', 'Open Kuma settings'); a.href = 'settings/settings.php?tab=kuma'; b.appendChild(a); }
  }
  function showState(title, text, withButton) {
    var s = $('state'); s.hidden = false; s.textContent = ''; $('app').hidden = true;
    add(s, h('p', 'text-base font-semibold', title));
    if (text) add(s, h('p', 'mx-auto mt-2 max-w-md text-sm text-soft', text));
    if (withButton) {
      if (isAdmin) { var a = h('a', 'pressable mt-6 inline-flex h-11 items-center rounded-lg bg-lime px-5 text-sm font-bold text-navy hover:brightness-95', 'Set up Kuma API Key'); a.href = 'settings/settings.php?tab=kuma'; s.appendChild(a); }
      else add(s, h('p', 'mt-4 text-sm text-soft', 'Ask an admin to connect Kuma in Settings.'));
    }
  }

  // ---------- pieces ----------
  function pill(m, big) {
    var st = status(m), text = st === 'up' && m.uptime24h != null ? pct(m.uptime24h) : LABEL[st];
    return h('span', 'pill p-' + st + (big ? ' badge-lg' : ''), big ? LABEL[st] : text);
  }
  function bar(beats, n, mini) {
    var w = h('div', 'k-bar' + (mini ? ' mini' : '')); w.setAttribute('role', 'img');
    var shown = (beats || []).slice(-n), pad = Math.max(0, n - shown.length);
    w.setAttribute('aria-label', shown.length ? 'Last ' + shown.length + ' checks' : 'No checks recorded yet');
    for (var i = 0; i < pad; i++) w.appendChild(h('i'));
    shown.forEach(function (b) {
      var i = h('i', 's-' + ({ 1: 'up', 0: 'down', 2: 'pending', 3: 'maintenance' }[b[0]] || 'pending'));
      i.title = fmtDate(b[2]) + (b[1] != null ? ' · ' + b[1] + ' ms' : '');
      w.appendChild(i);
    });
    return w;
  }
  function statCell(label, value, tone) {
    var c = h('div', 'k-stat'); var b = h('b', 'notranslate', value); if (tone) b.style.color = tone;
    return add(c, b, h('span', null, label));
  }

  // ---------- list ----------
  function counts() { var c = { all: S.monitors.length, up: 0, down: 0, pending: 0, maintenance: 0 }; S.monitors.forEach(function (m) { if (c[m.statusLabel] != null) c[m.statusLabel]++; }); return c; }
  function renderFilters() {
    var f = $('filters'), c = counts(); f.textContent = '';
    FILTERS.forEach(function (k) {
      var b = h('button', 'k-tab'); b.type = 'button'; b.setAttribute('aria-pressed', String(S.filter === k));
      add(b, document.createTextNode(k === 'all' ? 'All' : LABEL[k]), h('span', 'n', String(c[k])));
      b.addEventListener('click', function () { S.filter = k; renderFilters(); renderList(); });
      f.appendChild(b);
    });
  }
  function renderList() {
    var box = $('items'); box.textContent = '';
    var q = S.q.trim().toLowerCase();
    var list = S.monitors.filter(function (m) { return (S.filter === 'all' || m.statusLabel === S.filter) && (!q || (m.name + ' ' + m.url + ' ' + m.type).toLowerCase().indexOf(q) !== -1); });
    if (!list.length) { add(box, h('p', 'p-6 text-center text-sm', S.monitors.length ? 'No monitors match.' : 'Kuma has no monitors yet.')); return; }
    list.forEach(function (m) {
      var a = h('a', 'k-item'); a.href = '#m=' + m.id; if (S.sel === m.id) a.setAttribute('aria-current', 'true');
      var mid = h('div', 'min-w-0 flex-1'); add(mid, h('p', 'k-head truncate text-sm font-semibold', m.name || '(unnamed)'));
      if (m.type) add(mid, h('p', 'truncate text-xs notranslate', m.type + (m.url ? ' · ' + m.url : '')));
      add(a, pill(m), mid, bar(m.heartbeats, 14, true));
      box.appendChild(a);
    });
  }

  // ---------- right pane: dashboard ----------
  function eventsTable(rows, withName) {
    var wrap = h('div', 'overflow-x-auto'), t = h('table', 'w-full text-left text-sm'), head = h('tr');
    (withName ? ['Name', 'Status', 'Date and time', 'Message'] : ['Status', 'Date and time', 'Message']).forEach(function (x) { head.appendChild(h('th', 'px-4 py-3 text-xs font-semibold', x)); });
    add(t, add(h('thead'), head));
    var tb = h('tbody');
    if (!rows.length) { var tr = h('tr'), td = h('td', 'px-4 py-8 text-center', 'No status changes recorded yet. They appear as this dashboard sees them.'); td.colSpan = withName ? 4 : 3; add(tb, add(tr, td)); }
    rows.forEach(function (e) {
      var tr = h('tr');
      if (withName) { var td0 = h('td', 'k-head px-4 py-2.5 font-semibold'); var a = h('a', 'hover:underline', e.name); a.href = '#m=' + e.id; add(tr, add(td0, a)); }
      var st = LABEL[e.statusLabel] ? e.statusLabel : 'unknown';
      add(tr, add(h('td', 'px-4 py-2.5'), h('span', 'pill p-' + st, LABEL[st])), h('td', 'px-4 py-2.5 tabular-nums notranslate', fmtDate(e.at)), h('td', 'px-4 py-2.5', e.message));
      tb.appendChild(tr);
    });
    return add(wrap, add(t, tb));
  }
  function renderDashboard(pane) {
    var c = counts(), unknown = Math.max(0, c.all - c.up - c.down - c.pending - c.maintenance);
    var quick = h('div', 'k-card p-5 sm:p-6'); add(quick, h('h2', 'mb-4 text-lg font-bold', 'Quick Stats'));
    var g = h('div', 'grid grid-cols-2 gap-5 sm:grid-cols-5');
    add(g, statCell('Up', String(c.up), 'var(--k-uptext)'), statCell('Down', String(c.down), 'var(--k-downtext)'), statCell('Maintenance', String(c.maintenance), 'var(--k-mainttext)'), statCell('Pending', String(c.pending), 'var(--k-pendtext)'), statCell('Unknown', String(unknown), 'var(--k-text)'));
    add(quick, g);
    var ev = h('div', 'k-card mt-6 overflow-hidden'); add(ev, add(h('div', 'p-5', null), h('h3', 'text-lg font-bold', 'Recent status changes')), eventsTable(S.events, true));
    add(pane, quick, ev);
  }

  // ---------- right pane: one monitor ----------
  function certCell(m) {
    var d = m.certDaysRemaining;
    if (d == null) return statCell('Cert Exp.', '—');
    var tone = d < 0 || m.certValid === false ? 'var(--k-downtext)' : (d < WARN ? 'var(--k-pendtext)' : null);
    return statCell('Cert Exp.' + (d < 0 ? ' (expired)' : d < WARN ? ' (soon)' : ''), d < 0 ? 'Expired' : d + ' days', tone);
  }
  function chart(series) {
    var NS = 'http://www.w3.org/2000/svg', W = 640, H = 170, P = { l: 44, r: 8, t: 8, b: 22 };
    var pts = series.filter(function (p) { return p[1] != null; });
    var card = h('div', 'k-card mt-6 p-5 sm:p-6'); add(card, h('h3', 'mb-3 text-lg font-bold', 'Response time, last 24 hours'));
    if (pts.length < 2) { add(card, h('p', 'py-8 text-center text-sm', 'Not enough data yet. The chart fills in as checks are recorded.')); return card; }
    var max = Math.max.apply(null, pts.map(function (p) { return p[1]; })) * 1.15 || 1, t0 = new Date(series[0][0]).getTime(), t1 = new Date(series[series.length - 1][0]).getTime() || t0 + 1;
    var X = function (iso) { return P.l + (new Date(iso).getTime() - t0) / Math.max(1, t1 - t0) * (W - P.l - P.r); };
    var Y = function (v) { return P.t + (1 - v / max) * (H - P.t - P.b); };
    var svg = document.createElementNS(NS, 'svg'); svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.setAttribute('class', 'w-full'); svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Response time chart, peak ' + Math.round(max / 1.15) + ' milliseconds');
    function el(n, a) { var e = document.createElementNS(NS, n); for (var k in a) e.setAttribute(k, a[k]); return e; }
    [0, .5, 1].forEach(function (f) {
      var y = P.t + (1 - f) * (H - P.t - P.b);
      svg.appendChild(el('line', { x1: P.l, x2: W - P.r, y1: y, y2: y, style: 'stroke:var(--k-line)', 'stroke-width': 1 }));
      var tx = el('text', { x: P.l - 6, y: y + 4, 'text-anchor': 'end', style: 'fill:var(--k-text)', 'font-size': 11 }); tx.textContent = Math.round(max * f) + ' ms'; svg.appendChild(tx);
    });
    series.forEach(function (p) { if (p[2] === 0) svg.appendChild(el('rect', { x: X(p[0]) - 2, y: P.t, width: 4, height: H - P.t - P.b, fill: '#F0654F', opacity: .45 })); });
    var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + X(p[0]).toFixed(1) + ' ' + Y(p[1]).toFixed(1); }).join(' ');
    svg.appendChild(el('path', { d: d + ' L' + X(pts[pts.length - 1][0]).toFixed(1) + ' ' + (H - P.b) + ' L' + X(pts[0][0]).toFixed(1) + ' ' + (H - P.b) + ' Z', fill: '#C7F36B', opacity: .16 }));
    svg.appendChild(el('path', { d: d, fill: 'none', style: 'stroke:var(--k-uptext)', 'stroke-width': 2, 'stroke-linejoin': 'round' }));
    [[series[0][0], 'start'], [series[Math.floor(series.length / 2)][0], 'middle'], [series[series.length - 1][0], 'end']].forEach(function (a) {
      var tx = el('text', { x: a[1] === 'start' ? P.l : a[1] === 'end' ? W - P.r : (P.l + W - P.r) / 2, y: H - 5, 'text-anchor': a[1], style: 'fill:var(--k-text)', 'font-size': 11 }); tx.textContent = fmtTime(a[0]).slice(0, 5); svg.appendChild(tx);
    });
    return add(card, svg);
  }
  function renderMonitor(pane, m) {
    var back = h('a', 'k-link mb-3 inline-block text-sm font-semibold', '← Dashboard'); back.href = '#'; pane.appendChild(back);
    var head = h('div', 'k-card p-5 sm:p-6');
    var top = h('div', 'flex flex-wrap items-start justify-between gap-3');
    var left = h('div', 'min-w-0'); add(left, h('h2', 'break-words text-2xl font-bold', m.name || '(unnamed)'));
    if (m.url && /^https?:\/\//i.test(m.url)) { var a = h('a', 'k-link break-all text-sm notranslate', m.url); a.href = m.url; a.target = '_blank'; a.rel = 'noopener noreferrer'; add(left, a); }
    else if (m.url) add(left, h('p', 'break-all text-sm notranslate', m.url));
    if (m.type) add(left, h('p', 'mt-1 text-xs uppercase notranslate', m.type));
    add(top, left, pill(m, true)); add(head, top);
    var hb = h('div', 'mt-5'); add(hb, bar((S.detail && S.detail.id === m.id) ? S.detail.heartbeats : m.heartbeats, 60, false));
    add(hb, h('p', 'mt-2 text-xs', (S.detail && S.detail.heartbeats && S.detail.heartbeats.length ? 'Last ' + S.detail.heartbeats.length + ' recorded checks, oldest on the left' : 'No checks recorded yet')));
    add(head, hb); pane.appendChild(head);

    var stats = h('div', 'k-card mt-6 grid grid-cols-2 gap-5 p-5 sm:grid-cols-3 sm:p-6 lg:grid-cols-6');
    add(stats, statCell('Response (current)', ms(m.responseTimeMs)), statCell('Avg. response (24-hour)', ms(m.avgResponse24hMs)), statCell('Uptime (24-hour)', pct(m.uptime24h)),
        statCell('Uptime (30-day)', pct(m.uptime30d)), statCell('Uptime (1-year)', '—'), certCell(m));
    pane.appendChild(stats);
    pane.appendChild(chart((S.detail && S.detail.id === m.id) ? S.detail.series : []));
    var ev = h('div', 'k-card mt-6 overflow-hidden'); add(ev, add(h('div', 'p-5', null), h('h3', 'text-lg font-bold', 'Important heartbeats')), eventsTable((S.detail && S.detail.id === m.id) ? S.detail.events : [], false));
    pane.appendChild(ev);
  }
  function renderPane() {
    var pane = $('pane'); pane.textContent = '';
    var m = S.sel && S.monitors.filter(function (x) { return x.id === S.sel; })[0];
    if (S.sel && !m) { S.sel = null; }
    if (m) renderMonitor(pane, m); else renderDashboard(pane);
  }
  function renderAll() { renderFilters(); renderList(); renderPane(); }

  // ---------- data ----------
  function readHash() { var x = /^#m=([a-f0-9]{16})$/.exec(location.hash); return x ? x[1] : null; }
  function select() {
    var id = readHash(); if (id !== S.sel) { S.sel = id; S.detail = null; }
    renderList(); renderPane(); if (S.sel) loadDetail(); else loadEvents();
    if (S.sel) { var p = $('pane'); if (window.matchMedia('(max-width: 1023px)').matches) p.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }
  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) {
      if (r.status === 401) { location.href = 'auth/login.php'; throw new Error('signed out'); }
      return r.json();
    });
  }
  function loadDetail() { var id = S.sel; if (!id) return; getJSON('monitoring/monitoring_uptime.php?monitor=' + id).then(function (d) { if (S.sel === id && !d.error) { S.detail = d; renderPane(); } }).catch(function () {}); }
  function loadEvents() { getJSON('monitoring/monitoring_uptime.php?events=1').then(function (d) { if (!S.sel && d.events) { S.events = d.events; renderPane(); } }).catch(function () {}); }

  function apply(d) {
    if (d.pollIntervalSeconds) S.interval = d.pollIntervalSeconds;
    if (d.error === 'not_configured') { banner(); showState('Uptime Kuma is not connected yet', 'Add the address and API key of your Kuma server to see the health of its monitors here.', true); return; }
    if (d.error && !d.monitors) { banner(); showState('No data from Kuma', ERR[d.error] || 'Kuma returned an error.', false); if (isAdmin && d.error !== 'kuma_unreachable') banner('error', ERR[d.error], true); return; }
    S.monitors = d.monitors || [];
    $('state').hidden = true; $('app').hidden = false; $('updated').textContent = fmtTime(d.updatedAt);
    if (d.error) banner('warn', 'Showing the last known data from ' + fmtTime(d.updatedAt) + '. ' + (ERR[d.error] || 'Kuma returned an error.'), d.error !== 'kuma_unreachable');
    else { banner(); S.lastOk = Date.now(); $('refreshNote').textContent = ' · refreshes every ' + S.interval + ' s'; }
    var first = !S.started; S.started = true; if (first) S.sel = readHash();
    renderFilters(); renderList(); renderPane();
    if (S.sel) loadDetail(); else loadEvents();
  }
  function schedule() { clearTimeout(S.timer); if (!document.hidden) S.timer = setTimeout(load, S.interval * 1000); }
  function load() {
    if (S.busy) return; S.busy = true;
    getJSON('monitoring/monitoring_uptime.php').then(apply)
      .catch(function (e) { if (e.message !== 'signed out') { banner('error', 'The dashboard could not be reached. Retrying…'); if (!S.started) showState('Could not load the monitors', 'Check your connection. This page retries automatically.', false); } })
      .finally(function () { S.busy = false; schedule(); });
  }
  $('search').addEventListener('input', function (e) { S.q = e.target.value; renderList(); });
  $('homeLink').addEventListener('click', function (e) { e.preventDefault(); history.pushState(null, '', location.pathname); select(); });
  window.addEventListener('hashchange', select);
  document.addEventListener('visibilitychange', function () {   // pause while hidden, refresh as soon as the tab is back
    if (document.hidden) clearTimeout(S.timer); else if (Date.now() - S.lastOk > S.interval * 1000) load(); else schedule();
  });
  load();
})();
</script>
<?php page_end(); ?>
