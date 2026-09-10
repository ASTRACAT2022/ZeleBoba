<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,Wallet,UserAdminService,ReportingService};
use App\Identity\Auth;
final class UserAdminTest extends TestCase
{
    private Database $db; private Outbox $outbox; private Wallet $wallet; private UserAdminService $svc; private string $uid; private string $adminUid;
    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $this->wallet=new Wallet($this->db);
        $this->svc=new UserAdminService($this->db,$this->wallet);
        $auth=new Auth($this->db);
        $this->uid=$auth->register('user@example.org','correct-horse-battery');
        $this->adminUid=$auth->register('admin@example.org','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
    }
    public function testProfile():void
    {
        $p=$this->svc->profile($this->uid);
        self::assertSame($this->uid,$p['user']['id']);
        self::assertArrayHasKey('subscriptions',$p);
        self::assertArrayHasKey('orders',$p);
        self::assertArrayHasKey('transactions',$p);
        self::assertArrayHasKey('promo_uses',$p);
        self::assertArrayHasKey('referrals',$p);
    }
    public function testProfileNotFound():void
    {
        $this->expectException(\App\Billing\BillingError::class);
        $this->svc->profile('nonexistent');
    }
    public function testAdjustBalanceCreditAndDebit():void
    {
        $this->svc->adjustBalance($this->uid,50000,'Компенсация',$this->adminUid);
        self::assertSame(50000,$this->wallet->balance($this->uid)['balance_kopeks']);
        $this->svc->adjustBalance($this->uid,-10000,'Штраф',$this->adminUid);
        self::assertSame(40000,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertCount(2,$this->db->all('SELECT * FROM transactions'));
        self::assertCount(2,$this->db->all("SELECT * FROM audit_log WHERE action='user.balance_adjusted'"));
    }
    public function testAdjustBalanceRejectsZeroAndOversized():void
    {
        $this->expectException(\App\Billing\BillingError::class);
        $this->svc->adjustBalance($this->uid,0,'Ноль',$this->adminUid);
    }
    public function testGrantDaysExtendsExisting():void
    {
        $billing=new BillingService($this->db,$this->outbox,'demo');
        $o=$billing->order($this->uid,'basic','grant-key');
        $billing->settle($o['id'],'demo','demo_g',19900,'RUB');
        $sub=$this->db->one('SELECT * FROM subscriptions');
        $oldExpiry=(int)$sub['expires_at'];
        $this->svc->grantDays($this->uid,10,'basic',$this->adminUid);
        $sub=$this->db->one('SELECT * FROM subscriptions');
        self::assertSame($oldExpiry+10*86400,(int)$sub['expires_at']);
    }
    public function testGrantDaysCreatesNew():void
    {
        $sub=$this->svc->grantDays($this->uid,7,'basic',$this->adminUid);
        self::assertSame('active',$sub['status']);
        self::assertSame(7,(int)round(((int)$sub['expires_at']-time())/86400));
        self::assertSame('basic',$sub['plan_id']);
    }
    public function testSetAndClearDiscount():void
    {
        $this->svc->setDiscount($this->uid,15,24,$this->adminUid);
        $user=$this->db->one('SELECT * FROM users WHERE id=?',[$this->uid]);
        self::assertSame(15,(int)$user['promo_offer_discount_percent']);
        self::assertNotNull($user['promo_offer_discount_expires_at']);
        $this->svc->clearDiscount($this->uid,$this->adminUid);
        $user=$this->db->one('SELECT * FROM users WHERE id=?',[$this->uid]);
        self::assertSame(0,(int)$user['promo_offer_discount_percent']);
    }
    public function testSetDiscountRejectsInvalid():void
    {
        $this->expectException(\App\Billing\BillingError::class);
        $this->svc->setDiscount($this->uid,150,24,$this->adminUid);
    }
    public function testSearch():void
    {
        $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',['12345',$this->uid]);
        self::assertCount(1,$this->svc->search('user@example.org'));
        self::assertCount(1,$this->svc->search('12345'));
        self::assertCount(0,$this->svc->search('nobody@example.org'));
        self::assertCount(0,$this->svc->search(''));
    }
    public function testReportingByProviderPlanTypeCustomers():void
    {
        $billing=new BillingService($this->db,$this->outbox,'demo');
        $o=$billing->order($this->uid,'basic','report-key');
        $billing->settle($o['id'],'demo','demo_r',19900,'RUB');
        $r=new ReportingService($this->db);
        $byProvider=$r->revenueByProvider(30);
        self::assertCount(1,$byProvider);
        self::assertSame('demo',$byProvider[0]['provider']);
        self::assertSame(19900,(int)$byProvider[0]['total']);
        $byPlan=$r->revenueByPlan(30);
        self::assertCount(1,$byPlan);
        self::assertSame('Basic',$byPlan[0]['plan_name']);
        $byType=$r->revenueByType(30);
        self::assertSame(19900,$byType['purchases_kopeks']);
        $top=$r->topCustomers(30);
        self::assertCount(1,$top);
        self::assertSame(19900,(int)$top[0]['spent']);
        $stats=$r->salesStats(30);
        self::assertSame(19900,$stats['avg_check_kopeks']);
        self::assertSame(1,$stats['orders']);
    }
}
