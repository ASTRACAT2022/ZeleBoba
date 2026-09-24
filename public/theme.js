(() => {
  const key = 'zb-theme';
  const dark = window.matchMedia?.('(prefers-color-scheme: dark)');
  const read = () => {
    try { return localStorage.getItem(key); } catch (_) { return null; }
  };
  const preferred = () => read() || (dark?.matches ? 'dark' : 'light');
  const apply = (theme) => {
    document.documentElement.dataset.theme = theme;
    const button = document.querySelector('[data-theme-toggle]');
    if (!button) return;
    const isDark = theme === 'dark';
    button.setAttribute('aria-pressed', String(isDark));
    button.setAttribute('aria-label', isDark ? 'Включить светлую тему' : 'Включить тёмную тему');
    button.querySelector('[data-theme-icon]').textContent = isDark ? '☀' : '◐';
    button.querySelector('[data-theme-label]').textContent = isDark ? 'Светлая тема' : 'Тёмная тема';
  };
  apply(preferred());
  document.addEventListener('DOMContentLoaded', () => {
    apply(preferred());
    document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
      const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem(key, next); } catch (_) {}
      apply(next);
    });
  });
  dark?.addEventListener('change', () => { if (!read()) apply(preferred()); });
})();
