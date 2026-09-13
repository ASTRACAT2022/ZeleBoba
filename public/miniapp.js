(() => {
  const tg = window.Telegram?.WebApp;
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const haptic = (kind) => {
    try {
      const h = tg?.HapticFeedback;
      if (!h) return;
      if (kind === 'success' || kind === 'warning' || kind === 'error') h.notificationOccurred(kind);
      else h.impactOccurred(kind || 'light');
    } catch (_) {}
  };

  // ===== boot =====
  if (tg) {
    try {
      tg.ready();
      tg.expand();
      tg.setHeaderColor?.(tg.themeParams.bg_color || '');
      tg.setBackgroundColor?.(tg.themeParams.bg_color || '');
    } catch (_) {}
  }

  $$('[data-fullscreen]').forEach((el) => el.addEventListener('click', () => {
    haptic('light');
    try { tg?.requestFullscreen?.(); } catch (_) {}
    try { tg?.expand?.(); } catch (_) {}
  }));

  // ===== authed =====
  if ($('body')?.dataset.authenticated === '1') {
    if (tg) setTimeout(() => { try { tg.requestFullscreen?.(); } catch (_) {} }, 120);

    // Haptic on every [data-haptic]
    $$('[data-haptic]').forEach((el) => el.addEventListener('click', () => haptic(el.dataset.haptic)));

    // Bottom-tab: smooth scroll for in-page anchors; native nav (stays in WebView) for routes.
    $$('.tab').forEach((tab) => {
      tab.addEventListener('click', (e) => {
        const target = tab.getAttribute('href') || '';
        // Routes already work via href — just trigger haptic + let default navigation happen.
        if (target.startsWith('/miniapp/')) {
          haptic('light');
          // allow default <a> navigation (location change inside WebView)
          return;
        }
        // Legacy: in-page hash anchors
        if (target.startsWith('#')) {
          e.preventDefault();
          haptic('light');
          const dest = target === '#' || target === '#home' ? null : $(target);
          if (dest) dest.scrollIntoView({ behavior: 'smooth', block: 'start' });
          else window.scrollTo({ top: 0, behavior: 'smooth' });
        }
      });
    });

    // Copy-link helper for referral page
    $$('.copy-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const text = btn.dataset.copy || '';
        if (!text) return;
        try {
          await navigator.clipboard.writeText(text);
          haptic('success');
          const orig = btn.textContent;
          btn.textContent = '✓ Скопировано';
          setTimeout(() => { btn.textContent = orig; }, 1600);
        } catch (_) {
          // Fallback: select + exec
          const ta = document.createElement('textarea');
          ta.value = text;
          ta.style.position = 'fixed';
          ta.style.opacity = '0';
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); haptic('success'); } catch (_) {}
          ta.remove();
        }
      });
    });

    // Hide Telegram BackButton on main pages — we have our own tabbar
    try { tg?.BackButton?.hide?.(); } catch (_) {}
    return;
  }

  // ===== anon: initData auth =====
  const status = $('#boot-status');
  const fallback = $('#boot-fallback');
  const setStatus = (text) => { if (status) status.textContent = text; };

  const initData = tg?.initData;
  if (!initData) {
    setStatus('Откройте Mini App кнопкой в Telegram.');
    return;
  }

  fetch('/miniapp/auth', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ init_data: initData }),
    credentials: 'same-origin',
  })
    .then(async (r) => ({ ok: r.ok, data: await r.json().catch(() => ({})) }))
    .then(({ ok, data }) => {
      if (!ok || !data.ok) throw new Error(data.error || 'Не удалось войти');
      haptic('success');
      location.replace('/miniapp/home');
    })
    .catch((err) => {
      setStatus(err.message || 'Не удалось открыть приложение. Повторите попытку.');
      haptic('error');
      if (fallback) fallback.style.display = 'inline-block';
    });
})();
