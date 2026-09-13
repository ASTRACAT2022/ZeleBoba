(() => {
  const tg = window.Telegram?.WebApp;
  const body = document.body;
  const setStatus = (text) => { const node = document.querySelector('#mini-status'); if (node) node.textContent = text; };
  const fullscreen = () => {
    try { tg?.requestFullscreen?.(); } catch (_) {}
    try { tg?.expand(); } catch (_) {}
  };
  if (tg) {
    tg.ready();
    tg.expand();
    document.documentElement.style.setProperty('--tg-bg', tg.themeParams.bg_color || '');
    document.documentElement.style.setProperty('--tg-text', tg.themeParams.text_color || '');
  }
  document.querySelector('[data-fullscreen]')?.addEventListener('click', fullscreen);
  if (body.dataset.authenticated === '1') {
    setTimeout(fullscreen, 120);
    return;
  }
  const initData = tg?.initData;
  if (!initData) { setStatus('Откройте Mini App кнопкой в Telegram.'); return; }
  fetch('/miniapp/auth', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({init_data: initData}), credentials: 'same-origin' })
    .then(async response => ({ok: response.ok, data: await response.json()}))
    .then(({ok, data}) => { if (!ok || !data.ok) throw new Error(data.error || 'Не удалось войти'); location.replace('/miniapp'); })
    .catch(error => setStatus(error.message || 'Не удалось открыть приложение. Повторите попытку.'));
})();
