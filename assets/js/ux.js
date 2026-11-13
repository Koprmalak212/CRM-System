/*
 * Small conveniences for the signed-in pages (loaded by includes/core/layout.php). Every part works on its own and the pages work without it:
 *   1. menus that close            2. quick changes in a list row     3. search suggestions
 *   4. lists remember filters      5. messages under a field          6. drafts of comments and notes
 *   7. warning for unsaved forms   8. warning before the session ends 9. keyboard shortcuts
 * Nothing here talks to another site. What is remembered stays in this browser tab (sessionStorage) and is gone when the tab closes.
 */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const store = {
    get(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* storage may be blocked */ } },
    del(k) { try { sessionStorage.removeItem(k); } catch (e) { /* storage may be blocked */ } },
    keys(prefix) { try { return Object.keys(sessionStorage).filter(k => k.startsWith(prefix)); } catch (e) { return []; } },
  };
  const typing = el => !!el && (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName || '') || el.isContentEditable);
  const main = $('#main');

  /* ---------- 1. Menus (details[data-menu]) close on a click outside and on Escape ---------- */
  const menus = $$('details[data-menu]');
  document.addEventListener('click', e => menus.forEach(m => { if (m.open && !m.contains(e.target)) m.open = false; }));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') menus.forEach(m => { if (m.open) { m.open = false; $('summary', m).focus(); } }); });

  /* ---------- 2. Quick changes: a dropdown in a list row saves as soon as it changes ---------- */
  $$('form[data-quick]').forEach(f => f.addEventListener('change', () => { if (f.requestSubmit) f.requestSubmit(); else f.submit(); }));

  /* ---------- 3. Search suggestions under the search bar ---------- */
  const gs = $('#gsearch');
  if (gs && window.fetch) {
    const box = document.createElement('ul');
    box.id = 'gsuggest'; box.hidden = true; box.setAttribute('role', 'listbox'); box.setAttribute('aria-label', 'Suggestions');
    box.className = 'notranslate absolute left-0 right-0 top-12 z-50 max-h-[26rem] overflow-y-auto rounded-lg border border-line bg-panel py-1 shadow-[0_14px_30px_-10px_rgba(0,0,0,.8)]';
    gs.form.appendChild(box);
    gs.setAttribute('autocomplete', 'off'); gs.setAttribute('aria-controls', 'gsuggest');
    let timer = 0, seq = 0, active = -1;
    const isEgg = v => v.trim().toLowerCase() === 'creator' || v.trim() === 'Teletollie';   // the easter eggs own the space under the bar for these words
    const opts = () => $$('a', box);
    const mark = i => { active = i; opts().forEach((a, n) => { a.classList.toggle('bg-butter-50', n === i); a.setAttribute('aria-selected', String(n === i)); }); if (i >= 0) opts()[i].scrollIntoView({ block: 'nearest' }); };
    const close = () => { box.hidden = true; active = -1; };
    const row = (href, title, sub, kind) => {
      const li = document.createElement('li'), a = document.createElement('a');
      a.href = href; a.setAttribute('role', 'option'); a.className = 'flex items-baseline gap-3 px-4 py-2 text-sm hover:bg-butter-50';
      const k = document.createElement('span'); k.className = 'w-16 shrink-0 text-xs font-semibold text-soft'; k.textContent = kind;
      const t = document.createElement('span'); t.className = 'min-w-0 flex-1 truncate font-semibold'; t.textContent = title;
      const s = document.createElement('span'); s.className = 'max-w-[40%] shrink-0 truncate text-xs text-soft'; s.textContent = sub;
      a.append(k, t, s); li.appendChild(a); return li;
    };
    const load = () => {
      const q = gs.value.trim();
      if (q.length < 2 || isEgg(gs.value)) { close(); return; }
      const mine = ++seq;
      fetch('search/suggest.php?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(r => (r.ok && !r.redirected ? r.json() : []))
        .then(list => {
          if (mine !== seq || document.activeElement !== gs) return;
          box.textContent = '';
          (Array.isArray(list) ? list : []).forEach(x => box.appendChild(row(x.url, x.title, x.sub || '', x.kind)));
          box.appendChild(row('search/search.php?q=' + encodeURIComponent(q), list.length ? 'See all results for “' + q + '”' : 'Nothing found yet. Search everything for “' + q + '”', '', ''));
          active = -1; box.hidden = false;
        }).catch(() => close());
    };
    gs.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 200); });
    gs.addEventListener('keydown', e => {
      if (box.hidden) return;
      const n = opts().length;
      if (e.key === 'ArrowDown') { e.preventDefault(); mark((active + 1) % n); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); mark((active - 1 + n) % n); }
      else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); location.href = opts()[active].href; }
      else if (e.key === 'Escape') close();
    });
    gs.addEventListener('blur', () => setTimeout(() => { if (!box.contains(document.activeElement)) close(); }, 150));
  }

  /* ---------- 4. Lists remember their filters, sorting and page, and "back" links return to them ---------- */
  const here = location.pathname;
  if ($('form[data-auto]')) {
    if (location.search.length > 1) store.set('list:' + here, location.search); else store.del('list:' + here);
  }
  $$('#sidebar a.nav-item, #main a[href]').forEach(a => {
    if (a.closest('#main') && !a.closest('nav[aria-label="Breadcrumb"]') && !a.querySelector('.icon-left')) return;   // in the page only the "back to the list" links
    let u; try { u = new URL(a.href); } catch (e) { return; }
    if (u.origin !== location.origin || u.search || u.pathname === here) return;   // a link on the list itself ("Clear filters") keeps meaning "everything"
    const kept = store.get('list:' + u.pathname);
    if (kept) a.href = u.pathname + kept + u.hash;
  });

  /* ---------- 5. A message under a field as soon as the person leaves it (the server still checks everything) ---------- */
  const message = el => {
    const v = el.validity;
    if (v.valid) return '';
    if (v.valueMissing) return el.type === 'checkbox' ? 'Tick this box to continue.' : (el.tagName === 'SELECT' ? 'Choose one.' : 'This can not be empty.');
    if (v.typeMismatch) return el.type === 'email' ? 'That does not look like an email address.' : (el.type === 'url' ? 'That does not look like a web address. Start with https://' : 'That is not valid.');
    if (v.tooLong) return 'At most ' + el.maxLength + ' characters.';
    if (v.tooShort) return 'At least ' + el.minLength + ' characters.';
    if (v.rangeUnderflow) return 'Not lower than ' + el.min + '.';
    if (v.rangeOverflow) return 'Not higher than ' + el.max + '.';
    if (v.patternMismatch) return el.title || 'That is not in the expected form.';
    if (v.badInput || v.stepMismatch) return el.type === 'number' ? 'Enter a number.' : 'That is not valid.';
    return 'That is not valid.';
  };
  const show = el => {
    const text = message(el), id = (el.id || el.name) + '-msg';
    let p = el.form ? $('[data-msg-for="' + CSS.escape(id) + '"]', el.form) : null;
    if (!text) { if (p) p.remove(); el.removeAttribute('aria-invalid'); return; }
    if (!p) {
      p = document.createElement('p'); p.dataset.msgFor = id; p.id = id; p.className = 'mt-1 text-xs font-semibold text-[#FF9C8C]';
      (el.closest('.relative') && el.closest('.relative') !== el.form ? el.closest('.relative') : el).insertAdjacentElement('afterend', p);
    }
    p.textContent = text; el.setAttribute('aria-invalid', 'true');
  };
  if (main) {
    const checked = el => el.matches && el.matches('input:not([type=hidden]):not([type=file]):not([type=password]), select, textarea') && el.form && el.form.method === 'post' && el.willValidate;
    main.addEventListener('focusout', e => { if (checked(e.target) && (e.target.value !== '' || e.target.dataset.touched)) show(e.target); });
    main.addEventListener('input', e => { if (checked(e.target)) { e.target.dataset.touched = '1'; if (e.target.hasAttribute('aria-invalid')) show(e.target); } });
  }

  /* ---------- 6. Drafts: text typed in a comment or note box survives a refresh, a wrong click and an expired session ---------- */
  const areas = main ? $$('form[method="post"] textarea[name]:not([readonly])', main) : [];
  const draftKey = i => 'draft:' + here + location.search + '#' + i;
  const failed = !!$('.toast.bg-\\[\\#FFD3CC\\]');   // the last save was refused, so what was sent is not stored yet
  areas.forEach((ta, i) => {
    const key = draftKey(i);
    let saved = null; try { saved = JSON.parse(store.get(key) || 'null'); } catch (e) { saved = null; }
    if (saved && saved.sent && !failed) { store.del(key); saved = null; }
    if (saved && typeof saved.v === 'string' && saved.v !== ta.value && ta.value === ta.defaultValue) {
      const before = ta.value;
      ta.value = saved.v;
      const note = document.createElement('p'); note.className = 'mt-1 text-xs text-soft';
      const undo = document.createElement('button'); undo.type = 'button'; undo.className = 'font-semibold underline decoration-lime decoration-2 underline-offset-4 hover:text-ink'; undo.textContent = 'Discard it';
      undo.addEventListener('click', () => { ta.value = before; store.del(key); note.remove(); ta.focus(); });
      note.append('Your unsent text was put back. ', undo);
      ta.insertAdjacentElement('afterend', note);
      const d = ta.closest('details'); if (d) d.open = true;
    }
    let t = 0;
    ta.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { if (ta.value.trim() !== '' && ta.value !== ta.defaultValue) store.set(key, JSON.stringify({ v: ta.value })); else store.del(key); }, 300); });
    ta.form.addEventListener('submit', () => { if (ta.value.trim() !== '' && ta.value !== ta.defaultValue) store.set(key, JSON.stringify({ v: ta.value, sent: true })); });
  });
  const out = $('form[action$="auth/logout.php"]');
  if (out) out.addEventListener('submit', () => store.keys('draft:').concat(store.keys('list:')).forEach(k => store.del(k)));   // signing out leaves nothing behind

  /* ---------- 7. Leaving a form with changes that are not saved asks first (text boxes are covered by their draft) ---------- */
  const dirty = new Set();
  let leaving = false;
  if (main) {
    const track = e => { const el = e.target; if (e.isTrusted && el.form && el.form.method === 'post' && !el.form.hasAttribute('data-quick') && !areas.includes(el) && el.type !== 'file') dirty.add(el); };
    main.addEventListener('input', track); main.addEventListener('change', track);
    document.addEventListener('submit', () => { leaving = true; }, true);
    addEventListener('beforeunload', e => { if (dirty.size && !leaving) { e.preventDefault(); e.returnValue = ''; } });
  }

  /* ---------- 8. A warning two minutes before the session ends, with a button to stay signed in ---------- */
  const idle = parseInt(document.body.dataset.idle || '0', 10) * 1000;
  if (idle > 0 && window.fetch) {
    const WARN = 120000, KEY = 'sessionSeen';
    const seen = () => { try { return parseInt(localStorage.getItem(KEY) || '0', 10) || 0; } catch (e) { return 0; } };
    const touch = () => { try { localStorage.setItem(KEY, String(Date.now())); } catch (e) { /* storage may be blocked */ } last = Date.now(); };
    let last = Date.now(), bar = null, state = '';
    touch();   // this page just loaded, which counted as activity for every tab
    const build = () => {
      bar = document.createElement('div');
      bar.setAttribute('role', 'alert');
      bar.className = 'fixed inset-x-4 bottom-4 z-[70] mx-auto flex max-w-xl flex-wrap items-center gap-3 rounded-lg border border-line bg-panel px-4 py-3 text-sm shadow-[0_14px_30px_-10px_rgba(0,0,0,.8)]';
      document.body.appendChild(bar);
    };
    const render = s => {
      if (s === state) return; state = s;
      if (s === '') { if (bar) { bar.remove(); bar = null; } return; }
      if (!bar) build();
      bar.textContent = '';
      const p = document.createElement('p'); p.className = 'min-w-0 flex-1';
      if (s === 'soon') {
        p.textContent = 'You will be signed out in about two minutes, because nothing happened here for a while.';
        const b = document.createElement('button'); b.type = 'button'; b.className = 'h-9 rounded-lg bg-lime px-4 text-sm font-bold text-navy hover:brightness-95'; b.textContent = 'Stay signed in';
        b.addEventListener('click', () => {
          fetch('auth/ping.php', { credentials: 'same-origin', cache: 'no-store' }).then(r => { if (r.status === 204) { touch(); render(''); } else render('ended'); }).catch(() => { p.textContent = 'The server could not be reached. Check your connection and try again.'; });
        });
        bar.append(p, b); b.focus();
      } else {
        p.textContent = 'You were signed out because nothing happened for a while. Text you typed in a comment or note box is kept in this tab.';
        const a = document.createElement('a'); a.href = 'auth/login.php'; a.className = 'inline-flex h-9 items-center rounded-lg bg-lime px-4 text-sm font-bold text-navy hover:brightness-95'; a.textContent = 'Sign in again';
        a.addEventListener('click', () => { leaving = true; });
        bar.append(p, a);
      }
    };
    setInterval(() => {
      const left = idle - (Date.now() - Math.max(last, seen()));
      render(left <= 0 ? 'ended' : (left <= WARN ? 'soon' : ''));
    }, 10000);
  }

  /* ---------- 9. Keyboard shortcuts: g then a letter goes to a page, n opens "New", ? shows the list ---------- */
  const help = $('#kbdHelp'), newMenu = $('#newMenu');
  const pages = { o: 'index.php', t: 'tickets/tickets.php', c: 'companies/companies.php', u: 'users/users.php', i: 'invoices/invoices.php', a: 'calendar/calendar.php', l: 'system/logs.php', s: 'settings/settings.php' };
  const openHelp = () => { if (help && help.showModal && !help.open) help.showModal(); };
  const kbdOpen = $('#kbdOpen'); if (kbdOpen) kbdOpen.addEventListener('click', openHelp);
  let gAt = 0;
  document.addEventListener('keydown', e => {
    if (e.ctrlKey || e.metaKey || e.altKey || typing(e.target) || (help && help.open)) return;
    if (gAt && Date.now() - gAt < 1500 && pages[e.key]) {
      gAt = 0;
      const a = $$('#sidebar a.nav-item').find(x => x.getAttribute('href').split('?')[0] === pages[e.key]);   // only pages that are in the menu (a module can be off)
      if (a) { e.preventDefault(); a.click(); }
      return;
    }
    gAt = 0;
    if (e.key === 'g') gAt = Date.now();
    else if (e.key === 'n' && newMenu) { e.preventDefault(); newMenu.open = true; const a = $('a', newMenu); if (a) a.focus(); }
    else if (e.key === '?') { e.preventDefault(); openHelp(); }
  });
})();
