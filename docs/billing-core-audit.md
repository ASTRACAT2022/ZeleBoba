# Billing Core audit — 2026-09-19

## Scope

The target flow is: an active subscription is extended after an authoritative
gateway confirmation; payment, order and entitlement commit locally; a
Remnawave failure is retried without reversing money; recovery reconciles the
same `expires_at`; repeated deliveries do not change the result.

## Verified locally

| Check | Result |
|---|---|
| Fresh schema migration | PASS (SQLite smoke migration) |
| Calendar-period policy | PASS (`2026-01-31 + 1 month = 2026-02-28`) |
| 20 duplicate event deliveries | PASS: 1 `payment_events`, 1 `payments`, 1 subscription, 2 ledger rows |
| Repeated settlement | PASS: receipt uniqueness preserves one entitlement |
| Provisioning failure | PASS: `provisioning_accounts.state` becomes `retry`; no money mutation |
| Dead outbox recovery | IMPLEMENTED: reconciler emits a new provision/extend command for `retry` or `failed` accounts |

The automated versions of these checks are in
`tests/BillingCoreArchitectureTest.php`.

## Fixes made during audit

1. A failed provisioning/extension job now writes `retry` (or `failed` after
   the retry budget), class-only error data, and timestamp to
   `provisioning_accounts`.
2. A successful remote extension makes its paid renewal order `fulfilled` and
   marks the provisioning account `active`.
3. Reconciliation recovers retry/dead provisioning accounts, including active
   subscriptions whose renewal sync had failed.
4. Existing subscriptions are backfilled into `provisioning_accounts` by
   migration `020_billing_core.sql`.

## Not certified yet

PostgreSQL integration, forked concurrency, process-kill recovery and the
actual Remnawave HTTP contract could not be executed in this workspace:

- the Docker daemon is not running;
- no PostgreSQL service is reachable;
- Composer dependencies cannot be downloaded because DNS access to Packagist/
  GitHub is unavailable.

MariaDB is **not a supported production backend**. `Database` deliberately
uses PostgreSQL-only locking/`SKIP LOCKED`/advisory locks and treats the only
non-PostgreSQL backend as development SQLite. A MariaDB pass must not be
claimed without a dedicated SQL dialect and lock implementation.

## Release gate

Do not call this core production-certified until all items below are green on
an isolated PostgreSQL 17 database with real dependencies installed:

```sh
composer install --no-interaction --prefer-dist
TEST_POSTGRES_DSN='pgsql:host=127.0.0.1;port=5432;dbname=astracat_audit' \
TEST_POSTGRES_USER=astracat_audit \
TEST_POSTGRES_PASSWORD='...' \
vendor/bin/phpunit --filter 'PostgresConcurrencyTest|BillingCoreArchitectureTest|RemnawaveSyncTest'
```

Add a kill/restart test that terminates a worker after the remote PATCH but
before outbox acknowledgement. Its retry must PATCH the same absolute
`expires_at`, then reconciliation must report zero drift. Run `php bin/console
billing:audit` before and after the test and require zero violations.
