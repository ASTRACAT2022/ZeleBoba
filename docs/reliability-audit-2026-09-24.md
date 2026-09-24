# Reliability audit — 2026-09-24

## Scope and evidence

The repository has 81 PHP files in `src` and 587 named function declarations. Every
source file passed `php -l`. The PHPUnit suite exercises 264 tests with SQLite;
one PostgreSQL test is skipped without `TEST_POSTGRES_DSN`. Syntax and passing
tests do not establish that every function is correct. This review traced the
critical paths for payment settlement, wallet credit and debit, compensation,
promotions, contests, polls, campaigns, admin grants, outbox delivery, VPN
provisioning, and Remnawave reconciliation.

## Corrected in this audit

| Area | Failure | Correction |
| --- | --- | --- |
| Promo codes | A combined code could grant days and then fail traffic validation, leaving a partial bonus. | Apply all effects in a savepoint and roll back together. |
| Remnawave sync | Order snapshots could overwrite purchased traffic or device add-ons; unlimited device limit `0` became `1`. | Derive desired limits from the current subscription and retain `0`. |
| Remnawave HTTP | Failed PATCH/DELETE responses could be treated as successful; a timed-out create recovered an existing user without updating changed terms. | Require a 2xx response and reconcile recovered users with current terms. |
| Provisioning queue | Traffic/device jobs could finish before the remote account existed. | Defer them until provisioning has produced the remote account. |
| Contests | A winner could be recorded without an eligible subscription or a valid legacy prize. | Validate the prize and eligibility before the draw; grant traffic through a durable outbox job. |
| Polls | An arbitrary answer could receive a reward. | Require a stored option and validate question/option input. |
| Campaigns | Unsupported or unconfigured bonuses could be marked granted; a new subscription could appear active before VPN creation. | Validate bonus configuration and create a provisioning subscription with a durable job. |
| Admin day grants | New grants appeared active before provisioning; extending a pending grant changed it to active. | Require an active plan and preserve `provisioning` until the worker succeeds. |
| Admin edits | Traffic, device and expiry edits could silently fail to reach the panel; traffic edits omitted purchased add-ons. | Queue an idempotent absolute-state sync in the same transaction as each edit; include add-ons and support legacy panel IDs. |
| Reverse import | A failure creating the provisioning account could leave a subscription only half imported; a later page failure returned a partial list as complete. | Make both inserts atomic and fail the import when any page fails. |

## Remaining release checks

1. Run the PostgreSQL concurrency suite with `TEST_POSTGRES_DSN` and test worker
   death after a successful remote PATCH but before outbox acknowledgement.
2. Verify the live Remnawave API contract for create, PATCH, DELETE and list
   pagination in a staging panel. Mock HTTP tests cannot certify its real schema.
3. `UserAdminService::resetSubscriptionTraffic()` updates local usage but has no
   verified remote reset operation. It must not be treated as proof that panel
   usage was reset; implement and test the panel's documented reset endpoint.
4. Investigate dead `subscription.admin_sync` jobs in operations. The outbox
   retries transient errors eight times, then retains them as `dead` for manual
   recovery; no automatic replay exists for this new topic.
5. Extend deep review to the remaining lower risk display, settings and
   reporting functions. The file-wide syntax pass is not a behavioral audit of
   every one of the 587 functions.

The test suite is the local release gate. A passing SQLite run alone does not
certify production concurrency or external service behavior.
