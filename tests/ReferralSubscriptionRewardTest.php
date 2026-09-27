<?php
declare(strict_types=1);
namespace Tests;

use App\Billing\{BillingService,ReferralService,Wallet};
use App\Identity\Auth;
use App\Infrastructure\{Database,Outbox};
use App\Settings\Settings;
use App\Subscriptions\SubscriptionService;
use PHPUnit\Framework\TestCase;

final class ReferralSubscriptionRewardTest extends TestCase
{
    private Database $db;
    private Outbox $outbox;
    private BillingService $billing;
    private ReferralService $referrals;
    private string $referrerId;

    protected function setUp(): void
    {
        $this->db=new Database('sqlite::memory:');
        $this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $auth=new Auth($this->db);
        $this->referrerId=$auth->register('inviter@example.test','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,duration_months,traffic_bytes,devices,active) VALUES('monthly','Monthly',19900,'RUB',30,1,0,3,1)");
        $config=array_merge(Settings::DEFAULTS,['PURCHASES_ENABLED'=>'1','PAYMENT_DRIVER'=>'demo','REFERRAL_PROGRAM_ENABLED'=>'1','PROVISION_DRIVER'=>'demo','APP_URL'=>'https://cabinet.example']);
        $this->billing=new BillingService($this->db,$this->outbox,'demo',$config);
        $this->referrals=new ReferralService($this->db,$this->outbox,new Wallet($this->db),$config);
        $this->billing->setReferrals($this->referrals);
    }

    private function invitee(int $n): string
    {
        $id=(new Auth($this->db))->register('invitee'.$n.'@example.test','correct-horse-battery');
        $this->referrals->attachReferrer($id,$this->referrals->ensureCode($this->referrerId));
        return $id;
    }

    private function paySubscription(string $userId,string $key): array
    {
        $order=$this->billing->order($userId,'monthly',$key);
        $this->billing->settle($order['id'],'demo','payment-'.$key,19900,'RUB');
        return $order;
    }

    public function testEveryFiveUniquePaidInviteesGrantAnotherMonthAndRetriesAreHarmless(): void
    {
        $ownOrder=$this->paySubscription($this->referrerId,'inviter-monthly-order');
        $ownSub=$this->db->one('SELECT * FROM subscriptions WHERE order_id=?',[$ownOrder['id']]);
        $expiry=time()+45*86400;
        $this->db->execute("UPDATE subscriptions SET status='active',lifecycle_status='active',expires_at=? WHERE id=?",[$expiry,$ownSub['id']]);

        for($n=1;$n<=15;$n++) {
            $this->paySubscription($this->invitee($n),'invitee-order-'.$n.'-monthly');
            if($n%5===0) {
                $this->billing->settle($this->db->one("SELECT id FROM orders WHERE idempotency_key=?",['invitee-order-'.$n.'-monthly'])['id'],'demo','payment-invitee-order-'.$n.'-monthly',19900,'RUB');
                self::assertSame(intdiv($n,5),(int)$this->db->one('SELECT COUNT(*) AS c FROM referral_subscription_rewards WHERE referrer_id=?',[$this->referrerId])['c']);
            }
        }

        $expected=$expiry;
        $calendar=new SubscriptionService($this->db,$this->outbox);
        for($i=0;$i<3;$i++) $expected=$calendar->expiryAfter($expected,0,1);
        self::assertSame($expected,(int)$this->db->one('SELECT expires_at FROM subscriptions WHERE id=?',[$ownSub['id']])['expires_at']);
        self::assertSame(15,(int)$this->db->one('SELECT COUNT(*) AS c FROM referral_subscription_payments WHERE referrer_id=?',[$this->referrerId])['c']);
        self::assertSame(3,$this->referrals->stats($this->referrerId)['free_months_earned']);
        self::assertSame(0,$this->referrals->stats($this->referrerId)['subscription_paid_progress']);
        self::assertSame(3,(int)$this->db->one("SELECT COUNT(*) AS c FROM outbox WHERE topic='subscription.extend' AND dedup_key LIKE 'referral-month:%'")['c']);
    }

    public function testAReferrerWithoutAnActiveSubscriptionReceivesAProvisionedMonth(): void
    {
        for($n=1;$n<=5;$n++) $this->paySubscription($this->invitee($n),'new-invitee-order-'.$n);

        $reward=$this->db->one('SELECT * FROM referral_subscription_rewards WHERE referrer_id=?',[$this->referrerId]);
        self::assertNotNull($reward);
        $subscription=$this->db->one('SELECT * FROM subscriptions WHERE id=?',[$reward['subscription_id']]);
        self::assertSame($this->referrerId,$subscription['user_id']);
        self::assertNull($subscription['order_id']);
        self::assertSame('provisioning',$subscription['status']);
        self::assertSame('monthly',$subscription['plan_id']);
        self::assertNotNull($this->db->one('SELECT id FROM provisioning_accounts WHERE subscription_id=?',[$subscription['id']]));
        self::assertNotNull($this->db->one("SELECT id FROM outbox WHERE topic='subscription.provision' AND dedup_key LIKE 'referral-month:%'"));
    }

    public function testRenewalsAndRepeatedOrdersFromOneInviteeDoNotIncreaseTheMilestone(): void
    {
        $invitee=$this->invitee(1);
        $first=$this->paySubscription($invitee,'single-invitee-first-order');
        $sub=$this->db->one('SELECT * FROM subscriptions WHERE order_id=?',[$first['id']]);
        $this->db->execute("UPDATE subscriptions SET status='active',expires_at=? WHERE id=?",[time()+86400,$sub['id']]);

        $renewal=$this->billing->order($invitee,'monthly','single-invitee-renewal-order',null,null,$sub['id']);
        $this->billing->settle($renewal['id'],'demo','payment-single-invitee-renewal-order',19900,'RUB');
        $secondPurchase=$this->paySubscription($invitee,'single-invitee-second-order');

        self::assertCount(1,$this->db->all('SELECT * FROM referral_subscription_payments WHERE referrer_id=?',[$this->referrerId]));
        self::assertSame(1,$this->referrals->stats($this->referrerId)['subscription_paid_referrals']);
        self::assertSame(0,$this->referrals->stats($this->referrerId)['free_months_earned']);
        self::assertSame('monthly',$secondPurchase['plan_id']);
    }

    public function testDisabledReferralProgramDoesNotCountOrGrantMonths(): void
    {
        $this->db->execute("INSERT INTO app_settings(name,value,updated_at) VALUES('REFERRAL_PROGRAM_ENABLED','0',?)",[time()]);
        for($n=1;$n<=5;$n++) $this->paySubscription($this->invitee($n),'disabled-invitee-order-'.$n);

        self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) AS c FROM referral_subscription_payments')['c']);
        self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) AS c FROM referral_subscription_rewards')['c']);
    }

    public function testAdminCanDisableOnlyTheSubscriptionReward(): void
    {
        $this->db->execute("INSERT INTO app_settings(name,value,updated_at) VALUES('REFERRAL_SUBSCRIPTION_REWARD_ENABLED','0',?)",[time()]);
        for($n=1;$n<=5;$n++) $this->paySubscription($this->invitee($n),'reward-disabled-invitee-order-'.$n);

        self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) AS c FROM referral_subscription_payments')['c']);
        self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) AS c FROM referral_subscription_rewards')['c']);
    }

    public function testAdminCanConfigureInviteThresholdAndRewardMonths(): void
    {
        $this->db->execute("INSERT INTO app_settings(name,value,updated_at) VALUES('REFERRAL_SUBSCRIPTION_REWARD_INVITES','3',?)",[time()]);
        $this->db->execute("INSERT INTO app_settings(name,value,updated_at) VALUES('REFERRAL_SUBSCRIPTION_REWARD_MONTHS','2',?)",[time()]);
        for($n=1;$n<=3;$n++) $this->paySubscription($this->invitee($n),'configured-invitee-order-'.$n);

        $reward=$this->db->one('SELECT * FROM referral_subscription_rewards WHERE referrer_id=?',[$this->referrerId]);
        self::assertNotNull($reward);
        self::assertSame(3,(int)$reward['paid_count_at_award']);
        self::assertSame(2,(int)$reward['months_awarded']);
        self::assertSame(2,$this->referrals->stats($this->referrerId)['free_months_earned']);
    }

    public function testChangedConditionsApplyStartingWithTheNextCycle(): void
    {
        for($n=1;$n<=2;$n++) $this->paySubscription($this->invitee($n),'snapshot-before-change-'.$n);
        $this->db->execute("INSERT INTO app_settings(name,value,updated_at) VALUES('REFERRAL_SUBSCRIPTION_REWARD_INVITES','2',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at",[time()]);
        $this->db->execute("INSERT INTO app_settings(name,value,updated_at) VALUES('REFERRAL_SUBSCRIPTION_REWARD_MONTHS','3',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at",[time()]);

        for($n=3;$n<=5;$n++) $this->paySubscription($this->invitee($n),'snapshot-old-cycle-'.$n);
        $first=$this->db->one('SELECT * FROM referral_subscription_rewards WHERE referrer_id=? AND milestone=1',[$this->referrerId]);
        self::assertSame(1,(int)$first['months_awarded']);
        self::assertSame(0,$this->referrals->stats($this->referrerId)['subscription_paid_progress']);

        for($n=6;$n<=7;$n++) $this->paySubscription($this->invitee($n),'snapshot-new-cycle-'.$n);
        $second=$this->db->one('SELECT * FROM referral_subscription_rewards WHERE referrer_id=? AND milestone=2',[$this->referrerId]);
        self::assertSame(3,(int)$second['months_awarded']);
        self::assertSame(4,$this->referrals->stats($this->referrerId)['free_months_earned']);
    }
}
