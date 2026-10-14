/* Fits documents to the screen / printable area.
   - .cl-page, .payslip, .a4-sheet (non-report) : scaled down on narrow screens (screen only; print uses 100%).
   - .rpt-fluid (report modules): fluid width on screen (no horizontal bar); before printing, anything wider
     than the printable width is scaled down so every column fits the page. */
(function () {
  var sel = '.cl-page, .payslip, .a4-sheet';
  function isReport(s) { return !!(s.closest && s.closest('.rpt-fluid')); }

  function fit() {
    document.querySelectorAll(sel).forEach(function (s) {
      if (isReport(s)) { s.style.zoom = 1; return; }
      s.style.zoom = 1;
      var box = s.parentElement ? s.parentElement.clientWidth : window.innerWidth;
      var z = Math.min(1, (box - 16) / s.offsetWidth);
      s.style.zoom = z > 0 ? z : 1;
    });
  }

  // Printable width of an A4 LANDSCAPE page with 8mm margins (CSS px at 96dpi): (297 - 16) mm.
  var PRINT_W = (297 - 16) * 96 / 25.4;
  var PAD = 2 * (3 * 96 / 25.4);             // sheet padding (3mm each side) in print

  function fitForPrint() {
    document.querySelectorAll('.rpt-fluid .a4-sheet').forEach(function (s) {
      s.style.zoom = 1;
      s.style.width = PRINT_W + 'px';
      var avail = PRINT_W - PAD, need = 0;
      // Narrowest width each table can be squeezed to (text wraps); only scale if even that is too wide.
      s.querySelectorAll('table').forEach(function (t) {
        var old = t.style.width;
        t.style.width = '1px';
        need = Math.max(need, t.offsetWidth);
        t.style.width = old;
      });
      if (need > avail + 1) {
        var z = avail / need;
        s.style.setProperty('--print-zoom', z.toFixed(4));
        s.style.width = (PRINT_W / z) + 'px';
      } else {
        s.style.setProperty('--print-zoom', 1);
        s.style.width = '';
      }
    });
  }

  function resetAfterPrint() {
    document.querySelectorAll('.rpt-fluid .a4-sheet').forEach(function (s) { s.style.zoom = 1; s.style.width = ''; s.style.removeProperty('--print-zoom'); });
    fit();
  }

  window.addEventListener('load', fit);
  window.addEventListener('resize', fit);
  window.addEventListener('beforeprint', fitForPrint);
  window.addEventListener('afterprint', resetAfterPrint);

  // Chrome/Edge fire matchMedia('print') reliably too.
  if (window.matchMedia) {
    var mq = window.matchMedia('print');
    var h = function (e) { if (e.matches) fitForPrint(); else resetAfterPrint(); };
    if (mq.addEventListener) mq.addEventListener('change', h); else if (mq.addListener) mq.addListener(h);
  }
})();
