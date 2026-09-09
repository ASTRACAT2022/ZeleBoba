# Объём версии 0.5 — FreeKassa + Telegram-дублер + автопродление

Реализован самостоятельный продукт для покупки и автопродления подписок. Это не полный функциональный клон [Bedolaga](https://github.com/BEDOLAGA-DEV/remnawave-bedolaga-telegram-bot).

| Область | Реализовано | Не реализовано |
|---|---|---|
| Веб | Регистрация, пароль, Telegram-вход, кабинет, заказы, подписки, автопродление (вкл/выкл на карточке) | Email verification/recovery, OAuth |
| Telegram | Бот-дублер кабинета через зеркало `astracattg.netlify.app`: /start, /help, /plans, /buy, /status, /orders, /subs, /cabinet, /support, /link + deep-link вход, inline-кнопки (тарифы/покупка/оплата/обновление), answerCallbackQuery, тандем с вебом (общие заказы/подписки) | Автослияние существующих аккаунтов, маркетинговые рассылки |
| Защита | TOTP/recovery, admin step-up, CSRF, серверные сессии, шифрование настроек | Гранулярный RBAC сверх customer/admin |
| Тарифы | Создание, изменение, скрытие, snapshots, цена/дни/устройства/трафик/squad | Пакеты доптрафика, сложная смена тарифа |
| Оплата | FreeKassa (API: создание заказа, подпись HMAC, вебхук MD5 + IP whitelist, сверка) + ЮKassa + demo, идемпотентность, webhook/API-сверка, параметры чека | Автоматические возвраты и их учёт, нативные рекурренты FreeKassa (используем свои renewal-заказы), крипта через SCI |
| Подписки | Выдача через Remnawave, повтор после сбоя, сроки, автопродление тем же тарифом (renewal-заказ + extend в панели) | Trial, подарки, смена тарифа при продлении |
| Админка | Настройки сервиса/интеграций, тарифы, клиенты, очередь, аудит, readiness | Финансовые отчёты, кошелёк, промокоды, рефералы |
| Эксплуатация | Docker, роли БД, health/heartbeat, backup/restore procedure, CI | Установка внешнего мониторинга, offsite/PITR инфраструктура, измеренные SLO |

Боевой запуск версии 0.5 допустимо оценивать только для перечисленного реализованного сценария, с утверждённым ручным процессом возвратов/компенсаций и после live-проверок конкретных интеграций. Ни прохождение unit-тестов, ни заполненные поля настройки сами по себе не подтверждают готовность принимать деньги.

## FreeKassa: что настроено

- Приём через API `https://api.fk.life/v1/orders/create` (не SCI). Ссылка из поля `location` отдаётся клиенту.
- Email: реальный email клиента или `<tgid>@telegram.org`. IP: реальный IP клиента (за прокси берётся из `X-Real-IP` / `X-Forwarded-For`); `127.0.0.1` блокируется FreeKassa, поэтому есть fallback.
- Способы: `i=44` — СБП QR, `i=36` — карты РФ (настраивается в админке `FREEKASSA_PAYMENT_ID`).
- Вебхук `POST/GET /webhooks/freekassa` (form-data): проверка `SIGN = md5(MERCHANT_ID:AMOUNT:SECRET2:MERCHANT_ORDER_ID)`, IP whitelist `168.119.157.136, 168.119.60.227, 178.154.197.79, 51.250.54.238`, ответ `YES`. Статус подтверждается через API `orders` перед зачислением.
- Вывод денег: автовывод FreeKassa → FKWallet в кабинете merchant, далее обмен RUB → USDT и вывод в кабинете fkwallet.io. Код выплат FKWallet в биллинг не встраивался.

## Telegram: бот-дублер кабинета

- Все Bot API вызовы идут через зеркало `https://astracattg.netlify.app` (настраивается `TELEGRAM_API_BASE` в `/admin/config`). Worker `telegram.send` и `telegram.answer` используют зеркало.
- Команды: `/start` (привет + меню), `/help`, `/plans` (список с кнопками Купить), `/buy <id>` (создаёт заказ тем же `BillingService::order` что и веб), `/status` (подписки + pending), `/orders` (10 последних), `/subs` (подписки), `/cabinet` (magic-link), `/support`, `/link`, `/login`.
- Inline-кнопки: `buy:`, `order:`, `plan:`, `menu:*` — покупка, оплата (url-кнопка на `checkout_url`), обновление статуса, навигация. Callback'и отвечают `answerCallbackQuery` через зеркало и дедуплицируются по `update_id`.
- Тандем: бот и веб используют одну БД — `users`/`orders`/`subscriptions` общие. Заказ из бота виден в `/orders` веба и наоборот. Подписка после `settle` видна в обоих каналах.

## Автопродление: как работает

- Клиент включает на карточке подписки (веб `/` или бот `/subs` → кнопка). Требуется `AUTORENEW_ENABLED=1` в админке. Только активные подписки, тариф должен быть активен.
- За `AUTORENEW_DAYS_BEFORE` дней (1–14, по умолчанию 3) scheduler ставит `subscription.renew`, worker создаёт renewal-заказ (`idempotency_key=renew:<sub>:<expiry>`) тем же провайдером и шлёт `payment.create`.
- Оплата — как обычная: ссылка FreeKassa/ЮKassa в кабинете и в боте. После `settle` срок продлевается от `max(now, expires_at)` + `duration_days`, в Remnawave уходит `PATCH /api/users/<uuid>` с новым `expireAt` (`subscription.extend`).
- Неудачи: отменённый renewal-заказ → `renew_fail_count+1`, повтор через час. После `AUTORENEW_MAX_FAILS` (1–10) автопродление для подписки отключается. Пауза продаж блокирует новые renewal-заказы.
- Нативные рекурренты FreeKassa (`recurrent=Y`) не используются — продление идёт нашими renewal-заказами, клиент каждый раз оплачивает по ссылке. Это прозрачнее для VPN и не требует хранения карт.
