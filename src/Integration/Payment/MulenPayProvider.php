<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class MulenPayProvider extends AbstractProvider
{
    public function id(): string { return 'mulenpay'; }
    public function name(): string { return 'MulenPay'; }
    public function configured(): bool
    {
        return ($this->config['MULENPAY_ENABLED'] ?? '0') === '1'
            && ($this->config['MULENPAY_API_KEY'] ?? '') !== ''
            && ($this->config['MULENPAY_SECRET_KEY'] ?? '') !== ''
            && ($this->config['MULENPAY_SHOP_ID'] ?? '') !== '';
    }
    private function baseUrl(): string
    {
        return rtrim($this->config['MULENPAY_BASE_URL'] ?? 'https://mulenpay.ru/api', '/');
    }
    private function sign(string $currency, string $amount): string
    {
        return sha1($currency.$amount.$this->config['MULENPAY_SHOP_ID'].$this->config['MULENPAY_SECRET_KEY']);
    }
    private function api(string $method, string $path, array $data = []): array
    {
        $key = (string)($this->config['MULENPAY_API_KEY'] ?? '');
        if ($key === '') throw new BillingError('MulenPay не настроен.');
        $resp = $this->json($method, $this->baseUrl().$path, [
            'headers' => ['Authorization' => 'Bearer '.$key, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
        if (!($resp['success'] ?? false)) throw new BillingError('MulenPay: '.($resp['message'] ?? 'API error'));
        return $resp;
    }
    public function createTopup(array $topup, array $user): array
    {
        $amount = self::decimal((int)$topup['amount_kopeks']);
        $result = $this->api('POST', '/v2/payments', [
            'currency' => 'rub',
            'amount' => $amount,
            'uuid' => $topup['id'],
            'shopId' => (int)$this->config['MULENPAY_SHOP_ID'],
            'description' => 'Пополнение баланса',
            'items' => [['name' => 'Пополнение баланса', 'price' => $amount, 'quantity' => 1]],
            'language' => 'ru',
            'sign' => $this->sign('rub', $amount),
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('MulenPay не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $amount = self::decimal((int)$order['price_minor']);
        $result = $this->api('POST', '/v2/payments', [
            'currency' => 'rub',
            'amount' => $amount,
            'uuid' => $order['id'],
            'shopId' => (int)$this->config['MULENPAY_SHOP_ID'],
            'description' => 'Подписка: '.$order['plan_name'],
            'items' => [['name' => 'Подписка: '.$order['plan_name'], 'price' => $amount, 'quantity' => 1]],
            'language' => 'ru',
            'sign' => $this->sign('rub', $amount),
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('MulenPay не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('GET', '/v2/payments/'.rawurlencode($paymentId));
        $status = (int)($result['status'] ?? 0);
        $amount = self::minor(self::normalizeAmount((string)($result['amount'] ?? '0')));
        return [
            'status' => $status === 1 ? 'paid' : ($status === 3 ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => 'RUB',
            'payment_id' => $paymentId,
            'metadata' => ['uuid' => (string)($result['uuid'] ?? '')],
        ];
    }
    public function handleWebhook(Request $request): ?array
    {
        $data = $request->toArray();
        $paymentId = (string)($data['id'] ?? $data['payment_id'] ?? '');
        if ($paymentId === '') return null;
        $status = (int)($data['status'] ?? 0);
        return ['payment_id' => $paymentId, 'status' => $status === 1 ? 'paid' : 'pending'];
    }
}
