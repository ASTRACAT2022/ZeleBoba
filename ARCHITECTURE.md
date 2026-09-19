# ASTRACAT Billing invariants

1. Money has an immutable trace: payment, ledger entries and audit context are never edited in place.
2. A provider payment can settle only one order and can extend rights only once.
3. Webhook delivery is an inbox write, not a provisioning command; duplicate deliveries are safe.
4. A Remnawave outage cannot prevent a verified payment from becoming `succeeded` or from extending `subscriptions.expires_at`.
5. Billing owns purchased rights. Remnawave is a provisioning adapter, never the source of subscription truth.
6. `subscriptions.expires_at` is the only business expiry timestamp. Calendar-month arithmetic is owned by `SubscriptionService`.
7. Reconciliation detects and retries Billing ↔ provider drift.
8. A Telegram numeric ID is an external `user_identity`, not a user primary key. Usernames are never identities.
9. Administrative changes require an immutable audit record; structured reason/context belongs in `audit_context`.
10. Web, Telegram, and admin call domain services; no client directly changes a subscription.
11. Every interface reads the same subscription state and expiry date.

## Delivery path

`gateway webhook → payment_events → outbox → verified provider lookup → payment/order/subscription/ledger transaction → outbox → provisioning_accounts → Remnawave`

PostgreSQL is durable state. Redis, if introduced, may be used only for cache, queue acceleration, rate limits, and locks.
