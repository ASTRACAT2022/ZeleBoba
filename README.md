<div align="center">

# ZeleBoba Billing

### Промышленный биллинг подписок для VPN-панелей Remnawave

**Обработка денег уровня Stripe · Точно-однократное зачисление · Устойчивое асинхронное выполнение · Полный аудит**

[![CI](https://github.com/ASTRACAT2022/ZeleBoba/actions/workflows/ci.yml/badge.svg)](https://github.com/ASTRACAT2022/ZeleBoba/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4-8892BE?logo=php&logoColor=white)](https://www.php.net)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-17-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Symfony](https://img.shields.io/badge/Symfony-7.4-000000?logo=symfony&logoColor=white)](https://symfony.com)

_Веб-кабинет · Telegram-бот · Кошелёк · Платежи Platega · Рефералы · Подарки · Триалы · Маркетинг · Полная админка_

</div>

---

**ZeleBoba** — полноценная, self-hosted платформа биллинга подписок для VPN-панелей [Remnawave](https://github.com/remnawave). Она спроектирована **прежде всего как система обработки денег**: каждый платёж зачисляется **ровно один раз**, ничего никогда не теряется, а каждое финансовое изменение фиксируется в защищённом от подделки журнале аудита.

> ⚠️ **Реальный приём денег включается только после проверки конкретного магазина, панели и публичного HTTPS-домена.** Живые платежи подключаются исключительно через **Platega** (плюс встроенный `demo`-адаптер для локальной разработки). См. [Развёртывание](#развёртывание).

---

## Почему «промышленный класс»

ZeleBoba была построена — и закалена в живом production — вокруг тех же инвариантов корректности, на которых держатся платформы класса Stripe:

| Инвариант | Как обеспечивается |
|---|---|
| **Точно-однократное зачисление** | advisory locks PostgreSQL + `UNIQUE(provider, provider_payment_id)` + ключи идемпотентности |
| **Ни один платёж не теряется** | провайдер → durable-вход → транзакционный outbox → повторная верификация по API → зачисление |
| **Ни двойного зачисления** | блокировки строк `FOR UPDATE` + уникальные квитанции + state machine |
| **Защита от replay / подделки** | хеширование payload в `WebhookGuard`; ни один webhook не может перезапустить зачисление |
| **Provisioning, устойчивый к сбоям** | `DurableWorkflow` лизинг сетевых вызовов; детерминированные `zb_<sub>` username'ы безопасно восстанавливаются |
| **Самовосстановление** | reconciliation пере-ставит в очередь только проверяемую работу; circuit breakers дают fail-fast |
| **Выявление финансового дрейфа** | `ConsistencyChecker` каждый цикл проверяет, что ledger сходится к нулю |

В production сейчас: **34 000+ заказов**, **3 600+ активных подписок**, **ноль дублей зачислений**, **ноль денежного дрейфа** (баланс ledger = 0).

---

## Возможности

| Домен | Что делает |
|--------|---------------|
| **Аутентификация** | Email/пароль + Telegram; одноразовый вход по deep-link на 5 минут; TOTP 2FA с резервными кодами |
| **Биллинг** | Единый кошелёк (пополнение через Platega, покупка с баланса, автопокупка «умная корзина») |
| **Платежи** | Основной провайдер Platega (нормализация сумм, net-зачисление после комиссии, gross handling), адаптер `demo` |
| **Подписки** | Автопродление за N дней до окончания, подписки с дневной ценой, синхронизация с Remnawave |
| **Промо** | Деньги, дни, триалы, скидки, комбо; лимиты и защита анти-стакинг |
| **Рефералы** | Комиссия с пополнений, бонусы, ступени, вывод средств с риск-скорингом |
| **Подарки** | Подписки `GIFT_<код>`, активация по deep-link |
| **Триалы** | Бесплатный/платный триал, конвертация в платный on-place |
| **Маркетинг** | Рассылки по сегментам, обязательные каналы, лендинги, конкурсы, награды за опросы |
| **Админка** | RBAC роли/права, неизменяемый аудит, отчёты, мониторинг, бэкапы, техработы |

---

## Модель безопасности

- **Секреты шифруются при хранении** в PostgreSQL; мастер-ключ живёт вне приложения в `var/master.key` (отдельный volume).
- **Разделение ролей PostgreSQL** — runtime-роль не может переписать ledger, квитанции или аудит.
- **Секретов нет в образе и в web root**; `.env` содержит только подключение к БД, а не ключи магазина.
- **Неизменяемый аудит** — каждое изменение денег/услуг логируется с актором, старым/новым состоянием и correlation ID.
- **2FA обязательна** для админ-разделов; административное подтверждение авто-истекает через 15 минут.
- **Выявление подделки webhook** — тот же event id + другой payload = помечается как потенциальная атака, всплывает у оператора.

---

## Технологический стек

- **PHP 8.4** (строгие типы), компоненты **Symfony 7.4**, **Twig 3**
- **PostgreSQL 17** — система записи для состояния, outbox и workflow
- **Docker / Docker Compose** (единый объединённый runtime-контейнер)
- **OpenTelemetry** (OTLP exporter) для наблюдаемости
- CI: PHPUnit, PSR-12 lint, `composer validate --strict`, `composer audit`, сборка Docker

---

## Структура репозитория

```
├── src/
│   ├── Billing/          # BillingService, Wallet, Topups, Auto-renew, Refunds
│   ├── Integration/      # PaymentService, Platega-provider, Remnawave-provisioner, Telegram
│   ├── Payments/         # Durable webhook inbox (PaymentEventStore), attempts
│   ├── Infrastructure/   # Outbox, Reconciler, DurableWorkflow, CircuitBreaker, WebhookGuard
│   ├── Subscriptions/    # Жизненный цикл подписок и state machine
│   ├── Identity/         # Аутентификация, TOTP 2FA
│   ├── Observability/    # ConsistencyChecker, Ops, Telemetry
│   ├── Settings/         # Конфиг, readiness, проверки интеграций
│   └── Web/              # HTTP-приложение, роутер, контроллеры
├── templates/             # Twig-шаблоны
├── public/               # Web root
├── migrations/           # Версионированные SQL-миграции
├── tests/                # PHPUnit + fault-injection харнессы
├── docs/                 # Архитектура, эксплуатация, проверки, руководство
├── .github/workflows/    # CI-пайплайн
└── compose.yaml          # Docker Compose
```

---

## Развёртывание

Требуется Docker Engine + Docker Compose. HTTPS завершается на внешнем reverse proxy; встроенный Nginx слушает только localhost, а БД и PHP-FPM не публикуются наружу.

1. Скопируйте `.env.example` → `.env`; задайте **отдельные сильные** `DATABASE_PASSWORD` и `DATABASE_MIGRATION_PASSWORD`.
2. Поднимите стек:

   ```sh
   docker compose build
   docker compose up -d db
   docker compose run --rm migrate
   docker compose run --rm app php bin/console app:install owner@example.com
   docker compose up -d app worker scheduler web
   ```

   `app:install` запрашивает пароль скрытым вводом. Встроенных учётных данных нет.
3. Настройте HTTPS-прокси по `infra/reverse-proxy.example.conf`. **Не открывайте порт 8080** в интернет.
4. Откройте `/login`, включите 2FA, сохраните резервные коды. Затем `/admin/config`: публичный HTTPS URL, ключи, username бота, squad Remnawave. Держите продажи **выключенными** до проверки.
5. Зарегистрируйте webhook Telegram; в кабинете Platega укажите URL оповещения `https://ваш-домен/webhooks/platega` (POST) и настройте `PLATEGA_SECRET`.
6. Проведите **тестовый сквозной платёж и выдачу подписки**. Затем переключитесь на боевые ключи магазина и включите продажи.
7. Завершите `/admin/readiness`, настройте offsite-бэкап и мониторинг.

> **Для тестового и боевого магазинов используйте отдельные окружения и БД.** Никогда не переносите тестовые заказы в боевую систему.

### Hot-fix деплой (production)

Workspace не bind-mounted в runtime. После правки PHP-файлов скопируйте их в объединённый контейнер и перезапустите:

```sh
docker cp src/Integration/PaymentService.php zeleboba-all-1:/app/src/Integration/PaymentService.php
docker restart zeleboba-all-1
```

---

## Локальная разработка

```sh
composer install
cp .env.example .env
php bin/console db:migrate
php bin/console app:install owner@example.com
php bin/console db:seed
php -S 127.0.0.1:8080 -t public public/router.php
# Второй терминал:
php bin/console worker:run
# Периодически:
php bin/console billing:reconcile
```

SQLite предназначена только для разработки. Включите демопродажи в админке для локальных тестов; продажи по умолчанию выключены.

---

## Проверки и эксплуатация

```sh
composer test
composer validate --strict
composer audit
docker compose exec app php bin/console app:doctor
docker compose exec app php bin/console billing:audit
scripts/backup.sh /secure/offsite-staging
```

Для конкурентных тестов задайте `TEST_POSTGRES_DSN`, `TEST_POSTGRES_USER`, `TEST_POSTGRES_PASSWORD`, указывающие на отдельную БД PostgreSQL.

Набор fault-injection тестов (`tests/*_faulttest.php`) проверяет на живой БД точно-однократное зачисление, идемпотентность двойных webhook, гонки конкурентного зачисления и выявление оплаченных-без-выдачи.

---

## Документация

- [Архитектура](docs/architecture.md) — проектирование системы, потоки данных, инварианты
- [Эксплуатация и восстановление](docs/operations.md) — runbook, бэкап/восстановление, инциденты
- [Проверки](docs/verification.md) — покрытие тестами, аудиты, доказательства
- [Границы функциональности](docs/parity.md) — границы возможностей
- [Руководство оператора](docs/manual.md) — полное руководство оператора
- [Статус production](STATUS.md) — здоровье боевого деплоя и инварианты
- [История изменений](CHANGELOG.md) — история версий

## Здоровье проекта

- [Политика безопасности](SECURITY.md) — как сообщать об уязвимостях
- [Участие](CONTRIBUTING.md) — стандарты кода и чек-лист PR

---

## Лицензия

[MIT](LICENSE) © 2026 ASTRACAT2022

*Независимая PHP-реализация; вдохновлена [Bedolaga](https://github.com/BEDOLAGA-DEV/remnawave-bedolaga-telegram-bot). Исходный код и ресурсы не копировались.*
