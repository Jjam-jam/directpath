(function () {
  var button = document.getElementById('themeToggle');
  if (!button) return;

  function syncTheme(isDark) {
    document.documentElement.classList.toggle('dark-theme', isDark);
    button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    var label = isDark ? 'Switch to light theme' : 'Switch to dark theme';
    button.setAttribute('aria-label', label);
    button.title = label;
  }

  syncTheme(document.documentElement.classList.contains('dark-theme'));
  button.addEventListener('click', function () {
    var isDark = !document.documentElement.classList.contains('dark-theme');
    syncTheme(isDark);
    try { localStorage.setItem('dpTheme', isDark ? 'dark' : 'light'); } catch (e) {}
  });
})();
