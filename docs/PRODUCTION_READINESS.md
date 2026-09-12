# Production readiness verdict

## NOT READY

Production traffic must remain on the legacy runtime. Laravel may be used only
for isolated development, rehearsal, or shadow work until every P0 item below
is closed with release-bound evidence.

| Severity | Subsystem | Reason | Done | Required to close |
|---|---|---|---|---|
| P0 | Payment parity | Only YooKassa and FreeKassa are ported; 24 legacy providers have no Laravel contract or approved deprecation | Verified callbacks and idempotent settlement for two providers | Provider usage classification, migrate all ACTIVE providers, sandbox E2E traces and `payments.json` |
| P0 | Provisioning | No Remnawave sandbox E2E or full lifecycle implementation | Deterministic username and retry-safe lookup exist | Test endpoint, token, squad, create/update/suspend/delete E2E and `provisioning.json` |
| P0 | RBAC/audit | Core permission middleware and immutable append-only audit schema exist, but all admin endpoints are not migrated | Granular permission enforcement and audit tested for user-block action | Migrate remaining admin mutations, PostgreSQL runtime privilege verification, `rbac.json` |
| P0 | Queue | Horizon is installed and production Compose supplies Redis, but no live Redis/Horizon recovery rehearsal exists | Cutover rejects non-Redis queue; Horizon runs dedicated priority queues | Deploy staging Redis/Horizon, perform restart/failure recovery test and record `queue-recovery.json` |
| P0 | Reconciliation | Laravel reconciliation engine is absent | Legacy reconciler remains active | Implement/test safe repair and incident flow |
| P0 | Restore/rehearsal | No restored production clone or staging credentials | Fail-closed evidence validation | Restore rehearsal, sandbox E2E, rollback rehearsal and evidence files |
| P0 | Infrastructure | No target TLS/DNS/monitoring/alerting credentials or deployment target | Health endpoints and production gate exist | Production-like staging, OTel/SigNoz, alerts, TLS and release rehearsal |
| P1 | Static analysis | PHPStan/Larastan is not installed in the Laravel dependency set | Pint and PHPUnit run; Composer audit has no known advisories | Add Larastan configuration and fix reported issues without blanket ignores |

Evidence files are never a substitute for tests. For the exact release commit,
write one JSON file per required section to `laravel/storage/app/cutover/`, for
example:

```json
{"status":"passed","commit":"<git-sha>","tested_at":"2026-09-12T12:00:00Z","environment":"staging","operator":"release-engineer","reference":"CI/job-or-ticket"}
```

`php artisan cutover:check --production` rejects missing, malformed, failed or
commit-mismatched evidence. It also rejects demo payments, non-Redis queues,
missing Horizon and unsafe application configuration.
