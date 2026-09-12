# Laravel migration inventory and cutover gate

Generated from the repository on 2026-09-12. This is an evidence ledger, not
a claim that the Laravel application is production-ready. Legacy PHP remains
the production runtime until every row marked **Missing** or **Partial** has a
tested replacement or an approved deprecation decision.

## Cutover status normalization

The detailed runtime table below is the implementation inventory. The following
mapping supplies the mandatory cutover status and priority for every listed
area; no provider/module is implicitly deprecated.

| Area | Status | Priority |
|---|---|---|
| Public web, auth/session, billing, webhooks, subscriptions, provisioning, queues, wallet/topups, Telegram, admin, timeline, monitoring, Docker | PARTIAL | P0 |
| Payments | PARTIAL | P0 |
| RBAC, audit, transactional outbox, reconciliation, API/rate limiting, backup/restore | PARTIAL | P0 |
| Promo codes, referrals/withdrawals, gifts/trials, marketing | MISSING | P0 until production usage is classified; otherwise explicit DEPRECATED decision required |
| Payment providers other than YooKassa/FreeKassa | MISSING | P0 until classified ACTIVE/LEGACY_USED/DISABLED/OBSOLETE and approved |

`COMPLETE` is not assigned to a P0 feature until production verification is
recorded against the exact release commit. `DEPRECATED` requires a named owner,
usage evidence, customer-impact decision and rollback plan.

## Runtime inventory

| Area | Legacy evidence | Laravel equivalent | Status | Tests | Production verified | Blocking issue |
|---|---|---|---|---|---|---|
| Public web routes and dashboard | `src/Web/Application.php`, `src/Web/*Actions.php` | dashboard, plans, order, balance routes | Partial | feature tests | No | Most legacy routes/admin functions absent |
| Authentication/session/password reset | `src/Identity/Auth.php`, `Mfa.php`, `TelegramLogin.php` | `LegacyAuthService`, cookie/session middleware | Partial | `LegacyAuthTest` | No | MFA, recovery, Telegram auth and password reset are not ported |
| RBAC / ABAC | `RbacService.php`, `Permissions.php` | `RequireLegacyAdmin` | Missing | one legacy-admin timeline test | No | Only `role=admin`; no granular permissions/policies |
| Billing, orders, ledger | `BillingService.php`, `Wallet.php` | `OrderService`, `WalletService` | Partial | demo/verified provider tests | No | No Laravel audit/reconciliation or complete legacy parity |
| Payments (26 + demo providers) | `src/Integration/Payment/*.php`, `PaymentService.php` | YooKassa, FreeKassa, demo only | Partial | HTTP-faked YooKassa/FreeKassa tests | No | 24 legacy providers have no Laravel implementation; no sandbox proof |
| Webhooks / replay protection | `Payments.php`, provider classes | two webhook routes | Partial | signature/API-verification tests | No | Provider-specific callbacks, ordering and replay matrix incomplete |
| Subscriptions / renewal | `BillingService.php`, `AutoPurchaseService.php` | `SubscriptionLifecycleService` | Partial | indirect demo test | No | State machine is not explicit; cancellation/suspend/resume absent |
| Provisioning / Remnawave | `Provisioner.php`, `RemnawaveProvisioner.php`, `RemnawaveSync.php` | `ProvisionSubscription` | Partial | demo path only | No | No sandbox Remnawave test; no suspend/delete/reconciliation flow |
| Transactional outbox | `Outbox.php`, `Worker.php` | Laravel queue dispatch only | Missing | none | No | Laravel mutations do not write/use legacy outbox atomically |
| Queues/scheduler | `Worker.php`, `bin/console`, cron operations | queue worker, `subscriptions:process` | Partial | none | No | No Redis/Horizon, priority queues, recovery rehearsal |
| Reconciliation | `Reconciler.php`, `RemnawaveSync.php` | none | Missing | none | No | Required invariant detection/repair absent |
| Wallet/topups/autopurchase | `Wallet.php`, `TopupService.php`, `CartService.php`, `AutoPurchaseService.php` | topups/wallet only | Partial | `VerifiedTopupTest` | No | Cart intent and automatic purchase absent |
| Promo codes | `PromoCodeService.php` | none | Missing | none | No | All promo semantics remain legacy-only |
| Referrals / withdrawals | `ReferralService.php` | none | Missing | none | No | Commissions, risk scoring and payout workflows absent |
| Gifts / trials | `GiftService.php`, `TrialService.php` | trial start only | Partial | none | No | Gifts and trial conversion absent |
| Telegram / notifications | `Telegram.php`, `Mailer.php`, broadcasts | `SendTelegramNotification` job | Partial | none | No | Bot commands, callback auth and real delivery absent |
| Marketing | campaign/contest/channel/landing/broadcast services | none | Missing | none | No | Entire domain remains legacy-only |
| Admin | `AdminActions.php`, user/admin/monitoring/reporting/backup services | customer timeline view | Partial | `AdminCustomerTimelineTest` | No | Payments, resources, settings, audit, jobs and operations absent |
| Timeline | `CustomerTimeline.php`, migration 019 | customer timeline reads/writes | Partial | timeline UI and demo chain | No | Legacy history import/adapter and full event coverage unproved |
| Audit | `audit_log`, `UserAdminService.php` | none | Missing | none | No | No immutable Laravel audit record for sensitive mutations |
| Monitoring/telemetry | `Telemetry.php`, `MonitoringService.php` | health endpoints | Partial | `HealthTest` | No | No OTel/SigNoz pipeline, metrics or alerting |
| Backup / restore | `BackupService.php` | none | Missing | none | No | Laravel rehearsal on restored production copy not performed |
| API / rate limiting | `Application.php`, `rate_limits` | form routes + login throttle | Missing | none | No | No `/api/v1`, OpenAPI, error schema or broad rate limits |
| Docker deployment | root `Dockerfile`, `compose.yaml` | `laravel/compose.production.yaml` | Partial | none | No | No Postgres/Redis services, healthchecks, limits or deployment rehearsal |

## Database inventory

The legacy schema is PostgreSQL-oriented SQL in `migrations/001_initial.sql`
through `migrations/019_customer_timeline.sql`; its tables include users,
sessions, plans, orders, payment receipts, ledger, subscriptions, outbox,
audit, Telegram state, rate limits, topups, transactions, carts, referrals,
withdrawals, gifts, trials, marketing, RBAC and customer timeline. Laravel
currently reads the shared schema directly and includes only Laravel framework
cache/jobs migrations. No schema migration, reversible or otherwise, has been
applied for the Laravel migration. This protects history today but means
Laravel-only features must not be introduced by unreviewed schema changes.

## Required evidence before cutover

`php artisan cutover:check --production` fails closed unless each of these
machine-readable evidence files exists under `storage/app/cutover/`: `parity`,
`rbac`, `payments`, `provisioning`, `queue-recovery`, `restore`, `security`,
`rehearsal`, and `rollback` (each with a `.json` extension). Every file must
contain `status: "passed"`, the exact current Git commit, ISO-8601 `tested_at`,
and a non-empty `environment`. Environment flags cannot bypass this gate.

Evidence is a gate, not proof by itself. Attach provider transaction IDs, test
date, environment, operator and result to the release record; do not commit
evidence or secrets to Git.

## Current hard blockers

- Laravel supports only 2 of 26 real legacy payment providers; no provider
  sandbox credentials or successful sandbox traces were supplied.
- No sandbox/test Remnawave endpoint or credentials were supplied.
- Full legacy functional parity, RBAC, audit, reconciliation, API and admin
  migration have not been implemented.
- No production-copy backup/restore plus Laravel smoke test has been run.
- No Redis/Horizon, SigNoz/OTel collector, alerting, DNS or TLS target has been
  supplied or verified.

Therefore a production switch is currently prohibited. Keep the legacy
upstream running and use Laravel only in an isolated rehearsal/shadow role.
