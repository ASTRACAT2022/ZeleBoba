# Development & diagnostic scripts

These scripts are **NOT** part of the production runtime. They live here so
historical debugging artefacts from the FreeKassa nonce lockout incident
(2026-09) stay in the repository without polluting the main `scripts/`
directory that production backup tooling depends on.

> ⚠️ **FreeKassa был удалён из кодовой базы** (коммит `2b729a4` — остался только
> Platega). Эти `fk_*.php`-скрипты сохранены как исторический артефакт инцидента
> и не используются в текущем платёжном контуре. Не запускать их против текущего
> прода — провайдер FreeKassa больше не зарегистрирован.

## Contents

| File | Purpose |
|---|---|
| `fk_check.php` | Verify FreeKassa API key + optionally reset dead `topup.create` outbox jobs. Use inside the running app container: `php /app/scripts/dev/fk_check.php [--reset]`. |
| `fk_bal_*.php` | One-off diagnostics probing `/v1/balance` with different nonce strategies. Kept for replay if FreeKassa nonce lockout recurs. |
| `fk_verify*.php`, `fk_createtopup.php`, `fk_newtopup.php` | Manual payment verification and topup-creation probes. Use to confirm FreeKassa checkout URLs work end-to-end. |

## When to run

- Only when FreeKassa checkout starts failing (payment_unavailable flood,
  dead outbox jobs in `topic='topup.create'`).
- Always inside the running app container so `$c` (Container) is available.
- Read the script's top comment for the exact usage and prerequisites.

## When to delete

- Only after FreeKassa has been stable for >90 days AND no longer relies
  on its current nonce sequence. Most of these scripts are time-bounded
  workarounds for the `015_freekassa_nonce.sql` incident.