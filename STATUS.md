# ZeleBoba — Production Status

> **Snapshot: 2026-09-25** — live production state of the billing platform.
> Runtime version: **1.1+ef31c96** (Platega-only payment, single merged container;
> "Redesign VPN dashboard and admin navigation" deployed).
> Deploy note (2026-09-25): updated to commit `ef31c96` via rsync + rebuild.
> Added `.dockerignore` entries (the checkout had a recursive `zeleboba/` copy
> ~20 GB that blew up the build context); rebuilt `app` + `migrate`; the
> `billing_owner` password had to be re-aligned via `ALTER USER`; old
> all-in-one container `zeleboba-all-1` stopped (double Telegram poller → HTTP 409)
> and `web` recreated so nginx re-resolved `app:9000`. All subsystems healthy,
> prod `/login` 200, Traefik `zeleboba` backend UP.

This document reflects the **current production deployment** and the correctness
invariants the codebase is verified against. Historical notes about removed
providers are retained in [docs/operations.md](docs/operations.md) for
maintenance context only.

---

## ✅ Live production health (2026-09-21)

| System | State | Evidence |
|---|---|---|
| **Container** | 🟢 healthy | `zeleboba-all-1` Up (healthy), web 200 on `/login` |
| **Payment provider** | 🟢 Platega | `enabled()=platega`, circuit breaker `closed`, 0 failures |
| **Remnawave provisioning** | 🟢 active | 3,600+ active subscriptions with remote ids |
| **Outbox queue** | 🟢 drained | all recent jobs `done`, 0 stuck pending |
| **Ledger balance** | 🟢 zero-drift | `SUM(provider_clearing) + SUM(subscription_sales) = 0` |
| **Duplicate settlement** | 🟢 none | 0 duplicate `payments`, 0 duplicate `payment_receipts` |
| **Financial drift** | 🟢 none | `ConsistencyChecker` → `ok` (no critical drift) |
| **Open operational cases** | 🟢 none | 0 open `operational_cases`, 0 high-severity |
| **Migrations** | 🟢 applied | runtime role restricted; ledger/audit unwritable by app role |

### Load (trailing 7 days)

- **Orders**: 34,213 created
- **Active subscriptions**: 3,600+
- **Payments (24h)**: 57 succeeded
- **Webhook events processed**: 74

---

## Correctness invariants (verified, not assumed)

The following are enforced at the database level and covered by
[fault-injection tests](tests/) that run against a live PostgreSQL DB:

| Invariant | Enforcement |
|---|---|
| **A payment settles exactly one order, exactly once** | `pg_advisory_xact_lock` + `UNIQUE(provider, provider_payment_id)` + idempotency keys |
| **Concurrent settles never double-credit** | `FOR UPDATE` row locks + unique receipts; losers throw caught 23505 |
| **Duplicate webhook is a no-op** | durable inbox `ON CONFLICT DO NOTHING` + replay guard |
| **Wrong amount/currency is rejected** | `price_minor !== amount` guard; order never settles |
| **Crash after provider success is recoverable** | `DurableWorkflow` re-creates only disposable delivery commands |
| **Paid-but-not-delivered is alerted** | `ConsistencyChecker.deliveryGaps()` → deduped Ops op + admin email |
| **Provisioning retry never duplicates** | deterministic `zb_<sub>` usernames; leases + `max_attempts` escalation |
| **Ledger always balances** | double-entry with both legs in one transaction |

### Fault-test coverage

The `tests/*_faulttest.php` harnesses verify against prod DB using synthetic
entities (no real money, no provisioning):

- `worker_event_faulttest` — orphan webhook event acked, no retry loop
- `outbox_cycle_faulttest` — `payment.event.process` completes in one attempt
- `orphan_event_faulttest` — paid orphan acked processed, no dead-letter
- `daily_charge_faulttest` — daily auto-charge idempotent, grace on shortfall
- `PostgresConcurrencyTest`, `StateMachineTest`, `AutoRenewTest`, … (PHPUnit)

---

## Security posture

- **Secrets at rest**: encrypted in PostgreSQL; master key in `var/master.key`
  (separate volume, never in Git, never in the image).
- **Role separation**: runtime DB role cannot `UPDATE` ledger, `DELETE` audit,
  or `CREATE TABLE` (verified → SQLSTATE 42501).
- **Immutable audit**: every money/service mutation logged with actor, old/new
  state, correlation id.
- **2FA**: required for all admin sections; backup codes; 15-min approval TTL.
- **Webhook tamper detection**: same event id + different payload → `processed=2`,
  Ops alert, admin email; money untouched.
- **No secrets in repo**: `.gitignore` excludes `.env`, `infra/*.creds`,
  `infra/*.htpasswd`, `cookies.txt`, and operational artifacts.

---

## Versioning

- **Current**: 1.1 — Platega-only, single runtime container, hardened settlement.
- **Changelog**: see [CHANGELOG.md](CHANGELOG.md).
- **Roadmap notes**: retained only in product scope — no legacy provider
  (FreeKassa/YooKassa/Laravel) is part of the current runtime.
