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
            return ['payment_id' => 'demo_'.$orderId, 'checkout_url' => '/orders/'.$orderId];
        }
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
            return ['payment_id' => 'demo_'.$topupId, 'checkout_url' => '/balance/topup/'.$topupId];
        }
        $provider = $this->registry->get($topup['provider']);
        $user = $this->db->one('SELECT * FROM users WHERE id=?', [$topup['user_id']]);
        $result = $provider->createTopup($topup, $user ?? []);
        $this->db->execute('UPDATE topups SET provider_payment_id=?,checkout_url=? WHERE id=? AND (provider_payment_id IS NULL OR provider_payment_id=?)', [$result['payment_id'], $result['checkout_url'], $topupId, $result['payment_id']]);
        return $result;
    }
    /** Verify a payment with the provider and settle if paid. */
    public function verify(string $paymentId): void
    {
        $topup = $this->db->one('SELECT * FROM topups WHERE provider_payment_id=?', [$paymentId]);
        if ($topup) {
            $this->verifyTopup($topup, $paymentId);
            return;
        }
        $order = $this->db->one('SELECT * FROM orders WHERE provider_payment_id=?', [$paymentId]);
        if ($order) {
            $this->verifyOrder($order, $paymentId);
            return;
        }
        // FreeKassa may reference by merchant order id
        $order = $this->db->one('SELECT * FROM orders WHERE id=?', [$paymentId]);
        if ($order && $order['provider'] === 'freekassa') {
            $this->verifyOrder($order, $paymentId);
            return;
        }
        $topup = $this->db->one('SELECT * FROM topups WHERE id=?', [$paymentId]);
        if ($topup && $topup['provider'] === 'freekassa') {
            $this->verifyTopup($topup, $paymentId);
            return;
        }
    }
    private function verifyOrder(array $order, string $paymentId): void
    {
        if ($order['provider'] === 'demo') return;
        $provider = $this->registry->get($order['provider']);
        $result = $provider->verify($paymentId);
        if (($result['status'] ?? '') === 'paid') {
            if (($this->config['APP_ENV'] ?? 'dev') === 'prod' && !empty($result['test'])) throw new BillingError('Тестовый платёж запрещён в production.');
            $this->billing->settle($order['id'], $order['provider'], $result['payment_id'], (int)$result['amount_kopeks'], $result['currency']);
        } elseif (($result['status'] ?? '') === 'canceled') {
            $this->db->execute("UPDATE orders SET status='canceled' WHERE id=? AND status='pending'", [$order['id']]);
        }
    }
    private function verifyTopup(array $topup, string $paymentId): void
    {
        if ($topup['provider'] === 'demo') return;
        $provider = $this->registry->get($topup['provider']);
        $result = $provider->verify($paymentId);
        if (($result['status'] ?? '') === 'paid') {
            if (($this->config['APP_ENV'] ?? 'dev') === 'prod' && !empty($result['test'])) throw new BillingError('Тестовый платёж запрещён в production.');
            $this->billing->settleTopup($topup['id'], $topup['provider'], $result['payment_id'], (int)$result['amount_kopeks'], $result['currency']);
        } elseif (($result['status'] ?? '') === 'canceled') {
            $this->db->execute("UPDATE topups SET status='canceled' WHERE id=? AND status='pending'", [$topup['id']]);
        }
    }
    /** Handle a provider webhook. Returns true if the webhook was ours. */
    public function handleWebhook(string $providerId, \Symfony\Component\HttpFoundation\Request $request): bool
    {
        if (!$this->registry->has($providerId)) return false;
        $provider = $this->registry->get($providerId);
        $result = $provider->handleWebhook($request);
        if ($result === null) return false;
        $paymentId = $result['payment_id'];
        $status = $result['status'];
        if ($status === 'paid') {
            $this->db->transaction(function () use ($paymentId) {
                $this->outboxEnqueueVerify($paymentId);
            });
        } elseif ($status === 'canceled') {
            $this->db->execute("UPDATE orders SET status='canceled' WHERE provider_payment_id=? AND status='pending'", [$paymentId]);
            $this->db->execute("UPDATE topups SET status='canceled' WHERE provider_payment_id=? AND status='pending'", [$paymentId]);
        }
        return true;
    }
    private function outboxEnqueueVerify(string $paymentId): void
    {
        // Reuse the outbox for authoritative verification (webhook is only a hint).
        $this->db->execute('INSERT INTO outbox(id,topic,dedup_key,payload,available_at,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(dedup_key) DO NOTHING', [
            Database::id(), 'payment.verify', 'verify:'.$paymentId, json_encode(['payment_id' => $paymentId], JSON_THROW_ON_ERROR), time(), time(),
        ]);
    }
}
