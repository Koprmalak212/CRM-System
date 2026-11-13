(() => {
  'use strict';
  window.__motion = 1;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  /* 2. Numbers marked data-count are formatted with thousands separators (no count-up) */
  $$('[data-count], [data-money]').forEach(el => {
    el.classList.add('notranslate');
    if (el.hasAttribute('data-money')) return;
    const to = parseFloat(el.dataset.count), dec = parseInt(el.dataset.decimals || '0', 10);
    if (!isNaN(to)) el.textContent = Math.max(0, to).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + (el.dataset.suffix || '');
  });

  /* 3. Sidebar marker: sits behind the current page */
  const ind = $('#navInd'), cur = $('#sidebar .nav-item[aria-current="page"]');
  if (ind && cur) { ind.style.translate = '0 ' + cur.offsetTop + 'px'; ind.classList.add('on'); }

  /* 3b. Header gains depth once the page scrolls */
  const hdr = $('header.sticky');
  if (hdr) { const f = () => hdr.classList.toggle('scrolled', scrollY > 6); f(); addEventListener('scroll', f, { passive: true }); }

  /* 4. Chart tooltip: follows the cursor */
  $$('.bar[title], .col[title]').forEach(el => { el.dataset.tip = el.getAttribute('title'); el.removeAttribute('title'); });
  const tip = document.createElement('div');
  tip.className = 'tip notranslate';
  tip.setAttribute('aria-hidden', 'true');
  tip.innerHTML = '<span></span>';
  document.body.appendChild(tip);
  let tx = 0, ty = 0, cx = 0, cy = 0, raf = 0, shown = false;
  const loop = () => {
    cx = tx;
    cy = ty;
    tip.style.transform = 'translate(' + cx.toFixed(1) + 'px,' + cy.toFixed(1) + 'px)';
    raf = (shown || Math.abs(tx - cx) > 0.4 || Math.abs(ty - cy) > 0.4) ? requestAnimationFrame(loop) : 0;
  };
  document.addEventListener('mouseover', e => {
    const t = e.target.closest && e.target.closest('[data-tip]');
    if (!t) return;
    tip.firstChild.textContent = t.dataset.tip;
    if (!shown) { cx = tx = e.clientX; cy = ty = e.clientY; }
    shown = true; tip.classList.add('on');
    if (!raf) raf = requestAnimationFrame(loop);
  });
  document.addEventListener('mousemove', e => { if (shown) { tx = e.clientX; ty = e.clientY; } });
  document.addEventListener('mouseout', e => {
    const t = e.target.closest && e.target.closest('[data-tip]');
    if (t && !(e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('[data-tip]'))) { shown = false; tip.classList.remove('on'); }
  });

  /* 5. A pressed submit button shows a spinner while the server works */
  document.addEventListener('submit', e => { if (e.target instanceof HTMLFormElement && e.submitter) setTimeout(() => e.submitter.classList.add('is-busy'), 0); });

  /* 6. Forms marked data-dirty show their Save button once a field changed */
  $$('form[data-dirty]').forEach(f => f.addEventListener('change', () => f.classList.add('dirty')));

  /* 6b. File pickers: our own button, with the chosen file name beside it (the browser's own control shows its text in the browser's language) */
  $$('input[type=file][data-file-name]').forEach(i => i.addEventListener('change', () => {
    const out = document.getElementById(i.dataset.fileName);
    if (out) out.textContent = i.files && i.files.length ? i.files[0].name : 'No file chosen';
  }));

  /* 6c. Filter forms apply themselves: at once for a dropdown or a date, a moment after typing stops in a search box.
         The page reloads, so the search box gets its focus and caret back afterwards. */
  $$('form[data-auto]').forEach(f => {
    let t = 0;
    const go = () => { if (f.requestSubmit) f.requestSubmit(); else f.submit(); };
    f.addEventListener('change', e => { if (e.target.matches('select, input[type=date]')) go(); });
    f.addEventListener('input', e => {
      if (!e.target.matches('input[type=search]')) return;
      clearTimeout(t);
      t = setTimeout(() => { try { sessionStorage.setItem('autoFocus', location.pathname + '|' + e.target.id); } catch (x) { /* storage may be blocked */ } go(); }, 600);
    });
  });
  try {
    const k = sessionStorage.getItem('autoFocus'); sessionStorage.removeItem('autoFocus');
    const [p, id] = (k || '|').split('|'), el = p === location.pathname && id ? document.getElementById(id) : null;
    if (el) { el.focus(); try { el.setSelectionRange(el.value.length, el.value.length); } catch (x) { /* not a text field */ } }
  } catch (x) { /* storage may be blocked */ }

  /* 6d. A table row opens its record: a click anywhere on the row follows the link in its first cell (Ctrl or Cmd click: new tab) */
  $$('tbody tr').forEach(tr => {
    const cell = tr.querySelector('td, th'), a = cell && !cell.hasAttribute('colspan') ? cell.querySelector('a[href]') : null;
    if (!a) return;
    tr.classList.add('row-link');
    tr.addEventListener('click', e => {
      if (e.target.closest('a, button, input, select, textarea, label, form, summary') || String(getSelection())) return;
      if (e.ctrlKey || e.metaKey) window.open(a.href, '_blank', 'noopener'); else location.href = a.href;
    });
  });

  /* 7. Toast: stays long enough to read, then leaves quickly */
  $$('.toast').forEach(t => {
    if (!reduce && getComputedStyle(t).position !== 'static') { const bar = document.createElement('span'); bar.className = 'toast-bar'; bar.setAttribute('aria-hidden', 'true'); t.appendChild(bar); }
    const hide = () => { t.classList.add('toast-out'); setTimeout(() => t.remove(), 160); };
    const ms = parseInt(t.dataset.ms || '4800', 10);   // a message with an Undo button stays longer
    const bar = t.querySelector('.toast-bar'); if (bar) bar.style.animationDuration = ms + 'ms';
    setTimeout(hide, ms);
    t.addEventListener('click', e => { if (!e.target.closest('form')) hide(); });
  });
})();

/* 8. Pop-out sidebar: hover the left edge to reveal it, pin it to keep it out */
(() => {
  'use strict';
  const sb = document.getElementById('sidebar');
  const edge = document.getElementById('sbEdge');
  const pin = document.getElementById('sbPin');
  if (!sb || !edge || !pin) return;
  const desktop = matchMedia('(min-width: 1024px)');
  const root = document.documentElement;

  const isPinned = () => root.dataset.sb === 'pinned';
  let openT = 0, closeT = 0;
  const open = () => {
    if (!desktop.matches || isPinned()) return;
    clearTimeout(closeT);
    if (!sb.classList.contains('open')) { sb.classList.add('open'); root.classList.add('sb-open'); seen(); }
  };
  const close = () => { clearTimeout(openT); sb.classList.remove('open'); root.classList.remove('sb-open'); };
  const seen = () => { root.dataset.sbseen = '1'; try { localStorage.setItem('sbSeen', '1'); } catch (e) { /* ignore */ } };
  const menuLg = document.getElementById('menuLg');
  if (menuLg) menuLg.addEventListener('click', () => { open(); });
  const sync = () => {
    const p = isPinned();
    pin.setAttribute('aria-pressed', String(p));
    pin.setAttribute('aria-label', p ? 'Unpin sidebar' : 'Pin sidebar');
    pin.title = p ? 'Unpin sidebar (auto-hide)' : 'Pin sidebar open';
  };
  sync();

  edge.addEventListener('mouseenter', () => { clearTimeout(openT); openT = setTimeout(open, 120); });
  edge.addEventListener('mouseleave', () => clearTimeout(openT));
  sb.addEventListener('mouseenter', () => clearTimeout(closeT));
  sb.addEventListener('mouseleave', () => {
    if (isPinned()) return;
    clearTimeout(closeT);
    closeT = setTimeout(() => { if (!sb.matches(':hover')) { if (sb.contains(document.activeElement)) document.activeElement.blur(); close(); } }, 260);
  });

  const setPinned = on => {
    if (on) root.dataset.sb = 'pinned'; else delete root.dataset.sb;
    try { localStorage.setItem('sbPinned', on ? '1' : '0'); } catch (e) { /* storage may be blocked */ }
    sync();
    if (on) { sb.classList.remove('open'); root.classList.remove('sb-open'); } else { sb.classList.add('open'); root.classList.add('sb-open'); }
    seen();
    pin.blur();
  };
  pin.addEventListener('click', () => setPinned(!isPinned()));

  document.addEventListener('keydown', e => {
    const typing = /^(input|textarea|select)$/i.test((e.target.tagName || '')) || e.target.isContentEditable;
    if (e.key === 'Escape' && !isPinned() && sb.contains(document.activeElement)) { document.activeElement.blur(); close(); }
    if (e.key === '[' && !typing && desktop.matches && !e.ctrlKey && !e.metaKey) setPinned(!isPinned());
  });
  desktop.addEventListener('change', () => { if (!desktop.matches) close(); });
})();

/* 9. Easter eggs: typing a secret word in the search bar pops up a button to a hidden page */
(() => {
  'use strict';
  const input = document.getElementById('gsearch');
  if (!input) return;
  // "creator" ignores capitals; "Teletollie" must be typed exactly (capital T), so it can not appear by accident.
  const eggs = [
    { pop: document.getElementById('creatorPop'), hit: v => v.trim().toLowerCase() === 'creator' },
    { pop: document.getElementById('teletolliePop'), hit: v => v.trim() === 'Teletollie' },
  ].filter(e => e.pop);
  if (!eggs.length) return;
  const sync = () => eggs.forEach(e => { e.pop.hidden = !e.hit(input.value); });
  const hideAll = () => eggs.forEach(e => { if (!e.pop.matches(':focus, :hover')) e.pop.hidden = true; });
  input.addEventListener('input', sync);
  input.addEventListener('focus', sync);
  input.addEventListener('blur', () => setTimeout(hideAll, 180));
  input.addEventListener('keydown', e => {
    const egg = eggs.find(x => x.hit(input.value));
    if (e.key === 'Enter' && egg) { e.preventDefault(); egg.pop.hidden = false; egg.pop.focus(); }   // Enter hands focus to the button instead of searching
    if (e.key === 'Escape') eggs.forEach(x => { x.pop.hidden = true; });
  });
  eggs.forEach(egg => egg.pop.addEventListener('blur', () => setTimeout(() => { if (!egg.hit(input.value) || document.activeElement !== input) egg.pop.hidden = !egg.pop.matches(':hover'); }, 180)));
})();
