<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\{Container};
use App\Infrastructure\{Database,Outbox};
use App\Billing\BillingService;
use App\Integration\Payments;
use App\Settings\Settings;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
final class CheckoutSnapshotTest extends TestCase
{
    public function testRetriesUseOriginalCheckoutAndReceiptSettings():void
    {
        $db=new Database('sqlite::memory:');$db->migrate(__DIR__.'/../migrations');
        $db->execute("INSERT INTO users(id,email,created_at) VALUES('u','customer@example.test',0)");
        $db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices) VALUES('p','Plan',19999,'RUB',30,0,3)");
        $config=array_merge(Settings::DEFAULTS,['PURCHASES_ENABLED'=>'1','PAYMENT_DRIVER'=>'yookassa','YOOKASSA_SHOP_ID'=>'shop','YOOKASSA_SECRET'=>'secret','YOOKASSA_RECEIPT'=>'1','YOOKASSA_VAT_CODE'=>'1','APP_URL'=>'https://original.example']);
        $billing=new BillingService($db,new Outbox($db),'yookassa',$config);$order=$billing->order('u','p','checkout-key');
        $newConfig=array_merge($config,['APP_URL'=>'https://changed.example','YOOKASSA_RECEIPT'=>'0','YOOKASSA_VAT_CODE'=>'2']);
        $http=new MockHttpClient(function($method,$url,$options)use($order){$body=json_decode($options['body'],true);self::assertSame('https://original.example/orders/'.$order['id'],$body['confirmation']['return_url']);self::assertSame('199.99',$body['amount']['value']);self::assertSame(1,$body['receipt']['items'][0]['vat_code']);self::assertSame('customer@example.test',$body['receipt']['customer']['email']);return new MockResponse(json_encode(['id'=>'payment-123','test'=>true,'confirmation'=>['confirmation_url'=>'https://yookassa.ru/checkout']]));});
        (new Payments($db,$billing,$http,$newConfig))->create($order['id']);self::assertSame('payment-123',$db->one('SELECT provider_payment_id FROM orders')['provider_payment_id']);
    }
}
