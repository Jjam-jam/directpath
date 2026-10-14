/* Shared Chart.js theme. Updates canvas content, not just surrounding CSS. */
(function () {
  if (typeof Chart === 'undefined') return;
  var originals = new WeakMap();
  function token(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
  function palette() { return Array.from({length: 8}, function (_, i) { return token('--dp-chart-' + (i + 1)); }); }
  window.dpChartTheme = { token: token, palette: palette };
  Chart.defaults.font.family = "'Poppins', sans-serif";
  Chart.defaults.font.size = 11;
  Chart.defaults.plugins.legend.position = 'bottom';
  Object.assign(Chart.defaults.plugins.legend.labels, {usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 14});
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 8;
  Chart.defaults.plugins.tooltip.titleFont = {weight: '600'};
  Chart.defaults.animation.duration = 600;
  if (Chart.overrides.doughnut) Chart.overrides.doughnut.cutout = '70%';
  var keys = ['backgroundColor', 'borderColor', 'pointBackgroundColor', 'pointBorderColor'];
  function apply(chart) {
    var dark = document.documentElement.classList.contains('dark-theme');
    var o = chart.config.options || (chart.config.options = {}), colors = palette();
    o.color = token('--dp-chart-muted');
    var plugins = o.plugins || (o.plugins = {});
    if (plugins.legend !== false) {
      var legend = plugins.legend || (plugins.legend = {});
      var labels = legend.labels || (legend.labels = {}); labels.color = token('--dp-chart-muted');
    }
    if (plugins.tooltip !== false) {
      var tip = plugins.tooltip || (plugins.tooltip = {});
      Object.assign(tip, {backgroundColor: token('--dp-tooltip-bg'), titleColor: token('--dp-tooltip-text'), bodyColor: token('--dp-tooltip-text'), footerColor: token('--dp-tooltip-text')});
    }
    Object.keys(o.scales || {}).forEach(function (key) {
      var scale = o.scales[key];
      scale.ticks = Object.assign({}, scale.ticks || {}, {color: token('--dp-chart-muted')});
      scale.grid = Object.assign({}, scale.grid || {}, {color: token('--dp-chart-grid')});
      scale.border = Object.assign({}, scale.border || {}, {color: token('--dp-chart-grid')});
      scale.title = Object.assign({}, scale.title || {}, {color: token('--dp-chart-text')});
      if (scale.pointLabels) scale.pointLabels.color = token('--dp-chart-muted');
      if (scale.angleLines) scale.angleLines.color = token('--dp-chart-grid');
    });
    (chart.data.datasets || []).forEach(function (d, i) {
      if (!originals.has(d)) {
        var saved = {}; keys.forEach(function (k) { saved[k] = Array.isArray(d[k]) ? d[k].slice() : d[k]; }); originals.set(d, saved);
      }
      var saved = originals.get(d), type = d.type || chart.config.type;
      if (chart.canvas.dataset.keepColors !== undefined) return; // explicit opt-out only affects data colors
      if (!dark) { keys.forEach(function (k) { if (saved[k] === undefined) delete d[k]; else d[k] = saved[k]; }); return; }
      var color = colors[i % colors.length];
      if (type === 'doughnut' || type === 'pie' || type === 'polarArea') {
        d.backgroundColor = (d.data || []).map(function (_, k) { return colors[k % colors.length]; });
        d.borderColor = token('--card-bg');
      } else {
        d.borderColor = color; d.pointBackgroundColor = color; d.pointBorderColor = color;
        d.backgroundColor = type === 'line' || type === 'radar' ? color + '26' : color;
      }
    });
  }
  Chart.register({id: 'dpTheme', beforeInit: apply, beforeUpdate: function (chart) { apply(chart); chart.config.clearCache(); }});
  function refresh() {
    Chart.defaults.color = token('--dp-chart-muted');
    Object.keys(Chart.instances).forEach(function (id) { var chart = Chart.instances[id]; apply(chart); chart.data.datasets = chart.data.datasets.map(function (d) { var copy = Object.assign({}, d); if (originals.has(d)) originals.set(copy, originals.get(d)); return copy; }); chart.stop(); chart.update('none'); });
  }
  new MutationObserver(refresh).observe(document.documentElement, {attributes: true, attributeFilter: ['class']});
  refresh();
})();
