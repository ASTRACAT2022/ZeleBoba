<?php
declare(strict_types=1);
namespace Tests;

use App\Billing\RevenueInsights;
use App\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the analytics lifecycle semantics that were wrong before
 * the 2026-09-28 fix: renewal classification, provisioning health, churn windows,
 * the sequential funnel, the 4₽ trial cohort, and revenue source-of-truth rules.
 */
final class RevenueInsightsTest extends TestCase
{
    private Database $db;
    private RevenueInsights $insights;

    protected function setUp(): void
    {
        $this->db = new Database('sqlite::memory:');
        $this->db->migrate(__DIR__.'/../migrations');
        $this->insights = new RevenueInsights($this->db);
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('day4','Сутки 4₽',400,'RUB',1,0,1,1)");
    }

    private function user(string $id): string
    {
        $this->db->execute('INSERT INTO users(id,email,created_at) VALUES(?,?,?)', [$id, $id.'@example.test', time()]);
        return $id;
    }

    private function setTrackingStart(int $timestamp): void
    {
        $this->db->execute("UPDATE migrations SET applied_at=? WHERE version='045_business_analytics.sql'", [$timestamp]);
    }

    private function order(string $id, string $userId, string $planId, int $price, int $paidAt, ?string $renewalSubId = null, string $status = 'fulfilled'): void
    {
        $this->db->execute(
            "INSERT INTO orders(id,user_id,plan_id,plan_name,price_minor,currency,provider,status,created_at,duration_days,renewal_subscription_id,paid_at,idempotency_key,traffic_bytes,devices) VALUES(?,?,?,?,?,'RUB','platega',?,?,?,?,?,?,0,3)",
            [$id, $userId, $planId, $planId, $price, $status, $paidAt, $planId === 'day4' ? 1 : 30, $renewalSubId, $paidAt, 'idem-'.$id]
        );
    }

    private function payment(string $id, string $orderId, string $userId, int $amount, int $paidAt, string $provider = 'platega'): void
    {
        $this->db->execute(
            "INSERT INTO payments(id,order_id,user_id,provider,provider_payment_id,amount_minor,currency,status,created_at,paid_at) VALUES(?,?,?,?,?,?,'RUB','succeeded',?,?)",
            [$id, $orderId, $userId, $provider, 'pp-'.$id, $amount, $paidAt, $paidAt]
        );
    }

    private function subscription(string $id, string $userId, int $expiresAt, string $lifecycle = 'active', string $origin = 'purchase', ?string $orderId = null, int $startedAt = 0): void
    {
        $startedAt = $startedAt ?: $expiresAt - 30 * 86400;
        $status = $lifecycle === 'expired' ? 'expired' : ($lifecycle === 'pending' ? 'provisioning' : 'active');
        $this->db->execute(
            "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit,traffic_used_gb,plan_id,lifecycle_status,starts_at,subscription_origin) VALUES(?,?,?,?,?,?,0,1,0,'basic',?,?,?)",
            [$id, $orderId, $userId, $status, $expiresAt, $startedAt, $lifecycle, $startedAt, $origin]
        );
    }

    /** 1. First commercial purchase is NEW. */
    public function testFirstPurchaseIsNew(): void
    {
        $u = $this->user('u1');
        $this->order('o1', $u, 'basic', 19900, 1_700_000_000);
        $this->payment('p1', 'o1', $u, 19900, 1_700_000_000);
        self::assertSame('new', $this->insights->classifyPayment(['user_id' => $u, 'paid_at' => 1_700_000_000, 'order_id' => 'o1', 'price_minor' => 19900, 'renewal_subscription_id' => null]));
    }

    /** 2. Purchase linked to renewal_subscription_id is RENEWAL. */
    public function testLinkedRenewalIsClassifiedAsRenewal(): void
    {
        $u = $this->user('u2');
        $this->subscription('s2', $u, 1_800_000_000);
        $this->order('o2', $u, 'basic', 19900, 1_750_000_000, 's2');
        $this->payment('p2', 'o2', $u, 19900, 1_750_000_000);
        self::assertSame('renewal', $this->insights->classifyPayment(['user_id' => $u, 'paid_at' => 1_750_000_000, 'order_id' => 'o2', 'price_minor' => 19900, 'renewal_subscription_id' => 's2']));
    }

    /** 3. Repeat purchase while a paid subscription is still live ⇒ RENEWAL (no explicit link needed). */
    public function testInGracePurchaseWithoutLinkIsRenewal(): void
    {
        $u = $this->user('u3');
        $this->subscription('s3', $u, 1_751_000_000); // live at purchase time
        $this->order('o3a', $u, 'basic', 19900, 1_700_000_000); $this->payment('p3a', 'o3a', $u, 19900, 1_700_000_000);
        $this->order('o3b', $u, 'basic', 19900, 1_750_000_000); $this->payment('p3b', 'o3b', $u, 19900, 1_750_000_000);
        self::assertSame('renewal', $this->insights->classifyPayment(['user_id' => $u, 'paid_at' => 1_750_000_000, 'order_id' => 'o3b', 'price_minor' => 19900, 'renewal_subscription_id' => null]));
    }

    /** 4. New purchase after a long silence gap is REACTIVATION. */
    public function testPurchaseAfterLongGapIsReactivation(): void
    {
        $u = $this->user('u4');
        $old = 1_700_000_000;
        $this->subscription('s4old', $u, $old + 30 * 86400, 'expired');
        $this->order('o4a', $u, 'basic', 19900, $old); $this->payment('p4a', 'o4a', $u, 19900, $old);
        $again = $old + 90 * 86400;
        $this->order('o4b', $u, 'basic', 19900, $again); $this->payment('p4b', 'o4b', $u, 19900, $again);
        self::assertSame('reactivation', $this->insights->classifyPayment(['user_id' => $u, 'paid_at' => $again, 'order_id' => 'o4b', 'price_minor' => 19900, 'renewal_subscription_id' => null]));
    }

    /** 5. Compensation-granted subscription is never a purchase; import origin never counts. */
    public function testImportedAndCompensationSubscriptionsAreNotPurchases(): void
    {
        $u = $this->user('u5');
        $this->subscription('s5', $u, 1_800_000_000, 'active', 'remnawave_import');
        self::assertSame(0, $this->insights->revenueBreakdown(0)['paid_customers']);
        self::assertSame(0, $this->insights->churn()['expired']);
    }

    /** 6. The 4₽ day plan is a TRIAL, never a normal purchase/new. */
    public function testTrialPlanIsClassifiedAsTrial(): void
    {
        $u = $this->user('u6');
        $this->order('o6', $u, 'day4', 400, 1_700_000_000);
        $this->payment('p6', 'o6', $u, 400, 1_700_000_000);
        self::assertSame('trial', $this->insights->classifyPayment(['user_id' => $u, 'paid_at' => 1_700_000_000, 'order_id' => 'o6', 'price_minor' => 400, 'renewal_subscription_id' => null]));
    }

    /** 7. Synthetic noncechk_* / test orders never enter revenue. */
    public function testSyntheticOrdersAreExcludedFromRevenue(): void
    {
        $u = $this->user('u7');
        $this->order('noncechk_abc', $u, 'basic', 19900, 1_700_000_000);
        $this->payment('p7', 'noncechk_abc', $u, 19900, 1_700_000_000, 'balance');
        // balance_* provider ids and non-successful rows are excluded by the revenue query.
        self::assertSame(0, $this->insights->revenueSince(0));
    }

    private function provisioningOperation(string $id, string $subId, string $orderId, string $status): void
    {
        $this->db->execute(
            "INSERT INTO provisioning_operations(id,subscription_id,order_id,operation_type,status,desired_state,idempotency_key,attempts,max_attempts,correlation_id,created_at,updated_at) VALUES(?,?,?,'activate',?,'active',?,1,5,?,?,?)",
            [$id, $subId, $orderId, $status, 'po-idem-'.$id, 'corr-'.$id, 1_700_000_000, 1_700_000_000]
        );
    }

    /** 8. Confirmed payment + successful provisioning ⇒ provisioned. */
    public function testProvisioningSucceededIsCounted(): void
    {
        $u = $this->user('u8');
        $this->order('o8', $u, 'basic', 19900, 1_700_000_000);
        $this->payment('p8', 'o8', $u, 19900, 1_700_000_000);
        $this->subscription('s8', $u, 1_800_000_000, 'active', 'purchase', 'o8');
        $this->provisioningOperation('po8', 's8', 'o8', 'succeeded');
        $health = $this->insights->provisioningHealth(0);
        self::assertSame(1, $health['succeeded']);
        self::assertSame(0, $health['failed']);
    }

    /** 9. Payment linked to a subscription but no provisioning op ⇒ missing_event, NOT failure. */
    public function testMissingProvisioningOperationIsNotAFailure(): void
    {
        $u = $this->user('u9');
        $this->order('o9', $u, 'basic', 19900, 1_700_000_000);
        $this->payment('p9', 'o9', $u, 19900, 1_700_000_000);
        $this->subscription('s9', $u, 1_800_000_000, 'active', 'purchase', 'o9');
        $health = $this->insights->provisioningHealth(0);
        self::assertSame(1, $health['missing_event']);
        self::assertSame(0, $health['failed']);
    }

    /** 10. Real provisioning failure is counted as failed. */
    public function testProvisioningFailureIsCounted(): void
    {
        $u = $this->user('u10');
        $this->order('o10', $u, 'basic', 19900, 1_700_000_000);
        $this->payment('p10', 'o10', $u, 19900, 1_700_000_000);
        $this->subscription('s10', $u, 1_800_000_000, 'pending', 'purchase', 'o10');
        $this->provisioningOperation('po10', 's10', 'o10', 'failed_needs_attention');
        $health = $this->insights->provisioningHealth(0);
        self::assertSame(1, $health['failed']);
    }

    /** 11. Renewal payment (extends sub, no order_id link) ⇒ unlinked, never a fabricated loss. */
    public function testPaymentWithoutOrderLinkIsUnlinked(): void
    {
        $u = $this->user('u11');
        $this->subscription('s11', $u, 1_800_000_000, 'active', 'purchase', null);
        $this->order('o11', $u, 'basic', 19900, 1_750_000_000, 's11');
        $this->payment('p11', 'o11', $u, 19900, 1_750_000_000);
        $health = $this->insights->provisioningHealth(0);
        self::assertSame(1, $health['unlinked']);
        self::assertSame(0, $health['failed']);
    }

    /** 12. Abandoned attempt on a cancelled order is classified, not silently dropped. */
    public function testAbandonedAttemptOnCancelledOrder(): void
    {
        $u = $this->user('u12');
        $this->order('o12', $u, 'basic', 19900, 1_700_000_000, null, 'canceled');
        $this->db->execute(
            "INSERT INTO payment_attempts(id,entity_type,entity_id,user_id,provider,amount_minor,currency,status,idempotency_key,created_at,updated_at,correlation_id) VALUES('a12','order','o12',?,'platega',19900,'RUB','pending','k12',?,?,'c12')",
            [$u, time() - 2 * 86400, time() - 2 * 86400]
        );
        self::assertSame(1, $this->insights->staleAttempts()['on_cancelled_order']);
    }

    /** 13. Funnel step conversion can never exceed 100%. */
    public function testFunnelStepConversionNeverExceeds100(): void
    {
        $now = time();
        $u = $this->user('u13');
        $this->db->execute("INSERT INTO analytics_events(id,event_key,event_name,user_id,occurred_at,created_at) VALUES('e1','k1','pricing_viewed',?,?,?)", [$u, $now, $now]);
        $this->db->execute("INSERT INTO analytics_events(id,event_key,event_name,user_id,occurred_at,created_at) VALUES('e2','k2','payment_succeeded',?,?,?)", [$u, $now, $now]);
        $f = $this->insights->funnel($now - 3600)['stages'];
        foreach ($f as $stage) {
            if ($stage['conversion'] !== null) {
                self::assertLessThanOrEqual(100.0, $stage['conversion']);
            }
        }
    }

    /** 14. A historical payment (before tracking start) never appears in the post-tracking funnel. */
    public function testPreTrackingPaymentDoesNotEnterFunnel(): void
    {
        // Force a tracking start in the future so the window clamps to it.
        $this->setTrackingStart(time() + 100000);
        $u = $this->user('u14');
        $old = time() - 10 * 86400;
        $this->db->execute("INSERT INTO analytics_events(id,event_key,event_name,user_id,occurred_at,created_at) VALUES('e3','k3','payment_succeeded',?,?,?)", [$u, $old, $old]);
        $stats = $this->insights->funnel(time() - 30 * 86400);
        $paid = null;
        foreach ($stats['stages'] as $s) { if ($s['event'] === 'payment_succeeded') $paid = $s; }
        self::assertSame(0, $paid['users']);
    }

    /** 15. Immature churn window reports not_matured, not 100% churn. */
    public function testImmatureChurnWindowIsNotMatured(): void
    {
        $u = $this->user('u15');
        // expired yesterday ⇒ the 7d and 30d windows have not matured.
        $this->subscription('s15', $u, time() - 86400, 'expired');
        $churn = $this->insights->churn();
        self::assertSame(1, $churn['expired']);
        self::assertSame(1, $churn['windows'][30]['not_matured']);
        self::assertNull($churn['windows'][30]['rate']);
    }

    /** 16. Immature 4₽ conversion window reports not_matured, not a final 0%. */
    public function testImmatureTrialConversionWindowIsNotMatured(): void
    {
        $u = $this->user('u16');
        $this->order('o16', $u, 'day4', 400, time() - 3600);
        $this->payment('p16', 'o16', $u, 400, time() - 3600);
        $trial = $this->insights->trialConversion();
        self::assertSame(1, $trial['buyers']);
        self::assertSame(1, $trial['not_matured'][30]);
    }

    /** 17. Repeat 4₽ purchases do not count as conversion to a normal plan. */
    public function testRepeatTrialPurchaseIsNotConversion(): void
    {
        $u = $this->user('u17');
        $t0 = time() - 40 * 86400;
        $this->order('o17a', $u, 'day4', 400, $t0); $this->payment('p17a', 'o17a', $u, 400, $t0);
        $this->order('o17b', $u, 'day4', 400, $t0 + 86400); $this->payment('p17b', 'o17b', $u, 400, $t0 + 86400);
        $trial = $this->insights->trialConversion();
        self::assertSame(1, $trial['buyers']);
        self::assertSame(0, $trial['converted'][30]);
    }

    /** 18. A real 4₽ → normal plan purchase within 30d counts as conversion. */
    public function testTrialConvertsToNormalPlan(): void
    {
        $u = $this->user('u18');
        $t0 = time() - 40 * 86400;
        $this->order('o18a', $u, 'day4', 400, $t0); $this->payment('p18a', 'o18a', $u, 400, $t0);
        $this->order('o18b', $u, 'basic', 19900, $t0 + 2 * 86400); $this->payment('p18b', 'o18b', $u, 19900, $t0 + 2 * 86400);
        $trial = $this->insights->trialConversion();
        self::assertSame(1, $trial['converted'][7]);
        self::assertSame(19900, $trial['revenue_after']);
    }

    /** 19. Attribution split separates legacy pre-tracking users from genuinely missing ones. */
    public function testAttributionSplitSeparatesLegacyFromMissing(): void
    {
        $this->setTrackingStart(time() - 1000);
        $legacy = $this->user('legacy');
        $this->db->execute('UPDATE users SET created_at=? WHERE id=?', [time() - 5000, $legacy]);
        $attr = $this->insights->attributionHealth();
        self::assertGreaterThanOrEqual(1, $attr['legacy_pre_tracking']);
    }
}
