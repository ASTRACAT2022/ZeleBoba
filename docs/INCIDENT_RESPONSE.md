# Incident response

Use one incident owner, preserve correlation/provider IDs, and avoid manual DB
updates to financial or audit records.

| Symptom | Immediate action | Safe next step |
|---|---|---|
| PostgreSQL unavailable | Remove Laravel from readiness/traffic; pause workers | Fail over/restore under DBA control, then reconcile payments |
| Redis/Horizon unavailable | Pause ingress that enqueues critical side effects | Restore Redis/Horizon; inspect failed jobs before retry |
| Queue backlog/stuck | Stop autoscaling/replay loops | Identify queue and oldest job; replay only idempotent, reconciled work |
| Provider outage/webhook failures | Keep orders pending; do not mark paid from redirects | Use provider API reconciliation after recovery |
| Provisioning outage | Keep subscription in provisioning; do not issue a second resource | Query provider by deterministic external identity, then retry |
| High 5xx/broken release | Roll back traffic per `ROLLBACK.md` | Preserve logs/evidence and investigate before redeploy |

Escalate suspected duplicate charges, balance mutations, lost resources, audit
tampering or data loss as P0. Preserve evidence and notify the service owner
before any compensating operation.
