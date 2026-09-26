(() => {
  const root = document.documentElement;
  const themeButton = document.querySelector('.theme-toggle');
  const themeLabel = themeButton?.querySelector('.theme-label');
  let storedTheme = null;
  try { storedTheme = localStorage.getItem('antarktida-ui-theme'); } catch (_) { /* Theme still works when storage is unavailable. */ }

  if (storedTheme === 'dark') root.dataset.theme = 'dark';
  if (themeButton) {
    const syncThemeControl = () => {
      const isDark = root.dataset.theme === 'dark';
      themeButton.setAttribute('aria-pressed', String(isDark));
      themeButton.setAttribute('aria-label', isDark ? 'Включить светлую тему' : 'Включить тёмную тему');
      if (themeLabel) themeLabel.textContent = isDark ? 'Светлая тема' : 'Тёмная тема';
    };
    syncThemeControl();
    themeButton.addEventListener('click', () => {
      root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('antarktida-ui-theme', root.dataset.theme); } catch (_) { /* Keep the current page usable without storage. */ }
      syncThemeControl();
    });
  }

  const tabs = [...document.querySelectorAll('[role="tab"]')];
  const feedback = document.querySelector('.tab-feedback');
  const selectTab = (tab) => {
    tabs.forEach((item) => {
      const selected = item === tab;
      item.setAttribute('aria-selected', String(selected));
      item.classList.toggle('is-selected', selected);
      item.tabIndex = selected ? 0 : -1;
    });
    if (feedback) feedback.textContent = `Показан период: ${tab.textContent.trim()}`;
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => selectTab(tab));
    tab.addEventListener('keydown', (event) => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
      tabs[next].focus();
      selectTab(tabs[next]);
    });
  });

  const selectControls = [...document.querySelectorAll('.select-control')];
  selectControls.forEach((control) => {
    const trigger = control.querySelector('[role="combobox"]');
    const menu = control.querySelector('[role="listbox"]');
    const options = [...control.querySelectorAll('[role="option"]')];
    const valueLabel = control.querySelector('.select-value');
    const valueInput = control.querySelector('input[type="hidden"]');
    if (!trigger || !menu || !options.length) return;

    const selectedIndex = () => Math.max(0, options.findIndex((option) => option.getAttribute('aria-selected') === 'true'));
    const focusOption = (index) => options[(index + options.length) % options.length].focus();
    const close = (restoreFocus = false) => {
      menu.hidden = true;
      control.classList.remove('is-open');
      trigger.setAttribute('aria-expanded', 'false');
      if (restoreFocus) trigger.focus();
    };
    const open = () => {
      menu.hidden = false;
      control.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
      focusOption(selectedIndex());
    };
    const choose = (option) => {
      options.forEach((item) => {
        const selected = item === option;
        item.setAttribute('aria-selected', String(selected));
        item.classList.toggle('is-selected', selected);
      });
      if (valueLabel) valueLabel.textContent = option.textContent.trim();
      if (valueInput) valueInput.value = option.dataset.value ?? option.textContent.trim();
      close(true);
    };

    trigger.addEventListener('click', () => menu.hidden ? open() : close());
    trigger.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (menu.hidden) open();
        else focusOption(selectedIndex() + (event.key === 'ArrowDown' ? 1 : -1));
      } else if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        if (menu.hidden) open();
        else choose(options[selectedIndex()]);
      } else if (event.key === 'Escape' && !menu.hidden) {
        event.preventDefault();
        close();
      }
    });
    options.forEach((option, index) => {
      option.addEventListener('click', () => choose(option));
      option.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          focusOption(index + (event.key === 'ArrowDown' ? 1 : -1));
        } else if (event.key === 'Home' || event.key === 'End') {
          event.preventDefault();
          focusOption(event.key === 'Home' ? 0 : options.length - 1);
        } else if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          choose(option);
        } else if (event.key === 'Escape') {
          event.preventDefault();
          close(true);
        } else if (event.key === 'Tab') {
          close();
        }
      });
    });
  });
  document.addEventListener('click', (event) => {
    selectControls.forEach((control) => {
      if (!control.contains(event.target)) {
        const menu = control.querySelector('[role="listbox"]');
        const trigger = control.querySelector('[role="combobox"]');
        if (menu && trigger && !menu.hidden) {
          menu.hidden = true;
          control.classList.remove('is-open');
          trigger.setAttribute('aria-expanded', 'false');
        }
      }
    });
  });

  const notice = document.querySelector('.notice');
  notice?.querySelector('button')?.addEventListener('click', () => notice.remove());

  const links = [...document.querySelectorAll('.nav-link')];
  const sections = links.map((link) => document.querySelector(link.getAttribute('href'))).filter(Boolean);
  const observer = new IntersectionObserver((entries) => {
    const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
    if (!visible) return;
    links.forEach((link) => {
      const active = link.hash === `#${visible.target.id}`;
      link.classList.toggle('is-active', active);
      if (active) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
  }, { rootMargin: '-22% 0px -68% 0px', threshold: [0, .1, .5] });
  sections.forEach((section) => observer.observe(section));
})();
