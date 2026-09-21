<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,Wallet,TopupService};
use App\Identity\Auth;
use App\Integration\Payment\{ProviderRegistry,PlategaProvider};
use App\Integration\PaymentService;
use App\Payments\PaymentEventStore;
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
            if ($method==='POST') return new MockResponse(json_encode(['transactionId'=>'pay-1','status'=>'PENDING','url'=>'https://pay.platega.io/p/abc','amount'=>199.00]));
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
            if ($method==='POST') return new MockResponse(json_encode(['transactionId'=>'top-1','status'=>'PENDING','url'=>'https://pay.platega.io/p/top','amount'=>500.00]));
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
    public function testVerifiedWebhookIsDurableBeforeAcknowledgement():void
    {
        $config=['PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'merchant','PLATEGA_SECRET'=>'secret'];
        $r=$this->registry($config);$svc=new PaymentService($this->db,$this->billing,new MockHttpClient(),$config,$r,new PaymentEventStore($this->db));
        $body=json_encode(['transactionId'=>'payment-1','status'=>'CONFIRMED','orderId'=>'order-1','paymentDetails'=>['amount'=>199.00],'comission'=>0]);
        $req=Request::create('/webhooks/platega','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_MERCHANTID'=>'merchant','HTTP_X_SECRET'=>'secret'],$body);
        self::assertTrue($svc->handleWebhook('platega',$req));
        self::assertSame(1,(int)$this->db->one('SELECT COUNT(*) c FROM payment_events')['c']);
    }
}
