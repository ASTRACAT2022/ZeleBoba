# Laravel rollback procedure

Do not drop legacy tables, stop legacy workers permanently, or make destructive
schema changes before final cutover approval.

1. Freeze Laravel ingress and pause Horizon workers. Preserve Redis queues and
   Laravel logs for investigation; do not blindly replay jobs.
2. Switch the reverse proxy upstream back to the retained legacy deployment.
   Confirm login, payment callbacks and provisioning routes are served there.
3. Record the release commit, time window, affected payment/subscription IDs,
   queue job IDs and provider request IDs. Reconcile those IDs before any queue
   replay to avoid duplicate side effects.
4. Keep the shared database. Laravel migrations must be additive and its data
   is retained for audit. Restore a database backup only after incident-command
   approval; it can otherwise discard valid payments.
5. Run legacy billing reconciliation and compare orders, receipts,
   subscriptions, remote resources and timeline events created during the
   window. Open incidents for non-deterministic mismatches.
6. Resume only the necessary legacy workers after reconciliation. Keep Laravel
   paused until a corrected release passes a new rehearsal.

Rollback is incomplete until the traffic switch, provider callback ownership,
and side-effect reconciliation are documented in `rollback.json`.
