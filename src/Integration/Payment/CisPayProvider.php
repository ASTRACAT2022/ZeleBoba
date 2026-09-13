<?php
declare(strict_types=1);
namespace App\Integration\Payment;
use App\Billing\BillingError;
use Symfony\Component\HttpFoundation\Request;
final class CisPayProvider extends AbstractProvider
{
    public function id(): string { return 'cispay'; }
    public function name(): string { return 'CisPay'; }
    public function configured(): bool
    {
        return ($this->config['CISPAY_ENABLED'] ?? '0') === '1'
            && ($this->config['CISPAY_SHOP_ID'] ?? '') !== ''
            && ($this->config['CISPAY_API_KEY'] ?? '') !== '';
    }
    private function baseUrl(): string
    {
        return rtrim($this->config['CISPAY_BASE_URL'] ?? 'https://api.cispay.app', '/');
    }
    private function api(string $endpoint, array $data = []): array
    {
        $shopId = (string)($this->config['CISPAY_SHOP_ID'] ?? '');
        $key = (string)($this->config['CISPAY_API_KEY'] ?? '');
        if ($shopId === '' || $key === '') throw new BillingError('CisPay не настроен.');
        $resp = $this->json('POST', $this->baseUrl().'/'.$endpoint, [
            'headers' => ['X-Shop-ID' => $shopId, 'X-Api-Key' => $key, 'Content-Type' => 'application/json'],
            'json' => $data,
        ]);
        if (($resp['status'] ?? '') !== 'success' && ($resp['success'] ?? false) !== true) throw new BillingError('CisPay: '.($resp['message'] ?? 'API error'));
        return $resp['data'] ?? $resp;
    }
    public function createTopup(array $topup, array $user): array
    {
        $result = $this->api('payment/create', [
            'amount' => self::decimal((int)$topup['amount_kopeks']),
            'currency' => 'RUB',
            'order_id' => $topup['id'],
            'description' => 'Пополнение баланса',
            'return_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/balance',
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('CisPay не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $topup['id']), 'checkout_url' => $url];
    }
    public function createOrder(array $order, array $user): array
    {
        $result = $this->api('payment/create', [
            'amount' => self::decimal((int)$order['price_minor']),
            'currency' => 'RUB',
            'order_id' => $order['id'],
            'description' => 'Подписка: '.$order['plan_name'],
            'return_url' => rtrim($this->config['APP_URL'] ?? '', '/').'/orders/'.$order['id'],
        ]);
        $url = $result['url'] ?? $result['payment_url'] ?? null;
        if (!$url || !str_starts_with($url, 'https://')) throw new BillingError('CisPay не вернул ссылку оплаты.');
        return ['payment_id' => (string)($result['id'] ?? $order['id']), 'checkout_url' => $url];
    }
    public function verify(string $paymentId): array
    {
        $result = $this->api('payment/status', ['id' => $paymentId]);
        $status = (string)($result['status'] ?? '');
        $amount = self::minor(self::normalizeAmount((string)($result['amount'] ?? '0')));
        return [
            'status' => in_array($status, ['paid', 'success', 'completed'], true) ? 'paid' : (in_array($status, ['canceled', 'failed', 'expired'], true) ? 'canceled' : 'pending'),
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
        return ['payment_id' => $paymentId, 'status' => in_array($status, ['paid', 'success'], true) ? 'paid' : 'pending'];
    }
}
