<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox,JobDeferred};
use App\Infrastructure\WebhookGuard;
use App\Billing\{BillingService,Wallet,TopupService};
use App\Identity\Auth;
use App\Integration\Payment\{ProviderRegistry,PlategaProvider};
use App\Integration\PaymentService;
use App\Integration\Provisioner;
use App\Payments\PaymentEventStore;
use App\Subscriptions\SubscriptionSyncService;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
use Symfony\Component\HttpFoundation\Request;
final class ProvidersTest extends TestCase
{
    private Database $db; private Outbox $outbox; private BillingService $billing; private Wallet $wallet; private TopupService $topups; private string $uid;
    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $this->wallet=new Wallet($this->db);
        $this->billing=new BillingService($this->db,$this->outbox,'demo');
        $this->topups=new TopupService($this->db,$this->outbox,$this->wallet,'demo');
        $this->billing->setTopups($this->topups);
        $this->uid=(new Auth($this->db))->register('user@example.org','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
    }
    private function registry(array $config, ?MockHttpClient $http=null): ProviderRegistry
    {
        $http=$http??new MockHttpClient();
        $r=new ProviderRegistry($http,$config);
        $r->register(new PlategaProvider($http,$config));
        return $r;
    }
    public function testRegistryOnlyExposesPlategaWhenConfigured():void
    {
        $config=['PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'merchant','PLATEGA_SECRET'=>'secret'];
        $r=$this->registry($config);
        $enabled=$r->enabled();
        self::assertSame(['platega'],array_keys($enabled));
        $r2=$this->registry([]);
        self::assertSame([],array_keys($r2->enabled()));
    }
    public function testPlategaWebhookRequiresBothCredentials():void
    {
        $config=['PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'merchant','PLATEGA_SECRET'=>'secret'];
        $r=$this->registry($config);
        $svc=new PaymentService($this->db,$this->billing,new MockHttpClient(),$config,$r);
        $body=json_encode(['transactionId'=>'payment-1','status'=>'CONFIRMED','orderId'=>'order-1','paymentDetails'=>['amount'=>199.00],'comission'=>0]);
        $req=Request::create('/webhooks/platega','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_MERCHANTID'=>'merchant','HTTP_X_SECRET'=>'secret'],$body);
        self::assertNotNull($r->get('platega')->handleWebhook($req));
        self::assertTrue($svc->handleWebhook('platega',$req));
        $bad=Request::create('/webhooks/platega','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_MERCHANTID'=>'merchant','HTTP_X_SECRET'=>'wrong'],$body);
        self::assertFalse($svc->handleWebhook('platega',$bad));
    }
    public function testPlategaOrderCheckoutAndVerify():void
    {
        $config=['PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'shop','PLATEGA_SECRET'=>'secret','APP_ENV'=>'test','APP_URL'=>'http://localhost'];
        $http=new MockHttpClient(function($method,$url,$options)use(&$calls,&$order){
            $calls++;
            if ($method==='POST') {
                $body=json_decode((string)($options['body'] ?? ''),true);
                self::assertSame('199.00',$body['paymentDetails']['amount'] ?? null);
                return new MockResponse(json_encode(['transactionId'=>'pay-1','status'=>'PENDING','url'=>'https://pay.platega.io/p/abc','amount'=>199.00]));
            }
            // GET /transaction/{id} returns the authoritative status.
            return new MockResponse(json_encode(['id'=>'pay-1','transactionId'=>'pay-1','status'=>'CONFIRMED','paymentDetails'=>['amount'=>216.91,'currency'=>'RUB'],'comission'=>17.91]));
        });
        $r=$this->registry($config,$http);
        $svc=new PaymentService($this->db,$this->billing,$http,$config,$r);
        $billing=new BillingService($this->db,$this->outbox,'platega',['PURCHASES_ENABLED'=>'1','PROVISION_DRIVER'=>'demo','REMNAWAVE_SQUAD_UUID'=>'','APP_URL'=>'http://localhost','PAYMENT_DRIVER'=>'platega','APP_ENV'=>'test','PLATEGA_MERCHANT_ID'=>'shop','PLATEGA_SECRET'=>'secret']);
        $order=$billing->order($this->uid,'basic','prov-key-2',null,null);
        $this->db->execute('UPDATE orders SET provider_account=? WHERE id=?',['shop',$order['id']]);
        $svc->createOrder($order['id']);
        $row=$this->db->one('SELECT * FROM orders WHERE id=?',[$order['id']]);
        self::assertSame('pay-1',$row['provider_payment_id']);
        $svc->verify('pay-1');
        self::assertSame('paid',$this->db->one('SELECT status FROM orders')['status']);
    }
    public function testPlategaTopupCheckoutAndVerify():void
    {
        $config=['PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'shop','PLATEGA_SECRET'=>'secret','APP_ENV'=>'test','APP_URL'=>'http://localhost'];
        $http=new MockHttpClient(function($method,$url,$options){
            if ($method==='POST') {
                $body=json_decode((string)($options['body'] ?? ''),true);
                self::assertSame('500.00',$body['paymentDetails']['amount'] ?? null);
                return new MockResponse(json_encode(['transactionId'=>'top-1','status'=>'PENDING','url'=>'https://pay.platega.io/p/top','amount'=>500.00]));
            }
            return new MockResponse(json_encode(['id'=>'top-1','transactionId'=>'top-1','status'=>'CONFIRMED','paymentDetails'=>['amount'=>545.00,'currency'=>'RUB'],'comission'=>45.00]));
        });
        $r=$this->registry($config,$http);
        $svc=new PaymentService($this->db,$this->billing,$http,$config,$r);
        $t=$this->topups->create($this->uid,50000,'prov-key-top','platega');
        $svc->createTopup($t['id']);
        $row=$this->db->one('SELECT * FROM topups WHERE id=?',[$t['id']]);
        self::assertSame('top-1',$row['provider_payment_id']);
        $svc->verify('top-1');
        self::assertSame('paid',$this->db->one('SELECT status FROM topups')['status']);
        self::assertSame(50000,$this->wallet->balance($this->uid)['balance_kopeks']);
    }
    public function testVerifiedWebhookSettlesOnlyAfterProviderApiConfirmsPayment():void
    {
        $config=['PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'merchant','PLATEGA_SECRET'=>'secret','APP_ENV'=>'test'];
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode([
            'id'=>'payment-1','transactionId'=>'payment-1','status'=>'CONFIRMED','orderId'=>'webhook-order',
            'paymentDetails'=>['amount'=>199.00,'currency'=>'RUB'],'comission'=>0,
        ])));
        $billing=new BillingService($this->db,$this->outbox,'platega');
        $order=$billing->order($this->uid,'basic','webhook-order-key');
        $this->db->execute("UPDATE orders SET provider_payment_id='payment-1' WHERE id=?",[$order['id']]);
        $r=$this->registry($config,$http);$svc=new PaymentService($this->db,$billing,$http,$config,$r,new PaymentEventStore($this->db));
        $body=json_encode(['transactionId'=>'payment-1','status'=>'CONFIRMED','orderId'=>$order['id']]);
        $req=Request::create('/webhooks/platega','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_MERCHANTID'=>'merchant','HTTP_X_SECRET'=>'secret'],$body);
        self::assertTrue($svc->handleWebhook('platega',$req));
        self::assertSame(1,(int)$this->db->one('SELECT COUNT(*) c FROM payment_events')['c']);
        self::assertSame('succeeded',$this->db->one("SELECT status FROM payments WHERE provider_payment_id='payment-1'")['status']);
        self::assertSame(1,(int)$this->db->one('SELECT COUNT(*) c FROM subscriptions WHERE order_id=?',[$order['id']])['c']);
    }
    public function testDuplicateConfirmedWebhookSettlesOnce():void
    {
        $config=['PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'merchant','PLATEGA_SECRET'=>'secret','APP_ENV'=>'test'];
        $calls=0;
        $http=new MockHttpClient(function()use(&$calls){$calls++;return new MockResponse(json_encode([
            'id'=>'hook-payment','transactionId'=>'hook-payment','status'=>'CONFIRMED','orderId'=>'hook-order',
            'paymentDetails'=>['amount'=>199.00,'currency'=>'RUB'],'comission'=>0,
        ]));});
        $registry=$this->registry($config,$http);
        $billing=new BillingService($this->db,$this->outbox,'platega');
        $order=$billing->order($this->uid,'basic','hook-order-key');
        $this->db->execute("UPDATE orders SET provider_payment_id='hook-payment' WHERE id=?",[$order['id']]);
        $service=new PaymentService($this->db,$billing,$http,$config,$registry,new PaymentEventStore($this->db),new WebhookGuard($this->db));
        $body=json_encode(['transactionId'=>'hook-payment','status'=>'CONFIRMED','orderId'=>$order['id']]);
        $request=fn()=>Request::create('/webhooks/platega','POST',[],[],[],[
            'CONTENT_TYPE'=>'application/json','HTTP_X_MERCHANTID'=>'merchant','HTTP_X_SECRET'=>'secret',
        ],$body);

        self::assertTrue($service->handleWebhook('platega',$request()));
        $expiry=$this->db->one('SELECT expires_at FROM subscriptions WHERE order_id=?',[$order['id']])['expires_at'];
        for($n=0;$n<4;$n++)self::assertTrue($service->handleWebhook('platega',$request()));
        self::assertSame(1,(int)$this->db->one('SELECT COUNT(*) n FROM payments WHERE provider_payment_id=\'hook-payment\'')['n']);
        self::assertSame(1,(int)$this->db->one('SELECT COUNT(*) n FROM subscriptions WHERE order_id=?',[$order['id']])['n']);
        self::assertSame($expiry,$this->db->one('SELECT expires_at FROM subscriptions WHERE order_id=?',[$order['id']])['expires_at']);
        self::assertSame(1,$calls,'Duplicate webhook must not call Platega or apply settlement again');
    }
    public function testConfirmedPlategaWebhookSurvivesPanelOutageAndRecovers():void
    {
        $config=['PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'merchant','PLATEGA_SECRET'=>'secret','APP_ENV'=>'test'];
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode([
            'id'=>'panel-outage-payment','transactionId'=>'panel-outage-payment','status'=>'CONFIRMED',
            'paymentDetails'=>['amount'=>199.00,'currency'=>'RUB'],'comission'=>0,
        ])));
        $available=false;
        $panel=new class($available) implements Provisioner {
            private bool $available;
            public function __construct(bool &$available){$this->available=&$available;}
            public function provision(array $subscription):array {
                if(!$this->available)throw new \RuntimeException('panel unavailable');
                return ['id'=>'451','url'=>'https://panel.example/sub/451'];
            }
            public function extend(array $subscription):void {if(!$this->available)throw new \RuntimeException('panel unavailable');}
            public function setTraffic(array $subscription,int $trafficGb):void {}
            public function setDevices(array $subscription,int $devices):void {}
        };
        $billing=new BillingService($this->db,$this->outbox,'platega');
        $sync=new SubscriptionSyncService($this->db,$panel,'remnawave');
        $billing->setSubscriptionSync($sync);
        $order=$billing->order($this->uid,'basic','panel-outage-order');
        $this->db->execute("UPDATE orders SET provider_payment_id='panel-outage-payment',provision_driver='remnawave' WHERE id=?",[$order['id']]);
        $service=new PaymentService($this->db,$billing,$http,$config,$this->registry($config,$http),new PaymentEventStore($this->db));
        $body=json_encode(['transactionId'=>'panel-outage-payment','status'=>'CONFIRMED','orderId'=>$order['id']]);
        $request=Request::create('/webhooks/platega','POST',[],[],[],[
            'CONTENT_TYPE'=>'application/json','HTTP_X_MERCHANTID'=>'merchant','HTTP_X_SECRET'=>'secret',
        ],$body);
        self::assertTrue($service->handleWebhook('platega',$request));
        $sub=$this->db->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
        self::assertSame('succeeded',$this->db->one("SELECT status FROM payments WHERE provider_payment_id='panel-outage-payment'")['status']);
        self::assertSame('active',$sub['status']);
        self::assertSame('error',$sub['sync_status']);
        self::assertSame('paid',$this->db->one('SELECT status FROM orders WHERE id=?',[$order['id']])['status']);
        $available=true;
        self::assertSame('synced',$sync->syncOne($sub['id']));
        self::assertSame('fulfilled',$this->db->one('SELECT status FROM orders WHERE id=?',[$order['id']])['status']);
    }
    public function testPaidWebhookBeforeCheckoutBindingRemainsRetryable():void
    {
        $config=['PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'shop','PLATEGA_SECRET'=>'secret','APP_ENV'=>'test'];
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode([
            'id'=>'early-payment','status'=>'CONFIRMED','orderId'=>'future-order',
            'paymentDetails'=>['amount'=>199.00,'currency'=>'RUB'],'comission'=>0,
        ])));
        $events=new PaymentEventStore($this->db);
        $svc=new PaymentService($this->db,$this->billing,$http,$config,$this->registry($config,$http),$events);
        $id=$events->receive('platega','early-event','early-payment',['status'=>'paid'],true);
        try {$svc->processEvent($id);self::fail('Unbound payment was acknowledged');}
        catch (JobDeferred) {}
        self::assertSame('retry',$this->db->one('SELECT status FROM payment_events WHERE id=?',[$id])['status']);
        self::assertNull($this->db->one('SELECT processed_at FROM payment_events WHERE id=?',[$id])['processed_at']);
        self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) n FROM payment_receipts')['n']);
    }
}
