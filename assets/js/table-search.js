/* ============================================================
   Directpath HRMS - live table search
   Add  data-table-search  to the element that wraps a <table>
   (.table-wrapper / .table-responsive) and a search box appears above it.
   Rows are filtered as you type, no reload and no button.
       <div class="table-wrapper" data-table-search>
       <div class="table-wrapper" data-table-search="Search employees...">   (custom placeholder)
   Add  data-ts-keep  to a <tr> that must always stay visible (e.g. a TOTAL row).
   Every word you type must appear somewhere in the row (any order, any column).
   ============================================================ */
(function () {
    'use strict';

    var SEL = '[data-table-search]';
    var saved = {};          // typed text per table, so it survives the live-filter page swap
    var queued = false;

    function norm(s) { return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim(); }

    function rowsOf(table) {
        var out = [];
        Array.prototype.forEach.call(table.tBodies, function (tb) {
            Array.prototype.forEach.call(tb.rows, function (tr) {
                if (tr.hasAttribute('data-ts-keep')) return;
                out.push(tr);
            });
        });
        return out;
    }

    // "No records found." style row: a single cell stretched across the table
    function isEmptyState(tr) {
        return tr.cells.length === 1 && tr.cells[0].hasAttribute('colspan');
    }

    function textOf(tr) {
        if (tr.__ts === undefined) tr.__ts = norm((tr.getAttribute('data-search') || '') + ' ' + tr.textContent);
        return tr.__ts;
    }

    function keyFor(wrap) {
        var all = document.querySelectorAll(SEL), i = Array.prototype.indexOf.call(all, wrap);
        return location.pathname + '#' + i;
    }

    function apply(wrap) {
        var input = wrap.__tsInput, table = wrap.querySelector('table');
        if (!input || !table) return;
        var terms = norm(input.value).split(' ').filter(Boolean);
        var rows = rowsOf(table), shown = 0, total = 0;

        rows.forEach(function (tr) {
            if (isEmptyState(tr)) { tr.classList.toggle('ts-hide', terms.length > 0); return; }
            total++;
            var t = textOf(tr), ok = terms.every(function (w) { return t.indexOf(w) !== -1; });
            tr.classList.toggle('ts-hide', !ok);
            if (ok) shown++;
        });

        wrap.__tsCount.textContent = terms.length && total ? 'Showing ' + shown + ' of ' + total : '';
        wrap.__tsNone.hidden = !(terms.length && total && shown === 0);
        input.setAttribute('data-has-value', terms.length ? '1' : '');
        saved[keyFor(wrap)] = input.value;
    }

    function build(wrap) {
        if (wrap.__tsReady || !wrap.querySelector('table')) return;
        wrap.__tsReady = true;

        var bar = document.createElement('div');
        bar.className = 'ts-bar';
        var input = document.createElement('input');
        input.type = 'search';
        input.className = 'search-input ts-input';
        input.autocomplete = 'off';
        input.placeholder = wrap.getAttribute('data-table-search') || 'Search this table...';
        input.setAttribute('aria-label', input.placeholder);
        var count = document.createElement('span');
        count.className = 'ts-count';
        bar.appendChild(input);
        bar.appendChild(count);

        var none = document.createElement('div');
        none.className = 'ts-none';
        none.hidden = true;
        none.textContent = 'No matching results.';

        wrap.parentNode.insertBefore(bar, wrap);
        wrap.parentNode.insertBefore(none, wrap.nextSibling);
        wrap.__tsInput = input; wrap.__tsCount = count; wrap.__tsNone = none;

        var prev = saved[keyFor(wrap)];
        if (prev) { input.value = prev; apply(wrap); }
    }

    function init() {
        queued = false;
        Array.prototype.forEach.call(document.querySelectorAll(SEL), build);
    }

    function schedule() {
        if (queued) return;
        queued = true;
        (window.requestAnimationFrame || setTimeout)(init);
    }

    // typing = filtering (event delegation, so it keeps working after the live-filter swaps the page body)
    document.addEventListener('input', function (e) {
        if (!e.target.classList || !e.target.classList.contains('ts-input')) return;
        var wrap = e.target.parentNode && e.target.parentNode.nextElementSibling;
        if (wrap && wrap.__tsInput === e.target) apply(wrap);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !e.target.classList || !e.target.classList.contains('ts-input') || !e.target.value) return;
        e.target.value = '';
        e.target.dispatchEvent(new Event('input', { bubbles: true }));
    });
    // Enter inside the box must not submit anything
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('ts-input')) e.preventDefault();
    });

    var css = document.createElement('style');
    css.textContent =
        '.ts-hide{display:none!important}' +
        '.ts-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 12px}' +
        '.ts-input{width:100%;max-width:340px;padding-left:32px!important;' +
        'background:#fff url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'14\' height=\'14\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%239CA3AF\' stroke-width=\'2.4\' stroke-linecap=\'round\'><circle cx=\'11\' cy=\'11\' r=\'7\'/><path d=\'m20 20-3.5-3.5\'/></svg>") no-repeat 11px center}' +
        '.ts-count{font-size:12px;color:#6B7280}' +
        '.ts-none{padding:18px;text-align:center;font-size:13px;color:#9CA3AF}' +
        '.ts-none[hidden]{display:none}';
    document.head.appendChild(css);

    function start() {
        init();
        if (window.MutationObserver) new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
