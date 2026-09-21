# Security Policy

ZeleBoba handles **real money** and **customer data**. We take security seriously
and follow the same discipline as payment platforms that move billions.

## Supported versions

Only the latest release on the `main` branch is supported. Security fixes are
backported to the current release only.

| Version | Supported |
|---|---|
| 1.1 (current) | ✅ |
| < 1.1 | ❌ |

## Reporting a vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Instead, report privately so we can fix and release before disclosure: open a
**private vulnerability report** on this repository (GitHub → Security →
"Report a vulnerability"), or contact the maintainers directly.

We aim to respond within **48 hours** and publish a fix as soon as a
reproducible case is confirmed.

### What we handle as a vulnerability

- Funds loss, double-credit, or payment replay
- Unauthorized access to admin or user accounts
- Credential / secret disclosure
- Audit-trail tampering
- Remote code execution or injection
- Any issue that can lead to a customer being charged incorrectly

### Non-priority (please report anyway)

- Cosmetic UI bugs
- Missing rate limits (worth reporting, but not critical on their own)

## Security design notes

- **Exactly-once settlement** — advisory locks + unique constraints prevent
  double-credit even under concurrent/duplicate webhooks.
- **No secrets in the image or web root** — keys are encrypted at rest in
  PostgreSQL; the master key lives on a separate volume.
- **Role separation** — the runtime DB role cannot rewrite ledger, receipts,
  or audit (verified via SQLSTATE 42501).
- **Webhook tamper detection** — same event id + different payload is treated
  as a potential attack and surfaced to ops with money untouched.
- **Immutable audit** — every money/service mutation is logged with actor,
  old/new state, and correlation id.

If you find a way to break any of these, that's a critical report.

## Disclosure

We follow **responsible disclosure**:
1. Reporter privately contacts us.
2. We confirm the issue and reproduce it.
3. We ship a fixed version.
4. The issue is publicly disclosed after a reasonable window.

Thank you for helping keep ZeleBoba production-safe.
