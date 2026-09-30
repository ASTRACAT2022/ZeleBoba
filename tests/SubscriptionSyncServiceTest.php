<?php
declare(strict_types=1);
namespace Tests;

use App\Billing\BillingService;
use App\Identity\Auth;
use App\Infrastructure\{Database,Outbox};
use App\Integration\Provisioner;
use App\Subscriptions\SubscriptionSyncService;
use PHPUnit\Framework\TestCase;

final class SubscriptionSyncServiceTest extends TestCase
{
    private Database $db;

    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');
        $this->db->migrate(__DIR__.'/../migrations');
    }

    public function testPanelFailureCannotRollbackPaymentAndCronRetriesSubscription():void
    {
        $user=(new Auth($this->db))->register('sync@example.test','correct horse battery staple');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('sync','Monthly',19900,'RUB',30,0,1,1)");
        $available=false;
        $provisioner=new class($available) implements Provisioner {
            private bool $available;
            public function __construct(bool &$available){$this->available=&$available;}
            public function provision(array $subscription):array {if(!$this->available)throw new \RuntimeException('panel unavailable');return ['id'=>'451','url'=>'https://panel.example/sub/451'];}
            public function extend(array $subscription):void {if(!$this->available)throw new \RuntimeException('panel unavailable');}
            public function setTraffic(array $subscription,int $trafficGb):void{}
            public function setDevices(array $subscription,int $devices):void{}
        };
        $sync=new SubscriptionSyncService($this->db,$provisioner,'remnawave');
        $billing=new BillingService($this->db,new Outbox($this->db),'demo');
        $billing->setSubscriptionSync($sync);
        $order=$billing->order($user,'sync','sync-payment-key');
        $this->db->execute("UPDATE orders SET provision_driver='remnawave' WHERE id=?",[$order['id']]);
        $billing->settle($order['id'],'demo','payment-sync-1',19900,'RUB');

        self::assertSame('succeeded',$this->db->one("SELECT status FROM payments WHERE provider_payment_id='payment-sync-1'")['status']);
        self::assertSame('error',$this->db->one('SELECT sync_status FROM subscriptions WHERE order_id=?',[$order['id']])['sync_status']);
        self::assertSame('active',$this->db->one('SELECT status FROM subscriptions WHERE order_id=?',[$order['id']])['status']);
        self::assertSame('active',$this->db->one('SELECT lifecycle_status FROM subscriptions WHERE order_id=?',[$order['id']])['lifecycle_status']);
        self::assertSame('paid',$this->db->one('SELECT status FROM orders WHERE id=?',[$order['id']])['status']);

        $available=true;
        $result=$sync->run(100);
        self::assertSame(['checked'=>1,'synced'=>1,'errors'=>0,'pending'=>0],$result);
        self::assertSame('synced',$this->db->one('SELECT sync_status FROM subscriptions WHERE order_id=?',[$order['id']])['sync_status']);
        self::assertSame('active',$this->db->one('SELECT status FROM subscriptions WHERE order_id=?',[$order['id']])['status']);
    }

    public function testSettlementRollsBackTogetherWhenSubscriptionInsertFails():void
    {
        $user=(new Auth($this->db))->register('rollback@example.test','correct horse battery staple');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('rollback','Monthly',19900,'RUB',30,0,1,1)");
        $billing=new BillingService($this->db,new Outbox($this->db),'demo');
        $order=$billing->order($user,'rollback','rollback-payment-key');
        $this->db->execute("CREATE TRIGGER reject_subscription BEFORE INSERT ON subscriptions BEGIN SELECT RAISE(ABORT,'simulated subscription write failure'); END");
        try{$billing->settle($order['id'],'demo','payment-rollback-1',19900,'RUB');self::fail('Settlement must fail when subscription write fails');}
        catch(\Throwable $e){self::assertStringContainsString('simulated subscription write failure',$e->getMessage());}
        self::assertSame(0,(int)$this->db->one("SELECT COUNT(*) n FROM payments WHERE provider_payment_id='payment-rollback-1'")['n']);
        self::assertSame(0,(int)$this->db->one("SELECT COUNT(*) n FROM payment_receipts WHERE payment_id='payment-rollback-1'")['n']);
        self::assertSame('pending',$this->db->one('SELECT status FROM orders WHERE id=?',[$order['id']])['status']);
    }

    public function testPanelCallWaitsForOutermostCommitAndIsDiscardedOnRollback():void
    {
        $user=(new Auth($this->db))->register('outer@example.test','correct horse battery staple');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('outer','Monthly',19900,'RUB',30,0,1,1)");
        $calls=0;
        $provisioner=new class($this->db,$calls) implements Provisioner {
            private int $calls;
            public function __construct(private Database $db,int &$calls){$this->calls=&$calls;}
            public function provision(array $subscription):array {
                TestCase::assertFalse($this->db->pdo->inTransaction());
                $this->calls++;
                return ['id'=>'451','url'=>'https://panel.example/sub/451'];
            }
            public function extend(array $subscription):void { $this->calls++; }
            public function setTraffic(array $subscription,int $trafficGb):void {}
            public function setDevices(array $subscription,int $devices):void {}
        };
        $billing=new BillingService($this->db,new Outbox($this->db),'demo');
        $billing->setSubscriptionSync(new SubscriptionSyncService($this->db,$provisioner,'remnawave'));
        $order=$billing->order($user,'outer','outer-payment-key');
        $this->db->execute("UPDATE orders SET provision_driver='remnawave' WHERE id=?",[$order['id']]);
        try {
            $this->db->transaction(function() use($billing,$order,&$calls):void {
                $billing->settle($order['id'],'demo','outer-payment',19900,'RUB');
                self::assertSame(0,$calls);
                throw new \RuntimeException('outer rollback');
            });
            self::fail('Outer transaction should roll back');
        } catch(\RuntimeException $e) { self::assertSame('outer rollback',$e->getMessage()); }
        self::assertSame(0,$calls);
        self::assertSame(0,(int)$this->db->one("SELECT COUNT(*) n FROM payments WHERE provider_payment_id='outer-payment'")['n']);

        $billing->settle($order['id'],'demo','outer-payment',19900,'RUB');
        self::assertSame(1,$calls);
        self::assertSame('synced',$this->db->one('SELECT sync_status FROM subscriptions WHERE order_id=?',[$order['id']])['sync_status']);
    }

    public function testOlderPanelResultCannotClearNewerPendingTerms():void
    {
        $user=(new Auth($this->db))->register('stale@example.test','correct horse battery staple');
        $this->db->execute("INSERT INTO subscriptions(id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit,sync_status) VALUES('stale-sub',?,'active',?,?,10,1,'pending')",[$user,time()+86400,time()]);
        $calls=0;
        $provisioner=new class($this->db,$calls) implements Provisioner {
            private int $calls;
            public function __construct(private Database $db,int &$calls){$this->calls=&$calls;}
            public function provision(array $subscription):array {
                $this->calls++;
                if($this->calls===1)$this->db->execute("UPDATE subscriptions SET expires_at=expires_at+86400,version=version+1,sync_status='pending' WHERE id=?",[$subscription['id']]);
                return ['id'=>'451','url'=>'https://panel.example/sub/451'];
            }
            public function extend(array $subscription):void {}
            public function setTraffic(array $subscription,int $trafficGb):void {}
            public function setDevices(array $subscription,int $devices):void {}
        };
        $sync=new SubscriptionSyncService($this->db,$provisioner,'remnawave');
        self::assertSame('pending',$sync->syncOne('stale-sub'));
        self::assertSame('pending',$this->db->one("SELECT sync_status FROM subscriptions WHERE id='stale-sub'")['sync_status']);
        self::assertSame('synced',$sync->syncOne('stale-sub'));
        self::assertSame(2,$calls);
    }

    public function testRenewalOrderIsFulfilledOnlyAfterLatestTermsSync():void
    {
        $user=(new Auth($this->db))->register('renew-sync@example.test','correct horse battery staple');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('renew-sync','Monthly',19900,'RUB',30,0,1,1)");
        $available=true;
        $provisioner=new class($available) implements Provisioner {
            private bool $available;
            public function __construct(bool &$available){$this->available=&$available;}
            public function provision(array $subscription):array {return ['id'=>'451','url'=>'https://panel.example/sub/451'];}
            public function extend(array $subscription):void {if(!$this->available)throw new \RuntimeException('panel unavailable');}
            public function setTraffic(array $subscription,int $trafficGb):void {}
            public function setDevices(array $subscription,int $devices):void {}
        };
        $billing=new BillingService($this->db,new Outbox($this->db),'demo');
        $sync=new SubscriptionSyncService($this->db,$provisioner,'remnawave');
        $billing->setSubscriptionSync($sync);
        $first=$billing->order($user,'renew-sync','initial-sync-key');
        $this->db->execute("UPDATE orders SET provision_driver='remnawave' WHERE id=?",[$first['id']]);
        $billing->settle($first['id'],'demo','first-sync-payment',19900,'RUB');
        $subscription=$this->db->one('SELECT id FROM subscriptions WHERE order_id=?',[$first['id']]);
        self::assertSame('fulfilled',$this->db->one('SELECT status FROM orders WHERE id=?',[$first['id']])['status']);

        $available=false;
        $renewal=$billing->order($user,'renew-sync','renewal-sync-key',renewSubscriptionId:$subscription['id']);
        $this->db->execute("UPDATE orders SET provision_driver='remnawave' WHERE id=?",[$renewal['id']]);
        $billing->settle($renewal['id'],'demo','renewal-sync-payment',19900,'RUB');
        self::assertSame('paid',$this->db->one('SELECT status FROM orders WHERE id=?',[$renewal['id']])['status']);
        self::assertSame('error',$this->db->one('SELECT sync_status FROM subscriptions WHERE id=?',[$subscription['id']])['sync_status']);

        $available=true;
        self::assertSame('synced',$sync->syncOne($subscription['id']));
        self::assertSame('fulfilled',$this->db->one('SELECT status FROM orders WHERE id=?',[$renewal['id']])['status']);
    }

    public function testCommittedPaymentRecoversAfterProcessExitBeforePanelCall():void
    {
        if(!function_exists('pcntl_fork'))self::markTestSkipped('pcntl required');
        $path=tempnam(sys_get_temp_dir(),'zb-commit-');
        self::assertNotFalse($path);
        try {
            $db=new Database('sqlite:'.$path);
            $db->migrate(__DIR__.'/../migrations');
            $user=(new Auth($db))->register('restart@example.test','correct horse battery staple');
            $db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('restart','Monthly',19900,'RUB',30,0,1,1)");
            $billing=new BillingService($db,new Outbox($db),'demo');
            $order=$billing->order($user,'restart','restart-order-key');
            $db->execute("UPDATE orders SET provision_driver='remnawave' WHERE id=?",[$order['id']]);
            unset($billing,$db);
            $pid=pcntl_fork();
            if($pid===-1)self::fail('fork failed');
            if($pid===0){
                try {
                    $childDb=new Database('sqlite:'.$path);
                    (new BillingService($childDb,new Outbox($childDb),'demo'))->settle($order['id'],'demo','restart-payment',19900,'RUB');
                    exit(0); // process stops immediately after the durable commit
                }catch(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
            }
            pcntl_waitpid($pid,$status);
            self::assertSame(0,pcntl_wexitstatus($status));
            $restartedDb=new Database('sqlite:'.$path);
            self::assertSame('succeeded',$restartedDb->one("SELECT status FROM payments WHERE provider_payment_id='restart-payment'")['status']);
            $sub=$restartedDb->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
            self::assertSame('active',$sub['status']);
            self::assertSame('pending',$sub['sync_status']);
            $panel=new class implements Provisioner {
                public function provision(array $subscription):array {return ['id'=>'451','url'=>'https://panel.example/sub/451'];}
                public function extend(array $subscription):void {}
                public function setTraffic(array $subscription,int $trafficGb):void {}
                public function setDevices(array $subscription,int $devices):void {}
            };
            $sync=new SubscriptionSyncService($restartedDb,$panel,'remnawave');
            self::assertSame('synced',$sync->syncOne($sub['id']));
            self::assertSame('fulfilled',$restartedDb->one('SELECT status FROM orders WHERE id=?',[$order['id']])['status']);
            unset($restartedDb);
        }finally{
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }
}
