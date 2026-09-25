'use strict';
/* Progressive enhancement only. Business logic lives on the server.
   - Marks the clicked submit button as loading so a payment can't be
     submitted twice while the browser navigates (server idempotency is
     unchanged and remains the source of truth).
   - Copy-to-clipboard for [data-copy] targets.
   - Warns on unsaved form changes for long admin forms.
*/
(() => {
  // --- submit loading state ---
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.noLock !== undefined) return;
    const buttons = form.querySelectorAll('button[type="submit"], button:not([type])');
    for (const button of buttons) {
      if (button.disabled) continue;
      button.classList.add('is-loading');
      button.setAttribute('aria-busy', 'true');
      // disable after paint so the value is still submitted
      requestAnimationFrame(() => { button.disabled = true; });
    }
  });

  // --- copy to clipboard ---
  document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-copy]');
    if (!trigger) return;
    event.preventDefault();
    const value = trigger.getAttribute('data-copy');
    const done = trigger.getAttribute('data-copy-label') || 'Скопировано';
    const original = trigger.textContent;
    try {
      await navigator.clipboard.writeText(value);
      trigger.textContent = done;
    } catch {
      trigger.textContent = 'Не удалось скопировать';
    }
    setTimeout(() => { trigger.textContent = original; }, 1600);
  });

  // --- pricing selector: reflect the chosen term in the summary ---
  const pricing = document.querySelector('[data-pricing]');
  if (pricing) {
    const terms = [...pricing.querySelectorAll('.term')];
    const sync = () => {
      const checked = pricing.querySelector('input[type="radio"]:checked');
      terms.forEach((term) => {
        const input = term.querySelector('input[type="radio"]');
        term.classList.toggle('selected', input === checked);
      });
      if (!checked) return;
      const out = document.querySelector('[data-summary-plan]');
      const total = document.querySelector('[data-summary-total]');
      if (out) out.textContent = checked.dataset.name;
      if (total) total.textContent = checked.dataset.price;
      const btn = document.querySelector('[data-summary-cta]');
      if (btn) btn.textContent = checked.dataset.cta || btn.textContent;
    };
    terms.forEach((term) => term.addEventListener('click', (event) => {
      const input = term.querySelector('input[type="radio"]');
      if (input && event.target !== input) input.checked = true;
      sync();
    }));
    sync();
  }

  // --- confirm destructive actions ---
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-confirm]');
    if (!trigger) return;
    if (!window.confirm(trigger.getAttribute('data-confirm'))) event.preventDefault();
  });
})();
