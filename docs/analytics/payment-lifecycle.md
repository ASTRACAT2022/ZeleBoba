# Payment lifecycle & analytics — source of truth

This document defines the canonical semantics behind the business dashboard
(`/admin/analytics`). It exists because several metrics previously measured the
wrong thing (a "provisioning loss" of 31 that was really a linkage gap; a funnel
whose stages came from different cohorts; a churn rate that ignored user-level
renewals).

Implementation: `App\Billing\RevenueInsights` (canonical logic) +
`App\Billing\AnalyticsService::dashboard()` (presentation).

## 1. Source of truth for revenue

**Revenue = confirmed successful provider payments only.**

- Subscriptions: `payments.status='succeeded'`
  AND `provider='platega'`
  AND `provider_payment_id NOT LIKE 'balance_%'` (internal wallet settlement is
  not new cash).
- Top-ups (cash): `topups.status='paid'` AND `provider='platega'`.

Never revenue:

- `pending` or `canceled` orders;
- synthetic `noncechk_*` probe orders;
- test/demo rows;
- internal balance payments (`balance_<order_id>`);
- provisioning/ledger rows (these mirror a payment, they are not a second sale).

`payment_receipts` is the confirmation record; a succeeded payment always has a
receipt (invariant, see §8).

## 2. Payment lifecycle (canonical states)

```
checkout_started            (analytics event)
order_created               orders.status=pending
payment_attempt_created     payment_attempts.status in (creating,pending,unknown)
provider_redirected         payment_attempts.redirected_at
payment_succeeded           payments.status=succeeded  (+ payment_receipts)
order_paid                  orders.status=paid
provisioning_started        provisioning_operations (operation_type=activate)
provisioning_succeeded      subscriptions.status=active / lifecycle_status=active
subscription_active         subscription usable by the client
```

Attempt terminal states: `succeeded`, `cancelled`, `failed`, `expired`. Anything
else older than `RevenueInsights::STALE_ATTEMPT_SECONDS` (24h) is a *stale
attempt* and is classified by cause (see §6).

## 3. NEW / RENEWAL / REACTIVATION / TRIAL

Classification is per confirmed payment, in this order (`classifyPayment`):

| Result | Rule |
|---|---|
| `renewal` | order linked via `orders.renewal_subscription_id`, OR linked via `subscriptions.renew_order_id`, OR the user had a live paid subscription at purchase time (within `RENEWAL_GRACE_SECONDS` = 3d). |
| `trial` | `orders.price_minor <= 400` (the 4₽ day plan). Never counted as a normal purchase. |
| `new` | the user's first confirmed commercial (non-trial) payment. |
| `reactivation` | a later purchase after a silence gap with no live paid subscription (`REACTIVATION_GAP_SECONDS` = 30d). |
| `other`/`upgrade` | later purchase while a paid subscription was still active. |

**Important:** the legacy `users.has_had_paid_subscription` flag is **never** a
classification source of truth. It was polluted by Remnawave imports and
compensation (~35× inflation observed). Classification uses the actual payment
history (`payments` + `orders` + `subscriptions.subscription_origin IN
('purchase','renewal')`).

## 4. Churn

Churn is evaluated **only** for commercial paid subscriptions
(`subscription_origin IN ('purchase','renewal')`). Imports, compensation, manual
grants and trials are excluded.

For each expired subscription, a user is "renewed" if they made **any** new
commercial purchase within the observation window after expiry (user-level, not
only the same `subscription_id`). Windows: 1d, 3d, 7d, 14d, 30d.

**NOT MATURED:** if `expires_at + window > now`, the window is not yet observable
and reports `not_matured` instead of a false 100% churn.

## 5. Provisioning health

Replaces the old single "loss" number. Split, non-overlapping, windowed:

| Counter | Meaning |
|---|---|
| `succeeded` | confirmed payment with a successful `activate` operation. |
| `pending` | subscription still in `pending`/`provisioning`. |
| `retrying` | a `pending`/`creating`/`retrying` activate operation exists. |
| `failed` | a `failed`/`failed_needs_attention` activate operation exists. |
| `missing_event` | subscription exists and is active, but no activate operation is recorded — an *operation-tracking* gap, **not** a provisioning failure. |
| `unlinked` | confirmed payment with no `subscriptions.order_id` link. The renewal path extends the existing subscription without writing `order_id`, so this is a *linkage* gap, **not** lost provisioning. |

## 6. Stale attempts

Attempts in a non-terminal state for >24h are split by cause:

- `on_cancelled_order` — the related order was canceled (abandoned checkout);
- `orphan` — the related order/topup no longer exists;
- `genuinely_pending` — a real open order still awaiting provider confirmation.

Only `genuinely_pending` warrants an operational look; the rest are expected
residue and are **not** auto-marked succeeded/failed.

## 7. Sales funnel

The funnel is **cohort-consistent**: all stages count distinct
`COALESCE(user_id, anonymous_id)` over **one time window**, clamped to start no
earlier than `tracking_started_at` (the `045_business_analytics.sql` migration
timestamp). This prevents historical payments (back-filled) from appearing as a
downstream stage of a funnel that did not yet exist.

- A stage whose event was never recorded is shown as `DATA NOT AVAILABLE`, not 0.
- Step conversion is capped at 100% (`min(100, …)`) so an instrumented stage can
  never exceed its predecessor.

**Tracking start:** events began 2026-09-27; payment history exists from
2026-09-20. The dashboard shows a banner stating this and that prior visits are
not recoverable.

## 8. Invariants / tests

Enforced by `tests/RevenueInsightsTest.php`:

1. first purchase ⇒ `new`;
2. linked renewal ⇒ `renewal`;
3. in-grace unlinked repeat ⇒ `renewal`;
4. long-gap repeat ⇒ `reactivation`;
5. imports/compensation are never purchases;
6. 4₽ plan ⇒ `trial`;
7. synthetic/`balance_*` never revenue;
8. payment + successful provisioning ⇒ `succeeded`;
9. payment + active sub without operation ⇒ `missing_event` (not failure);
10. payment + failed operation ⇒ `failed`;
11. renewal-path payment without link ⇒ `unlinked`;
12. abandoned attempt on canceled order is classified;
13. funnel step conversion never >100%;
14. pre-tracking payment never enters the post-tracking funnel;
15. immature churn window ⇒ `not_matured`;
16. immature 4₽ window ⇒ `not_matured`;
17. repeat 4₽ purchase is not conversion;
18. 4₽ → normal plan counts as conversion;
19. attribution split separates legacy pre-tracking users from genuinely missing.

## 9. Attribution

Attribution is split into:

- `legacy_pre_tracking` — users created before tracking start (expected, not an error);
- `missing_post_tracking` — users created after tracking start without attribution
  (a real gap).

The dashboard shows both, so the (expected) legacy majority is not flagged as a
critical error.

## 10. Trial cohort (4₽)

For each 4₽ buyer: first trial purchase time, count of repeat 4₽ purchases, next
normal-plan purchase, time-to-conversion, and revenue after the trial. Windows:
1/3/7/14/30d. Repeat 4₽ purchases are not conversions. Immature windows report
`NOT MATURED`.
