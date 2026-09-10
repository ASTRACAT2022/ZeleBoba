<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,BillingError,Wallet,TopupService,CartService,AutoPurchaseService};
use App\Identity\Auth;
final class WalletTest extends TestCase
{
    private Database $db; private Outbox $outbox; private Wallet $wallet; private TopupService $topups; private CartService $carts; private AutoPurchaseService $auto; private BillingService $billing; private string $uid;
    protected function setUp():void
    {
        $this->db=new Database('sqlite::memory:');$this->db->migrate(__DIR__.'/../migrations');
        $this->outbox=new Outbox($this->db);
        $this->wallet=new Wallet($this->db);
        $this->billing=new BillingService($this->db,$this->outbox,'demo');
        $this->topups=new TopupService($this->db,$this->outbox,$this->wallet,'demo');
        $this->billing->setTopups($this->topups);
        $this->carts=new CartService($this->db);
        $this->auto=new AutoPurchaseService($this->db,$this->outbox,$this->wallet,$this->carts,$this->billing);
        $this->uid=(new Auth($this->db))->register('user@example.org','correct-horse-battery');
        $this->db->execute("INSERT INTO plans(id,name,price_minor,currency,duration_days,traffic_bytes,devices,active) VALUES('basic','Basic',19900,'RUB',30,0,3,1)");
    }
    public function testCreditDebitAndHistory():void
    {
        $this->wallet->credit($this->uid,50000,'balance_topup','Пополнение');
        self::assertSame(50000,$this->wallet->balance($this->uid)['balance_kopeks']);
        $this->wallet->debit($this->uid,19900,'subscription_purchase','Покупка');
        self::assertSame(30100,$this->wallet->balance($this->uid)['balance_kopeks']);
        $history=$this->wallet->history($this->uid);
        self::assertCount(2,$history);
        self::assertSame(-19900,(int)$history[0]['amount_kopeks']);
        self::assertSame(50000,(int)$history[1]['amount_kopeks']);
    }
    public function testDebitFailsWithoutFunds():void
    {
        $this->expectException(BillingError::class);
        $this->wallet->debit($this->uid,19900,'subscription_purchase','Покупка');
    }
    public function testTopupSettleCreditsExactlyOnce():void
    {
        $t=$this->topups->create($this->uid,50000,'topup-key-1');
        self::assertSame('pending',$t['status']);
        $this->topups->settle($t['id'],'demo','demo_'.$t['id'],50000,'RUB');
        $this->topups->settle($t['id'],'demo','demo_'.$t['id'],50000,'RUB');
        self::assertSame(50000,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertSame('paid',$this->db->one('SELECT status FROM topups')['status']);
        self::assertCount(1,$this->db->all('SELECT * FROM transactions'));
        self::assertSame(1,(int)$this->db->one('SELECT has_made_first_topup FROM users')['has_made_first_topup']);
    }
    public function testTopupWrongAmountRejected():void
    {
        $t=$this->topups->create($this->uid,50000,'topup-key-2');
        $this->expectException(BillingError::class);
        $this->topups->settle($t['id'],'demo','demo_x',100,'RUB');
    }
    public function testTopupIdempotencyKeyReuse():void
    {
        $a=$this->topups->create($this->uid,50000,'topup-key-3');
        $b=$this->topups->create($this->uid,50000,'topup-key-3');
        self::assertSame($a['id'],$b['id']);
        $this->expectException(BillingError::class);
        $this->topups->create($this->uid,60000,'topup-key-3');
    }
    public function testAutoPurchaseAfterTopup():void
    {
        $this->carts->save($this->uid,['kind'=>'subscription','plan_id'=>'basic','idempotency_key'=>'cart-key-1'],true);
        $t=$this->topups->create($this->uid,50000,'topup-key-4');
        $this->topups->settle($t['id'],'demo','demo_'.$t['id'],50000,'RUB');
        $this->auto->afterTopup($this->uid);
        self::assertSame(30100,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertCount(1,$this->db->all('SELECT * FROM orders'));
        self::assertSame('paid',$this->db->one('SELECT status FROM orders')['status']);
        self::assertNull($this->carts->get($this->uid));
    }
    public function testAutoPurchaseSkipsWithoutIntent():void
    {
        $this->carts->save($this->uid,['kind'=>'subscription','plan_id'=>'basic','idempotency_key'=>'cart-key-2'],false);
        $t=$this->topups->create($this->uid,50000,'topup-key-5');
        $this->topups->settle($t['id'],'demo','demo_'.$t['id'],50000,'RUB');
        $this->auto->afterTopup($this->uid);
        self::assertCount(0,$this->db->all('SELECT * FROM orders'));
        self::assertSame(50000,$this->wallet->balance($this->uid)['balance_kopeks']);
    }
    public function testAutoPurchaseKeepsCartOnFailure():void
    {
        $this->carts->save($this->uid,['kind'=>'subscription','plan_id'=>'missing','idempotency_key'=>'cart-key-3'],true);
        $t=$this->topups->create($this->uid,50000,'topup-key-6');
        $this->topups->settle($t['id'],'demo','demo_'.$t['id'],50000,'RUB');
        $this->auto->afterTopup($this->uid);
        self::assertSame(50000,$this->wallet->balance($this->uid)['balance_kopeks']);
        $cart=$this->carts->get($this->uid);
        self::assertNotNull($cart);
        self::assertTrue($cart['_intent']);
    }
    public function testSettleFromBalanceCreatesSubscription():void
    {
        $this->wallet->credit($this->uid,50000,'balance_topup','Пополнение');
        $order=$this->billing->order($this->uid,'basic','balance-key-1');
        $this->wallet->debit($this->uid,19900,'subscription_purchase','Покупка подписки: Basic');
        $this->billing->settleFromBalance($order['id'],19900,'RUB');
        self::assertSame('paid',$this->db->one('SELECT status FROM orders')['status']);
        self::assertCount(1,$this->db->all('SELECT * FROM subscriptions'));
        self::assertSame(30100,$this->wallet->balance($this->uid)['balance_kopeks']);
        self::assertCount(3,$this->db->all('SELECT * FROM transactions'));
    }
    public function testTopupWorkerCreatesCheckout():void
    {
        $t=$this->topups->create($this->uid,50000,'topup-key-7');
        $http=new \Symfony\Component\HttpClient\MockHttpClient();
        $payments=new \App\Integration\Payments($this->db,$this->billing,$http,[]);
        $worker=new \App\Infrastructure\Worker($this->db,$this->outbox,$payments,new \App\Integration\DemoProvisioner(),$http,'',true,'https://astracattg.netlify.app',$this->topups,$this->auto);
        $worker->handle('topup.create',['topup_id'=>$t['id']]);
        $row=$this->db->one('SELECT * FROM topups WHERE id=?',[$t['id']]);
        self::assertSame('demo_'.$t['id'],$row['provider_payment_id']);
        self::assertNotNull($row['checkout_url']);
    }
}
