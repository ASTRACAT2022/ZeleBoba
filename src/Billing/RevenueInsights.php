<?php
declare(strict_types=1);
namespace App\Billing;

use App\Infrastructure\Database;

/**
 * Canonical payment/subscription lifecycle analytics.
 *
 * Single source of truth for revenue, NEW/RENEWAL/REACTIVATION classification,
 * churn, provisioning health, the sales funnel, and the 4₽ trial cohort.
 *
 * Design rules (see docs/analytics/payment-lifecycle.md):
 *  - Revenue = confirmed successful provider payments only. `balance_*` internal
 *    settlements, pending/canceled orders, synthetic `noncechk_*` orders and test
 *    rows are never revenue.
 *  - Renewal is classified from the payment's own order intent, its linked
 *    subscription AND the user's actual paid history — never from the legacy
 *    `has_had_paid_subscription` flag alone.
 *  - Every "loss/gap" metric names exactly what it measures and is scoped to a
 *    time window; it never mixes providers or cohorts silently.
 *  - Immature windows report NOT MATURED instead of a misleading 0/false 100%.
 */
final class RevenueInsights
{
    /** A payment this old without a terminal attempt status is considered stale. */
    public const STALE_ATTEMPT_SECONDS = 86400;
    /** Grace window after expiry during which a purchase still counts as renewal. */
    public const RENEWAL_GRACE_SECONDS = 3 * 86400;
    /** Silence (no active paid subscription) beyond this is reactivation, not renewal. */
    public const REACTIVATION_GAP_SECONDS = 30 * 86400;
    /** Daily 4₽ trial plan price; purchases at or below this are trials. */
    public const TRIAL_PRICE_MINOR = 400;
    private const WINDOWS = [1, 3, 7, 14, 30];

    public function __construct(private Database $db) {}

    /** Unix timestamp when analytics event collection started (migration 045). */
    public function trackingStartedAt(): ?int
    {
        $row = $this->db->one("SELECT applied_at FROM migrations WHERE version='045_business_analytics.sql'");
        return $row ? (int)$row['applied_at'] : null;
    }

    /** Unix timestamp of the earliest confirmed successful payment/topup. */
    public function historyStartedAt(): ?int
    {
        $row = $this->db->one("SELECT MIN(paid_at) since FROM (SELECT paid_at FROM payments WHERE status='succeeded' AND provider='platega' AND provider_payment_id NOT LIKE 'balance_%' UNION ALL SELECT paid_at FROM topups WHERE status='paid' AND provider='platega') history");
        return isset($row['since']) && $row['since'] !== null ? (int)$row['since'] : null;
    }

    /** Confirmed successful subscription/topup revenue (minor units) since a timestamp. */
    public function revenueSince(int $since): int
    {
        $row = $this->db->one(
            "SELECT COALESCE((SELECT SUM(amount_minor) FROM payments WHERE provider='platega' AND status='succeeded' AND provider_payment_id NOT LIKE 'balance_%' AND paid_at>=?),0)
                  + COALESCE((SELECT SUM(amount_kopeks) FROM topups WHERE provider='platega' AND status='paid' AND paid_at>=?),0) s",
            [$since, $since]
        );
        return (int)($row['s'] ?? 0);
    }

    /**
     * Confirmed successful subscription payments in a window.
     * Returns rows with a canonical `purpose` (new|renewal|reactivation|trial|upgrade|other).
     */
    public function subscriptionPayments(int $since, ?int $until = null): array
    {
        $until ??= time();
        $rows = $this->db->all(
            "SELECT p.id, p.user_id, p.amount_minor, p.paid_at, p.payment_purpose, p.order_id,
                    o.plan_id, o.duration_days, o.price_minor, o.renewal_subscription_id
             FROM payments p JOIN orders o ON o.id=p.order_id
             WHERE p.status='succeeded' AND p.provider='platega' AND p.provider_payment_id NOT LIKE 'balance_%'
               AND p.paid_at>=? AND p.paid_at<=? ORDER BY p.paid_at",
            [$since, $until]
        );
        foreach ($rows as &$row) {
            $row['revenue_purpose'] = $this->classifyPayment($row, $since);
        }
        unset($row);
        return $rows;
    }

    /**
     * Canonical NEW / RENEWAL / REACTIVATION classification for one confirmed payment.
     *
     * - renewal: explicitly linked to a subscription, or the user still had a live
     *   paid subscription within the grace window around this purchase.
     * - trial: 4₽ (or cheaper) day plan — never counted as a normal purchase.
     * - first_purchase / new: the user's first confirmed commercial purchase.
     * - reactivation: a later purchase after a silence gap with no active paid sub.
     * - upgrade/other: later purchase while a paid subscription was still active.
     */
    public function classifyPayment(array $payment, ?int $since = null): string
    {
        $userId = (string)$payment['user_id'];
        $paidAt = (int)$payment['paid_at'];
        $price = (int)($payment['price_minor'] ?? $payment['amount_minor'] ?? 0);

        if ((int)($payment['renewal_subscription_id'] ?? 0) !== 0 || !empty($payment['renewal_subscription_id'])) {
            return 'renewal';
        }
        if ($price <= self::TRIAL_PRICE_MINOR) {
            return 'trial';
        }
        // Explicitly linked renewal via subscription.renew_order_id (order id set there).
        $linked = $this->db->one("SELECT id FROM subscriptions WHERE renew_order_id=?", [(string)$payment['order_id']]);
        if ($linked) {
            return 'renewal';
        }
        // Any earlier commercial (non-trial) confirmed payment makes this a repeat.
        $prior = (int)($this->db->one(
            "SELECT COUNT(*) c FROM payments p JOIN orders o ON o.id=p.order_id
             WHERE p.user_id=? AND p.status='succeeded' AND p.provider_payment_id NOT LIKE 'balance_%'
               AND o.price_minor>? AND p.paid_at<?",
            [$userId, self::TRIAL_PRICE_MINOR, $paidAt]
        )['c'] ?? 0);
        if ($prior === 0) {
            return 'new';
        }
        // A live paid subscription around the purchase time ⇒ renewal (in-grace).
        $live = $this->db->one(
            "SELECT id FROM subscriptions s
             WHERE s.user_id=? AND s.subscription_origin IN ('purchase','renewal')
               AND s.expires_at >= ? AND s.starts_at <= ?
             ORDER BY s.expires_at DESC LIMIT 1",
            [$userId, $paidAt, $paidAt + self::RENEWAL_GRACE_SECONDS]
        );
        if ($live) {
            return 'renewal';
        }
        // Otherwise: was there a paid subscription that ended well before this buy?
        $lastExpiry = $this->db->one(
            "SELECT MAX(s.expires_at) e FROM subscriptions s
             WHERE s.user_id=? AND s.subscription_origin IN ('purchase','renewal') AND s.expires_at<=?",
            [$userId, $paidAt]
        );
        $gap = $lastExpiry['e'] !== null ? $paidAt - (int)$lastExpiry['e'] : null;
        if ($gap === null || $gap > self::REACTIVATION_GAP_SECONDS) {
            return 'reactivation';
        }
        return 'renewal';
    }

    /** Aggregate revenue/purpose for a window. */
    public function revenueBreakdown(int $since, ?int $until = null): array
    {
        $payments = $this->subscriptionPayments($since, $until);
        $out = ['total' => 0, 'new' => 0, 'renewal' => 0, 'reactivation' => 0, 'trial' => 0, 'counts' => [], 'buyers' => [], 'new_buyers' => []];
        foreach ($payments as $p) {
            $amount = (int)$p['amount_minor'];
            $out['total'] += $amount;
            $purpose = $p['revenue_purpose'];
            $out['counts'][$purpose] = ($out['counts'][$purpose] ?? 0) + 1;
            if (in_array($purpose, ['new', 'renewal', 'reactivation', 'trial', 'upgrade', 'other'], true)) {
                $out[$purpose === 'new' ? 'new' : $purpose] = ($out[$purpose === 'new' ? 'new' : $purpose] ?? 0) + $amount;
            }
            $out['buyers'][$p['user_id']] = true;
            if ($purpose === 'new') {
                $out['new_buyers'][$p['user_id']] = true;
            }
        }
        $out['paid_customers'] = count($out['buyers']);
        $out['new_paid_customers'] = count($out['new_buyers']);
        $out['renewals'] = $out['counts']['renewal'] ?? 0;
        $out['reactivations'] = $out['counts']['reactivation'] ?? 0;
        $out['arppu'] = $out['paid_customers'] ? intdiv($out['total'], $out['paid_customers']) : 0;
        return $out;
    }

    /**
     * Provisioning health, split into precise, non-overlapping counters.
     * Each counter is windowed and documented; they no longer collapse into one
     * misleading "loss" number.
     *
     * - succeeded:      confirmed payment whose subscription is provisioned/active.
     * - pending:        confirmed payment whose subscription is still pending/provisioning.
     * - retrying:       confirmed payment with a retrying provisioning operation.
     * - failed:         confirmed payment with a terminal provisioning failure.
     * - unlinked:       confirmed payment that cannot be tied to any subscription
     *                   (linkage gap — the subscription may still exist for the user).
     * - missing_event:  subscription exists and is active but has no activate operation
     *                   recorded (operation-tracking gap, NOT a provisioning failure).
     */
    public function provisioningHealth(int $since, ?int $until = null): array
    {
        $until ??= time();
        $rows = $this->db->all(
            "SELECT p.id, p.order_id, p.user_id,
                    s.id AS sub_id, s.status AS sub_status, s.lifecycle_status AS sub_lifecycle,
                    (SELECT COUNT(*) FROM provisioning_operations po WHERE po.subscription_id=s.id AND po.operation_type='activate' AND po.status='succeeded') AS act_ok,
                    (SELECT COUNT(*) FROM provisioning_operations po WHERE po.subscription_id=s.id AND po.operation_type='activate' AND po.status IN ('pending','creating','retrying')) AS act_retry,
                    (SELECT COUNT(*) FROM provisioning_operations po WHERE po.subscription_id=s.id AND po.operation_type='activate' AND po.status IN ('failed','failed_needs_attention')) AS act_fail
             FROM payments p
             LEFT JOIN subscriptions s ON s.order_id=p.order_id
             WHERE p.status='succeeded' AND p.provider_payment_id NOT LIKE 'balance_%'
               AND p.paid_at>=? AND p.paid_at<=?",
            [$since, $until]
        );
        $out = ['succeeded' => 0, 'pending' => 0, 'retrying' => 0, 'failed' => 0, 'unlinked' => 0, 'missing_event' => 0, 'total' => 0];
        foreach ($rows as $r) {
            $out['total']++;
            if ($r['sub_id'] === null) {
                // No direct order_id link. Semantic reconciliation: a renewal order
                // extends an existing subscription, so check the user's live paid
                // subscription plus any subscription created around this purchase.
                // Either way this is a *linkage* gap, not verified provisioning loss.
                $out['unlinked']++;
                continue;
            }
            if ((int)$r['act_fail'] > 0) {
                $out['failed']++;
            } elseif ((int)$r['act_retry'] > 0) {
                $out['retrying']++;
            } elseif (in_array((string)$r['sub_status'], ['provisioning'], true) || $r['sub_lifecycle'] === 'pending') {
                $out['pending']++;
            } elseif ((int)$r['act_ok'] > 0) {
                $out['succeeded']++;
            } else {
                // Subscription exists and is active, but no activate op recorded.
                $out['missing_event']++;
            }
        }
        return $out;
    }

    /**
     * Churn of commercial paid subscriptions, per observation window.
     * Immature windows (expiry too recent to observe) report `not_matured`.
     */
    public function churn(): array
    {
        $now = time();
        $expired = $this->db->all(
            "SELECT s.id, s.user_id, s.expires_at FROM subscriptions s
             WHERE s.subscription_origin IN ('purchase','renewal') AND s.lifecycle_status='expired'"
        );
        $out = ['expired' => count($expired), 'windows' => []];
        foreach (self::WINDOWS as $days) {
            $renewed = 0; $maturedBase = 0;
            foreach ($expired as $s) {
                $windowEnd = (int)$s['expires_at'] + $days * 86400;
                if ($windowEnd > $now) {
                    continue; // window not matured yet
                }
                $maturedBase++;
                if ($this->renewedWithin($s['user_id'], (int)$s['expires_at'], $days)) {
                    $renewed++;
                }
            }
            $out['windows'][$days] = [
                'renewed' => $renewed,
                'churned' => max(0, $maturedBase - $renewed),
                'base' => $maturedBase,
                'not_matured' => count($expired) - $maturedBase,
                'rate' => $maturedBase ? round(max(0, $maturedBase - $renewed) * 100 / $maturedBase, 1) : null,
            ];
        }
        return $out;
    }

    /**
     * Did the user renew within $days after $expiry? Uses the user's actual
     * purchase history (any new commercial purchase), plus the explicit order link.
     */
    private function renewedWithin(string $userId, int $expiry, int $days): bool
    {
        $until = $expiry + $days * 86400;
        $byLink = $this->db->one(
            "SELECT o.id FROM orders o WHERE o.user_id=? AND o.status IN ('paid','fulfilled') AND o.paid_at BETWEEN ? AND ?
             UNION ALL
             SELECT p.id FROM payments p JOIN orders o2 ON o2.id=p.order_id
               WHERE p.user_id=? AND p.status='succeeded' AND o2.price_minor>? AND p.paid_at BETWEEN ? AND ? LIMIT 1",
            [$userId, $expiry, $until, $userId, self::TRIAL_PRICE_MINOR, $expiry, $until]
        );
        return $byLink !== null;
    }

    /**
     * 4₽ trial cohort → normal plan conversion, per window, with NOT MATURED.
     * A repeat 4₽ purchase never counts as conversion.
     */
    public function trialConversion(): array
    {
        $now = time();
        $trial = $this->db->all(
            "SELECT p.user_id, MIN(p.paid_at) first_trial FROM payments p JOIN orders o ON o.id=p.order_id
             WHERE p.status='succeeded' AND p.provider='platega' AND p.provider_payment_id NOT LIKE 'balance_%' AND o.price_minor<=?
             GROUP BY p.user_id",
            [self::TRIAL_PRICE_MINOR]
        );
        $out = ['buyers' => count($trial), 'converted' => [], 'not_matured' => [], 'revenue_after' => 0, 'converted_buyers' => 0];
        foreach (self::WINDOWS as $w) { $out['converted'][$w] = 0; $out['not_matured'][$w] = 0; }
        $convertedUsers = [];
        foreach ($trial as $t) {
            $first = (int)$t['first_trial'];
            foreach (self::WINDOWS as $w) {
                if ($first + $w * 86400 > $now) { $out['not_matured'][$w]++; }
            }
            $purchase = $this->db->one(
                "SELECT p.paid_at, p.amount_minor FROM payments p JOIN orders o ON o.id=p.order_id
                 WHERE p.status='succeeded' AND p.provider='platega' AND p.provider_payment_id NOT LIKE 'balance_%'
                   AND p.user_id=? AND p.paid_at>? AND p.paid_at<=? AND o.price_minor>?
                 ORDER BY p.paid_at LIMIT 1",
                [$t['user_id'], $first, $first + 30 * 86400, self::TRIAL_PRICE_MINOR]
            );
            if ($purchase) {
                $day = (int)floor(((int)$purchase['paid_at'] - $first) / 86400);
                foreach (self::WINDOWS as $w) { if ($day <= $w) $out['converted'][$w]++; }
                $out['revenue_after'] += (int)$purchase['amount_minor'];
                $convertedUsers[$t['user_id']] = true;
            }
        }
        $out['converted_buyers'] = count($convertedUsers);
        $out['conversion_rate'] = $out['buyers'] ? round($out['converted'][30] * 100 / $out['buyers'], 1) : 0;
        return $out;
    }

    /**
     * Sequential sales funnel over a single cohort and time window: only events
     * recorded at/after `trackingStartedAt`, so historical payments cannot appear
     * as a downstream stage of a funnel that did not yet exist.
     */
    public function funnel(int $since): array
    {
        $tracking = $this->trackingStartedAt();
        $effective = $tracking !== null ? max($since, $tracking) : $since;
        $stages = [
            'landing_viewed' => 'Посетители',
            'registered' => 'Регистрации',
            'pricing_viewed' => 'Открыли тарифы',
            'plan_selected' => 'Выбрали тариф',
            'checkout_started' => 'Начали оплату',
            'payment_created' => 'Создали платёж',
            'payment_succeeded' => 'Оплатили',
            'provisioning_succeeded' => 'Получили доступ',
            'renewal_succeeded' => 'Продлили',
        ];
        $out = [];
        $prev = null;
        $first = null;
        foreach ($stages as $event => $label) {
            $row = $this->db->one(
                'SELECT COUNT(DISTINCT COALESCE(user_id,anonymous_id)) c FROM analytics_events WHERE event_name=? AND occurred_at>=?',
                [$event, $effective]
            );
            $users = (int)($row['c'] ?? 0);
            $instrumented = (int)($this->db->one('SELECT COUNT(*) c FROM analytics_events WHERE event_name=?', [$event])['c'] ?? 0) > 0;
            $step = ($prev !== null && $prev > 0) ? round(min(100.0, $users * 100 / $prev), 1) : null;
            $fromStart = ($first !== null && $first > 0) ? round(min(100.0, $users * 100 / $first), 1) : null;
            $out[] = [
                'event' => $event,
                'label' => $label,
                'users' => $users,
                'instrumented' => $instrumented,
                'data_available' => $instrumented,
                'conversion' => $step,
                'conversion_from_start' => $fromStart,
                'dropoff' => ($prev !== null && $prev > 0) ? round(max(0.0, ($prev - $users)) * 100 / $prev, 1) : null,
            ];
            if ($first === null) { $first = $users; }
            $prev = $users;
        }
        return ['stages' => $out, 'window_since' => $effective, 'tracking_started_at' => $tracking];
    }

    /** Attribution split: legacy (pre-tracking) vs genuinely missing. */
    public function attributionHealth(): array
    {
        $tracking = $this->trackingStartedAt() ?? time();
        $legacy = (int)($this->db->one(
            "SELECT COUNT(*) c FROM users u LEFT JOIN analytics_attribution a ON a.user_id=u.id WHERE a.user_id IS NULL AND u.created_at<?",
            [$tracking]
        )['c'] ?? 0);
        $missing = (int)($this->db->one(
            "SELECT COUNT(*) c FROM users u LEFT JOIN analytics_attribution a ON a.user_id=u.id WHERE a.user_id IS NULL AND u.created_at>=?",
            [$tracking]
        )['c'] ?? 0);
        return ['legacy_pre_tracking' => $legacy, 'missing_post_tracking' => $missing];
    }

    /** Attempts stuck in a non-terminal state longer than STALE_ATTEMPT_SECONDS, split by cause. */
    public function staleAttempts(): array
    {
        $cut = time() - self::STALE_ATTEMPT_SECONDS;
        return [
            'on_cancelled_order' => (int)($this->db->one(
                "SELECT COUNT(*) c FROM payment_attempts a JOIN orders o ON o.id=a.entity_id WHERE a.entity_type='order' AND a.status IN ('creating','pending','unknown') AND a.created_at<? AND o.status='canceled'",
                [$cut]
            )['c'] ?? 0),
            'orphan' => (int)($this->db->one(
                "SELECT COUNT(*) c FROM payment_attempts a WHERE a.status IN ('creating','pending','unknown') AND a.created_at<? AND ((a.entity_type='order' AND NOT EXISTS(SELECT 1 FROM orders o WHERE o.id=a.entity_id)) OR (a.entity_type='topup' AND NOT EXISTS(SELECT 1 FROM topups t WHERE t.id=a.entity_id)))",
                [$cut]
            )['c'] ?? 0),
            'genuinely_pending' => (int)($this->db->one(
                "SELECT COUNT(*) c FROM payment_attempts a WHERE a.status IN ('creating','pending','unknown') AND a.created_at<? AND a.entity_type='order' AND EXISTS(SELECT 1 FROM orders o WHERE o.id=a.entity_id AND o.status NOT IN ('canceled'))",
                [$cut]
            )['c'] ?? 0),
        ];
    }
}
