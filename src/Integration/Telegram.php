<?php
declare(strict_types=1);
namespace App\Integration;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,BillingError};
final class Telegram
{
    public function __construct(private Database $db,private Outbox $outbox,private BillingService $billing,private string $appUrl, private ?\App\Identity\TelegramLogin $login=null, private string $apiBase='https://astracattg.netlify.app') {}
    public function apiBase(): string { return rtrim($this->apiBase,'/')===''?'https://astracattg.netlify.app':rtrim($this->apiBase,'/'); }
    public function receive(array $update): void
    {
        $id=$update['update_id']??null; $message=$update['message']??null;
        $callback=$update['callback_query']??null;
        if($callback){
            $this->handleCallback($id,$callback);
            return;
        }
        if (!is_int($id)) throw new BillingError('Invalid update');
        // Never trust group messages or forwarded identities for account operations.
        if (!$message || ($message['chat']['type']??'')!=='private' || !is_int($message['from']['id']??null) || ($message['from']['id']??null)!==($message['chat']['id']??null)) return;
        $tg=(string)$message['from']['id']; $text=trim($message['text']??'');
        // Persist inbox receipt and response together. Purchases use the update id as a separate idempotency key.
        if ($this->db->one('SELECT update_id FROM telegram_updates WHERE update_id=?',[$id])) return;
        if($this->login && str_starts_with($text,'/start login_')){
            $token=substr($text,13);
            if($this->login->pending($token)){
                $this->db->transaction(fn()=> $this->outbox->enqueue('telegram.send','login-prompt:'.$id,['chat_id'=>$tg,'text'=>'Подтвердите вход на '.parse_url($this->appUrl,PHP_URL_HOST).'. Подтверждайте только запрос, который вы только что начали в своём браузере.','reply_markup'=>['inline_keyboard'=>[[['text'=>'Это я, войти','callback_data'=>'login:'.$token]]]]]));
            }
            return;
        }
        // Normalize commands: support /start, /help, /plans, /buy, /status, /orders, /subs, /cabinet, /support, /link
        $command=strtolower(strtok($text,' '));
        if ($command==='/start' || $command==='start') { $this->sendWelcome($id,$tg); return; }
        if ($command==='/help' || $command==='help') { $this->sendHelp($id,$tg); return; }
        if (str_starts_with($text,'/link ')) {
            $reply=$this->link($tg,substr($text,6));
            $this->reply($id,$tg,$reply,$this->mainMenu());
            return;
        }
        $user=$this->ensureUser($tg);
        if(!$user) return;
        if(($command==='/login'||$command==='login') && $this->login){
            $this->db->transaction(function()use($id,$tg){
                if(!$this->db->execute('INSERT INTO telegram_updates VALUES(?,?) ON CONFLICT(update_id) DO NOTHING',[$id,time()]))return;
                $token=$this->login->magic($tg);
                $this->outbox->enqueue('telegram.send','reply:'.$id,['chat_id'=>$tg,'text'=>'Одноразовая ссылка действует 5 минут. Не пересылайте её.','reply_markup'=>['inline_keyboard'=>[[['text'=>'Открыть кабинет','url'=>rtrim($this->appUrl,'/').'/telegram/magic#'.$token]]]]]);
            });return;
        }
        if($command==='/plans'||$command==='plans'||$command==='/tariffs'){
            $this->sendPlans($id,$tg); return;
        }
        if(str_starts_with($command,'/buy')||str_starts_with($text,'/buy ')){
            $arg=trim(substr($text,4));
            if($arg===''){ $this->sendPlans($id,$tg,'Выберите тариф для покупки:'); return; }
            $this->buyPlan($id,$tg,$user,$arg);
            return;
        }
        if($command==='/status'||$command==='status'){
            $this->sendStatus($id,$tg,$user); return;
        }
        if($command==='/orders'||$command==='orders'){
            $this->sendOrders($id,$tg,$user); return;
        }
        if($command==='/subs'||$command==='/mysubs'||$command==='subs'){
            $this->sendSubs($id,$tg,$user); return;
        }
        if($command==='/cabinet'||$command==='cabinet'){
            $this->sendCabinet($id,$tg); return;
        }
        if($command==='/support'||$command==='support'){
            $this->reply($id,$tg,'Поддержка: '.($this->supportUrl()?:'напишите администратору.')."\nКабинет: ".$this->appUrl,$this->mainMenu()); return;
        }
        // Unknown text: show help + main menu (cabinet duplicate behavior)
        $this->sendHelp($id,$tg);
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

    private function ensureUser(string $tg): ?array
    {
        $this->db->execute('INSERT INTO users(id,telegram_id,created_at) VALUES(?,?,?) ON CONFLICT(telegram_id) DO NOTHING',[Database::id(),$tg,time()]);
        $user=$this->db->one('SELECT * FROM users WHERE telegram_id=?',[$tg]);
        if(!$user || (int)$user['disabled']===1) return null;
        return $user;
    }
    private function supportUrl(): string
    {
        // BillingService config is not directly available here; read from app_settings fallback
        $row=$this->db->one("SELECT value FROM app_settings WHERE name='SUPPORT_URL'");
        return $row['value']??'';
    }
    private function mainMenu(): array
    {
        return ['inline_keyboard'=>[
            [['text'=>'Тарифы','callback_data'=>'menu:plans'],['text'=>'Мои подписки','callback_data'=>'menu:subs']],
            [['text'=>'Мои заказы','callback_data'=>'menu:orders'],['text'=>'Кабинет','callback_data'=>'menu:cabinet']],
            [['text'=>'Помощь','callback_data'=>'menu:help']],
        ]];
    }
    private function reply(int $updateId,string $chatId,string $text,?array $markup=null): void
    {
        $this->db->transaction(function () use ($updateId,$chatId,$text,$markup) {
            if ($this->db->execute('INSERT INTO telegram_updates VALUES(?,?) ON CONFLICT(update_id) DO NOTHING',[$updateId,time()])) $this->outbox->enqueue('telegram.send','reply:'.$updateId,array_filter(['chat_id'=>$chatId,'text'=>$text,'reply_markup'=>$markup],fn($v)=>$v!==null));
        });
    }
    private function sendWelcome(int $id,string $tg): void
    {
        $this->ensureUser($tg);
        $text="Привет! Это дублер веб-кабинета.\nЗдесь можно купить подписку, оплатить и получить доступ — всё как на сайте.\n\nКабинет: ".$this->appUrl;
        $this->reply($id,$tg,$text,$this->mainMenu());
    }
    private function sendHelp(int $id,string $tg): void
    {
        $text="Команды:\n/plans — тарифы\n/buy <id> — купить\n/status — подписки + pending заказы\n/orders — мои заказы\n/subs — мои подписки\n/cabinet — открыть веб-кабинет\n/login — вход в кабинет\n/support — поддержка\n\nКабинет и бот работают в тандеме: заказы и подписки общие.";
        $this->reply($id,$tg,$text,$this->mainMenu());
    }
    private function sendPlans(int $id,string $tg,?string $prefix=null): void
    {
        $plans=$this->db->all('SELECT * FROM plans WHERE active=1 ORDER BY price_minor');
        if(!$plans){ $this->reply($id,$tg,'Пока нет доступных тарифов.',$this->mainMenu()); return; }
        $lines=[];
        $keyboard=[];
        foreach($plans as $p){
            $price=Payments::decimal((int)$p['price_minor']).' ₽';
            $traffic=(int)$p['traffic_bytes']===0?'безлимит':(round((int)$p['traffic_bytes']/1073741824).' ГБ');
            $lines[]=$p['name'].' · '.$price.' / '.$p['duration_days'].' дн. · '.$traffic.' · до '.$p['devices'].' устр.';
            $keyboard[]= [['text'=>'Купить '.$p['name'].' · '.$price,'callback_data'=>'buy:'.$p['id']]];
        }
        $keyboard[]= [['text'=>'Мои подписки','callback_data'=>'menu:subs'],['text'=>'Меню','callback_data'=>'menu:main']];
        $text=($prefix?$prefix."\n\n":'').implode("\n",$lines)."\n\nНажмите кнопку покупки или отправьте /buy <id>.";
        $this->reply($id,$tg,$text,['inline_keyboard'=>$keyboard]);
    }
    private function buyPlan(int $id,string $tg,array $user,string $planId): void
    {
        $planId=trim($planId);
        if(!preg_match('/^[a-zA-Z0-9:_-]{1,64}$/D',$planId)){ $this->reply($id,$tg,'Некорректный тариф.',$this->mainMenu()); return; }
        try {
            $order=$this->billing->order($user['id'],$planId,'telegram:'.$id,null,'8.8.8.8');
        } catch (BillingError $e) { $this->reply($id,$tg,$e->getMessage(),$this->mainMenu()); return; }
        // Demo driver: settle immediately for full cabinet parity in dev
        if($order['provider']==='demo'){
            try { $this->billing->settle($order['id'],'demo','demo_'.$order['id'],(int)$order['price_minor'],$order['currency']); } catch (BillingError) {}
            $order=$this->db->one('SELECT * FROM orders WHERE id=?',[$order['id']]);
        } else {
            // Refresh local copy (worker may have already created checkout_url)
            $order=$this->db->one('SELECT * FROM orders WHERE id=?',[$order['id']]);
        }
        $this->reply($id,$tg,$this->orderText($order)."\nКабинет: ".rtrim($this->appUrl,'/').'/orders/'.$order['id'],$this->orderKeyboard($order));
    }
    private function orderText(array $o): string
    {
        $price=Payments::decimal((int)$o['price_minor']).' ₽';
        $statusMap=['pending'=>'ожидает оплаты','paid'=>'оплачен, готовим доступ','fulfilled'=>'выполнен','canceled'=>'отменён'];
        $text='Заказ '.$o['plan_name'].' · '.$price.' · '.($statusMap[$o['status']]??$o['status']);
        if($o['status']==='pending'){
            $text.=$o['checkout_url']?"\nОплатите по ссылке ниже.": "\nГотовим ссылку на оплату — нажмите «Обновить».";
        } elseif($o['status']==='paid'){
            $text.="\nОплата получена, настраиваем подписку.";
        } elseif($o['status']==='fulfilled'){
            $sub=$this->db->one('SELECT * FROM subscriptions WHERE order_id=?',[$o['id']]);
            if($sub && $sub['subscription_url']) $text.="\nДоступ: ".$sub['subscription_url'];
            $text.="\nДо ".gmdate('d.m.Y H:i',(int)($sub['expires_at']??time())).' UTC.';
        }
        return $text;
    }
    private function orderKeyboard(array $o): array
    {
        if($o['status']==='pending' && $o['checkout_url']){
            return ['inline_keyboard'=>[
                [['text'=>'Оплатить','url'=>$o['checkout_url']]],
                [['text'=>'Обновить статус','callback_data'=>'order:'.$o['id']],['text'=>'Мои заказы','callback_data'=>'menu:orders']],
            ]];
        }
        if($o['status']==='pending'){
            return ['inline_keyboard'=>[
                [['text'=>'Обновить ссылку','callback_data'=>'order:'.$o['id']],['text'=>'Меню','callback_data'=>'menu:main']],
            ]];
        }
        return $this->mainMenu();
    }
    private function sendStatus(int $id,string $tg,array $user): void
    {
        $subs=$this->db->all('SELECT s.*,o.plan_name,o.devices FROM subscriptions s JOIN orders o ON o.id=s.order_id WHERE s.user_id=? ORDER BY s.created_at DESC LIMIT 5',[$user['id']]);
        $text=$subs?implode("\n",array_map(fn($s)=>'Подписка '.$s['plan_name'].': '.((int)$s['expires_at']<=time()?'истекла':$s['status']).' до '.gmdate('d.m.Y',(int)$s['expires_at']).($s['status']==='active' && (int)$s['expires_at']>time() && $s['subscription_url'] ? ' · '.$s['subscription_url'] : ''),$subs)):'Подписок пока нет.';
        $orders=$this->db->all("SELECT * FROM orders WHERE user_id=? AND status='pending' ORDER BY created_at DESC LIMIT 3",[$user['id']]);
        $keyboard=[];
        foreach ($orders as $o){
            $label=$o['plan_name'].': '.($o['provider']==='demo'?'демозаказ':($o['checkout_url']?'оплатить':'ссылка готовится'));
            $keyboard[]= [['text'=>$label,'callback_data'=>'order:'.$o['id']]];
        }
        $keyboard[]= [['text'=>'Тарифы','callback_data'=>'menu:plans'],['text'=>'Меню','callback_data'=>'menu:main']];
        if($orders) $text.="\n\nНеоплаченные заказы — нажмите чтобы открыть:";
        $this->reply($id,$tg,$text,['inline_keyboard'=>$keyboard]);
    }
    private function sendOrders(int $id,string $tg,array $user): void
    {
        $orders=$this->db->all('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 10',[$user['id']]);
        if(!$orders){ $this->reply($id,$tg,'Заказов пока нет. Выберите тариф:',['inline_keyboard'=>[[['text'=>'Тарифы','callback_data'=>'menu:plans']]]]); return; }
        $lines=[];
        $keyboard=[];
        foreach($orders as $o){
            $lines[]=$o['plan_name'].' · '.Payments::decimal((int)$o['price_minor']).' ₽ · '.$o['status'];
            $keyboard[]= [['text'=>$o['plan_name'].' · '.$o['status'],'callback_data'=>'order:'.$o['id']]];
        }
        $keyboard[]= [['text'=>'Меню','callback_data'=>'menu:main']];
        $this->reply($id,$tg,"Ваши заказы (общие с веб-кабинетом):\n".implode("\n",$lines),['inline_keyboard'=>$keyboard]);
    }
    private function sendSubs(int $id,string $tg,array $user): void
    {
        $subs=$this->db->all('SELECT s.*,o.plan_name,o.devices FROM subscriptions s JOIN orders o ON o.id=s.order_id WHERE s.user_id=? ORDER BY s.created_at DESC LIMIT 10',[$user['id']]);
        if(!$subs){ $this->reply($id,$tg,'Подписок пока нет. Выберите тариф:',['inline_keyboard'=>[[['text'=>'Тарифы','callback_data'=>'menu:plans']]]]); return; }
        $lines=array_map(fn($s)=>$s['plan_name'].': '.((int)$s['expires_at']<=time()?'истекла':$s['status']).' до '.gmdate('d.m.Y H:i',(int)$s['expires_at']).' UTC'.($s['subscription_url'] && (int)$s['expires_at']>time() ? ' · '.$s['subscription_url'] : ''),$subs);
        $this->reply($id,$tg,"Ваши подписки (общие с веб-кабинетом):\n".implode("\n",$lines),$this->mainMenu());
    }
    private function sendCabinet(int $id,string $tg): void
    {
        if(!$this->login){ $this->reply($id,$tg,'Кабинет: '.$this->appUrl,$this->mainMenu()); return; }
        $this->db->transaction(function()use($id,$tg){
            if(!$this->db->execute('INSERT INTO telegram_updates VALUES(?,?) ON CONFLICT(update_id) DO NOTHING',[$id,time()]))return;
            $token=$this->login->magic($tg);
            $this->outbox->enqueue('telegram.send','reply:'.$id,['chat_id'=>$tg,'text'=>'Одноразовая ссылка действует 5 минут. Не пересылайте её.','reply_markup'=>['inline_keyboard'=>[[['text'=>'Открыть кабинет','url'=>rtrim($this->appUrl,'/').'/telegram/magic#'.$token]]]]]);
        });
    }
    private function handleCallback($updateId,$callback): void
    {
        if(!is_int($updateId)) return;
        $msgChatType=$callback['message']['chat']['type']??'';
        $fromId=$callback['from']['id']??null;
        $msgChatId=$callback['message']['chat']['id']??null;
        if($msgChatType!=='private' || !is_int($fromId) || $fromId!==$msgChatId) return;
        $tg=(string)$fromId;
        $data=$callback['data']??'';
        $queryId=$callback['id']??'';
        if(!is_string($data)) return;
        // Always answer callback to remove spinner (via mirror)
        if(is_string($queryId) && $queryId!=='') $this->db->transaction(fn()=> $this->outbox->enqueue('telegram.answer','answer:'.$updateId,['callback_query_id'=>$queryId]));
        // Dedup callbacks by update_id
        if($this->db->one('SELECT update_id FROM telegram_updates WHERE update_id=?',[$updateId])) return;
        if($this->login && str_starts_with($data,'login:')){
            $ok=$this->login->approve(substr($data,6),$tg);
            $this->reply($updateId,$tg,$ok?'Вход подтверждён. Вернитесь в браузер и нажмите «Войти в кабинет».':'Запрос уже обработан или истёк. Начните вход заново.',null);
            return;
        }
        $user=$this->ensureUser($tg);
        if(!$user) return;
        if($data==='menu:main'){ $this->reply($updateId,$tg,'Меню. Кабинет: '.$this->appUrl,$this->mainMenu()); return; }
        if($data==='menu:plans'){ $this->sendPlans($updateId,$tg); return; }
        if($data==='menu:subs'){ $this->sendSubs($updateId,$tg,$user); return; }
        if($data==='menu:orders'){ $this->sendOrders($updateId,$tg,$user); return; }
        if($data==='menu:cabinet'){ $this->sendCabinet($updateId,$tg); return; }
        if($data==='menu:help'){ $this->sendHelp($updateId,$tg); return; }
        if(str_starts_with($data,'plan:')){
            $planId=substr($data,5);
            $plan=$this->db->one('SELECT * FROM plans WHERE id=? AND active=1',[$planId]);
            if(!$plan){ $this->reply($updateId,$tg,'Тариф недоступен.',$this->mainMenu()); return; }
            $price=Payments::decimal((int)$plan['price_minor']).' ₽';
            $traffic=(int)$plan['traffic_bytes']===0?'безлимит':(round((int)$plan['traffic_bytes']/1073741824).' ГБ');
            $text=$plan['name'].' · '.$price.' / '.$plan['duration_days'].' дн. · '.$traffic.' · до '.$plan['devices'].' устр.';
            $this->reply($updateId,$tg,$text,['inline_keyboard'=>[[['text'=>'Купить · '.$price,'callback_data'=>'buy:'.$plan['id']]], [['text'=>'Назад','callback_data'=>'menu:plans']]]]);
            return;
        }
        if(str_starts_with($data,'buy:')){
            $this->buyPlan($updateId,$tg,$user,substr($data,4));
            return;
        }
        if(str_starts_with($data,'order:')){
            $orderId=substr($data,6);
            if(!preg_match('/^[a-f0-9]{32}$/D',$orderId)){ $this->reply($updateId,$tg,'Заказ не найден.',$this->mainMenu()); return; }
            $order=$this->db->one('SELECT * FROM orders WHERE id=? AND user_id=?',[$orderId,$user['id']]);
            if(!$order){ $this->reply($updateId,$tg,'Заказ не найден.',$this->mainMenu()); return; }
            $this->reply($updateId,$tg,$this->orderText($order)."\nКабинет: ".rtrim($this->appUrl,'/').'/orders/'.$order['id'],$this->orderKeyboard($order));
            return;
        }
    }
}
