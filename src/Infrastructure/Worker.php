<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Integration\{Payments,Provisioner};
use Symfony\Contracts\HttpClient\HttpClientInterface;
final class Worker
{
    public function __construct(private Database $db, private Outbox $outbox, private Payments $payments, private Provisioner $provisioner, private HttpClientInterface $http, private string $botToken, private bool $allowDemo=true, private string $telegramApiBase='https://astracattg.netlify.app') {}
    public function handle(string $topic,array $payload): void
    {
        match ($topic) {
            'payment.create'=>$this->payments->create($payload['order_id']),
            'payment.verify'=>$this->payments->refresh($payload['payment_id']),
            'subscription.provision'=>$this->provision($payload['subscription_id']),
            'telegram.send'=>$this->send($payload),
            'telegram.answer'=>$this->answer($payload),
            default=>throw new \RuntimeException('Unknown outbox topic')
        };
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
