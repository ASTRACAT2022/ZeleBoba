# ASTRACAT Telegram UI prototype

Это офлайн-прототип presentation layer. Он использует демонстрационные значения и не подключён к базе данных, платежам или Telegram API.

Откройте `index.html` в браузере. Слева можно переключать 22 состояния интерфейса; кнопки в макете показывают предполагаемую inline-навигацию без создания новых сообщений. Кнопка оплаты имитирует результат и не выполняет платёж.

Структуры Rich Message генерируются PHP builders из `src/TelegramUI`. Этот макет остаётся офлайн-демо; реальные кнопки и статусы в боте используют backend-данные. Чтобы обновить файлы-примеры после правок:

```sh
php scripts/telegram-ui-prototype.php > docs/prototypes/telegram-ui/screens.json
php scripts/telegram-ui-prototype.php --js > docs/prototypes/telegram-ui/screens.js
```

`screens.json` содержит `InputRichMessage` payload для каждого экрана. Интеграция отправки и callback-навигации находится в backend; фактическая доставка зависит от поддержки Rich Messages используемым Telegram API endpoint.
