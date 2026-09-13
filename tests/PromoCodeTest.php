<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,Wallet,PromoCodeService};
use App\Identity\Auth;
final class PromoCodeTest extends TestCase
{
    private Database $db; private Outbox $outbox; private Wallet $wallet; private PromoCodeService $promos; private string $uid;
    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $this->wallet=new Wallet($this->db);
        $this->promos=new PromoCodeService($this->db,$this->outbox,$this->wallet);
        $this->uid=(new Auth($this->db))->register('user@example.org','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
    }
    private function make(array $overrides=[]): array
    {
        return $this->promos->create(array_merge([
            'code'=>'SUMMER2026','type'=>'balance','balance_bonus_kopeks'=>50000,'subscription_days'=>0,
            'traffic_gb'=>0,'max_uses'=>1,'valid_from'=>(string)time(),'valid_until'=>'','first_purchase_only'=>'0','plan_id'=>'',
        ],$overrides),$this->uid);
    }
    public function testBalancePromoCreditsWallet():void
    {
        $this->make();
        $r=$this->promos->activate($this->uid,'summer2026');
        self::assertTrue($r['success']);
        self::assertSame(50000,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertSame(1,(int)$this->db->one('SELECT current_uses FROM promocodes')['current_uses']);
    }
    public function testPromoCannotBeUsedTwiceBySameUser():void
    {
        $this->make(['max_uses'=>'10']);
        $this->promos->activate($this->uid,'SUMMER2026');
        $r=$this->promos->activate($this->uid,'SUMMER2026');
        self::assertFalse($r['success']);
        self::assertSame('already_used_by_user',$r['error']);
        self::assertSame(50000,$this->wallet->balance($this->uid)['balance_kopeks']);
    }
    public function testPromoMaxUsesEnforced():void
    {
        $this->make(['max_uses'=>'1']);
        $other=(new Auth($this->db))->register('other@example.org','correct-horse-battery');
        $this->promos->activate($this->uid,'SUMMER2026');
        $r=$this->promos->activate($other,'SUMMER2026');
        self::assertFalse($r['success']);
        self::assertSame('used',$r['error']);
    }
    public function testExpiredPromoRejected():void
    {
        $this->make(['valid_until'=>(string)(time()-3600)]);
        $r=$this->promos->activate($this->uid,'SUMMER2026');
        self::assertFalse($r['success']);
        self::assertSame('expired',$r['error']);
    }
    public function testInactivePromoRejected():void
    {
        $p=$this->make();
        $this->promos->toggle($p['id'],false,'admin');
        $r=$this->promos->activate($this->uid,'SUMMER2026');
        self::assertFalse($r['success']);
        self::assertSame('inactive',$r['error']);
    }
    public function testFirstPurchaseOnlyPromo():void
    {
        $this->make(['first_purchase_only'=>'1']);
        $this->db->execute('UPDATE users SET has_had_paid_subscription=1 WHERE id=?',[$this->uid]);
        $r=$this->promos->activate($this->uid,'SUMMER2026');
        self::assertFalse($r['success']);
        self::assertSame('not_first_purchase',$r['error']);
    }
    public function testDaysPromoExtendsSubscription():void
    {
        $this->make(['type'=>'subscription_days','subscription_days'=>'10','balance_bonus_kopeks'=>'0']);
        $now=time();
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at) VALUES('sub1',NULL,?,'active',?,?)",[$this->uid,$now+86400,$now]);
        $r=$this->promos->activate($this->uid,'SUMMER2026');
        self::assertTrue($r['success']);
        $sub=$this->db->one('SELECT * FROM subscriptions WHERE id=?',['sub1']);
        self::assertSame($now+86400+10*86400,(int)$sub['expires_at']);
        self::assertSame(1,(int)$this->db->one('SELECT has_had_paid_subscription FROM users')['has_had_paid_subscription']);
    }
    public function testDaysPromoFailsWithoutSubscription():void
    {
        $this->make(['type'=>'subscription_days','subscription_days'=>'10','balance_bonus_kopeks'=>'0']);
        $r=$this->promos->activate($this->uid,'SUMMER2026');
        self::assertFalse($r['success']);
        self::assertSame('no_subscription_for_days',$r['error']);
        self::assertSame(0,(int)$this->db->one('SELECT current_uses FROM promocodes')['current_uses']);
    }
    public function testTrialPromoCreatesTrialSubscription():void
    {
        $this->make(['type'=>'trial_subscription','subscription_days'=>'7','plan_id'=>'basic','balance_bonus_kopeks'=>'0']);
        $r=$this->promos->activate($this->uid,'SUMMER2026');
        self::assertTrue($r['success']);
        $sub=$this->db->one('SELECT * FROM subscriptions');
        self::assertSame('trial',$sub['status']);
        self::assertCount(1,$this->db->all("SELECT * FROM outbox WHERE topic='subscription.provision'"));
    }
    public function testDiscountPromo():void
    {
        $this->make(['type'=>'discount','balance_bonus_kopeks'=>'20','subscription_days'=>'24']);
        $r=$this->promos->activate($this->uid,'SUMMER2026');
        self::assertTrue($r['success']);
        $user=$this->db->one('SELECT * FROM users WHERE id=?',[$this->uid]);
        self::assertSame(20,(int)$user['promo_offer_discount_percent']);
        self::assertNotNull($user['promo_offer_discount_expires_at']);
    }
    public function testDiscountCannotStack():void
    {
        $this->make(['type'=>'discount','balance_bonus_kopeks'=>'20','subscription_days'=>'24']);
        $this->promos->activate($this->uid,'SUMMER2026');
        $this->make(['code'=>'SECOND','type'=>'discount','balance_bonus_kopeks'=>'30','subscription_days'=>'24']);
        $r=$this->promos->activate($this->uid,'SECOND');
        self::assertFalse($r['success']);
        self::assertSame('active_discount_exists',$r['error']);
    }
    public function testDuplicateCodeRejected():void
    {
        $this->make();
        $this->expectException(\App\Billing\BillingError::class);
        $this->make();
    }
}
