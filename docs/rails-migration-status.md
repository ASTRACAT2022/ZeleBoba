# Rails migration status

Date: 2026-09-25

## Implemented in this slice

- Rails API reuses the current PostgreSQL schema and leaves UI assets and Twig
  files unchanged.
- Email registration/login, legacy Django password verification with Argon2id
  upgrade, shared expiring sessions, MFA/TOTP with compatible
  XChaCha20-Poly1305 secret storage, and recovery codes.
- Password resets, Telegram browser login/magic links/account linking, and
  private Telegram bot updates. The Telegram webhook validates its secret,
  deduplicates update IDs, and queues replies and channel-membership checks.
- SMTP queue persistence with bounded retries and the `zeleboba:flush_mail`
  task; message delivery stays outside request/webhook processing.
- User-owned order/topup APIs, Platega checkout/webhook/verification,
  idempotent payment settlement, wallet ledger, and protected cookie requests.
- An opt-in PHP presentation gateway can forward the existing order, topup,
  wallet purchase, renewal, gift purchase, and referral withdrawal forms to
  Rails while retaining the current Twig pages and redirects. It is
  disabled by default with `RAILS_WRITE_GATEWAY_ENABLED=0` until shared-database
  behavior is verified.
- RBAC permission lookup and MFA-gated admin read endpoints for overview,
  users, and plans; selected admin actions for access control, refunds,
  promocodes, and referral withdrawals.
- Remnawave API client and subscription activate/extend/traffic outbox handling.
- Trials, promocodes, internal balance refunds, and core referral code,
  commission, stats, and withdrawal flows.
- Gift purchase from wallet balance, buyer/recipient history, deep-link/code
  parsing, and idempotent claim that provisions a subscription from the stored
  gift entitlement snapshot.
- Creator attribution capture and registration attachment, payment commissions
  with hold periods and milestone awards, payout ledger reservations, admin
  payout processing, and reconciliation entry point.
- Subscription merging with resource transfer, hard deletion of source state,
  durable target sync, and best-effort source disable; balance auto-renew,
  daily wallet renewal, scheduling, and user opt-in controls.
- Manual renewal checkouts now reserve the subscription's renewal slot;
  settlement recalculates the next renewal time and clears failure state.
  Initial daily subscriptions opt into the configured auto-renew flow, and
  canceled renewal orders release their slot through bounded reconciliation.
- Advertising campaigns with admin create/list/toggle APIs, start-parameter
  registration, idempotent bonus grants and registration-time attachment.
- Poll creation/listing and one-time user submissions with wallet rewards;
  contest template/round administration, attempt limits and prize processing
  with wallet, subscription-day, or traffic awards.
- Required-channel administration, landing-page management, sales reports,
  monitoring event/error views, and error clearing.
- Admin role creation/assignment/revocation and paginated audit-log API.
- Admin maintenance mode status/toggle API with an audit entry; purchase
  creation now respects the shared `MAINTENANCE_MODE` setting.
- Existing referral-withdrawal requests with no prior wallet reservation are
  debited exactly once when approved or paid.
- Read-only operation investigations for historical user state, subscription
  dependency graph, purchase simulation, and service blast radius; audited
  global safety mode and scheduled maintenance windows.
- Durable investigation list/start/detail/note/resolve APIs, renewal diagnostics,
  and expected-versus-Remnawave state inspection.
- Operation search/detail/support summaries, provisioning queue views/retries,
  and subscription state explanations.
- Plan creation/updates with immutable product versions; landing discounts are
  applied to order snapshots as in the existing billing flow.
- Versioned settings API with PHP-compatible encrypted-secret storage and a
  shared runtime configuration reader for payments, provisioning, and Telegram;
  read-only integration probes.
- Production outbox worker execution requires RAILS_OUTBOX_WORKER_ENABLED=1.
- The Rails worker now schedules retryable payment verification, stale webhook
  event processing, and provisioning recovery under a shared database lease.
  Transactional email and expired-token cleanup run only when
  RAILS_SCHEDULER_ENABLED=1 and use PHP's `reconcile` lease.
- Readiness checks database schema and production payment, provisioning,
  PostgreSQL, and master-key configuration.

## Production status: blocked

This remains a partial migration. Do not route production traffic to Rails or
enable it as the only worker.

Blockers:

- Customer and admin pages are server-rendered by PHP/Twig. Rails exposes a
  JSON API and does not serve the same routes and page contracts. No frontend
  files were changed, so the existing pages are not wired to Rails.
- Broadcast and compensation processing and selected admin APIs are ported;
  their PHP/Twig pages remain on the current app.
- Backup creation supports Rails deployment overrides, but restore remains an
  operator action; production backup and isolated restore rehearsal are still
  required. Some PHP admin actions/pages and reconciliation controls are not
  yet available through Rails APIs.
- Rails exposes JSON endpoints while the current application serves HTML from
  PHP/Twig on existing public URLs. UI files were left unchanged, so production
  traffic cannot be switched to Rails until routing preserves those page
  contracts or the PHP frontend is safely connected to the Rails APIs.
- Compose can build a separate Rails API process behind the explicit `rails`
  profile, and Nginx forwards `/rails/*` when it is running. The live
  `/webhooks/platega`, legacy HTML routes, and shared outbox worker remain on
  PHP; Rails worker cutover is not enabled. The isolated
  `/rails/webhooks/platega` endpoint exists only for staged verification.
- Telegram webhook registration must be explicitly performed through the
  authorized integration check after DNS/HTTPS routing is ready. Production
  readiness also requires an HTTPS app URL and a valid webhook secret.
- The Rails worker now has handlers for every topic in the current PHP worker,
  including checkouts, payments, wallet renewals, provisioning, referral
  rewards, gifts, Telegram delivery and membership checks, broadcasts and
  compensation batches.
  Runtime parity and concurrent-claim behavior still require shared-database
  verification before either worker is disabled.
- Rails scheduled maintenance now records financial consistency checks,
  delivery-gap alerts, subscription expiry repair, and a gated forward
  Remnawave drift scan. Reverse import of panel users missing locally exists
  as an explicit dry-run service, with writes gated by `reconciliation.auto_heal`;
  it is not part of automatic maintenance. Some PHP reconciliation details
  remain absent. Shared-database tests are required before disabling the PHP
  scheduler.
- Authorized admins can preview or explicitly run the reverse import through
  `POST /rails/admin/remnawave/import`; writes still require `fix=1` and the
  shared `reconciliation.auto_heal` flag. Results include `next_page` for
  bounded follow-up batches using `start_page`.
- Rails also emits `subscription.remove` and `telegram.membership_check`, which
  the deployed PHP worker does not know. Drain and stop PHP workers before
  Rails writes these topics to the shared outbox, or update PHP workers first.
- Referral commissions are connected to topup settlement, but referral code
  attachment and all referral edge cases need database-backed parity checks;
  admin withdrawal permissions and policy also need reconciliation with the
  deployed RBAC configuration.
- Full Rails boot and database-backed verification have not run. Gemfile.lock
  was generated with Ruby 4.0.5 and Bundler 4.0.11; deployment must use a
  compatible runtime or regenerate the lockfile on the selected production
  Ruby. `bundle check` reports missing native gems; the earlier local install
  stopped at the Xcode license gate.
- No staged shadow run, production database restore rehearsal, or rollback
  rehearsal has been completed.
- The operator backup script now supports explicit Rails service/database/key
  overrides and includes the Rails lockfile when present. Production-compatible
  archive creation and isolated restore verification have not been rehearsed;
  restore remains an operator-only action against a newly created database.

## Checks completed

- Ruby syntax parsing passed for all 169 Rails .rb files after the latest edits.
- PHP syntax parsing passed for all files under `src`, `public`, and `tests`.
- Static route/action scan passed for all 153 Rails route targets.
- `docker compose config --quiet` passed with the default and both Rails
  profiles. The PHP supervisor fault harness passed for normal startup,
  worker failure, clean shutdown, and PHP-FPM-only operation. Nginx syntax
  passed `nginx -t` in the local Nginx image with the Compose `app` hostname
  supplied. Rails image build was retried, but the Docker Hub token request
  ended with EOF before any Dockerfile step ran.
- Gemfile.lock dependency resolution completed.
- `bundle check` cannot pass because Rails dependencies including pg, puma,
  argon2, rbnacl and sqlite3 are not installed. The full Rails app has not
  booted and no database-backed request/service tests have run.
- Local `composer test` could not start because this checkout has no
  `phpunit` executable in its installed Composer dependencies.
- Backup script syntax and argument validation passed. A real backup, SMTP
  delivery, Platega call, Telegram webhook registration or Remnawave operation
  was not run.

## Cutover gates

1. Finish Rails parity for all PHP routes and outbox topics while preserving
   the existing page behavior and visual assets.
2. Build the pinned Ruby 4.0.5 image or install the locked gems locally with
   working developer tools, boot Rails, and run request/service checks on
   SQLite development and PostgreSQL.
3. Run shadow traffic and compare payment, wallet, subscription, and panel
   state against the current app.
4. Rehearse backup restore and rollback, then switch traffic and workers in a
   controlled deployment.
