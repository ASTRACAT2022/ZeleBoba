<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,Wallet,CompensationService};
use App\Identity\Auth;
final class CompensationTest extends TestCase
{
    private Database $db; private Outbox $outbox; private Wallet $wallet; private CompensationService $svc; private string $uid; private string $uid2; private string $adminUid;
    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $this->wallet=new Wallet($this->db);
        $this->svc=new CompensationService($this->db,$this->outbox,$this->wallet);
        $auth=new Auth($this->db);
        $this->uid=$auth->register('user@example.org','correct-horse-battery');
        $this->uid2=$auth->register('user2@example.org','correct-horse-battery');
        $this->adminUid=$auth->register('admin@example.org','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
    }
    public function testBalanceCompensation():void
    {
        $c=$this->svc->create('all','balance',50000,'Компенсация за простой',$this->adminUid,'admin');
        self::assertSame('in_progress',$c['status']);
        $this->svc->run($c['id']);
        $row=$this->db->one('SELECT * FROM compensations WHERE id=?',[$c['id']]);
        self::assertSame('running',$row['status']);
        self::assertSame(3,(int)$row['total_count']);
        $this->svc->grant($c['id'],$this->uid);
        $this->svc->grant($c['id'],$this->uid2);
        self::assertSame(50000,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertSame(50000,$this->wallet->balance($this->uid2)['balance_kopeks']);
        $row=$this->db->one('SELECT * FROM compensations WHERE id=?',[$c['id']]);
        self::assertSame(2,(int)$row['processed_count']);
    }
    public function testCompensationIsIdempotentPerUser():void
    {
        $c=$this->svc->create('all','balance',10000,'Бонус',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$this->uid);
        $this->svc->grant($c['id'],$this->uid);
        self::assertSame(10000,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertSame(1,(int)$this->db->one('SELECT processed_count FROM compensations')['processed_count']);
    }
    public function testDaysCompensationExtendsSubscription():void
    {
        $billing=new BillingService($this->db,$this->outbox,'demo');
        $o=$billing->order($this->uid,'basic','comp-key');
        $billing->settle($o['id'],'demo','demo_c',19900,'RUB');
        $sub=$this->db->one('SELECT * FROM subscriptions');
        $oldExpiry=(int)$sub['expires_at'];
        $c=$this->svc->create('active','days',10,'Компенсация дней',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$this->uid);
        $sub=$this->db->one('SELECT * FROM subscriptions');
        self::assertSame($oldExpiry+10*86400,(int)$sub['expires_at']);
    }
    public function testDaysCompensationCreatesNewSubscription():void
    {
        $c=$this->svc->create('all','days',7,'Компенсация дней',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$this->uid);
        $sub=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$this->uid]);
        self::assertNotNull($sub);
        self::assertSame('active',$sub['status']);
        self::assertSame(7,(int)round(((int)$sub['expires_at']-time())/86400));
    }
    public function testTrafficCompensation():void
    {
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('limited','Limited',29900,'RUB',30,10737418240,3,1)");
        $billing=new BillingService($this->db,$this->outbox,'demo');
        $o=$billing->order($this->uid,'limited','comp-traffic');
        $billing->settle($o['id'],'demo','demo_t',29900,'RUB');
        $c=$this->svc->create('active','traffic',20,'Компенсация трафика',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$this->uid);
        $sub=$this->db->one('SELECT * FROM subscriptions');
        self::assertSame(20,(int)$sub['purchased_traffic_gb']);
        self::assertCount(1,$this->db->all("SELECT * FROM outbox WHERE topic='subscription.traffic'"));
    }
    public function testTrafficCompensationSkipsUnlimited():void
    {
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb) VALUES('s1',NULL,?,'active',?,?,0)",[$this->uid,time()+86400,time()]);
        $c=$this->svc->create('active','traffic',20,'Компенсация трафика',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$this->uid);
        $sub=$this->db->one('SELECT * FROM subscriptions');
        self::assertSame(0,(int)$sub['purchased_traffic_gb']);
    }
    public function testInvalidInputRejected():void
    {
        $this->expectException(\App\Billing\BillingError::class);
        $this->svc->create('all','balance',0,'Причина',$this->adminUid,'admin');
    }
    public function testInvalidSegmentRejected():void
    {
        $this->expectException(\App\Billing\BillingError::class);
        $this->svc->create('nobody','balance',100,'Причина',$this->adminUid,'admin');
    }
    public function testInvalidKindRejected():void
    {
        $this->expectException(\App\Billing\BillingError::class);
        $this->svc->create('all','gold',100,'Причина',$this->adminUid,'admin');
    }
    public function testSegments():void
    {
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at) VALUES('s1',NULL,?,'active',?,?)",[$this->uid,time()+86400,time()]);
        $c=$this->svc->create('active','balance',100,'Тест',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        self::assertSame(1,(int)$this->db->one('SELECT total_count FROM compensations')['total_count']);
        $c2=$this->svc->create('inactive','balance',100,'Тест',$this->adminUid,'admin');
        $this->svc->run($c2['id']);
        self::assertSame(2,(int)$this->db->one('SELECT total_count FROM compensations WHERE id=?',[$c2['id']])['total_count']);
    }
}
