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
    public function testRemnawaveRecoversPreviouslyCreatedUser():void
    {
        $calls=0;$http=new MockHttpClient(function($method,$url)use(&$calls){$calls++;self::assertSame('GET',$method);self::assertStringContainsString('/api/users/by-username/zb_abc',$url);return new MockResponse(json_encode(['response'=>['username'=>'zb_abc','id'=>123,'shortUuid'=>'abc123','subscriptionUrl'=>'https://sub.example/key']]));});
        $p=new RemnawaveProvisioner($http,'https://panel.example','token','squad');$result=$p->provision(['id'=>'abc']);self::assertSame('123',$result['id']);self::assertSame(1,$calls);
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
