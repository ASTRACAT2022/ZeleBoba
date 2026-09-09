<?php
declare(strict_types=1);
namespace App\Integration;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,BillingError};
final class Telegram
{
    public function __construct(private Database $db,private Outbox $outbox,private BillingService $billing,private string $appUrl, private ?\App\Identity\TelegramLogin $login=null) {}
    public function receive(array $update): void
    {
        $id=$update['update_id']??null; $message=$update['message']??null;
        $callback=$update['callback_query']??null;
        if($callback && $this->login){
            if(!is_int($id) || ($callback['message']['chat']['type']??'')!=='private' || !is_int($callback['from']['id']??null) || $callback['from']['id']!==($callback['message']['chat']['id']??null))return;
            $data=$callback['data']??'';
            if(is_string($data) && str_starts_with($data,'login:')){
                $ok=$this->login->approve(substr($data,6),(string)$callback['from']['id']);
                $this->db->transaction(fn()=> $this->outbox->enqueue('telegram.send','login-confirm:'.$id,['chat_id'=>(string)$callback['from']['id'],'text'=>$ok?'Вход подтверждён. Вернитесь в браузер и нажмите «Войти в кабинет».':'Запрос уже обработан или истёк. Начните вход заново.']));
            }
            return;
        }
        if (!is_int($id)) throw new BillingError('Invalid update');
        // Never trust group messages or forwarded identities for account operations.
        if (!$message || ($message['chat']['type']??'')!=='private' || !is_int($message['from']['id']??null) || ($message['from']['id']??null)!==($message['chat']['id']??null)) return;
        $tg=(string)$message['from']['id']; $text=trim($message['text']??'');
        // Persist inbox receipt and response together. Purchases use the update id as a separate idempotency key.
        if ($this->db->one('SELECT update_id FROM telegram_updates WHERE update_id=?',[$id])) return;
        $markup=null;
        if($this->login && str_starts_with($text,'/start login_')){
            $token=substr($text,13);
            if($this->login->pending($token)){
                $this->db->transaction(fn()=> $this->outbox->enqueue('telegram.send','login-prompt:'.$id,['chat_id'=>$tg,'text'=>'Подтвердите вход на '.parse_url($this->appUrl,PHP_URL_HOST).'. Подтверждайте только запрос, который вы только что начали в своём браузере.','reply_markup'=>['inline_keyboard'=>[[['text'=>'Это я, войти','callback_data'=>'login:'.$token]]]]]));
            }
            return;
        }
        $reply='Команды: /login — вход в кабинет, /plans — тарифы, /buy basic — покупка, /status — подписки. Кабинет: '.$this->appUrl;
        if (str_starts_with($text,'/link ')) {
            $reply=$this->link($tg,substr($text,6));
        } else {
            $this->db->execute('INSERT INTO users(id,telegram_id,created_at) VALUES(?,?,?) ON CONFLICT(telegram_id) DO NOTHING',[Database::id(),$tg,time()]);
            $user=$this->db->one('SELECT * FROM users WHERE telegram_id=?',[$tg]);
            if((int)$user['disabled']===1)return;
            if($text==='/login' && $this->login){
                $this->db->transaction(function()use($id,$tg){
                    if(!$this->db->execute('INSERT INTO telegram_updates VALUES(?,?) ON CONFLICT(update_id) DO NOTHING',[$id,time()]))return;
                    $token=$this->login->magic($tg);
                    $this->outbox->enqueue('telegram.send','reply:'.$id,['chat_id'=>$tg,'text'=>'Одноразовая ссылка действует 5 минут. Не пересылайте её.','reply_markup'=>['inline_keyboard'=>[[['text'=>'Открыть кабинет','url'=>rtrim($this->appUrl,'/').'/telegram/magic#'.$token]]]]]);
                });return;
            } elseif ($text==='/plans') {
                $reply=implode("\n",array_map(fn($p)=>$p['name'].' · '.Payments::decimal((int)$p['price_minor']).' ₽ / '.$p['duration_days'].' дн. → /buy '.$p['id'],$this->db->all('SELECT * FROM plans WHERE active=1 ORDER BY price_minor')));
            } elseif (str_starts_with($text,'/buy ')) {
                try {
                    $order=$this->billing->order($user['id'],trim(substr($text,5)),'telegram:'.$id,null,'8.8.8.8');
                    $reply='Заказ '.$order['id'].' создан. Ссылка на оплату появится по команде /status. Для веб-доступа сначала привяжите аккаунт через кабинет.';
                } catch (BillingError $e) { $reply=$e->getMessage(); }
            } elseif ($text==='/status') {
                $subs=$this->db->all('SELECT * FROM subscriptions WHERE user_id=? ORDER BY created_at DESC LIMIT 5',[$user['id']]);
                $reply=$subs?implode("\n",array_map(fn($s)=>'Подписка: '.((int)$s['expires_at']<=time()?'expired':$s['status']).' до '.gmdate('d.m.Y',(int)$s['expires_at']).($s['status']==='active' && (int)$s['expires_at']>time() && $s['subscription_url'] ? ' · '.$s['subscription_url'] : ''),$subs)):'Подписок пока нет.';
                $orders=$this->db->all("SELECT * FROM orders WHERE user_id=? AND status='pending' ORDER BY created_at DESC LIMIT 3",[$user['id']]);
                foreach ($orders as $o) $reply.="\n".$o['plan_name'].': '.($o['provider']==='demo'?'демозаказ; оплата через привязанный веб-кабинет':($o['checkout_url']?:'ссылка готовится'));
            }
        }
        $this->db->transaction(function () use ($id,$tg,$reply,$markup) {
            if ($this->db->execute('INSERT INTO telegram_updates VALUES(?,?) ON CONFLICT(update_id) DO NOTHING',[$id,time()])) $this->outbox->enqueue('telegram.send','reply:'.$id,array_filter(['chat_id'=>$tg,'text'=>$reply,'reply_markup'=>$markup],fn($v)=>$v!==null));
        });
    }
    private function link(string $tg,string $token): string
    {
        return $this->db->transaction(function () use ($tg,$token) {
            $link=$this->db->one('SELECT * FROM telegram_links WHERE token_hash=? AND expires_at>?'.$this->db->lock(),[hash('sha256',$token),time()]);
            if (!$link) return 'Код недействителен. Получите новый в веб-кабинете.';
            $existing=$this->db->one('SELECT * FROM users WHERE telegram_id=?',[$tg]);
            $target=$this->db->one('SELECT * FROM users WHERE id=?'.$this->db->lock(),[$link['user_id']]);
            if (($existing && $existing['id']!==$target['id']) || ($target['telegram_id'] && $target['telegram_id']!==$tg)) return 'Telegram уже связан с аккаунтом. Обратитесь к администратору для переноса данных.';
            $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',[$tg,$target['id']]);
            $this->db->execute('DELETE FROM telegram_links WHERE token_hash=?',[$link['token_hash']]);
            return 'Telegram подключён к веб-кабинету.';
        });
    }
}
