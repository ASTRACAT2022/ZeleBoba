<?php
declare(strict_types=1);
namespace App\Integration;
use App\Billing\{BillingError,BillingService};
use App\Infrastructure\Database;
use App\Integration\Payment\ProviderRegistry;
use Symfony\Contracts\HttpClient\HttpClientInterface;
/**
 * Unified payment service. Routes order/topup checkouts and verification
 * through the provider registry. Keeps the legacy Payments API for
 * compatibility while delegating to providers.
 */
final class PaymentService
{
    public function __construct(
        private Database $db,
        private BillingService $billing,
        private HttpClientInterface $http,
        private array $config,
        private ProviderRegistry $registry,
    ) {}
    public function registry(): ProviderRegistry { return $this->registry; }
    /** Create checkout for an order. Returns [payment_id, checkout_url]. */
    public function createOrder(string $orderId): array
    {
        $order = $this->db->one('SELECT * FROM orders WHERE id=?', [$orderId]);
        if (!$order || $order['status'] !== 'pending' || $order['checkout_url']) return ['payment_id' => $order['provider_payment_id'] ?? '', 'checkout_url' => $order['checkout_url'] ?? ''];
        if ($order['provider'] === 'demo') {
            if (($this->config['APP_ENV'] ?? 'dev') === 'prod') throw new BillingError('Демоплатёж запрещён.');
            $this->db->execute('UPDATE orders SET provider_payment_id=?,checkout_url=? WHERE id=?',['demo_'.$orderId,'/orders/'.$orderId,$orderId]);
            return ['payment_id' => 'demo_'.$orderId, 'checkout_url' => '/orders/'.$orderId];
        }
        $this->requireCheckoutSupport($order['provider']);
        $provider = $this->registry->get($order['provider']);
        $user = $this->db->one('SELECT * FROM users WHERE id=?', [$order['user_id']]);
        $result = $provider->createOrder($order, $user ?? []);
        $this->db->execute('UPDATE orders SET provider_payment_id=?,checkout_url=? WHERE id=? AND (provider_payment_id IS NULL OR provider_payment_id=?)', [$result['payment_id'], $result['checkout_url'], $orderId, $result['payment_id']]);
        return $result;
    }
    /** Create checkout for a topup. Returns [payment_id, checkout_url]. */
    public function createTopup(string $topupId): array
    {
        $topup = $this->db->one('SELECT * FROM topups WHERE id=?', [$topupId]);
        if (!$topup || $topup['status'] !== 'pending' || $topup['checkout_url']) return ['payment_id' => $topup['provider_payment_id'] ?? '', 'checkout_url' => $topup['checkout_url'] ?? ''];
        if ($topup['provider'] === 'demo') {
            if (($this->config['APP_ENV'] ?? 'dev') === 'prod') throw new BillingError('Демоплатёж запрещён.');
            $this->db->execute('UPDATE topups SET provider_payment_id=?,checkout_url=? WHERE id=?',['demo_'.$topupId,'/balance/topup/'.$topupId,$topupId]);
            return ['payment_id' => 'demo_'.$topupId, 'checkout_url' => '/balance/topup/'.$topupId];
        }
        $this->requireCheckoutSupport($topup['provider']);
        $provider = $this->registry->get($topup['provider']);
        $user = $this->db->one('SELECT * FROM users WHERE id=?', [$topup['user_id']]);
        $result = $provider->createTopup($topup, $user ?? []);
        $this->db->execute('UPDATE topups SET provider_payment_id=?,checkout_url=? WHERE id=? AND (provider_payment_id IS NULL OR provider_payment_id=?)', [$result['payment_id'], $result['checkout_url'], $topupId, $result['payment_id']]);
        return $result;
    }
    private function requireCheckoutSupport(string $provider): void
    {
        if (in_array($provider,['telegram_stars','tribute'],true)) throw new BillingError('Подтверждение платежей этого провайдера пока не реализовано.');
    }
    /** Verify a payment with the provider and settle if paid. */
    public function verify(string $paymentId, ?string $providerId=null): void
    {
        if ($paymentId==='' || strlen($paymentId)>100) throw new BillingError('Некорректный платёж.');
        $params=[$paymentId,$paymentId];
        $scope=$providerId!==null?' AND provider=?':'';
        if ($providerId!==null) $params[]=$providerId;
        $orders=$this->db->all("SELECT * FROM orders WHERE (provider_payment_id=? OR (provider='freekassa' AND id=?))".$scope,$params);
        $topups=$this->db->all("SELECT * FROM topups WHERE (provider_payment_id=? OR (provider='freekassa' AND id=?))".$scope,$params);
        if (count($orders)+count($topups)>1) throw new BillingError('Неоднозначная привязка платежа.');
        $entity=$orders[0]??$topups[0]??null;
        $providerId??=$entity['provider']??null;
        if ($providerId===null || $providerId==='demo') return;
        $result=$this->registry->get($providerId)->verify($paymentId);
        if (!in_array($result['status']??'',['paid','canceled'],true)) return;
        $metadata=$result['metadata']??[];
        // Recover a checkout that succeeded remotely before its id was stored locally.
        if (!$entity && in_array($providerId,['yookassa','freekassa'],true)) {
            $orderId=$metadata['order_id']??$metadata['merchant_order_id']??'';
            $topupId=$metadata['topup_id']??$metadata['merchant_order_id']??'';
            $order=$this->db->one('SELECT * FROM orders WHERE id=? AND provider=?',[$orderId,$providerId]);
            $topup=$this->db->one('SELECT * FROM topups WHERE id=? AND provider=?',[$topupId,$providerId]);
            if ($order && $topup) throw new BillingError('Неоднозначная привязка платежа.');
            $entity=$order??$topup;
            $orders=$order?[$order]:[];
        }
        if (!$entity) return;
        $isOrder=$orders!==[];
        $expected=$providerId==='freekassa'?($metadata['merchant_order_id']??null):($metadata[$isOrder?'order_id':'topup_id']??null);
        if (in_array($providerId,['yookassa','freekassa'],true) && $expected!==$entity['id']) throw new BillingError('Платёж относится к другому заказу.');
        if ($providerId==='cryptobot' && ($metadata['payload']??'')!==($isOrder?'order:':'topup:').$entity['id']) throw new BillingError('CryptoBot: неверная привязка счёта.');
        $account=$providerId==='yookassa'?($this->config['YOOKASSA_SHOP_ID']??''):($this->config['FREEKASSA_SHOP_ID']??'');
        if (isset($entity['provider_account']) && $entity['provider_account']!=='' && $entity['provider_account']!==$account) throw new BillingError('Несовпадение магазина.');
        $actualId=$result['payment_id']??'';
        if (!is_string($actualId) || $actualId==='' || strlen($actualId)>100 || ($entity['provider_payment_id']!==null && $entity['provider_payment_id']!==$actualId)) throw new BillingError('Несовпадение платежа.');
        if (($result['status']??'')==='paid') {
            if (($this->config['APP_ENV']??'dev')==='prod' && !empty($result['test'])) throw new BillingError('Тестовый платёж запрещён в production.');
            if ($isOrder) $this->billing->settle($entity['id'],$providerId,$actualId,(int)$result['amount_kopeks'],$result['currency']);
            else $this->billing->settleTopup($entity['id'],$providerId,$actualId,(int)$result['amount_kopeks'],$result['currency']);
        } elseif (($result['status']??'')==='canceled') {
            $table=$isOrder?'orders':'topups';
            $this->db->execute("UPDATE ".$table." SET status='canceled' WHERE id=? AND provider=? AND status='pending'",[$entity['id'],$providerId]);
        }
    }
    /** Handle a provider webhook. Returns true if the webhook was ours. */
    public function handleWebhook(string $providerId, \Symfony\Component\HttpFoundation\Request $request): bool
    {
        if (!$this->registry->has($providerId)) return false;
        $provider = $this->registry->get($providerId);
        // A webhook must never wake a retry loop for a provider whose own
        // verification credentials are absent. Bad JSON is an invalid
        // delivery, not an application error worthy of a 500 response.
        if (!$provider->configured()) return false;
        try {
            $result = $provider->handleWebhook($request);
        } catch (\Throwable) {
            return false;
        }
        if ($result === null) return false;
        $paymentId = $result['payment_id'];
        $status = $result['status'];
        if (!is_string($paymentId) || $paymentId==='' || strlen($paymentId)>100) return false;
        $known=$this->db->one('SELECT id FROM orders WHERE provider=? AND provider_payment_id=?',[$providerId,$paymentId])
            ??$this->db->one('SELECT id FROM topups WHERE provider=? AND provider_payment_id=?',[$providerId,$paymentId]);
        if (!$known) return false;
        if (in_array($status,['paid','canceled'],true)) {
            // Neither payment nor cancellation is trusted until fetched from this provider.
            $this->db->transaction(function () use ($paymentId,$providerId,$status) {
                $this->outboxEnqueueVerify($paymentId,$providerId,$status);
            });
        }
        return true;
    }
    private function outboxEnqueueVerify(string $paymentId,string $providerId,string $status): void
    {
        // Reuse the outbox for authoritative verification (webhook is only a hint).
        // payment.verify is the highest-priority job; hardcode 100 to match Outbox::PRIORITY.
        $this->db->execute('INSERT INTO outbox(id,topic,dedup_key,payload,priority,available_at,created_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING', [
            Database::id(), 'payment.verify', 'verify:'.$providerId.':'.$paymentId.':'.$status.':'.intdiv(time(),60), json_encode(['payment_id' => $paymentId,'provider'=>$providerId], JSON_THROW_ON_ERROR), 100, time(), time(),
        ]);
    }
}
