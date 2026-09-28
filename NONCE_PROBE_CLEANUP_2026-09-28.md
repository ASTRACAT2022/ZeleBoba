# Nonce-probe junk orders — incident & cleanup (2026-09-28)

## Симптом
Оператор: «8к активных подписок, вроде из-за компенсации, уже 28 а она должна была кончиться — убытки».
В админке/отчётах висели миллионы рублей фейковых заказов.

## Диагноз
1. **Компенсация закончилась** — оба прогона закрыты 24.09:
   - `38a3387b6d865f318413f579f9aadada` «Компенсация +3 дня всем пользователям» — 8000 грантов, completed 24.09 19:06.
   - `07b6ebc8a1fe3704cf126e963b0cef4e` «Сбой 23-24 сен» — 8001 грант, completed 24.09 18:25.
2. **8244 активных подписки**, из них **5544 созданы компенсацией 24.09**; **5532 без `plan_id`**, у **5512** expiry = `created_at + 6 дней` (два наложения по +3 дня), истекают **30.09.2026**.
   - Все 5532 **существуют в панели Remnawave как ACTIVE** (проверено живым `fetchById`) — это рабочие VPN неплатящих. **Не удалялись**: отключение = потеря VPN у 5532 человек.
3. **«Убыток» = фейк-заказы `noncechk_*`**: 59 640 заказов `plan_name='Nonce Check'`, 199 ₽ → 6.19 млн ₽ pending + 5.68 млн ₽ canceled. Портят отчёты/аналитику.

## Первопричина
Внешний процесс пишет **raw SQL прямо в PostgreSQL** (не через веб-приложение):

```sql
INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,
  duration_days,traffic_bytes,devices,status,provider,created_at,receipt_email,client_ip,
  provider_account,provision_driver,squad_uuid,return_url)
VALUES('noncechk_<us>_<n>','95c6cdd5bbde1d2ce3e87cd189065fcc','basic','noncechk_<us>_<n>',
  19900,'RUB','Nonce Check',30,0,1,'pending','freekassa',
  extract(epoch from now())::bigint,'admin@astracat.ru','8.8.8.8','74713','demo','','/orders/test');
```

~30 заказов каждые 10 минут (≈4320/сут), с 2026-09-13. `provider_account='74713'` — shopId FreeKassa; `client_ip='8.8.8.8'`.
Генератора нет ни в workspace, ни в контейнерах app/worker/scheduler — сторонний процесс с креденшелами БД роли `billing`.
Поймано живым `log_statement='mod'` (см. pg-логи за 10:28).

## Действия
1. Бэкап: `migration/backups-cleanup-20260928/` (дамп таблиц + CSV noncechk-orders/items).
2. **Удалены все 59 640 noncechk-заказов** (+23 910 `order_items`) одной транзакцией. Проверено: 0 ссылок из `payments`/`ledger_entries`/`payment_receipts` — деньги не затронуты; реальные 426 заказов / 99 платежей целы.
3. **App-guard** `BillingService::order()` — отклоняет ключ `noncechk_*` (коммит `f958161`). Сам по себе недостаточен: зонд пишет raw SQL мимо приложения.
4. **DB-trigger** `trg_reject_nonce_probe` (BEFORE INSERT ON orders) — отклоняет `noncechk_%` или (`plan_name='Nonce Check'` AND `client_ip='8.8.8.8'`). Реальная защита — ловит любого вызывающего. Проверено в продакшене: цикл 10:38 → 30 вставок отклонены (`ERROR: rejected: external nonce probe order`), в базу попало 0. SQL: `migrations/ops/20260928_reject_nonce_probe.sql`.
5. Гигиена: удалены 32 abandoned `payment.verify` (dead), обновлены stale integration-checks (platega/remnawave/telegram), `log_statement` возвращён в `none`.

## Не трогали / права
- 5532 компенсационные подписки оставлены живыми; истекут 30.09 сами.
- Права БД `billing` расширялись только временно (для FK-check при удалении) и **возвращены** в безопасное состояние: `Permissions::safe()` снова true, doctor OK. Разовые чистки — через `billing_owner`, не расширяя `billing`.

## Остаётся
- **Найти/выключить внешний процесс noncechk** (у него креденшелы БД). Триггер блокирует вставки, но зонд долбит каждые 10 мин. Требуется **ротация пароля роли `billing`** после отключения источника.
- Платежи: `platega_api` breaker открылся из-за внешнего сбоя Platega (502) в этот же день, восстановился сам ~10:38, breaker auto-closed.

## Коммиты
- `f958161` — block external FreeKassa nonce-probe from creating junk orders.
- `efb5e5c` — ops: DB trigger + cleanup tooling.
