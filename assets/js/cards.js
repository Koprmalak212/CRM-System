/*
 * Phone layout for tables, part 2 (stage 12). Reads each table's header, copies the column names onto the cells (data-label) so
 * assets/css/cards.css can show them beside the values on a phone, wraps cell contents so they can sit in a grid, and gives the table
 * explicit ARIA roles so assistive technology still reads rows and columns after the CSS turns it into cards.
 * Tables built later by other scripts (the Uptime page) are handled too. Add data-plain to a table to leave it alone.
 */
(() => {
  'use strict';
  const text = el => (el.textContent || '').replace(/\s+/g, ' ').trim();

  function prepare(table) {
    if (table.dataset.cards === '1' || table.hasAttribute('data-plain') || !table.tHead) return;
    const headRow = table.tHead.rows[table.tHead.rows.length - 1];
    if (!headRow) return;
    const labels = [];
    let sortable = false;
    Array.from(headRow.cells).forEach(th => {
      const span = th.colSpan || 1;
      for (let i = 0; i < span; i++) labels.push(text(th));
      if (th.querySelector('a[href]')) { th.classList.add('rt-sort'); sortable = true; }
    });
    if (sortable) headRow.classList.add('rt-has-sort');
    table.classList.add('rt');
    table.setAttribute('role', 'table');
    table.tHead.setAttribute('role', 'rowgroup');
    headRow.setAttribute('role', 'row');
    Array.from(headRow.cells).forEach(th => th.setAttribute('role', 'columnheader'));
    table.dataset.cards = '1';
    Array.from(table.tBodies).forEach(tb => { tb.setAttribute('role', 'rowgroup'); watchBody(tb, labels); });
    Array.from(table.tBodies).forEach(tb => Array.from(tb.rows).forEach(tr => row(tr, labels)));
  }

  function row(tr, labels) {
    if (tr.dataset.rt === '1') return;
    tr.dataset.rt = '1';
    tr.setAttribute('role', 'row');
    let col = 0;
    Array.from(tr.cells).forEach((cell, i) => {
      const span = cell.colSpan || 1;
      const isRowHeader = cell.tagName === 'TH';
      cell.setAttribute('role', isRowHeader ? 'rowheader' : 'cell');
      if (span === 1) {
        const label = labels[col] || '';
        cell.setAttribute('data-label', label);
        if (i === 0) cell.setAttribute('data-main', '');
      }
      // contents go into one wrapper so a cell with several elements still sits in the grid as two columns
      if (!cell.querySelector(':scope > .rt-v')) {
        const w = document.createElement('span');
        w.className = 'rt-v';
        while (cell.firstChild) w.appendChild(cell.firstChild);
        cell.appendChild(w);
      }
      col += span;
    });
  }

  function watchBody(tb, labels) {
    new MutationObserver(list => list.forEach(m => m.addedNodes.forEach(n => { if (n.nodeType === 1 && n.tagName === 'TR') row(n, labels); }))).observe(tb, { childList: true });
  }

  const scan = root => (root.querySelectorAll ? root : document).querySelectorAll('table').forEach(prepare);
  scan(document);
  // tables that scripts add after the page has loaded
  new MutationObserver(list => list.forEach(m => m.addedNodes.forEach(n => {
    if (n.nodeType !== 1) return;
    if (n.tagName === 'TABLE') prepare(n); else if (n.querySelectorAll) n.querySelectorAll('table').forEach(prepare);
  }))).observe(document.body, { childList: true, subtree: true });
})();
