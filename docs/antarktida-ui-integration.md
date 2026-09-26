# AntarktidaUI в кабинете

Кабинет использует токены и Twig-компоненты ASTRACAT AntarktidaUI. Статические стили находятся в `public/design-system/`, Twig-компоненты и паттерны — в `design-system/`, а адаптация старых классов кабинета к семантическим токенам — в `public/portal-design.css`.

Twig loader регистрирует `@ui` и `@patterns` в `src/Web/Application.php`. В базовом шаблоне токены подключаются один раз. Для новых страниц используйте, например:

```twig
{% include '@ui/button.html.twig' with {
  label: 'Пополнить', type: 'submit', variant: 'action', size: 'md'
} only %}

{% include '@ui/metric.html.twig' with {
  label: 'Доступно на балансе', value: balance|rub,
  secondary: 'Средства для оплаты подписок'
} only %}
```

Селекты остаются нативными: системные токены задают поверхности и цветовую схему, а браузер сохраняет клавиатурное управление и доступность. Если страница использует custom select, подключайте `design-system/interactions.js` один раз и применяйте documented API из `design-system/docs/components.md`.
