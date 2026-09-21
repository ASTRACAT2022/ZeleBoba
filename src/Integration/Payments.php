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
            throw new BillingError('Платёжный провайдер не поддерживается в этом канале выдачи.');
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
            throw new BillingError('Платёжный провайдер не поддерживается в этом канале выдачи.');
        }
        $this->db->execute('UPDATE orders SET provider_payment_id=?,checkout_url=? WHERE id=? AND (provider_payment_id IS NULL OR provider_payment_id=?)',[$paymentId,$url,$id,$paymentId]);
    }
    public function refresh(string $paymentId): void
    {
        // Balance topups carry provider_payment_id; refresh is delegated to
        // PaymentService (Platega). This legacy path only supports demo now.
        $topup=$this->db->one('SELECT * FROM topups WHERE provider_payment_id=?',[$paymentId]);
        if ($topup) { $this->refreshTopup($topup,$paymentId); return; }
        $order=$this->db->one('SELECT * FROM orders WHERE provider_payment_id=?',[$paymentId]);
        if ($order && $order['provider']==='demo') { $this->billing->settle($order['id'],'demo',$paymentId,(int)$order['price_minor'],$order['currency']); return; }
        throw new BillingError('Платёжный провайдер не поддерживается в этом канале выдачи.');
    }
    private function refreshTopup(array $topup, string $paymentId): void
    {
        if ($topup['provider']==='demo') { $this->topupSettle($topup,$paymentId,null,null); return; }
        throw new BillingError('Платёжный провайдер не поддерживается в этом канале выдачи.');
    }
    private function topupSettle(array $topup, string $paymentId, ?string $amountValue, ?string $currency): void
    {
        $amount=(int)$topup['amount_kopeks'];
        if ($amountValue!==null) $amount=self::minor($amountValue);
        $cur=$currency??$topup['currency'];
        $this->billing->settleTopup($topup['id'],$topup['provider'],$paymentId,$amount,$cur);
    }
    public static function decimal(int $minor): string { return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT); }
    public static function minor(string $decimal): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,9})\.([0-9]{2})$/D',$decimal,$m)) throw new BillingError('Некорректная сумма.');
        return (int)$m[1]*100+(int)$m[2];
    }
}
