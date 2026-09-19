<?php
declare(strict_types=1);
namespace Tests;
use App\Billing\CreatorService;
use App\Infrastructure\Database;
use PHPUnit\Framework\TestCase;
final class CreatorServiceTest extends TestCase
{
    private Database $db; private CreatorService $service;
    protected function setUp(): void
    {
        $this->db=new Database('sqlite::memory:'); $this->db->migrate(__DIR__.'/../migrations');
        $this->db->execute("INSERT INTO users(id,email,created_at) VALUES('creator','creator@example.test',0),('buyer','buyer@example.test',0)");
        $this->db->execute("INSERT INTO creators(id,user_id,name,code,status,first_percent,recurring_percent,recurring_days,hold_days,created_at,updated_at) VALUES('c','creator','Creator','CAT123','active',10,5,180,0,0,0)");
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('plan','Plan',59700,'RUB',30,0,1,1)");
        $this->db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,created_at) VALUES('order','buyer','plan','creator-test-key',59700,'RUB','Plan',30,0,1,'paid','demo',1)");
        $this->db->execute("INSERT INTO payments(id,order_id,user_id,provider,provider_payment_id,amount_minor,currency,status,created_at,paid_at) VALUES('payment','order','buyer','demo','payment-1',59700,'RUB','succeeded',1,1)");
        $this->service=new CreatorService($this->db);
    }
    public function testAttributionPaymentAndRetryAreIdempotent(): void
    {
        $token=$this->service->capture('CAT123','tiktok',null); self::assertNotNull($token);
        $this->service->attachRegistration('buyer',$token);
        $this->service->recordPayment('payment'); $this->service->recordPayment('payment');
        self::assertSame(1,(int)$this->db->one('SELECT COUNT(*) c FROM creator_commissions')['c']);
        self::assertSame(5970,(int)$this->db->one('SELECT amount_minor FROM creator_ledger WHERE entry_type=\'commission\'')['amount_minor']);
        $this->service->releaseDue();
        self::assertSame('available',$this->db->one('SELECT status FROM creator_commissions')['status']);
    }
    public function testReversalAppendsInsteadOfDeleting(): void
    {
        $token=$this->service->capture('CAT123',null,null); $this->service->attachRegistration('buyer',$token); $this->service->recordPayment('payment'); $this->service->reversePayment('payment');
        self::assertSame('reversed',$this->db->one('SELECT status FROM creator_commissions')['status']);
        self::assertSame(0,(int)$this->db->one('SELECT SUM(amount_minor) s FROM creator_ledger')['s']);
    }
    public function testExistingUserCanBeEnabledAndSuspendedWithoutNewAccount(): void
    {
        $this->db->execute("INSERT INTO users(id,email,created_at) VALUES('member','member@example.test',0)");
        $profile=$this->service->activate('member',['code'=>'CAT-A7K29','first_percent'=>10,'recurring_percent'=>5,'recurring_days'=>180,'attribution_days'=>30,'hold_days'=>14],'admin');
        self::assertSame('member',$profile['user_id']); self::assertSame('active',$profile['status']);
        $this->service->suspend('member','admin'); self::assertSame('suspended',$this->service->profile('member')['status']);
    }
    public function testPayoutReservesOnlyAvailableCommission(): void
    {
        $token=$this->service->capture('CAT123',null,null);$this->service->attachRegistration('buyer',$token);$this->service->recordPayment('payment');$this->service->releaseDue();
        $payout=$this->service->requestPayout('creator',5000,'Bank account 12345');
        self::assertSame('requested',$payout['status']);self::assertSame(970,(int)$this->service->balance('c')['available']);
        $this->expectException(\App\Billing\BillingError::class);$this->service->requestPayout('creator',971,'Bank account 12345');
    }
}
