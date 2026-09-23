# Contributing

ZeleBoba processes payments and subscription rights. Changes to billing,
payments, provisioning, or authentication must include focused tests.

## Local checks

```sh
bundle install
ruby -Itest test/ruby_port_test.rb
scripts/e2e_ruby.sh
```

Use a separate PostgreSQL database for any production-like integration test.
Do not commit secrets, cookies, payment identifiers, or customer data.

## Pull requests

- Keep changes scoped to the intended behavior.
- Add or update tests for billing invariants and idempotency.
- Run the Ruby unit suite and E2E scenario.
- Document operational changes in the pull request.
