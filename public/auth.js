'use strict';
const magic = document.querySelector('#magic-login');
if (magic) {
  const token = location.hash.slice(1);
  history.replaceState(null, '', location.pathname);
  if (/^[a-f0-9]{64}$/.test(token)) magic.elements.token.value = token;
  else { magic.querySelector('button').disabled = true; magic.insertAdjacentText('beforebegin', 'Ссылка неполная. Запросите /login в боте заново.'); }
}
const waiting = document.querySelector('[data-telegram-wait]');
if (waiting) {
  const status = document.querySelector('#telegram-status');
  const deadline = Date.now() + 300000;
  const poll = async () => {
    if (Date.now() > deadline) { status.textContent = 'Запрос истёк. Начните вход заново.'; return; }
    try {
      const response = await fetch('/telegram/status', {credentials:'same-origin',cache:'no-store'});
      if (!response.ok) throw new Error('Unavailable');
      const data = await response.json();
      if (data.ready) { status.textContent = 'Подтверждено. Можно войти в кабинет.'; status.classList.add('success'); return; }
    } catch { status.textContent = 'Проверяем соединение. Можно попробовать кнопку входа вручную.'; }
    setTimeout(poll, 2500);
  };
  poll();
}
