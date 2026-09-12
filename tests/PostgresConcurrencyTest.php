<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\BillingService;
final class PostgresConcurrencyTest extends TestCase
{
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
            $db=$connect();self::assertCount(2,$db->all('SELECT * FROM processed'));self::assertCount(2,$db->all("SELECT * FROM outbox WHERE status='done'"));
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
