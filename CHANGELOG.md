# История изменений

Все значимые изменения **ZeleBoba Billing** документируются здесь.

Формат следует [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) и придерживается [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Исправлено
- **Orphan-платёжные события теперь ack'атся вместо ретраев до смерти** — задания `payment.event.process` без подходящего провайдера (например, устаревший или неизвестный id) бросали `BillingError`, ретраились 8 раз и уходили в dead-letter. `processEvent()` теперь помечает постоянные бизнес-ошибки как `processed` (ack), чтобы событие покидало очередь без 500-loop; `PaymentEventStore::failed()` сразу dead-letter'ит `JobPermanentFailure`. Проверено `tests/worker_event_faulttest.php` + `tests/outbox_cycle_faulttest.php`.

---

## 1.1 — 2026-09-21

### Изменено (консолидация провайдеров)
- **Удалены все платёжные провайдеры, кроме Platega** (+ `demo` для разработки). FreeKassa, YooKassa, CryptoBot, Telegram Stars, Lava, WATA, Heleket, Tribute, MulenPay, Pal24, CloudPayments, Kassa AI, RioPay, SeverPay, PayPear, RollyPay, Overpay, AuraPay, Etoplatezhi, Antilopay, Jupiter, Donut, CisPay, TabPay, ParityPay больше нет.
- Webhook'и теперь только `/webhooks/platega` и `/webhooks/telegram`; удалённые webhook'и возвращают 404.
- `PAYMENT_DRIVER` принимает только `demo|platega`.

### Исправлено
- **Инфляция суммы Platega (×100)** — `paymentDetails.amount` указывается в целых рублях; отправка копеек раздувала каждый платёж в 100 раз (100₽ → 10 000₽). Конвертация копеек→рублей при создании, и обратно при верификации.
- **Проверка статуса** — используется `GET /transaction/{id}`, а не `POST /v2/transaction/{id}` (404 ложно читал каждый живой транзак как отменённый).
- **Зачисление по net-сумме** — `verify()` теперь зачисляет net-копейки (`gross − комиссия`), поэтому оплаченный заказ больше не падает на `price_minor !== amount` и не входит в бесконечный цикл пере-верификации.
- **Постоянные сбои Telegram** — заблокированные/мёртвые чаты теперь разрешаются в `done` (через `JobPermanentFailure`) вместо ретраев до 8 и dead-letter, предотвращая засорение очереди.
- **Баг диспетчеризации воркера** — `?? throw` на void-методе dead-letter'ил каждое задание `payment.event.process`; заменён на явную null-проверку.

### Добавлено
- **Ежедневное автосписание** для тарифов с дневной ценой (авто при покупке, кнопка отмены его останавливает).
- **Настройки авто-продления по тарифу** (дней-до-окончания / макс-сбоев) с админ-UI.
- **Конкурентный fault-тест** для ежедневного автосписания — двойное списание при гонке исключено.
- **Оповещение delivery-gap в ConsistencyChecker** — успешный платёж, у которого заказ не `paid`/`fulfilled`, поднимается как дедуплицированная Ops-операция (клиент заплатил, но не получил услугу; оператор предупреждается, без авто-мутации).
- **Подключение WebhookGuard** — DB-защита от подделки/replay теперь действительно вызывается внутри транзакции зачисления.
- **State machine** — `can()`/`assertCanTransition` enforced в `BillingService::settle()` и `TopupService::settle()`.
- **Объединённый runtime-контейнер** — php-fpm web + outbox worker + reconcile scheduler + telegram poll под одним супервизором.

### Безопасность
- **Оповещение о подделке webhook** — тот же `provider_event_id` с изменённым payload переводит `processed=2`, поднимает Ops-оповещение и письмо админу. Деньги не трогаются.

---

## 1.0 — 2026-09-10

### Начальный production-релиз
- Полный биллинг подписок для Remnawave: кошелёк, платежи Platega, промо, рефералы, подарки, триалы, маркетинг, админка с RBAC.
- Транзакционный outbox, durable webhook inbox, advisory-locked точно-однократное зачисление.
- Разделение ролей PostgreSQL (runtime vs migrator), секреты шифруются при хранении.
- TOTP 2FA для админ-разделов.
- CI-пайплайн (PHPUnit + PSR-12 lint + `composer validate/audit` + сборка Docker).
