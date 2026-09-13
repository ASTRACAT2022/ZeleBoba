<?php
declare(strict_types=1);
namespace Tests;

use App\{Container,Web\Application};
use App\Billing\BillingError;
use App\Infrastructure\Reconciler;
use App\Integration\{PaymentService,Payment\ProviderInterface,Payment\ProviderRegistry};
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\TestCase;

final class AuditRegressionTest extends TestCase
{
    private Container $app;
    private Application $web;
    private string $session;
    private string $csrf;

    protected function setUp(): void
    {
        $this->app=new Container(['DATABASE_DSN'=>'sqlite::memory:','APP_ENV'=>'test','PURCHASES_ENABLED'=>'1','CABINET_GIFT_ENABLED'=>'1','APP_URL'=>'http://localhost']);
        $this->app->db->migrate(__DIR__.'/../migrations');
        foreach (['buyer','recipient','other'] as $id) $this->app->db->execute('INSERT INTO users(id,email,created_at) VALUES(?,?,?)',[$id,$id.'@example.test',time()]);
        $this->app->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,is_trial_available,trial_duration_days) VALUES('basic','Basic',19900,'RUB',30,0,3,1,1,3)");
        $this->session=$this->app->auth->issue('recipient');
        $this->csrf=$this->app->auth->session($this->session)['csrf'];
        $this->web=new Application($this->app);
    }
    private function request(string $path,string $method='GET',array $data=[]): \Symfony\Component\HttpFoundation\Response
    {
        return $this->web->handle(Request::create($path,$method,$data,['zb_session'=>$this->session]));
    }
    private function gift(): array
    {
        $this->app->wallet->credit('buyer',50000,'manual_adjust','fixture');
        return $this->app->gifts->purchaseFromBalance('buyer','basic','gift-regression');
    }
    public function testWildcardAndTruncatedGiftCodesCannotClaim(): void
    {
        $gift=$this->gift();
        foreach (['GIFT_%','GIFT_','GIFT_'.str_repeat('_',59),substr($gift['token'],0,8)] as $code) {
            self::assertSame(422,$this->request('/gifts/claim','POST',['_csrf'=>$this->csrf,'code'=>$code])->getStatusCode());
        }
        self::assertSame('paid',$this->app->db->one('SELECT status FROM guest_purchases')['status']);
    }
    public function testGiftGetOnlyDisplaysConfirmationAndPostProvisions(): void
    {
        $gift=$this->gift();
        $response=$this->request('/buy/gift/'.$gift['token']);
        self::assertSame(200,$response->getStatusCode());
        self::assertStringContainsString('value="'.$gift['token'].'"',$response->getContent());
        self::assertSame('paid',$this->app->db->one('SELECT status FROM guest_purchases')['status']);
        self::assertSame(403,$this->request('/gifts/claim','POST',['code'=>$gift['token']])->getStatusCode());
        self::assertSame(200,$this->request('/gifts/claim','POST',['_csrf'=>$this->csrf,'code'=>$gift['token']])->getStatusCode());
        $sub=$this->app->db->one('SELECT * FROM subscriptions');
        $this->app->worker->handle('subscription.provision',['subscription_id'=>$sub['id']]);
        $sub=$this->app->db->one('SELECT * FROM subscriptions');
        self::assertSame('active',$sub['status']);
        self::assertSame('demo_'.$sub['id'],$sub['remote_id']);
    }
    public function testMalformedGiftLinkIsRejected(): void
    {
        self::assertNull($this->app->gifts->parseClaimInput('https://t.me/bot?start[]=x'));
    }
    public function testPasswordResetRevokesAllSessionsAndSiblingTokens(): void
    {
        $token=$this->app->auth->createPasswordReset('recipient@example.test');
        $other=$this->app->auth->createPasswordReset('recipient@example.test');
        self::assertTrue($this->app->auth->applyPasswordReset($token,'new-correct-password'));
        self::assertNull($this->app->auth->session($this->session));
        self::assertFalse($this->app->auth->applyPasswordReset($token,'replacement-password'));
        self::assertFalse($this->app->auth->applyPasswordReset($other,'replacement-password'));
        self::assertSame('recipient',$this->app->auth->login('recipient@example.test','new-correct-password'));
    }
    public function testBalancePurchaseReplayChargesOnce(): void
    {
        $this->app->wallet->credit('recipient',50000,'manual_adjust','fixture');
        $data=['_csrf'=>$this->csrf,'plan_id'=>'basic','idempotency_key'=>'balance-regression'];
        self::assertSame(303,$this->request('/orders/balance','POST',$data)->getStatusCode());
        self::assertSame(303,$this->request('/orders/balance','POST',$data)->getStatusCode());
        self::assertSame(30100,$this->app->wallet->balance('recipient')['balance_kopeks']);
        self::assertCount(1,$this->app->db->all('SELECT * FROM orders'));
        self::assertCount(2,$this->app->wallet->history('recipient'));
    }
    public function testTrialProvisionsAndCannotBeRepeatedAfterExpiry(): void
    {
        $sub=$this->app->trials->start('recipient','basic');
        $this->app->worker->handle('subscription.provision',['subscription_id'=>$sub['id']]);
        self::assertSame('demo_'.$sub['id'],$this->app->db->one('SELECT remote_id FROM subscriptions')['remote_id']);
        $this->app->db->execute("UPDATE subscriptions SET status='expired'");
        self::assertFalse($this->app->trials->available('recipient'));
        $this->expectException(BillingError::class);
        $this->app->trials->start('recipient','basic');
    }
    public function testAssignedRoleRequiresMfaAndCannotAccessOtherSections(): void
    {
        $role=$this->app->rbac->createRole('Support','',1,['admin.users'],'buyer');
        $this->app->rbac->assignRole('recipient',$role['id'],'buyer');
        self::assertSame(303,$this->request('/admin/users')->getStatusCode());
        $this->app->db->execute("UPDATE users SET totp_secret='fixture' WHERE id='recipient'");
        $this->app->mfa->stepUp($this->session);
        self::assertSame(200,$this->request('/admin/users')->getStatusCode());
        self::assertSame(403,$this->request('/admin/config')->getStatusCode());
        self::assertSame(403,$this->request('/admin/roles')->getStatusCode());
        $this->app->rbac->revokeRole('recipient',$role['id'],'buyer');
        self::assertSame(403,$this->request('/admin/users')->getStatusCode());
    }
    public function testAdminArrayFormsAndReportPeriod(): void
    {
        $this->app->db->execute("UPDATE users SET role='admin',totp_secret='fixture' WHERE id='recipient'");
        $this->app->mfa->stepUp($this->session);
        self::assertSame(303,$this->request('/admin/roles','POST',['_csrf'=>$this->csrf,'name'=>'Reader','permissions'=>['admin.view']])->getStatusCode());
        self::assertSame(303,$this->request('/admin/polls','POST',['_csrf'=>$this->csrf,'title'=>'Question','q_text'=>['Choose'],'q_options_0'=>['Yes','No']])->getStatusCode());
        self::assertCount(1,$this->app->db->all('SELECT * FROM poll_questions'));
        self::assertSame(200,$this->request('/admin/reports?days=7')->getStatusCode());
    }
    public function testBrandingStyleHasMatchingCspNonce(): void
    {
        $response=$this->request('/');
        preg_match('/<style nonce="([a-f0-9]+)">/',$response->getContent(),$match);
        self::assertNotEmpty($match);
        self::assertStringContainsString("'nonce-".$match[1]."'",$response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('unsafe-inline',$response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString(' style="',$response->getContent());
    }
    public function testTopupCannotChangeAttachedPaymentId(): void
    {
        $topup=$this->app->topups->create('recipient',10000,'topup-regression');
        $this->app->db->execute('UPDATE topups SET provider_payment_id=? WHERE id=?',['original',$topup['id']]);
        $this->expectException(BillingError::class);
        $this->app->topups->settle($topup['id'],'demo','replacement',10000,'RUB');
    }
    public function testWalletRollsBackBalanceIfTransactionInsertFails(): void
    {
        $this->app->db->execute("CREATE TRIGGER reject_transaction BEFORE INSERT ON transactions BEGIN SELECT RAISE(ABORT,'fixture'); END");
        try { $this->app->wallet->credit('recipient',10000,'manual_adjust','fixture'); self::fail('Expected insertion failure'); }
        catch (\PDOException) {}
        self::assertSame(0,$this->app->wallet->balance('recipient')['balance_kopeks']);
    }
    public function testWebhookCancellationIsVerifiedAndScopedToProvider(): void
    {
        $topup=$this->app->topups->create('recipient',10000,'webhook-regression','wata');
        $this->app->db->execute('UPDATE topups SET provider_payment_id=? WHERE id=?',['same-id',$topup['id']]);
        $provider=new class implements ProviderInterface {
            public function id(): string { return 'wata'; }
            public function name(): string { return 'fixture'; }
            public function configured(): bool { return true; }
            public function createTopup(array $topup,array $user): array { return []; }
            public function createOrder(array $order,array $user): array { return []; }
            public function verify(string $paymentId): array { return ['status'=>'pending','payment_id'=>$paymentId,'metadata'=>[]]; }
            public function handleWebhook(Request $request): ?array { return ['payment_id'=>'same-id','status'=>'canceled']; }
        };
        $http=new MockHttpClient();
        $registry=new ProviderRegistry($http,[]);
        $registry->register($provider);
        $service=new PaymentService($this->app->db,$this->app->billing,$http,[],$registry);
        self::assertTrue($service->handleWebhook('wata',Request::create('/','POST')));
        self::assertSame('pending',$this->app->db->one('SELECT status FROM topups')['status']);
        $job=$this->app->db->one("SELECT payload FROM outbox WHERE topic='payment.verify'");
        self::assertSame('wata',json_decode($job['payload'],true)['provider']);
        $service->verify('same-id','wata');
        self::assertSame('pending',$this->app->db->one('SELECT status FROM topups')['status']);
    }
    public function testReferralJobCanBeRetriedWithoutDuplicateBonuses(): void
    {
        $this->app->referrals->attachReferrer('recipient',$this->app->referrals->ensureCode('buyer'));
        $topup=$this->app->topups->create('recipient',50000,'referral-job');
        $this->app->topups->settle($topup['id'],'demo','demo_'.$topup['id'],50000,'RUB');
        $payload=['topup_id'=>$topup['id'],'user_id'=>'recipient','amount_kopeks'=>50000];
        $this->app->worker->handle('referral.topup',$payload);
        $this->app->worker->handle('referral.topup',$payload);
        self::assertSame(60000,$this->app->wallet->balance('recipient')['balance_kopeks']);
        self::assertSame(22500,$this->app->wallet->balance('buyer')['balance_kopeks']);
        self::assertCount(1,$this->app->db->all('SELECT * FROM referral_earnings'));
    }
    public function testWithdrawalReservesFundsAndRejectionRefundsOnce(): void
    {
        $service=new \App\Billing\ReferralService($this->app->db,$this->app->outbox,$this->app->wallet,['REFERRAL_WITHDRAWAL_ENABLED'=>'1','REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS'=>'100']);
        $this->app->referrals->attachReferrer('recipient',$this->app->referrals->ensureCode('buyer'));
        $service->processTopup('recipient',50000);
        $withdrawal=$service->requestWithdrawal('buyer',10000,'payment details');
        self::assertSame(12500,$this->app->wallet->balance('buyer')['balance_kopeks']);
        $service->processWithdrawal($withdrawal['id'],'approved','ok','other');
        $service->processWithdrawal($withdrawal['id'],'rejected','no','other');
        $service->processWithdrawal($withdrawal['id'],'rejected','no','other');
        self::assertSame(22500,$this->app->wallet->balance('buyer')['balance_kopeks']);
    }
    public function testBackupRestoresIndexesAndForeignKeys(): void
    {
        $dir=sys_get_temp_dir().'/zb-audit-backup-'.bin2hex(random_bytes(6));
        $backup=new \App\Billing\BackupService($this->app->db,$dir);
        try {
            $file=$backup->create();
            self::assertSame(0600,fileperms($file)&0777);
            $backup->restore(basename($file));
            self::assertSame(1,(int)$this->app->db->one('PRAGMA foreign_keys')['foreign_keys']);
            self::assertNotNull($this->app->db->one("SELECT name FROM sqlite_master WHERE type='index' AND name='transactions_user_created'"));
        } finally {
            foreach (glob($dir.'/*')?:[] as $file) unlink($file);
            if (is_dir($dir)) rmdir($dir);
        }
    }
    public function testLandingIsPublicAndCheckoutUsesAdvertisedPrice(): void
    {
        $this->app->landings->save(['slug'=>'summer','title'=>'Summer','discount_percent'=>'20'],'buyer');
        self::assertSame(200,$this->web->handle(Request::create('/l/summer'))->getStatusCode());
        $response=$this->request('/orders','POST',['_csrf'=>$this->csrf,'plan_id'=>'basic','idempotency_key'=>'landing-price','landing_slug'=>'summer']);
        self::assertSame(303,$response->getStatusCode());
        self::assertSame(15920,(int)$this->app->db->one('SELECT price_minor FROM orders')['price_minor']);
    }
    public function testForwardedHeaderCannotBypassFreekassaIpAllowlist(): void
    {
        $config=array_merge($this->app->config,['PAYMENT_DRIVER'=>'freekassa','FREEKASSA_SHOP_ID'=>'123','FREEKASSA_SECRET2'=>'secret']);
        $request=Request::create('/webhooks/freekassa','POST',['MERCHANT_ID'=>'123','AMOUNT'=>'100','MERCHANT_ORDER_ID'=>str_repeat('a',32),'SIGN'=>md5('123:100:secret:'.str_repeat('a',32))],[],[],['REMOTE_ADDR'=>'203.0.113.1','HTTP_X_REAL_IP'=>'168.119.157.136','HTTP_X_FORWARDED_FOR'=>'168.119.157.136']);
        // This container needs its own in-memory schema.
        $container=new Container($config);
        $container->db->migrate(__DIR__.'/../migrations');
        self::assertSame(403,(new Application($container))->handle($request)->getStatusCode());
    }

    public function testPersonalDiscountIsShownAndChargedWithoutStacking(): void
    {
        $this->app->userAdmin->setDiscount('recipient',25,24,'buyer');
        self::assertStringContainsString('149,25 ₽',$this->request('/plans')->getContent());
        $this->app->landings->save(['slug'=>'offer','title'=>'Offer','discount_percent'=>'20'],'buyer');
        $response=$this->request('/orders','POST',['_csrf'=>$this->csrf,'plan_id'=>'basic','idempotency_key'=>'personal-price','landing_slug'=>'offer']);
        self::assertSame(303,$response->getStatusCode());
        self::assertSame(14925,(int)$this->app->db->one('SELECT price_minor FROM orders')['price_minor']);
    }
    public function testIncompletePaymentProvidersCannotCollectMoney(): void
    {
        foreach (['telegram_stars','tribute'] as $provider) {
            try { $this->app->topups->create('recipient',10000,'unsupported-'.$provider,$provider); self::fail('Unsupported checkout accepted'); }
            catch (BillingError) {}
        }
        self::assertCount(0,$this->app->db->all('SELECT * FROM topups'));
    }

    public function testReconcilerQueuesEveryConfiguredProviderAndSkipsDisabledOnes(): void
    {
        $app=new Container([
            'DATABASE_DSN'=>'sqlite::memory:', 'APP_ENV'=>'test', 'PURCHASES_ENABLED'=>'1', 'APP_URL'=>'http://localhost',
            'CRYPTOBOT_ENABLED'=>'1', 'CRYPTOBOT_API_TOKEN'=>'fixture-token',
        ]);
        $app->db->migrate(__DIR__.'/../migrations');
        $app->db->execute("INSERT INTO users(id,email,created_at) VALUES('user','user@example.test',?)",[time()]);
        $app->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices) VALUES('plan','Plan',100,'RUB',30,0,1)");
        $now=time();
        $app->db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,provider_payment_id,created_at) VALUES('crypto-order','user','plan','reconcile-order',100,'RUB','Plan',30,0,1,'pending','cryptobot','invoice-1',?)",[$now]);
        $app->db->execute("INSERT INTO topups(id,user_id,amount_kopeks,currency,status,provider,idempotency_key,provider_payment_id,created_at) VALUES('unsupported-topup','user',100,'RUB','pending','tribute','reconcile-topup','invoice-2',?)",[$now]);
        (new Reconciler($app))->run();
        self::assertCount(1,$app->db->all("SELECT * FROM outbox WHERE topic='payment.verify'"));
        $received=[];
        self::assertTrue($app->outbox->runOne(function(string $topic,array $payload) use (&$received): void {
            $received=['topic'=>$topic,'payload'=>$payload];
        }));
        self::assertSame(['topic'=>'payment.verify','payload'=>['payment_id'=>'invoice-1','provider'=>'cryptobot']],$received);
    }

    public function testReconcilerRecoversProvisioningAfterDeadJob(): void
    {
        $now=time();
        $this->app->db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,created_at) VALUES('paid-order','recipient','basic','paid-order-key',19900,'RUB','Basic',30,0,3,'paid','demo',?)",[$now]);
        $this->app->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit) VALUES('stuck-sub','paid-order','recipient','provisioning',?,?,0,3)",[$now+86400,$now]);
        $this->app->outbox->enqueue('subscription.provision','provision:stuck-sub',['subscription_id'=>'stuck-sub']);
        $this->app->db->execute("UPDATE outbox SET status='dead' WHERE dedup_key='provision:stuck-sub'");
        (new Reconciler($this->app))->run();
        self::assertCount(1,$this->app->db->all("SELECT * FROM outbox WHERE topic='subscription.provision' AND dedup_key LIKE 'reconcile-provision:stuck-sub:%'"));
    }

    public function testMalformedConfiguredProviderWebhookIsRejectedWithoutServerError(): void
    {
        $config=['CRYPTOBOT_ENABLED'=>'1','CRYPTOBOT_API_TOKEN'=>'fixture-token'];
        $http=new MockHttpClient();
        $registry=new ProviderRegistry($http,$config);
        $registry->register(new \App\Integration\Payment\CryptoBotProvider($http,$config));
        $service=new PaymentService($this->app->db,$this->app->billing,$http,$config,$registry);
        self::assertFalse($service->handleWebhook('cryptobot',Request::create('/','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],'{')));
    }

}
