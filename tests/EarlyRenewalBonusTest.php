<?php
declare(strict_types=1);
namespace Tests;

use App\Billing\BillingService;
use App\Identity\Auth;
use App\Infrastructure\{Database,Outbox};
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class EarlyRenewalBonusTest extends TestCase
{
    private Database $db;
    private BillingService $billing;
    private string $userId;
    private string $subscriptionId;

    protected function setUp(): void
    {
        $this->db=new Database('sqlite::memory:');
        $this->db->migrate(__DIR__.'/../migrations');
        $this->userId=(new Auth($this->db))->register('early-renewal@example.test','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
        $config=array_merge(Settings::DEFAULTS,['PURCHASES_ENABLED'=>'1','APP_URL'=>'https://cabinet.example']);
        $this->billing=new BillingService($this->db,new Outbox($this->db),'demo',$config);

        $initial=$this->billing->order($this->userId,'basic','initial-subscription-order');
        $this->billing->settle($initial['id'],'demo','initial-subscription-payment',19900,'RUB');
        $subscription=$this->db->one('SELECT * FROM subscriptions WHERE order_id=?',[$initial['id']]);
        $this->subscriptionId=$subscription['id'];
        $this->db->execute("UPDATE subscriptions SET status='active',lifecycle_status='active',expires_at=? WHERE id=?",[time()+30*86400,$this->subscriptionId]);
    }

    private function setting(string $name,string $value): void
    {
        $this->db->execute('INSERT INTO app_settings(name,value,updated_at) VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at',[$name,$value,time()]);
    }

    public function testBonusIsSnapshottedWithinConfiguredWindowAndAddedOnceAfterPayment(): void
    {
        $this->setting('EARLY_RENEWAL_BONUS_ENABLED','1');
        $this->setting('EARLY_RENEWAL_WINDOW_DAYS','7');
        $this->setting('EARLY_RENEWAL_BONUS_DAYS','5');
        $oldExpiry=time()+7*86400;
        $this->db->execute('UPDATE subscriptions SET expires_at=? WHERE id=?',[$oldExpiry,$this->subscriptionId]);

        $renewal=$this->billing->order($this->userId,'basic','renewal-inside-window',null,null,$this->subscriptionId);
        self::assertSame(5,(int)$renewal['early_renewal_bonus_days']);

        // A settings change after checkout cannot change the terms of this order.
        $this->setting('EARLY_RENEWAL_BONUS_ENABLED','0');
        $this->billing->settle($renewal['id'],'demo','early-renewal-payment',19900,'RUB');
        $expected=$oldExpiry+30*86400+5*86400;
        self::assertSame($expected,(int)$this->db->one('SELECT expires_at FROM subscriptions WHERE id=?',[$this->subscriptionId])['expires_at']);

        $this->billing->settle($renewal['id'],'demo','early-renewal-payment',19900,'RUB');
        self::assertSame($expected,(int)$this->db->one('SELECT expires_at FROM subscriptions WHERE id=?',[$this->subscriptionId])['expires_at']);
    }

    public function testBonusIsNotCapturedWhenDisabledOrOutsideWindow(): void
    {
        $this->setting('EARLY_RENEWAL_BONUS_ENABLED','0');
        $disabled=$this->billing->order($this->userId,'basic','renewal-disabled',null,null,$this->subscriptionId);
        self::assertSame(0,(int)$disabled['early_renewal_bonus_days']);

        // Clear the in-flight renewal marker as a separate checkout would do after cancellation.
        $this->db->execute('UPDATE subscriptions SET renew_order_id=NULL,expires_at=? WHERE id=?',[time()+8*86400,$this->subscriptionId]);
        $this->setting('EARLY_RENEWAL_BONUS_ENABLED','1');
        $this->setting('EARLY_RENEWAL_WINDOW_DAYS','7');
        $outside=$this->billing->order($this->userId,'basic','renewal-outside-window',null,null,$this->subscriptionId);
        self::assertSame(0,(int)$outside['early_renewal_bonus_days']);
    }
}
