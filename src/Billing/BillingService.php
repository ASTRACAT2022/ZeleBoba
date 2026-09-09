<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class BillingService
{
    public function __construct(private Database $db, private Outbox $outbox, private string $provider, private ?array $config=null) {}
    public function order(string $userId, string $planId, string $key, ?string $receiptEmail=null, ?string $clientIp=null): array
    {
        if ($this->config!==null) {
            if ($this->config['PURCHASES_ENABLED']!=='1') throw new BillingError('Покупки временно приостановлены.');
            foreach(\App\Settings\Settings::purchaseErrors($this->config) as $error) throw new BillingError($error);
        }
        if (!preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D',$key)) throw new BillingError('Некорректный ключ операции.');
        return $this->db->transaction(function () use ($userId,$planId,$key,$receiptEmail,$clientIp) {
            if (!$this->db->one('SELECT id FROM users WHERE id=? AND disabled=0'.$this->db->lock(),[$userId])) throw new BillingError('Аккаунт не найден.');
            $existing=$this->db->one('SELECT * FROM orders WHERE user_id=? AND idempotency_key=?',[$userId,$key]);
            if ($existing) {
                if ($existing['plan_id']!==$planId) throw new BillingError('Этот ключ уже использован для другого тарифа.');
                return $existing;
            }
            $plan=$this->db->one('SELECT * FROM plans WHERE id=? AND active=1',[$planId]);
            if (!$plan) throw new BillingError('Тариф недоступен.');
            $user=$this->db->one('SELECT email,telegram_id FROM users WHERE id=?',[$userId]);
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
            return $this->db->one('SELECT * FROM orders WHERE id=?',[$id]);
        });
    }
    /** Values must originate from a verified provider API, never browser/webhook claims. */
    public function settle(string $orderId,string $provider,string $paymentId,int $amount,string $currency): void
    {
        $this->db->transaction(function () use ($orderId,$provider,$paymentId,$amount,$currency) {
            $order=$this->db->one('SELECT * FROM orders WHERE id=?'.$this->db->lock(),[$orderId]);
            if (!$order || $order['provider']!==$provider || (int)$order['price_minor']!==$amount || $order['currency']!==$currency || ($order['provider_payment_id']!==null && $order['provider_payment_id']!==$paymentId)) throw new BillingError('Платёж не соответствует заказу.');
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
            $sub=Database::id();
            // Each purchase is an independent subscription; explicit renewal is a later capability.
            $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at) VALUES(?,?,?,'provisioning',?,?)",[$sub,$orderId,$order['user_id'],$now+(int)$order['duration_days']*86400,$now]);
            $this->outbox->enqueue('subscription.provision','provision:'.$sub,['subscription_id'=>$sub]);
            $this->audit('provider:'.$provider,'payment.settled',$orderId);
        });
    }
    public function audit(string $actor,string $action,string $subject): void
    {
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',[Database::id(),$actor,$action,$subject,time()]);
    }
}
