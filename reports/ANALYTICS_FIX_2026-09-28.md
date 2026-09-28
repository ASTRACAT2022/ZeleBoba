# ANALYTICS FIX — 2026-09-28

Reconcile payment lifecycle and retention metrics in the ASTRACAT / ZeleBoba
business dashboard. **No production financial records were changed** — only
analytics interpretation was corrected. Read-only production queries were used
for BEFORE/AFTER comparison.

## ROOT CAUSES

1. **"Потеря provisioning = 31"** was `payments ⋈ subscriptions(order_id) ⋈
   provisioning_operations(order_id)` counting a payment when *either* the
   subscription link *or* the activation operation was missing, over **all
   providers and all time**. It mixed three different things:
   - 15 payments with no `subscriptions.order_id` link (the subscription exists —
     it is the **renewal path**, which extends the existing subscription and never
     writes the new order onto the subscription);
   - 16 payments with a subscription but no `activate` operation row (an
     operation-**tracking** gap; the subscription is active).
   Result: a scary "31 loss" that did not correspond to any real lost access.

2. **Payments without subscription = 10** and **payment without successful
   provisioning = 3** used *different* joins/providers/windows than the headline
   number, so the three never reconciled. All 15 unlinked payments belonged to
   users who **do** have a subscription.

3. **22 expired / 0 renewals / 100% churn**. All 22 were the 4₽ day plan
   (`subscriptions.plan_id = d8527802…`). The churn query only looked for
   `orders.renewal_subscription_id`; user-level renewals (same user, new
   purchase) were invisible (only 2 of 22 had the explicit link, 4 had an order
   within 30d).

4. **Impossible funnel** `1 → 35 → 0 → 8 → 21 → 62 → 5`. `payment_succeeded`
   was back-filled for the whole history (occurred_at = `paid_at`, from 10.09),
   while `landing_viewed` / `registered` / `pricing_viewed` only exist from
   27.09. Stages were counted from different cohorts/windows; `plan_selected`
   and `renewal_succeeded` were never recorded but shown as 0.

5. **Stale attempts = 35**: 29 were on **canceled** orders (abandoned checkout),
   6 were **orphans** (order/topup row deleted). Zero were genuinely awaiting
   provider confirmation — so the metric implied trouble that was not there.

6. **`payment_purpose` NULL for 69 of 72 payments** (column added later), so the
   NEW/RENEWAL split was effectively empty.

7. **users without attribution = 8952** — all pre-tracking legacy, shown as a
   critical data-quality error rather than an expected legacy bucket.

8. **Test suite could not bootstrap at all**: migration `020_payment_unavailable.sql`
   used PostgreSQL-only `ALTER TABLE … DROP/ADD CONSTRAINT` without the `[PG]`
   guard the migration runner strips for SQLite → every test errored before
   running. (Pre-existing on `main`.)

## FIXES

- New `App\Billing\RevenueInsights` — canonical lifecycle logic:
  `classifyPayment`, `revenueBreakdown`, `provisioningHealth`, `churn`,
  `trialConversion`, `funnel`, `attributionHealth`, `staleAttempts`.
- `AnalyticsService::dashboard()` rewired to use it; removed the misleading
  `provisioning_gap` and the dead `trialStats`/`churnStats`.
- Provisioning split into succeeded / pending / retrying / failed / missing_event
  / unlinked.
- Churn: user-level renewal detection + observation windows with `NOT MATURED`.
- Funnel: single cohort/window clamped to `tracking_started_at`, `min(100,…)`
  step conversion, `DATA NOT AVAILABLE` for un-instrumented stages.
- Trial cohort: windows with `NOT MATURED`; repeat 4₽ is never conversion.
- Attribution: split legacy-pre-tracking vs missing-post-tracking.
- Stale attempts: split by cause.
- `migrations/020_payment_unavailable.sql`: wrapped PG-only constraint edits in
  the existing `[PG]` guard (portable to the SQLite test backend).
- Template `templates/admin-analytics.html.twig`: new Provisioning panel, split
  data-quality rows, funnel tracking-start warning, NOT MATURED labels.

## BEFORE / AFTER (production, read-only)

| Metric | BEFORE | AFTER |
|---|---|---|
| renewal (30d) | 0 | **6** |
| reactivation (30d) | 0 | **1** |
| provisioning "loss" | 31 | split: unlinked 15 · missing_event 15 · failed 1 · succeeded 42 |
| funnel | `1→35→0→8→21→62→5` (impossible) | cohort-consistent, clamped to tracking start, ≤100% steps |
| churn | 22 expired / 0 renewed / 100% | 22 expired; renewal by user history; immature windows NOT MATURED |
| paying customers (30d) | 45 | **46** |
| ARPPU (30d) | 183,71 ₽ | **183,18 ₽** |
| stale attempts | 35 | 29 cancelled · 6 orphan · **0 genuinely pending** |
| attribution without | 8952 (error) | 8953 legacy-pre-tracking · 0 post-tracking |

Revenue itself is unchanged: **30d subscriptions 8 426,20 ₽** (+ top-ups shown
separately in receipts). No financial record was edited.

## DATABASE CHANGES

**None.** No schema migration, no data mutation. (See `MIGRATIONS` below for the
one *code-portability* edit to an existing migration file.)

## MIGRATIONS

No new migration. `migrations/020_payment_unavailable.sql` was wrapped in the
`-- [PG] … -- [/PG]` guard so the SQLite test backend can bootstrap. This does
**not** change PostgreSQL behaviour (the guarded statements still run in PG) and
is already-applied in production (the `migrations` row exists), so it is a no-op
there.

## TESTS

- New `tests/RevenueInsightsTest.php`: **19 tests, 32 assertions — PASS**.
- Full suite baseline (clean `main`): `294 tests, 26 errors, 16 failures` — all
  **pre-existing** (confirmed by stash-testing a clean tree). The analytics fix
  does not add or remove any of them. The previously-fatal migration bootstrap
  error (285 errors) is gone after the `020` guard fix, exposing the real 42.

## PRODUCTION VERIFICATION

Read-only queries re-run after the change, manually traced `payment → receipt →
order → provisioning → subscription`. Counts above were produced from the
production DB directly (see BEFORE/AFTER).

## KNOWN LIMITATIONS

- Pre-tracking funnel stages cannot be reconstructed; the dashboard states this.
- `plan_selected` is instrumented in web checkout but was never recorded before
  this change; it will populate going forward.
- Remnawave-side node health is outside this analytics scope.
- `payment_purpose` on historical rows stays NULL; classification now derives
  purpose from history rather than that column.
- The 3 "payment without successful provisioning" cases remain as
  `missing_event` (operation rows were never written); back-filling synthetic
  operations was deliberately **not** done to avoid fabricating audit history.
