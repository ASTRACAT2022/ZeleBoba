<?php
declare(strict_types=1);
namespace App\Integration;
use App\Infrastructure\{Database,Outbox};
use App\Billing\{BillingService,BillingError};
use App\TelegramUI\{Blocks,Screens};
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\HttpClient;
final class Telegram
{
    private ?\App\Container $app = null;
    private ?HttpClientInterface $http = null;
    private ?array $callbackMessage = null;
    public function __construct(private Database $db,private Outbox $outbox,private BillingService $billing,private string $appUrl, private ?\App\Identity\TelegramLogin $login=null, private string $apiBase='https://astracattg.netlify.app', ?HttpClientInterface $http=null) { $this->http=$http; }
    public function setApp(\App\Container $app): void { $this->app = $app; }
    public function apiBase(): string { return rtrim($this->apiBase,'/')===''?'https://astracattg.netlify.app':rtrim($this->apiBase,'/'); }

    private function httpClient(): HttpClientInterface
    {
        return $this->http ??= HttpClient::create();
    }

    /** Ask the Telegram API (via mirror) whether $tgId is a member of the channel. */
    private function checkMembership(string $tgId, string $channelId): bool
    {
        $token = $this->app?->config['TELEGRAM_BOT_TOKEN'] ?? '';
        $target = $channelId; // getChatMember accepts @username or numeric chat_id
        if ($token === '') return false;
        try {
            $resp = $this->httpClient()->request('GET', $this->apiBase().'/bot'.$token.'/getChatMember', [
                'query' => ['chat_id' => $target, 'user_id' => $tgId],
                'timeout' => 12, 'max_duration' => 15,
            ])->toArray();
            if (!($resp['ok'] ?? false)) return false;
            $status = (string)($resp['result']['status'] ?? '');
            return in_array($status, ['member', 'administrator', 'creator'], true);
        } catch (\Throwable) {
            return false;
        }
    }
    public function receive(array $update): void
    {
        $id=$update['update_id']??null; $message=$update['message']??null;
        $callback=$update['callback_query']??null;
        $this->callbackMessage = is_array($callback) ? $callback : null;
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
        // Mandatory channel gate: block the whole bot until the user follows required channels.
        if (!$this->gatePass($id,$tg)) return;
        if($this->login && str_starts_with($text,'/start login_')){
            $token=substr($text,13);
            if($this->login->pending($token)){
                $this->db->transaction(fn()=> $this->outbox->enqueue('telegram.send','login-prompt:'.$id,['chat_id'=>$tg,'text'=>'Подтвердите вход на '.parse_url($this->appUrl,PHP_URL_HOST).'. Подтверждайте только запрос, который вы только что начали в своём браузере.','reply_markup'=>['inline_keyboard'=>[[['text'=>'Это я, войти','callback_data'=>'login:'.$token]]]]]));
            }
            return;
        }
        if(str_starts_with($text,'/start ref_') || str_starts_with($text,'start ref_')){
            $code=substr($text,strpos($text,'ref_')+4);
            $user=$this->ensureUser($tg);
            if($user && $this->app){
                $this->app->referrals->attachReferrer($user['id'],$code);
            }
            $this->sendWelcome($id,$tg);
            return;
        }
        if(str_starts_with($text,'/start GIFT_') || str_starts_with($text,'start GIFT_')){
            $code=substr($text,strpos($text,'GIFT_'));
            $user=$this->ensureUser($tg);
            if($user && $this->app){
                try {
                    $purchase=$this->app->gifts->claim($user['id'],$code);
                    $this->reply($id,$tg,'🎁 Подарок активирован! Подписка на '.$purchase['period_days'].' дней оформляется. Отправьте /status для проверки.',$this->mainMenu());
                } catch (BillingError $e) {
                    $this->reply($id,$tg,'🎁 '.$e->getMessage(),$this->mainMenu());
                }
            }
            return;
        }
        if(str_starts_with($text,'/start ') || str_starts_with($text,'start ')){
            $param=trim(substr($text,strpos($text,' ')+1));
            if($param!=='' && !str_starts_with($param,'login_') && !str_starts_with($param,'ref_') && !str_starts_with($param,'GIFT_') && $this->app){
                $user=$this->ensureUser($tg);
                if($user){
                    $campaign=$this->app->campaigns->register($user['id'],$param);
                    if($campaign){
                        $this->reply($id,$tg,'🎁 Бонус кампании «'.$campaign['name'].'» активирован!',$this->mainMenu());
                        return;
                    }
                }
            }
            $this->sendWelcome($id,$tg);
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
        if($command==='/myid'||$command==='myid'||$command==='/id'||$command==='id'){
            $info='Ваш chat_id: '.$tg."\nЭтот Telegram привязан к аккаунту ".$user['id'].(!empty($user['email']) && !str_starts_with($user['email'],'tg_') ? ' ('.$user['email'].')' : ' (только Telegram — без email)');
            $this->reply($id, $tg, $info, $this->mainMenu());
            return;
        }
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
        if(in_array($command,['/connect','connect','/vpn','vpn'],true)){ $this->showUi($id,$tg,$user,'connect'); return; }
        if(in_array($command,['/servers','servers'],true)){ $this->showUi($id,$tg,$user,'servers'); return; }
        if(in_array($command,['/network','network','/statuspage'],true)){ $this->showUi($id,$tg,$user,'network'); return; }
        if(in_array($command,['/profile','profile'],true)){ $this->showUi($id,$tg,$user,'profile'); return; }
        if(in_array($command,['/settings','settings'],true)){ $this->showUi($id,$tg,$user,'settings'); return; }
        if(in_array($command,['/support','support'],true)){ $this->showUi($id,$tg,$user,'support'); return; }
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
        if(str_starts_with($command,'/promo')||str_starts_with($text,'/promo ')){
            $arg=trim(substr($text,6));
            if($arg===''){ $this->reply($id,$tg,'Отправьте /promo <код>, например /promo SUMMER2026',$this->mainMenu()); return; }
            $result=$this->app->promocodes->activate($user['id'],$arg);
            $this->reply($id,$tg,$result['success']?$result['description']:$this->promoError($result['error']),$this->mainMenu());
            return;
        }
        if(str_starts_with($command,'/gift_buy')||str_starts_with($text,'/gift_buy ')){
            $arg=trim(substr($text,9));
            if($arg===''||!$this->app){ $this->reply($id,$tg,'Отправьте /gift_buy <id тарифа>.',$this->mainMenu()); return; }
            try {
                $purchase=$this->app->gifts->purchaseFromBalance($user['id'],$arg,'tg-gift:'.$id.':'.$arg,null,null,null,'bot');
                $code=$this->app->gifts->publicCode($purchase['token']);
                $this->reply($id,$tg,'🎁 Подарок куплен! Код: <code>'.$code.'</code>'."\nОтправьте его другу. Активировать собственный подарок нельзя.",$this->mainMenu());
            } catch (BillingError $e) { $this->reply($id,$tg,$e->getMessage(),$this->mainMenu()); }
            return;
        }
        if(str_starts_with($command,'/gift_claim')||str_starts_with($text,'/gift_claim ')){
            $arg=trim(substr($text,11));
            if($arg===''||!$this->app){ $this->reply($id,$tg,'Отправьте /gift_claim <код>.',$this->mainMenu()); return; }
            try {
                $purchase=$this->app->gifts->claim($user['id'],$arg);
                $this->reply($id,$tg,'🎁 Подарок активирован! Подписка на '.$purchase['period_days'].' дней оформляется. Отправьте /status для проверки.',$this->mainMenu());
            } catch (BillingError $e) { $this->reply($id,$tg,'🎁 '.$e->getMessage(),$this->mainMenu()); }
            return;
        }
        if(str_starts_with($command,'/trial')||str_starts_with($text,'/trial ')){
            $arg=trim(substr($text,6));
            if($arg===''||!$this->app){ $this->reply($id,$tg,'Отправьте /trial <id тарифа>.',$this->mainMenu()); return; }
            try {
                $sub=$this->app->trials->start($user['id'],$arg);
                $this->reply($id,$tg,'🎁 Триал активирован! Подписка действует до '.gmdate('d.m.Y',(int)$sub['expires_at']).' UTC. Отправьте /status для проверки.',$this->mainMenu());
            } catch (BillingError $e) { $this->reply($id,$tg,$e->getMessage(),$this->mainMenu()); }
            return;
        }
        if($command==='/gift'||$command==='gift'||$command==='/gifts'||$command==='gifts'){
            $this->sendGifts($id,$tg,$user); return;
        }
        if($command==='/referral'||$command==='referral'||$command==='/ref'||$command==='ref'){
            $this->sendReferral($id,$tg,$user); return;
        }
        if(str_starts_with($command,'/topup')||str_starts_with($text,'/topup ')){
            $arg=trim(substr($text,6));
            if($arg===''){ $this->sendBalance($id,$tg,$user); return; }
            $this->topupAmount($id,$tg,$user,$arg);
            return;
        }
        if($command==='/balance'||$command==='balance'||$command==='/topup'||$command==='topup'){
            $this->sendBalance($id,$tg,$user); return;
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
            $identities=new \App\Identity\IdentityService($this->db);
            $existingId=$identities->userId('telegram',$tg);
            $target=$this->db->one('SELECT * FROM users WHERE id=?'.$this->db->lock(),[$link['user_id']]);
            if (($existingId && $existingId!==$target['id']) || ($target['telegram_id'] && $target['telegram_id']!==$tg)) return 'Telegram уже связан с аккаунтом. Обратитесь к администратору для переноса данных.';
            $identities->attach($target['id'],'telegram',$tg,true);
            $this->db->execute('UPDATE users SET telegram_id=? WHERE id=?',[$tg,$target['id']]);
            $this->db->execute('DELETE FROM telegram_links WHERE token_hash=?',[$link['token_hash']]);
            return 'Telegram подключён к веб-кабинету.';
        });
    }

    private function ensureUser(string $tg): ?array
    {
        $id=(new \App\Identity\TelegramLogin($this->db,new \App\Identity\Auth($this->db)))->telegramUser($tg);
        $user=$this->db->one('SELECT * FROM users WHERE id=?',[$id]);
        if(!$user || (int)$user['disabled']===1) return null;
        return $user;
    }
    /** Membership gate: returns true when the user passes all active required channels. */
    private function gatePass(int $updateId, string $tg): bool
    {
        if (!$this->app || !$this->app->channels) return true;
        $user = $this->ensureUser($tg);
        if (!$user) return false;
        $missing = $this->app->channels->missingChannels($user['id']);
        if (!$missing) return true;
        $keyboard = [];
        foreach ($missing as $ch) {
            $url = $ch['channel_link'] ?? ('https://t.me/'.ltrim((string)$ch['channel_id'], '@'));
            $keyboard[] = [['text' => '📢 Подписаться: '.($ch['title'] ?? 'канал'), 'url' => $url]];
        }
        $keyboard[] = [['text' => '✅ Я подписался', 'callback_data' => 'chk:'.($missing[0]['channel_id'] ?? '')]];
        $names = implode(', ', array_map(fn($ch) => $ch['title'] ?? $ch['channel_id'], $missing));
        $this->db->transaction(function () use ($updateId, $tg, $names, $keyboard) {
            if (!$this->db->execute('INSERT INTO telegram_updates VALUES(?,?) ON CONFLICT(update_id) DO NOTHING',[$updateId,time()])) return;
            $this->outbox->enqueue('telegram.send','gate:'.$updateId,['chat_id'=>$tg,'text'=>"Чтобы пользоваться ботом, подпишитесь на наш канал: ".$names."\nПосле подписки нажмите «✅ Я подписался».",'reply_markup'=>['inline_keyboard'=>$keyboard]]);
        });
        return false;
    }
    /** Re-check membership after the user tapped "Я подписался". */
    private function handleCheckCallback(int|string $updateId, string $tg, string $channelId): void
    {
        $subscribed = $this->checkMembership($tg, $channelId);
        $user = $this->ensureUser($tg);
        if ($this->app && $subscribed && $user) $this->app->channels->updateMembership($user['id'], $channelId, true);
        $text = $subscribed ? "✅ Спасибо! Доступ открыт." : "Подписка ещё не найдена. Убедитесь, что вы вступили в канал, и нажмите кнопку ещё раз.";
        $this->reply($updateId, $tg, $text, $subscribed ? $this->mainMenu() : null);
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
            [['text'=>'VPN','callback_data'=>'ui:connect'],['text'=>'Подписка','callback_data'=>'ui:subscription']],
            [['text'=>'Серверы','callback_data'=>'ui:servers'],['text'=>'Платежи','callback_data'=>'ui:payments']],
            [['text'=>'Рефералы','callback_data'=>'ui:referrals'],['text'=>'Профиль','callback_data'=>'ui:profile']],
            [['text'=>'Поддержка','callback_data'=>'ui:support']],
        ]];
    }

    /** Build a native Rich Message from current backend state. */
    private function uiScreen(string $screen, array $user, string $argument = ''): array
    {
        $ui = new Screens();
        if ($screen === 'home') {
            $subscription = $this->db->one("SELECT s.*,COALESCE(o.plan_name,p.name) AS plan_name FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? AND s.status IN ('active','trial') AND s.expires_at>? ORDER BY s.expires_at DESC LIMIT 1", [$user['id'], time()]);
            return $ui->buildHomeScreen($user, $subscription);
        }
        if ($screen === 'subscription') {
            $subscriptions = $this->db->all("SELECT s.*,COALESCE(o.plan_name,p.name) AS plan_name,COALESCE(o.devices,s.device_limit) AS devices FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? AND s.status IN ('active','trial') AND s.expires_at>? ORDER BY s.expires_at DESC LIMIT 10", [$user['id'], time()]);
            return $ui->buildSubscriptionScreen($subscriptions);
        }
        if (str_starts_with($screen, 'connect-')) {
            $device = substr($screen, 8);
            $subscription = $this->db->one("SELECT subscription_url FROM subscriptions WHERE user_id=? AND status IN ('active','trial') AND expires_at>? AND subscription_url IS NOT NULL ORDER BY expires_at DESC LIMIT 1", [$user['id'], time()]);
            $devices = ['iphone'=>'iPhone','android'=>'Android','windows'=>'Windows','macos'=>'macOS','linux'=>'Linux'];
            return $ui->buildConnectionGuideScreen($devices[$device] ?? 'устройство', $subscription['subscription_url'] ?? null);
        }
        if ($screen === 'servers') return $ui->buildServersScreen([]);
        if ($screen === 'connect') return $ui->buildConnectionScreen();
        if ($screen === 'plans') {
            $plans = $this->db->all('SELECT * FROM plans WHERE active=1 ORDER BY price_minor');
            return $ui->buildPlansScreen(array_map(fn($plan) => $this->uiPlan($plan, $user['id']), $plans));
        }
        if ($screen === 'plan') {
            $plan = $this->db->one('SELECT * FROM plans WHERE id=? AND active=1', [$argument]);
            if (!$plan) return ['blocks'=>[Blocks::heading('Тариф недоступен'), Blocks::paragraph('Выберите другой тариф.'), Blocks::buttons([Blocks::button('← К тарифам','ui:plans')])]];
            return $ui->buildSelectedPlanScreen($this->uiPlan($plan, $user['id']));
        }
        if ($screen === 'order') {
            $order = $this->db->one('SELECT * FROM orders WHERE id=? AND user_id=?', [$argument, $user['id']]);
            if (!$order) return ['blocks'=>[Blocks::heading('Заказ не найден'), Blocks::buttons([Blocks::button('Мои платежи','ui:payments'), Blocks::button('На главную','ui:home')])]];
            // Create the checkout on open if it is still missing, so the payment
            // link is available without an extra «Обновить» press. Idempotent and
            // guarded by the same durable attempt as the worker.
            if ($order['status']==='pending' && empty($order['checkout_url']) && $order['provider']!=='demo' && $this->app) {
                try { $this->app->paymentService->createOrder((string)$order['id']); } catch (\Throwable $e) {}
                $order = $this->db->one('SELECT * FROM orders WHERE id=?', [$order['id']]);
            }
            $subscription = $this->db->one('SELECT * FROM subscriptions WHERE order_id=? AND user_id=?', [$order['id'], $user['id']]);
            return $ui->buildOrderScreen($order, $subscription);
        }
        if ($screen === 'payments') {
            $orders = $this->db->all('SELECT plan_name,price_minor,created_at,status FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 10', [$user['id']]);
            $payments = array_map(static fn($row) => [
                'date'=>gmdate('d.m', (int)$row['created_at']),
                'product'=>(string)$row['plan_name'].' · '.match($row['status']) {'fulfilled'=>'Готово','paid'=>'Оплачено','pending'=>'Ожидает','canceled','cancelled'=>'Отмена',default=>(string)$row['status']},
                'amount'=>Payments::decimal((int)$row['price_minor']).' ₽',
            ], $orders);
            return $ui->buildPaymentsScreen($payments);
        }
        if ($screen === 'network') {
            $labels = ['worker'=>'Обработка задач','scheduler'=>'Планировщик','telegram'=>'Telegram-бот'];
            $rows = [];
            foreach ($labels as $name=>$label) {
                $heartbeat = $this->db->one('SELECT seen_at FROM runtime_heartbeats WHERE name=?', [$name]);
                $ok = $heartbeat && (int)$heartbeat['seen_at'] > time()-180;
                $rows[] = ['name'=>$label, 'status'=>$ok ? '🟢 Работает' : '🟡 Нет свежего статуса'];
            }
            return $ui->buildNetworkStatusScreen($rows);
        }
        if ($screen === 'referrals' && $this->app) {
            $stats = $this->app->referrals->stats($user['id']);
            $bot = trim((string)($this->app->config['TELEGRAM_BOT_USERNAME'] ?? ''), '@ ');
            $stats['referral_count'] = count($stats['referrals']);
            $stats['link'] = $bot !== '' ? 'https://t.me/'.$bot.'?start=ref_'.$stats['code'] : '';
            return $ui->buildReferralScreen($stats);
        }
        if ($screen === 'profile') return $ui->buildProfileScreen($user);
        if ($screen === 'settings') return $ui->buildSettingsScreen([]);
        if ($screen === 'support') return $ui->buildSupportScreen(['url'=>$this->supportUrl()]);
        return $ui->buildHomeScreen($user);
    }

    private function uiPlan(array $plan, string $userId): array
    {
        $months = (int)($plan['duration_months'] ?? 0);
        $days = (int)$plan['duration_days'];
        $duration = $months > 0 ? $months.' мес.' : ($days % 30 === 0 && $days >= 30 ? (int)($days/30).' мес.' : $days.' дн.');
        $trafficBytes = (int)$plan['traffic_bytes'];
        $traffic = $trafficBytes <= 0 ? 'Безлимит' : number_format($trafficBytes / 1073741824, 0, ',', ' ').' GB';
        $price = $this->app ? $this->app->billing->priceFor($userId, $plan) : (int)$plan['price_minor'];
        return ['id'=>(string)$plan['id'],'duration'=>$duration,'traffic'=>$traffic,'price'=>Payments::decimal($price).' ₽'];
    }

    private function showUi(int $updateId, string $chatId, array $user, string $screen, string $argument = '', ?int $messageId = null): void
    {
        $richMessage = $this->uiScreen($screen, $user, $argument);
        $isEdit = $messageId !== null && $messageId > 0;
        $topic = $isEdit ? 'telegram.rich.edit' : 'telegram.rich.send';
        $dedup = ($isEdit ? 'rich-edit:' : 'rich-send:').$updateId;
        $payload = ['chat_id'=>$chatId,'rich_message'=>$richMessage,'_ui_user_id'=>$user['id'],
            '_ui_screen'=>$screen,'_ui_context_id'=>$screen==='order'?$argument:null];
        if ($isEdit) $payload['message_id'] = $messageId;
        $this->db->transaction(function () use ($updateId, $topic, $dedup, $payload) {
            if (!$this->db->execute('INSERT INTO telegram_updates VALUES(?,?) ON CONFLICT(update_id) DO NOTHING', [$updateId,time()])) return;
            $this->outbox->enqueue($topic, $dedup, $payload);
        });
    }

    private function uiEditFromCallback(int $updateId, string $chatId, array $user, string $screen, string $argument, array $callback): void
    {
        $messageId = $callback['message']['message_id'] ?? null;
        if (!is_int($messageId) || $messageId < 1) return;
        $this->showUi($updateId, $chatId, $user, $screen, $argument, $messageId);
    }

    private function reply(int $updateId,string $chatId,string $text,?array $markup=null): void
    {
        $messageId = $this->callbackMessage['message']['message_id'] ?? null;
        if (is_int($messageId) && $messageId > 0) {
            $blocks = [Blocks::paragraph($text)];
            foreach (($markup['inline_keyboard'] ?? []) as $row) {
                $buttons = [];
                foreach ($row as $item) {
                    if (!empty($item['callback_data'])) $buttons[] = Blocks::button((string)$item['text'], (string)$item['callback_data'], $item['style'] ?? null);
                    elseif (!empty($item['url'])) $buttons[] = Blocks::urlButton((string)$item['text'], (string)$item['url'], $item['style'] ?? null);
                    elseif (!empty($item['copy_text']['text'])) $buttons[] = Blocks::copyButton((string)$item['text'], (string)$item['copy_text']['text']);
                }
                if ($buttons) $blocks[] = Blocks::buttons($buttons);
            }
            $user = $this->db->one('SELECT id FROM users WHERE telegram_id=?',[$chatId]);
            $payload = ['chat_id'=>$chatId,'message_id'=>$messageId,'rich_message'=>['blocks'=>$blocks],
                '_ui_user_id'=>$user['id']??null,'_ui_screen'=>'message','_ui_context_id'=>null];
            $this->db->transaction(function () use ($updateId,$payload) {
                if ($this->db->execute('INSERT INTO telegram_updates VALUES(?,?) ON CONFLICT(update_id) DO NOTHING',[$updateId,time()])) {
                    $this->outbox->enqueue('telegram.rich.edit','rich-edit:'.$updateId,$payload);
                }
            });
            return;
        }
        $this->db->transaction(function () use ($updateId,$chatId,$text,$markup) {
            if ($this->db->execute('INSERT INTO telegram_updates VALUES(?,?) ON CONFLICT(update_id) DO NOTHING',[$updateId,time()])) $this->outbox->enqueue('telegram.send','reply:'.$updateId,array_filter(['chat_id'=>$chatId,'text'=>$text,'reply_markup'=>$markup],fn($v)=>$v!==null));
        });
    }
    private function sendWelcome(int $id,string $tg): void
    {
        $user=$this->ensureUser($tg);
        if ($user) $this->showUi($id,$tg,$user,'home');
    }
    private function sendHelp(int $id,string $tg): void
    {
        $user=$this->ensureUser($tg);
        if ($user) $this->showUi($id,$tg,$user,'support');
    }
    private function sendPlans(int $id,string $tg,?string $prefix=null): void
    {
        $user=$this->ensureUser($tg);
        if ($user) $this->showUi($id,$tg,$user,'plans');
    }
    private function buyPlan(int $id,string $tg,array $user,string $planId,?array $callback=null): void
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
            // Create the checkout synchronously so the payment link is ready the
            // moment the order screen opens (no «Обновить» press needed). This is
            // idempotent and shares the durable-attempt guard with the worker, so
            // a concurrent payment.create job cannot double-charge.
            try {
                if(!$order['checkout_url'] && $this->app){
                    $this->app->paymentService->createOrder((string)$order['id']);
                }
            } catch (\Throwable $e) {
                // Provider slow/unknown: worker owns the durable attempt and will
                // finish it; the «Обновить» button stays as recovery.
            }
            $order=$this->db->one('SELECT * FROM orders WHERE id=?',[$order['id']]);
        }
        $messageId = $callback['message']['message_id'] ?? null;
        $this->showUi($id,$tg,$user,'order',(string)$order['id'],is_int($messageId)?$messageId:null);
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
        $this->showUi($id,$tg,$user,'subscription');
    }
    private function promoError(string $key): string
    {
        return match ($key) {
            'not_found' => 'Промокод не найден.',
            'inactive' => 'Промокод неактивен.',
            'used' => 'Промокод уже использован.',
            'not_yet_valid' => 'Промокод ещё не действует.',
            'expired' => 'Срок действия промокода истёк.',
            'already_used_by_user' => 'Вы уже использовали этот промокод.',
            'daily_limit' => 'Слишком много активаций за сутки.',
            'not_first_purchase' => 'Промокод действует только для первой покупки.',
            'active_discount_exists' => 'У вас уже есть активная скидка.',
            'no_subscription_for_days' => 'Нет подписки для начисления дней.',
            'trial_subscription_exists' => 'Триал недоступен: у вас уже есть подписка.',
            'traffic_not_applicable' => 'Трафик не начислен: у подписки безлимит.',
            default => 'Не удалось активировать промокод.',
        };
    }
    private function sendGifts(int $id,string $tg,array $user): void
    {
        if(!$this->app){ $this->reply($id,$tg,'Подарки недоступны.',$this->mainMenu()); return; }
        $bought=$this->app->gifts->boughtBy($user['id']);
        $lines=['🎁 Подарки'];
        if($bought){
            foreach($bought as $g){
                $code=$this->app->gifts->publicCode($g['token']);
                $lines[]='— '.$g['period_days'].' дн. · '.($g['status']==='delivered'?'активирован':'код: <code>'.$code.'</code>');
            }
        } else {
            $lines[]='Пока нет купленных подарков.';
        }
        $lines[]='\nКупить подарок: /gift_buy <id тарифа> или в веб-кабинете.';
        $lines[]='Активировать: /gift_claim <код>';
        $this->reply($id,$tg,implode("\n",$lines),['inline_keyboard'=>[[['text'=>'Меню','callback_data'=>'menu:main']]]]);
    }
    private function sendReferral(int $id,string $tg,array $user): void
    {
        $this->showUi($id,$tg,$user,'referrals');
    }
    private function sendBalance(int $id,string $tg,array $user): void
    {
        $balance=$this->db->one('SELECT balance_kopeks FROM users WHERE id=?',[$user['id']]);
        $text='💰 Баланс: '.Payments::decimal((int)($balance['balance_kopeks']??0)).' ₽'."\n\nПополните баланс и покупайте подписки без повторной оплаты. Отправьте /topup <сумма>, например /topup 500.";
        $keyboard=[];
        if ($this->app) {
            foreach ($this->app->providers->enabled() as $pid=>$provider) {
                if ($pid==='freekassa') continue; // FreeKassa removed from payment selection (legacy data only)
                $keyboard[]=[['text'=>$provider->name(),'callback_data'=>'topup-provider:'.$pid]];
            }
        }
        $keyboard[]=[['text'=>'Тарифы','callback_data'=>'menu:plans'],['text'=>'Меню','callback_data'=>'menu:main']];
        $this->reply($id,$tg,$text,['inline_keyboard'=>$keyboard]);
    }
    private function topupAmount(int $id,string $tg,array $user,string $arg): void
    {
        $amount=filter_var(trim($arg),FILTER_VALIDATE_INT);
        if ($amount===false || $amount<1 || $amount>1000000){ $this->reply($id,$tg,'Сумма пополнения: от 1 до 1 000 000 ₽. Пример: /topup 500',$this->mainMenu()); return; }
        $provider=$this->db->one('SELECT value FROM app_settings WHERE name=?',['TOPUP_PROVIDER'])['value']??null;
        try {
            $topup=$this->app->topups->create($user['id'],$amount*100,'telegram:'.$id,$provider);
        } catch (BillingError $e) { $this->reply($id,$tg,$e->getMessage(),$this->mainMenu()); return; }
        if($topup['provider']==='demo'){
            try { $this->app->topups->settle($topup['id'],'demo','demo_'.$topup['id'],(int)$topup['amount_kopeks'],$topup['currency']); } catch (BillingError) {}
            $this->reply($id,$tg,'Баланс пополнен на '.Payments::decimal((int)$topup['amount_kopeks']).' ₽ (демо).',$this->mainMenu());
            return;
        }
        $topup=$this->db->one('SELECT * FROM topups WHERE id=?',[$topup['id']]);
        $keyboard=$topup['checkout_url']?[['text'=>'Оплатить','url'=>$topup['checkout_url']]]:[];
        $keyboard[]=['text'=>'Обновить статус','callback_data'=>'topup:'.$topup['id']];
        $this->reply($id,$tg,'Пополнение на '.Payments::decimal((int)$topup['amount_kopeks']).' ₽ создано.'.($topup['checkout_url']?"\nОплатите по ссылке ниже.":"\nГотовим ссылку — нажмите «Обновить статус»."),['inline_keyboard'=>[$keyboard,[['text'=>'Меню','callback_data'=>'menu:main']]]]);
    }
    private function sendOrders(int $id,string $tg,array $user): void
    {
        $this->showUi($id,$tg,$user,'payments');
    }
    private function sendSubs(int $id,string $tg,array $user): void
    {
        $this->showUi($id,$tg,$user,'subscription');
    }
    private function sendCabinet(int $id,string $tg): void
    {
        if(!$this->login){ $this->reply($id,$tg,'Кабинет: '.$this->appUrl,$this->mainMenu()); return; }
        $token=$this->login->magic($tg);
        $this->reply($id,$tg,'Одноразовая ссылка действует 5 минут. Не пересылайте её.',[
            'inline_keyboard'=>[[['text'=>'Открыть кабинет','url'=>rtrim($this->appUrl,'/').'/telegram/magic#'.$token]]],
        ]);
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
        if(str_starts_with($data,'chk:')){
            $this->handleCheckCallback($updateId, $tg, substr($data,4));
            return;
        }
        if($this->login && str_starts_with($data,'login:')){
            $ok=$this->login->approve(substr($data,6),$tg);
            $this->reply($updateId,$tg,$ok?'Вход подтверждён. Вернитесь в браузер и нажмите «Войти в кабинет».':'Запрос уже обработан или истёк. Начните вход заново.',null);
            return;
        }
        $user=$this->ensureUser($tg);
        if(!$user) return;
        if(!$this->gatePass($updateId,$tg)) return;
        if(str_starts_with($data,'ui:')){
            $action=substr($data,3);
            $screenMap=['home'=>'home','subscription'=>'subscription','servers'=>'servers','connect'=>'connect','plans'=>'plans','payments'=>'payments','network'=>'network','referrals'=>'referrals','profile'=>'profile','settings'=>'settings','support'=>'support'];
            if(isset($screenMap[$action])){ $this->uiEditFromCallback($updateId,$tg,$user,$screenMap[$action],'',$callback); return; }
            if(str_starts_with($action,'connect:')){
                $device=substr($action,8);
                if(in_array($device,['iphone','android','windows','macos','linux'],true)) $this->uiEditFromCallback($updateId,$tg,$user,'connect-'.$device,'',$callback);
                else $this->uiEditFromCallback($updateId,$tg,$user,'connect','',$callback);
                return;
            }
            if(str_starts_with($action,'plan:')){ $this->uiEditFromCallback($updateId,$tg,$user,'plan',substr($action,5),$callback); return; }
        }
        if($data==='menu:main'){ $this->uiEditFromCallback($updateId,$tg,$user,'home','',$callback); return; }
        if($data==='menu:plans'){ $this->uiEditFromCallback($updateId,$tg,$user,'plans','',$callback); return; }
        if($data==='menu:subs'){ $this->uiEditFromCallback($updateId,$tg,$user,'subscription','',$callback); return; }
        if($data==='menu:orders'){ $this->uiEditFromCallback($updateId,$tg,$user,'payments','',$callback); return; }
        if($data==='menu:cabinet'){ $this->sendCabinet($updateId,$tg); return; }
        if($data==='menu:help'){ $this->uiEditFromCallback($updateId,$tg,$user,'support','',$callback); return; }
        if(str_starts_with($data,'plan:')){
            $planId=substr($data,5);
            $plan=$this->db->one('SELECT * FROM plans WHERE id=? AND active=1',[$planId]);
            if(!$plan){ $this->reply($updateId,$tg,'Тариф недоступен.',$this->mainMenu()); return; }
            $this->uiEditFromCallback($updateId,$tg,$user,'plan',$planId,$callback);
            return;
        }
        if(str_starts_with($data,'buy:')){
            $this->buyPlan($updateId,$tg,$user,substr($data,4),$callback);
            return;
        }
        if(str_starts_with($data,'order:')){
            $orderId=substr($data,6);
            if(!preg_match('/^[a-f0-9]{32}$/D',$orderId)){ $this->reply($updateId,$tg,'Заказ не найден.',$this->mainMenu()); return; }
            $order=$this->db->one('SELECT * FROM orders WHERE id=? AND user_id=?',[$orderId,$user['id']]);
            if(!$order){ $this->reply($updateId,$tg,'Заказ не найден.',$this->mainMenu()); return; }
            $this->uiEditFromCallback($updateId,$tg,$user,'order',$orderId,$callback);
            return;
        }
        if(str_starts_with($data,'topup-provider:')){
            $pid=substr($data,15);
            if(!$this->app || !$this->app->providers->has($pid)){ $this->reply($updateId,$tg,'Провайдер недоступен.',$this->mainMenu()); return; }
            $this->db->execute('INSERT INTO app_settings VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at',['TOPUP_PROVIDER',$pid,time()]);
            $this->reply($updateId,$tg,'Способ оплаты: '.$this->app->providers->get($pid)->name().'. Отправьте /topup <сумма>.',$this->mainMenu());
            return;
        }
        if(str_starts_with($data,'topup:')){
            $topupId=substr($data,6);
            if(!preg_match('/^[a-f0-9]{32}$/D',$topupId)){ $this->reply($updateId,$tg,'Пополнение не найдено.',$this->mainMenu()); return; }
            $topup=$this->db->one('SELECT * FROM topups WHERE id=? AND user_id=?',[$topupId,$user['id']]);
            if(!$topup){ $this->reply($updateId,$tg,'Пополнение не найдено.',$this->mainMenu()); return; }
            if($topup['status']==='paid'){ $this->reply($updateId,$tg,'Средства зачислены на баланс.',$this->mainMenu()); return; }
            if($topup['status']==='canceled'){ $this->reply($updateId,$tg,'Платёж отменён. Создайте новое пополнение: /topup <сумма>',$this->mainMenu()); return; }
            $keyboard=$topup['checkout_url']?[['text'=>'Оплатить','url'=>$topup['checkout_url']]]:[];
            $keyboard[]=['text'=>'Обновить статус','callback_data'=>'topup:'.$topup['id']];
            $this->reply($updateId,$tg,'Пополнение на '.Payments::decimal((int)$topup['amount_kopeks']).' ₽.'.($topup['checkout_url']?"\nОплатите по ссылке ниже.":"\nГотовим ссылку — нажмите «Обновить статус»."),['inline_keyboard'=>[$keyboard,[['text'=>'Меню','callback_data'=>'menu:main']]]]);
            return;
        }
        if(str_starts_with($data,'autorenew:')){
            $subId=substr($data,10);
            if(!preg_match('/^[a-f0-9]{32}$/D',$subId)){ $this->reply($updateId,$tg,'Подписка не найдена.',$this->mainMenu()); return; }
            $sub=$this->db->one('SELECT * FROM subscriptions WHERE id=? AND user_id=?',[$subId,$user['id']]);
            if(!$sub){ $this->reply($updateId,$tg,'Подписка не найдена.',$this->mainMenu()); return; }
            try {
                $updated=$this->billing->setAutoRenew($user['id'],$subId,((int)($sub['auto_renew']??0)!==1));
                $this->uiEditFromCallback($updateId,$tg,$user,'subscription','',$callback);
            } catch (BillingError $e) { $this->reply($updateId,$tg,$e->getMessage(),$this->mainMenu()); }
            return;
        }
        if(str_starts_with($data,'renew:')){
            $subId=substr($data,6);
            if(!preg_match('/^[a-f0-9]{32}$/D',$subId)){ $this->reply($updateId,$tg,'Подписка не найдена.',$this->mainMenu()); return; }
            $sub=$this->db->one('SELECT * FROM subscriptions WHERE id=? AND user_id=?',[$subId,$user['id']]);
            if(!$sub){ $this->reply($updateId,$tg,'Подписка не найдена.',$this->mainMenu()); return; }
            if($sub['status']!=='active' || (int)$sub['expires_at']<=time()){ $this->reply($updateId,$tg,'Продлить можно только активную подписку.',$this->mainMenu()); return; }
            $plan=$this->db->one('SELECT * FROM plans WHERE id=? AND active=1',[$sub['plan_id']]);
            if(!$plan){ $this->reply($updateId,$tg,'Тариф подписки больше недоступен.',$this->mainMenu()); return; }
            try {
                $order=$this->billing->order($user['id'],$plan['id'],'renew:'.$subId.':'.$updateId,null,'8.8.8.8',$subId,null);
            } catch (BillingError $e) { $this->reply($updateId,$tg,$e->getMessage(),$this->mainMenu()); return; }
            if($order['provider']==='demo'){
                try { $this->billing->settle($order['id'],'demo','demo_'.$order['id'],(int)$order['price_minor'],$order['currency']); } catch (BillingError) {}
                $order=$this->db->one('SELECT * FROM orders WHERE id=?',[$order['id']]);
            } else {
                $order=$this->db->one('SELECT * FROM orders WHERE id=?',[$order['id']]);
            }
            $messageId=$callback['message']['message_id']??null;
            $this->showUi($updateId,$tg,$user,'order',(string)$order['id'],is_int($messageId)?$messageId:null);
            return;
        }
    }
}
