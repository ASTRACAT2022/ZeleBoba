<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Integration\{Payments,Provisioner,PaymentService};
use App\Billing\TopupService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
final class Worker
{
    public function __construct(private Database $db, private Outbox $outbox, private Payments $payments, private Provisioner $provisioner, private HttpClientInterface $http, private string $botToken, private bool $allowDemo=true, private string $telegramApiBase='https://astracattg.netlify.app', private ?TopupService $topups=null, private ?\App\Billing\AutoPurchaseService $autoPurchase=null, private ?PaymentService $paymentService=null, private ?\App\Billing\ReferralService $referrals=null, private ?\App\Billing\BroadcastService $broadcasts=null, private ?\App\Billing\CompensationService $compensations=null) {}
    public function handle(string $topic,array $payload): void
    {
        match ($topic) {
            'payment.create'=>$this->paymentService?$this->paymentService->createOrder($payload['order_id']):$this->payments->create($payload['order_id']),
            'payment.verify'=>$this->paymentService?$this->paymentService->verify($payload['payment_id']):$this->payments->refresh($payload['payment_id']),
            'topup.create'=>$this->topupCreate($payload['topup_id']),
            'topup.after'=>$this->topupAfter($payload['user_id']),
            'referral.topup'=>$this->referralTopup($payload['user_id'],(int)($payload['amount_kopeks']??0)),
            'subscription.provision'=>$this->provision($payload['subscription_id']),
            'subscription.extend'=>$this->extend($payload['subscription_id']),
            'subscription.renew'=>$this->renew($payload['subscription_id']),
            'subscription.traffic'=>$this->traffic($payload['subscription_id'],(int)($payload['traffic_gb']??0)),
            'subscription.devices'=>$this->devices($payload['subscription_id'],(int)($payload['devices']??0)),
            'gift.create'=>$this->giftCreate($payload),
            'broadcast.run'=>$this->broadcastRun($payload['broadcast_id']),
            'broadcast.send'=>$this->broadcastSend($payload['broadcast_id'],$payload['chat_id'],$payload['text']),
            'compensation.run'=>$this->compensationRun($payload['compensation_id']),
            'compensation.grant'=>$this->compensationGrant($payload['compensation_id'],$payload['user_id']),
            'telegram.send'=>$this->send($payload),
            'telegram.answer'=>$this->answer($payload),
            default=>throw new \RuntimeException('Unknown outbox topic')
        };
    }
    private function broadcastRun(string $id): void
    {
        if ($this->broadcasts) $this->broadcasts->run($id);
    }
    private function compensationRun(string $id): void
    {
        if ($this->compensations) $this->compensations->run($id);
    }
    private function compensationGrant(string $id, string $userId): void
    {
        if ($this->compensations) $this->compensations->grant($id, $userId);
    }
    private function broadcastSend(string $id, string $chatId, string $text): void
    {
        try {
            $this->send(['chat_id' => $chatId, 'text' => $text]);
            if ($this->broadcasts) $this->broadcasts->markSent($id, true);
        } catch (\Throwable) {
            if ($this->broadcasts) $this->broadcasts->markSent($id, false);
            throw new \RuntimeException('Broadcast send failed');
        }
    }
    private function topupCreate(string $id): void
    {
        $topup=$this->db->one('SELECT * FROM topups WHERE id=?',[$id]);
        if (!$topup || $topup['status']!=='pending' || $topup['checkout_url']) return;
        if ($this->paymentService) { $this->paymentService->createTopup($id); return; }
        $this->payments->createTopup($topup);
    }
    private function topupAfter(string $userId): void
    {
        if ($this->autoPurchase) $this->autoPurchase->afterTopup($userId);
    }
    private function referralTopup(string $userId, int $amountKopeks): void
    {
        if ($this->referrals) $this->referrals->processTopup($userId, $amountKopeks);
    }
    private function traffic(string $id,int $gb): void
    {
        $s=$this->db->one('SELECT s.*,o.traffic_bytes,o.provision_driver,o.squad_uuid FROM subscriptions s JOIN orders o ON o.id=s.order_id WHERE s.id=?',[$id]);
        if (!$s || $s['status']!=='active' || $s['provision_driver']==='demo') return;
        $this->provisioner->setTraffic($s,$gb);
    }
    private function devices(string $id,int $count): void
    {
        $s=$this->db->one('SELECT s.*,o.traffic_bytes,o.provision_driver,o.squad_uuid FROM subscriptions s JOIN orders o ON o.id=s.order_id WHERE s.id=?',[$id]);
        if (!$s || $s['status']!=='active' || $s['provision_driver']==='demo') return;
        $this->provisioner->setDevices($s,$count);
    }
    private function giftCreate(array $payload): void
    {
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',[\App\Infrastructure\Database::id(),$payload['user_id'],'gift.created',$payload['plan_id'],time()]);
    }
    private function provision(string $id): void
    {
        $s=$this->db->one('SELECT s.*,o.traffic_bytes,o.devices,o.provision_driver,o.squad_uuid FROM subscriptions s JOIN orders o ON o.id=s.order_id WHERE s.id=?',[$id]);
        if (!$s || $s['status']!=='provisioning') return;
        if($s['provision_driver']==='demo' && !$this->allowDemo) throw new \RuntimeException('Demo provisioning forbidden');
        $remote=$s['provision_driver']==='demo'?(new \App\Integration\DemoProvisioner())->provision($s):$this->provisioner->provision($s);
        $this->db->transaction(function () use ($s,$remote,$id) {
            $changed=$this->db->execute("UPDATE subscriptions SET status='active',remote_id=?,subscription_url=? WHERE id=? AND status='provisioning'",[$remote['id'],$remote['url'],$id]);
            if (!$changed) return;
            $this->db->execute("UPDATE orders SET status='fulfilled' WHERE id=?",[$s['order_id']]);
            $user=$this->db->one('SELECT telegram_id FROM users WHERE id=?',[$s['user_id']]);
            if ($user['telegram_id']) $this->outbox->enqueue('telegram.send','activated:'.$id,['chat_id'=>$user['telegram_id'],'text'=>'Подписка готова. Откройте веб-кабинет или отправьте /status.']);
        });
    }
    private function extend(string $id): void
    {
        $s=$this->db->one('SELECT s.*,o.traffic_bytes,o.devices,o.provision_driver,o.squad_uuid FROM subscriptions s JOIN orders o ON o.id=s.order_id WHERE s.id=?',[$id]);
        if (!$s || $s['status']!=='active') return;
        if($s['provision_driver']==='demo'){
            // Demo: nothing to extend remotely
            return;
        }
        $this->provisioner->extend($s);
        $user=$this->db->one('SELECT telegram_id FROM users WHERE id=?',[$s['user_id']]);
        if ($user['telegram_id']) $this->outbox->enqueue('telegram.send','renewed:'.$id,['chat_id'=>$user['telegram_id'],'text'=>'Подписка продлена до '.gmdate('d.m.Y H:i',(int)$s['expires_at']).' UTC.']);
    }
    private function renew(string $id): void
    {
        $s=$this->db->one('SELECT * FROM subscriptions WHERE id=?',[$id]);
        if (!$s || (int)$s['auto_renew']!==1 || $s['status']!=='active' || (int)$s['expires_at']<=time()) return;
        if ($s['renew_order_id']) return;
        // Check global autorenew toggle
        $enabled=$this->db->one("SELECT value FROM app_settings WHERE name='AUTORENEW_ENABLED'");
        if ($enabled && $enabled['value']==='0') return;
        // Check max fails
        $maxFails=(int)($this->db->one("SELECT value FROM app_settings WHERE name='AUTORENEW_MAX_FAILS'")['value']??3);
        if ((int)$s['renew_fail_count']>=$maxFails) return;
        $planId=$s['renew_plan_id']??$this->db->one('SELECT plan_id FROM orders WHERE id=?',[$s['order_id']])['plan_id']??null;
        if (!$planId) return;
        $plan=$this->db->one('SELECT * FROM plans WHERE id=? AND active=1',[$planId]);
        if (!$plan) return;
        $user=$this->db->one('SELECT * FROM users WHERE id=?',[$s['user_id']]);
        if (!$user || (int)$user['disabled']===1) return;
        $email=$user['email']?:($user['telegram_id']?$user['telegram_id'].'@telegram.org':null);
        if (!$email || !filter_var($email,FILTER_VALIDATE_EMAIL)) return;
        $orderId=\App\Infrastructure\Database::id();
        $key='renew:'.$id.':'.(int)$s['expires_at'];
        // Idempotency: if renewal order already exists for this expiry, skip
        if ($this->db->one('SELECT id FROM orders WHERE idempotency_key=?',[$key])) return;
        $provider=$this->db->one('SELECT provider FROM orders WHERE id=?',[$s['order_id']])['provider']??'demo';
        $provisionDriver=$this->db->one('SELECT provision_driver FROM orders WHERE id=?',[$s['order_id']])['provision_driver']??'demo';
        $squadUuid=$this->db->one('SELECT squad_uuid FROM orders WHERE id=?',[$s['order_id']])['squad_uuid']??'';
        $providerAccount=$this->db->one('SELECT provider_account FROM orders WHERE id=?',[$s['order_id']])['provider_account']??'';
        // Respect global purchases pause
        $purchases=$this->db->one("SELECT value FROM app_settings WHERE name='PURCHASES_ENABLED'");
        if ($purchases && $purchases['value']!=='1') return;
        $this->db->transaction(function() use ($s,$plan,$user,$email,$orderId,$key,$provider,$provisionDriver,$squadUuid,$providerAccount,$id) {
            $this->db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,'pending',?,?)",[$orderId,$s['user_id'],$plan['id'],$key,$plan['price_minor'],$plan['currency'],$plan['name'],$plan['duration_days'],$plan['traffic_bytes'],$plan['devices'],$provider,time()]);
            $this->db->execute('UPDATE orders SET provision_driver=?,squad_uuid=?,provider_account=?,receipt_email=?,client_ip=? WHERE id=?',[$provisionDriver,$squadUuid,$providerAccount,$email,'8.8.8.8',$orderId]);
            $this->db->execute('UPDATE orders SET return_url=? WHERE id=?',['https://cabinet.example/orders/'.$orderId,$orderId]);
            $this->db->execute('UPDATE subscriptions SET renew_order_id=?,renew_at=NULL WHERE id=?',[$orderId,$id]);
            $this->outbox->enqueue('payment.create','checkout:'.$orderId,['order_id'=>$orderId]);
        });
    }
    private function send(array $payload): void
    {
        if (!$this->botToken) throw new \RuntimeException('Telegram is not configured');
        $result=$this->http->request('POST',rtrim($this->telegramApiBase,'/').'/bot'.$this->botToken.'/sendMessage',['json'=>$payload,'timeout'=>10,'max_duration'=>20,'max_redirects'=>0])->toArray();
        if (!($result['ok']??false)) throw new \RuntimeException('Telegram rejected message');
    }
    private function answer(array $payload): void
    {
        if (!$this->botToken) throw new \RuntimeException('Telegram is not configured');
        $result=$this->http->request('POST',rtrim($this->telegramApiBase,'/').'/bot'.$this->botToken.'/answerCallbackQuery',['json'=>$payload,'timeout'=>10,'max_duration'=>20,'max_redirects'=>0])->toArray();
        if (!($result['ok']??false)) throw new \RuntimeException('Telegram rejected callback answer');
    }
}
