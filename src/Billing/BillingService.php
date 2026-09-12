<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class BillingService
{
    private ?\App\Billing\TopupService $topups = null;
    public function __construct(private Database $db, private Outbox $outbox, private string $provider, private ?array $config=null) {}
    public function order(string $userId, string $planId, string $key, ?string $receiptEmail=null, ?string $clientIp=null, ?string $renewSubscriptionId=null, ?string $landingSlug=null): array
    {
        if ($this->config!==null) {
            if ($this->config['PURCHASES_ENABLED']!=='1') throw new BillingError('Покупки временно приостановлены.');
            foreach(\App\Settings\Settings::purchaseErrors($this->config) as $error) throw new BillingError($error);
        }
        if (!preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D',$key)) throw new BillingError('Некорректный ключ операции.');
        return $this->db->transaction(function () use ($userId,$planId,$key,$receiptEmail,$clientIp,$renewSubscriptionId,$landingSlug) {
            if (!$this->db->one('SELECT id FROM users WHERE id=? AND disabled=0'.$this->db->lock(),[$userId])) throw new BillingError('Аккаунт не найден.');
            $existing=$this->db->one('SELECT * FROM orders WHERE user_id=? AND idempotency_key=?',[$userId,$key]);
            if ($existing) {
                if ($existing['plan_id']!==$planId) throw new BillingError('Этот ключ уже использован для другого тарифа.');
                return $existing;
            }
            $plan=$this->db->one('SELECT * FROM plans WHERE id=? AND active=1',[$planId]);
            if (!$plan) throw new BillingError('Тариф недоступен.');
            $user=$this->db->one('SELECT * FROM users WHERE id=?',[$userId]);
            if ($landingSlug!==null && $landingSlug!=='') {
                $landings=new LandingService($this->db,$this->outbox);
                $landing=$landings->get($landingSlug);
                if (!$landing) throw new BillingError('Предложение больше недоступно.');
                $plan['price_minor']=$landings->effectivePrice($landing,$plan);
            }
            $plan['price_minor']=min((int)$plan['price_minor'],$this->priceFor($userId,$this->db->one('SELECT * FROM plans WHERE id=?',[$planId])));
            $email=$receiptEmail ?: ($user['email']??null) ?: (!empty($user['telegram_id'])?$user['telegram_id'].'@telegram.org':null);
            if ($this->provider==='yookassa' && ($this->config['YOOKASSA_RECEIPT']??'0')==='1' && (!$email || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254)) throw new BillingError('Для чека укажите email при покупке в кабинете.');
            if ($this->provider==='freekassa' && (!$email || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254)) throw new BillingError('Для оплаты через FreeKassa нужен email. Укажите его в кабинете.');
            $providerAccount=$this->provider==='freekassa'?($this->config['FREEKASSA_SHOP_ID']??''):($this->config['YOOKASSA_SHOP_ID']??'');
            $ip=$clientIp!==null?trim($clientIp):null;
            if ($ip!==null && ($ip==='' || strlen($ip)>45)) $ip=null;
            $id=Database::id();
            $this->db->execute("INSERT INTO orders(id,user_id,plan_id,idempotency_key,price_minor,currency,plan_name,duration_days,traffic_bytes,devices,status,provider,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,'pending',?,?)",[$id,$userId,$planId,$key,$plan['price_minor'],$plan['currency'],$plan['name'],$plan['duration_days'],$plan['traffic_bytes'],$plan['devices'],$this->provider,time()]);
            $this->db->execute('UPDATE orders SET provision_driver=?,squad_uuid=?,provider_account=?,receipt_email=?,receipt_enabled=?,vat_code=?,tax_system=?,client_ip=? WHERE id=?',[$this->config['PROVISION_DRIVER']??'demo',$plan['squad_uuid']?:($this->config['REMNAWAVE_SQUAD_UUID']??''),$providerAccount,$email,(int)($this->config['YOOKASSA_RECEIPT']??0),(int)($this->config['YOOKASSA_VAT_CODE']??1),($this->config['YOOKASSA_TAX_SYSTEM']??'')?:null,$ip,$id]);
            $this->db->execute('UPDATE orders SET return_url=? WHERE id=?',[rtrim($this->config['APP_URL']??'http://127.0.0.1:8080','/').'/orders/'.$id,$id]);
            $this->outbox->enqueue('payment.create','checkout:'.$id,['order_id'=>$id]);
            $this->audit($userId,'order.created',$id);
            if ($renewSubscriptionId!==null) {
                $sub=$this->db->one('SELECT * FROM subscriptions WHERE id=?'.$this->db->lock(),[$renewSubscriptionId]);
                if (!$sub || $sub['user_id']!==$userId) throw new BillingError('Подписка для продления не найдена.');
                $this->db->execute('UPDATE subscriptions SET renew_order_id=? WHERE id=?',[$id,$renewSubscriptionId]);
                $this->audit($userId,'subscription.renew_ordered',$renewSubscriptionId);
            }
            return $this->db->one('SELECT * FROM orders WHERE id=?',[$id]);
        });
    }
    /** Values must originate from a verified provider API, never browser/webhook claims. */
    public function settle(string $orderId,string $provider,string $paymentId,int $amount,string $currency): void
    {
        $this->db->transaction(function () use ($orderId,$provider,$paymentId,$amount,$currency) {
            if ($this->db->postgres()) $this->db->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$provider.':'.$paymentId]);
            $order=$this->db->one('SELECT * FROM orders WHERE id=?'.$this->db->lock(),[$orderId]);
            if (!$order || $order['provider']!==$provider || (int)$order['price_minor']!==$amount || $order['currency']!==$currency || ($order['provider_payment_id']!==null && $order['provider_payment_id']!==$paymentId)) throw new BillingError('Платёж не соответствует заказу.');
            if ($this->db->one("SELECT id FROM topups WHERE provider=? AND provider_payment_id=? AND status='paid'",[$provider,$paymentId])) throw new BillingError('Платёж уже использован для пополнения.');
            $receipt=$this->db->one('SELECT * FROM payment_receipts WHERE provider=? AND payment_id=?',[$provider,$paymentId]);
            if ($receipt) {
                if ($receipt['order_id']!==$orderId) throw new BillingError('Платёж уже принадлежит другому заказу.');
                return;
            }
            if ($order['status']!=='pending') throw new BillingError('Заказ уже обработан.');
            $now=time();
            $this->db->execute('INSERT INTO payment_receipts VALUES(?,?,?,?,?,?)',[$provider,$paymentId,$orderId,$amount,$currency,$now]);
            foreach (['provider_clearing'=>$amount,'subscription_sales'=>-$amount] as $account=>$value) {
                $this->db->execute('INSERT INTO ledger_entries VALUES(?,?,?,?,?,?)',[Database::id(),$orderId,$account,$value,$currency,$now]);
            }
            $this->db->execute("UPDATE orders SET status='paid',provider_payment_id=?,paid_at=? WHERE id=?",[$paymentId,$now,$orderId]);
            $this->db->execute('UPDATE users SET has_had_paid_subscription=1 WHERE id=?',[$order['user_id']]);
            // Renewal: extend the existing subscription instead of creating a new one.
            $renewSub=$this->db->one('SELECT * FROM subscriptions WHERE renew_order_id=?'.$this->db->lock(),[$orderId]);
            // Record the purchase transaction for analytics (wallet history).
            $type = $renewSub ? 'subscription_renewal' : 'subscription_purchase';
            if ($paymentId!=='balance_'.$orderId) $this->db->execute(
                'INSERT INTO transactions(id,seq,user_id,type,amount_kopeks,description,payment_method,external_id,is_completed,created_at,completed_at) VALUES(?,?,?,?,?,?,?,?,1,?,?)',
                [Database::id(), $this->nextTxSeq(), $order['user_id'], $type, -$amount, 'Оплата заказа: '.$order['plan_name'], $provider, $paymentId, $now, $now]
            );
            if ($renewSub) {
                $base=max($now,(int)$renewSub['expires_at']);
                $newExpiry=$base+(int)$order['duration_days']*86400;
                $this->db->execute("UPDATE subscriptions SET expires_at=?,status='active',renew_order_id=NULL,renew_at=?,renew_failed_at=NULL,renew_fail_count=0 WHERE id=?",[$newExpiry,(int)$renewSub['auto_renew']===1?$newExpiry-max(1,min(14,(int)($this->config['AUTORENEW_DAYS_BEFORE']??3)))*86400:null,$renewSub['id']]);
                $this->outbox->enqueue('subscription.extend','extend:'.$renewSub['id'].':'.$orderId,['subscription_id'=>$renewSub['id']]);
                $this->audit('provider:'.$provider,'subscription.renewed',$renewSub['id']);
            } else {
                $sub=Database::id();
                // Each purchase is an independent subscription unless it is a renewal order.
                $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,traffic_limit_gb,device_limit) VALUES(?,?,?,'provisioning',?,?,?,?)",[$sub,$orderId,$order['user_id'],$now+(int)$order['duration_days']*86400,$now,(int)$order['traffic_bytes']/1073741824,(int)$order['devices']]);
                $this->outbox->enqueue('subscription.provision','provision:'.$sub,['subscription_id'=>$sub]);
            }
            $this->audit('provider:'.$provider,'payment.settled',$orderId);
        });
    }
    /** Personal discounts do not stack with landing discounts. */
    public function priceFor(string $userId,array $plan): int
    {
        $user=$this->db->one('SELECT promo_offer_discount_percent,promo_offer_discount_expires_at FROM users WHERE id=?',[$userId]);
        $price=(int)$plan['price_minor'];
        $percent=(int)($user['promo_offer_discount_percent']??0);
        $expires=$user['promo_offer_discount_expires_at']??null;
        if ($percent>0 && ($expires===null || (int)$expires>time())) {
            if ($percent>=100) throw new BillingError('Скидка 100% требует бесплатной выдачи подписки администратором.');
            return max(1,intdiv($price*(100-$percent),100));
        }
        return $price;
    }
    public function purchaseFromBalance(string $userId,string $planId,string $key): array
    {
        return $this->db->transaction(function() use ($userId,$planId,$key) {
            $order=$this->order($userId,$planId,$key);
            if (in_array($order['status'],['paid','fulfilled'],true) && $order['provider_payment_id']==='balance_'.$order['id']) return $order;
            if ($order['status']!=='pending' || $order['provider_payment_id']!==null) throw new BillingError('Заказ уже передан на оплату.');
            (new Wallet($this->db))->debit($userId,(int)$order['price_minor'],'subscription_purchase','Покупка подписки: '.$order['plan_name'],'balance',$order['id']);
            $this->settleFromBalance($order['id'],(int)$order['price_minor'],$order['currency']);
            return $this->db->one('SELECT * FROM orders WHERE id=?',[$order['id']]);
        });
    }
    /** Settle an order paid from the internal wallet balance (no provider payment). */
    public function settleFromBalance(string $orderId, int $amount, string $currency): void
    {
        $order = $this->db->one('SELECT provider FROM orders WHERE id=?', [$orderId]);
        $provider = $order['provider'] ?? 'balance';
        $this->settle($orderId, $provider, 'balance_' . $orderId, $amount, $currency);
    }
    /** Settle a balance topup after provider verification. Delegates to TopupService. */
    public function settleTopup(string $topupId, string $provider, string $paymentId, int $amount, string $currency): void
    {
        $this->topups?->settle($topupId, $provider, $paymentId, $amount, $currency);
    }
    public function setTopups(\App\Billing\TopupService $topups): void
    {
        $this->topups = $topups;
    }
    /** Enable or disable auto-renew for a subscription. Returns updated row. */
    public function setAutoRenew(string $userId,string $subscriptionId,bool $enable): array
    {
        return $this->db->transaction(function() use ($userId,$subscriptionId,$enable) {
            $sub=$this->db->one('SELECT s.*,o.plan_id,o.price_minor,o.duration_days FROM subscriptions s JOIN orders o ON o.id=s.order_id WHERE s.id=?'.$this->db->lock(),[$subscriptionId]);
            if (!$sub || $sub['user_id']!==$userId) throw new BillingError('Подписка не найдена.');
            if ($sub['status']!=='active' || (int)$sub['expires_at']<=time()) throw new BillingError('Автопродление доступно только для активной подписки.');
            if ($enable) {
                if (($this->config['AUTORENEW_ENABLED']??'0')!=='1') throw new BillingError('Автопродление отключено администратором.');
                $plan=$this->db->one('SELECT * FROM plans WHERE id=? AND active=1',[$sub['plan_id']]);
                if (!$plan) throw new BillingError('Тариф подписки больше недоступен.');
                $daysBefore=max(1,min(14,(int)($this->config['AUTORENEW_DAYS_BEFORE']??3)));
                $renewAt=(int)$sub['expires_at']-$daysBefore*86400;
                if ($renewAt<time()) $renewAt=time()+60;
                $this->db->execute('UPDATE subscriptions SET auto_renew=1,renew_plan_id=?,renew_price_minor=?,renew_at=?,renew_failed_at=NULL,renew_fail_count=0 WHERE id=?',[$plan['id'],(int)$plan['price_minor'],$renewAt,$subscriptionId]);
                $this->audit($userId,'subscription.autorenew_on',$subscriptionId);
            } else {
                $this->db->execute('UPDATE subscriptions SET auto_renew=0,renew_at=NULL WHERE id=?',[$subscriptionId]);
                $this->audit($userId,'subscription.autorenew_off',$subscriptionId);
            }
            return $this->db->one('SELECT * FROM subscriptions WHERE id=?',[$subscriptionId]);
        });
    }
    public function audit(string $actor,string $action,string $subject): void
    {
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',[Database::id(),$actor,$action,$subject,time()]);
    }
    private function nextTxSeq(): int
    {
        return (int)($this->db->one('SELECT COALESCE(MAX(seq),0)+1 AS s FROM transactions')['s'] ?? 1);
    }
}
