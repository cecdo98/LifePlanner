(function () {
  const KEY = 'lp-theme';

  function getTheme() {
    const saved = localStorage.getItem(KEY);
    if (saved) return saved;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function applyTheme(theme) {
    const dark = theme === 'dark';
    document.documentElement.setAttribute('data-theme', theme);
    const btn = document.getElementById('theme-toggle');
    if (btn) btn.innerHTML = dark ? sunSVG() : moonSVG();
    if (typeof Chart !== 'undefined') updateCharts(dark);
  }

  function moonSVG() {
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
  }

  function sunSVG() {
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
  }

  function updateCharts(dark) {
    const color = dark ? '#8892a4' : '#6b7280';
    const gridColor = dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)';
    const tooltipBg = dark ? 'rgba(15,17,23,0.96)' : 'rgba(0,0,0,0.82)';
    Chart.defaults.color = color;
    Chart.defaults.plugins.tooltip.backgroundColor = tooltipBg;
    (window.__LP_CHARTS || []).forEach(function (chart) {
      try {
        ['x', 'y'].forEach(function (axis) {
          const s = chart.options.scales && chart.options.scales[axis];
          if (!s) return;
          if (s.grid) s.grid.color = gridColor;
          if (s.ticks) s.ticks.color = color;
        });
        if (chart.options.plugins && chart.options.plugins.legend && chart.options.plugins.legend.labels) {
          chart.options.plugins.legend.labels.color = color;
        }
        chart.update('none');
      } catch (e) {}
    });
  }

  // Apply theme immediately (before DOM ready) to avoid flash
  document.documentElement.setAttribute('data-theme', getTheme());

  document.addEventListener('DOMContentLoaded', function () {
    applyTheme(getTheme());

    var toggleBtn = document.getElementById('theme-toggle');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        localStorage.setItem(KEY, next);
        applyTheme(next);
      });
    }

    var hamburger = document.getElementById('nav-hamburger');
    var navLinks = document.getElementById('nav-links');
    if (hamburger && navLinks) {
      hamburger.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = navLinks.classList.toggle('open');
        hamburger.classList.toggle('open', open);
        hamburger.setAttribute('aria-expanded', String(open));
      });
      document.addEventListener('click', function (e) {
        if (!navLinks.contains(e.target) && e.target !== hamburger) {
          navLinks.classList.remove('open');
          hamburger.classList.remove('open');
          hamburger.setAttribute('aria-expanded', 'false');
        }
      });
    }
  });
})();
