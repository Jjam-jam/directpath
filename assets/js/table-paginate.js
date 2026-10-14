/* ============================================================
   Directpath HRMS - table pagination
   Every <table> with more than one page of rows gets a small, centered pager underneath it.
   The selected page is highlighted. Works together with table-sort.js, table-search.js,
   the live-filter page swap and tables built by JavaScript.

   Optional markup:
     <table data-no-paginate>          never paginate this table (or any table inside the element)
     <table data-page-size="25">       rows per page for this table (default 10)
     <tr data-ts-keep>                 row stays visible on every page (e.g. a TOTAL row)

   Not paginated automatically: payslip / letter layouts, tables that already have server-side
   paging, and the Reports modules (header.php does not load this script there).
   ============================================================ */
(function () {
    'use strict';

    var PAGE_SIZE = 10;
    var SKIP_TABLE = 'table.ps, table.cl-salary, table.pay-table, table.d-tbl';
    var queued = false;

    /* ---------- rows ---------- */

    // "No records found." style row, or a pinned TOTAL row - never paged
    function pinned(tr) {
        if (tr.hasAttribute('data-ts-keep')) return true;
        if (tr.cells.length === 1 && tr.cells[0].colSpan > 1) return true;
        if (tr.cells.length && tr.cells[0].tagName === 'TH' && tr.cells[0].colSpan > 1) return true;
        return false;
    }

    // rows a search / filter has already hidden are not counted
    function filteredOut(tr) {
        return tr.hidden || tr.classList.contains('ts-hide') || tr.style.display === 'none';
    }

    function pagedRows(table) {
        var out = [];
        Array.prototype.forEach.call(table.tBodies, function (tb) {
            Array.prototype.forEach.call(tb.rows, function (tr) {
                if (pinned(tr) || filteredOut(tr)) return;
                out.push(tr);
            });
        });
        return out;
    }

    function sizeOf(table) {
        var n = parseInt(table.getAttribute('data-page-size'), 10);
        return n > 0 ? n : PAGE_SIZE;
    }

    /* ---------- pager ---------- */

    // 1 … 4 5 6 … 12
    function windowOf(page, pages) {
        var out = [], i;
        for (i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || Math.abs(i - page) <= 1 || (page <= 3 && i <= 4) || (page >= pages - 2 && i >= pages - 3)) out.push(i);
            else if (out[out.length - 1] !== '…') out.push('…');
        }
        return out;
    }

    function btn(label, page, opts) {
        opts = opts || {};
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'dp-btn' + (opts.cur ? ' is-current' : '') + (opts.nav ? ' is-nav' : '');
        b.textContent = label;
        b.setAttribute('data-page', page);
        if (opts.aria) b.setAttribute('aria-label', opts.aria);
        if (opts.cur) b.setAttribute('aria-current', 'page');
        if (opts.off) b.disabled = true;
        return b;
    }

    function renderPager(table, page, pages, from, to, total) {
        var nav = table.__dpNav;
        if (pages <= 1) { nav.hidden = true; return; }
        nav.hidden = false;
        nav.innerHTML = '';

        var row = document.createElement('div');
        row.className = 'dp-row';
        row.appendChild(btn('‹', page - 1, { nav: 1, off: page === 1, aria: 'Previous page' }));
        windowOf(page, pages).forEach(function (p) {
            if (p === '…') {
                var s = document.createElement('span');
                s.className = 'dp-gap';
                s.textContent = '…';
                row.appendChild(s);
            } else {
                row.appendChild(btn(p, p, { cur: p === page, aria: 'Page ' + p }));
            }
        });
        row.appendChild(btn('›', page + 1, { nav: 1, off: page === pages, aria: 'Next page' }));
        nav.appendChild(row);

        var info = document.createElement('div');
        info.className = 'dp-info';
        info.textContent = from + '–' + to + ' of ' + total;
        nav.appendChild(info);
    }

    /* ---------- paging ---------- */

    function apply(table) {
        var rows = pagedRows(table), size = sizeOf(table), total = rows.length;
        var pages = Math.max(1, Math.ceil(total / size));

        // a search / filter changed the row count: start again from page 1
        if (table.__dpTotal !== undefined && table.__dpTotal !== total) table.__dpPage = 1;
        table.__dpTotal = total;
        var page = Math.min(Math.max(1, table.__dpPage || 1), pages);
        table.__dpPage = page;

        var start = (page - 1) * size, end = start + size;
        if (table.__dpObs) table.__dpObs.disconnect();      // our own class changes must not retrigger us
        rows.forEach(function (tr, i) {
            var hide = pages > 1 && (i < start || i >= end);
            if (tr.classList.contains('dp-hide') !== hide) tr.classList.toggle('dp-hide', hide);
        });
        renderPager(table, page, pages, total ? start + 1 : 0, Math.min(end, total), total);
        watch(table);
    }

    function watch(table) {
        if (!table.__dpObs || !window.MutationObserver) return;
        table.__dpObs.observe(table, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'] });
    }

    function relevant(records) {
        return records.some(function (r) {
            var t = r.target.tagName;
            if (r.type === 'childList') return t === 'TBODY' || t === 'TABLE';
            return t === 'TR';
        });
    }

    /* ---------- setting tables up ---------- */

    function build(table) {
        if (table.__dpReady) return;
        if (table.hasAttribute('data-no-paginate') || table.closest('[data-no-paginate]')) return;
        if (table.matches(SKIP_TABLE) || table.closest('table') !== table) return;   // layouts and nested tables
        if (!table.tBodies.length || !table.tHead) return;
        table.__dpReady = true;

        var nav = document.createElement('nav');
        nav.className = 'dp-pager';
        nav.setAttribute('aria-label', 'Table pagination');
        nav.hidden = true;
        var host = table.parentNode && table.parentNode.classList.contains('table-wrapper') ? table.parentNode : table;
        host.parentNode.insertBefore(nav, host.nextSibling);
        table.__dpNav = nav;
        table.__dpPage = 1;

        if (window.MutationObserver) {
            var pending = false;
            table.__dpObs = new MutationObserver(function (records) {
                if (pending || !relevant(records)) return;
                pending = true;
                (window.requestAnimationFrame || setTimeout)(function () { pending = false; apply(table); });
            });
        }
        nav.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('button[data-page]');
            if (!b || b.disabled) return;
            table.__dpPage = parseInt(b.getAttribute('data-page'), 10);
            apply(table);
        });
        apply(table);
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

    function start() {
        init();
        if (window.MutationObserver) new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
