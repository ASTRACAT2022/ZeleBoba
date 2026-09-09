<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Settings\{Settings,Vault};
use App\Identity\{Auth,TelegramLogin,Mfa};
use App\Billing\{BillingService,BillingError};
use App\Integration\Telegram;
final class OperationsTest extends TestCase
{
    private Database $db;private Settings $settings;private Vault $vault;private string $keyPath;private Auth $auth;private TelegramLogin $login;
    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->keyPath=sys_get_temp_dir().'/zb-key-'.bin2hex(random_bytes(8));$this->vault=new Vault($this->keyPath);$this->settings=new Settings($this->db,$this->vault);$this->auth=new Auth($this->db);$this->login=new TelegramLogin($this->db,$this->auth);
    }
    protected function tearDown():void{if(is_file($this->keyPath))unlink($this->keyPath);}
    public function testSettingsSecretsAreEncryptedAndNotReturnedToForm():void
    {
        $this->settings->save(['REMNAWAVE_TOKEN'=>'super-secret','SITE_NAME'=>'My service'],'owner',0);
        self::assertSame('super-secret',$this->settings->values()['REMNAWAVE_TOKEN']);self::assertSame('',$this->settings->form()['values']['REMNAWAVE_TOKEN']);self::assertTrue($this->settings->form()['secrets_set']['REMNAWAVE_TOKEN']);
        self::assertStringNotContainsString('super-secret',$this->db->one("SELECT value FROM app_settings WHERE name='REMNAWAVE_TOKEN'")['value']);
        self::assertStringNotContainsString('super-secret',json_encode($this->db->all('SELECT * FROM audit_log')));
        $this->settings->save(['REMNAWAVE_TOKEN'=>''],'owner',1);self::assertSame('super-secret',$this->settings->values()['REMNAWAVE_TOKEN']);self::assertSame(0600,fileperms($this->keyPath)&0777);
    }
    public function testConcurrentSettingsRevisionIsRejected():void
    {
        $this->settings->save(['SITE_NAME'=>'One'],'owner',0);$this->expectException(BillingError::class);$this->settings->save(['SITE_NAME'=>'Two'],'owner',0);
    }
    public function testEncryptedValuesCannotBeSwapped():void
    {
        $sealed=$this->vault->seal('first','secret');$this->expectException(\RuntimeException::class);$this->vault->open('second',$sealed);
    }
    public function testPanelIdentityCannotBeSilentlyReplaced():void
    {
        $this->settings->save(['REMNAWAVE_URL'=>'https://panel.example'],'owner',0);$this->expectException(BillingError::class);$this->settings->save(['REMNAWAVE_URL'=>'https://other.example'],'owner',1);
    }
    public function testBrowserLoginBoundToOriginatingBrowserAndConsumedOnce():void
    {
        $challenge=$this->login->begin();self::assertFalse($this->login->ready($challenge['browser']));self::assertTrue($this->login->approve($challenge['token'],'123456'));self::assertFalse($this->login->approve($challenge['token'],'999999'));self::assertFalse($this->login->ready(str_repeat('a',64)));
        try{$this->login->consume(str_repeat('a',64),'browser');self::fail();}catch(BillingError){}
        $session=$this->login->consume($challenge['browser'],'browser');self::assertSame('123456',$this->auth->session($session)['telegram_id']);
        $this->expectException(BillingError::class);$this->login->consume($challenge['browser'],'browser');
    }
    public function testExpiredChallengeCannotBeApproved():void
    {
        $challenge=$this->login->begin();$this->db->execute('UPDATE login_challenges SET expires_at=0');self::assertFalse($this->login->approve($challenge['token'],'123'));
    }
    public function testBotMagicIsOneTimeAndInvalidatesEarlierLink():void
    {
        $first=$this->login->magic('123');$second=$this->login->magic('123');
        try{$this->login->consume($first,'magic');self::fail();}catch(BillingError){}
        $session=$this->login->consume($second,'magic');self::assertSame('123',$this->auth->session($session)['telegram_id']);self::assertSame(0,(int)$this->auth->session($session)['admin_verified_until']);
        $this->expectException(BillingError::class);$this->login->consume($second,'magic');
    }
    public function testDisabledAccountCannotConsumeApprovedLogin():void
    {
        $token=$this->login->magic('123');$this->db->execute('UPDATE users SET disabled=1');$this->expectException(BillingError::class);$this->login->consume($token,'magic');
    }
    public function testTelegramDeepLinkPromptsForConfirmationWithoutAutomaticApproval():void
    {
        $challenge=$this->login->begin();$outbox=new Outbox($this->db);$bot=new Telegram($this->db,$outbox,new BillingService($this->db,$outbox,'demo'),'https://cabinet.example',$this->login);
        $bot->receive(['update_id'=>20,'message'=>['from'=>['id'=>123],'chat'=>['id'=>123,'type'=>'private'],'text'=>'/start login_'.$challenge['token']]]);
        self::assertFalse($this->login->ready($challenge['browser']));$job=json_decode($this->db->one('SELECT payload FROM outbox')['payload'],true);$callback=$job['reply_markup']['inline_keyboard'][0][0]['callback_data'];self::assertLessThanOrEqual(64,strlen($callback));
        $bot->receive(['update_id'=>21,'callback_query'=>['data'=>$callback,'from'=>['id'=>123],'message'=>['chat'=>['id'=>123,'type'=>'private']]]]);self::assertTrue($this->login->ready($challenge['browser']));
    }
    public function testDuplicateBotLoginKeepsOriginalValidLink():void
    {
        $outbox=new Outbox($this->db);$bot=new Telegram($this->db,$outbox,new BillingService($this->db,$outbox,'demo'),'https://cabinet.example',$this->login);
        $update=['update_id'=>10,'message'=>['from'=>['id'=>123],'chat'=>['id'=>123,'type'=>'private'],'text'=>'/login']];$bot->receive($update);$bot->receive($update);
        self::assertCount(1,$this->db->all('SELECT * FROM login_challenges'));self::assertCount(1,$this->db->all('SELECT * FROM outbox'));
        $job=json_decode($this->db->one('SELECT payload FROM outbox')['payload'],true);$token=parse_url($job['reply_markup']['inline_keyboard'][0][0]['url'],PHP_URL_FRAGMENT);self::assertNotEmpty($this->login->consume($token,'magic'));
    }
    public function testOutboxEncryptsAndErasesLoginLinksAfterDelivery():void
    {
        $outbox=new Outbox($this->db,$this->vault);$outbox->enqueue('telegram.send','test',['text'=>'secret-link']);
        self::assertStringNotContainsString('secret-link',$this->db->one('SELECT payload FROM outbox')['payload']);
        $outbox->runOne(function($topic,$payload){self::assertSame('secret-link',$payload['text']);});self::assertSame('{}',$this->db->one('SELECT payload FROM outbox')['payload']);
    }
    public function testTotpMatchesRfcAndRejectsReuse():void
    {
        self::assertSame('287082',Mfa::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',1));
        $uid=$this->auth->register('mfa@example.org','correct-horse-battery');$mfa=new Mfa($this->db,$this->vault);$secret=$mfa->begin($uid);$code=Mfa::code($secret,intdiv(time(),30));$recovery=$mfa->enroll($uid,$code);self::assertCount(8,$recovery);
        try{$mfa->verify($uid,$code);self::fail();}catch(BillingError){}
        $mfa->verify($uid,$recovery[0]);self::assertCount(7,$this->db->all('SELECT * FROM mfa_recovery'));$this->expectException(BillingError::class);$mfa->verify($uid,$recovery[0]);
    }
    public function testSalesPauseBlocksNewOrdersButNotSettlement():void
    {
        $uid=$this->auth->register('buy@example.org','correct-horse-battery');$this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices) VALUES('p','P',100,'RUB',30,0,1)");
        $outbox=new Outbox($this->db);$service=new BillingService($this->db,$outbox,'demo',array_merge(Settings::DEFAULTS,['PURCHASES_ENABLED'=>'1']));$order=$service->order($uid,'p','purchase-key');
        $paused=new BillingService($this->db,$outbox,'demo',Settings::DEFAULTS);$paused->settle($order['id'],'demo','payment',100,'RUB');self::assertCount(1,$this->db->all('SELECT * FROM subscriptions'));
        $this->expectException(BillingError::class);$paused->order($uid,'p','second-key');
    }
}
