<?php
declare(strict_types=1);
namespace Tests;
use App\Billing\BillingService;
use App\Identity\{Auth,TelegramLogin};
use App\Infrastructure\{Database,Outbox};
use App\Payments\PaymentEventStore;
use App\Subscriptions\SubscriptionService;
use PHPUnit\Framework\TestCase;

final class BillingCoreArchitectureTest extends TestCase
{
    private Database $db;
    protected function setUp(): void { $this->db=new Database('sqlite::memory:'); $this->db->migrate(__DIR__.'/../migrations'); }
    public function testExternalIdentityIsSeparateFromUser(): void
    {
        $auth=new Auth($this->db); $emailUser=$auth->register('identity@example.test','correct horse battery staple');
        $telegramUser=(new TelegramLogin($this->db,$auth))->telegramUser('123456');
        self::assertNotSame($emailUser,$telegramUser);
        self::assertSame($telegramUser,$this->db->one("SELECT user_id FROM user_identities WHERE type='telegram' AND external_id='123456'")['user_id']);
    }
    public function testPaymentCreatesDurableMoneyAndProvisioningState(): void
    {
        $user=(new Auth($this->db))->register('paid@example.test','correct horse battery staple');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,duration_months) VALUES('month','Monthly',19900,'RUB',30,0,1,1,1)");
        $billing=new BillingService($this->db,new Outbox($this->db),'demo');
        $order=$billing->order($user,'month','architecture-order-key');
        $billing->settle($order['id'],'demo','architecture-payment',19900,'RUB');
        self::assertSame(1,(int)$this->db->one('SELECT count(*) AS n FROM payments')['n']);
        self::assertSame(1,(int)$this->db->one('SELECT count(*) AS n FROM provisioning_accounts WHERE state="pending"')['n']);
        $billing->settle($order['id'],'demo','architecture-payment',19900,'RUB');
        self::assertSame(1,(int)$this->db->one('SELECT count(*) AS n FROM subscriptions')['n']);
    }
    public function testCalendarMonthIsNotThirtyDays(): void
    {
        $service=new SubscriptionService($this->db,new Outbox($this->db));
        $base=(new \DateTimeImmutable('2026-01-31 12:00:00 UTC'))->getTimestamp();
        self::assertSame('2026-02-28',gmdate('Y-m-d',$service->expiryAfter($base,30,1)));
    }
    public function testTwentyDuplicateWebhookEventsAndRetryDoNotChangeMoneyOrEntitlement(): void
    {
        $user=(new Auth($this->db))->register('retry@example.test','correct horse battery staple');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,duration_months) VALUES('retry','Monthly',19900,'RUB',30,0,1,1,1)");
        $outbox=new Outbox($this->db); $billing=new BillingService($this->db,$outbox,'demo');
        $order=$billing->order($user,'retry','retry-order-key');
        $events=new PaymentEventStore($this->db);
        for($i=0;$i<20;$i++) $eventId=$events->receive('demo','gateway-payment-1:succeeded','gateway-payment-1',['status'=>'paid'],true);
        self::assertSame(1,(int)$this->db->one('SELECT count(*) AS n FROM payment_events')['n']);
        $billing->settle($order['id'],'demo','gateway-payment-1',19900,'RUB');
        $billing->settle($order['id'],'demo','gateway-payment-1',19900,'RUB');
        self::assertSame(1,(int)$this->db->one('SELECT count(*) AS n FROM payments')['n']);
        self::assertSame(1,(int)$this->db->one('SELECT count(*) AS n FROM subscriptions')['n']);
        $subscription=$this->db->one('SELECT id FROM subscriptions');
        $this->db->execute("UPDATE provisioning_accounts SET state='processing' WHERE subscription_id=?",[$subscription['id']]);
        $this->db->execute("UPDATE outbox SET status='done'");
        $outbox->enqueue('subscription.provision','failure-case',['subscription_id'=>$subscription['id']]);
        $outbox->runOne(static fn()=>throw new \RuntimeException('panel unavailable'));
        self::assertSame('retry',$this->db->one('SELECT state FROM provisioning_accounts WHERE subscription_id=?',[$subscription['id']])['state']);
        self::assertSame(1,(int)$this->db->one('SELECT count(*) AS n FROM payments')['n']);
    }
}
