<?php
declare(strict_types=1);
namespace App\Integration;
use App\Billing\{BillingError,BillingService};
use App\Infrastructure\Database;
use Symfony\Contracts\HttpClient\HttpClientInterface;
final class Payments
{
    public function __construct(private Database $db, private BillingService $billing, private HttpClientInterface $http, private array $config) {}
    /** Create a checkout for a balance topup. Mirrors order checkout but for the topups table. */
    public function createTopup(array $topup): void
    {
        if ($topup['status']!=='pending' || $topup['checkout_url']) return;
        $id=$topup['id'];
        if ($topup['provider']==='demo') {
            if (($this->config['APP_ENV']??'dev')==='prod') throw new BillingError('Демоплатёж запрещён.');
            $paymentId='demo_'.$id; $url='/balance/topup/'.$id;
        } else {
            if (time()-(int)$topup['created_at']>23*3600) throw new BillingError('Требуется ручная сверка платежа.');
            $body=['amount'=>['value'=>self::decimal((int)$topup['amount_kopeks']),'currency'=>$topup['currency']], 'capture'=>true,
                'confirmation'=>['type'=>'redirect','return_url'=>rtrim($this->config['APP_URL']??'http://127.0.0.1:8080','/').'/balance'],
                'description'=>'Пополнение баланса','metadata'=>['topup_id'=>$id,'type'=>'balance_topup']];
            $data=$this->request('POST','payments',['headers'=>['Idempotence-Key'=>'topup-'.$id],'json'=>$body]);
            if (($this->config['APP_ENV']??'dev')==='prod' && ($data['test']??true)!==false) throw new BillingError('Магазин создал тестовый платёж в боевом режиме.');
            $paymentId=$data['id']; $url=$data['confirmation']['confirmation_url'] ?? null;
            if (!$url || !str_starts_with($url,'https://')) throw new BillingError('Провайдер не вернул ссылку оплаты.');
        }
        $this->db->execute('UPDATE topups SET provider_payment_id=?,checkout_url=? WHERE id=? AND (provider_payment_id IS NULL OR provider_payment_id=?)',[$paymentId,$url,$id,$paymentId]);
    }
    public function create(string $id): void
    {        $order=$this->db->one('SELECT * FROM orders WHERE id=?',[$id]);
        if (!$order || $order['status']!=='pending' || $order['checkout_url']) return;
        if ($order['provider']==='demo') {
            if (($this->config['APP_ENV']??'dev')==='prod') throw new BillingError('Демоплатёж запрещён.');
            $paymentId='demo_'.$id; $url='/orders/'.$id;
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
        // Topup payments carry topup_id in metadata
        $topup=$this->db->one('SELECT * FROM topups WHERE provider_payment_id=?',[$paymentId]);
        if ($topup) {
            $this->refreshTopup($topup,$paymentId);
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
    private function refreshTopup(array $topup, string $paymentId): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$paymentId)) throw new BillingError('Некорректный платёж.');
        $data=$this->request('GET','payments/'.rawurlencode($paymentId));
        if (($data['id']??null)!==$paymentId) throw new BillingError('Некорректный ответ провайдера.');
        if (($data['status']??'')==='succeeded' && ($data['paid']??false)===true) {
            if (($this->config['APP_ENV']??'dev')==='prod' && ($data['test']??true)!==false) throw new BillingError('Тестовый платёж запрещён в production.');
            $this->topupSettle($topup,$paymentId,$data['amount']['value']??null,$data['amount']['currency']??null);
        } elseif (($data['status']??'')==='canceled') {
            $this->db->execute("UPDATE topups SET status='canceled' WHERE id=? AND status='pending'",[$topup['id']]);
        }
    }
    private function topupSettle(array $topup, string $paymentId, ?string $amountValue, ?string $currency): void
    {
        $amount=(int)$topup['amount_kopeks'];
        if ($amountValue!==null) $amount=self::minor($amountValue);
        $cur=$currency??$topup['currency'];
        $this->billing->settleTopup($topup['id'],$topup['provider'],$paymentId,$amount,$cur);
    }
    private function request(string $method,string $path,array $options=[]): array
    {
        if (!$this->config['YOOKASSA_SHOP_ID'] || !$this->config['YOOKASSA_SECRET']) throw new BillingError('ЮKassa не настроена.');
        return $this->http->request($method,'https://api.yookassa.ru/v3/'.$path,array_merge(['auth_basic'=>[$this->config['YOOKASSA_SHOP_ID'],$this->config['YOOKASSA_SECRET']],'timeout'=>10,'max_duration'=>20,'max_redirects'=>0],$options))->toArray();
    }
    public static function decimal(int $minor): string { return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT); }
    public static function minor(string $decimal): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,9})\.([0-9]{2})$/D',$decimal,$m)) throw new BillingError('Некорректная сумма.');
        return (int)$m[1]*100+(int)$m[2];
    }
}
