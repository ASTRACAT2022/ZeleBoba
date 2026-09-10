<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,Wallet,TrialService};
use App\Identity\Auth;
final class TrialTest extends TestCase
{
    private Database $db; private Outbox $outbox; private Wallet $wallet; private TrialService $trials; private string $uid;
    protected function setUp():void
    {
        $config=['TRIAL_DURATION_DAYS'=>'3','TRIAL_ADD_REMAINING_DAYS_TO_PAID'=>'0','TRIAL_PAYMENT_ENABLED'=>'0','TRIAL_ACTIVATION_PRICE'=>'0'];
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $this->wallet=new Wallet($this->db);
        $this->trials=new TrialService($this->db,$this->outbox,$this->wallet,$config);
        $this->uid=(new Auth($this->db))->register('user@example.org','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,is_trial_available,trial_duration_days) VALUES('basic','Basic',19900,'RUB',30,0,3,1,1,3)");
    }
    public function testTrialAvailableForNewUser():void
    {
        self::assertTrue($this->trials->available($this->uid));
    }
    public function testStartTrialCreatesSubscription():void
    {
        $sub=$this->trials->start($this->uid,'basic');
        self::assertSame('active',$sub['status']);
        self::assertSame(1,(int)$sub['is_trial']);
        self::assertSame(3,(int)round(((int)$sub['expires_at']-time())/86400));
        self::assertCount(1,$this->db->all("SELECT * FROM outbox WHERE topic='subscription.provision'"));
        self::assertFalse($this->trials->available($this->uid));
    }
    public function testTrialUnavailableAfterPaidSubscription():void
    {
        $this->db->execute('UPDATE users SET has_had_paid_subscription=1 WHERE id=?',[$this->uid]);
        self::assertFalse($this->trials->available($this->uid));
        $this->expectException(\App\Billing\BillingError::class);
        $this->trials->start($this->uid,'basic');
    }
    public function testTrialUnavailableOnNonTrialPlan():void
    {
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,is_trial_available) VALUES('paid','Paid',29900,'RUB',30,0,3,1,0)");
        $this->expectException(\App\Billing\BillingError::class);
        $this->trials->start($this->uid,'paid');
    }
    public function testConvertTrialToPaid():void
    {
        $sub=$this->trials->start($this->uid,'basic');
        $this->wallet->credit($this->uid,50000,'balance_topup','Пополнение');
        $converted=$this->trials->convertToPaid($this->uid,$sub['id'],'basic',19900);
        self::assertSame(0,(int)$converted['is_trial']);
        self::assertSame('active',$converted['status']);
        self::assertSame(30100,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertSame(1,(int)$this->db->one('SELECT has_had_paid_subscription FROM users')['has_had_paid_subscription']);
        self::assertCount(1,$this->db->all('SELECT * FROM subscription_conversions'));
    }
    public function testConvertFailsWithoutFunds():void
    {
        $sub=$this->trials->start($this->uid,'basic');
        $this->expectException(\App\Billing\BillingError::class);
        $this->trials->convertToPaid($this->uid,$sub['id'],'basic',19900);
    }
    public function testExpireOverdueTrials():void
    {
        $sub=$this->trials->start($this->uid,'basic');
        $this->db->execute('UPDATE subscriptions SET expires_at=? WHERE id=?',[time()-3600,$sub['id']]);
        $count=$this->trials->expireOverdue();
        self::assertSame(1,$count);
        self::assertSame('expired',$this->db->one('SELECT status FROM subscriptions')['status']);
    }
    public function testPaidTrialActivationChargesBalance():void
    {
        $trials=new TrialService($this->db,$this->outbox,$this->wallet,['TRIAL_DURATION_DAYS'=>'3','TRIAL_ADD_REMAINING_DAYS_TO_PAID'=>'0','TRIAL_PAYMENT_ENABLED'=>'1','TRIAL_ACTIVATION_PRICE'=>'5000']);
        $this->db->execute('UPDATE plans SET trial_price_kopeks=5000 WHERE id=?',['basic']);
        $this->wallet->credit($this->uid,50000,'balance_topup','Пополнение');
        $sub=$trials->start($this->uid,'basic');
        self::assertSame('active',$sub['status']);
        self::assertSame(45000,$this->wallet->balance($this->uid)['balance_kopeks']);
    }
}
