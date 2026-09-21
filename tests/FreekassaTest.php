<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\{Container,Web\Application};
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,BillingError};
use App\Integration\Payments;
use App\Settings\Settings;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
use Symfony\Component\HttpFoundation\Request;
final class FreekassaTest extends TestCase
{
    /** FreeKassa was removed; Platega coverage lives in ProvidersTest. */
    protected function setUp(): void
    {
        $this->markTestSkipped('Legacy FreeKassa adapter has been removed.');
    }

    private function freekassaConfig(array $extra=[]): array
    {
        return array_merge(Settings::DEFAULTS,[
            'PURCHASES_ENABLED'=>'1','PAYMENT_DRIVER'=>'freekassa','PROVISION_DRIVER'=>'demo',
            'FREEKASSA_SHOP_ID'=>'777','FREEKASSA_API_KEY'=>'test-api-key','FREEKASSA_SECRET2'=>'secret2','FREEKASSA_PAYMENT_ID'=>'44',
            'APP_URL'=>'https://cabinet.example.com','APP_ENV'=>'test',
        ],$extra);
    }
    private function setupDb(string $provider='freekassa', array $configExtra=[]): array
    {
        $db=new Database('sqlite::memory:');$db->migrate(__DIR__.'/../migrations');
        $db->execute("INSERT INTO users(id,email,created_at) VALUES('u','customer@example.test',0)");
        $db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices) VALUES('p','Plan',19900,'RUB',30,0,3)");
        $config=$this->freekassaConfig($configExtra);
        $billing=new BillingService($db,new Outbox($db),$provider,$config);
        return [$db,$billing,$config];
    }
    public function testSignatureFormat(): void
    {
        // Notification signature: md5(shop:amount:secret2:orderId)
        $data=['MERCHANT_ID'=>'777','AMOUNT'=>'199.00','MERCHANT_ORDER_ID'=>'abc123','SIGN'=>md5('777:199.00:secret2:abc123')];
        self::assertTrue(Payments::verifyFreekassaNotification($data,'secret2'));
        $data['SIGN']='wrong';
        self::assertFalse(Payments::verifyFreekassaNotification($data,'secret2'));
    }
    public function testNormalizeAmountWithoutFloat(): void
    {
        self::assertSame('199.00',Payments::normalizeAmount('199'));
        self::assertSame('199.50',Payments::normalizeAmount('199.5'));
        self::assertSame('199.00',Payments::normalizeAmount('199.00'));
        self::assertSame(19900,Payments::minor(Payments::normalizeAmount('199')));
        foreach(['','abc','1.999','01','-5','1,00'] as $bad){ try{Payments::normalizeAmount($bad);self::fail($bad);}catch(BillingError){} }
    }
    public function testCreateSendsCorrectPayloadAndStoresLocation(): void
    {
        [$db,$billing,$config]=$this->setupDb();
        $order=$billing->order('u','p','fk-key-1',null,'85.8.8.8');
        $http=new MockHttpClient(function($method,$url,$options)use($order){
            self::assertSame('POST',$method);
            self::assertStringContainsString('https://api.fk.life/v1/orders/create',$url);
            $body=json_decode($options['body'],true);
            self::assertSame(777,$body['shopId']);
            self::assertSame($order['id'],$body['paymentId']);
            self::assertSame(44,$body['i']);
            self::assertSame('customer@example.test',$body['email']);
            self::assertSame('85.8.8.8',$body['ip']);
            self::assertSame('199.00',$body['amount']);
            self::assertSame('RUB',$body['currency']);
            self::assertArrayHasKey('nonce',$body);
            self::assertArrayHasKey('signature',$body);
            // Verify HMAC signature
            $check=$body; unset($check['signature']); ksort($check);
            $expected=hash_hmac('sha256',implode('|',array_map('strval',array_values($check))),'test-api-key');
            self::assertSame($expected,$body['signature']);
            return new MockResponse(json_encode(['type'=>'success','orderId'=>123456,'orderHash'=>'abc','location'=>'https://pay.freekassa.net/form/123/abc']));
        });
        (new Payments($db,$billing,$http,$config))->create($order['id']);
        $row=$db->one('SELECT provider_payment_id,checkout_url FROM orders WHERE id=?',[$order['id']]);
        self::assertSame('123456',$row['provider_payment_id']);
        self::assertSame('https://pay.freekassa.net/form/123/abc',$row['checkout_url']);
    }
    public function testCreateBlocksLocalhostIp(): void
    {
        [$db,$billing,$config]=$this->setupDb();
        $order=$billing->order('u','p','fk-key-2',null,'127.0.0.1');
        $http=new MockHttpClient(function($method,$url,$options){
            $body=json_decode($options['body'],true);
            self::assertNotSame('127.0.0.1',$body['ip']);
            return new MockResponse(json_encode(['type'=>'success','orderId'=>1,'location'=>'https://pay.freekassa.net/form/1/x']));
        });
        (new Payments($db,$billing,$http,$config))->create($order['id']);
        self::assertSame('1',$db->one('SELECT provider_payment_id FROM orders')['provider_payment_id']);
    }
    public function testRefreshSettlesPaidOrder(): void
    {
        [$db,$billing,$config]=$this->setupDb();
        $order=$billing->order('u','p','fk-key-3',null,'85.8.8.8');
        $db->execute('UPDATE orders SET provider_payment_id=?,checkout_url=?,freekassa_intid=? WHERE id=?',['999','https://pay.freekassa.net/form/999/x','999',$order['id']]);
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode(['type'=>'success','orders'=>[['merchant_order_id'=>$order['id'],'fk_order_id'=>999,'amount'=>199.00,'currency'=>'RUB','status'=>1]]])));
        (new Payments($db,$billing,$http,$config))->refresh('999');
        self::assertSame('paid',$db->one('SELECT status FROM orders')['status']);
        self::assertCount(1,$db->all('SELECT * FROM payment_receipts'));
    }
    public function testRefreshCancelsFailedOrder(): void
    {
        [$db,$billing,$config]=$this->setupDb();
        $order=$billing->order('u','p','fk-key-4',null,'85.8.8.8');
        $db->execute('UPDATE orders SET provider_payment_id=?,checkout_url=? WHERE id=?',['888','https://x',$order['id']]);
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode(['type'=>'success','orders'=>[['merchant_order_id'=>$order['id'],'fk_order_id'=>888,'amount'=>199.00,'currency'=>'RUB','status'=>9]]])));
        (new Payments($db,$billing,$http,$config))->refresh('888');
        self::assertSame('canceled',$db->one('SELECT status FROM orders')['status']);
    }
    public function testWebhookVerifiesAndEnqueues(): void
    {
        $config=['APP_ENV'=>'test','PURCHASES_ENABLED'=>'1','APP_URL'=>'https://cabinet.example.com','DATABASE_DSN'=>'sqlite::memory:','DATABASE_USER'=>'','DATABASE_PASSWORD'=>'','PAYMENT_DRIVER'=>'freekassa','PROVISION_DRIVER'=>'demo','FREEKASSA_SHOP_ID'=>'777','FREEKASSA_API_KEY'=>'k','FREEKASSA_SECRET2'=>'secret2','FREEKASSA_PAYMENT_ID'=>'44','YOOKASSA_SHOP_ID'=>'','YOOKASSA_SECRET'=>'','REMNAWAVE_URL'=>'','REMNAWAVE_TOKEN'=>'','REMNAWAVE_SQUAD_UUID'=>'','TELEGRAM_BOT_TOKEN'=>'123456:abcdefghijklmnopqrstuvwxyz','TELEGRAM_BOT_USERNAME'=>'example_bot','TELEGRAM_WEBHOOK_SECRET'=>str_repeat('s',32)];
        $c=new Container($config);$c->db->migrate(__DIR__.'/../migrations');
        $c->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
        $uid=$c->auth->register('fk@example.org','correct-horse-battery');
        $order=$c->billing->order($uid,'basic','fk-webhook-key',null,'85.8.8.8');
        $web=new Application($c);
        $sign=md5('777:199.00:secret2:'.$order['id']);
        $params=['MERCHANT_ID'=>'777','AMOUNT'=>'199.00','intid'=>'555','MERCHANT_ORDER_ID'=>$order['id'],'SIGN'=>$sign];
        $r=$web->handle(Request::create('/webhooks/freekassa','POST',$params,[],[],['REMOTE_ADDR'=>'168.119.157.136']));
        self::assertSame(200,$r->getStatusCode());
        self::assertSame('YES',$r->getContent());
        self::assertCount(1,$c->db->all("SELECT * FROM outbox WHERE topic='payment.verify'"));
        // Wrong sign
        $bad=$params; $bad['SIGN']='wrong';
        $r2=$web->handle(Request::create('/webhooks/freekassa','POST',$bad,[],[],['REMOTE_ADDR'=>'168.119.157.136']));
        self::assertSame(403,$r2->getStatusCode());
        // Wrong IP
        $r3=$web->handle(Request::create('/webhooks/freekassa','POST',$params,[],[],['REMOTE_ADDR'=>'1.2.3.4']));
        self::assertSame(403,$r3->getStatusCode());
    }
    public function testEmailRequiredForFreekassa(): void
    {
        $db=new Database('sqlite::memory:');$db->migrate(__DIR__.'/../migrations');
        $db->execute("INSERT INTO users(id,created_at) VALUES('nou','0')");
        $db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices) VALUES('p','Plan',19900,'RUB',30,0,3)");
        $config=$this->freekassaConfig();
        $billing=new BillingService($db,new Outbox($db),'freekassa',$config);
        $this->expectException(BillingError::class);
        $billing->order('nou','p','fk-no-email');
    }
}
