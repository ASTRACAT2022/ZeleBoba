(() => {
  const items = [
    ['Операции', '/admin/operations'], ['Контроль системы', '/admin/intelligence'],
    ['Выдача VPN', '/admin/provisioning'], ['Переключатели', '/admin/flags'],
    ['Инциденты', '/admin/incidents'], ['Клиенты', '/admin/users'], ['Тарифы', '/admin/plans'],
    ['Мониторинг', '/admin/monitoring'], ['Журнал действий', '/admin/audit']
  ];
  const dialog = document.createElement('dialog');
  dialog.className = 'command-palette';
  dialog.innerHTML = '<form method="dialog"><input autofocus placeholder="Найти пользователя, платёж, подписку или раздел…" aria-label="Быстрый поиск"><div class="command-results"></div><p>Esc — закрыть · Enter — открыть</p></form>';
  document.addEventListener('DOMContentLoaded', () => document.body.append(dialog));
  const input = () => dialog.querySelector('input'); const results = () => dialog.querySelector('.command-results');
  function render() { const q=input().value.toLowerCase().trim(); const found=items.filter(([name])=>name.toLowerCase().includes(q)); results().innerHTML=found.map(([name,url],i)=>`<a data-url="${url}" class="${i===0?'selected':''}" href="${url}">${name}<span>↵</span></a>`).join('') || '<span class="muted">Попробуйте другой запрос</span>'; }
  document.addEventListener('keydown', e => { if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase()==='k') { e.preventDefault(); dialog.showModal(); setTimeout(()=>input().focus(),0); render(); } else if (e.key==='Enter' && dialog.open) { const choice=results().querySelector('a.selected'); if(choice){ e.preventDefault(); location.href=choice.dataset.url; } } });
  dialog.addEventListener('input', render); dialog.addEventListener('click', e=>{ if(e.target===dialog)dialog.close(); });
})();
