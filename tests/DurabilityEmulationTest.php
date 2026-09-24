<?php
declare(strict_types=1);
namespace Tests;

use App\Billing\BillingService;
use App\Identity\Auth;
use App\Infrastructure\{Database,DurableWorkflow,Outbox};
use App\Payments\PaymentAttemptStore;
use PHPUnit\Framework\TestCase;

/** End-to-end emulation of settlement + queue loss + replay recovery. */
final class DurabilityEmulationTest extends TestCase
{
    public function testConfirmedPaymentSurvivesQueueLossAndWebhookReplay(): void
    {
        $db=new Database('sqlite::memory:'); $db->migrate(__DIR__.'/../migrations');
        $user=(new Auth($db))->register('durability@example.test','correct horse battery staple');
        $db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,duration_months) VALUES('durability','Durability',19900,'RUB',30,0,1,1,1)");
        $outbox=new Outbox($db); $billing=new BillingService($db,$outbox,'demo');

        $order=$billing->order($user,'durability','durability-emulation-key');
        $billing->settle($order['id'],'demo','durability-provider-payment',19900,'RUB');
        // Simulate a total queue loss after the durable payment COMMIT.
        $db->execute("UPDATE outbox SET status='done' WHERE topic='subscription.provision'");
        // Simulate a duplicated verified webhook: no second financial or service effect.
        $billing->settle($order['id'],'demo','durability-provider-payment',19900,'RUB');
        (new DurableWorkflow($db,$outbox))->recover();

        self::assertSame(1,(int)$db->one('SELECT COUNT(*) n FROM payments')['n']);
        self::assertSame(2,(int)$db->one('SELECT COUNT(*) n FROM ledger_entries')['n']);
        self::assertSame(1,(int)$db->one('SELECT COUNT(*) n FROM subscriptions')['n']);
        self::assertSame(1,(int)$db->one('SELECT COUNT(*) n FROM workflows')['n']);
        self::assertSame(1,(int)$db->one('SELECT COUNT(*) n FROM provisioning_operations')['n']);
        self::assertGreaterThanOrEqual(1,(int)$db->one("SELECT COUNT(*) n FROM outbox WHERE topic='subscription.provision' AND status='pending'")['n']);
    }

    public function testExhaustedProvisioningCreatesVisibleIncidentState(): void
    {
        $db=new Database('sqlite::memory:'); $db->migrate(__DIR__.'/../migrations');
        $user=(new Auth($db))->register('escalation@example.test','correct horse battery staple');
        $db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,duration_months) VALUES('escalation','Escalation',19900,'RUB',30,0,1,1,1)");
        $outbox=new Outbox($db); $billing=new BillingService($db,$outbox,'demo');
        $order=$billing->order($user,'escalation','durability-escalation-key');
        $billing->settle($order['id'],'demo','escalation-provider-payment',19900,'RUB');
        $subscription=$db->one('SELECT id FROM subscriptions WHERE order_id=?',[$order['id']]);
        $db->execute("UPDATE provisioning_operations SET attempts=1,max_attempts=1,next_attempt_at=0 WHERE subscription_id=?",[$subscription['id']]);

        self::assertNull((new DurableWorkflow($db,$outbox))->claimActivation($subscription['id'],'test-worker'));
        self::assertSame('failed_needs_attention',$db->one('SELECT status FROM provisioning_operations WHERE subscription_id=?',[$subscription['id']])['status']);
        self::assertSame('failed_needs_attention',$db->one('SELECT state FROM workflows WHERE entity_id=?',[$order['id']])['state']);
        self::assertSame(1,(int)$db->one("SELECT COUNT(*) n FROM operational_cases WHERE subscription_id=? AND status='open'",[$subscription['id']])['n']);
    }

    public function testStaleUnknownCheckoutBecomesVisibleForReconciliation(): void
    {
        $db=new Database('sqlite::memory:'); $db->migrate(__DIR__.'/../migrations');
        $user=(new Auth($db))->register('checkout-escalation@example.test','correct horse battery staple');
        $db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,duration_months) VALUES('checkout-escalation','Checkout escalation',19900,'RUB',30,0,1,1,1)");
        $outbox=new Outbox($db); $billing=new BillingService($db,$outbox,'demo');
        $order=$billing->order($user,'checkout-escalation','checkout-escalation-key');
        $attempt=(new PaymentAttemptStore($db))->begin('order',$order);
        $db->execute("UPDATE payment_attempts SET status='unknown',updated_at=0 WHERE id=?",[$attempt['id']]);

        self::assertSame(1,(new PaymentAttemptStore($db))->escalateUnknown(1));
        self::assertSame('failed',$db->one('SELECT status FROM payment_attempts WHERE id=?',[$attempt['id']])['status']);
        self::assertSame(1,(int)$db->one("SELECT COUNT(*) n FROM operational_cases WHERE user_id=? AND title='Checkout outcome needs reconciliation'",[$user])['n']);
    }
}
