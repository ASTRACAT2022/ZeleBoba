<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,Wallet,GiftService};
use App\Identity\Auth;
final class GiftTest extends TestCase
{
    private Database $db; private Outbox $outbox; private Wallet $wallet; private GiftService $gifts; private string $buyer; private string $recipient;
    protected function setUp():void
    {
        $config=['CABINET_GIFT_ENABLED'=>'1','APP_URL'=>'http://localhost','TELEGRAM_BOT_USERNAME'=>'example_bot'];
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $this->wallet=new Wallet($this->db);
        $this->gifts=new GiftService($this->db,$this->outbox,$this->wallet,$config);
        $auth=new Auth($this->db);
        $this->buyer=$auth->register('buyer@example.org','correct-horse-battery');
        $this->recipient=$auth->register('recipient@example.org','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
    }
    private function fund(string $uid, int $amount=50000): void
    {
        $this->wallet->credit($uid,$amount,'balance_topup','Пополнение');
    }
    public function testPublicCodeFormat():void
    {
        $token=$this->gifts->generateToken();
        self::assertSame(64,strlen($token));
        $code=$this->gifts->publicCode($token);
        self::assertSame(64,strlen($code));
        self::assertStringStartsWith('GIFT_',$code);
    }
    public function testPurchaseFromBalance():void
    {
        $this->fund($this->buyer);
        $p=$this->gifts->purchaseFromBalance($this->buyer,'basic','gift-key-1');
        self::assertSame('paid',$p['status']);
        self::assertSame(1,(int)$p['is_gift']);
        self::assertSame(30100,$this->wallet->balance($this->buyer)['balance_kopeks']);
        self::assertSame(64,strlen($p['token']));
    }
    public function testPurchaseFailsWithoutFunds():void
    {
        $this->expectException(\App\Billing\BillingError::class);
        $this->gifts->purchaseFromBalance($this->buyer,'basic','gift-key-2');
    }
    public function testIdempotentPurchase():void
    {
        $this->fund($this->buyer);
        $a=$this->gifts->purchaseFromBalance($this->buyer,'basic','gift-key-3');
        $b=$this->gifts->purchaseFromBalance($this->buyer,'basic','gift-key-3');
        self::assertSame($a['id'],$b['id']);
        self::assertSame(30100,$this->wallet->balance($this->buyer)['balance_kopeks']);
    }
    public function testClaimActivatesSubscription():void
    {
        $this->fund($this->buyer);
        $p=$this->gifts->purchaseFromBalance($this->buyer,'basic','gift-key-4');
        $claimed=$this->gifts->claim($this->recipient,$this->gifts->publicCode($p['token']));
        self::assertSame('delivered',$claimed['status']);
        self::assertSame($this->recipient,$claimed['user_id']);
        $sub=$this->db->one('SELECT * FROM subscriptions');
        self::assertSame('provisioning',$sub['status']);
        self::assertSame($this->recipient,$sub['user_id']);
        self::assertCount(1,$this->db->all("SELECT * FROM outbox WHERE topic='subscription.provision'"));
    }
    public function testSelfClaimRejected():void
    {
        $this->fund($this->buyer);
        $p=$this->gifts->purchaseFromBalance($this->buyer,'basic','gift-key-5');
        $this->expectException(\App\Billing\BillingError::class);
        $this->gifts->claim($this->buyer,$p['token']);
    }
    public function testSecondClaimantRejected():void
    {
        $this->fund($this->buyer);
        $p=$this->gifts->purchaseFromBalance($this->buyer,'basic','gift-key-6');
        $this->gifts->claim($this->recipient,$p['token']);
        $other=(new Auth($this->db))->register('other@example.org','correct-horse-battery');
        $this->expectException(\App\Billing\BillingError::class);
        $this->gifts->claim($other,$p['token']);
    }
    public function testClaimIsIdempotentForSameRecipient():void
    {
        $this->fund($this->buyer);
        $p=$this->gifts->purchaseFromBalance($this->buyer,'basic','gift-key-7');
        $this->gifts->claim($this->recipient,$p['token']);
        $again=$this->gifts->claim($this->recipient,$p['token']);
        self::assertSame('delivered',$again['status']);
        self::assertCount(1,$this->db->all('SELECT * FROM subscriptions'));
    }
    public function testParseClaimInputFormats():void
    {
        $token=$this->gifts->generateToken();
        $code=$this->gifts->publicCode($token);
        self::assertSame(substr($token,0,59),$this->gifts->parseClaimInput($code));
        self::assertSame(substr($token,0,59),$this->gifts->parseClaimInput('https://t.me/example_bot?start='.$code));
        self::assertSame($token,$this->gifts->parseClaimInput($token));
        self::assertSame($token,$this->gifts->parseClaimInput('http://localhost/buy/gift/'.$token));
        self::assertNull($this->gifts->parseClaimInput('garbage'));
    }
    public function testGiftDisabled():void
    {
        $gifts=new GiftService($this->db,$this->outbox,$this->wallet,['CABINET_GIFT_ENABLED'=>'0']);
        $this->fund($this->buyer);
        $this->expectException(\App\Billing\BillingError::class);
        $gifts->purchaseFromBalance($this->buyer,'basic','gift-key-8');
    }
}
