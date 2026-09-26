# Компоненты AntarktidaUI

Twig namespace: `@ui` → `design-system/components/`. Набор пока является foundation-preview; примеры ниже задают API для первого этапа.

## Button

```twig
{% include '@ui/button.html.twig' with {
  label: 'Продолжить',
  variant: 'action',
  size: 'md'
} only %}
```

Варианты: `action`, `neutral`, `quiet`, `positive`, `critical`. Размеры: `sm`, `md`, `lg`; `icon` и `ariaLabel` необязательны, а для кнопки только с иконкой передайте оба значения. Состояние загрузки добавляется через `aria-busy="true"` и индикатор, не заменяющий доступное имя.

## Status

```twig
{% include '@ui/status.html.twig' with {
  status: 'healthy',
  label: 'Все системы работают'
} only %}
```

Состояния: `healthy`, `degraded`, `maintenance`, `offline`, `unknown`. Цвет всегда сопровождается текстовой меткой.

## Metric и Metric Rail

```twig
{% include '@ui/metric.html.twig' with {
  label: 'Трафик', value: '412 GB', secondary: 'из 650 GB'
} only %}
```

Значения форматируются на стороне вызывающего кода, чтобы приложение сохраняло контроль над локалью и точностью чисел.

## Input

```twig
{% include '@ui/input.html.twig' with {
  id: 'account-email', label: 'Рабочая почта', type: 'email',
  value: user.email, hint: 'Адрес для уведомлений.'
} only %}
```

Ошибка связывается с полем через `aria-describedby` и `aria-invalid`.

## Select

```twig
{% include '@ui/select.html.twig' with {
  id: 'connection-region', name: 'region', label: 'Регион подключения',
  value: 'nl', options: [
    {value: 'nl', label: 'Нидерланды · Амстердам'},
    {value: 'fi', label: 'Финляндия · Хельсинки'}
  ]
} only %}
```

Меню использует theme-aware raised surface, управляется мышью и клавиатурой (стрелки, Home/End, Enter, Escape) и сохраняет выбранное значение в hidden input.

## Signal

```twig
{% include '@ui/signal.html.twig' with {
  status: 'healthy', location: 'Амстердам, NL', latency: '31',
  protocol: 'WIREGUARD', actionUrl: path('connection_settings')
} only %}
```

Фирменная поверхность соединения показывает состояние, точку выхода и задержку в одной последовательности.

## Service Pulse

```twig
{% include '@patterns/service-pulse.html.twig' with {
  services: [
    {name: 'VPN', status: 'healthy'},
    {name: 'DNS', status: 'healthy'},
    {name: 'Payments', status: 'maintenance'}
  ]
} only %}
```

## Состояния и доступность

- Интерактивные элементы имеют видимый `:focus-visible`.
- Поля всегда связаны с `<label>`; ошибки должны ссылаться через `aria-describedby`.
- Статус передаётся текстом, а не одним цветом или анимацией.
- Переходы короткие и отключаются при `prefers-reduced-motion`.
- В demo включены переключение темы, табы с клавиатурой, закрытие уведомления и якорная навигация.
