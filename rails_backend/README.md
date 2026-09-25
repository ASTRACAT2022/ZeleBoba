# ZeleBoba Rails backend

Partial Rails API port for ZeleBoba. Existing frontend assets and Twig
templates are untouched. This backend is not production-ready and does not
replace the current PHP/Twig app yet.

## Ported scope

- Existing database tables are reused; no Rails-only schema is introduced here.
- Payment checkout creation for orders and topups.
- Platega checkout, webhook parsing and provider verification.
- Durable webhook inbox via `payment_events` and `incoming_webhooks`.
- Transactional outbox handlers for all topics emitted by the current PHP
  worker, including payments, wallet renewals, provisioning, gifts, Telegram
  delivery, broadcasts, and compensation jobs.
- Order settlement with provider receipt, payments row, ledger rows,
  subscription creation or renewal, provisioning queue and audit row.
- Topup settlement with exactly-once wallet credit and referral/topup outbox.
- Wallet credit/debit with transaction history and double-entry wallet ledger.
- Registration/login, expiring shared sessions, MFA/TOTP and RBAC checks for
  the admin read API.
- Password reset and Telegram login/account-link flows, plus a secret-verified
  private-chat webhook that processes bot commands and queues responses.
- Trial conversion, promocodes, internal balance refunds, referral rewards,
  referral statistics and withdrawal requests.
- Balance-funded gifts, immutable plan snapshots, gift claims and provisioning.
- Creator attribution, payment commissions, payout balances and admin payout
  processing.
- Subscription merging, auto-renew settings, scheduled renewals and daily
  balance charges.
- Campaigns, polls, contest attempts/rewards, required-channel management,
  landing discounts, reports, monitoring, and admin role assignment.
- Versioned database-backed settings with PHP-compatible encrypted secrets;
  runtime payment/provisioning/Telegram clients read the shared settings.
- Telegram send/reply and membership-check jobs, queued email delivery,
  chunked broadcasts and retryable compensation jobs.
- Basic Remnawave subscription activation/extension queue handling.

## Run locally

The current machine needs working Apple developer tools to build native Ruby gems.
If `bundle install` fails with `You have to install development tools first`
or `You have not agreed to the Xcode license`, run this in a normal Terminal,
then rerun Bundler:

```sh
sudo xcodebuild -license
cd rails_backend
bundle install
```

Then:

```sh
cd rails_backend
bin/rails routes
bin/rails runner 'puts Rails.application.class.name'
bin/rails zeleboba:run_one
```

For PostgreSQL, provide `DATABASE_URL` or the `DATABASE_HOST`,
`DATABASE_NAME`, `DATABASE_USER`, and `DATABASE_PASSWORD` environment
variables. The schema must be the existing ZeleBoba schema.
The Compose Rails API image uses Ruby 4.0.5 from `Gemfile.lock` and reads the
same shared database and master-key volume as PHP. Set `RAILS_SECRET_KEY_BASE`
to a generated Rails secret before starting the `rails` Compose profile.
MFA decryption must use the same 32-byte key as the PHP app, through
ZELEBOBA_MASTER_KEY_FILE or ZELEBOBA_MASTER_KEY_BASE64. Install libsodium in
the runtime image before Bundler (Homebrew package libsodium on macOS, or the
distribution's libsodium development package on Linux); RbNaCl needs it to
keep PHP/Rails MFA secrets interoperable.

Operational settings are read from the shared `app_settings` table and take
precedence over environment variables, matching the PHP app. Secret values use
the same authenticated encryption format and master key. Admin endpoints are
`GET/POST /rails/admin/settings` and `POST /rails/admin/settings/check/:integration`;
the latter probes Platega, Remnawave, or Telegram. The authorized Telegram
integration check can register its webhook at `/rails/telegram/webhook`; first
route HTTPS requests there and verify the configured `APP_URL` and webhook
secret. Production readiness requires an HTTPS Telegram API base and bot
configuration.
Rails-only outbox topics (`subscription.remove` and channel-membership
verification) are disabled unless `RAILS_ONLY_TOPICS_ENABLED=1`. Before
enabling them, stop the PHP worker, then start the optional `rails-worker`
Compose profile with `RAILS_OUTBOX_WORKER_ENABLED=1`. The default Compose
profile leaves Rails API and worker stopped. PHP-FPM keeps serving the current
pages while `RUN_PHP_OUTBOX_WORKER=0` and `RUN_PHP_TELEGRAM_POLL=0` disable
the old background roles during a controlled cutover.

Rails recurring mail delivery and expired session/token cleanup are disabled
unless `RAILS_SCHEDULER_ENABLED=1` is set on the Rails worker. Before enabling
it, disable PHP `billing:reconcile` with `RUN_PHP_SCHEDULER=0` and wait for its
active run to finish. Both schedulers use the shared `reconcile` lease as a
second guard, but the flag does not stop PHP. `zeleboba:flush_mail` uses the
same lease and flag; do not run an independent mail flusher during cutover.

## Cutover rule

The worker tasks consume the shared outbox and can trigger real payment or VPN
provisioning effects. In production they require RAILS_OUTBOX_WORKER_ENABLED=1.
Do not route production traffic to this backend yet. Customer and admin pages
still use PHP/Twig routes and contracts, and cutover, worker concurrency,
database-backed checks, backup restore and rollback need rehearsal. Compose
starts Rails API only with `--profile rails`; Nginx proxies `/rails/*` when it
is available. The live Platega webhook, existing HTML routes and the shared
outbox worker remain on PHP. See
docs/rails-migration-status.md for the complete cutover blockers.
