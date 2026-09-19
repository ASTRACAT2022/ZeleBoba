# Critical-processing audit and implementation plan

## Current architecture

ZeleBoba is a PHP 8.4 application using Symfony HTTP/Console components,
Twig, PDO, PostgreSQL in production and SQLite for development/tests.

| Area | Current durable mechanism |
| --- | --- |
| Checkout and orders | `BillingService::order()` stores order, order item and `payment.create` outbox command in one DB transaction. |
| Provider confirmation | `PaymentService` treats webhooks as hints, performs provider API verification, and `BillingService::settle()` writes receipt, payment, balanced accounting entries, order/subscription state and provisioning outbox work in one transaction. |
| Webhook intake | `payment_events` is a durable inbox with `UNIQUE(provider, provider_event_id)`; `PaymentEventStore` inserts it and its outbox command before HTTP acknowledgement. |
| Queue | `outbox` has a unique dedup key, leased processing, retry with exponential backoff plus jitter, and durable `dead` state. |
| Provisioning | `provisioning_accounts` has unique `(subscription_id, provider)` and the worker/reconciler restore pending/retry/failed work. |
| Wallet and core ledger | All money is integer minor units. `transactions` projects wallet balance; paid orders get two balancing `ledger_entries` protected by unique `(order_id, account)`. |
| Creator money | `creator_commissions`, `creator_ledger`, unique payment commission and unique milestone award protect the Creator flow; consistency checks now detect ledger/commission drift. |
| Recovery / observability | `Reconciler`, `ConsistencyChecker`, `operations`, `operation_events`, audit log, OpenTelemetry wrapper and worker/scheduler heartbeats. |

## Confirmed gaps and risks

1. `payment_events` records a completion timestamp but has no claimed/processing state, attempt count, lease or explicit state machine. A manually duplicated consumer could verify the same event in parallel.
2. Payment verification has no persisted `unknown` state. A provider timeout safely retries through outbox today, but it is not visible or modelled as an operational state.
3. State transitions are partly guarded by conditional SQL, but payment, webhook-inbox and provisioning rules are not described by one reusable transition policy.
4. The current reconciler performs sound recovery of known local work, but needs explicit checkpointed reports and an admin reconciliation view for payment/inbox/provisioning/financial mismatch categories.
5. `PaymentService::createOrder()` calls an external provider before persisting its answer. YooKassa uses a stable idempotence key, but every provider must be audited for an equivalent stable external request key before it is production-enabled.
6. PostgreSQL concurrency tests are skipped unless `TEST_POSTGRES_DSN` is configured; production race guarantees need that dedicated CI database.

## Financial and business invariants

* A `(provider, provider_payment_id)` identifies at most one local payment and one receipt.
* A paid order has exactly two balancing `ledger_entries`.
* An outbox `dedup_key` produces at most one command record; a consumer is at-least-once and must be idempotent.
* A `(creator, payment)` creates at most one creator commission; `(creator, milestone)` creates at most one milestone award.
* Creator payout reservation is performed under the creator row lock and cannot exceed available ledger-derived funds.
* Financial records are append-only; reversals and releases are compensating ledger entries.

## Phased implementation plan

1. **Inbox and payment states** — extend `payment_events` with status, attempts, lease and next retry timestamp; introduce `pending → processing → processed | retry | dead` transitions and `payment UNKNOWN` operational reporting. Update `PaymentEventStore`, `PaymentService`, `Worker` and `Reconciler`.
2. **State transition guards** — centralize allowed transitions for payment/inbox/provisioning; retain existing conditional SQL and add test coverage for invalid transitions.
3. **Reconciliation reporting** — add an append-only reconciliation run/report model; scan bounded batches and expose counts for payments, inbox, provisioning, referral and creator issues. Obvious recoveries enqueue the existing idempotent commands; ambiguous ones open `operational_cases`.
4. **Provider-create safety** — verify that every enabled provider sends a stable idempotency key. Mark providers lacking it unavailable for production checkout until adapted.
5. **Fault-injection and concurrency** — add tests for duplicate webhook/event delivery, worker death before acknowledgement, reconciliation races, provider timeout/unknown and double payout; run the PostgreSQL suite in CI with `TEST_POSTGRES_DSN`.
6. **Operations UI and retention** — present reconciliation state, dead outbox/inbox events and recovery actions through existing RBAC/operations screens; define technical-data retention while retaining audit and financial records.
