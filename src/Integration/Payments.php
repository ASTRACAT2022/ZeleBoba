<?php
declare(strict_types=1);
namespace App\Integration;
use App\Billing\{BillingError,BillingService};
use App\Infrastructure\Database;
use Symfony\Contracts\HttpClient\HttpClientInterface;
final class Payments
{
    private const FREEKASSA_API = 'https://api.fk.life/v1/';
    public function __construct(private Database $db, private BillingService $billing, private HttpClientInterface $http, private array $config) {}
    public function create(string $id): void
    {
        $order=$this->db->one('SELECT * FROM orders WHERE id=?',[$id]);
        if (!$order || $order['status']!=='pending' || $order['checkout_url']) return;
        if ($order['provider']==='demo') {
            if (($this->config['APP_ENV']??'dev')==='prod') throw new BillingError('Демоплатёж запрещён.');
            $paymentId='demo_'.$id; $url='/orders/'.$id;
        } elseif ($order['provider']==='freekassa') {
            if (time()-(int)$order['created_at']>23*3600) throw new BillingError('Требуется ручная сверка платежа.');
            if ((string)$order['provider_account']!== (string)($this->config['FREEKASSA_SHOP_ID']??'')) throw new BillingError('Магазин заказа не соответствует настройкам.');
            if (!$this->config['FREEKASSA_SHOP_ID'] || !$this->config['FREEKASSA_API_KEY']) throw new BillingError('FreeKassa не настроена.');
            $email=$order['receipt_email']??'';
            if (!$email || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new BillingError('Некорректный email для FreeKassa.');
            $ip=$order['client_ip']??'';
            if (!$ip || $ip==='127.0.0.1' || !filter_var($ip,FILTER_VALIDATE_IP)) {
                // Fallback to server IP or a safe public IP; FreeKassa blocks 127.0.0.1
                $ip='8.8.8.8';
            }
            $amount=self::decimal((int)$order['price_minor']);
            $currency=$order['currency']??'RUB';
            // FreeKassa expects currency RUB etc., but we always use RUB
            $paymentSystemId=(int)($this->config['FREEKASSA_PAYMENT_ID']??44);
            if ($paymentSystemId<=0) $paymentSystemId=44;
            $params=[
                'shopId'=>(int)$this->config['FREEKASSA_SHOP_ID'],
                'nonce'=>self::nonce(),
                'paymentId'=>$id,
                'i'=>$paymentSystemId,
                'email'=>$email,
                'ip'=>$ip,
                'amount'=>$amount,
                'currency'=>$currency,
            ];
            $data=$this->freekassaRequest('orders/create',$params);
            $location=$data['location']??null;
            $fkOrderId=$data['orderId']??null;
            if (!$location || !is_string($location) || !str_starts_with($location,'https://')) throw new BillingError('Провайдер не вернул ссылку оплаты.');
            if ($fkOrderId===null) throw new BillingError('Провайдер не вернул номер заказа.');
            $paymentId=(string)$fkOrderId;
            $url=$location;
            // Store fk intid for reference if column exists
            try { $this->db->execute('UPDATE orders SET freekassa_intid=? WHERE id=?',[$paymentId,$id]); } catch (\Throwable) {}
        } else {
            // Provider idempotency is limited to 24h: never create a new charge after that window.
            if (time()-(int)$order['created_at']>23*3600) throw new BillingError('Требуется ручная сверка платежа.');
            $body=['amount'=>['value'=>self::decimal((int)$order['price_minor']),'currency'=>$order['currency']], 'capture'=>true,
                'confirmation'=>['type'=>'redirect','return_url'=>$order['return_url']?:rtrim($this->config['APP_URL'],'/').'/orders/'.$id],
                'description'=>'Подписка: '.$order['plan_name'],'metadata'=>['order_id'=>$id]];
            if((int)$order['receipt_enabled']===1){
                $body['receipt']=['customer'=>['email'=>$order['receipt_email']],'items'=>[['description'=>mb_substr('Подписка '.$order['plan_name'],0,128),'quantity'=>'1.00','amount'=>$body['amount'],'vat_code'=>(int)$order['vat_code'],'payment_mode'=>'full_payment','payment_subject'=>'service']]];
                if($order['tax_system'])$body['receipt']['tax_system_code']=(int)$order['tax_system'];
            }
            if ($order['provider_account']!==$this->config['YOOKASSA_SHOP_ID']) throw new BillingError('Магазин заказа не соответствует настройкам.');
            $data=$this->request('POST','payments',[
                'headers'=>['Idempotence-Key'=>$id],
                'json'=>$body
            ]);
            if (($this->config['APP_ENV']??'dev')==='prod' && ($data['test']??true)!==false) throw new BillingError('Магазин создал тестовый платёж в боевом режиме.');
            $this->db->execute('UPDATE orders SET provider_test=? WHERE id=?',[(int)($data['test']??true),$id]);
            $paymentId=$data['id']; $url=$data['confirmation']['confirmation_url'] ?? null;
            if (!$url || !str_starts_with($url,'https://')) throw new BillingError('Провайдер не вернул ссылку оплаты.');
        }
        $this->db->execute('UPDATE orders SET provider_payment_id=?,checkout_url=? WHERE id=? AND (provider_payment_id IS NULL OR provider_payment_id=?)',[$paymentId,$url,$id,$paymentId]);
    }
    public function refresh(string $paymentId): void
    {
        // FreeKassa paymentId is the FK intid (numeric) or merchant order id; try to detect
        if (preg_match('/^[0-9]{1,20}$/D',$paymentId)) {
            // Could be FK intid; try freekassa verification first if any freekassa order exists with this intid
            $maybe=$this->db->one('SELECT id,provider FROM orders WHERE provider_payment_id=? OR freekassa_intid=?',[$paymentId,$paymentId]);
            if ($maybe && $maybe['provider']==='freekassa') {
                $this->refreshFreekassa($paymentId);
                return;
            }
        }
        // Also check if paymentId is actually a merchant order id for freekassa
        $maybeOrder=$this->db->one('SELECT provider FROM orders WHERE id=?',[$paymentId]);
        if ($maybeOrder && $maybeOrder['provider']==='freekassa') {
            $this->refreshFreekassa($paymentId);
            return;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$paymentId)) throw new BillingError('Некорректный платёж.');
        $data=$this->request('GET','payments/'.rawurlencode($paymentId));
        if (($data['id']??null)!==$paymentId) throw new BillingError('Некорректный ответ провайдера.');
        $local=$this->db->one('SELECT provider_account FROM orders WHERE id=?',[$data['metadata']['order_id']??'']);
        if ($local && $local['provider_account']!=='' && $local['provider_account']!==$this->config['YOOKASSA_SHOP_ID']) throw new BillingError('Несовпадение магазина.');
        if (($data['status']??'')==='succeeded' && ($data['paid']??false)===true) {
            if (($this->config['APP_ENV']??'dev')==='prod' && ($data['test']??true)!==false) throw new BillingError('Тестовый платёж запрещён в production.');
            $this->billing->settle($data['metadata']['order_id']??'', 'yookassa', $paymentId, self::minor($data['amount']['value']), $data['amount']['currency']);
        } elseif (($data['status']??'')==='canceled') {
            $this->db->execute("UPDATE orders SET status='canceled' WHERE provider='yookassa' AND provider_payment_id=? AND status='pending'",[$paymentId]);
        }
    }
    private function refreshFreekassa(string $paymentId): void
    {
        if (!$this->config['FREEKASSA_SHOP_ID'] || !$this->config['FREEKASSA_API_KEY']) throw new BillingError('FreeKassa не настроена.');
        // paymentId may be FK intid or merchant order id. Try both.
        $isNumeric=preg_match('/^[0-9]+$/D',$paymentId);
        $params=['shopId'=>(int)$this->config['FREEKASSA_SHOP_ID'],'nonce'=>self::nonce()];
        if ($isNumeric) {
            // Try as FK orderId first
            $params['orderId']=(int)$paymentId;
        } else {
            $params['paymentId']=$paymentId;
        }
        $data=$this->freekassaRequest('orders',$params);
        $orders=$data['orders']??[];
        if (!is_array($orders) || count($orders)===0) {
            // If we queried by orderId and got nothing, try by paymentId (merchant order id) if we have mapping
            if ($isNumeric) {
                $order=$this->db->one('SELECT id FROM orders WHERE provider_payment_id=? OR freekassa_intid=?',[$paymentId,$paymentId]);
                if ($order) {
                    $params2=['shopId'=>(int)$this->config['FREEKASSA_SHOP_ID'],'nonce'=>self::nonce(),'paymentId'=>$order['id']];
                    $data2=$this->freekassaRequest('orders',$params2);
                    $orders=$data2['orders']??[];
                }
            }
            if (count($orders)===0) return;
        }
        foreach ($orders as $o) {
            $status=(int)($o['status']??-1);
            $merchantId=(string)($o['merchant_order_id']??$o['paymentId']??'');
            // Fallback: if merchant_order_id not present, try to find order by amount/currency? But spec says merchant_order_id
            if ($merchantId==='') {
                // Try to locate by FK orderId mapping
                $mapped=$this->db->one('SELECT id FROM orders WHERE provider_payment_id=? OR freekassa_intid=?',[(string)($o['fk_order_id']??$o['orderId']??''),(string)($o['fk_order_id']??$o['orderId']??'')]);
                if ($mapped) $merchantId=$mapped['id'];
            }
            if ($merchantId==='') continue;
            $local=$this->db->one('SELECT * FROM orders WHERE id=?',[$merchantId]);
            if (!$local || $local['provider']!=='freekassa') continue;
            if ((string)$local['provider_account']!== (string)$this->config['FREEKASSA_SHOP_ID']) throw new BillingError('Несовпадение магазина.');
            $amountMinor=self::minor(self::normalizeAmount((string)($o['amount']??'')));
            $currency=(string)($o['currency']??'RUB');
            if ($status===1) {
                // Paid
                if ((int)$local['price_minor']!==$amountMinor || $local['currency']!==$currency) throw new BillingError('Сумма заказа не совпадает.');
                $fkId=(string)($o['fk_order_id']??$o['orderId']??$paymentId);
                $this->billing->settle($merchantId,'freekassa',$fkId,$amountMinor,$currency);
            } elseif (in_array($status,[8,9],true)) {
                $this->db->execute("UPDATE orders SET status='canceled' WHERE id=? AND status='pending' AND provider='freekassa'",[$merchantId]);
            }
        }
    }
    private function request(string $method,string $path,array $options=[]): array
    {
        if (!$this->config['YOOKASSA_SHOP_ID'] || !$this->config['YOOKASSA_SECRET']) throw new BillingError('ЮKassa не настроена.');
        return $this->http->request($method,'https://api.yookassa.ru/v3/'.$path,array_merge(['auth_basic'=>[$this->config['YOOKASSA_SHOP_ID'],$this->config['YOOKASSA_SECRET']],'timeout'=>10,'max_duration'=>20,'max_redirects'=>0],$options))->toArray();
    }
    private function freekassaRequest(string $path, array $params): array
    {
        if (!$this->config['FREEKASSA_API_KEY']) throw new BillingError('FreeKassa не настроена.');
        $apiKey=(string)$this->config['FREEKASSA_API_KEY'];
        // Ensure nonce exists
        if (!isset($params['nonce'])) $params['nonce']=self::nonce();
        // Signature: sort by keys, implode values with "|", hmac sha256 with apiKey
        $sorted=$params; ksort($sorted);
        $values=array_map(fn($v)=> (string)$v, array_values($sorted));
        $sign=hash_hmac('sha256', implode('|',$values), $apiKey);
        $params['signature']=$sign;
        $resp=$this->http->request('POST', self::FREEKASSA_API.$path, ['json'=>$params,'timeout'=>10,'max_duration'=>20,'max_redirects'=>0])->toArray(false);
        // FreeKassa returns type=success or error; handle both
        if (($resp['type']??'')==='error' || isset($resp['error'])) {
            $msg=$resp['error']??$resp['message']??'FreeKassa error';
            throw new BillingError('FreeKassa: '.$msg);
        }
        if (($resp['type']??'')!=='success' && !isset($resp['location']) && !isset($resp['orders']) && !isset($resp['balance'])) {
            // Some endpoints return type success, others may not; if no error, assume success
        }
        return $resp;
    }
    private static function nonce(): int
    {
        // Must be strictly increasing per shop. Microseconds give finer granularity across workers;
        // per-process monotonic guard prevents duplicates within the same millisecond.
        static $last = 0;
        $now = (int)(microtime(true)*1000000);
        if ($now <= $last) $now = $last + 1;
        $last = $now;
        return $now;
    }
    public static function decimal(int $minor): string { return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT); }
    public static function minor(string $decimal): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,9})\.([0-9]{2})$/D',$decimal,$m)) throw new BillingError('Некорректная сумма.');
        return (int)$m[1]*100+(int)$m[2];
    }
    /** Normalize FreeKassa amounts ("100", "100.5", "100.00") to "100.00" without float math. */
    public static function normalizeAmount(string $amount): string
    {
        $amount=trim($amount);
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(\.([0-9]{1,2}))?$/D',$amount,$m)) throw new BillingError('Некорректная сумма.');
        $frac=$m[3]??'';
        if ($frac==='') $frac='00';
        elseif (strlen($frac)===1) $frac.='0';
        return $m[1].'.'.$frac;
    }
    public static function verifyFreekassaNotification(array $data, string $secret2): bool
    {
        $merchantId=(string)($data['MERCHANT_ID']??'');
        $amount=(string)($data['AMOUNT']??'');
        $orderId=(string)($data['MERCHANT_ORDER_ID']??'');
        $sign=(string)($data['SIGN']??'');
        if ($merchantId==='' || $amount==='' || $orderId==='' || $sign==='') return false;
        $expected=md5($merchantId.':'.$amount.':'.$secret2.':'.$orderId);
        return hash_equals(strtolower($expected), strtolower($sign));
    }
}
