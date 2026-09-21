<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,BillingError};
use App\Identity\Auth;
use App\Integration\Payments;
use Symfony\Component\HttpClient\MockHttpClient;
final class ProductionPaymentTest extends TestCase
{
    public function testDemoPaymentCannotActivateProductionSubscription():void
    {
        $db=new Database('sqlite::memory:');$db->migrate(__DIR__.'/../migrations');
        $billing=new BillingService($db,new Outbox($db),'demo');
        $uid=(new Auth($db))->register('user@example.org','correct-horse-battery');
        $db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
        $o=$billing->order($uid,'basic','prod-demo-key');
        $payments=new Payments($db,$billing,new MockHttpClient(),['APP_ENV'=>'prod']);
        $this->expectException(BillingError::class);$payments->create($o['id']);
    }
}
