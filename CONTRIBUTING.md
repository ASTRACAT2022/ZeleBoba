# Contributing to ZeleBoba

Thanks for wanting to help make ZeleBoba more reliable. This is a
**money-handling system**: changes to the payment or billing path have real
consequences, so we gate them with the same rigor as a payment platform.

## How to contribute

1. **Open an issue** first for anything touching payments, settlement,
   provisioning, or security. Discuss the approach before writing code.
2. Fork the repository.
3. Create a branch: `fix/<short-description>` or `feat/<short-description>`.
4. Write your change with tests.
5. Open a pull request against `main`.

## Code standards

- **PHP 8.4**, `declare(strict_types=1)` in every file.
- **Conventional commits**: `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`.
- **No secrets, ever** — never commit keys, `.env`, cookies, panel tokens, or
  subscription URLs. The `.gitignore` covers the known ones; if you find a new
  secret pattern, add it there.
- **Money paths are transactional.** Any change to `BillingService::settle()`,
  `TopupService::settle()`, `PaymentService::verify()`, or
  `DurableWorkflow` must preserve exactly-once semantics.

## Testing

```sh
composer install
cp .env.example .env
php bin/console db:migrate          # requires a database
composer test                        # PHPUnit
php bin/console biling:audit
```

For **fault-injection** tests (money safety) set a separate PostgreSQL DB:

```sh
TEST_POSTGRES_DSN=... TEST_POSTGRES_USER=... TEST_POSTGRES_PASSWORD=... php tests/worker_event_faulttest.php
```

Every change to the payment path **must** add or update a fault test proving the
invariant still holds (e.g. no double-settle under concurrent webhooks).

## Pull request checklist

- [ ] `composer test` passes
- [ ] `composer validate --strict` passes
- [ ] `composer audit` has no advisories
- [ ] `php -l` passes on every changed file
- [ ] Fault test added/updated for payment-path changes
- [ ] CHANGELOG updated under [Unreleased]
- [ ] No secrets or operational artifacts in the diff
