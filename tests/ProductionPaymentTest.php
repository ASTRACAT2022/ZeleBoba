<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,BillingError};
use App\Integration\Payments;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
final class ProductionPaymentTest extends TestCase
{
    public function testTestPaymentCannotActivateProductionSubscription():void
    {
        $db=new Database('sqlite::memory:');$db->migrate(__DIR__.'/../migrations');$billing=new BillingService($db,new Outbox($db),'yookassa');
        $http=new MockHttpClient(fn()=>new MockResponse(json_encode(['id'=>'test-id','paid'=>true,'status'=>'succeeded','test'=>true])));
        $payments=new Payments($db,$billing,$http,['APP_ENV'=>'prod','YOOKASSA_SHOP_ID'=>'shop','YOOKASSA_SECRET'=>'secret']);
        $this->expectException(BillingError::class);$payments->refresh('test-id');
    }
}
