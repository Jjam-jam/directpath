/* ============================================================
   Directpath HRMS - live search + live filters
   Any <form method="GET" class="filter-bar"> (or form[data-live]) updates its results
   as you type / change a field, with no Filter button and no page reload.
   The same URL is requested in the background and only the results area is swapped,
   so search still runs against the whole database (not only the rows already on screen).
   ============================================================ */
(function () {
    'use strict';
    var SEL = 'form.filter-bar[method], form[data-live]';
    var STATIC = 'script, style, link, .modal-overlay';
    var timer = null, seq = 0, ctrl = null;

    function isLive(f) {
        return f && f.matches && f.matches(SEL) && String(f.method).toLowerCase() === 'get' && !f.hasAttribute('data-no-live');
    }

    // Filter / Apply buttons are not needed any more
    function prepare(root) {
        (root || document).querySelectorAll(SEL).forEach(function (f) {
            if (!isLive(f) || f.__liveReady) return;
            f.__liveReady = true;
            f.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]').forEach(function (b) { b.style.display = 'none'; });
            f.querySelectorAll('select[onchange]').forEach(function (s) { s.removeAttribute('onchange'); });
        });
    }

    function urlFor(form) {
        var p = new URLSearchParams(new FormData(form)), base = form.getAttribute('action') || location.pathname;
        var qs = p.toString();
        return base.split('?')[0] + (qs ? '?' + qs : '');
    }

    function swap(doc, form, focus) {
        var cur = document.querySelector('.page-body'), nxt = doc.querySelector('.page-body');
        if (!cur || !nxt) return false;
        var curDyn = Array.prototype.filter.call(cur.children, function (n) { return !n.matches(STATIC); });
        var nxtDyn = Array.prototype.filter.call(nxt.children, function (n) { return !n.matches(STATIC); });
        if (!curDyn.length) return false;
        var anchor = curDyn[0];
        nxtDyn.forEach(function (n) { cur.insertBefore(document.importNode(n, true), anchor); });
        curDyn.forEach(function (n) { n.remove(); });
        prepare(cur);
        // keep what the person is typing exactly as typed, and keep the cursor where it was
        if (focus && focus.name) {
            var forms = Array.prototype.filter.call(cur.querySelectorAll(SEL), isLive);
            var idx = focus.formIndex, tf = forms[idx] || forms[0];
            if (tf) {
                var el = tf.elements[focus.name];
                if (el && el.nodeType === 1) {
                    if (focus.text) el.value = focus.value;
                    el.focus();
                    try { if (focus.text && focus.start != null) el.setSelectionRange(focus.start, focus.end); } catch (e) {}
                }
            }
        }
        return true;
    }

    function run(form, opts) {
        opts = opts || {};
        var url = opts.url || urlFor(form), mine = ++seq;
        var all = Array.prototype.filter.call(document.querySelectorAll(SEL), isLive);
        var a = document.activeElement, focus = null;
        if (a && form.contains(a) && a.name) {
            var text = /^(text|search|email|number|tel|url)$/i.test(a.type || '');
            focus = { name: a.name, formIndex: all.indexOf(form), text: text, value: a.value, start: text ? a.selectionStart : null, end: text ? a.selectionEnd : null };
        }
        if (ctrl) ctrl.abort();
        ctrl = window.AbortController ? new AbortController() : null;
        var body = document.querySelector('.page-body');
        if (body) body.classList.add('live-loading');
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'live-filter' }, signal: ctrl ? ctrl.signal : undefined })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(function (html) {
                if (mine !== seq) return;                    // a newer request replaced this one
                var doc = new DOMParser().parseFromString(html, 'text/html');
                if (!swap(doc, form, focus)) { location.href = url; return; }
                try { history.replaceState(null, '', url); } catch (e) {}
                var b = document.querySelector('.page-body'); if (b) b.classList.remove('live-loading');
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                location.href = url;                          // fall back to a normal page load
            });
    }

    function later(form, ms) { clearTimeout(timer); timer = setTimeout(function () { run(form); }, ms); }

    document.addEventListener('input', function (e) {
        var f = e.target.form;
        if (!isLive(f) || !/^(text|search|email|number|tel|url)$/i.test(e.target.type || '')) return;
        later(f, 220);                                         // search as you type
    });
    document.addEventListener('change', function (e) {
        var f = e.target.form;
        if (!isLive(f)) return;
        if (/^(text|search|email|number|tel|url)$/i.test(e.target.type || '')) return;   // handled by 'input'
        clearTimeout(timer); run(f);                           // selects, dates, months, checkboxes: instant
    });
    document.addEventListener('submit', function (e) {          // Enter key
        if (!isLive(e.target)) return;
        e.preventDefault(); clearTimeout(timer); run(e.target);
    });
    document.addEventListener('click', function (e) {           // "Reset" link inside a live form
        var a = e.target.closest && e.target.closest('a.btn-secondary');
        var f = a && a.closest(SEL);
        if (!a || !isLive(f) || !a.getAttribute('href') || a.hasAttribute('download') || /export=/.test(a.href)) return;
        var u = new URL(a.href, location.href);
        if (u.pathname !== location.pathname) return;
        e.preventDefault();
        fetch(u.href, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            if (!swap(doc, f, null)) { location.href = u.href; return; }
            try { history.replaceState(null, '', u.href); } catch (er) {}
        }).catch(function () { location.href = u.href; });
    });

    var css = document.createElement('style');
    css.textContent = '.page-body.live-loading .table-wrapper,.page-body.live-loading .tab-bar{opacity:.55;transition:opacity .15s}';
    document.head.appendChild(css);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { prepare(); }); else prepare();
})();
