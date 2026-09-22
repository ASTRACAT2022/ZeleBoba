<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox,Worker,Reconciler};
use App\Billing\{BillingService,BillingError};
use App\Settings\{Settings,Vault};
use App\Integration\{Payments,DemoProvisioner,RemnawaveProvisioner,Telegram};
use App\Identity\Auth;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
use Symfony\Component\HttpFoundation\Request;
use App\{Container,Web\Application};
final class AutoRenewTest extends TestCase
{
    private function setupBilling(string $provider='demo', array $configExtra=[]): array
    {
        $db=new Database('sqlite::memory:');$db->migrate(__DIR__.'/../migrations');
        $db->execute("INSERT INTO users(id,email,created_at) VALUES('u','customer@example.test',0)");
        $db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices) VALUES('p','Plan',19900,'RUB',30,0,3)");
        $config=array_merge(Settings::DEFAULTS,['PURCHASES_ENABLED'=>'1','PAYMENT_DRIVER'=>$provider,'PROVISION_DRIVER'=>'demo','AUTORENEW_ENABLED'=>'1','AUTORENEW_DAYS_BEFORE'=>'3','AUTORENEW_MAX_FAILS'=>'3','APP_URL'=>'https://cabinet.example'],$configExtra);
        $billing=new BillingService($db,new Outbox($db),$provider,$config);
        return [$db,$billing,$config];
    }
    public function testToggleAutoRenewAndRenewAt(): void
    {
        [$db,$billing]=$this->setupBilling();
        $order=$billing->order('u','p','autorenew-key-1');
        $billing->settle($order['id'],'demo','demo_'.$order['id'],19900,'RUB');
        $sub=$db->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
        // Need active status
        $db->execute("UPDATE subscriptions SET status='active' WHERE id=?",[$sub['id']]);
        $updated=$billing->setAutoRenew('u',$sub['id'],true);
        self::assertSame(1,(int)$updated['auto_renew']);
        self::assertNotNull($updated['renew_at']);
        self::assertSame('p',$updated['renew_plan_id']);
        $off=$billing->setAutoRenew('u',$sub['id'],false);
        self::assertSame(0,(int)$off['auto_renew']);
        self::assertNull($off['renew_at']);
    }
    public function testRenewCreatesOrderAndExtendsOnPayment(): void
    {
        [$db,$billing,$config]=$this->setupBilling('demo');
        $order=$billing->order('u','p','autorenew-key-2');
        $billing->settle($order['id'],'demo','demo_'.$order['id'],19900,'RUB');
        $sub=$db->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
        $db->execute("UPDATE subscriptions SET status='active',expires_at=? WHERE id=?",[time()+86400,$sub['id']]);
        $billing->setAutoRenew('u',$sub['id'],true);
        // Make renew_at due
        $db->execute('UPDATE subscriptions SET renew_at=? WHERE id=?',[time()-10,$sub['id']]);
        $http=new MockHttpClient(); $payments=new Payments($db,$billing,$http,$config);
        $worker=new Worker($db,new Outbox($db),$payments,new DemoProvisioner(),$http,'',true,'https://astracattg.netlify.app');
        // Reconciler enqueues renew
        $container=$this->createContainer($db);
        (new Reconciler($container))->run();
        self::assertCount(1,$db->all("SELECT * FROM outbox WHERE topic='subscription.renew'"));
        $worker->handle('subscription.renew',['subscription_id'=>$sub['id']]);
        $renewOrder=$db->one("SELECT * FROM orders WHERE idempotency_key LIKE 'renew:%'");
        self::assertNotNull($renewOrder);
        self::assertSame('pending',$renewOrder['status']);
        $sub2=$db->one('SELECT * FROM subscriptions WHERE id=?',[$sub['id']]);
        self::assertSame($renewOrder['id'],$sub2['renew_order_id']);
        // Simulate payment success for renewal
        $oldExpiry=(int)$sub2['expires_at'];
        $billing->settle($renewOrder['id'],'demo','demo_'.$renewOrder['id'],19900,'RUB');
        $sub3=$db->one('SELECT * FROM subscriptions WHERE id=?',[$sub['id']]);
        self::assertGreaterThan($oldExpiry,(int)$sub3['expires_at']);
        self::assertSame('active',$sub3['status']);
        self::assertNull($sub3['renew_order_id']);
        $worker->handle('subscription.extend',['subscription_id'=>$sub['id'],'order_id'=>$renewOrder['id']]);
        self::assertSame('fulfilled',$db->one('SELECT status FROM orders WHERE id=?',[$renewOrder['id']])['status']);
    }
    public function testRenewRespectsMaxFailsAndPurchasesPause(): void
    {
        [$db,$billing]=$this->setupBilling('demo',['AUTORENEW_MAX_FAILS'=>'1']);
        $db->execute("INSERT INTO app_settings VALUES('AUTORENEW_ENABLED','1',?)",[time()]);
        $db->execute("INSERT INTO app_settings VALUES('AUTORENEW_MAX_FAILS','1',?)",[time()]);
        $order=$billing->order('u','p','autorenew-key-3');
        $billing->settle($order['id'],'demo','demo_'.$order['id'],19900,'RUB');
        $sub=$db->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
        $db->execute("UPDATE subscriptions SET status='active',expires_at=? WHERE id=?",[time()+86400,$sub['id']]);
        $billing->setAutoRenew('u',$sub['id'],true);
        // Simulate a prior failure reaching the limit AFTER enabling (enable resets counter)
        $db->execute('UPDATE subscriptions SET renew_at=?,renew_fail_count=1 WHERE id=?',[time()-10,$sub['id']]);
        $container=$this->createContainer($db);
        (new Reconciler($container))->run();
        self::assertCount(0,$db->all("SELECT * FROM outbox WHERE topic='subscription.renew'"));
    }

    public function testWalletAutoRenewIsAtomicAndDailyPlanDoesNotLoop(): void
    {
        [$db,$billing]=$this->setupBilling();
        $db->execute("UPDATE plans SET duration_days=1,price_minor=19900 WHERE id='p'");
        $order=$billing->order('u','p','daily-autorenew-order');
        $billing->settle($order['id'],'demo','demo_'.$order['id'],19900,'RUB');
        $sub=$db->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
        $db->execute("UPDATE subscriptions SET status='active',lifecycle_status='active',expires_at=? WHERE id=?",[time()+86400,$sub['id']]);
        $enabled=$billing->setAutoRenew('u',$sub['id'],true);
        self::assertGreaterThan(time()+60,(int)$enabled['renew_at']);
        self::assertLessThan((int)$enabled['expires_at'],(int)$enabled['renew_at']);
        (new \App\Billing\Wallet($db))->credit('u',19900,'manual_adjust','test funds');
        $db->execute('UPDATE subscriptions SET renew_at=? WHERE id=?',[time()-1,$sub['id']]);

        self::assertTrue($billing->autoRenewFromBalance($sub['id']));
        $renewed=$db->one('SELECT * FROM subscriptions WHERE id=?',[$sub['id']]);
        self::assertGreaterThan(time()+86400,(int)$renewed['expires_at']);
        self::assertGreaterThan(time()+60,(int)$renewed['renew_at']);
        self::assertSame(0,(new \App\Billing\Wallet($db))->balance('u')['balance_kopeks']);
        self::assertCount(1,$db->all("SELECT id FROM orders WHERE idempotency_key LIKE 'autorenew-balance:%'"));
        self::assertFalse($billing->autoRenewFromBalance($sub['id']));
        self::assertCount(1,$db->all("SELECT id FROM orders WHERE idempotency_key LIKE 'autorenew-balance:%'"));
    }

    public function testReconcilerRoutesDailyPlansOnlyToDailyQueue(): void
    {
        [$db,$billing]=$this->setupBilling();
        $db->execute("UPDATE plans SET duration_days=1,price_minor=19900 WHERE id='p'");
        $order=$billing->order('u','p','daily-reconcile-order');
        $billing->settle($order['id'],'demo','demo_'.$order['id'],19900,'RUB');
        $sub=$db->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
        $db->execute("UPDATE subscriptions SET status='active',lifecycle_status='active',expires_at=? WHERE id=?",[time()+86400,$sub['id']]);
        $billing->setAutoRenew('u',$sub['id'],true);
        $db->execute('UPDATE subscriptions SET renew_at=?,last_daily_charge_at=? WHERE id=?',[time()-1,time()-90000,$sub['id']]);

        (new Reconciler($this->createContainer($db)))->run();

        self::assertSame(0,(int)$db->one("SELECT COUNT(*) n FROM outbox WHERE topic='subscription.renew'")['n']);
        self::assertSame(1,(int)$db->one("SELECT COUNT(*) n FROM outbox WHERE topic='subscription.daily'")['n']);
    }

    public function testDailyInsufficientBalanceWakesAsDailyAfterTopup(): void
    {
        [$db,$billing,$config]=$this->setupBilling();
        $db->execute("UPDATE plans SET duration_days=1,price_minor=19900 WHERE id='p'");
        $order=$billing->order('u','p','daily-topup-wake-order');
        $billing->settle($order['id'],'demo','demo_'.$order['id'],19900,'RUB');
        $sub=$db->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
        $db->execute("UPDATE subscriptions SET status='active',lifecycle_status='active',expires_at=?,last_daily_charge_at=? WHERE id=?",[time()+86400,time()-90000,$sub['id']]);
        $billing->setAutoRenew('u',$sub['id'],true);

        self::assertFalse($billing->dailyChargeFromBalance($sub['id']));
        $marked=$db->one('SELECT renew_failed_at FROM subscriptions WHERE id=?',[$sub['id']]);
        self::assertNotNull($marked['renew_failed_at']);

        (new \App\Billing\Wallet($db))->credit('u',19900,'manual_adjust','topup simulation');
        $payments=new Payments($db,$billing,new MockHttpClient(),$config);
        $worker=new Worker($db,new Outbox($db),$payments,new DemoProvisioner(),new MockHttpClient(),'',true,'https://astracattg.netlify.app',null,null,null,null,null,null,'demo',null,null,$billing);
        $worker->handle('topup.after',['user_id'=>'u']);

        self::assertSame(0,(int)$db->one("SELECT COUNT(*) n FROM outbox WHERE topic='subscription.renew'")['n']);
        self::assertSame(1,(int)$db->one("SELECT COUNT(*) n FROM outbox WHERE topic='subscription.daily'")['n']);
        while($db->one("SELECT id FROM outbox WHERE topic='subscription.daily' AND status='pending'")!==null) (new Outbox($db))->runOne($worker->handle(...));
        self::assertSame(0,(new \App\Billing\Wallet($db))->balance('u')['balance_kopeks']);
        self::assertSame(1,(int)$db->one("SELECT COUNT(*) n FROM transactions WHERE type='subscription_daily'")['n']);
        $charged=$db->one('SELECT renew_at,renew_failed_at FROM subscriptions WHERE id=?',[$sub['id']]);
        self::assertGreaterThan(time()+86000,(int)$charged['renew_at']);
        self::assertNull($charged['renew_failed_at']);
    }

    public function testWorkerRoutesStaleDailyRenewJobToDailyCharge(): void
    {
        [$db,$billing,$config]=$this->setupBilling();
        $db->execute("UPDATE plans SET duration_days=1,price_minor=19900 WHERE id='p'");
        $order=$billing->order('u','p','daily-stale-renew-order');
        $billing->settle($order['id'],'demo','demo_'.$order['id'],19900,'RUB');
        $sub=$db->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
        $oldExpiry=time()+86400;
        $db->execute("UPDATE subscriptions SET status='active',lifecycle_status='active',expires_at=?,renew_at=?,last_daily_charge_at=? WHERE id=?",[$oldExpiry,time()-1,time()-90000,$sub['id']]);
        $billing->setAutoRenew('u',$sub['id'],true);
        $db->execute('UPDATE subscriptions SET renew_at=?,last_daily_charge_at=? WHERE id=?',[time()-1,time()-90000,$sub['id']]);
        (new \App\Billing\Wallet($db))->credit('u',19900,'manual_adjust','stale renew funds');
        $payments=new Payments($db,$billing,new MockHttpClient(),$config);
        $worker=new Worker($db,new Outbox($db),$payments,new DemoProvisioner(),new MockHttpClient(),'',true,'https://astracattg.netlify.app',null,null,null,null,null,null,'demo',null,null,$billing);

        $worker->handle('subscription.renew',['subscription_id'=>$sub['id']]);

        self::assertSame(0,(int)$db->one("SELECT COUNT(*) n FROM orders WHERE idempotency_key LIKE 'autorenew-balance:%'")['n']);
        self::assertSame(1,(int)$db->one("SELECT COUNT(*) n FROM transactions WHERE type='subscription_daily'")['n']);
        self::assertGreaterThan($oldExpiry,(int)$db->one('SELECT expires_at FROM subscriptions WHERE id=?',[$sub['id']])['expires_at']);
    }

    public function testWaitingRenewalWakesAfterBalanceTopup(): void
    {
        [$db,$billing,$config]=$this->setupBilling();
        $order=$billing->order('u','p','balance-wake-order');
        $billing->settle($order['id'],'demo','demo_'.$order['id'],19900,'RUB');
        $sub=$db->one('SELECT * FROM subscriptions WHERE order_id=?',[$order['id']]);
        $db->execute("UPDATE subscriptions SET status='active',lifecycle_status='active',expires_at=? WHERE id=?",[time()+86400,$sub['id']]);
        $billing->setAutoRenew('u',$sub['id'],true);
        $db->execute('UPDATE subscriptions SET renew_at=? WHERE id=?',[time()-1,$sub['id']]);
        $payments=new Payments($db,$billing,new MockHttpClient(),$config);
        $worker=new Worker($db,new Outbox($db),$payments,new DemoProvisioner(),new MockHttpClient(),'',true,'https://astracattg.netlify.app',null,null,null,null,null,null,'demo',null,null,$billing);

        $worker->handle('subscription.renew',['subscription_id'=>$sub['id']]);
        self::assertSame(0,(int)$db->one("SELECT COUNT(*) n FROM orders WHERE idempotency_key LIKE 'autorenew-balance:%'")['n']);
        (new \App\Billing\Wallet($db))->credit('u',19900,'manual_adjust','topup simulation');
        $worker->handle('topup.after',['user_id'=>'u']);
        self::assertSame(1,(int)$db->one("SELECT COUNT(*) n FROM outbox WHERE topic='subscription.renew'")['n']);
        while($db->one("SELECT id FROM outbox WHERE topic='subscription.renew' AND status='pending'")!==null) (new Outbox($db))->runOne($worker->handle(...));
        self::assertSame(0,(new \App\Billing\Wallet($db))->balance('u')['balance_kopeks']);
    }
    public function testWebToggleAndBotToggle(): void
    {
$config=['APP_ENV'=>'test','PURCHASES_ENABLED'=>'1','APP_URL'=>'https://cabinet.example','DATABASE_DSN'=>'sqlite::memory:','DATABASE_USER'=>'','DATABASE_PASSWORD'=>'','PAYMENT_DRIVER'=>'demo','PROVISION_DRIVER'=>'demo','AUTORENEW_ENABLED'=>'1','AUTORENEW_DAYS_BEFORE'=>'3','AUTORENEW_MAX_FAILS'=>'3','REMNAWAVE_URL'=>'','REMNAWAVE_TOKEN'=>'','REMNAWAVE_SQUAD_UUID'=>'','TELEGRAM_BOT_TOKEN'=>'123456:abcdefghijklmnopqrstuvwxyz','TELEGRAM_BOT_USERNAME'=>'example_bot','TELEGRAM_WEBHOOK_SECRET'=>str_repeat('s',32),'TELEGRAM_API_BASE'=>'https://astracattg.netlify.app'];
        $c=new Container($config);$c->db->migrate(__DIR__.'/../migrations');
        $c->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
        $uid=$c->auth->register('web@example.org','correct-horse-battery');$session=$c->auth->issue($uid);$csrf=$c->auth->session($session)['csrf'];
        $web=new Application($c);
        // Create subscription via web
        $r=$web->handle(Request::create('/orders','POST',['plan_id'=>'basic','idempotency_key'=>'web-renew-key-1','_csrf'=>$csrf],['zb_session'=>$session]));
        self::assertSame(303,$r->getStatusCode());
        $orderId=basename((string)$r->headers->get('Location'));
        $web->handle(Request::create('/orders/'.$orderId.'/demo-pay','POST',['_csrf'=>$csrf],['zb_session'=>$session]));
        while($c->outbox->runOne($c->worker->handle(...))){}
        $sub=$c->db->one('SELECT * FROM subscriptions WHERE user_id=?',[$uid]);
        $c->db->execute("UPDATE subscriptions SET status='active' WHERE id=?",[$sub['id']]);
        // Toggle via web
        $r2=$web->handle(Request::create('/subscriptions/'.$sub['id'].'/autorenew','POST',['enable'=>'1','_csrf'=>$csrf],['zb_session'=>$session]));
        self::assertSame(303,$r2->getStatusCode());
        self::assertSame(1,(int)$c->db->one('SELECT auto_renew FROM subscriptions')['auto_renew']);
        // Toggle via bot
        $c->db->execute("UPDATE users SET telegram_id='999' WHERE id=?",[$uid]);
        $bot=new Telegram($c->db,$c->outbox,$c->billing,'https://cabinet.example',null,'https://astracattg.netlify.app');
        $bot->receive(['update_id'=>500,'callback_query'=>['id'=>'q1','data'=>'autorenew:'.$sub['id'],'from'=>['id'=>999],'message'=>['chat'=>['id'=>999,'type'=>'private']]]]);
        self::assertSame(0,(int)$c->db->one('SELECT auto_renew FROM subscriptions')['auto_renew']);
    }
    private function createContainer(Database $db): Container
    {
        // Minimal container for Reconciler: needs db + outbox + config
        $config=array_merge(Settings::DEFAULTS,['AUTORENEW_ENABLED'=>'1','AUTORENEW_MAX_FAILS'=>'3','PURCHASES_ENABLED'=>'1']);
        $ref=new \ReflectionClass(Container::class);
        $c=$ref->newInstanceWithoutConstructor();
        $refDb=$ref->getProperty('db');$refDb->setValue($c,$db);
        $refOutbox=$ref->getProperty('outbox');$refOutbox->setValue($c,new Outbox($db));
        $refConfig=$ref->getProperty('config');$refConfig->setValue($c,$config);
        return $c;
    }
}
