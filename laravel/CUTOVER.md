# Laravel cutover runbook

The Laravel application deliberately reads the existing shared database and `zb_session` cookie. Do not run a parallel schema reset, and do not switch the primary Nginx upstream until a rehearsal has passed.

1. Back up PostgreSQL and record the restore command; test a restore on an isolated host.
2. Deploy the image with `APP_ENV=production`, `APP_DEBUG=false`, PostgreSQL settings, a non-demo payment driver, queue worker, scheduler, and provider sandbox credentials.
3. Run `php artisan cutover:check --production`, then `php artisan test` against an isolated database.
4. Rehearse registration, legacy-session login, YooKassa and FreeKassa order/topup callbacks, balance settlement, provisioning, trial, and renewal. Confirm each provider callback is reachable at `/webhooks/...`.
5. Keep the old upstream available for instant rollback. Change only the reverse-proxy upstream to Laravel's `/app/public`; do not change the database or delete the old deployment during the first release.
6. Monitor `GET /up`, queue failures, scheduler output, provider pending payments, balance/transaction totals, and provisioning failures. Reconcile unresolved pending payments before finalizing the switch.

`subscriptions:process` runs every minute in the supplied scheduler service. It expires overdue subscriptions and renews eligible subscriptions from wallet balance. Provider callbacks settle orders and topups only after provider-side verification.

The production compose file is intentionally bound to loopback (`127.0.0.1`) so a host reverse proxy can perform TLS termination. Expose it publicly only through a configured TLS proxy.
