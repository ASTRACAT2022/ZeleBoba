<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class Pal24Provider extends AbstractProvider
{
    public function id(): string { return 'pal24'; }
    public function name(): string { return 'PayPalych (Pal24)'; }
    public function configured(): bool
    {
        return ($this->config['PAL24_ENABLED'] ?? '0') === '1'
            && ($this->config['PAL24_API_TOKEN'] ?? '') !== ''
            && ($this->config['PAL24_SHOP_ID'] ?? '') !== '';
    }
    private function baseUrl(): string
    {
        return rtrim($this->config['PAL24_BASE_URL'] ?? 'https://pal24.pro/api/v1/', '/').'/';
    }
    private function api(string $method, string $endpoint, array $data = [], array $params = []): array
    {
        $token = (string)($this->config['PAL24_API_TOKEN'] ?? '');
        if ($token === '') throw new BillingError('Pal24 не настроен.');
        $options = ['headers' => ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json', 'Accept' => 'application/json']];
        if ($data !== []) $options['json'] = $data;
        if ($params !== []) $options['query'] = $params;
        $resp = $this->json($method, $this->baseUrl().$endpoint, $options);
        if (!($resp['success'] ?? false)) throw new BillingError('Pal24: '.($resp['message'] ?? $resp['error'] ?? 'API error'));
        return $resp;
    }
    public function createTopup(array $topup, array $user): array
    {
        $result = $this->api('POST', 'bill/create', [
            'amount' => self::decimal((int)$topup['amount_kopeks']),
            'shop_id' => (string)$this->config['PAL24_SHOP_ID'],
            'order_id' => $topup['id'],
            'description' => 'Пополнение баланса',
            'currency_in' => 'RUB',
            'type' => 'normal',
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Pal24 не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $result['bill_id'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $result = $this->api('POST', 'bill/create', [
            'amount' => self::decimal((int)$order['price_minor']),
            'shop_id' => (string)$this->config['PAL24_SHOP_ID'],
            'order_id' => $order['id'],
            'description' => 'Подписка: '.$order['plan_name'],
            'currency_in' => 'RUB',
            'type' => 'normal',
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Pal24 не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $result['bill_id'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('GET', 'bill/status', [], ['id' => $paymentId]);
        $status = (string)($result['status'] ?? '');
        $amount = self::minor(self::normalizeAmount((string)($result['amount'] ?? '0')));
        return [
            'status' => in_array($status, ['paid', 'success', 'completed'], true) ? 'paid' : (in_array($status, ['canceled', 'expired', 'failed'], true) ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => 'RUB',
            'payment_id' => $paymentId,
            'metadata' => [],
        ];
    }
    public function handleWebhook(Request $request): ?array
    {
        $data = $request->toArray();
        $paymentId = (string)($data['id'] ?? $data['bill_id'] ?? '');
        if ($paymentId === '') return null;
        $status = (string)($data['status'] ?? '');
        return ['payment_id' => $paymentId, 'status' => in_array($status, ['paid', 'success'], true) ? 'paid' : 'pending'];
    }
}
