# Changelog

All notable changes to **ZeleBoba Billing** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Fixed
- **Orphan payment events are now acked instead of retried to death** — `payment.event.process` jobs with no matching provider (e.g. a stale or unknown provider id) threw `BillingError`, retried 8× and dead-lettered. `processEvent()` now marks permanent business errors as `processed` (acked) so the event leaves the queue without a 500-loop; `PaymentEventStore::failed()` dead-letters `JobPermanentFailure` immediately. Verified by `tests/worker_event_faulttest.php` + `tests/outbox_cycle_faulttest.php`.

---

## 1.1 — 2026-09-21

### Changed (provider consolidation)
- **Removed all payment providers except Platega** (+ `demo` for development). FreeKassa, YooKassa, CryptoBot, Telegram Stars, Lava, WATA, Heleket, Tribute, MulenPay, Pal24, CloudPayments, Kassa AI, RioPay, SeverPay, PayPear, RollyPay, Overpay, AuraPay, Etoplatezhi, Antilopay, Jupiter, Donut, CisPay, TabPay, ParityPay are gone.
- Webhooks are now only `/webhooks/platega` and `/webhooks/telegram`; removed webhooks return 404.
- `PAYMENT_DRIVER` accepts only `demo|platega`.

### Fixed
- **Platega amount inflation (100×)** — `paymentDetails.amount` is whole RUB; sending kopeks inflated each charge 100× (100₽ → 10,000₽). Converted kopeks→rubles on create, back on verify.
- **Status verification** — uses `GET /transaction/{id}`, not `POST /v2/transaction/{id}` (404 misread every live tx as canceled).
- **Net-amount settlement** — `verify()` now settles net kopeks (`gross − commission`), so a paid order no longer fails `price_minor !== amount` and doesn't enter a re-verify loop.
- **Telegram permanent failures** — blocked/dead chats now resolve to `done` (via `JobPermanentFailure`) instead of retrying to 8 and dead-lettering, preventing queue floods.
- **Worker payment dispatch bug** — `?? throw` on a void method dead-lettered every `payment.event.process` job; replaced with an explicit null-guard.

### Added
- **Daily auto-charge** for daily-priced tariffs (auto on purchase, cancel button stops it).
- **Per-tariff auto-renew settings** (days-before / max-fails) with admin UI.
- **Concurrency fault-test** for daily auto-charge — no double-debit under race.
- **ConsistencyChecker delivery-gap alert** — succeeded payment whose order isn't `paid`/`fulfilled` is surfaced as a deduplicated Ops op (customer paid but not served; operator alerted, no auto-mutation).
- **WebhookGuard wiring** — DB-backed tamper/replay protection now actually called inside the settlement transaction.
- **State machine** — `can()`/`assertCanTransition` enforced in `BillingService::settle()` and `TopupService::settle()`.
- **Merged runtime container** — php-fpm web + outbox worker + reconcile scheduler + telegram poll under one supervisor.

### Security
- **Webhook tamper alert** — same `provider_event_id` with different payload content flips `processed=2`, raises an Ops alert, and emails the admin. Money is never touched.

---

## 1.0 — 2026-09-10

### Initial production release
- Full subscription billing for Remnawave: wallet, Platega payments, promo, referrals, gifts, trials, marketing, admin with RBAC.
- Transactional outbox, durable webhook inbox, advisory-locked exactly-once settlement.
- PostgreSQL role separation (runtime vs migrator), secrets encrypted at rest.
- TOTP 2FA for admin sections.
- CI pipeline (PHPUnit + PSR-12 lint + `composer validate/audit` + Docker build).
