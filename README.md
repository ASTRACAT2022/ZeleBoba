<div align="center">

# ZeleBoba Billing

### Production-grade subscription billing for Remnawave VPN panels

**Stripe-grade money handling · Exactly-once settlement · Durable async processing · Full audit trail**

[![CI](https://github.com/ASTRACAT2022/ZeleBoba/actions/workflows/ci.yml/badge.svg)](https://github.com/ASTRACAT2022/ZeleBoba/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4-8892BE?logo=php&logoColor=white)](https://www.php.net)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-17-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Symfony](https://img.shields.io/badge/Symfony-7.4-000000?logo=symfony&logoColor=white)](https://symfony.com)

_Web cabinet · Telegram bot · Wallet · Platega payments · Referrals · Gifts · Trials · Marketing · Full admin_

</div>

---

**ZeleBoba** is a complete, self-hosted subscription billing platform for [Remnawave](https://github.com/remnawave) VPN panels. It is engineered as a **money-handling system first**: every payment settles **exactly once**, nothing is ever lost, and every financial mutation is recorded in a tamper-proof audit trail.

> ⚠️ **Real money receiving is enabled only after you verify a specific merchant, panel, and public HTTPS domain.** Live payments connect exclusively via **Platega** (plus a built-in `demo` adapter for local development). See [Deployment](#deployment).

---

## Why "production-grade"

ZeleBoba was built — and hardened in live production — around the same correctness invariants Stripe-class platforms rely on:

| Invariant | How it's enforced |
|---|---|
| **Exactly-once settled** | PostgreSQL advisory locks + `UNIQUE(provider, provider_payment_id)` + idempotency keys |
| **No lost payments** | Provider → durable inbox → transactional outbox → API re-verification → settle |
| **No double credits** | Row-level `FOR UPDATE` + unique receipts + state machine |
| **Replay / tamper safe** | `WebhookGuard` payload hashing; no webhook can re-trigger settlement |
| **Crash-safe provisioning** | `DurableWorkflow` leases remote calls; deterministic `zb_<sub>` usernames recover safely |
| **Self-healing** | A reconciler re-enqueues only verifiable work; circuit breakers fail fast |
| **Financial drift detection** | `ConsistencyChecker` verifies the ledger balances to zero every cycle |

In production today: **34,000+ orders**, **3,600+ active subscriptions**, **zero duplicate settlements**, **zero money drift** (ledger balance = 0).

---

## Capabilities

| Domain | What it does |
|--------|---------------|
| **Auth** | Email/password + Telegram; 5-min one-time deep-link login; TOTP 2FA with backup codes |
| **Billing** | One wallet (balance top-ups via Platega, balance purchases, auto-purchase smart cart) |
| **Payments** | Platega primary (amounts normalized, net-of-commission, gross handling), `demo` adapter |
| **Subscriptions** | Auto-renewal N-days before expiry, daily-priced plans, Remnawave sync |
| **Promo** | Money, days, trials, discounts, combos; limits and anti-stacking |
| **Referrals** | Topup commission, bonuses, tiers, withdraw with risk-scoring |
| **Gifts** | `GIFT_<code>` subscriptions, deep-link activation |
| **Trials** | Free/paid trial, in-place conversion to paid |
| **Marketing** | Segment broadcasts, required channels, landing pages, contests, reward surveys |
| **Admin** | RBAC roles/permissions, immutable audit, reports, monitoring, backups, maintenance |

---

## Security model

- **Secrets encrypted at rest** in PostgreSQL; master key lives outside the app in `var/master.key` (separate volume).
- **PostgreSQL role separation** — the runtime role cannot rewrite the ledger, receipts, or audit.
- **No secrets in the image or web root**; `.env` holds only DB connectivity, not merchant keys.
- **Immutable audit trail** — every money/service mutation is logged with actor, old/new state, and correlation ID.
- **2FA required** for admin sections; admin approval auto-expires after 15 minutes.
- **Webhook tamper detection** — same event id + different payload = flagged as potential attack, surfaced to ops.

---

## Tech stack

- **PHP 8.4** (strict types), **Symfony 7.4** components, **Twig 3**
- **PostgreSQL 17** — the system of record for state, outbox, and workflows
- **Docker / Docker Compose** (single merged runtime container)
- **OpenTelemetry** (OTLP exporter) for observability
- CI: PHPUnit, PSR-12 lint, `composer validate --strict`, `composer audit`, Docker build

---

## Repository layout

```
├── src/
│   ├── Billing/          # BillingService, Wallet, Topups, Auto-renew, Refunds
│   ├── Integration/      # PaymentService, Platega provider, Remnawave provisioner, Telegram
│   ├── Payments/         # Durable webhook inbox (PaymentEventStore), attempts
│   ├── Infrastructure/   # Outbox, Reconciler, DurableWorkflow, CircuitBreaker, WebhookGuard
│   ├── Subscriptions/    # Subscription lifecycle & state machine
│   ├── Identity/         # Auth, TOTP 2FA
│   ├── Observability/    # ConsistencyChecker, Ops, Telemetry
│   ├── Settings/         # Config, readiness, integration checks
│   └── Web/              # HTTP application, router, controllers
├── templates/             # Twig templates
├── public/               # Web root
├── migrations/           # Versioned SQL migrations
├── tests/                # PHPUnit + fault-injection harnesses
├── docs/                 # Architecture, operations, verification, manual
├── .github/workflows/    # CI pipeline
└── compose.yaml          # Docker Compose
```

---

## Deployment

Requires Docker Engine + Docker Compose. HTTPS is terminated at an external reverse proxy; the built-in Nginx listens only on localhost and the DB/PHP-FPM are not exposed.

1. Copy `.env.example` → `.env`; set **distinct strong** `DATABASE_PASSWORD` and `DATABASE_MIGRATION_PASSWORD`.
2. Bring the stack up:

   ```sh
   docker compose build
   docker compose up -d db
   docker compose run --rm migrate
   docker compose run --rm app php bin/console app:install owner@example.com
   docker compose up -d app worker scheduler web
   ```

   `app:install` prompts for a password via hidden input. No embedded credentials exist.
3. Configure an HTTPS proxy per `infra/reverse-proxy.example.conf`. **Do not expose port 8080** to the internet.
4. Open `/login`, enable 2FA, save backup codes. Then `/admin/config`: public HTTPS URL, keys, bot username, Remnawave squad. Keep sales **off** until verified.
5. Register the Telegram webhook; in the Platega cabinet set the notification URL `https://your-domain/webhooks/platega` (POST) and configure `PLATEGA_SECRET`.
6. Run a **test end-to-end payment and subscription issuance**. Then switch to live merchant keys and enable sales.
7. Complete `/admin/readiness`, configure offsite backup and monitoring.

> **Use separate environments and DBs for test and live merchants.** Never move test orders into the live system.

### Hot-fix deploy (production)

Workspace is not bind-mounted into the runtime. After editing PHP files, copy them into the merged container and restart:

```sh
docker cp src/Integration/PaymentService.php zeleboba-all-1:/app/src/Integration/PaymentService.php
docker restart zeleboba-all-1
```

---

## Local development

```sh
composer install
cp .env.example .env
php bin/console db:migrate
php bin/console app:install owner@example.com
php bin/console db:seed
php -S 127.0.0.1:8080 -t public public/router.php
# Second terminal:
php bin/console worker:run
# Periodically:
php bin/console billing:reconcile
```

SQLite is for development only. Enable demo sales in the admin for local tests; sales are off by default.

---

## Verification & operations

```sh
composer test
composer validate --strict
composer audit
docker compose exec app php bin/console app:doctor
docker compose exec app php bin/console billing:audit
scripts/backup.sh /secure/offsite-staging
```

For concurrency tests set `TEST_POSTGRES_DSN`, `TEST_POSTGRES_USER`, `TEST_POSTGRES_PASSWORD` pointing at a separate PostgreSQL DB.

The fault-injection suite (`tests/*_faulttest.php`) verifies exactly-once settlement, double-webhook idempotency, concurrent-settle races, and paid-without-delivery detection against a live DB.

---

## Documentation

- [Architecture](docs/architecture.md) — system design, data flow, invariants
- [Operations & Recovery](docs/operations.md) — runbook, backup/restore, incident handling
- [Verification](docs/verification.md) — test coverage, audits, proofs
- [Parity](docs/parity.md) — feature boundaries
- [Operator Manual](docs/manual.md) — full operator guide
- [Production Status](STATUS.md) — live deployment health & invariants
- [Changelog](CHANGELOG.md) — version history

## Project health

- [Security policy](SECURITY.md) — how to report vulnerabilities
- [Contributing](CONTRIBUTING.md) — coding standards & PR checklist

---

## License

[MIT](LICENSE) © 2026 ASTRACAT2022

*Independent PHP implementation; inspired by [Bedolaga](https://github.com/BEDOLAGA-DEV/remnawave-bedolaga-telegram-bot). No source or assets were copied.*
