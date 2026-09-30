<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\BillingService;
use App\Integration\Provisioner;
use App\Subscriptions\SubscriptionSyncService;
final class PostgresConcurrencyTest extends TestCase
{
    public function testDelayedPanelResponseCannotConfirmStaleSubscription():void
    {
        $dsn=getenv('TEST_POSTGRES_DSN');
        if (!$dsn || !function_exists('pcntl_fork')) self::markTestSkipped('Set TEST_POSTGRES_DSN to a dedicated PostgreSQL test database; pcntl required.');
        $schema='test_'.bin2hex(random_bytes(8));
        $connect=static function()use($dsn,$schema):Database {
            $db=new Database($dsn,getenv('TEST_POSTGRES_USER')?:'',getenv('TEST_POSTGRES_PASSWORD')?:'');
            $db->execute('SET search_path TO '.$schema);
            return $db;
        };
        $db=$connect();
        $db->execute('CREATE SCHEMA '.$schema);
        $db->migrate(__DIR__.'/../migrations');
        $db->execute("INSERT INTO users(id,created_at) VALUES('sync-user',0)");
        $oldExpiry=time()+86400;
        $newExpiry=$oldExpiry+30*86400;
        $db->execute("INSERT INTO subscriptions(id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit,sync_status) VALUES('sync-race','sync-user','active',?,?,10,1,'pending')",[$oldExpiry,time()]);
        // libpq connections cannot be shared across forked processes.
        unset($db);
        $directory=sys_get_temp_dir().'/zb-sync-race-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $entered=$directory.'/entered';$release=$directory.'/release';$childResult=$directory.'/result';$observed=$directory.'/observed';
        $childPid=null;
        try {
            $childPid=pcntl_fork();
            if($childPid===-1)self::fail('fork failed');
            if($childPid===0){
                try {
                    $childDb=$connect();
                    $slowPanel=new class($entered,$release) implements Provisioner {
                        public function __construct(private string $entered,private string $release) {}
                        public function provision(array $subscription):array {
                            touch($this->entered);
                            $until=microtime(true)+10;
                            while(!file_exists($this->release)){
                                if(microtime(true)>$until)throw new \RuntimeException('sync barrier timeout');
                                usleep(10000);
                            }
                            return ['id'=>'451','url'=>'https://panel.example/sub/451'];
                        }
                        public function extend(array $subscription):void {}
                        public function setTraffic(array $subscription,int $trafficGb):void {}
                        public function setDevices(array $subscription,int $devices):void {}
                    };
                    file_put_contents($childResult,(new SubscriptionSyncService($childDb,$slowPanel,'remnawave'))->syncOne('sync-race'));
                    exit(0);
                } catch(\Throwable $e) {fwrite(STDERR,$e->getMessage()."\n");exit(1);}
            }
            $db=$connect();
            $until=microtime(true)+10;
            while(!file_exists($entered)){
                if(microtime(true)>$until)self::fail('first sync did not reach the panel');
                usleep(10000);
            }
            $db->execute("UPDATE subscriptions SET expires_at=?,version=version+1,sync_status='pending' WHERE id='sync-race'",[$newExpiry]);
            $latestPanel=new class($observed) implements Provisioner {
                public function __construct(private string $observed) {}
                public function provision(array $subscription):array {
                    file_put_contents($this->observed,(string)$subscription['expires_at']);
                    return ['id'=>'451','url'=>'https://panel.example/sub/451'];
                }
                public function extend(array $subscription):void {file_put_contents($this->observed,(string)$subscription['expires_at']);}
                public function setTraffic(array $subscription,int $trafficGb):void {}
                public function setDevices(array $subscription,int $devices):void {}
            };
            $sync=new SubscriptionSyncService($db,$latestPanel,'remnawave');
            self::assertSame('pending',$sync->syncOne('sync-race')); // old sync holds the advisory lock
            self::assertFileDoesNotExist($observed);
            touch($release);
            pcntl_waitpid($childPid,$status);$childPid=null;
            self::assertSame(0,pcntl_wexitstatus($status));
            self::assertSame('pending',file_get_contents($childResult));
            self::assertSame('pending',$db->one("SELECT sync_status FROM subscriptions WHERE id='sync-race'")['sync_status']);
            self::assertSame('synced',$sync->syncOne('sync-race'));
            self::assertSame((string)$newExpiry,file_get_contents($observed));
            self::assertSame($newExpiry,(int)$db->one("SELECT expires_at FROM subscriptions WHERE id='sync-race'")['expires_at']);
        } finally {
            touch($release);
            if($childPid!==null)pcntl_waitpid($childPid,$status);
            foreach([$entered,$release,$childResult,$observed] as $file)@unlink($file);
            @rmdir($directory);
            $cleanupDb=$connect();
            $cleanupDb->execute('DROP SCHEMA '.$schema.' CASCADE');
        }
    }

    public function testConcurrentCheckoutSettlementAndWorkers():void
    {
        $dsn=getenv('TEST_POSTGRES_DSN');
        if (!$dsn || !function_exists('pcntl_fork')) self::markTestSkipped('Set TEST_POSTGRES_DSN to a dedicated PostgreSQL test database; pcntl required.');
        $schema='test_'.bin2hex(random_bytes(8));
        $connect=static function()use($dsn,$schema):Database{$db=new Database($dsn,getenv('TEST_POSTGRES_USER')?:'',getenv('TEST_POSTGRES_PASSWORD')?:'');$db->execute('SET search_path TO '.$schema);return $db;};
        $db=$connect();$db->execute('CREATE SCHEMA '.$schema);$db->migrate(__DIR__.'/../migrations');$db->execute("INSERT INTO users(id,created_at) VALUES('test-user',0)");$db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");unset($db);
        $race=function(callable $work):void{
            $children=[];
            for($n=0;$n<6;$n++){$pid=pcntl_fork();if($pid===-1)self::fail('fork failed');if($pid===0){try{$work();exit(0);}catch(\Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n");exit(1);}}$children[]=$pid;}
            foreach($children as $pid){pcntl_waitpid($pid,$status);self::assertSame(0,pcntl_wexitstatus($status));}
        };
        try {
            $race(function()use($connect){$db=$connect();(new BillingService($db,new Outbox($db),'demo'))->order('test-user','basic','concurrent-key');});
            $db=$connect();$orders=$db->all('SELECT * FROM orders');self::assertCount(1,$orders);$id=$orders[0]['id'];unset($db);
            $race(function()use($connect,$id){$db=$connect();(new BillingService($db,new Outbox($db),'demo'))->settle($id,'demo','shared-payment',19900,'RUB');});
            $db=$connect();self::assertCount(1,$db->all('SELECT * FROM subscriptions'));self::assertCount(1,$db->all('SELECT * FROM payment_receipts'));self::assertCount(2,$db->all('SELECT * FROM ledger_entries'));self::assertSame(0,(int)$db->one('SELECT SUM(amount_minor) AS total FROM ledger_entries')['total']);
            $db->execute('CREATE TABLE processed (key VARCHAR(100) PRIMARY KEY)');unset($db);
            $race(function()use($connect){$db=$connect();$outbox=new Outbox($db);while($outbox->runOne(function($topic,$payload)use($db){$db->execute('INSERT INTO processed VALUES(?)',[$topic]);usleep(50000);})){};});
            $db=$connect();self::assertCount(1,$db->all('SELECT * FROM processed'));self::assertCount(1,$db->all("SELECT * FROM outbox WHERE status='done'"));
            $login=new \App\Identity\TelegramLogin($db,new \App\Identity\Auth($db));$token=$login->magic('123456789');unset($login,$db);
            $race(function()use($connect,$token){$db=$connect();$login=new \App\Identity\TelegramLogin($db,new \App\Identity\Auth($db));try{$login->consume($token,'magic');}catch(\App\Billing\BillingError){}});
            $db=$connect();self::assertCount(1,$db->all('SELECT * FROM sessions'));
            self::assertCount(1,$db->all("SELECT * FROM login_challenges WHERE state='consumed'"));

            // Concurrent free-trial requests must produce only one entitlement.
            $db->execute("INSERT INTO users(id,email,created_at) VALUES('trial-user','trial@example.test',0)");
            $db->execute("UPDATE plans SET is_trial_available=1,trial_duration_days=3 WHERE id='basic'");
            unset($db);
            $race(function()use($connect){
                $db=$connect();
                $service=new \App\Billing\TrialService($db,new Outbox($db),new \App\Billing\Wallet($db));
                try { $service->start('trial-user','basic'); } catch (\App\Billing\BillingError) {}
            });
            $db=$connect();
            self::assertCount(1,$db->all("SELECT id FROM subscriptions WHERE user_id='trial-user'"));
            $wallet=new \App\Billing\Wallet($db);
            $wallet->credit('trial-user',50000,'manual_adjust','fixture');
            unset($db,$wallet);
            $race(function()use($connect){
                $db=$connect();
                (new BillingService($db,new Outbox($db),'demo'))->purchaseFromBalance('trial-user','basic','concurrent-balance-key');
            });
            $db=$connect();
            self::assertSame(30100,(int)$db->one("SELECT balance_kopeks FROM users WHERE id='trial-user'")['balance_kopeks']);
            self::assertCount(1,$db->all("SELECT id FROM orders WHERE idempotency_key='concurrent-balance-key'"));
            $auth=new \App\Identity\Auth($db);
            $reset=$auth->createPasswordReset('trial@example.test');
            $auth->issue('trial-user');
            unset($db,$auth);
            $race(function()use($connect,$reset){
                $db=$connect();
                (new \App\Identity\Auth($db))->applyPasswordReset($reset,'correct-reset-password');
            });
            $db=$connect();
            self::assertCount(0,$db->all("SELECT id FROM sessions WHERE user_id='trial-user'"));
            self::assertSame('trial-user',(new \App\Identity\Auth($db))->login('trial@example.test','correct-reset-password'));
        } finally { $db=$connect();$db->execute('DROP SCHEMA '.$schema.' CASCADE'); }
    }
}
