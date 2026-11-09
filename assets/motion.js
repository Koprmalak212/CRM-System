(() => {
  'use strict';
  window.__motion = 1;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  /* 1. Staging: panels arrive in reading order; each batch cascades, children follow through */
  $$('.rise').forEach(r => {
    $$(':scope > ul > li, :scope > dl > div, :scope .stat-grid > a, :scope > div > ul > li', r).slice(0, 14).forEach((c, i) => { c.classList.add('stg'); c.style.setProperty('--si', i); });
  });
  const rises = $$('.rise');
  if (reduce || !('IntersectionObserver' in window)) {
    rises.forEach(e => e.classList.add('in'));
  } else {
    const io = new IntersectionObserver(entries => {
      let n = 0;
      entries.filter(en => en.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top).forEach(en => {
        if (!en.target.style.getPropertyValue('--d')) en.target.style.setProperty('--d', (n * 70) + 'ms');
        n++;
        en.target.classList.add('in'); io.unobserve(en.target);
      });
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.04 });
    rises.forEach(e => io.observe(e));
  }
  $$('.sheen').forEach(e => { if (reduce) return; const o = new IntersectionObserver(es => { if (es[0].isIntersecting) { e.classList.add('in'); o.disconnect(); } }, { threshold: .3 }); o.observe(e); });

  /* 2. Follow-through: numbers overshoot their target, then settle (data-count, or data-money for euro amounts) */
  const easeOutBack = t => { const c1 = 1.4, c3 = c1 + 1; return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2); };
  $$('[data-count], [data-money]').forEach(el => {
    el.classList.add('notranslate');
    const money = el.hasAttribute('data-money');
    const to = parseFloat(money ? el.dataset.money : el.dataset.count);
    const dec = money ? 2 : parseInt(el.dataset.decimals || '0', 10);
    const suffix = el.dataset.suffix || '';
    const prefix = money ? (el.dataset.prefix || '€ ') : '';
    const delay = parseInt(el.dataset.delay || '0', 10);
    const fmt = v => prefix + Math.max(0, v).toLocaleString(money ? 'nl-NL' : 'en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suffix;
    if (reduce || isNaN(to)) { if (!isNaN(to) && !money) el.textContent = fmt(to); return; }
    const final = el.textContent;
    el.textContent = fmt(0);
    const run = () => {
      const t0 = performance.now(), dur = 1000;
      const step = now => {
        const t = Math.max(0, Math.min(1, (now - t0) / dur));
        el.textContent = fmt(to * easeOutBack(t));
        if (t < 1) requestAnimationFrame(step); else el.textContent = money ? final : fmt(to);
      };
      requestAnimationFrame(step);
    };
    const start = () => setTimeout(run, delay);
    if ('IntersectionObserver' in window) {
      const o = new IntersectionObserver(es => { if (es[0].isIntersecting) { o.disconnect(); start(); } }, { threshold: 0.2 });
      o.observe(el);
    } else start();
  });

  /* 3. Sidebar pill: squash and stretch while it travels, settles with a spring; remembers its origin across pages */
  const nav = $('#sidebar nav');
  const ind = $('#navInd');
  if (nav && ind) {
    const items = $$('.nav-item', nav);
    const active = items.find(i => i.getAttribute('aria-current') === 'page') || null;
    const yOf = el => el.offsetTop;
    let cur = 0, anim = null;
    const place = y => { ind.style.translate = '0 ' + y + 'px'; cur = y; };
    const glide = (to, instant) => {
      if (anim) { try { const m = getComputedStyle(ind).translate.split(' '); cur = parseFloat(m[1]) || cur; } catch (e) { /* keep cur */ } anim.cancel(); anim = null; }
      if (instant || reduce || !ind.animate || Math.abs(to - cur) < 1) { place(to); return; }
      const from = cur, d = Math.abs(to - from), stretch = Math.min(.34, .08 + d / 600), dir = to > from ? 1 : -1;
      place(to);
      anim = ind.animate([
        { translate: '0 ' + from + 'px', transform: 'scale(1,1)', transformOrigin: dir > 0 ? '50% 100%' : '50% 0%' },
        { translate: '0 ' + (from + (to - from) * .55) + 'px', transform: 'scale(' + (1 - stretch * .35) + ',' + (1 + stretch) + ')', offset: .4 },
        { translate: '0 ' + to + 'px', transform: 'scale(1,1)' }
      ], { duration: 560, easing: 'cubic-bezier(.34,1.25,.5,1)' });
      anim.onfinish = anim.oncancel = () => { anim = null; };
    };
    const home = () => { if (active) { ind.classList.add('on'); glide(yOf(active)); } else ind.classList.remove('on'); };
    let from = null;
    try { from = sessionStorage.getItem('navY'); } catch (e) { /* storage may be blocked */ }
    if (active) {
      ind.classList.add('on');
      if (from !== null && !reduce) { place(parseFloat(from)); requestAnimationFrame(() => glide(yOf(active))); }
      else place(yOf(active));
    }
    items.forEach(i => {
      const go = () => { if (!ind.classList.contains('on')) place(yOf(i)); ind.classList.add('on'); glide(yOf(i)); };
      i.addEventListener('mouseenter', go);
      i.addEventListener('focus', go);
      i.addEventListener('click', () => { try { sessionStorage.setItem('navY', String(yOf(i))); } catch (e) { /* ignore */ } });
    });
    nav.addEventListener('mouseleave', home);
    nav.addEventListener('focusout', e => { if (!nav.contains(e.relatedTarget)) home(); });
    addEventListener('pagehide', () => { try { if (active) sessionStorage.setItem('navY', String(yOf(active))); } catch (e) { /* ignore */ } });
  }

  /* 3b. Header gains depth once the page scrolls */
  const hdr = $('header.sticky');
  if (hdr) { const f = () => hdr.classList.toggle('scrolled', scrollY > 6); f(); addEventListener('scroll', f, { passive: true }); }

  /* 3c. A soft light follows the cursor over cards */
  if (!reduce) {
    $$('main .rounded-2xl, main .rounded-3xl').slice(0, 40).forEach(c => {
      if (getComputedStyle(c).position !== 'static') return;
      c.classList.add('glow');
      c.addEventListener('pointermove', e => { const r = c.getBoundingClientRect(); c.style.setProperty('--mx', (e.clientX - r.left) + 'px'); c.style.setProperty('--my', (e.clientY - r.top) + 'px'); }, { passive: true });
    });
  }

  /* 3d. Disclosures open with a spring instead of snapping */
  if (!reduce) $$('details').forEach(d => {
    const sum = d.querySelector(':scope > summary'); if (!sum) return;
    sum.addEventListener('click', () => {
      if (d.open) return;
      requestAnimationFrame(() => {
        Array.from(d.children).filter(c => c !== sum).forEach((c, i) => c.animate([{ opacity: 0, transform: 'translateY(-10px) scale(.99)' }, { opacity: 1, transform: 'none' }], { duration: 520, delay: i * 50, easing: 'cubic-bezier(.34,1.3,.64,1)', fill: 'backwards' }));
      });
    });
  });

  /* 4. Tooltip that trails the cursor with a little lag */
  $$('.bar[title], .col[title]').forEach(el => { el.dataset.tip = el.getAttribute('title'); el.removeAttribute('title'); });
  const tip = document.createElement('div');
  tip.className = 'tip notranslate';
  tip.setAttribute('aria-hidden', 'true');
  tip.innerHTML = '<span></span>';
  document.body.appendChild(tip);
  let tx = 0, ty = 0, cx = 0, cy = 0, raf = 0, shown = false;
  const loop = () => {
    cx += (tx - cx) * (reduce ? 1 : 0.2);
    cy += (ty - cy) * (reduce ? 1 : 0.2);
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

  /* 5. Table rows arrive as a list (capped so long tables never feel slow) */
  if (!reduce) $$('tbody tr').slice(0, 16).forEach((tr, i) => { tr.style.setProperty('--i', i); tr.classList.add('row-in'); });

  /* 6. Anticipation before an action fires: rows pull back, then leave, then the form submits */
  document.addEventListener('submit', e => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.sent) return;
    const row = form.matches('[data-exit]') ? form.closest('tr') : null;
    if (row && !reduce) {
      e.preventDefault();
      form.dataset.sent = '1';
      row.classList.add('row-exit');
      let done = false;
      const go = () => { if (!done) { done = true; form.submit(); } };
      row.addEventListener('animationend', go, { once: true });
      setTimeout(go, 520);
      return;
    }
    if (e.submitter) setTimeout(() => e.submitter.classList.add('is-busy'), 0);
  });

  /* 7. Toast: stays long enough to read, then leaves quickly */
  $$('.toast').forEach(t => {
    if (!reduce && getComputedStyle(t).position !== 'static') { const bar = document.createElement('span'); bar.className = 'toast-bar'; bar.setAttribute('aria-hidden', 'true'); t.appendChild(bar); }
    const hide = () => { t.classList.add('toast-out'); setTimeout(() => t.remove(), 280); };
    setTimeout(hide, 4800);
    t.addEventListener('click', hide);
  });
})();

/* 8. Pop-out sidebar: hover the left edge to reveal it, pin it to keep it out */
(() => {
  'use strict';
  const sb = document.getElementById('sidebar');
  const edge = document.getElementById('sbEdge');
  const tab = document.getElementById('sbTab');
  const pin = document.getElementById('sbPin');
  if (!sb || !edge || !pin) return;
  const desktop = matchMedia('(min-width: 1024px)');
  const root = document.documentElement;
  const items = Array.from(sb.querySelectorAll('.nav-item'));
  items.forEach((it, i) => it.style.setProperty('--i', i));
  const foot = sb.querySelector(':scope > div:last-of-type');
  if (foot) foot.style.setProperty('--i', items.length);

  const isPinned = () => root.dataset.sb === 'pinned';
  let openT = 0, closeT = 0, popT = 0;
  const pop = () => { sb.classList.add('popping'); clearTimeout(popT); popT = setTimeout(() => sb.classList.remove('popping'), 900); };
  const open = () => {
    if (!desktop.matches || isPinned()) return;
    clearTimeout(closeT);
    if (!sb.classList.contains('open')) { sb.classList.add('open'); root.classList.add('sb-open'); pop(); seen(); }
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

  edge.addEventListener('mouseenter', () => { clearTimeout(openT); openT = setTimeout(open, 70); });
  edge.addEventListener('mouseleave', () => clearTimeout(openT));
  if (tab) {
    tab.addEventListener('mouseenter', () => { clearTimeout(openT); openT = setTimeout(open, 70); });
    tab.addEventListener('click', open);
  }
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
    if (on) { sb.classList.remove('open'); root.classList.remove('sb-open'); pop(); } else { sb.classList.add('open'); root.classList.add('sb-open'); }
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
