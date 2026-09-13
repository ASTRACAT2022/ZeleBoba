<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,Wallet,TopupService};
use App\Identity\Auth;
use App\Integration\Payment\{ProviderRegistry,CryptoBotProvider,TelegramStarsProvider,LavaProvider,YooKassaProvider,FreeKassaProvider};
use App\Integration\PaymentService;
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
        foreach ([new YooKassaProvider($http,$config),new FreeKassaProvider($http,$config),new CryptoBotProvider($http,$config),new TelegramStarsProvider($http,$config),new LavaProvider($http,$config),new \App\Integration\Payment\MulenPayProvider($http,$config),new \App\Integration\Payment\Pal24Provider($http,$config),new \App\Integration\Payment\CloudPaymentsProvider($http,$config),new \App\Integration\Payment\KassaAiProvider($http,$config),new \App\Integration\Payment\RioPayProvider($http,$config),new \App\Integration\Payment\SeverPayProvider($http,$config),new \App\Integration\Payment\PayPearProvider($http,$config),new \App\Integration\Payment\RollyPayProvider($http,$config),new \App\Integration\Payment\OverpayProvider($http,$config),new \App\Integration\Payment\AuraPayProvider($http,$config),new \App\Integration\Payment\EtoplatezhiProvider($http,$config),new \App\Integration\Payment\AntilopayProvider($http,$config),new \App\Integration\Payment\JupiterProvider($http,$config),new \App\Integration\Payment\DonutProvider($http,$config),new \App\Integration\Payment\CisPayProvider($http,$config),new \App\Integration\Payment\TabPayProvider($http,$config),new \App\Integration\Payment\ParityPayProvider($http,$config),new \App\Integration\Payment\WataProvider($http,$config),new \App\Integration\Payment\HeleketProvider($http,$config),new \App\Integration\Payment\PlategaProvider($http,$config),new \App\Integration\Payment\TributeProvider($http,$config)] as $p) $r->register($p);
        return $r;
    }
    public function testRegistryOnlyExposesConfiguredProviders():void
    {
        $config=['CRYPTOBOT_ENABLED'=>'1','CRYPTOBOT_API_TOKEN'=>'tok','TELEGRAM_STARS_ENABLED'=>'0','LAVA_ENABLED'=>'0','YOOKASSA_SHOP_ID'=>'','YOOKASSA_SECRET'=>'','FREEKASSA_SHOP_ID'=>'','FREEKASSA_API_KEY'=>''];
        $r=$this->registry($config);
        $enabled=$r->enabled();
        self::assertArrayHasKey('cryptobot',$enabled);
        self::assertArrayNotHasKey('yookassa',$enabled);
        self::assertArrayNotHasKey('telegram_stars',$enabled);
    }
    public function testCryptoBotTopupCheckoutAndVerify():void
    {
        $config=['CRYPTOBOT_ENABLED'=>'1','CRYPTOBOT_API_TOKEN'=>'tok','APP_ENV'=>'test','APP_URL'=>'http://localhost'];
        $http=new MockHttpClient(function($method,$url,$options)use(&$calls,&$t){
            $calls++;
            $body=json_decode($options['body']??'[]',true);
            if (($body['currency_type']??'')==='fiat') return new MockResponse(json_encode(['ok'=>true,'result'=>['invoice_id'=>42,'bot_invoice_url'=>'https://t.me/bot/invoice']]));
            return new MockResponse(json_encode(['ok'=>true,'result'=>['items'=>[['invoice_id'=>42,'status'=>'paid','currency_type'=>'fiat','fiat'=>'RUB','amount'=>'199.00','payload'=>'topup:'.$t['id']]]]]));
        });
        $r=$this->registry($config,$http);
        $svc=new PaymentService($this->db,$this->billing,$http,$config,$r);
        $t=$this->topups->create($this->uid,19900,'prov-key-1','cryptobot');
        $svc->createTopup($t['id']);
        $row=$this->db->one('SELECT * FROM topups WHERE id=?',[$t['id']]);
        self::assertSame('42',$row['provider_payment_id']);
        self::assertSame('https://t.me/bot/invoice',$row['checkout_url']);
        $svc->verify('42');
        self::assertSame('paid',$this->db->one('SELECT status FROM topups')['status']);
        self::assertSame(19900,$this->wallet->balance($this->uid)['balance_kopeks']);
    }
    public function testCryptoBotWebhookSignature():void
    {
        $config=['CRYPTOBOT_ENABLED'=>'1','CRYPTOBOT_API_TOKEN'=>'tok','CRYPTOBOT_WEBHOOK_SECRET'=>'secret'];
        $r=$this->registry($config);
        $svc=new PaymentService($this->db,$this->billing,new MockHttpClient(),$config,$r);
        $body=json_encode(['update_type'=>'invoice_paid','payload'=>['invoice_id'=>7]]);
        $sig=hash_hmac('sha256',$body,hash('sha256','tok',true));
        $req=Request::create('/webhooks/cryptobot','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_CRYPTO_PAY_API_SIGNATURE'=>$sig],$body);
        self::assertNotNull($r->get('cryptobot')->handleWebhook($req));
        self::assertFalse($svc->handleWebhook('cryptobot',$req));
        $req2=Request::create('/webhooks/cryptobot','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_CRYPTO_PAY_API_SIGNATURE'=>'bad'],$body);
        self::assertFalse($svc->handleWebhook('cryptobot',$req2));
    }
    public function testYooKassaOrderCheckoutAndVerify():void
    {
        $config=['YOOKASSA_SHOP_ID'=>'shop','YOOKASSA_SECRET'=>'secret','APP_ENV'=>'test','APP_URL'=>'http://localhost'];
        $http=new MockHttpClient(function($method,$url,$options)use(&$calls,&$order){
            $calls++;
            if ($method==='POST') return new MockResponse(json_encode(['id'=>'pay-1','status'=>'pending','confirmation'=>['confirmation_url'=>'https://pay.example/1'],'test'=>false]));
            return new MockResponse(json_encode(['id'=>'pay-1','status'=>'succeeded','paid'=>true,'test'=>false,'metadata'=>['order_id'=>$order['id']],'amount'=>['value'=>'199.00','currency'=>'RUB']]));
        });
        $r=$this->registry($config,$http);
        $svc=new PaymentService($this->db,$this->billing,$http,$config,$r);
        $billing=new BillingService($this->db,$this->outbox,'yookassa',['PURCHASES_ENABLED'=>'1','YOOKASSA_RECEIPT'=>'0','PROVISION_DRIVER'=>'demo','REMNAWAVE_SQUAD_UUID'=>'','APP_URL'=>'http://localhost','PAYMENT_DRIVER'=>'yookassa','APP_ENV'=>'test','YOOKASSA_SHOP_ID'=>'shop','YOOKASSA_SECRET'=>'secret']);
        $order=$billing->order($this->uid,'basic','prov-key-2',null,null);
        $this->db->execute('UPDATE orders SET provider_account=? WHERE id=?',['shop',$order['id']]);
        $svc->createOrder($order['id']);
        $row=$this->db->one('SELECT * FROM orders WHERE id=?',[$order['id']]);
        self::assertSame('pay-1',$row['provider_payment_id']);
        self::assertSame('https://pay.example/1',$row['checkout_url']);
        $svc->verify('pay-1');
        self::assertSame('paid',$this->db->one('SELECT status FROM orders')['status']);
    }
    public function testLavaWebhookSignature():void
    {
        $config=['LAVA_ENABLED'=>'1','LAVA_SHOP_ID'=>'shop','LAVA_SECRET_KEY'=>'secret'];
        $r=$this->registry($config);
        $svc=new PaymentService($this->db,$this->billing,new MockHttpClient(),$config,$r);
        $data=['id'=>'inv-1','status'=>'success','sum'=>'199.00','orderId'=>'o1'];
        ksort($data);
        $sign=hash_hmac('sha256',implode('|',array_values($data)),'secret');
        $data['signature']=$sign;
        $req=Request::create('/webhooks/lava','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($data));
        self::assertNotNull($r->get('lava')->handleWebhook($req));
        self::assertFalse($svc->handleWebhook('lava',$req));
    }
    public function testStarsProviderConfiguredCheck():void
    {
        $config=['TELEGRAM_STARS_ENABLED'=>'1','TELEGRAM_BOT_TOKEN'=>'123:abc','STARS_RATE_KOPEKS'=>'1.5'];
        $r=$this->registry($config);
        self::assertTrue($r->has('telegram_stars'));
        self::assertArrayNotHasKey('telegram_stars',$r->enabled());
    }
    public function testFreeKassaWebhookVerification():void
    {
        $config=['FREEKASSA_SHOP_ID'=>'123','FREEKASSA_SECRET2'=>'secret2','FREEKASSA_API_KEY'=>'key'];
        $r=$this->registry($config);
        $svc=new PaymentService($this->db,$this->billing,new MockHttpClient(),$config,$r);
        $sign=md5('123:199.00:secret2:order-1');
        $req=Request::create('/webhooks/freekassa','POST',['MERCHANT_ID'=>'123','AMOUNT'=>'199.00','MERCHANT_ORDER_ID'=>'order-1','SIGN'=>$sign]);
        self::assertNotNull($r->get('freekassa')->handleWebhook($req));
        self::assertFalse($svc->handleWebhook('freekassa',$req));
        $req2=Request::create('/webhooks/freekassa','POST',['MERCHANT_ID'=>'123','AMOUNT'=>'199.00','MERCHANT_ORDER_ID'=>'order-1','SIGN'=>'bad']);
        self::assertFalse($svc->handleWebhook('freekassa',$req2));
    }
    public function testAllProvidersRegistered():void
    {
        $config=['CRYPTOBOT_ENABLED'=>'1','CRYPTOBOT_API_TOKEN'=>'tok','TELEGRAM_STARS_ENABLED'=>'1','TELEGRAM_BOT_TOKEN'=>'123:abc','STARS_RATE_KOPEKS'=>'1.5','LAVA_ENABLED'=>'1','LAVA_SHOP_ID'=>'s','LAVA_SECRET_KEY'=>'k','WATA_ENABLED'=>'1','WATA_ACCESS_TOKEN'=>'t','WATA_TERMINAL_PUBLIC_ID'=>'p','HELEKET_ENABLED'=>'1','HELEKET_MERCHANT_ID'=>'m','HELEKET_API_KEY'=>'k','PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'m','PLATEGA_SECRET'=>'s','TRIBUTE_ENABLED'=>'1','TRIBUTE_API_KEY'=>'k','TRIBUTE_DONATE_LINK'=>'https://t.me/donate','MULENPAY_ENABLED'=>'1','MULENPAY_API_KEY'=>'k','MULENPAY_SECRET_KEY'=>'s','MULENPAY_SHOP_ID'=>'1','PAL24_ENABLED'=>'1','PAL24_API_TOKEN'=>'t','PAL24_SHOP_ID'=>'s','CLOUDPAYMENTS_ENABLED'=>'1','CLOUDPAYMENTS_PUBLIC_ID'=>'p','CLOUDPAYMENTS_API_SECRET'=>'s','KASSA_AI_ENABLED'=>'1','KASSA_AI_SHOP_ID'=>'1','KASSA_AI_API_KEY'=>'k','RIOPAY_ENABLED'=>'1','RIOPAY_API_TOKEN'=>'t','SEVERPAY_ENABLED'=>'1','SEVERPAY_MID'=>'1','SEVERPAY_TOKEN'=>'t','PAYPEAR_ENABLED'=>'1','PAYPEAR_SHOP_ID'=>'s','PAYPEAR_SECRET_KEY'=>'k','ROLLYPAY_ENABLED'=>'1','ROLLYPAY_API_KEY'=>'k','ROLLYPAY_SIGNING_SECRET'=>'s','OVERPAY_ENABLED'=>'1','OVERPAY_PROJECT_ID'=>'p','OVERPAY_PASSWORD'=>'p','AURAPAY_ENABLED'=>'1','AURAPAY_API_KEY'=>'k','AURAPAY_SHOP_ID'=>'s','AURAPAY_SECRET_KEY'=>'k','ETOPLATEZHI_ENABLED'=>'1','ETOPLATEZHI_PROJECT_ID'=>'1','ETOPLATEZHI_SECRET_KEY'=>'k','ANTILOPAY_ENABLED'=>'1','ANTILOPAY_SECRET_ID'=>'s','ANTILOPAY_PROJECT_ID'=>'p','JUPITER_ENABLED'=>'1','JUPITER_TOKEN'=>'t','JUPITER_SECRET'=>'s','DONUT_ENABLED'=>'1','DONUT_TOKEN'=>'t','DONUT_SECRET'=>'s','CISPAY_ENABLED'=>'1','CISPAY_SHOP_ID'=>'s','CISPAY_API_KEY'=>'k','TABPAY_ENABLED'=>'1','TABPAY_API_KEY'=>'k','TABPAY_WEBHOOK_SECRET'=>'s','PARITYPAY_ENABLED'=>'1','PARITYPAY_SHOP_ID'=>'s','PARITYPAY_SECRET_KEY'=>'k','YOOKASSA_SHOP_ID'=>'s','YOOKASSA_SECRET'=>'k','FREEKASSA_SHOP_ID'=>'1','FREEKASSA_API_KEY'=>'k'];
        $r=$this->registry($config);
        $enabled=$r->enabled();
        // Stars and Tribute stay registered but are deliberately unavailable
        // until their provider-side confirmation flows are implemented.
        self::assertCount(24,$enabled);
        foreach (['yookassa','freekassa','cryptobot','lava','wata','heleket','platega','mulenpay','pal24','cloudpayments','kassa_ai','riopay','severpay','paypear','rollypay','overpay','aurapay','etoplatezhi','antilopay','jupiter','donut','cispay','tabpay','paritypay'] as $id) {
            self::assertArrayHasKey($id,$enabled,$id);
        }
        self::assertArrayNotHasKey('telegram_stars',$enabled);
        self::assertArrayNotHasKey('tribute',$enabled);
    }
}
