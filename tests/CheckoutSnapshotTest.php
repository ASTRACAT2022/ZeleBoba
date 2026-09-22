<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\BillingService;
use App\Identity\Auth;
use App\Integration\Payment\{ProviderRegistry,PlategaProvider};
use App\Integration\PaymentService;
use App\Settings\Settings;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
final class CheckoutSnapshotTest extends TestCase
{
    public function testPlategaCheckoutPinsOrderIdAndRubAmount():void
    {
        $db=new Database('sqlite::memory:');$db->migrate(__DIR__.'/../migrations');
        $db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices) VALUES('p','Plan',19999,'RUB',30,0,3)");
        $uid=(new Auth($db))->register('customer@example.test','correct-horse-battery');
        $config=array_merge(Settings::DEFAULTS,['PAYMENT_DRIVER'=>'platega','PLATEGA_ENABLED'=>'1','PLATEGA_MERCHANT_ID'=>'shop','PLATEGA_SECRET'=>'secret','PURCHASES_ENABLED'=>'1','APP_URL'=>'https://original.example','APP_ENV'=>'test']);
        $billing=new BillingService($db,new Outbox($db),'platega',$config);$order=$billing->order($uid,'p','checkout-key');
        $http=new MockHttpClient(function($method,$url,$options)use($order){
            self::assertSame('POST',$method);
            $body=json_decode($options['body'],true);
            self::assertSame((string)$order['id'],$body['orderId']);
            self::assertSame('199.99',$body['paymentDetails']['amount']);
            self::assertStringContainsString('original.example/orders/'.$order['id'],$body['return']);
            $payload=json_decode($body['payload'],true);
            self::assertSame($order['id'],$payload['order_id']);
            return new MockResponse(json_encode(['transactionId'=>'pay-1','url'=>'https://pay.platega.io/p/abc']));
        });
        $reg=new ProviderRegistry($http,$config);
        $reg->register(new PlategaProvider($http,$config));
        $res=(new PaymentService($db,$billing,$http,$config,$reg))->createOrder($order['id']);
        self::assertSame('pay-1',$res['payment_id']);
        self::assertSame('https://pay.platega.io/p/abc',$res['checkout_url']);
    }
}
