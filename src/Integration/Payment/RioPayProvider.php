<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class RioPayProvider extends AbstractProvider
{
    public function id(): string { return 'riopay'; }
    public function name(): string { return 'RioPay'; }
    public function configured(): bool
    {
        return ($this->config['RIOPAY_ENABLED'] ?? '0') === '1'
            && ($this->config['RIOPAY_API_TOKEN'] ?? '') !== '';
    }
    private function api(string $endpoint, array $data = []): array
    {
        $token = (string)($this->config['RIOPAY_API_TOKEN'] ?? '');
        if ($token === '') throw new BillingError('RioPay не настроен.');
        $resp = $this->json('POST', 'https://api.riopay.online/'.$endpoint, [
            'headers' => ['x-api-token' => $token, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
        if (($resp['status'] ?? '') !== 'success' && ($resp['success'] ?? false) !== true) throw new BillingError('RioPay: '.($resp['message'] ?? 'API error'));
        return $resp['data'] ?? $resp;
    }
    public function createTopup(array $topup, array $user): array
    {
        $result = $this->api('payment/create', [
            'amount' => self::decimal((int)$topup['amount_kopeks']),
            'currency' => 'RUB',
            'order_id' => $topup['id'],
            'description' => 'Пополнение баланса',
            'success_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
            'fail_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('RioPay не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $result = $this->api('payment/create', [
            'amount' => self::decimal((int)$order['price_minor']),
            'currency' => 'RUB',
            'order_id' => $order['id'],
            'description' => 'Подписка: '.$order['plan_name'],
            'success_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
            'fail_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('RioPay не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('payment/status', ['id' => $paymentId]);
        $status = (string)($result['status'] ?? '');
        $amount = self::minor(self::normalizeAmount((string)($result['amount'] ?? '0')));
        return [
            'status' => $status === 'COMPLETED' ? 'paid' : (in_array($status, ['CANCELED', 'FAILED', 'EXPIRED'], true) ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => 'RUB',
            'payment_id' => $paymentId,
            'metadata' => [],
        ];
    }
    public function handleWebhook(Request $request): ?array
    {
        $data = $request->toArray();
        $paymentId = (string)($data['id'] ?? $data['payment_id'] ?? '');
        if ($paymentId === '') return null;
        $status = (string)($data['status'] ?? '');
        return ['payment_id' => $paymentId, 'status' => $status === 'COMPLETED' ? 'paid' : 'pending'];
    }
}
