/* Builds the dashboard charts: one palette, compact fonts, always fits its card. */
(function () {
  var pal = ['#CD1E27', '#1A1D26', '#6B7280', '#E8888D', '#9CA3AF', '#A8181F', '#D1D5DB', '#F4B6BA'];
  function money(v) { v = +v; if (v >= 1e6) return '₱' + (v / 1e6).toFixed(1) + 'M'; if (v >= 1e3) return '₱' + Math.round(v / 1e3) + 'K'; return '₱' + v; }

  window.dpDash = function (list) {
    if (typeof Chart === 'undefined') return;
    Chart.defaults.font.family = "'Poppins', sans-serif";
    Chart.defaults.font.size = 10;
    list.forEach(function (c) {
      var el = document.getElementById(c.id); if (!el) return;
      var isDonut = c.type === 'doughnut';
      var datasets = c.datasets.map(function (d, i) {
        var col = d.color || pal[i % pal.length];
        if (isDonut) return { data: d.data, backgroundColor: c.labels.map(function (_, k) { return pal[k % pal.length]; }), borderWidth: 0, hoverOffset: 3 };
        if (c.type === 'line') return { label: d.label, data: d.data, borderColor: col, backgroundColor: col + '14', fill: true, tension: .35, borderWidth: 2, pointRadius: 2, pointHoverRadius: 4 };
        return { label: d.label, data: d.data, backgroundColor: col, borderRadius: 5, borderSkipped: false, maxBarThickness: c.thick || 18 };
      });
      var opts = { responsive: true, maintainAspectRatio: false, animation: { duration: 500 },
        plugins: { legend: { display: !isDonut && datasets.length > 1, position: 'top', align: 'end', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 6, boxHeight: 6, padding: 10, font: { size: 10 } } },
                   tooltip: { backgroundColor: '#1A1D26', padding: 8, cornerRadius: 8, titleFont: { size: 11 }, bodyFont: { size: 11 },
                              callbacks: c.money ? { label: function (x) { return ' ' + (x.dataset.label || x.label) + ': ₱' + Number(x.parsed.y != null ? x.parsed.y : x.parsed).toLocaleString(); } } : {} } } };
      if (isDonut) { opts.cutout = '72%'; }
      else {
        opts.scales = { x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 10 } } },
                        y: { beginAtZero: true, grid: { color: '#F3F4F6' }, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5, font: { size: 10 }, callback: c.money ? money : undefined } } };
      }
      var plugins = [];
      if (isDonut && c.center) {
        plugins.push({ id: 'ctr', afterDraw: function (ch) {
          var a = ch.chartArea, x = (a.left + a.right) / 2, y = (a.top + a.bottom) / 2, g = ch.ctx;
          g.save(); g.textAlign = 'center'; g.textBaseline = 'middle';
          g.fillStyle = window.dpChartTheme ? window.dpChartTheme.token('--dp-chart-text') : '#1A1D26'; g.font = '700 18px Poppins, sans-serif'; g.fillText(c.center[0], x, y - 6);
          g.fillStyle = window.dpChartTheme ? window.dpChartTheme.token('--dp-chart-muted') : '#6B7280'; g.font = '400 9px Poppins, sans-serif'; g.fillText(c.center[1], x, y + 10); g.restore();
        } });
      }
      new Chart(el, { type: c.type, data: { labels: c.labels, datasets: datasets }, options: opts, plugins: plugins });
    });
  };
})();
