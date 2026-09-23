# ZeleBoba Billing

ZeleBoba is a Ruby subscription-billing application for Remnawave VPN panels.
It provides an account area, plans, orders, a wallet, demo and Platega payment
flows, Telegram webhook handling, and asynchronous provisioning.

## Stack

- Ruby 4, Sinatra, Rack and Sequel
- PostgreSQL 17 in production, SQLite for local development and tests
- Rack server, worker and scheduler as separate processes

## Local development

```sh
bundle install
cp .env.example .env
DATABASE_DSN=sqlite:var/billing.sqlite ruby bin/console-ruby db:migrate
DATABASE_DSN=sqlite:var/billing.sqlite ruby bin/console-ruby db:seed
DATABASE_DSN=sqlite:var/billing.sqlite bundle exec rackup --host 127.0.0.1 --port 9292 config.ru
```

Run background processes in separate terminals with the same `DATABASE_DSN`:

```sh
ruby bin/worker-ruby
ruby bin/scheduler-ruby
```

## Verification

```sh
ruby -Itest test/ruby_port_test.rb
scripts/e2e_ruby.sh
```

The E2E scenario uses its own temporary SQLite database and checks
registration, checkout, demo payment, provisioning, and wallet top-up.

## Docker deployment

Set `APP_URL`, `DATABASE_PASSWORD`, and `DATABASE_MIGRATION_PASSWORD` in
`.env`, then run:

```sh
docker compose build
docker compose up -d db
docker compose run --rm migrate
docker compose up -d app worker scheduler
```

The application listens only on loopback by default. Terminate TLS in a
separate reverse proxy and forward the original host and HTTPS headers.

## Layout

```text
ruby_app/       Ruby domain, web, integration and infrastructure code
ruby_app/views/ ERB templates
public/         Static assets
migrations/     Shared SQL schema migrations
test/           Minitest suite
bin/            Console, worker and scheduler entry points
```
