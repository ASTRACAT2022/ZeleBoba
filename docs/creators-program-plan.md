# ASTRACAT Creators Program — implementation plan

## Architecture audit

The source of truth for subscription payments is `payments`, written atomically
by `App\Billing\BillingService::settle()` after provider verification.  The
existing `outbox` is transactional and `Infrastructure\Reconciler` is the
periodic recovery mechanism.  Authentication is provided by `Identity\Auth`,
admin access by `RbacService`, and `audit_log` is the established immutable
audit stream.

The legacy `ReferralService` pays customer-to-customer rewards from `topups`.
It must remain separate: Creators commissions are based on `payments` and
must never mutate wallet or legacy referral balances.

## Delivered first phase

1. Migration `026_creators.sql` introduces dedicated partner, attribution,
   commission, immutable ledger, milestone, payout, fraud and reconciliation
   tables. Monetary values are `*_minor BIGINT`; commission and milestone
   uniqueness are enforced by the database.
2. `CreatorService` owns attribution, payment eligibility, immutable ledger
   records, milestone evaluation, release/reversal, payout reservation and
   reconciliation. It is invoked from the already verified payment settlement
   transaction and can be safely retried.
3. A 30-day signed-server attribution cookie is captured from `?ref=CODE`;
   registration consumes it once. Existing customer attribution is never
   overwritten.
4. The scheduler promotes held commissions and reconciles any successful
   payment that did not receive a commission. Admin visibility is exposed at
   `Admin → Creators` and `Creators → Reconciliation`.

## Follow-up phases

* Move policy editing (commission rules, milestones and hold period) from the
  partner record into a dedicated policy form with versioning.
* Add provider-specific refund event ingestion to call `reversePayment()`.
* Add reviewed fraud heuristics and Prometheus/OpenTelemetry metric export.
* Add browser and PostgreSQL concurrency integration tests to the full test
  matrix before production activation.
