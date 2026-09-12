# Laravel deployment runbook

1. On the target host, copy `laravel/.env.production.example` to
   `laravel/.env`, set every required secret and hostname, and protect that
   file with mode `0600`. Do not commit it.
2. Deploy a candidate image without switching the public upstream. Use
   PHP-FPM plus Nginx; never `artisan serve`.
3. Configure external PostgreSQL and Redis with TLS where supported. Set
   `APP_ENV=production`, `APP_DEBUG=false`, `QUEUE_CONNECTION=redis`, secure
   cookies, provider secrets via the deployment secret store, and a fixed image
   digest.
4. Run `./bin/release-check`. It validates Compose, builds the image, applies
   additive Laravel migrations, and refuses a cutover that has missing
   evidence or unsafe configuration. Keep the
   legacy runtime DB role and schema intact.
5. Start Nginx, PHP-FPM, Horizon supervisors and the scheduler with
   `docker compose -f compose.production.yaml up -d`. Confirm
   `/health/live` and `/health/ready`; readiness must include reachable
   PostgreSQL and configured queue infrastructure.
6. A `cutover:check` failure is a release stop,
   not a warning. Do not manufacture evidence.
7. Perform the approved staging rehearsal, then enable shadow/canary traffic.
   Keep legacy upstream immediately reversible until post-cutover
   reconciliation is clean.

Secrets must not be baked into images, committed `.env` files, logs, or
cutover evidence.
