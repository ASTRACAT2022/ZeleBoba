<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class HeleketProvider extends AbstractProvider
{
    public function id(): string { return 'heleket'; }
    public function name(): string { return 'Heleket'; }
    public function configured(): bool
    {
        return ($this->config['HELEKET_ENABLED'] ?? '0') === '1'
            && ($this->config['HELEKET_MERCHANT_ID'] ?? '') !== ''
            && ($this->config['HELEKET_API_KEY'] ?? '') !== '';
    }
    private function api(string $endpoint, array $data = []): array
    {
        $merchant = (string)($this->config['HELEKET_MERCHANT_ID'] ?? '');
        $key = (string)($this->config['HELEKET_API_KEY'] ?? '');
        if ($merchant === '' || $key === '') throw new BillingError('Heleket не настроен.');
        $resp = $this->json('POST', 'https://api.heleket.com/v1/'.$endpoint, [
            'headers' => ['X-Merchant-Id' => $merchant, 'X-Api-Key' => $key, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
        if (($resp['status'] ?? '') !== 'success' && ($resp['success'] ?? false) !== true) throw new BillingError('Heleket: '.($resp['message'] ?? 'API error'));
        return $resp['data'] ?? $resp;
    }
    public function createTopup(array $topup, array $user): array
    {
        $result = $this->api('payment', [
            'amount' => self::decimal((int)$topup['amount_kopeks']),
            'currency' => 'RUB',
            'order_id' => $topup['id'],
            'description' => 'Пополнение баланса',
            'url_callback' => rtrim($this->config['APP_URL'] ?? '', '/').'/webhooks/heleket',
            'url_return' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
            'url_success' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Heleket не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $result = $this->api('payment', [
            'amount' => self::decimal((int)$order['price_minor']),
            'currency' => 'RUB',
            'order_id' => $order['id'],
            'description' => 'Подписка: '.$order['plan_name'],
            'url_callback' => rtrim($this->config['APP_URL'] ?? '', '/').'/webhooks/heleket',
            'url_return' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
            'url_success' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('Heleket не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('payment/'.$paymentId);
        $status = (string)($result['status'] ?? '');
        $amount = self::minor(self::normalizeAmount((string)($result['amount'] ?? '0')));
        return [
            'status' => $status === 'paid' ? 'paid' : ($status === 'canceled' ? 'canceled' : 'pending'),
            'amount_kopeks' => $amount,
            'currency' => 'RUB',
            'payment_id' => $paymentId,
            'metadata' => ['order_id' => (string)($result['order_id'] ?? '')],
        ];
    }
    public function handleWebhook(Request $request): ?array
    {
        $data = $request->toArray();
        $paymentId = (string)($data['id'] ?? $data['payment_id'] ?? '');
        if ($paymentId === '') return null;
        $status = (string)($data['status'] ?? '');
        return ['payment_id' => $paymentId, 'status' => $status === 'paid' ? 'paid' : 'pending'];
    }
}
