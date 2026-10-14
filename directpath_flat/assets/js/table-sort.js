/* ============================================================
   Directpath HRMS - click-to-sort table headers
   Every <table> that has a <thead> is sortable automatically. Click a header to sort
   ascending, click again for descending, click a third time to go back to the original order.
   Works with the live-filter page swap and with tables built by JavaScript.

   Optional markup:
     <table data-no-sort>        never sort this table
     <th data-no-sort>           never sort this column
     <td data-sort="123">        sort by this value instead of the visible text
     <tr data-ts-keep>           row stays pinned at the bottom (e.g. a TOTAL row)

   Detected automatically per column: numbers (₱ $ , % and units are ignored),
   dates / times, otherwise text (natural order, so "EMP-2" comes before "EMP-10").
   Blank cells (empty, "—", "N/A") always go to the bottom.
   ============================================================ */
(function () {
    'use strict';

    var SKIP_TABLE = 'table.ps, table.cl-salary, table.pay-table';
    var SKIP_TEXT = /^(actions?|#|select|view|manage|options?|photos?|current\s+photos?|uploaded\s+photos?|photo\s+uploads?|images?|current\s+images?|update|updates|edit|delete|remove|upload|download|print|preview|reset|clear|approve|reject|assign|schedule\s+action)$/i;
    var BLANK = /^(|—|–|-|--|n\/a|na|none|null)$/i;
    var MONTHS = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 };

    var saved = {};          // chosen sort per table, so it survives the live-filter page swap
    var seq = 0;             // used to remember the original row order
    var queued = false;
    var collator = window.Intl && Intl.Collator ? new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' }) : null;

    function clean(s) { return String(s == null ? '' : s).replace(/\s+/g, ' ').trim(); }

    /* ---------- finding the sortable columns ---------- */

    // the header row that sits directly above the data
    function headerRow(table) {
        var th = table.tHead;
        if (!th || !th.rows.length) return null;
        return th.rows[th.rows.length - 1];
    }

    // column index of a cell, counting colspans of the cells before it
    function colIndex(cell) {
        var idx = 0, c = cell.previousElementSibling;
        while (c) { idx += c.colSpan || 1; c = c.previousElementSibling; }
        return idx;
    }

    function sortable(th) {
        if (th.hasAttribute('data-no-sort')) return false;
        if ((th.colSpan || 1) > 1) return false;
        if (th.querySelector('input[type="checkbox"]')) return false;
        var t = clean(th.textContent).replace(/[：:]+$/, '').replace(/\s+/g, ' ');
        // Non-data/action columns should never get sorting behavior.
        return t !== '' && !SKIP_TEXT.test(t);
    }

    /* ---------- rows ---------- */

    // "No records found." style row, or a TOTAL row - stays at the bottom
    function pinned(tr) {
        if (tr.hasAttribute('data-ts-keep')) return true;
        if (tr.cells.length === 1 && tr.cells[0].colSpan > 1) return true;
        if (tr.cells.length && tr.cells[0].tagName === 'TH' && tr.cells[0].colSpan > 1) return true;
        return false;
    }

    function bodyRows(table) {
        var out = [], pins = [];
        Array.prototype.forEach.call(table.tBodies, function (tb) {
            Array.prototype.forEach.call(tb.rows, function (tr) {
                if (tr.__tsO === undefined) tr.__tsO = seq++;
                (pinned(tr) ? pins : out).push(tr);
            });
        });
        return { rows: out, pins: pins };
    }

    /* ---------- reading values ---------- */

    function cellText(tr, col) {
        var td = tr.cells[col];
        if (!td) return '';
        var v = td.getAttribute('data-sort');
        return clean(v !== null ? v : td.textContent);
    }

    function toNumber(s) {
        var t = s.replace(/[₱$€£¥]/g, '').replace(/\bPHP\b/i, '').replace(/\s+/g, ' ').trim();
        var m = /^(\(?)\s*(-|−)?\s*(\d{1,3}(?:,\d{3})+|\d+)(\.\d+)?\s*\)?\s*(%|min|mins|hrs?|hours?|days?|d)?$/i.exec(t);
        if (!m) return null;
        var n = parseFloat((m[3] + (m[4] || '')).replace(/,/g, ''));
        return (m[1] || m[2]) ? -n : n;
    }

    function monthNum(w) { return MONTHS[w.slice(0, 3).toLowerCase()]; }

    // first date in the text (ranges such as "Oct 01 – Oct 15, 2026" sort by their start)
    function toDate(s) {
        var m, y, mo, d, t = 0;
        var time = /(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?/i.exec(s);
        if (time) {
            var h = +time[1];
            if (time[4]) { h = h % 12; if (/pm/i.test(time[4])) h += 12; }
            t = h * 3600 + (+time[2]) * 60 + (+(time[3] || 0));
        }
        if ((m = /(\d{4})-(\d{1,2})-(\d{1,2})/.exec(s))) {
            return Date.UTC(+m[1], +m[2] - 1, +m[3]) / 1000 + t;
        }
        if ((m = /(\d{1,2})\/(\d{1,2})\/(\d{2,4})/.exec(s))) {
            y = +m[3]; if (y < 100) y += 2000;
            return Date.UTC(y, +m[1] - 1, +m[2]) / 1000 + t;
        }
        var re = /([A-Za-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?/g, first = null, year = null;
        while ((m = re.exec(s))) {
            if (monthNum(m[1]) === undefined) continue;
            if (!first) first = m;
            if (m[3]) year = +m[3];
        }
        if (first) {
            mo = monthNum(first[1]); d = +first[2]; y = first[3] ? +first[3] : year;
            if (y == null) return null;
            return Date.UTC(y, mo, d) / 1000 + t;
        }
        // a bare month + year, e.g. "October 2026"
        if ((m = /^([A-Za-z]{3,9})\.?\s+(\d{4})$/.exec(s)) && monthNum(m[1]) !== undefined) {
            return Date.UTC(+m[2], monthNum(m[1]), 1) / 1000;
        }
        // time only, e.g. "08:30 AM"
        if (time && /^\d{1,2}:\d{2}(:\d{2})?\s*(AM|PM)?$/i.test(s)) return t;
        return null;
    }

    // decide numbers / dates / text for the whole column, then build one key per row
    function keysFor(rows, col) {
        var texts = rows.map(function (tr) { return cellText(tr, col); });
        var filled = texts.filter(function (t) { return !BLANK.test(t); });
        var kind = 'text';
        if (filled.length) {
            if (filled.every(function (t) { return toNumber(t) !== null; })) kind = 'num';
            else if (filled.every(function (t) { return toDate(t) !== null; })) kind = 'date';
        }
        return texts.map(function (t) {
            if (BLANK.test(t)) return { blank: true };
            if (kind === 'num') return { v: toNumber(t) };
            if (kind === 'date') return { v: toDate(t) };
            return { v: t };
        });
    }

    function compare(a, b) {
        if (typeof a === 'number' && typeof b === 'number') return a - b;
        a = String(a); b = String(b);
        if (collator) return collator.compare(a, b);
        return a < b ? -1 : a > b ? 1 : 0;
    }

    /* ---------- sorting ---------- */

    function keyFor(table) {
        var all = document.querySelectorAll('table'), i = Array.prototype.indexOf.call(all, table);
        return location.pathname + '#' + i;
    }

    function markHeaders(table, col, dir) {
        var hr = headerRow(table);
        if (!hr) return;
        Array.prototype.forEach.call(hr.cells, function (th) {
            if (!th.hasAttribute('data-ts-th')) return;
            if (dir && colIndex(th) === col) {
                th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
                th.setAttribute('data-ts-dir', dir);
            } else {
                th.setAttribute('aria-sort', 'none');
                th.removeAttribute('data-ts-dir');
            }
        });
    }

    function sortBy(table, col, dir) {
        var parts = bodyRows(table), rows = parts.rows;
        if (dir) {
            var keys = keysFor(rows, col), items = rows.map(function (tr, i) { return { tr: tr, k: keys[i] }; });
            items.sort(function (x, y) {
                if (x.k.blank || y.k.blank) {
                    if (x.k.blank && y.k.blank) return x.tr.__tsO - y.tr.__tsO;
                    return x.k.blank ? 1 : -1;          // blanks always last
                }
                var c = compare(x.k.v, y.k.v);
                if (c === 0) return x.tr.__tsO - y.tr.__tsO;   // stable
                return dir === 'asc' ? c : -c;
            });
            rows = items.map(function (it) { return it.tr; });
        } else {
            rows = rows.slice().sort(function (x, y) { return x.__tsO - y.__tsO; });
        }
        var tb = table.tBodies[0];
        if (!tb) return;
        // sort inside the body each row already lives in (usually just one)
        var frag = document.createDocumentFragment();
        rows.forEach(function (tr) { frag.appendChild(tr); });
        parts.pins.forEach(function (tr) { frag.appendChild(tr); });
        tb.appendChild(frag);
        markHeaders(table, col, dir);
        table.__tsState = dir ? { col: col, dir: dir } : null;
        saved[keyFor(table)] = table.__tsState;
    }

    function onActivate(th) {
        var table = th.closest('table');
        if (!table) return;
        var col = colIndex(th), st = table.__tsState, dir;
        if (!st || st.col !== col) dir = 'asc';
        else if (st.dir === 'asc') dir = 'desc';
        else dir = null;
        sortBy(table, col, dir);
    }

    /* ---------- setting tables up ---------- */

    function build(table) {
        if (table.__tsReady) return;
        if (table.hasAttribute('data-no-sort') || table.closest('[data-no-sort]')) return;
        if (table.matches(SKIP_TABLE)) return;
        var hr = headerRow(table);
        if (!hr || !table.tBodies.length) return;
        table.__tsReady = true;

        var any = false;
        Array.prototype.forEach.call(hr.cells, function (th) {
            if (!sortable(th)) return;
            any = true;
            th.setAttribute('data-ts-th', '');
            th.setAttribute('aria-sort', 'none');
            th.setAttribute('tabindex', '0');
            th.setAttribute('role', 'columnheader');
            if (!th.title) th.title = 'Click to sort';
        });
        if (!any) return;
        table.classList.add('ts-sortable');
        bodyRows(table);   // remember the original order

        var prev = saved[keyFor(table)];
        if (prev) sortBy(table, prev.col, prev.dir);
    }

    function init() {
        queued = false;
        Array.prototype.forEach.call(document.querySelectorAll('table'), build);
    }

    function schedule() {
        if (queued) return;
        queued = true;
        (window.requestAnimationFrame || setTimeout)(init);
    }

    document.addEventListener('click', function (e) {
        var th = e.target.closest && e.target.closest('th[data-ts-th]');
        if (!th) return;
        // links, buttons and fields inside a header keep working as normal
        if (e.target.closest('a, button, input, select, textarea, label')) return;
        onActivate(th);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var th = e.target.matches && e.target.matches('th[data-ts-th]') ? e.target : null;
        if (!th) return;
        e.preventDefault();
        onActivate(th);
    });

    var css = document.createElement('style');
    var ICON = function (up, down) {
        return 'url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'8\' height=\'12\' viewBox=\'0 0 8 12\'>' +
            '<path d=\'M4 0 8 5H0z\' fill=\'' + up + '\'/><path d=\'M4 12 0 7h8z\' fill=\'' + down + '\'/></svg>")';
    };
    css.textContent =
        'th[data-ts-th]{cursor:pointer;user-select:none;-webkit-user-select:none}' +
        'th[data-ts-th]:hover{color:#1A1D26}' +
        'th[data-ts-th]:focus-visible{outline:2px solid #CD1E27;outline-offset:-2px}' +
        'th[data-ts-th]::after{content:"";display:inline-block;width:8px;height:12px;margin-left:7px;vertical-align:-2px;' +
        'opacity:0;transition:opacity .12s ease;background:' + ICON('%23C9CDD4', '%23C9CDD4') + ' no-repeat center}' +
        'th[data-ts-th]:hover::after,th[data-ts-th]:focus-visible::after{opacity:1;background-image:' + ICON('%239CA3AF', '%239CA3AF') + '}' +
        'th[data-ts-dir="asc"]::after{background-image:' + ICON('%23CD1E27', '%23D9DCE1') + '}' +
        'th[data-ts-dir="desc"]::after{background-image:' + ICON('%23D9DCE1', '%23CD1E27') + '}' +
        'th[data-ts-dir]:not(:hover):not(:focus-visible)::after{opacity:0}' +
        'th[data-ts-th][data-ts-dir]{color:#1A1D26}' +
        '@media print{th[data-ts-th]::after{display:none}}';
    document.head.appendChild(css);

    function start() {
        init();
        if (window.MutationObserver) new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
