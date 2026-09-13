<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,Wallet,TopupService,ReferralService};
use App\Identity\Auth;
final class ReferralTest extends TestCase
{
    private Database $db; private Outbox $outbox; private Wallet $wallet; private TopupService $topups; private ReferralService $referrals; private string $uid; private string $refUid;
    protected function setUp():void
    {
        $config=['REFERRAL_PROGRAM_ENABLED'=>'1','REFERRAL_MINIMUM_TOPUP_KOPEKS'=>'10000','REFERRAL_FIRST_TOPUP_BONUS_KOPEKS'=>'10000','REFERRAL_INVITER_BONUS_KOPEKS'=>'10000','REFERRAL_COMMISSION_PERCENT'=>'25','REFERRAL_FIRST_PAYMENT_COMMISSION_PERCENT'=>'','REFERRAL_RECURRING_COMMISSION_TIERS'=>'','REFERRAL_MAX_COMMISSION_PAYMENTS'=>'0','REFERRAL_WITHDRAWAL_ENABLED'=>'1','REFERRAL_WITHDRAWAL_MIN_AMOUNT_KOPEKS'=>'100000','REFERRAL_WITHDRAWAL_COOLDOWN_DAYS'=>'30','REFERRAL_WITHDRAWAL_SUSPICIOUS_MIN_DEPOSIT_KOPEKS'=>'50000','REFERRAL_WITHDRAWAL_SUSPICIOUS_MAX_DEPOSITS_PER_MONTH'=>'10'];
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $this->wallet=new Wallet($this->db);
        $this->topups=new TopupService($this->db,$this->outbox,$this->wallet,'demo');
        $this->referrals=new ReferralService($this->db,$this->outbox,$this->wallet,$config);
        $auth=new Auth($this->db);
        $this->uid=$auth->register('referrer@example.org','correct-horse-battery');
        $this->refUid=$auth->register('referee@example.org','correct-horse-battery');
    }
    public function testReferralCodeGenerated():void
    {
        $code=$this->referrals->ensureCode($this->uid);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/D',$code);
        self::assertSame($code,$this->referrals->ensureCode($this->uid));
    }
    public function testAttachReferrer():void
    {
        $code=$this->referrals->ensureCode($this->uid);
        $attached=$this->referrals->attachReferrer($this->refUid,$code);
        self::assertSame($this->uid,$attached);
        self::assertNull($this->referrals->attachReferrer($this->refUid,$code));
    }
    public function testSelfReferralBlocked():void
    {
        $code=$this->referrals->ensureCode($this->uid);
        self::assertNull($this->referrals->attachReferrer($this->uid,$code));
    }
    public function testFirstTopupAwardsBonuses():void
    {
        $code=$this->referrals->ensureCode($this->uid);
        $this->referrals->attachReferrer($this->refUid,$code);
        $this->referrals->processTopup($this->refUid,50000);
        // Referee gets first-topup bonus 10000
        self::assertSame(10000,$this->wallet->balance($this->refUid)['balance_kopeks']);
        // Referrer gets inviter bonus 10000 + 25% commission of 50000 = 12500 => 22500
        self::assertSame(22500,$this->wallet->balance($this->uid)['balance_kopeks']);
        $earnings=$this->db->one('SELECT * FROM referral_earnings');
        self::assertSame('referral_first_topup',$earnings['reason']);
        self::assertSame(22500,(int)$earnings['amount_kopeks']);
    }
    public function testRepeatTopupAwardsCommissionOnly():void
    {
        $code=$this->referrals->ensureCode($this->uid);
        $this->referrals->attachReferrer($this->refUid,$code);
        $this->referrals->processTopup($this->refUid,50000);
        $this->referrals->processTopup($this->refUid,20000);
        // Referrer: 22500 + 25% of 20000 = 5000 => 27500
        self::assertSame(27500,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertCount(2,$this->db->all('SELECT * FROM referral_earnings'));
    }
    public function testSmallTopupBeforeFirstBonusStillPaysCommission():void
    {
        $code=$this->referrals->ensureCode($this->uid);
        $this->referrals->attachReferrer($this->refUid,$code);
        $this->referrals->processTopup($this->refUid,5000);
        // Below minimum 10000: no first-topup bonus, but commission 25% of 5000 = 1250
        self::assertSame(0,$this->wallet->balance($this->refUid)['balance_kopeks']);
        self::assertSame(1250,$this->wallet->balance($this->uid)['balance_kopeks']);
    }
    public function testWithdrawalRequestAndProcessing():void
    {
        $code=$this->referrals->ensureCode($this->uid);
        $this->referrals->attachReferrer($this->refUid,$code);
        $this->referrals->processTopup($this->refUid,500000);
        $w=$this->referrals->requestWithdrawal($this->uid,100000,'4276 1234 5678 9012');
        self::assertSame('pending',$w['status']);
        $this->referrals->processWithdrawal($w['id'],'approved','ok',$this->uid);
        self::assertSame('approved',$this->db->one('SELECT status FROM withdrawal_requests')['status']);
    }
    public function testWithdrawalExceedsEarningsRejected():void
    {
        $code=$this->referrals->ensureCode($this->uid);
        $this->referrals->attachReferrer($this->refUid,$code);
        $this->referrals->processTopup($this->refUid,50000);
        $this->expectException(\App\Billing\BillingError::class);
        $this->referrals->requestWithdrawal($this->uid,500000,'4276 1234 5678 9012');
    }
    public function testWithdrawalBelowMinimumRejected():void
    {
        $this->expectException(\App\Billing\BillingError::class);
        $this->referrals->requestWithdrawal($this->uid,5000,'4276 1234 5678 9012');
    }
    public function testStats():void
    {
        $code=$this->referrals->ensureCode($this->uid);
        $this->referrals->attachReferrer($this->refUid,$code);
        $this->referrals->processTopup($this->refUid,50000);
        $stats=$this->referrals->stats($this->uid);
        self::assertSame($code,$stats['code']);
        self::assertCount(1,$stats['referrals']);
        self::assertSame(1,$stats['paid_referrals']);
        self::assertSame(22500,$stats['earnings_kopeks']);
    }
}
