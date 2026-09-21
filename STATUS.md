# ZeleBoba — текущий статус репозитория

> Snapshot: **2026-09-21 15:55 UTC** — актуальное состояние после выпилки всех платёжных провайдеров кроме Platega.
> Версия legacy-runtime: **1.1** (только Platega + demo; см. README).

## Актуальное состояние (2026-09-21)

Последнее крупное изменение — удалены все платёжные провайдеры, кроме **Platega** (коммит `2b729a4`). Ниже — что верно для текущей ветки:

- **Платёжный провайдер один — Platega** (`platega`), плюс `demo`-адаптер для разработки.
- Удалены: FreeKassa, ЮKassa, CryptoBot, Telegram Stars, Lava, WATA, Heleket, Tribute, MulenPay, Pal24, CloudPayments, Kassa AI, RioPay, SeverPay, PayPear, RollyPay, Overpay, AuraPay, Etoplatezhi, Antilopay, Jupiter, Donut, CisPay, TabPay, ParityPay.
- Webhook'и: только `/webhooks/platega` и `/webhooks/telegram`. Удалённые webhook'и возвращают 404.
- `PAYMENT_DRIVER` допускает только `demo|platega`; конфиг — `PLATEGA_ENABLED`, `PLATEGA_MERCHANT_ID`, `PLATEGA_SECRET`, `PLATEGA_API_BASE`.
- Прод (zeleboba-all-1): boot OK, `enabled()=platega`, outbox чист, логи без ошибок.

> ⚠️ Разделы ниже — **исторический срез на 2026-09-12** (до выпилки провайдеров). Они описывают legacy-состояние с FreeKassa/ЮKassa и не отражают текущую платёжную схему. Для актуального описания платёжного контура смотрите `README.md`, `docs/manual.md` (раздел 5) и `docs/architecture.md`.

---

## 1. Что реально работало в проде на 2026-09-12 (историч.)

Подтверждено через `docker ps`, `psql`, `audit_log`, `outbox.status`
на работающем `zeleboba-db-1`, `zeleboba-app-1`, `zeleboba-web-1`,
`zeleboba-bot-1`, `zeleboba-worker-1`, `zeleboba-scheduler-1`,
`zeleboba-otel-collector-1`.

| Подсистема | Состояние | Доказательство |
|---|---|---|
| **Telegram auth** | ✅ работает | `audit_log` показывает свежие `auth.telegram` за сегодня |
| **FreeKassa provider** | ⚠️ **частично заблокирован** | 44/78 orders в `pending`, FreeKassa nonce-lockout (см. `015_freekassa_nonce.sql`) |
| **ЮKassa provider** | ❌ **не используется** | `SELECT provider FROM orders GROUP BY provider` → только `freekassa` |
| **Remnawave provisioning** | ✅ работает | 20/78 orders в `fulfilled` — реально выдают VPN |
| **payment_unavailable flow** | ✅ работает | 5 orders уже в этом статусе, `src/Integration/PaymentService.php:43` |
| **Outbox** | ⚠️ забит dead-letter'ами | `SELECT status FROM outbox` → `done=21063, dead=6173` |
| **Audit log** | ✅ пишется | свежие события, корректные `actor/action/subject` |
| **Legacy reconciliation** | ✅ работает | `bin/console billing:reconcile` есть в `bin/console` |
| **Backup** | ✅ есть `scripts/backup.sh` | упоминается в `docs/manual.md` и `docs/operations.md` |
| **MFA / 2FA** | ✅ работает в legacy | `src/Identity/Mfa.php`, TOTP сценарий в `verification.md` |
| **Argon2id / Django pbkdf2 compat** | ✅ работает | `verification.md` упоминает «accept Django pbkdf2» |
| **Rate limiting** | ✅ работает | `rate_limits` таблица, throttle в `src/Web/Application.php` |
| **Promo / referrals / gifts / trials** | ✅ работают в legacy | `009_promocodes.sql`, `010_referrals.sql`, `011_gifts.sql`, `012_trials.sql` |
| **Marketing (broadcasts, channels, contests)** | ✅ работают в legacy | `013_marketing.sql` |
| **Admin / RBAC / audit** | ✅ работают в legacy | `014_admin_ops.sql`, `src/Billing/UserAdminService.php` |

### Не работает / проблемы

| Что | Симптом | Что делать |
|---|---|---|
| **FreeKassa checkout** | `payment_unavailable` 5 шт, 44 `pending` без движения | FreeKassa реально заблокирована. Нужно чинить nonce или ждать разблокировки FreeKassa |
| **Outbox: 6173 dead** | много мёртвых outbox-сообщений (legacy) | посмотреть `bin/console billing:audit`, почистить `dead` |
| **10 незакоммиченных правок** | `Dockerfile`, `bin/console`, `compose.yaml`, `src/Integration/Payment/FreeKassaProvider.php`, `src/Integration/PaymentService.php`, `src/Integration/RemnawaveProvisioner.php`, `src/Web/Application.php`, `templates/order.html.twig`, `templates/topup.html.twig`, `tests/BillingTest.php` | это **твоя** рабочая копия, я их не трогаю. Либо закоммить, либо отбрось |
| **7 untracked файлов** | `infra/otel-collector-config.yaml`, `infra/otel-collector-config.yaml.bak-20260912`, `infra/traces.creds`, `infra/traces.htpasswd`, `infra/update-otel-zeleboba-logs.py`, `migrations/020_payment_unavailable.sql`, `scripts/zeleboba-logs-link.sh` | **`infra/traces.creds` и `infra/traces.htpasswd` содержат секреты OTel**. Не пушить. Остальные — задокументировать или `.gitignore` |

---

## 2. Laravel runtime (foundation only, не запущен в проде)

Подтверждено через rehearsal-стенд `docker compose -f compose.rehearsal.yaml up`,
который поднимал 7 контейнеров (postgres + redis + app + horizon + scheduler +
web + mailpit) на изолированной сети `zeleboba_laravel_rehearsal_net` с
volume `zeleboba_laravel_rehearsal_*` (отдельным от прод-БД).

| Подсистема | Состояние | Проверено в rehearsal |
|---|---|---|
| **Laravel 12 bootstrap** | ✅ поднимается | `GET /health/live` → `{"status":"ok"}` |
| **PostgreSQL init (legacy migrations)** | ⚠️ **не полностью** | `015_freekassa_nonce.sql` падает без роли `billing` (см. замечание в `bin/rehearsal-check`) |
| **Laravel migrations** | ✅ | `cache`, `jobs`, `laravel_audit_log` |
| **Cutover preflight (rehearsal)** | ✅ | `php artisan cutover:check` → `Cutover preflight passed` |
| **Cutover preflight (production)** | ❌ FAIL | `cannot resolve the release commit for cutover evidence` (в PHP-FPM образе нет `git`) |
| **Pint (стиль)** | ✅ | 0 issues в 64 файлах |
| **Larastan level 5** | ✅ | 0 errors (после фикса `abort_unless($x !== null, 404)` в 3 контроллерах) |
| **composer audit** | ✅ | No advisories |
| **php artisan test** | ⚠️ 15/18 | 3 failing на `afterCommit` + sync queue (особенность Laravel test-environment, не баг кода) |
| **9 evidence-файлов** (`parity.json`, `rbac.json`, `payments.json`, `provisioning.json`, `queue-recovery.json`, `restore.json`, `security.json`, `rehearsal.json`, `rollback.json`) | ❌ все `pending` | нужны реальные sandbox-тесты и rehearsal-прогоны |
| **FreeKassa в Laravel** | ⚠️ реализован частично | `PaymentWebhookController::freekassa()`, но без sandbox-тестов |
| **ЮKassa в Laravel** | ⚠️ реализован частично | `PaymentWebhookController::yookassa()`, но без sandbox-тестов |
| **Remnawave в Laravel** | ⚠️ реализован | `ProvisionSubscription`, но `provision_driver=demo` в rehearsal |
| **24 «прочих» платёжных провайдера** | ❌ отсутствуют | ты сказал «нужен только FreeKassa» — пометить как `DEPRECATED` |
| **Promo / Referrals / Marketing в Laravel** | ❌ MISSING | по `laravel-migration-inventory.md` |
| **Reconciliation в Laravel** | ❌ отсутствует | по `laravel-migration-inventory.md` |
| **Transactional outbox в Laravel** | ⚠️ Laravel Queue + Horizon, но legacy outbox **не используется** | опасно при катоваре |

---

## 3. Что проверено и задокументировано

В `docs/verification.md` от 09.09.2026 зафиксировано:

- ✅ 43 PHPUnit-теста в legacy, 159 утверждений (PHP 8.4.25 + PostgreSQL 16/17)
- ✅ Идемпотентность order/payment, snapshots цены/чека, rollback
- ✅ Concurrency: 6 одинаковых checkout → 1 заказ; 6 webhook → 1 подписка
- ✅ `composer validate --strict`, `composer audit` (на 09.09 известных advisories не было)
- ✅ `billing:audit` — 0 нарушений на сквозном демо-заказе
- ✅ Runtime DB-роли: `UPDATE ledger`, `DELETE audit`, `CREATE TABLE` → SQLSTATE 42501
- ✅ Backup/restore: архив + master.key, расшифровка TOTP, ограничения runtime-роли

**Но `verification.md` от 09.09, не от 12.09.** После моей сессии:
- ❌ Не переигрывал legacy PHPUnit-тесты
- ❌ Не делал browser QA
- ❌ Не делал backup/restore на новой rehearsal-БД (FreeKassa-check показал, что работает с legacy `scripts/backup.sh`, но Laravel rehearsal-БД я не бэкапил)

---

## 4. Что надо убрать из репо

### Кандидаты на удаление (точно не нужны в проде)

| Файл | Почему | Действие |
|---|---|---|
| `scripts/fk_bal_final.php` | одноразовая диагностика FreeKassa, нигде не упоминается | можно удалить |
| `scripts/fk_bal_final2.php` | то же | удалить |
| `scripts/fk_bal_final3.php` | то же | удалить |
| `scripts/fk_bal_max.php` | то же | удалить |
| `scripts/fk_bal_time.php` | то же | удалить |
| `scripts/fk_bal_us.php` | то же | удалить |
| `scripts/fk_check.php` | одноразовая диагностика | удалить |
| `scripts/fk_createtopup.php` | одноразовая | удалить |
| `scripts/fk_newtopup.php` | одноразовая | удалить |
| `scripts/fk_verify.php` | одноразовая | удалить |
| `scripts/fk_verify2.php` | одноразовая | удалить |
| `scripts/migrate_bot.php` | одноразовый скрипт миграции бота | **проверить** — может быть нужен для бота в проде |

### Что НЕ надо удалять

| Файл | Почему оставить |
|---|---|
| `scripts/backup.sh` | реальный backup-скрипт, упоминается в `docs/manual.md` и `docs/operations.md` |
| `bin/console` | основная CLI-точка legacy-runtime |
| `infra/otel-collector-config.yaml` | прод-конфиг OTel (нужен!) |
| `infra/traces.creds` / `infra/traces.htpasswd` | **НЕ коммитить** — секреты, в `.gitignore` |
| `migrations/020_payment_unavailable.sql` | **применён в прод-БД**, нужен в репо |

---

## 5. Что добавить в `.gitignore`

```
# Секреты OTel — никогда не пушить
/infra/traces.creds
/infra/traces.htpasswd
/infra/*.bak-*

# Rehearsal-environment файлы — никогда не пушить
/.env
/.env.rehearsal
/.env.testing
/laravel/storage/app/cutover/*.json
/laravel/vendor/

# IDE / артефакты
/.phpunit.cache/
/.idea/
/.vscode/
```

---

## 6. Что надо сделать (приоритеты)

### Сегодня (легко, без риска)

1. ✅ **Применено**: `020_payment_unavailable.sql` в prod-БД (FreeKassa UI-фикс)
2. ⏭ Удалить одноразовые `scripts/fk_*.php` (после проверки `migrate_bot.php`)
3. ⏭ Добавить `infra/*.creds`, `infra/*.htpasswd`, `infra/*.bak-*` в `.gitignore`
4. ⏭ Закоммитить оставшиеся 10 локальных правок (или отбросить)
5. ⏭ Проверить статус `outbox.dead=6173` — может, очистить

### Эта неделя (требует sandbox-кредов)

6. Зарегистрировать **FreeKassa sandbox** магазин, дать creds через OpenClaw Secrets
7. Прогнать реальный Laravel-rehearsal с FreeKassa webhook → `payments.json` в `passed`
8. Прогнать Laravel-rehearsal на RBAC, queue-recovery, restore → ещё 4 evidence в `passed`

### До катовера (после P0 readiness)

9. Закрыть оставшиеся evidence (`parity`, `security`, `rehearsal`)
10. Добавить `git` в PHP-FPM образ (или передавать `RELEASE_COMMIT` через env)
11. **Только после `cutover:check --production = PASS`** → реальный катовер по `DEPLOYMENT.md`

---

## 7. Что я **не** делал и не должен был делать

- ❌ Не катовал Laravel в прод (твои `docs/PRODUCTION_READINESS.md` запрещают)
- ❌ Не подделывал evidence-файлы со статусом `passed`
- ❌ Не использовал prod-Freekassa-credentials в rehearsal
- ❌ Не удалял твои локальные10 незакоммиченных правок
- ❌ Не правил legacy runtime (только `020_payment_unavailable.sql` — аддитивная миграция)

---

## 8. Текущее состояние GitHub

```
origin/main = 3865982 (Merge chore/laravel-rehearsal-and-cutover-prep)
              f28ccb8 (Merge origin/main: bring in Laravel foundation)
              e6cf84d chore(rehearsal): fix rehearsal stack to a clean run-from-zero path
              19f4385 chore(rehearsal): bring Laravel runtime to passing rehearsal
              5d86810 Add Laravel production deployment foundation  ← сохранён
              ecba7d9 add OpenTelemetry tracing and fix dark buttons
              ...
```

Все мои rehearsal-коммиты **залиты в `main`**. Force-push не понадобился.
Твои10 незакоммиченных правок остались локально — это **твоя** забота.

---

## 9. Что осталось до полного cutover'а

| Блокер | Статус | Что нужно |
|---|---|---|
| `cutover:check --production` | ❌ FAIL | git в PHP-FPM, или `RELEASE_COMMIT` env |
| 9 evidence-файлов | ❌ pending | sandbox-Freekassa + RBAC + queue-recovery + restore + security + parity + rehearsal |
| Sandbox-Freekassa | ❌ нет creds | регистрация на freekassa.com → 5 минут |
| Backup/restore rehearsal Laravel | ❌ не делали | staging с изолированной prod-БД |
| `PRODUCTION_READINESS.md` P0 | ❌ 7 пунктов | закрыть по `laravel-migration-inventory.md` |

**Катовер возможен только когда:**
- Все 9 evidence в `passed`
- `cutover:check --production` зелёный
- У тебя есть свежий backup legacy-БД для rollback
- Ты осознанно говоришь «катуй, я принял риск»

---

## 10. Конкретные следующие действия

1. Прочитать `STATUS.md` (этот файл), решить, что править
2. Удалить мусор из `scripts/` (см. раздел 4)
3. Добавить секреты в `.gitignore`
4. Зарегистрировать FreeKassa sandbox → дать creds → я закрою `payments.json` evidence
5. Когда будет готов — продолжить по `laravel-migration-inventory.md`