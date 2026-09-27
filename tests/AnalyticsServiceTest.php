<?php
declare(strict_types=1);
namespace Tests;

use App\Billing\AnalyticsService;
use App\Identity\Auth;
use App\Infrastructure\Database;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsServiceTest extends TestCase
{
    private Database $db;
    private AnalyticsService $analytics;

    protected function setUp(): void
    {
        $this->db=new Database('sqlite::memory:');
        $this->db->migrate(__DIR__.'/../migrations');
        $this->analytics=new AnalyticsService($this->db);
    }

    public function testAttributionAndRegistrationAreAssociatedWithoutStoringRawRequestData(): void
    {
        $visit=$this->analytics->captureVisit(Request::create('/?utm_source=telegram&utm_medium=post&utm_campaign=launch_1&email=private@example.test'),'landing');
        $user=(new Auth($this->db))->register('analytics@example.test','a long and safe test password');
        $this->analytics->associateRegistration($user,$visit['anonymous_id'],$visit['session_id']);

        self::assertSame('telegram',$this->db->one('SELECT first_source FROM analytics_attribution WHERE user_id=?',[$user])['first_source']);
        self::assertSame(1,(int)$this->db->one("SELECT COUNT(*) n FROM analytics_events WHERE event_name='registered' AND user_id=?",[$user])['n']);
        self::assertStringNotContainsString('private@example.test',(string)$this->db->one('SELECT properties FROM analytics_events ORDER BY created_at DESC LIMIT 1')['properties']);
    }

    public function testSettledTopupEventIsIdempotentAndCashReceiptsExcludeInternalBalancePayments(): void
    {
        $user=(new Auth($this->db))->register('cash@example.test','a long and safe test password');
        $now=time();$topup=['id'=>'topup-analytics-test','user_id'=>$user,'provider'=>'platega','provider_payment_id'=>'platega-topup-1','amount_kopeks'=>12500,'currency'=>'RUB','paid_at'=>$now];
        $this->db->execute("INSERT INTO topups(id,user_id,amount_kopeks,currency,status,provider,provider_payment_id,idempotency_key,created_at,paid_at) VALUES(?,?,?,'RUB','paid','platega',?,?,?,?)",[$topup['id'],$user,$topup['amount_kopeks'],$topup['provider_payment_id'],'analytics-idempotency-key',$now,$now]);
        $this->analytics->trackSettledTopup($topup);
        $this->analytics->trackSettledTopup($topup);

        self::assertSame(1,(int)$this->db->one("SELECT COUNT(*) n FROM analytics_events WHERE event_key='topup_succeeded:topup-analytics-test'")['n']);
        self::assertSame(12500,$this->analytics->dashboard(7)['today_revenue']);
        self::assertSame(0,$this->analytics->dashboard(7)['revenue']);
    }

    public function testTrialConversionCountsUniquePeopleAndExcludesTheTrialFromNewRevenuePurpose(): void
    {
        $user=(new Auth($this->db))->register('trial@example.test','a long and safe test password');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active,duration_months) VALUES('trial-4','Проба',400,'RUB',1,0,1,1,0),('month-199','Месяц',19900,'RUB',30,0,1,1,1)");
        $billing=new \App\Billing\BillingService($this->db,new \App\Infrastructure\Outbox($this->db),'platega');
        $billing->setAnalytics($this->analytics);
        $trial=$billing->order($user,'trial-4','analytics-trial-key');
        $attempt=(new \App\Payments\PaymentAttemptStore($this->db,$this->analytics))->begin('order',$trial);
        $billing->settle($trial['id'],'platega','analytics-trial-payment',400,'RUB');
        $this->db->execute('UPDATE payments SET paid_at=? WHERE order_id=?',[time()-60,$trial['id']]);
        $purchase=$billing->order($user,'month-199','analytics-normal-key');
        $billing->settle($purchase['id'],'platega','analytics-normal-payment',19900,'RUB');

        self::assertSame('trial',$this->db->one('SELECT payment_purpose FROM payments WHERE order_id=?',[$trial['id']])['payment_purpose']);
        self::assertSame('first_purchase',$this->db->one('SELECT payment_purpose FROM payments WHERE order_id=?',[$purchase['id']])['payment_purpose']);
        self::assertSame('trial-4',$this->db->one('SELECT plan_id FROM payment_attempts WHERE id=?',[$attempt['id']])['plan_id']);
        $trialStats=$this->analytics->dashboard(7)['trial'];
        self::assertSame(1,$trialStats['buyers']);
        self::assertSame(1,$trialStats['converted'][1]);
        self::assertSame(1,$trialStats['converted_buyers']);
    }
}
