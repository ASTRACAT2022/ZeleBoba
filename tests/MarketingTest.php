<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,Wallet,BroadcastService,ChannelService,LandingService,ContestService,PollService,CampaignService};
use App\Identity\Auth;
final class MarketingTest extends TestCase
{
    private Database $db; private Outbox $outbox; private Wallet $wallet; private string $uid;
    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $this->wallet=new Wallet($this->db);
        $this->uid=(new Auth($this->db))->register('user@example.org','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
    }
    public function testBroadcastCreateAndRun():void
    {
        $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',['12345',$this->uid]);
        $svc=new BroadcastService($this->db,$this->outbox);
        $b=$svc->create('all','Привет всем!',$this->uid,'admin');
        self::assertSame('in_progress',$b['status']);
        $svc->run($b['id']);
        $row=$this->db->one('SELECT * FROM broadcast_history WHERE id=?',[$b['id']]);
        self::assertSame('running',$row['status']);
        self::assertSame(1,(int)$row['total_count']);
        self::assertCount(1,$this->db->all("SELECT * FROM outbox WHERE topic='broadcast.send'"));
    }
    public function testBroadcastSegments():void
    {
        $svc=new BroadcastService($this->db,$this->outbox);
        $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',['12345',$this->uid]);
        $b=$svc->create('active','Только активные',$this->uid,'admin');
        $svc->run($b['id']);
        self::assertSame(0,(int)$this->db->one('SELECT total_count FROM broadcast_history')['total_count']);
        $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at) VALUES('s1',NULL,?,'active',?,?)",[$this->uid,time()+86400,time()]);
        $b2=$svc->create('active','Только активные 2',$this->uid,'admin');
        $svc->run($b2['id']);
        self::assertSame(1,(int)$this->db->one('SELECT total_count FROM broadcast_history WHERE id=?',[$b2['id']])['total_count']);
    }
    public function testChannelAddToggleRemove():void
    {
        $svc=new ChannelService($this->db,$this->outbox);
        $c=$svc->add('-1001234567890','https://t.me/channel','Мой канал',$this->uid);
        self::assertSame('-1001234567890',$c['channel_id']);
        $svc->toggle($c['id'],false,$this->uid);
        self::assertSame(0,(int)$this->db->one('SELECT is_active FROM required_channels')['is_active']);
        $svc->remove($c['id'],$this->uid);
        self::assertCount(0,$this->db->all('SELECT * FROM required_channels'));
    }
    public function testChannelMembershipCheck():void
    {
        $svc=new ChannelService($this->db,$this->outbox);
        $svc->add('-1001234567890','https://t.me/channel','Канал',$this->uid);
        $missing=$svc->missingChannels($this->uid);
        self::assertCount(1,$missing);
        $svc->updateMembership($this->uid,'-1001234567890',true);
        self::assertCount(0,$svc->missingChannels($this->uid));
    }
    public function testLandingSaveAndDiscount():void
    {
        $svc=new LandingService($this->db,$this->outbox);
        $l=$svc->save(['slug'=>'sale','title'=>'Летняя распродажа','discount_percent'=>'20','is_active'=>'1','subtitle'=>'','features'=>'','footer_text'=>'','allowed_plan_ids'=>'','payment_methods'=>'','gift_enabled'=>'1','custom_css'=>'','meta_title'=>'','meta_description'=>'','display_order'=>'0','discount_starts_at'=>'','discount_ends_at'=>''],$this->uid);
        self::assertSame('sale',$l['slug']);
        $plan=$this->db->one('SELECT * FROM plans WHERE id=?',['basic']);
        self::assertSame(15920,$svc->effectivePrice($l,$plan));
        self::assertNotNull($svc->get('sale'));
        self::assertNull($svc->get('nope'));
    }
    public function testContestAttemptAndAward():void
    {
        $svc=new ContestService($this->db,$this->outbox,$this->wallet);
        $t=$svc->createTemplate(['name'=>'Удача','slug'=>'luck','prize_type'=>'balance','prize_value'=>'1000','max_winners'=>'1','times_per_day'=>'1'],$this->uid);
        $r=$svc->startRound($t['id'],$this->uid);
        $result=$svc->attempt($this->uid,$r['id']);
        self::assertArrayHasKey('won',$result);
        $again=$svc->attempt($this->uid,$r['id']);
        self::assertTrue($again['already']);
        if($result['won']) self::assertSame(1000,$this->wallet->balance($this->uid)['balance_kopeks']);
    }
    public function testPollCreateAndSubmit():void
    {
        $svc=new PollService($this->db,$this->outbox,$this->wallet);
        $p=$svc->create(['title'=>'Как вам сервис?','reward_amount_kopeks'=>'5000','questions'=>[['text'=>'Оценка','options'=>['5','4','3']]]],$this->uid);
        $result=$svc->submit($this->uid,$p['id'],['5']);
        self::assertSame(5000,$result['reward']);
        self::assertSame(5000,$this->wallet->balance($this->uid)['balance_kopeks']);
        $this->expectException(\App\Billing\BillingError::class);
        $svc->submit($this->uid,$p['id'],['4']);
    }
    public function testCampaignRegisterGrantsBonus():void
    {
        $svc=new CampaignService($this->db,$this->outbox,$this->wallet);
        $c=$svc->create(['name'=>'Лето','start_parameter'=>'summer2026','bonus_type'=>'balance','balance_bonus_kopeks'=>'10000','subscription_duration_days'=>'','subscription_traffic_gb'=>'','subscription_device_limit'=>'','plan_id'=>'','partner_user_id'=>''],$this->uid);
        $granted=$svc->register($this->uid,'summer2026');
        self::assertNotNull($granted);
        self::assertSame(10000,$this->wallet->balance($this->uid)['balance_kopeks']);
        $svc->register($this->uid,'summer2026');
        self::assertSame(10000,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertNull($svc->register($this->uid,'unknown'));
    }
}
