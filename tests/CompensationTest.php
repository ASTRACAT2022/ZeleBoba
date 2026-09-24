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
    public function testCompensationCompletesAfterEveryGrant():void
    {
        $c=$this->svc->create('all','balance',100,'Бонус',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        foreach([$this->uid,$this->uid2,$this->adminUid] as $userId) $this->svc->grant($c['id'],$userId);
        $this->svc->grant($c['id'],$this->uid);
        $row=$this->db->one('SELECT status,processed_count,total_count FROM compensations WHERE id=?',[$c['id']]);
        self::assertSame('completed',$row['status']);
        self::assertSame(3,(int)$row['processed_count']);
        self::assertSame(3,(int)$row['total_count']);
    }
    public function testEmptyCompensationCompletes():void
    {
        $c=$this->svc->create('active','balance',100,'Бонус',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        self::assertSame('completed',$this->db->one('SELECT status FROM compensations WHERE id=?',[$c['id']])['status']);
    }
    public function testDaysCompensationExtendsSubscription():void
    {
        $billing=new BillingService($this->db,$this->outbox,'demo');
        $o=$billing->order($this->uid,'basic','comp-key');
        $billing->settle($o['id'],'demo','demo_c',19900,'RUB');
        $sub=$this->db->one('SELECT * FROM subscriptions');
        $oldExpiry=(int)$sub['expires_at'];
        $c=$this->svc->create('all','days',10,'Компенсация дней',$this->adminUid,'admin','basic');
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$this->uid);
        $sub=$this->db->one('SELECT * FROM subscriptions');
        self::assertSame($oldExpiry+10*86400,(int)$sub['expires_at']);
    }
    public function testDaysWithoutPlanExtendExistingTariffAndSkipUsersWithoutSubscription():void
    {
        $billing=new BillingService($this->db,$this->outbox,'demo');
        $order=$billing->order($this->uid,'basic','comp-no-plan');
        $billing->settle($order['id'],'demo','demo_no_plan',19900,'RUB');
        $original=$this->db->one('SELECT id,plan_id,expires_at FROM subscriptions WHERE user_id=?',[$this->uid]);
        $c=$this->svc->create('all','days',3,'Компенсация без смены тарифа',$this->adminUid,'admin');
        self::assertNull($c['plan_id']);
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$this->uid);
        $this->svc->grant($c['id'],$this->uid2);
        $updated=$this->db->one('SELECT id,plan_id,expires_at FROM subscriptions WHERE user_id=?',[$this->uid]);
        self::assertSame($original['id'],$updated['id']);
        self::assertSame('basic',$updated['plan_id']);
        self::assertSame((int)$original['expires_at']+3*86400,(int)$updated['expires_at']);
        self::assertNull($this->db->one('SELECT id FROM subscriptions WHERE user_id=?',[$this->uid2]));
        self::assertSame('skipped',$this->db->one('SELECT status FROM compensation_targets WHERE compensation_id=? AND user_id=?',[$c['id'],$this->uid2])['status']);
        $this->svc->grant($c['id'],$this->uid);
        self::assertSame((int)$updated['expires_at'],(int)$this->db->one('SELECT expires_at FROM subscriptions WHERE id=?',[$original['id']])['expires_at']);
    }
    public function testDaysWithPlanDoNotChangeExistingTariff():void
    {
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('other','Other',29900,'RUB',30,10737418240,5,1)");
        $billing=new BillingService($this->db,$this->outbox,'demo');
        $order=$billing->order($this->uid,'basic','comp-other-plan');
        $billing->settle($order['id'],'demo','demo_other_plan',19900,'RUB');
        $c=$this->svc->create('all','days',3,'Компенсация',$this->adminUid,'admin','other');
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$this->uid);
        self::assertSame('basic',$this->db->one('SELECT plan_id FROM subscriptions WHERE user_id=?',[$this->uid])['plan_id']);
    }
    public function testDaysCompensationCreatesNewSubscription():void
    {
        $c=$this->svc->create('all','days',7,'Компенсация дней',$this->adminUid,'admin','basic');
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$this->uid);
        $sub=$this->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$this->uid]);
        self::assertNotNull($sub);
        self::assertSame('provisioning',$sub['status']);
        self::assertSame('basic',$sub['plan_id']);
        self::assertSame(7,(int)round(((int)$sub['expires_at']-time())/86400));
    }
    public function testTrafficCompensation():void
    {
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('limited','Limited',29900,'RUB',30,10737418240,3,1)");
        $billing=new BillingService($this->db,$this->outbox,'demo');
        $o=$billing->order($this->uid,'limited','comp-traffic');
        $billing->settle($o['id'],'demo','demo_t',29900,'RUB');
        $c=$this->svc->create('all','traffic',20,'Компенсация трафика',$this->adminUid,'admin');
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

    public function testRecipientSnapshotAndDuplicateSubmission():void
    {
        $key=Database::id();
        $c=$this->svc->create('all','balance',100,'За простой',$this->adminUid,'admin',null,$key);
        $same=$this->svc->create('all','balance',100,'За простой',$this->adminUid,'admin',null,$key);
        self::assertSame($c['id'],$same['id']);
        self::assertCount(1,$this->db->all('SELECT id FROM compensations'));
        $late=(new Auth($this->db))->register('late@example.org','correct-horse-battery');
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$late);
        self::assertSame(0,$this->wallet->balance($late)['balance_kopeks']);
        self::assertSame(3,(int)$this->db->one('SELECT total_count FROM compensations WHERE id=?',[$c['id']])['total_count']);
    }

    public function testTrafficSnapshotOnlyIncludesLimitedSubscriptions():void
    {
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb) VALUES('unlimited',NULL,?,'active',?,?,0)",[$this->uid,time()+86400,time()]);
        $c=$this->svc->create('all','traffic',5,'За простой',$this->adminUid,'admin');
        self::assertSame(0,(int)$c['total_count']);
        $this->svc->run($c['id']);
        self::assertSame('completed',$this->db->one('SELECT status FROM compensations WHERE id=?',[$c['id']])['status']);
    }

    public function testTrafficSkipIsVisibleIfSubscriptionExpiresAfterSnapshot():void
    {
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb) VALUES('limited',NULL,?,'active',?,?,10)",[$this->uid,time()+86400,time()]);
        $c=$this->svc->create('all','traffic',5,'За простой',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        $this->db->execute("UPDATE subscriptions SET expires_at=? WHERE id='limited'",[time()-1]);
        $this->svc->grant($c['id'],$this->uid);
        $row=$this->db->one('SELECT processed_count,skipped_count,status FROM compensations WHERE id=?',[$c['id']]);
        self::assertSame(0,(int)$row['processed_count']);
        self::assertSame(1,(int)$row['skipped_count']);
        self::assertSame('completed',$row['status']);
    }

    public function testFailedGrantCanBeRetriedWithoutDoubleCredit():void
    {
        $c=$this->svc->create('all','balance',500,'За простой',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        $key='cgrant:'.$c['id'].':'.$this->uid;
        $this->db->execute("UPDATE outbox SET status='dead',attempts=8 WHERE dedup_key=?",[$key]);
        $this->svc->markFailed($c['id'],$this->uid);
        self::assertSame(1,(int)$this->db->one('SELECT failed_count FROM compensations WHERE id=?',[$c['id']])['failed_count']);
        self::assertSame(1,$this->svc->retryFailed($c['id'],$this->adminUid));
        self::assertSame('pending',$this->db->one('SELECT status FROM outbox WHERE dedup_key=?',[$key])['status']);
        $this->svc->grant($c['id'],$this->uid);
        $this->svc->grant($c['id'],$this->uid);
        self::assertSame(500,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertSame(0,(int)$this->db->one('SELECT failed_count FROM compensations WHERE id=?',[$c['id']])['failed_count']);
    }
    public function testRetryingDeadPanelSyncDoesNotGrantDaysAgain():void
    {
        $billing=new BillingService($this->db,$this->outbox,'demo');
        $order=$billing->order($this->uid,'basic','comp-retry-sync');
        $billing->settle($order['id'],'demo','demo_retry_sync',19900,'RUB');
        $c=$this->svc->create('all','days',3,'За сбой',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        $this->svc->grant($c['id'],$this->uid);
        $sub=$this->db->one('SELECT id,expires_at FROM subscriptions WHERE user_id=?',[$this->uid]);
        $key='comp-days:'.$c['id'].':'.$sub['id'];
        $this->db->execute("UPDATE outbox SET status='dead',attempts=8 WHERE dedup_key=?",[$key]);
        self::assertSame(1,$this->svc->retryFailed($c['id'],$this->adminUid));
        self::assertSame('pending',$this->db->one('SELECT status FROM outbox WHERE dedup_key=?',[$key])['status']);
        self::assertSame((int)$sub['expires_at'],(int)$this->db->one('SELECT expires_at FROM subscriptions WHERE id=?',[$sub['id']])['expires_at']);
        self::assertSame(1,(int)$this->db->one('SELECT processed_count FROM compensations WHERE id=?',[$c['id']])['processed_count']);
    }

    public function testOutboxExhaustionMarksGrantFailedAndCanRetry():void
    {
        $c=$this->svc->create('all','balance',500,'За простой',$this->adminUid,'admin');
        $this->svc->run($c['id']);
        $this->db->execute("UPDATE outbox SET status='done' WHERE topic='compensation.run'");
        $key='cgrant:'.$c['id'].':'.$this->uid;
        $this->db->execute("UPDATE outbox SET attempts=7,priority=1000 WHERE dedup_key=?",[$key]);
        $this->outbox->runOne(static function():void { throw new \RuntimeException('Temporary failure'); });
        self::assertSame('dead',$this->db->one('SELECT status FROM outbox WHERE dedup_key=?',[$key])['status']);
        self::assertSame(1,(int)$this->db->one('SELECT failed_count FROM compensations WHERE id=?',[$c['id']])['failed_count']);
        self::assertSame(1,$this->svc->retryFailed($c['id'],$this->adminUid));
        $this->svc->grant($c['id'],$this->uid);
        self::assertSame(500,$this->wallet->balance($this->uid)['balance_kopeks']);
    }

    public function testDeadRunCanBeRetried():void
    {
        $c=$this->svc->create('all','balance',500,'За простой',$this->adminUid,'admin');
        $this->db->execute("UPDATE outbox SET attempts=7 WHERE topic='compensation.run'");
        $this->outbox->runOne(static function():void { throw new \RuntimeException('Temporary failure'); });
        self::assertSame(1,(int)$this->db->one('SELECT queue_error FROM compensations WHERE id=?',[$c['id']])['queue_error']);
        self::assertSame(1,$this->svc->retryFailed($c['id'],$this->adminUid));
        self::assertSame('pending',$this->db->one("SELECT status FROM outbox WHERE topic='compensation.run'")['status']);
    }

    public function testLargeRunQueuesBoundedBatches():void
    {
        for($i=0;$i<205;$i++)$this->db->execute('INSERT INTO users(id,email,created_at) VALUES(?,?,?)',[Database::id(),'bulk'.$i.'@example.org',time()]);
        $c=$this->svc->create('all','balance',100,'За простой',$this->adminUid,'admin');
        self::assertSame(208,(int)$c['total_count']);
        $this->svc->run($c['id']);
        self::assertSame(200,(int)$this->db->one('SELECT queued_count FROM compensations WHERE id=?',[$c['id']])['queued_count']);
        $this->svc->run($c['id']);
        self::assertSame(208,(int)$this->db->one('SELECT queued_count FROM compensations WHERE id=?',[$c['id']])['queued_count']);
        self::assertSame(208,(int)$this->db->one("SELECT COUNT(*) AS n FROM outbox WHERE topic='compensation.grant'")['n']);
    }

    public function testMigrationKeepsLegacyGrantReceipts():void
    {
        $dir=sys_get_temp_dir().'/compensation-migration-'.Database::id();
        mkdir($dir);
        try {
            $newMigration=__DIR__.'/../migrations/039_compensation_reliability.sql';
            foreach(glob(__DIR__.'/../migrations/*.sql') as $path) {
                if($path!==$newMigration)symlink($path,$dir.'/'.basename($path));
            }
            $legacy=new Database('sqlite::memory:');
            $legacy->migrate($dir);
            $legacy->execute("INSERT INTO users(id,email,created_at) VALUES('legacy-user','legacy@example.org',?)",[time()]);
            $legacy->execute("INSERT INTO compensations(id,segment,kind,value,reason,total_count,processed_count,status,admin_id,created_at) VALUES('legacy-comp','all','balance',100,'За простой',1,1,'completed','legacy-user',?)",[time()]);
            $legacy->execute("INSERT INTO compensation_grants(id,compensation_id,user_id,created_at) VALUES('legacy-grant','legacy-comp','legacy-user',?)",[time()]);
            symlink($newMigration,$dir.'/039_compensation_reliability.sql');
            $legacy->migrate($dir);
            self::assertSame('applied',$legacy->one("SELECT status FROM compensation_targets WHERE compensation_id='legacy-comp' AND user_id='legacy-user'")['status']);
        } finally {
            foreach(glob($dir.'/*') as $path)unlink($path);
            rmdir($dir);
        }
    }
}
