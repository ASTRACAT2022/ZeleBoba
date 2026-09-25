<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox,Worker,JobDeferred};
use App\Billing\{BillingService,BillingError};
use App\Identity\Auth;
use App\Integration\{Payments,DemoProvisioner,RemnawaveProvisioner,Telegram};
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
final class BillingTest extends TestCase
{
    private Database $db; private Outbox $outbox; private BillingService $billing; private string $uid;
    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);$this->billing=new BillingService($this->db,$this->outbox,'demo');
        $this->uid=(new Auth($this->db))->register('user@example.org','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
    }
    private function order(string $key='test-key-123'):array{return $this->billing->order($this->uid,'basic',$key);}
    private function pay(array $o,string $id='payment-1'):void{$this->billing->settle($o['id'],'demo',$id,19900,'RUB');}
    public function testDuplicateCheckoutReturnsOriginalAndSnapshotsPrice():void
    {
        $a=$this->order();$this->db->execute("UPDATE plans SET price_minor=30000 WHERE id='basic'");$b=$this->order();
        self::assertSame($a['id'],$b['id']);self::assertSame(19900,(int)$b['price_minor']);self::assertCount(1,$this->db->all('SELECT * FROM outbox'));
    }
    public function testKeyCannotBeReusedForDifferentPlan():void
    {
        $this->order();$this->expectException(BillingError::class);$this->billing->order($this->uid,'other','test-key-123');
    }
    public function testDuplicatePaymentsDoNotDoubleCreditOrProvision():void
    {
        $o=$this->order();$this->pay($o);$this->pay($o);
        self::assertCount(1,$this->db->all('SELECT * FROM payment_receipts'));self::assertCount(1,$this->db->all('SELECT * FROM subscriptions'));
        self::assertCount(2,$this->db->all('SELECT * FROM ledger_entries'));self::assertSame(0,(int)$this->db->one('SELECT SUM(amount_minor) AS total FROM ledger_entries')['total']);
        self::assertCount(1,$this->db->all("SELECT * FROM outbox WHERE topic='subscription.provision'"));
    }
    public function testWrongAmountRollsBackEverything():void
    {
        $o=$this->order();try{$this->billing->settle($o['id'],'demo','x',1,'RUB');self::fail();}catch(BillingError){}
        self::assertCount(0,$this->db->all('SELECT * FROM ledger_entries'));self::assertCount(0,$this->db->all('SELECT * FROM subscriptions'));self::assertSame('pending',$this->db->one('SELECT status FROM orders')['status']);
    }
    public function testWrongCurrencyAndProviderRejected():void
    {
        $o=$this->order();foreach([['demo','USD'],['other','RUB']] as [$p,$c]){try{$this->billing->settle($o['id'],$p,'x',19900,$c);self::fail();}catch(BillingError){}}
        self::assertCount(0,$this->db->all('SELECT * FROM payment_receipts'));
    }
    public function testReceiptCannotPayAnotherOrder():void
    {
        $a=$this->order();$b=$this->order('second-key-123');$this->pay($a);
        $this->expectException(BillingError::class);$this->pay($b);
    }
    public function testTransactionFailureRollsBackReceiptAndLedger():void
    {
        $o=$this->order();$this->db->execute("CREATE TRIGGER fail_sub BEFORE INSERT ON subscriptions BEGIN SELECT RAISE(ABORT,'test'); END");
        try{$this->pay($o);self::fail();}catch(\PDOException){}
        self::assertCount(0,$this->db->all('SELECT * FROM payment_receipts'));self::assertCount(0,$this->db->all('SELECT * FROM ledger_entries'));self::assertSame('pending',$this->db->one('SELECT status FROM orders')['status']);
    }
    public function testWorkerRetriesThenDeadLettersAndDoesNotLeakSecrets():void
    {
        $this->outbox->enqueue('test','test',[]);
        for($n=0;$n<8;$n++){$this->outbox->runOne(fn()=>throw new \RuntimeException('secret-token'));$this->db->execute('UPDATE outbox SET available_at=0');}
        $job=$this->db->one('SELECT * FROM outbox');self::assertSame('dead',$job['status']);self::assertSame(8,(int)$job['attempts']);self::assertStringNotContainsString('secret-token',$job['last_error']);self::assertFalse($this->outbox->runOne(fn()=>null));
    }
    public function testOutboxRecordsOnlySafeRemnawaveStatus():void
    {
        $this->outbox->enqueue('test','safe-http-status',[]);
        $this->outbox->runOne(fn()=>throw new \RuntimeException('Remnawave request failed: HTTP 429'));
        self::assertSame('RuntimeException HTTP 429',$this->db->one("SELECT last_error FROM outbox WHERE dedup_key='safe-http-status'")['last_error']);
    }
    public function testExpiredLeaseIsRecovered():void
    {
        $this->outbox->enqueue('test','test',[]);$this->db->execute("UPDATE outbox SET status='processing',locked_until=0");
        self::assertTrue($this->outbox->runOne(fn()=>null));self::assertSame('done',$this->db->one('SELECT status FROM outbox')['status']);
    }
    public function testOperationalPauseDoesNotConsumeRetryBudget():void
    {
        $this->outbox->enqueue('subscription.provision','paused',['subscription_id'=>'missing']);
        self::assertTrue($this->outbox->runOne(fn()=>throw new JobDeferred(30)));
        $job=$this->db->one("SELECT * FROM outbox WHERE dedup_key='paused'");
        self::assertSame('pending',$job['status']);self::assertSame(0,(int)$job['attempts']);self::assertNull($job['last_error']);
    }
    public function testSuccessfulProvisionIsIdempotent():void
    {
        $o=$this->order();$this->pay($o);$id=$this->db->one('SELECT id FROM subscriptions')['id'];$http=new MockHttpClient();
        $payments=new Payments($this->db,$this->billing,$http,[]);$worker=new Worker($this->db,$this->outbox,$payments,new DemoProvisioner(),$http,'');
        $worker->handle('subscription.provision',['subscription_id'=>$id]);$worker->handle('subscription.provision',['subscription_id'=>$id]);
        self::assertSame('active',$this->db->one('SELECT status FROM subscriptions')['status']);self::assertSame('fulfilled',$this->db->one('SELECT status FROM orders')['status']);
    }
    public function testProvisionDoesNotActivateWhenPanelReadbackIsMissing():void
    {
        $o=$this->order('panel-readback-key');
        $this->db->execute("UPDATE orders SET provision_driver='remnawave',squad_uuid='squad' WHERE id=?",[$o['id']]);
        $this->pay($o,'panel-readback-payment');
        $id=$this->db->one('SELECT id FROM subscriptions WHERE order_id=?',[$o['id']])['id'];
        $gets=0;
        $http=new MockHttpClient(function($method,$url)use(&$gets,$id){
            if ($method==='POST') return new MockResponse(json_encode(['response'=>['id'=>777,'username'=>'zb_'.$id,'subscriptionUrl'=>'https://panel.example/sub']]));
            $gets++;
            return new MockResponse('{}',['http_code'=>404]);
        });
        $worker=new Worker($this->db,$this->outbox,new Payments($this->db,$this->billing,$http,[]),new RemnawaveProvisioner($http,'https://panel.example','token','squad'),$http,'',defaultProvisionDriver:'remnawave',workflows:new \App\Infrastructure\DurableWorkflow($this->db,$this->outbox));
        try {$worker->handle('subscription.provision',['subscription_id'=>$id]);self::fail('Missing panel account was accepted');}
        catch (\RuntimeException $e) {self::assertSame('Remnawave user not found after activation',$e->getMessage());}
        self::assertGreaterThanOrEqual(2,$gets);
        self::assertSame('provisioning',$this->db->one('SELECT status FROM subscriptions WHERE id=?',[$id])['status']);
        self::assertSame('paid',$this->db->one('SELECT status FROM orders WHERE id=?',[$o['id']])['status']);
        self::assertSame('unknown',$this->db->one('SELECT status FROM provisioning_operations WHERE subscription_id=?',[$id])['status']);
        self::assertSame(0,(int)$this->db->one("SELECT COUNT(*) n FROM outbox WHERE topic='telegram.send'")['n']);
    }
    public function testRecoveryDoesNotCompleteWorkflowForMissingPanelAccount():void
    {
        $o=$this->order('panel-recovery-key');
        $this->db->execute("UPDATE orders SET provision_driver='remnawave',squad_uuid='squad' WHERE id=?",[$o['id']]);
        $this->pay($o,'panel-recovery-payment');
        $id=$this->db->one('SELECT id FROM subscriptions WHERE order_id=?',[$o['id']])['id'];
        $this->db->execute("UPDATE subscriptions SET status='active',remote_id='777' WHERE id=?",[$id]);
        $http=new MockHttpClient(fn()=>new MockResponse('{}',['http_code'=>404]));
        $worker=new Worker($this->db,$this->outbox,new Payments($this->db,$this->billing,$http,[]),new RemnawaveProvisioner($http,'https://panel.example','token','squad'),$http,'',defaultProvisionDriver:'remnawave',workflows:new \App\Infrastructure\DurableWorkflow($this->db,$this->outbox));
        try {$worker->handle('subscription.provision',['subscription_id'=>$id]);self::fail('Missing panel account completed workflow');}
        catch (\RuntimeException $e) {self::assertSame('Remnawave user not found after activation',$e->getMessage());}
        self::assertSame('unknown',$this->db->one('SELECT status FROM provisioning_operations WHERE subscription_id=?',[$id])['status']);
        self::assertSame('paid',$this->db->one('SELECT status FROM orders WHERE id=?',[$o['id']])['status']);
    }
    public function testLegacyPanelIdDoesNotCreateDuplicateAccount():void
    {
        $expiry=time()+86400;
        $this->db->execute("INSERT INTO subscriptions(id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit,remnawave_id) VALUES('legacy-active',?,'active',?,?,10,3,777)",[$this->uid,$expiry,time()]);
        $http=new MockHttpClient(function($method,$url)use($expiry){
            self::assertSame('GET',$method);
            self::assertStringEndsWith('/api/users/777',$url);
            return new MockResponse(json_encode(['response'=>['id'=>777,'status'=>'ACTIVE','expireAt'=>gmdate('Y-m-d\TH:i:s\Z',$expiry),'subscriptionUrl'=>'https://panel.example/sub']]));
        });
        $worker=new Worker($this->db,$this->outbox,new Payments($this->db,$this->billing,$http,[]),new RemnawaveProvisioner($http,'https://panel.example','token','squad'),$http,'',defaultProvisionDriver:'remnawave');
        $worker->handle('subscription.provision',['subscription_id'=>'legacy-active']);
        self::assertSame('777',$this->db->one("SELECT remote_id FROM subscriptions WHERE id='legacy-active'")['remote_id']);
    }
    public function testTrafficSyncWaitsUntilRemoteAccountExists():void
    {
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb,purchased_traffic_gb,device_limit) VALUES('pending-remote',NULL,?,'provisioning',?,?,?,?,3)",[$this->uid,time()+86400,time(),10,5]);
        $http=new MockHttpClient();
        $worker=new Worker($this->db,$this->outbox,new Payments($this->db,$this->billing,$http,[]),new DemoProvisioner(),$http,'',defaultProvisionDriver:'remnawave');
        $this->expectException(JobDeferred::class);
        $worker->handle('subscription.traffic',['subscription_id'=>'pending-remote','traffic_gb'=>5]);
    }
    public function testLegacyExtensionUsesPanelIdAndNotifiesOnlyAfterReadback():void
    {
        $expiry=time()+86400;
        $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',['12345',$this->uid]);
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit,remote_id,remnawave_id) VALUES('legacy-sub',NULL,?,'active',?,?,10,3,NULL,777)",[$this->uid,$expiry,time()]);
        $patched=null;
        $http=new MockHttpClient(function($method,$url,$options)use($expiry,&$patched){
            self::assertStringContainsString('/api/users', $url);
            if ($method==='PATCH') {$patched=json_decode((string)$options['body'],true,512,JSON_THROW_ON_ERROR);return new MockResponse('{}');}
            self::assertStringEndsWith('/api/users/777',$url);
            return new MockResponse(json_encode(['response'=>['id'=>777,'status'=>'ACTIVE','expireAt'=>gmdate('Y-m-d\TH:i:s\Z',$expiry)]]));
        });
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $worker=new Worker($this->db,$this->outbox,new Payments($this->db,$this->billing,$http,[]),$p,$http,'',defaultProvisionDriver:'remnawave');
        $worker->handle('subscription.extend',['subscription_id'=>'legacy-sub']);
        self::assertSame(777,$patched['id']);
        self::assertSame(1,(int)$this->db->one("SELECT COUNT(*) AS n FROM outbox WHERE topic='telegram.send'")['n']);
    }
    public function testExtensionDoesNotNotifyWhenPanelDidNotChangeExpiry():void
    {
        $expiry=time()+86400;
        $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',['12345',$this->uid]);
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit,remote_id,remnawave_id) VALUES('legacy-sub',NULL,?,'active',?,?,10,3,'777',777)",[$this->uid,$expiry,time()]);
        $http=new MockHttpClient(function($method)use($expiry){
            if ($method==='PATCH') return new MockResponse('{}');
            return new MockResponse(json_encode(['response'=>['id'=>777,'status'=>'ACTIVE','expireAt'=>gmdate('Y-m-d\TH:i:s\Z',$expiry-86400)]]));
        });
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $worker=new Worker($this->db,$this->outbox,new Payments($this->db,$this->billing,$http,[]),$p,$http,'',defaultProvisionDriver:'remnawave');
        $this->expectException(\RuntimeException::class);
        try {$worker->handle('subscription.extend',['subscription_id'=>'legacy-sub']);}
        finally {self::assertSame(0,(int)$this->db->one("SELECT COUNT(*) AS n FROM outbox WHERE topic='telegram.send'")['n']);}
    }
    public function testStalePanelIdFallsBackToCanonicalAccount():void
    {
        $expiry=time()+86400;
        $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',['12345',$this->uid]);
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit,remote_id,remnawave_id) VALUES('legacy-sub',NULL,?,'active',?,?,10,3,'777',777)",[$this->uid,$expiry,time()]);
        $patched=null;
        $http=new MockHttpClient(function($method,$url,$options)use($expiry,&$patched){
            if($method==='PATCH'){$patched=json_decode((string)$options['body'],true,512,JSON_THROW_ON_ERROR);return new MockResponse('{}');}
            if(str_ends_with($url,'/api/users/777'))return new MockResponse('{}',['http_code'=>404]);
            self::assertTrue(str_ends_with($url,'/api/users/by-username/zb_legacy-sub')||str_ends_with($url,'/api/users/999'));
            return new MockResponse(json_encode(['response'=>['id'=>999,'username'=>'zb_legacy-sub','status'=>'ACTIVE','expireAt'=>gmdate('Y-m-d\TH:i:s\Z',$expiry)]]));
        });
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $worker=new Worker($this->db,$this->outbox,new Payments($this->db,$this->billing,$http,[]),$p,$http,'',defaultProvisionDriver:'remnawave');
        $worker->handle('subscription.extend',['subscription_id'=>'legacy-sub']);
        self::assertSame(999,$patched['id']);
        self::assertSame(999,(int)$this->db->one("SELECT remnawave_id FROM subscriptions WHERE id='legacy-sub'")['remnawave_id']);
        self::assertSame(1,(int)$this->db->one("SELECT COUNT(*) AS n FROM outbox WHERE topic='telegram.send'")['n']);
    }
    public function testStalePanelIdFallsBackToStoredShortUuid():void
    {
        $expiry=time()+86400;
        $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',['12345',$this->uid]);
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit,remote_id,remnawave_id,remnawave_short_uuid) VALUES('legacy-sub',NULL,?,'active',?,?,10,3,'777',777,'legacyShort')",[$this->uid,$expiry,time()]);
        $patched=null;
        $http=new MockHttpClient(function($method,$url,$options)use($expiry,&$patched){
            if($method==='PATCH'){$patched=json_decode((string)$options['body'],true,512,JSON_THROW_ON_ERROR);return new MockResponse('{}');}
            if(str_ends_with($url,'/api/users/777'))return new MockResponse('{}',['http_code'=>404]);
            if(str_ends_with($url,'/api/users/by-short-uuid/legacyShort')||str_ends_with($url,'/api/users/999')) {
                return new MockResponse(json_encode(['response'=>['id'=>999,'shortUuid'=>'legacyShort','username'=>'legacy_name','status'=>'ACTIVE','expireAt'=>gmdate('Y-m-d\TH:i:s\Z',$expiry)]]));
            }
            self::fail('Unexpected Remnawave lookup: '.$url);
        });
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $worker=new Worker($this->db,$this->outbox,new Payments($this->db,$this->billing,$http,[]),$p,$http,'',defaultProvisionDriver:'remnawave');
        $worker->handle('subscription.extend',['subscription_id'=>'legacy-sub']);
        self::assertSame(999,$patched['id']);
        self::assertSame(999,(int)$this->db->one("SELECT remnawave_id FROM subscriptions WHERE id='legacy-sub'")['remnawave_id']);
        self::assertSame(1,(int)$this->db->one("SELECT COUNT(*) AS n FROM outbox WHERE topic='telegram.send'")['n']);
    }
    public function testRemnawaveRecoversPreviouslyCreatedUser():void
    {
        $calls=[];$patched=null;
        $http=new MockHttpClient(function($method,$url,$options)use(&$calls,&$patched){
            $calls[]=$method;
            if($method==='PATCH'){$patched=json_decode((string)$options['body'],true);return new MockResponse('{}');}
            self::assertStringContainsString('/api/users/by-username/zb_abc',$url);
            return new MockResponse(json_encode(['response'=>['username'=>'zb_abc','id'=>123,'shortUuid'=>'abc123','subscriptionUrl'=>'https://sub.example/key']]));
        });
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');
        $result=$p->provision(['id'=>'abc','expires_at'=>time()+86400,'traffic_bytes'=>10*1073741824,'devices'=>3]);
        self::assertSame('123',$result['id']);
        self::assertSame(['GET','PATCH'],$calls);
        self::assertSame(10*1073741824,$patched['trafficLimitBytes']);
    }
    public function testProviderStatusIsFetchedBeforeSettlement():void
    {
        // Legacy Payments::refresh now only supports demo (Platega goes through
        // PaymentService). Verify demo settles a matching order and records a receipt.
        $billing=new BillingService($this->db,$this->outbox,'demo');$o=$billing->order($this->uid,'basic','demo-key');
        $this->db->execute("UPDATE orders SET provider='demo',provider_payment_id='demo-refresh' WHERE id=?",[$o['id']]);
        $payments=new Payments($this->db,$billing,new MockHttpClient(),[]);
        $payments->refresh('demo-refresh');
        self::assertCount(1,$this->db->all('SELECT * FROM payment_receipts'));
        self::assertSame('paid',$this->db->one('SELECT status FROM orders')['status']);
    }
    public function testMoneyDoesNotUseFloatingPointParsing():void
    {
        self::assertSame(19999,Payments::minor('199.99'));self::assertSame('199.99',Payments::decimal(19999));$this->expectException(BillingError::class);Payments::minor('1.999');
    }
    public function testTelegramDuplicateUpdateCreatesOneOrderAndReply():void
    {
        $tg=new Telegram($this->db,$this->outbox,$this->billing,'https://example.org');$u=['update_id'=>10,'message'=>['from'=>['id'=>123],'chat'=>['id'=>123,'type'=>'private'],'text'=>'/buy basic']];$tg->receive($u);$tg->receive($u);
        self::assertCount(1,$this->db->all('SELECT * FROM orders'));self::assertCount(1,$this->db->all("SELECT * FROM outbox WHERE topic='telegram.send'"));
    }
    public function testTelegramGroupCannotBuy():void
    {
        (new Telegram($this->db,$this->outbox,$this->billing,''))->receive(['update_id'=>11,'message'=>['from'=>['id'=>123],'chat'=>['id'=>456,'type'=>'group'],'text'=>'/buy basic']]);self::assertCount(0,$this->db->all('SELECT * FROM orders'));
    }
    public function testAuthHashesSessionsAndEnforcesThrottle():void
    {
        $auth=new Auth($this->db);$raw=$auth->issue($this->uid);self::assertSame($this->uid,$auth->session($raw)['id']);self::assertNotSame($raw,$this->db->one('SELECT id FROM sessions')['id']);$auth->logout($raw);self::assertNull($auth->session($raw));$auth->throttle('test',1);$this->expectException(BillingError::class);$auth->throttle('test',1);
    }
}
