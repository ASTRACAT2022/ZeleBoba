<?php
declare(strict_types=1);
namespace Tests;
use App\{Container,Web\Application};
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\TestCase;
final class WebTest extends TestCase
{
    private Container $c;private Application $web;private string $uid;private string $session;private string $csrf;
    protected function setUp():void
    {
        $config=['APP_ENV'=>'test','PURCHASES_ENABLED'=>'1','APP_URL'=>'http://localhost','DATABASE_DSN'=>'sqlite::memory:','DATABASE_USER'=>'','DATABASE_PASSWORD'=>'','PAYMENT_DRIVER'=>'demo','PROVISION_DRIVER'=>'demo','YOOKASSA_SHOP_ID'=>'','YOOKASSA_SECRET'=>'','REMNAWAVE_URL'=>'','REMNAWAVE_TOKEN'=>'','REMNAWAVE_SQUAD_UUID'=>'','TELEGRAM_BOT_TOKEN'=>'123456:abcdefghijklmnopqrstuvwxyz','TELEGRAM_BOT_USERNAME'=>'example_bot','TELEGRAM_WEBHOOK_SECRET'=>str_repeat('s',32)];
        $this->c=new Container($config);$this->c->db->migrate(__DIR__.'/../migrations');$this->c->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
        $this->uid=$this->c->auth->register('a@example.org','correct-horse-battery');$this->session=$this->c->auth->issue($this->uid);$this->csrf=$this->c->auth->session($this->session)['csrf'];$this->web=new Application($this->c);
    }
    private function request(string $path,string $method='GET',array $data=[]):\Symfony\Component\HttpFoundation\Response{return $this->web->handle(Request::create($path,$method,$data,['zb_session'=>$this->session]));}
    public function testAllCabinetViewsRender():void
    {
        foreach(['/','/plans','/orders','/settings','/balance'] as $path)self::assertSame(200,$this->request($path)->getStatusCode(),$path);
    }
    public function testAuthFormsAndRegistration():void
    {
        $r=$this->web->handle(Request::create('/register'));self::assertSame(200,$r->getStatusCode());$guest=$r->headers->getCookies()[0]->getValue();
        $r=$this->web->handle(Request::create('/register','POST',['email'=>'b@example.org','password'=>'a-long-password-123','_csrf'=>$guest],['zb_guest'=>$guest]));self::assertSame(303,$r->getStatusCode());self::assertSame('zb_session',$r->headers->getCookies()[0]->getName());
    }
    public function testGuestRedirectedAndPostRequiresCsrf():void
    {
        self::assertSame('/login',$this->web->handle(Request::create('/'))->headers->get('Location'));
        self::assertSame(403,$this->request('/orders','POST',['plan_id'=>'basic','idempotency_key'=>'request-key'])->getStatusCode());self::assertCount(0,$this->c->db->all('SELECT * FROM orders'));
    }
    public function testCustomerCannotReadOrPayOtherUsersOrder():void
    {
        $other=$this->c->auth->register('other@example.org','correct-horse-battery');$o=$this->c->billing->order($other,'basic','request-key');
        self::assertSame(404,$this->request('/orders/'.$o['id'])->getStatusCode());self::assertSame(404,$this->request('/orders/'.$o['id'].'/demo-pay','POST',['_csrf'=>$this->csrf])->getStatusCode());
    }
    public function testFullWebPurchaseCycle():void
    {
        $response=$this->request('/orders','POST',['_csrf'=>$this->csrf,'plan_id'=>'basic','idempotency_key'=>'request-key']);self::assertSame(303,$response->getStatusCode());$location=$response->headers->get('Location');
        self::assertSame(200,$this->request($location)->getStatusCode());self::assertSame(303,$this->request($location.'/demo-pay','POST',['_csrf'=>$this->csrf])->getStatusCode());
        while($this->c->outbox->runOne($this->c->worker->handle(...))){}
        self::assertSame('fulfilled',$this->c->db->one('SELECT status FROM orders')['status']);self::assertStringContainsString('Тестовая подписка',$this->request('/')->getContent());
    }
    public function testTopupAndBalancePurchaseCycle():void
    {
        $response=$this->request('/balance/topup','POST',['_csrf'=>$this->csrf,'amount'=>'500','idempotency_key'=>'topup-web-1']);self::assertSame(303,$response->getStatusCode());
        $topup=$this->c->db->one('SELECT * FROM topups');self::assertSame('pending',$topup['status']);
        self::assertSame(303,$this->request('/balance/topup/'.$topup['id'].'/demo-pay','POST',['_csrf'=>$this->csrf])->getStatusCode());
        self::assertSame(50000,$this->c->wallet->balance($this->uid)['balance_kopeks']);
        self::assertSame(200,$this->request('/balance')->getStatusCode());
        self::assertStringContainsString('500 ₽',$this->request('/balance')->getContent());
        $response=$this->request('/orders/balance','POST',['_csrf'=>$this->csrf,'plan_id'=>'basic']);self::assertSame(303,$response->getStatusCode());
        while($this->c->outbox->runOne($this->c->worker->handle(...))){}
        self::assertSame('fulfilled',$this->c->db->one('SELECT status FROM orders')['status']);
        self::assertSame(30100,$this->c->wallet->balance($this->uid)['balance_kopeks']);
        self::assertCount(2,$this->c->db->all('SELECT * FROM transactions'));
    }
    public function testBalancePurchaseFailsWithoutFunds():void
    {
        $response=$this->request('/orders/balance','POST',['_csrf'=>$this->csrf,'plan_id'=>'basic']);
        self::assertSame(422,$response->getStatusCode());
        self::assertCount(0,$this->c->db->all('SELECT * FROM orders'));
    }
    public function testAdminAuthorizationAndCreatePlan():void
    {
        self::assertSame(403,$this->request('/admin')->getStatusCode());$this->c->db->execute("UPDATE users SET role='admin' WHERE id=?",[$this->uid]);
        self::assertSame(303,$this->request('/admin')->getStatusCode());$this->c->db->execute('UPDATE users SET totp_secret=? WHERE id=?',['test-fixture',$this->uid]);$this->c->mfa->stepUp($this->session);self::assertSame(200,$this->request('/admin')->getStatusCode());self::assertSame(303,$this->request('/admin/plans','POST',['_csrf'=>$this->csrf,'name'=>'New','price_minor'=>'50000','duration_days'=>'60','traffic_gb'=>'0','devices'=>'4'])->getStatusCode());self::assertCount(2,$this->c->db->all('SELECT * FROM plans'));self::assertCount(1,$this->c->db->all('SELECT * FROM audit_log'));
    }
    public function testInvalidTelegramSecretRejected():void
    {
        $r=Request::create('/webhooks/telegram','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode(['update_id'=>1]));self::assertSame(403,$this->web->handle($r)->getStatusCode());
    }
    public function testProductionCannotUseDemo():void
    {
        $this->expectException(\RuntimeException::class);new Container(array_merge($this->c->config,['APP_ENV'=>'prod']));
    }
    public function testEscapesPlanNames():void
    {
        $this->c->db->execute('UPDATE plans SET name=?',['<script>alert(1)</script>']);$body=$this->request('/plans')->getContent();self::assertStringNotContainsString('<script>',$body);self::assertStringContainsString('&lt;script&gt;',$body);
    }
    public function testNewAdminPagesRequireMfaAndRenderAfterVerification():void
    {
        $this->c->db->execute("UPDATE users SET role='admin' WHERE id=?",[$this->uid]);
        foreach(['/admin/config','/admin/readiness','/admin/plans','/admin/users'] as $path)self::assertSame(303,$this->request($path)->getStatusCode());
        $this->c->db->execute('UPDATE users SET totp_secret=? WHERE id=?',['fixture',$this->uid]);$this->c->mfa->stepUp($this->session);
        foreach(['/admin/config','/admin/readiness','/admin/plans','/admin/users','/security'] as $path)self::assertSame(200,$this->request($path)->getStatusCode(),$path);
        self::assertSame(303,$this->request('/admin/config','POST',['_csrf'=>$this->csrf,'revision'=>'0','SITE_NAME'=>'Test service'])->getStatusCode());
        self::assertSame('Test service',$this->c->settings->values()['SITE_NAME']);
    }
    public function testTelegramHttpEntryAndOneTimeFinish():void
    {
        $guest=str_repeat('a',64);
        $r=$this->web->handle(Request::create('/telegram/start','POST',['_csrf'=>$guest],['zb_guest'=>$guest]));
        self::assertSame(200,$r->getStatusCode());preg_match('/start=login_([a-f0-9]+)/',$r->getContent(),$match);self::assertNotEmpty($match);
        $browser=$r->headers->getCookies()[0]->getValue();$this->c->telegramLogin->approve($match[1],'112233');
        $r=$this->web->handle(Request::create('/telegram/finish','POST',['_csrf'=>$guest],['zb_guest'=>$guest,'zb_tg'=>$browser]));self::assertSame(303,$r->getStatusCode());
        $r=$this->web->handle(Request::create('/telegram/finish','POST',['_csrf'=>$guest],['zb_guest'=>$guest,'zb_tg'=>$browser]));self::assertSame(422,$r->getStatusCode());
    }

}
